<?php
/**
 * WordPress admin UI.
 *
 * @package WEM_Image_ALT_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIAA_Admin {

	private $scanner;
	private $deepseek;
	private $generator;
	private $auditor;
	private $page_hook = '';
	private $audit_hook = '';
	private $settings_hook = '';

	public function __construct( WIAA_Image_Scanner $scanner, WIAA_DeepSeek_Client $deepseek, WIAA_Alt_Generator $generator, WIAA_Frontend_Auditor $auditor ) {
		$this->scanner   = $scanner;
		$this->deepseek  = $deepseek;
		$this->generator = $generator;
		$this->auditor   = $auditor;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_post_wiaa_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_wiaa_test_connection', array( $this, 'handle_test_connection' ) );

		add_action( 'wp_ajax_wiaa_generate_alt', array( $this, 'ajax_generate_alt' ) );
		add_action( 'wp_ajax_wiaa_save_candidate', array( $this, 'ajax_save_candidate' ) );
		add_action( 'wp_ajax_wiaa_set_reviewed', array( $this, 'ajax_set_reviewed' ) );
		add_action( 'wp_ajax_wiaa_apply_candidate', array( $this, 'ajax_apply_candidate' ) );
		add_action( 'wp_ajax_wiaa_mark_no_alt', array( $this, 'ajax_mark_no_alt' ) );
		add_action( 'wp_ajax_wiaa_restore_pending', array( $this, 'ajax_restore_pending' ) );
		add_action( 'wp_ajax_wiaa_get_counts', array( $this, 'ajax_get_counts' ) );
	}

	public function register_menu() {
		$this->page_hook = add_menu_page(
			'WEM Image ALT Assistant',
			'Image ALT',
			WIAA_CAP_USE,
			'wem-image-alt-assistant',
			array( $this, 'render_library_page' ),
			'dashicons-format-image',
			58
		);

		add_submenu_page(
			'wem-image-alt-assistant',
			'图片 ALT',
			'图片 ALT',
			WIAA_CAP_USE,
			'wem-image-alt-assistant',
			array( $this, 'render_library_page' )
		);

		$this->audit_hook = add_submenu_page(
			'wem-image-alt-assistant',
			'前台 ALT 验证',
			'前台 ALT 验证',
			WIAA_CAP_USE,
			'wem-image-alt-assistant-audit',
			array( $this, 'render_frontend_audit_page' )
		);

		$this->settings_hook = add_submenu_page(
			'wem-image-alt-assistant',
			'DeepSeek 设置',
			'DeepSeek 设置',
			WIAA_CAP_MANAGE,
			'wem-image-alt-assistant-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( $this->page_hook, $this->audit_hook, $this->settings_hook ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'wiaa-admin',
			WIAA_URL . 'assets/css/admin.css',
			array(),
			WIAA_VERSION
		);

		if ( $hook_suffix === $this->page_hook ) {
			wp_enqueue_script(
				'wiaa-admin',
				WIAA_URL . 'assets/js/admin.js',
				array(),
				WIAA_VERSION,
				true
			);

			wp_localize_script(
				'wiaa-admin',
				'wiaaAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wiaa_admin' ),
					'canManage' => current_user_can( WIAA_CAP_MANAGE ),
					'strings' => array(
						'generating' => '正在生成…',
						'applying'   => '正在应用…',
						'done'       => '完成',
						'failed'     => '失败',
					),
				)
			);
		}
	}

	public function render_library_page() {
		if ( ! current_user_can( WIAA_CAP_USE ) ) {
			wp_die( esc_html__( '您没有权限访问此页面。', 'wem-image-alt-assistant' ) );
		}

		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$allowed = array( 'pending', 'candidate', 'reviewed', 'no_alt', 'failed', 'complete', 'all' );

		if ( 'missing' === $status ) {
			$status = 'pending';
		}

		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'pending';
		}

		$result = $this->scanner->query(
			array(
				'status' => $status,
				'search' => $search,
				'page'   => $page,
			)
		);
		$counts        = $this->scanner->get_counts();
		$is_configured = $this->deepseek->is_configured();

		include WIAA_PATH . 'admin/views/media-library.php';
	}

	public function render_frontend_audit_page() {
		if ( ! current_user_can( WIAA_CAP_USE ) ) {
			wp_die( esc_html__( '您没有权限访问此页面。', 'wem-image-alt-assistant' ) );
		}

		$target = isset( $_GET['wiaa_audit_target'] ) ? sanitize_text_field( wp_unslash( $_GET['wiaa_audit_target'] ) ) : '';
		$audit  = null;

		if ( '' !== $target ) {
			$nonce = isset( $_GET['_wiaa_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wiaa_nonce'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'wiaa_frontend_audit' ) ) {
				$audit = new WP_Error( 'wiaa_audit_nonce', '验证请求已过期，请重新提交。' );
			} else {
				$audit = $this->auditor->audit( $target );
			}
		}

		include WIAA_PATH . 'admin/views/frontend-audit.php';
	}

	public function render_settings_page() {
		if ( ! current_user_can( WIAA_CAP_MANAGE ) ) {
			wp_die( esc_html__( '您没有权限管理此插件设置。', 'wem-image-alt-assistant' ) );
		}

		$models        = $this->deepseek->get_supported_models();
		$current_model = $this->deepseek->get_model();
		$languages     = $this->generator->get_supported_languages();
		$current_language = $this->generator->get_alt_language();
		$key_source    = $this->deepseek->get_api_key_source();
		$is_configured = $this->deepseek->is_configured();
		$notice        = isset( $_GET['wiaa_notice'] ) ? sanitize_key( wp_unslash( $_GET['wiaa_notice'] ) ) : '';
		$test_result   = get_transient( 'wiaa_connection_test_' . get_current_user_id() );

		if ( false !== $test_result ) {
			delete_transient( 'wiaa_connection_test_' . get_current_user_id() );
		}

		include WIAA_PATH . 'admin/views/deepseek-settings.php';
	}

	public function handle_save_settings() {
		if ( ! current_user_can( WIAA_CAP_MANAGE ) ) {
			wp_die( esc_html__( '权限不足。', 'wem-image-alt-assistant' ) );
		}

		check_admin_referer( 'wiaa_save_settings' );

		if ( ! defined( 'WIAA_DEEPSEEK_API_KEY' ) ) {
			if ( ! empty( $_POST['wiaa_clear_api_key'] ) ) {
				delete_option( WIAA_DeepSeek_Client::OPTION_API_KEY );
			} elseif ( isset( $_POST['wiaa_deepseek_api_key'] ) ) {
				$key = trim( sanitize_text_field( wp_unslash( $_POST['wiaa_deepseek_api_key'] ) ) );
				if ( '' !== $key ) {
					update_option( WIAA_DeepSeek_Client::OPTION_API_KEY, $key, false );
				}
			}
		}

		$models = $this->deepseek->get_supported_models();
		$model  = isset( $_POST['wiaa_deepseek_model'] ) ? sanitize_text_field( wp_unslash( $_POST['wiaa_deepseek_model'] ) ) : '';

		if ( isset( $models[ $model ] ) ) {
			update_option( WIAA_DeepSeek_Client::OPTION_MODEL, $model, false );
		}

		$languages = $this->generator->get_supported_languages();
		$language  = isset( $_POST['wiaa_alt_language'] ) ? sanitize_text_field( wp_unslash( $_POST['wiaa_alt_language'] ) ) : '';

		if ( isset( $languages[ $language ] ) ) {
			update_option( WIAA_Alt_Generator::OPTION_ALT_LANGUAGE, $language, false );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'wem-image-alt-assistant-settings',
					'wiaa_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_test_connection() {
		if ( ! current_user_can( WIAA_CAP_MANAGE ) ) {
			wp_die( esc_html__( '权限不足。', 'wem-image-alt-assistant' ) );
		}

		check_admin_referer( 'wiaa_test_connection' );

		$result = $this->deepseek->test_connection();

		if ( is_wp_error( $result ) ) {
			$data = array(
				'ok'      => false,
				'message' => $result->get_error_message(),
			);
		} else {
			$data = array(
				'ok'   => true,
				'data' => $result,
			);
		}

		set_transient( 'wiaa_connection_test_' . get_current_user_id(), $data, MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'wem-image-alt-assistant-settings' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function ajax_generate_alt() {
		$this->check_ajax_permission();
		$attachment_id = $this->get_editable_attachment_id();

		$result = $this->generator->generate_candidate( $attachment_id );

		if ( is_wp_error( $result ) ) {
			$this->send_error_with_counts( $result->get_error_message() );
		}

		$result['counts'] = $this->scanner->get_counts();
		wp_send_json_success( $result );
	}

	public function ajax_save_candidate() {
		$this->check_ajax_permission();
		$attachment_id = $this->get_editable_attachment_id();
		$candidate = isset( $_POST['candidate'] ) ? sanitize_text_field( wp_unslash( $_POST['candidate'] ) ) : '';

		$result = $this->generator->save_candidate( $attachment_id, $candidate );

		if ( is_wp_error( $result ) ) {
			$this->send_error_with_counts( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'alt'      => $result,
				'reviewed' => false,
				'counts'   => $this->scanner->get_counts(),
			)
		);
	}

	public function ajax_set_reviewed() {
		$this->check_ajax_permission();
		$attachment_id = $this->get_editable_attachment_id();
		$candidate = isset( $_POST['candidate'] ) ? sanitize_text_field( wp_unslash( $_POST['candidate'] ) ) : '';
		$reviewed  = ! empty( $_POST['reviewed'] );

		$result = $this->generator->set_reviewed( $attachment_id, $candidate, $reviewed );

		if ( is_wp_error( $result ) ) {
			$this->send_error_with_counts( $result->get_error_message() );
		}

		$result['counts'] = $this->scanner->get_counts();
		wp_send_json_success( $result );
	}

	public function ajax_apply_candidate() {
		$this->check_ajax_permission();
		$attachment_id = $this->get_editable_attachment_id();
		$candidate = isset( $_POST['candidate'] ) ? sanitize_text_field( wp_unslash( $_POST['candidate'] ) ) : '';

		$result = $this->generator->apply_candidate( $attachment_id, $candidate );

		if ( is_wp_error( $result ) ) {
			$this->send_error_with_counts( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'alt'    => $result,
				'counts' => $this->scanner->get_counts(),
			)
		);
	}

	public function ajax_mark_no_alt() {
		$this->check_ajax_permission();
		$attachment_id = $this->get_editable_attachment_id();
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';

		$result = $this->generator->mark_no_alt( $attachment_id, $mode );

		if ( is_wp_error( $result ) ) {
			$this->send_error_with_counts( $result->get_error_message() );
		}

		$result['counts'] = $this->scanner->get_counts();
		wp_send_json_success( $result );
	}

	public function ajax_restore_pending() {
		$this->check_ajax_permission();
		$attachment_id = $this->get_editable_attachment_id();

		$result = $this->generator->restore_pending( $attachment_id );

		if ( is_wp_error( $result ) ) {
			$this->send_error_with_counts( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'status' => 'pending',
				'counts' => $this->scanner->get_counts(),
			)
		);
	}

	public function ajax_get_counts() {
		$this->check_ajax_permission();
		wp_send_json_success( array( 'counts' => $this->scanner->get_counts() ) );
	}

	private function get_editable_attachment_id() {
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => '您没有权限编辑这张媒体图片。' ), 403 );
		}

		return $attachment_id;
	}

	private function send_error_with_counts( $message ) {
		wp_send_json_error(
			array(
				'message' => $message,
				'counts'  => $this->scanner->get_counts(),
			)
		);
	}

	private function check_ajax_permission() {
		check_ajax_referer( 'wiaa_admin', 'nonce' );

		if ( ! current_user_can( WIAA_CAP_USE ) ) {
			wp_send_json_error( array( 'message' => '权限不足。' ), 403 );
		}
	}
}
