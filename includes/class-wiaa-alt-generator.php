<?php
/**
 * ALT candidate generator and review-state persistence.
 *
 * @package WEM_Image_ALT_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIAA_Alt_Generator {

	/**
	 * Base64 inline threshold. Prefer WordPress-generated local sizes so large
	 * originals do not force a remote URL request.
	 */
	const INLINE_MAX_BYTES = 8388608; // 8 MiB.

	/**
	 * Preferred WordPress image sizes for Vision input.
	 */
	const PREFERRED_IMAGE_SIZES = array( 'large', 'medium_large', 'medium' );
	const OPTION_ALT_LANGUAGE = 'wiaa_alt_language';
	const DEFAULT_ALT_LANGUAGE = 'auto';

	private $context;
	private $deepseek;

	public function __construct( WIAA_Image_Context $context, WIAA_DeepSeek_Client $deepseek ) {
		$this->context  = $context;
		$this->deepseek = $deepseek;
	}

	/**
	 * Supported ALT output languages.
	 *
	 * @return array<string,string>
	 */
	public function get_supported_languages() {
		return array(
			'auto' => '自动（跟随页面 / 站点语言）',
			'en'   => 'English',
			'zh_CN'=> '简体中文',
		);
	}

	/**
	 * Return the selected ALT output language.
	 *
	 * @return string
	 */
	public function get_alt_language() {
		$language  = get_option( self::OPTION_ALT_LANGUAGE, self::DEFAULT_ALT_LANGUAGE );
		$languages = $this->get_supported_languages();

		return is_string( $language ) && isset( $languages[ $language ] ) ? $language : self::DEFAULT_ALT_LANGUAGE;
	}

	/**
	 * Generate and store an AI classification + ALT candidate.
	 *
	 * The AI result is never written directly to WordPress ALT.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function generate_candidate( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'wiaa_invalid_attachment', '找不到有效的媒体图片。' );
		}

		$current_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		if ( '' !== $current_alt ) {
			return new WP_Error(
				'wiaa_alt_already_exists',
				'该图片已经有 ALT。为避免误覆盖，当前版本不会自动重新生成。'
			);
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			$this->mark_failed( $attachment_id, '当前 DeepSeek Vision 模型仅处理 JPEG / PNG / GIF / WebP。' );
			return new WP_Error(
				'wiaa_unsupported_image_type',
				'当前 DeepSeek Vision 模型仅处理 JPEG / PNG / GIF / WebP。'
			);
		}

		$image_source = $this->get_image_source( $attachment_id, $mime );
		if ( is_wp_error( $image_source ) ) {
			$this->mark_failed( $attachment_id, $image_source->get_error_message() );
			return $image_source;
		}

		$context = $this->context->build( $attachment_id );
		$prompt  = $this->build_prompt( $context );

		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_STATUS, 'processing' );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED, '0' );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED_AT );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_LAST_ERROR );

		$result = $this->deepseek->generate_alt( $image_source, $prompt );

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$debug      = is_array( $error_data ) && ! empty( $error_data['response_debug'] ) ? (string) $error_data['response_debug'] : '';
			$this->mark_failed( $attachment_id, $result->get_error_message(), $debug );
			return $result;
		}

		$analysis = $this->parse_ai_result( isset( $result['content'] ) ? $result['content'] : '' );
		$intent   = $analysis['type'];
		$candidate = $analysis['alt'];
		$note      = $analysis['reason'];

		if ( 'content' === $intent && '' === $candidate ) {
			$this->mark_failed( $attachment_id, 'AI 判断图片需要 ALT，但没有返回可用的候选文本。' );
			return new WP_Error( 'wiaa_empty_candidate', 'AI 判断图片需要 ALT，但没有返回可用的候选文本。' );
		}

		if ( '' !== $candidate ) {
			update_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE, $candidate );
		} else {
			delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE );
		}

		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_STATUS, 'candidate' );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_INTENT, $intent );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_AI_NOTE, $note );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_MODEL, isset( $result['model'] ) ? sanitize_text_field( $result['model'] ) : $this->deepseek->get_model() );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_GENERATED_AT, current_time( 'mysql' ) );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_LAST_ERROR );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_API_DEBUG );

		return array(
			'alt'        => $candidate,
			'intent'     => $intent,
			'note'       => $note,
			'reviewed'   => false,
			'model'      => isset( $result['model'] ) ? (string) $result['model'] : $this->deepseek->get_model(),
			'elapsed_ms' => isset( $result['elapsed_ms'] ) ? (int) $result['elapsed_ms'] : 0,
		);
	}

	/**
	 * Save an edited candidate on textarea blur.
	 *
	 * Any change resets the reviewed flag.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $candidate     Candidate.
	 * @return string|WP_Error
	 */
	public function save_candidate( $attachment_id, $candidate ) {
		$attachment_id = absint( $attachment_id );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'wiaa_invalid_attachment', '找不到有效的媒体图片。' );
		}

		$candidate = $this->clean_candidate( $candidate );

		if ( '' === $candidate ) {
			delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE );
		} else {
			update_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE, $candidate );
			update_post_meta( $attachment_id, WIAA_Image_Scanner::META_INTENT, 'content' );
		}

		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_STATUS, 'candidate' );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED, '0' );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED_AT );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_LAST_ERROR );

		return $candidate;
	}

	/**
	 * Set or clear human approval for the exact candidate currently shown.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $candidate     Current candidate.
	 * @param bool   $reviewed      Approval state.
	 * @return array<string,mixed>|WP_Error
	 */
	public function set_reviewed( $attachment_id, $candidate, $reviewed ) {
		$attachment_id = absint( $attachment_id );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'wiaa_invalid_attachment', '找不到有效的媒体图片。' );
		}

		$candidate = $this->clean_candidate( $candidate );

		if ( $reviewed && '' === $candidate ) {
			return new WP_Error( 'wiaa_review_empty_candidate', '候选 ALT 为空，不能标记为审核通过。' );
		}

		if ( '' !== $candidate ) {
			update_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE, $candidate );
			update_post_meta( $attachment_id, WIAA_Image_Scanner::META_INTENT, 'content' );
		}

		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_STATUS, 'candidate' );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED, $reviewed ? '1' : '0' );

		if ( $reviewed ) {
			update_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED_AT, current_time( 'mysql' ) );
		} else {
			delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED_AT );
		}

		return array(
			'alt'      => $candidate,
			'reviewed' => (bool) $reviewed,
		);
	}

	/**
	 * Apply a reviewed candidate to native WordPress ALT meta.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $candidate     Candidate displayed in UI.
	 * @return string|WP_Error
	 */
	public function apply_candidate( $attachment_id, $candidate ) {
		$attachment_id = absint( $attachment_id );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'wiaa_invalid_attachment', '找不到有效的媒体图片。' );
		}

		$current_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		if ( '' !== $current_alt ) {
			return new WP_Error(
				'wiaa_refuse_overwrite',
				'该图片已经存在 ALT。当前版本默认拒绝覆盖，以避免误修改人工内容。'
			);
		}

		$candidate        = $this->clean_candidate( $candidate );
		$stored_candidate = $this->clean_candidate( (string) get_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE, true ) );
		$reviewed         = '1' === (string) get_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED, true );

		if ( '' === $candidate ) {
			return new WP_Error( 'wiaa_empty_alt', 'ALT 不能为空。' );
		}

		if ( ! $reviewed ) {
			return new WP_Error( 'wiaa_not_reviewed', '请先勾选“审核通过”，再应用 ALT。' );
		}

		if ( $candidate !== $stored_candidate ) {
			return new WP_Error( 'wiaa_candidate_changed', '候选 ALT 已修改，请重新审核后再应用。' );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $candidate );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_APPLIED_ALT, $candidate );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_APPLIED_AT, current_time( 'mysql' ) );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_STATUS, 'applied' );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_INTENT, 'content' );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_LAST_ERROR );

		return $candidate;
	}

	/**
	 * Confirm that an empty ALT is intentional, or ignore this attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $mode          decorative|ignored.
	 * @return array<string,string>|WP_Error
	 */
	public function mark_no_alt( $attachment_id, $mode ) {
		$attachment_id = absint( $attachment_id );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'wiaa_invalid_attachment', '找不到有效的媒体图片。' );
		}

		$mode = sanitize_key( $mode );
		if ( ! in_array( $mode, array( 'decorative', 'ignored' ), true ) ) {
			return new WP_Error( 'wiaa_invalid_no_alt_mode', '无效的处理方式。' );
		}

		$current_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		if ( '' !== $current_alt ) {
			return new WP_Error( 'wiaa_alt_exists', '该图片已经存在 ALT，不需要标记为无需 ALT。' );
		}

		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_STATUS, 'no_alt' );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_INTENT, $mode );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED, '1' );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED_AT, current_time( 'mysql' ) );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_LAST_ERROR );

		return array(
			'status' => 'no_alt',
			'intent' => $mode,
		);
	}

	/**
	 * Restore a no-ALT/ignored image to the pending queue.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return true|WP_Error
	 */
	public function restore_pending( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'wiaa_invalid_attachment', '找不到有效的媒体图片。' );
		}

		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_STATUS );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_INTENT );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED_AT );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_LAST_ERROR );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_API_DEBUG );

		return true;
	}

	private function build_prompt( $context ) {
		$language = $this->get_alt_language();

		switch ( $language ) {
			case 'en':
				$language_rule = 'ALT output language is fixed to English. The alt and reason fields must be written in English.';
				break;

			case 'zh_CN':
				$language_rule = 'ALT 输出语言固定为简体中文。alt 与 reason 字段都使用简体中文。';
				break;

			case 'auto':
			default:
				$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
				$language_rule = 'ALT 语言自动判断：优先跟随可靠的父级页面 / 上下文主要语言；无法判断时跟随 WordPress 站点语言（' . $locale . '）。不要因为文件名中偶尔出现中文或英文就切换语言。';
				break;
		}

		return "请分析这张 WordPress 网站图片，并判断它是否应该有描述性 ALT。\n\n"
			. "严格只返回一个合法 JSON 对象。不要 Markdown、不要代码围栏、不要 JSON 前后的解释。\n"
			. "必须使用以下三个字段，字段名固定：\n"
			. '{"type":"content|decorative|uncertain","alt":"","reason":""}' . "\n\n"
			. "语言要求：\n"
			. $language_rule . "\n\n"
			. "判断规则：\n"
			. "1. content：图片传达了页面内容所需的信息，应生成 ALT。\n"
			. "2. decorative：图片主要用于装饰、占位、背景或重复视觉信息，通常应该保留空 ALT。\n"
			. "3. uncertain：仅凭图片和上下文无法可靠判断，交给人工复核。\n"
			. "4. 对 content，alt 必须准确、自然、简洁；不要关键词堆砌。\n"
			. "5. 不要写 image of / photo of / picture of 等无意义前缀。\n"
			. "6. 不臆造品牌、型号、材质、颜色、尺寸或用途；只有图片可见或上下文可靠提供时才使用。\n"
			. "7. 英文 ALT 通常控制在 8–20 个单词；确有必要可略长。\n"
			. "8. decorative 时 alt 必须为空字符串。\n"
			. "9. uncertain 时可以给出非常保守的候选，也可以保持 alt 为空；reason 用一句短话说明不确定点。\n"
			. "10. reason 只用于后台审核，保持一句简短说明，不要重复 ALT。\n\n"
			. "WordPress Context:\n"
			. ( $context ? $context : '{}' );
	}

	/**
	 * Normalize an AI payload using the same parser as new Vision responses.
	 *
	 * Exposed for one-time local migrations of legacy candidate data.
	 * This method does not call an external API.
	 *
	 * @param string $raw Raw AI payload or stored legacy candidate.
	 * @return array{type:string,alt:string,reason:string}
	 */
	public function normalize_ai_payload( $raw ) {
		return $this->parse_ai_result( $raw );
	}

	/**
	 * Parse the AI JSON, with a safe fallback for legacy/plain text output.
	 *
	 * @param string $raw Raw AI content.
	 * @return array{type:string,alt:string,reason:string}
	 */
	private function parse_ai_result( $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		$raw = preg_replace( '/^```(?:json|text)?\s*|\s*```$/iu', '', $raw );
		$raw = trim( (string) $raw );

		$decoded = json_decode( $raw, true );

		// Some vision responses wrap JSON with a short sentence despite the prompt.
		// Extract the first balanced-looking JSON object as a tolerant fallback.
		if ( ! is_array( $decoded ) ) {
			$json = $this->extract_json_object( $raw );
			if ( '' !== $json ) {
				$decoded = json_decode( $json, true );
			}
		}

		if ( is_array( $decoded ) ) {
			$type   = isset( $decoded['type'] ) ? sanitize_key( (string) $decoded['type'] ) : 'uncertain';
			$alt    = isset( $decoded['alt'] ) && is_scalar( $decoded['alt'] ) ? $this->clean_candidate( (string) $decoded['alt'] ) : '';
			$reason = isset( $decoded['reason'] ) && is_scalar( $decoded['reason'] ) ? sanitize_text_field( (string) $decoded['reason'] ) : '';

			if ( ! in_array( $type, array( 'content', 'decorative', 'uncertain' ), true ) ) {
				$type = '' !== $alt ? 'content' : 'uncertain';
			}

			if ( 'decorative' === $type ) {
				$alt = '';
			}

			return array(
				'type'   => $type,
				'alt'    => $alt,
				'reason' => $reason,
			);
		}

		// If the model returned a JSON-looking object that could not be decoded,
		// do not place the raw JSON into ALT. Attempt field extraction instead.
		if ( preg_match( '/[\{\}]|"(?:type|alt|reason)"\s*:/iu', $raw ) ) {
			$type   = $this->extract_jsonish_field( $raw, 'type' );
			$alt    = $this->clean_candidate( $this->extract_jsonish_field( $raw, 'alt' ) );
			$reason = sanitize_text_field( $this->extract_jsonish_field( $raw, 'reason' ) );
			$type   = sanitize_key( $type );

			if ( ! in_array( $type, array( 'content', 'decorative', 'uncertain' ), true ) ) {
				$type = '' !== $alt ? 'content' : 'uncertain';
			}

			if ( 'decorative' === $type ) {
				$alt = '';
			}

			return array(
				'type'   => $type,
				'alt'    => $alt,
				'reason' => $reason ? $reason : 'AI 返回了非标准 JSON，插件已容错提取字段，请人工复核。',
			);
		}

		// Backward-compatible fallback: true plain text is treated as a content ALT.
		$alt = $this->clean_candidate( $raw );

		return array(
			'type'   => '' !== $alt ? 'content' : 'uncertain',
			'alt'    => $alt,
			'reason' => '' !== $alt ? 'AI 返回了纯文本候选，已按内容图片处理，请人工复核。' : 'AI 返回结果无法解析，请人工复核。',
		);
	}

	/**
	 * Extract the first JSON object while respecting quoted braces.
	 *
	 * @param string $raw Raw model text.
	 * @return string
	 */
	private function extract_json_object( $raw ) {
		$start = strpos( $raw, '{' );
		if ( false === $start ) {
			return '';
		}

		$depth   = 0;
		$in_str  = false;
		$escaped = false;
		$length  = strlen( $raw );

		for ( $i = $start; $i < $length; $i++ ) {
			$char = $raw[ $i ];

			if ( $in_str ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $char ) {
					$escaped = true;
				} elseif ( '"' === $char ) {
					$in_str = false;
				}
				continue;
			}

			if ( '"' === $char ) {
				$in_str = true;
				continue;
			}

			if ( '{' === $char ) {
				$depth++;
			} elseif ( '}' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $raw, $start, $i - $start + 1 );
				}
			}
		}

		return '';
	}

	/**
	 * Extract a simple quoted field from malformed JSON-ish text.
	 *
	 * @param string $raw Raw text.
	 * @param string $key Field name.
	 * @return string
	 */
	private function extract_jsonish_field( $raw, $key ) {
		$key = preg_quote( $key, '/' );
		if ( preg_match( '/["\']?' . $key . '["\']?\s*:\s*(["\'])(.*?)\1/isu', $raw, $matches ) ) {
			return stripcslashes( $matches[2] );
		}

		return '';
	}

	/**
	 * Build the Vision image source.
	 *
	 * Prefer local WordPress-generated image sizes and send them as Base64. This
	 * avoids remote-download failures caused by Chinese / non-ASCII filenames,
	 * Basic Auth, Cloudflare rules or private staging sites. Only use a public URL
	 * as the final fallback.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $mime          Original MIME.
	 * @return string|WP_Error
	 */
	private function get_image_source( $attachment_id, $mime ) {
		$original = get_attached_file( $attachment_id );
		$metadata = wp_get_attachment_metadata( $attachment_id );

		// Animated GIFs are best represented by the original when reasonably small.
		if ( 'image/gif' === $mime ) {
			$source = $this->local_file_to_data_url( $original, $mime );
			if ( $source ) {
				return $source;
			}
		}

		// Prefer WordPress-generated ~1024px variants before the full original.
		if ( $original && is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_dir = dirname( $original );

			foreach ( self::PREFERRED_IMAGE_SIZES as $size_name ) {
				if ( empty( $metadata['sizes'][ $size_name ]['file'] ) ) {
					continue;
				}

				$variant_file = trailingslashit( $base_dir ) . wp_basename( $metadata['sizes'][ $size_name ]['file'] );
				$variant_mime = ! empty( $metadata['sizes'][ $size_name ]['mime-type'] )
					? (string) $metadata['sizes'][ $size_name ]['mime-type']
					: $mime;
				$source = $this->local_file_to_data_url( $variant_file, $variant_mime );

				if ( $source ) {
					return $source;
				}
			}
		}

		// If no suitable intermediate size exists, use the local original when safe.
		$source = $this->local_file_to_data_url( $original, $mime );
		if ( $source ) {
			return $source;
		}

		// Last resort only: send a public URL with path segments percent-encoded.
		$url = wp_get_attachment_url( $attachment_id );
		if ( $url && preg_match( '#^https?://#i', $url ) ) {
			return $this->encode_remote_image_url( $url );
		}

		return new WP_Error(
			'wiaa_image_source_unavailable',
			'无法读取本地图片，也没有可供 DeepSeek 访问的 HTTP(S) 图片 URL。'
		);
	}

	/**
	 * Convert a readable local image to a data URL when it is within the limit.
	 *
	 * @param string|false $file Local path.
	 * @param string       $mime MIME type.
	 * @return string
	 */
	private function local_file_to_data_url( $file, $mime ) {
		if ( ! $file || ! is_readable( $file ) ) {
			return '';
		}

		$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size || $size <= 0 || $size > self::INLINE_MAX_BYTES ) {
			return '';
		}

		$contents = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $contents ) {
			return '';
		}

		$checked = wp_check_filetype( $file );
		if ( ! empty( $checked['type'] ) && 0 === strpos( (string) $checked['type'], 'image/' ) ) {
			$mime = (string) $checked['type'];
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $contents );
	}

	/**
	 * Percent-encode URL path segments without double-encoding existing escapes.
	 *
	 * @param string $url Public image URL.
	 * @return string
	 */
	private function encode_remote_image_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( '' !== $path ) {
			$segments = explode( '/', $path );
			foreach ( $segments as &$segment ) {
				$segment = rawurlencode( rawurldecode( $segment ) );
			}
			unset( $segment );
			$path = implode( '/', $segments );
		}

		$encoded = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$encoded .= ':' . absint( $parts['port'] );
		}
		$encoded .= $path;

		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$encoded .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$encoded .= '#' . rawurlencode( rawurldecode( $parts['fragment'] ) );
		}

		return $encoded;
	}

	private function clean_candidate( $candidate ) {
		$candidate = is_string( $candidate ) ? trim( $candidate ) : '';
		$candidate = preg_replace( '/^```(?:text)?\s*|\s*```$/i', '', $candidate );
		$candidate = preg_replace( '/^\s*(?:alt(?:\s*text)?\s*[:：]\s*)/iu', '', $candidate );
		$candidate = trim( (string) $candidate, " \t\n\r\0\x0B\"'“”‘’" );
		$candidate = wp_strip_all_tags( $candidate );
		$candidate = preg_replace( '/\s+/u', ' ', $candidate );
		$candidate = trim( (string) $candidate );

		if ( function_exists( 'mb_substr' ) ) {
			$candidate = mb_substr( $candidate, 0, 250 );
		} else {
			$candidate = substr( $candidate, 0, 250 );
		}

		return sanitize_text_field( $candidate );
	}

	private function mark_failed( $attachment_id, $message, $debug = '' ) {
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_STATUS, 'failed' );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED, '0' );
		delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED_AT );
		update_post_meta( $attachment_id, WIAA_Image_Scanner::META_LAST_ERROR, sanitize_text_field( $message ) );

		if ( '' !== trim( (string) $debug ) ) {
			update_post_meta( $attachment_id, WIAA_Image_Scanner::META_API_DEBUG, sanitize_text_field( (string) $debug ) );
		} else {
			delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_API_DEBUG );
		}
	}
}
