<?php
/**
 * Read-only frontend ALT auditor.
 *
 * Fetches one public frontend page from the current site, inspects rendered
 * <img> elements, maps them back to WordPress attachments when possible, and
 * compares the rendered ALT with the Media Library ALT.
 *
 * @package WEM_Image_ALT_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIAA_Frontend_Auditor {

	/**
	 * Audit a current-site post/page/product URL or numeric post ID.
	 *
	 * @param string $target URL or post ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function audit( $target ) {
		$post_id = $this->resolve_post_id( $target );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'wiaa_audit_missing_post', '找不到对应的 WordPress 内容。' );
		}

		$url = get_permalink( $post_id );

		if ( ! $url ) {
			return new WP_Error( 'wiaa_audit_missing_permalink', '无法取得该内容的前台 URL。' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 2,
				'user-agent'  => 'WEM-Image-ALT-Assistant/' . WIAA_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wiaa_audit_fetch_failed',
				'无法读取前台页面：' . $response->get_error_message()
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$html        = (string) wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'wiaa_audit_http_error',
				sprintf( '前台页面返回 HTTP %d。若测试站有 Basic Auth / Access / 登录限制，请先临时允许服务器访问该页面。', $status_code )
			);
		}

		if ( '' === trim( $html ) ) {
			return new WP_Error( 'wiaa_audit_empty_html', '前台页面返回了空 HTML。' );
		}

		$images = $this->extract_images( $html );
		$rows   = array();
		$counts = array(
			'total'            => 0,
			'mapped'           => 0,
			'synced'           => 0,
			'frontend_missing' => 0,
			'different'        => 0,
			'both_empty'       => 0,
			'frontend_only'    => 0,
			'unmapped'         => 0,
		);

		foreach ( $images as $image ) {
			$counts['total']++;
			$attachment_id = $this->resolve_attachment_id( $image );
			$frontend_alt  = isset( $image['alt'] ) ? trim( (string) $image['alt'] ) : '';
			$alt_present   = ! empty( $image['alt_present'] );
			$media_alt     = '';
			$status        = 'unmapped';

			if ( $attachment_id ) {
				$counts['mapped']++;
				$media_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
				$status    = $this->compare_alt( $media_alt, $frontend_alt, $alt_present );
			} else {
				$counts['unmapped']++;
			}

			if ( isset( $counts[ $status ] ) && 'unmapped' !== $status ) {
				$counts[ $status ]++;
			}

			$rows[] = array(
				'attachment_id' => $attachment_id,
				'src'           => isset( $image['src'] ) ? $image['src'] : '',
				'class'         => isset( $image['class'] ) ? $image['class'] : '',
				'frontend_alt'  => $frontend_alt,
				'alt_present'   => $alt_present,
				'media_alt'     => $media_alt,
				'status'        => $status,
				'title'         => $attachment_id ? get_the_title( $attachment_id ) : '',
				'thumb_url'     => $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '',
				'edit_url'      => $attachment_id ? get_edit_post_link( $attachment_id, 'raw' ) : '',
			);
		}

		return array(
			'post_id'     => (int) $post_id,
			'post_title'  => get_the_title( $post_id ),
			'post_type'   => $post->post_type,
			'url'         => $url,
			'status_code' => $status_code,
			'counts'      => $counts,
			'rows'        => $rows,
			'conclusion'  => $this->build_conclusion( $counts ),
		);
	}

	/**
	 * Resolve and validate a same-site content target.
	 *
	 * @param string $target URL or ID.
	 * @return int|WP_Error
	 */
	private function resolve_post_id( $target ) {
		$target = trim( (string) $target );

		if ( '' === $target ) {
			return new WP_Error( 'wiaa_audit_empty_target', '请输入要验证的本站页面 URL 或文章 ID。' );
		}

		if ( ctype_digit( $target ) ) {
			$post_id = absint( $target );
			$post    = get_post( $post_id );

			if ( ! $post ) {
				return new WP_Error( 'wiaa_audit_invalid_id', '找不到这个文章 ID。' );
			}

			return $post_id;
		}

		$url = esc_url_raw( $target );
		if ( '' === $url ) {
			return new WP_Error( 'wiaa_audit_invalid_url', '请输入有效 URL。' );
		}

		$target_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home_host   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		if ( '' === $target_host || '' === $home_host || $target_host !== $home_host ) {
			return new WP_Error( 'wiaa_audit_external_url', '为了安全，前台 ALT 验证只允许当前 WordPress 网站自己的 URL。' );
		}

		$post_id = url_to_postid( $url );

		if ( ! $post_id ) {
			return new WP_Error(
				'wiaa_audit_unresolved_url',
				'无法把该 URL 解析为 WordPress 文章 / 页面 / 产品。当前验证器暂不处理分类归档、搜索页或纯模板 URL。'
			);
		}

		return (int) $post_id;
	}

	/**
	 * Extract image attributes from final HTML.
	 *
	 * DOMDocument is used because the plugin supports WordPress 6.0, while
	 * WP_HTML_Tag_Processor is not available on every supported install.
	 *
	 * @param string $html HTML.
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_images( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return $this->extract_images_with_regex( $html );
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $this->extract_images_with_regex( $html );
		}

		$items = array();
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			$items[] = array(
				'src'         => $img->getAttribute( 'src' ),
				'alt'         => $img->getAttribute( 'alt' ),
				'alt_present' => $img->hasAttribute( 'alt' ),
				'class'       => $img->getAttribute( 'class' ),
				'data_id'     => $img->getAttribute( 'data-id' ),
			);
		}

		return $items;
	}

	/**
	 * Conservative regex fallback when DOM is unavailable.
	 *
	 * @param string $html HTML.
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_images_with_regex( $html ) {
		$items = array();
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $html, $matches ) ) {
			return $items;
		}

		foreach ( $matches[0] as $tag ) {
			$src       = $this->extract_attr( $tag, 'src' );
			$alt       = $this->extract_attr( $tag, 'alt' );
			$class     = $this->extract_attr( $tag, 'class' );
			$data_id   = $this->extract_attr( $tag, 'data-id' );
			$alt_match = preg_match( '/\balt\s*=\s*(["\']).*?\1/is', $tag );

			$items[] = array(
				'src'         => $src,
				'alt'         => $alt,
				'alt_present' => (bool) $alt_match,
				'class'       => $class,
				'data_id'     => $data_id,
			);
		}

		return $items;
	}

	private function extract_attr( $tag, $attr ) {
		$pattern = '/\b' . preg_quote( $attr, '/' ) . '\s*=\s*(["\'])(.*?)\1/is';
		if ( preg_match( $pattern, $tag, $match ) ) {
			return html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' );
		}
		return '';
	}

	/**
	 * Map rendered image to a Media Library attachment.
	 *
	 * @param array<string,mixed> $image Extracted image.
	 * @return int
	 */
	private function resolve_attachment_id( array $image ) {
		$class = isset( $image['class'] ) ? (string) $image['class'] : '';
		if ( preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $match ) ) {
			$id = absint( $match[1] );
			if ( $id && 'attachment' === get_post_type( $id ) ) {
				return $id;
			}
		}

		$data_id = isset( $image['data_id'] ) ? absint( $image['data_id'] ) : 0;
		if ( $data_id && 'attachment' === get_post_type( $data_id ) ) {
			return $data_id;
		}

		$src = isset( $image['src'] ) ? html_entity_decode( (string) $image['src'], ENT_QUOTES, 'UTF-8' ) : '';
		if ( '' === $src ) {
			return 0;
		}

		$src = strtok( $src, '?' );
		if ( 0 === strpos( $src, '//' ) ) {
			$src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
		} elseif ( 0 === strpos( $src, '/' ) ) {
			$src = home_url( $src );
		}

		$candidates = array_unique(
			array_filter(
				array(
					$src,
					$this->normalize_upload_url( $src ),
					$this->strip_intermediate_size( $src ),
					$this->strip_intermediate_size( $this->normalize_upload_url( $src ) ),
				)
			)
		);

		foreach ( $candidates as $candidate ) {
			$id = attachment_url_to_postid( $candidate );
			if ( $id ) {
				return (int) $id;
			}
		}

		return $this->lookup_attachment_by_upload_path( $src );
	}

	/**
	 * Replace CDN/current host with the canonical uploads base URL when paths match.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private function normalize_upload_url( $url ) {
		$uploads = wp_get_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		if ( '' === $baseurl ) {
			return $url;
		}

		$base_path = (string) wp_parse_url( $baseurl, PHP_URL_PATH );
		$url_path  = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $base_path || '' === $url_path ) {
			return $url;
		}

		$pos = strpos( $url_path, $base_path );
		if ( false === $pos ) {
			return $url;
		}

		$relative = substr( $url_path, $pos + strlen( $base_path ) );
		return untrailingslashit( $baseurl ) . '/' . ltrim( $relative, '/' );
	}

	/**
	 * Convert generated size filename to the probable original attachment URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function strip_intermediate_size( $url ) {
		return preg_replace( '/-\d+x\d+(?=\.(?:jpe?g|png|gif|webp|avif)$)/i', '', (string) $url );
	}

	/**
	 * Last-resort attachment lookup by _wp_attached_file.
	 *
	 * @param string $url Image URL.
	 * @return int
	 */
	private function lookup_attachment_by_upload_path( $url ) {
		global $wpdb;

		$uploads   = wp_get_upload_dir();
		$base_path = isset( $uploads['baseurl'] ) ? (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) : '';
		$url_path  = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $base_path || '' === $url_path ) {
			return 0;
		}

		$pos = strpos( $url_path, $base_path );
		if ( false === $pos ) {
			return 0;
		}

		$relative = ltrim( substr( $url_path, $pos + strlen( $base_path ) ), '/' );
		$relative = preg_replace( '/-\d+x\d+(?=\.(?:jpe?g|png|gif|webp|avif)$)/i', '', $relative );

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$relative
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return $id ? (int) $id : 0;
	}

	private function compare_alt( $media_alt, $frontend_alt, $alt_present ) {
		$media_alt    = trim( (string) $media_alt );
		$frontend_alt = trim( (string) $frontend_alt );

		if ( '' !== $media_alt && '' === $frontend_alt ) {
			return 'frontend_missing';
		}

		if ( '' !== $media_alt && '' !== $frontend_alt ) {
			return $this->same_text( $media_alt, $frontend_alt ) ? 'synced' : 'different';
		}

		if ( '' === $media_alt && '' !== $frontend_alt ) {
			return 'frontend_only';
		}

		return 'both_empty';
	}

	private function same_text( $a, $b ) {
		$a = html_entity_decode( wp_strip_all_tags( (string) $a ), ENT_QUOTES, 'UTF-8' );
		$b = html_entity_decode( wp_strip_all_tags( (string) $b ), ENT_QUOTES, 'UTF-8' );
		$a = preg_replace( '/\s+/u', ' ', trim( $a ) );
		$b = preg_replace( '/\s+/u', ' ', trim( $b ) );
		return $a === $b;
	}

	private function build_conclusion( array $counts ) {
		if ( $counts['frontend_missing'] > 0 ) {
			return array(
				'type'    => 'warning',
				'title'   => '发现媒体库 ALT 已设置，但前台仍为空',
				'message' => sprintf( '本页有 %d 张已能映射到媒体库的图片出现这种情况。这说明至少该页面的渲染路径不会自动同步媒体库 ALT，后续值得考虑 Frontend ALT Fallback。', $counts['frontend_missing'] ),
			);
		}

		if ( $counts['synced'] > 0 ) {
			return array(
				'type'    => 'success',
				'title'   => '本页已设置的媒体库 ALT 能正常反映到前台',
				'message' => sprintf( '本页检测到 %d 张图片的前台 ALT 与媒体库 ALT 一致。当前页面暂时没有证据表明需要 Frontend ALT Fallback。', $counts['synced'] ),
			);
		}

		return array(
			'type'    => 'neutral',
			'title'   => '暂时无法得出同步结论',
			'message' => '本页没有足够的“媒体库已有 ALT”样本，或者大部分图片无法映射到 Attachment。建议先给 1–2 张图片应用 ALT 后再验证。',
		);
	}
}
