<?php
/**
 * Plugin Name: WEM Image ALT Assistant
 * Plugin URI:  https://github.com/coowinit/seo-image-alt
 * Description: 扫描 WordPress 媒体库图片，结合页面上下文通过 DeepSeek Vision 生成候选 ALT，支持人工审核、安全写入与大型媒体库全站批量任务。
 * Version:     1.1.2
 * Author:      COOWIN
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wem-image-alt-assistant
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WIAA_VERSION', '1.1.2' );
define( 'WIAA_FILE', __FILE__ );
define( 'WIAA_PATH', plugin_dir_path( __FILE__ ) );
define( 'WIAA_URL', plugin_dir_url( __FILE__ ) );

define( 'WIAA_CAP_USE', 'wiaa_use_image_alt_assistant' );
define( 'WIAA_CAP_MANAGE', 'wiaa_manage_image_alt_assistant' );
define( 'WIAA_CAPABILITIES_VERSION', '1.0' );

require_once WIAA_PATH . 'includes/class-wiaa-image-scanner.php';
require_once WIAA_PATH . 'includes/class-wiaa-image-context.php';
require_once WIAA_PATH . 'includes/class-wiaa-deepseek-client.php';
require_once WIAA_PATH . 'includes/class-wiaa-alt-generator.php';
require_once WIAA_PATH . 'includes/class-wiaa-frontend-auditor.php';
require_once WIAA_PATH . 'includes/class-wiaa-legacy-candidate-migrator.php';
require_once WIAA_PATH . 'includes/class-wiaa-bulk-task-manager.php';
require_once WIAA_PATH . 'admin/class-wiaa-admin.php';

/**
 * Install default capabilities.
 *
 * Administrator: use + manage.
 * Editor: use only.
 *
 * @return void
 */
function wiaa_install_capabilities() {
	$administrator = get_role( 'administrator' );

	if ( $administrator ) {
		$administrator->add_cap( WIAA_CAP_USE );
		$administrator->add_cap( WIAA_CAP_MANAGE );
	}

	$editor = get_role( 'editor' );

	if ( $editor ) {
		$editor->add_cap( WIAA_CAP_USE );
	}

	update_option( 'wiaa_capabilities_version', WIAA_CAPABILITIES_VERSION );
}

/**
 * Add capabilities once when upgrading from an older version.
 *
 * @return void
 */
function wiaa_maybe_install_capabilities() {
	$current_version = (string) get_option( 'wiaa_capabilities_version', '' );

	if ( WIAA_CAPABILITIES_VERSION !== $current_version ) {
		wiaa_install_capabilities();
	}
}

function wiaa_activate() {
	wiaa_install_capabilities();
}
register_activation_hook( WIAA_FILE, 'wiaa_activate' );

function wiaa_boot() {
	wiaa_maybe_install_capabilities();

	$scanner   = new WIAA_Image_Scanner();
	$context   = new WIAA_Image_Context();
	$deepseek  = new WIAA_DeepSeek_Client();
	$generator = new WIAA_Alt_Generator( $context, $deepseek );
	$auditor   = new WIAA_Frontend_Auditor();
	$migrator  = new WIAA_Legacy_Candidate_Migrator( $generator );
	$bulk      = new WIAA_Bulk_Task_Manager( $scanner, $generator, $deepseek );

	$migrator->register();

	$admin = new WIAA_Admin( $scanner, $deepseek, $generator, $auditor, $bulk );
	$admin->register();
}
add_action( 'plugins_loaded', 'wiaa_boot' );
