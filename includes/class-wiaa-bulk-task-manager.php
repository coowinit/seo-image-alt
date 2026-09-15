<?php
/**
 * Persistent site-wide bulk task runner.
 *
 * Tasks are stored per user and advanced by small AJAX requests. This keeps
 * large Media Libraries away from one long PHP request while still allowing
 * pause/resume after a page refresh.
 *
 * @package WEM_Image_ALT_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIAA_Bulk_Task_Manager {

	const USER_META_TASK = '_wiaa_bulk_task_v1_1';
	const NON_AI_BATCH_SIZE = 50;
	const MAX_RETRIES = 2;
	const DEFAULT_RETRY_DELAY = 3;
	const DEFAULT_RATE_LIMIT_DELAY = 30;

	private $scanner;
	private $generator;
	private $deepseek;

	public function __construct( WIAA_Image_Scanner $scanner, WIAA_Alt_Generator $generator, WIAA_DeepSeek_Client $deepseek ) {
		$this->scanner   = $scanner;
		$this->generator = $generator;
		$this->deepseek  = $deepseek;
	}

	/**
	 * Return the current user's task without the internal ID queue.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_public_task() {
		$task = $this->get_task();
		return $task ? $this->to_public_task( $task ) : null;
	}

	/**
	 * Create a task.
	 *
	 * Supported operations:
	 * - generate: sequential Vision generation, one image per request.
	 * - review: approve all candidates that pass the conservative quality gate.
	 * - apply: write every reviewed candidate whose native ALT is still empty.
	 *
	 * @param string $operation generate|review|apply.
	 * @param string $scope     pending|failed.
	 * @param int    $limit     Optional item limit (used by the 20-image test).
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_task( $operation, $scope = 'pending', $limit = 0 ) {
		$operation = sanitize_key( (string) $operation );
		$scope     = sanitize_key( (string) $scope );
		$limit     = max( 0, absint( $limit ) );

		$current = $this->get_task();
		if ( $current && in_array( $current['status'], array( 'running', 'paused', 'cooldown' ), true ) ) {
			return new WP_Error( 'wiaa_bulk_active_task', '当前还有未结束的全站任务，请先继续或停止该任务。' );
		}

		$excluded = 0;
		$label    = '';

		switch ( $operation ) {
			case 'generate':
				if ( ! $this->deepseek->is_configured() ) {
					return new WP_Error( 'wiaa_bulk_missing_api_key', '尚未配置 DeepSeek API Key。' );
				}

				if ( ! in_array( $scope, array( 'pending', 'failed' ), true ) ) {
					$scope = 'pending';
				}

				$source_ids = $this->scanner->get_ids_by_status( $scope );
				$ids        = array();
				$supported  = $this->scanner->get_supported_mime_types();

				foreach ( $source_ids as $attachment_id ) {
					if ( in_array( (string) get_post_mime_type( $attachment_id ), $supported, true ) ) {
						$ids[] = $attachment_id;
					} else {
						$excluded++;
					}
				}

				$label = 'failed' === $scope ? '重试全部失败图片' : '生成全部待处理图片';
				break;

			case 'review':
				$source_ids = $this->scanner->get_ids_by_status( 'candidate' );
				$ids        = array();

				if ( ! empty( $source_ids ) ) {
					update_meta_cache( 'post', $source_ids );
				}

				foreach ( $source_ids as $attachment_id ) {
					if ( $this->is_review_eligible( $attachment_id ) ) {
						$ids[] = $attachment_id;
					} else {
						$excluded++;
					}
				}

				$label = '批量审核合格内容图';
				$scope = 'eligible_content';
				break;

			case 'apply':
				$ids   = $this->scanner->get_ids_by_status( 'reviewed' );
				$label = '应用全部已审核 ALT';
				$scope = 'reviewed';
				break;

			default:
				return new WP_Error( 'wiaa_bulk_invalid_operation', '不支持的全站批量任务。' );
		}

		$ids     = array_values( array_unique( array_map( 'absint', $ids ) ) );
		$is_test = 'generate' === $operation && $limit > 0;

		if ( $limit > 0 ) {
			$ids   = array_slice( $ids, 0, $limit );
			$label = '测试生成 ' . count( $ids ) . ' 张图片';
		}

		if ( empty( $ids ) ) {
			if ( 'review' === $operation && $excluded > 0 ) {
				return new WP_Error( 'wiaa_bulk_no_review_eligible', '当前没有通过安全质量门槛的内容图候选，请先处理“不确定 / 装饰图 / 异常候选”。' );
			}
			return new WP_Error( 'wiaa_bulk_empty_task', '当前没有符合该任务条件的图片。' );
		}

		$task = array(
			'id'             => wp_generate_uuid4(),
			'operation'      => $operation,
			'scope'          => $scope,
			'label'          => $label,
			'status'         => 'running',
			'ids'            => $ids,
			'total'          => count( $ids ),
			'cursor'         => 0,
			'processed'      => 0,
			'success'        => 0,
			'failed'         => 0,
			'skipped'        => 0,
			'excluded'       => $excluded,
			'retry_count'    => 0,
			'cooldown_until' => 0,
			'pause_reason'   => '',
			'last_error'     => '',
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
			'is_test'        => $is_test,
			'test_limit'     => $is_test ? $limit : 0,
		);

		$this->save_task( $task );
		return $this->to_public_task( $task );
	}

	/**
	 * Advance the current task by one AI item or one local batch.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function step() {
		$task = $this->get_task();

		if ( ! $task ) {
			return new WP_Error( 'wiaa_bulk_missing_task', '当前没有全站批量任务。' );
		}

		if ( 'cooldown' === $task['status'] ) {
			if ( time() < (int) $task['cooldown_until'] ) {
				return $this->to_public_task( $task );
			}
			$task['status']         = 'running';
			$task['pause_reason']   = '';
			$task['cooldown_until'] = 0;
		}

		if ( 'running' !== $task['status'] ) {
			return $this->to_public_task( $task );
		}

		if ( (int) $task['cursor'] >= (int) $task['total'] ) {
			$task['status'] = 'completed';
			$this->touch_and_save( $task );
			return $this->to_public_task( $task );
		}

		if ( 'generate' === $task['operation'] ) {
			$this->step_generate( $task );
		} else {
			$this->step_local_batch( $task );
		}

		if ( (int) $task['cursor'] >= (int) $task['total'] && 'running' === $task['status'] ) {
			$task['status'] = 'completed';
		}

		$this->touch_and_save( $task );
		return $this->to_public_task( $task );
	}

	/**
	 * Pause/resume/stop the current task.
	 *
	 * @param string $action pause|resume|skip|stop.
	 * @return array<string,mixed>|WP_Error
	 */
	public function control( $action ) {
		$action = sanitize_key( (string) $action );
		$task   = $this->get_task();

		if ( ! $task ) {
			return new WP_Error( 'wiaa_bulk_missing_task', '当前没有全站批量任务。' );
		}

		switch ( $action ) {
			case 'pause':
				if ( in_array( $task['status'], array( 'running', 'cooldown' ), true ) ) {
					$task['status']         = 'paused';
					$task['pause_reason']   = 'manual';
					$task['cooldown_until'] = 0;
				}
				break;

			case 'resume':
				if ( in_array( $task['status'], array( 'paused', 'error' ), true ) ) {
					$task['status']         = 'running';
					$task['pause_reason']   = '';
					$task['cooldown_until'] = 0;
					$task['retry_count']    = 0;
				}
				break;

			case 'skip':
				if ( 'generate' !== $task['operation'] || 'error' !== $task['status'] || (int) $task['cursor'] >= (int) $task['total'] ) {
					return new WP_Error( 'wiaa_bulk_cannot_skip', '当前任务没有可跳过的异常图片。' );
				}

				$this->advance( $task, 'skipped' );
				$task['status']         = (int) $task['cursor'] >= (int) $task['total'] ? 'completed' : 'running';
				$task['pause_reason']   = '';
				$task['cooldown_until'] = 0;
				$task['retry_count']    = 0;
				$task['last_error']     = '';
				break;

			case 'stop':
				if ( ! in_array( $task['status'], array( 'completed', 'stopped' ), true ) ) {
					$task['status']       = 'stopped';
					$task['pause_reason'] = 'manual';
				}
				break;

			default:
				return new WP_Error( 'wiaa_bulk_invalid_control', '无效的任务控制操作。' );
		}

		$this->touch_and_save( $task );
		return $this->to_public_task( $task );
	}

	private function step_generate( array &$task ) {
		$attachment_id = isset( $task['ids'][ $task['cursor'] ] ) ? absint( $task['ids'][ $task['cursor'] ] ) : 0;

		if ( ! $attachment_id ) {
			$this->advance( $task, 'skipped' );
			return;
		}

		// The queue is a snapshot. Recheck native ALT before every API call.
		$current_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		if ( '' !== $current_alt ) {
			$this->advance( $task, 'skipped' );
			return;
		}

		$result = $this->generator->generate_candidate( $attachment_id );

		if ( ! is_wp_error( $result ) ) {
			$task['last_error']  = '';
			$task['retry_count'] = 0;
			$this->advance( $task, 'success' );
			return;
		}

		$data        = $result->get_error_data();
		$status_code = is_array( $data ) && isset( $data['status_code'] ) ? absint( $data['status_code'] ) : 0;
		$retry_after = is_array( $data ) && isset( $data['retry_after'] ) ? absint( $data['retry_after'] ) : 0;
		$error_code  = $result->get_error_code();
		$message     = $result->get_error_message();

		$task['last_error'] = $message;

		if ( 429 === $status_code ) {
			$delay                  = $retry_after > 0 ? min( 300, $retry_after ) : self::DEFAULT_RATE_LIMIT_DELAY;
			$task['status']         = 'cooldown';
			$task['pause_reason']   = 'rate_limit';
			$task['cooldown_until'] = time() + $delay;
			return;
		}

		// A failure that belongs to one image must never block a 1000+ image queue.
		if ( $this->is_single_image_error( $error_code, $status_code, $message ) ) {
			$task['retry_count'] = 0;
			$this->advance( $task, 'failed' );
			return;
		}

		// Authentication, quota, model or malformed-request errors usually affect
		// every following item. Pause so the operator can fix the configuration.
		if ( $this->is_global_api_error( $status_code, $message ) ) {
			$task['status']       = 'error';
			$task['pause_reason'] = 'api_error';
			return;
		}

		$retryable = $status_code >= 500
			|| 'wiaa_deepseek_transport_error' === $error_code
			|| 'wiaa_deepseek_empty_response' === $error_code
			|| 'wiaa_deepseek_invalid_json' === $error_code;

		if ( $retryable && (int) $task['retry_count'] < self::MAX_RETRIES ) {
			$task['retry_count'] = (int) $task['retry_count'] + 1;
			return;
		}

		$task['retry_count'] = 0;
		$this->advance( $task, 'failed' );
	}

	/**
	 * Decide whether an error only belongs to the current image.
	 *
	 * @param string $error_code  WP_Error code.
	 * @param int    $status_code HTTP status code.
	 * @param string $message     Provider message.
	 * @return bool
	 */
	private function is_single_image_error( $error_code, $status_code, $message ) {
		if ( in_array( $error_code, array( 'wiaa_image_source_unavailable', 'wiaa_unsupported_image_type', 'wiaa_invalid_attachment' ), true ) ) {
			return true;
		}

		if ( 'wiaa_alt_already_exists' === $error_code ) {
			return true;
		}

		$message = strtolower( (string) $message );
		$image_markers = array(
			'failed to download image',
			'cannot download image',
			'could not download image',
			'invalid image',
			'image url',
			'image_url',
			'unsupported image',
			'image format',
			'image file',
			'corrupt image',
			'corrupted image',
			'image size',
			'image resolution',
			'base64 image',
			'data:image/',
		);

		foreach ( $image_markers as $marker ) {
			if ( false !== strpos( $message, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decide whether continuing the queue would likely repeat the same failure.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $message     Provider message.
	 * @return bool
	 */
	private function is_global_api_error( $status_code, $message ) {
		if ( in_array( $status_code, array( 401, 402, 403 ), true ) ) {
			return true;
		}

		$message = strtolower( (string) $message );
		$global_markers = array(
			'api key',
			'authentication',
			'unauthorized',
			'permission',
			'insufficient balance',
			'quota',
			'credit',
			'model not found',
			'model does not exist',
			'unknown model',
			'invalid model',
			'unsupported model',
			'unsupported parameter',
			'unknown parameter',
			'invalid request',
		);

		foreach ( $global_markers as $marker ) {
			if ( false !== strpos( $message, $marker ) ) {
				return true;
			}
		}

		return in_array( $status_code, array( 400, 404, 422 ), true );
	}

	private function step_local_batch( array &$task ) {
		$handled = 0;

		while ( $handled < self::NON_AI_BATCH_SIZE && (int) $task['cursor'] < (int) $task['total'] ) {
			$attachment_id = isset( $task['ids'][ $task['cursor'] ] ) ? absint( $task['ids'][ $task['cursor'] ] ) : 0;
			$handled++;

			if ( ! $attachment_id ) {
				$this->advance( $task, 'skipped' );
				continue;
			}

			if ( 'review' === $task['operation'] ) {
				if ( ! $this->is_review_eligible( $attachment_id ) ) {
					$this->advance( $task, 'skipped' );
					continue;
				}

				$candidate = trim( (string) get_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE, true ) );
				$result    = $this->generator->set_reviewed( $attachment_id, $candidate, true );
			} else {
				$current_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
				if ( '' !== $current_alt ) {
					$this->advance( $task, 'skipped' );
					continue;
				}

				$candidate = trim( (string) get_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE, true ) );
				$result    = $this->generator->apply_candidate( $attachment_id, $candidate );
			}

			if ( is_wp_error( $result ) ) {
				if ( in_array( $result->get_error_code(), array( 'wiaa_refuse_overwrite', 'wiaa_empty_alt', 'wiaa_not_reviewed' ), true ) ) {
					$this->advance( $task, 'skipped' );
				} else {
					$task['last_error'] = $result->get_error_message();
					$this->advance( $task, 'failed' );
				}
			} else {
				$this->advance( $task, 'success' );
			}
		}
	}

	/**
	 * Conservative quality gate for global approval.
	 *
	 * Only clearly classified content images are eligible. Uncertain/decorative
	 * items always stay in the manual-review queue.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_review_eligible( $attachment_id ) {
		$reviewed = '1' === (string) get_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED, true );
		return ! $reviewed && $this->passes_candidate_quality_gate( $attachment_id );
	}

	/**
	 * Candidate quality gate shared by global review and the 20-image test panel.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function passes_candidate_quality_gate( $attachment_id ) {
		$current_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$status      = (string) get_post_meta( $attachment_id, WIAA_Image_Scanner::META_STATUS, true );
		$intent      = sanitize_key( (string) get_post_meta( $attachment_id, WIAA_Image_Scanner::META_INTENT, true ) );
		$candidate   = trim( (string) get_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE, true ) );

		if ( '' !== $current_alt || 'candidate' !== $status || 'content' !== $intent || '' === $candidate ) {
			return false;
		}

		$metrics = $this->analyse_candidate( $candidate );
		return ! $metrics['looks_json'] && $metrics['length_ok'] && $metrics['words_ok'];
	}

	/**
	 * Return lightweight metrics for review guidance. These are not an SEO score.
	 *
	 * @param string $candidate Candidate ALT.
	 * @return array<string,mixed>
	 */
	private function analyse_candidate( $candidate ) {
		$candidate = trim( (string) $candidate );
		$length    = function_exists( 'mb_strlen' ) ? mb_strlen( $candidate ) : strlen( $candidate );
		$looks_json = (bool) preg_match( '/^\s*[\{\[]|"(?:type|alt|reason)"\s*:/iu', $candidate );
		$is_cjk     = (bool) preg_match( '/[\x{3400}-\x{9FFF}]/u', $candidate );
		$words      = 0;

		if ( ! $is_cjk && '' !== $candidate ) {
			preg_match_all( "/[A-Za-z0-9][A-Za-z0-9'’-]*/u", $candidate, $matches );
			$words = isset( $matches[0] ) ? count( $matches[0] ) : 0;
		}

		$length_ok = $length >= 8 && $length <= 160;
		$words_ok  = $is_cjk ? $length <= 80 : ( $words >= 3 && $words <= 25 );

		return array(
			'chars'      => $length,
			'words'      => $words,
			'is_cjk'     => $is_cjk,
			'looks_json' => $looks_json,
			'length_ok'  => $length_ok,
			'words_ok'   => $words_ok,
		);
	}

	/**
	 * Build the visible results for a small test-generation task.
	 *
	 * Only processed IDs are returned and test tasks are capped at a small limit,
	 * so exposing these rows does not turn normal site-wide jobs into large AJAX
	 * payloads.
	 *
	 * @param array<string,mixed> $task Internal task.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_test_results( array $task ) {
		if ( empty( $task['is_test'] ) || empty( $task['ids'] ) ) {
			return array();
		}

		$processed = min( count( $task['ids'] ), max( 0, (int) $task['processed'] ) );
		if ( $processed < 1 ) {
			return array();
		}

		$ids = array_slice( $task['ids'], 0, $processed );
		update_meta_cache( 'post', $ids );

		$results = array();
		foreach ( $ids as $attachment_id ) {
			$attachment = get_post( $attachment_id );
			if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
				continue;
			}

			$item    = $this->scanner->format_item( $attachment );
			$metrics = $this->analyse_candidate( $item['candidate'] );
			$eligible = $this->passes_candidate_quality_gate( $attachment_id );
			$quality  = 'manual';
			$quality_label = '建议人工检查';

			if ( 'complete' === $item['status'] ) {
				$quality = 'good';
				$quality_label = '已应用 ALT';
			} elseif ( 'no_alt' === $item['status'] ) {
				$quality = 'manual';
				$quality_label = '已确认无需 ALT';
			} elseif ( 'failed' === $item['status'] ) {
				$quality = 'failed';
				$quality_label = '生成失败';
			} elseif ( 'decorative' === $item['intent'] ) {
				$quality = 'manual';
				$quality_label = '装饰图，人工确认';
			} elseif ( 'uncertain' === $item['intent'] ) {
				$quality = 'manual';
				$quality_label = 'AI 不确定';
			} elseif ( $eligible ) {
				$quality = 'good';
				$quality_label = '长度与结构合适';
			} elseif ( $metrics['looks_json'] ) {
				$quality = 'warning';
				$quality_label = '疑似 JSON，需要检查';
			} elseif ( (int) $metrics['chars'] > 160 ) {
				$quality = 'warning';
				$quality_label = 'ALT 偏长';
			} elseif ( '' === $item['candidate'] ) {
				$quality = 'warning';
				$quality_label = '没有可用候选';
			}

			$results[] = array(
				'id'               => $item['id'],
				'title'            => $item['title'],
				'filename'         => $item['filename'],
				'url'              => $item['url'],
				'thumb_url'        => $item['thumb_url'],
				'candidate'        => $item['candidate'],
				'status'           => $item['status'],
				'reviewed'         => $item['reviewed'],
				'intent'           => $item['intent'],
				'ai_note'          => $item['ai_note'],
				'error'            => $item['error'],
				'model'            => $item['model'],
				'generated_at'     => $item['generated_at'],
				'quality'          => $quality,
				'quality_label'    => $quality_label,
				'quality_eligible' => $eligible,
				'chars'            => (int) $metrics['chars'],
				'words'            => (int) $metrics['words'],
				'is_cjk'           => (bool) $metrics['is_cjk'],
			);
		}

		return $results;
	}

	private function advance( array &$task, $result ) {
		$task['cursor']    = (int) $task['cursor'] + 1;
		$task['processed'] = (int) $task['processed'] + 1;

		if ( isset( $task[ $result ] ) ) {
			$task[ $result ] = (int) $task[ $result ] + 1;
		}
	}

	private function get_task() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$task = get_user_meta( $user_id, self::USER_META_TASK, true );
		return is_array( $task ) && ! empty( $task['id'] ) ? $task : null;
	}

	private function save_task( array $task ) {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::USER_META_TASK, $task );
		}
	}

	private function touch_and_save( array $task ) {
		$task['updated_at'] = current_time( 'mysql' );
		$this->save_task( $task );
	}

	private function to_public_task( array $task ) {
		$public = $task;
		$public['test_results'] = array();

		if ( ! empty( $task['is_test'] ) && (int) $task['processed'] > 0 && in_array( $task['status'], array( 'completed', 'paused', 'stopped', 'error' ), true ) ) {
			$public['test_results'] = $this->get_test_results( $task );
		}

		$public['current_item'] = null;
		if ( 'generate' === $task['operation'] && isset( $task['ids'][ $task['cursor'] ] ) ) {
			$current_id = absint( $task['ids'][ $task['cursor'] ] );
			$current    = $current_id ? get_post( $current_id ) : null;
			if ( $current instanceof WP_Post && 'attachment' === $current->post_type ) {
				$file = get_attached_file( $current_id );
				$public['current_item'] = array(
					'id'       => $current_id,
					'title'    => get_the_title( $current_id ),
					'filename' => $file ? wp_basename( $file ) : '',
				);
			}
		}

		unset( $public['ids'] );

		$public['remaining'] = max( 0, (int) $public['total'] - (int) $public['processed'] );
		$public['percent']   = (int) $public['total'] > 0 ? min( 100, (int) round( ( (int) $public['processed'] / (int) $public['total'] ) * 100 ) ) : 0;
		$public['wait_seconds'] = 'cooldown' === $public['status'] ? max( 0, (int) $public['cooldown_until'] - time() ) : 0;

		return $public;
	}
}
