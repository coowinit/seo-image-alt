<?php
/**
 * Minimal DeepSeek Vision client.
 *
 * @package WEM_Image_ALT_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIAA_DeepSeek_Client {

	const API_URL = 'https://api.deepseek.com/chat/completions';
	const OPTION_API_KEY = 'wiaa_deepseek_api_key';
	const OPTION_MODEL   = 'wiaa_deepseek_model';
	const DEFAULT_MODEL  = 'deepseek-v4-flash-vision-exp';

	/**
	 * Vision models supported by this plugin release.
	 *
	 * @return array<string,string>
	 */
	public function get_supported_models() {
		return array(
			'deepseek-v4-flash-vision-exp' => 'DeepSeek V4 Flash Vision Exp',
		);
	}

	public function get_api_key() {
		if ( defined( 'WIAA_DEEPSEEK_API_KEY' ) && is_string( WIAA_DEEPSEEK_API_KEY ) ) {
			return trim( WIAA_DEEPSEEK_API_KEY );
		}

		$key = get_option( self::OPTION_API_KEY, '' );
		return is_string( $key ) ? trim( $key ) : '';
	}

	public function get_api_key_source() {
		if ( defined( 'WIAA_DEEPSEEK_API_KEY' ) && '' !== $this->get_api_key() ) {
			return 'constant';
		}

		if ( '' !== $this->get_api_key() ) {
			return 'option';
		}

		return 'none';
	}

	public function is_configured() {
		return '' !== $this->get_api_key();
	}

	public function get_model() {
		$model  = get_option( self::OPTION_MODEL, self::DEFAULT_MODEL );
		$models = $this->get_supported_models();

		if ( ! is_string( $model ) || ! isset( $models[ $model ] ) ) {
			return self::DEFAULT_MODEL;
		}

		return $model;
	}

	/**
	 * Verify both API connectivity and vision input.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function test_connection() {
		// 1×1 PNG. The test intentionally sends no WordPress content.
		$image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are an API connection test. Reply only with WIAA_OK.',
			),
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Return exactly WIAA_OK. The image is only used to verify vision input.',
					),
					array(
						'type'      => 'image_url',
						'image_url' => array(
							'url' => $image,
						),
					),
				),
			),
		);

		return $this->chat(
			$messages,
			array(
				'max_tokens'  => 32,
				'temperature' => 0.0,
				'thinking'    => array( 'type' => 'disabled' ),
			)
		);
	}

	/**
	 * Send an image + context request.
	 *
	 * @param string $image_source Public URL or data URL.
	 * @param string $prompt       Generation prompt.
	 * @return array<string,mixed>|WP_Error
	 */
	public function generate_alt( $image_source, $prompt ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You write concise, factual, accessibility-first HTML image alt text for websites. Never invent product facts that are not visible in the image or supplied in the context.',
			),
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => $prompt,
					),
					array(
						'type'      => 'image_url',
						'image_url' => array(
							'url' => $image_source,
						),
					),
				),
			),
		);

		return $this->chat(
			$messages,
			array(
				'max_tokens'  => 256,
				'temperature' => 0.1,
				'thinking'    => array( 'type' => 'disabled' ),
			)
		);
	}

	/**
	 * Send a non-streaming OpenAI-compatible Chat Completions request.
	 *
	 * @param array<int,array<string,mixed>> $messages Messages.
	 * @param array<string,mixed>            $args     Body overrides.
	 * @return array<string,mixed>|WP_Error
	 */
	public function chat( array $messages, array $args = array() ) {
		$api_key = $this->get_api_key();

		if ( '' === $api_key ) {
			return new WP_Error(
				'wiaa_deepseek_missing_key',
				'尚未配置 DeepSeek API Key。'
			);
		}

		$request_model = $this->get_model();

		$body = array_merge(
			array(
				'model'    => $request_model,
				'messages' => $messages,
				'stream'   => false,
			),
			$args
		);

		$started_at = microtime( true );
		$response   = wp_remote_post(
			self::API_URL,
			array(
				'timeout'     => 90,
				'redirection' => 2,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);
		$elapsed_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wiaa_deepseek_transport_error',
				'无法连接 DeepSeek API：' . $response->get_error_message()
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = '';

			if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
				$message = $decoded['error']['message'];
			}

			if ( '' === $message ) {
				$message = sprintf( 'DeepSeek API 返回 HTTP %d。', $status_code );
			}

			return new WP_Error(
				'wiaa_deepseek_api_error',
				$message,
				array( 'status_code' => $status_code )
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wiaa_deepseek_invalid_json',
				'DeepSeek API 返回了无法解析的响应。'
			);
		}

		$message       = isset( $decoded['choices'][0]['message'] ) && is_array( $decoded['choices'][0]['message'] ) ? $decoded['choices'][0]['message'] : array();
		$finish_reason = isset( $decoded['choices'][0]['finish_reason'] ) && is_string( $decoded['choices'][0]['finish_reason'] ) ? $decoded['choices'][0]['finish_reason'] : '';
		$content       = $this->extract_message_text( $message );

		if ( '' === $content ) {
			$debug = $this->build_response_debug( $message, $finish_reason );

			return new WP_Error(
				'wiaa_deepseek_empty_response',
				'DeepSeek API 请求成功，但当前响应中没有提取到可用正文。' . ( $finish_reason ? ' finish_reason=' . $finish_reason . '。' : '' ),
				array(
					'status_code'    => $status_code,
					'finish_reason'  => $finish_reason,
					'response_debug' => $debug,
				)
			);
		}

		return array(
			'content'       => $content,
			'request_model' => $request_model,
			'model'         => isset( $decoded['model'] ) && is_string( $decoded['model'] ) ? $decoded['model'] : $request_model,
			'usage'         => isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ? $decoded['usage'] : array(),
			'finish_reason' => $finish_reason,
			'elapsed_ms'    => $elapsed_ms,
			'status_code'   => $status_code,
		);
	}

	/**
	 * Extract readable text from several OpenAI-compatible message shapes.
	 *
	 * Vision endpoints may return message.content as a string or as an array of
	 * typed text parts. Some compatible providers also expose text in alternate
	 * fields. Keep the transport tolerant and leave business JSON parsing to the
	 * ALT generator.
	 *
	 * @param array<string,mixed> $message Response message.
	 * @return string
	 */
	private function extract_message_text( array $message ) {
		$candidates = array();

		if ( isset( $message['content'] ) ) {
			if ( is_string( $message['content'] ) ) {
				$candidates[] = $message['content'];
			} elseif ( is_array( $message['content'] ) ) {
				foreach ( $message['content'] as $part ) {
					if ( is_string( $part ) ) {
						$candidates[] = $part;
						continue;
					}

					if ( ! is_array( $part ) ) {
						continue;
					}

					foreach ( array( 'text', 'content', 'value' ) as $key ) {
						if ( isset( $part[ $key ] ) && is_string( $part[ $key ] ) ) {
							$candidates[] = $part[ $key ];
						}
					}
				}
			}
		}

		foreach ( array( 'text', 'output_text', 'final', 'answer' ) as $key ) {
			if ( isset( $message[ $key ] ) && is_string( $message[ $key ] ) ) {
				$candidates[] = $message[ $key ];
			}
		}

		// Last-resort compatibility: if a provider placed only a compact JSON
		// final object in reasoning_content, accept that object. Do not surface or
		// store free-form reasoning text.
		if ( empty( $candidates ) && isset( $message['reasoning_content'] ) && is_string( $message['reasoning_content'] ) ) {
			$reasoning = trim( $message['reasoning_content'] );
			if ( strlen( $reasoning ) <= 2000 && preg_match( '/^\s*\{.*\}\s*$/s', $reasoning ) ) {
				$candidates[] = $reasoning;
			}
		}

		$candidates = array_values( array_filter( array_map( 'trim', $candidates ) ) );

		return empty( $candidates ) ? '' : trim( implode( "\n", $candidates ) );
	}

	/**
	 * Build a small response-shape diagnostic without storing image data, API
	 * keys, or the complete provider response.
	 *
	 * @param array<string,mixed> $message       Response message.
	 * @param string              $finish_reason Finish reason.
	 * @return string
	 */
	private function build_response_debug( array $message, $finish_reason ) {
		$shape = array(
			'message_keys'  => array_keys( $message ),
			'content_type'  => isset( $message['content'] ) ? gettype( $message['content'] ) : 'missing',
			'finish_reason' => (string) $finish_reason,
		);

		if ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
			$shape['content_parts'] = count( $message['content'] );
		}

		return wp_json_encode( $shape, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

}
