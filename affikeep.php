<?php
/**
 * Plugin Name: AffiKeep
 * Plugin URI:  https://hlc-zuigen.xyz
 * Description: アフィリエイト収益管理コックピット。リンク切れチェック・記事別クリック計測・商品管理を一画面で。
 * Version:     0.8.0
 * Author:      Yasuhiro Ueda
 * Author URI:  https://yasuhiro.me
 * Text Domain: affikeep
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * GitHub Plugin URI: healingyasu/affikeep
 * Primary Branch: main
 */

defined( 'ABSPATH' ) || exit;

define( 'AFFIKEEP_VERSION', '0.8.0' );
define( 'AFFIKEEP_BUILD',   '2026-07-10 (Pro: 対応モール拡張（楽天トラベル・Booking.com）を追加)' );
define( 'AFFIKEEP_DIR',     plugin_dir_path( __FILE__ ) );
define( 'AFFIKEEP_URL',     plugin_dir_url( __FILE__ ) );
define( 'AFFIKEEP_SLUG',    'affikeep' );

require_once AFFIKEEP_DIR . 'includes/class-logger.php';
require_once AFFIKEEP_DIR . 'includes/class-license.php';
require_once AFFIKEEP_DIR . 'includes/class-malls.php';
require_once AFFIKEEP_DIR . 'includes/class-post-type.php';
require_once AFFIKEEP_DIR . 'includes/class-settings.php';
require_once AFFIKEEP_DIR . 'includes/class-admin.php';
require_once AFFIKEEP_DIR . 'includes/class-block.php';
require_once AFFIKEEP_DIR . 'includes/class-meta-box.php';
require_once AFFIKEEP_DIR . 'includes/class-amazon-paapi.php';
require_once AFFIKEEP_DIR . 'includes/class-rest-api.php';
require_once AFFIKEEP_DIR . 'includes/class-link-checker.php';
require_once AFFIKEEP_DIR . 'includes/class-rinker-import.php';
require_once AFFIKEEP_DIR . 'includes/class-cleanup.php';
require_once AFFIKEEP_DIR . 'includes/class-analytics.php';
require_once AFFIKEEP_DIR . 'includes/class-csv-export.php';

register_activation_hook( __FILE__,   [ 'AffiKeep_Admin', 'on_activate' ] );
register_deactivation_hook( __FILE__, [ 'AffiKeep_Admin', 'on_deactivate' ] );

add_action( 'plugins_loaded', function () {
	AffiKeep_License::init();
	AffiKeep_Post_Type::init();
	AffiKeep_Settings::init();
	AffiKeep_Admin::init();
	AffiKeep_Block::init();
	AffiKeep_Meta_Box::init();
	AffiKeep_Rest_API::init();
	AffiKeep_Link_Checker::init();
	AffiKeep_Rinker_Import::init();
	AffiKeep_Cleanup::init();
	AffiKeep_Analytics::init();
	AffiKeep_CSV_Export::init();
} );
