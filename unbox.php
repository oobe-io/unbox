<?php
/**
 * Plugin Name:       Unbox
 * Description:       WordPress サイトの書き出し・取り込み（移行）。容量制限なし。.unbox 形式（.wpress 互換）と、LocalWP にそのまま取り込める zip に対応。
 * Version:           0.3.0
 * Requires at least: 5.3
 * Requires PHP:      7.4
 * Author:            oobe
 * Author URI:        https://oobe-io.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       unbox
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UNBOX_VERSION', '0.3.0' );
define( 'UNBOX_DIR', plugin_dir_path( __FILE__ ) );
define( 'UNBOX_URL', plugin_dir_url( __FILE__ ) );
define( 'UNBOX_BASENAME', plugin_basename( __FILE__ ) );

// 表のページでは何も読み込まない。管理画面（移行処理の admin-ajax も含む）でだけ動く。
if ( is_admin() ) {
	require_once UNBOX_DIR . 'includes/class-unbox-wpress.php';
	require_once UNBOX_DIR . 'includes/class-unbox-zip.php';
	require_once UNBOX_DIR . 'includes/class-unbox-replacer.php';
	require_once UNBOX_DIR . 'includes/class-unbox-sql.php';
	require_once UNBOX_DIR . 'includes/class-unbox-storage.php';
	require_once UNBOX_DIR . 'includes/class-unbox-guard.php';
	require_once UNBOX_DIR . 'includes/class-unbox-dumper.php';
	require_once UNBOX_DIR . 'includes/class-unbox-export.php';
	require_once UNBOX_DIR . 'includes/class-unbox-import.php';
	require_once UNBOX_DIR . 'includes/class-unbox-admin.php';
	Unbox_Admin::init();
	add_action(
		'init',
		function () {
			// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Loads the bundled Japanese translation until it is available from translate.wordpress.org.
			load_plugin_textdomain( 'unbox', false, dirname( UNBOX_BASENAME ) . '/languages' );
		}
	);
}

register_deactivation_hook(
	__FILE__,
	function () {
		require_once UNBOX_DIR . 'includes/class-unbox-guard.php';
		Unbox_Guard::remove();
	}
);

/**
 * 拡張（将来の有料版など）はここに処理を足す。
 * 例: add_action( 'unbox_loaded', function () { ... } );
 */
do_action( 'unbox_loaded' );
