<?php
/**
 * 移行処理のリクエストだけ、他のプラグインとテーマを読み込ませない仕掛け（mu-plugin）。
 *
 * 取り込み中はプラグインのファイルが新しいサイトのものへ置き換わっていくため、
 * 古い DB と新しいプラグインの組み合わせで管理画面が落ちることがある。
 * 移行処理のリクエストがそれに巻き込まれないよう、処理中だけ mu-plugins に置く。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; multi-gigabyte archives are read and written with native file handles.

class Unbox_Guard {
	const FILE = 'unbox-guard.php';

	public static function path() {
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		return $dir . '/' . self::FILE;
	}

	public static function install() {
		$path = self::path();
		if ( ! is_dir( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
			return false;
		}
		$code = "<?php\n"
			. "/**\n * Unbox: during migration requests only, do not load other plugins or the theme.\n * This file is removed automatically when the migration finishes. If left behind, it affects nothing but Unbox requests.\n */\n"
			. "if ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( \$_REQUEST['action'] ) && strpos( (string) \$_REQUEST['action'], 'unbox_' ) === 0 ) {\n"
			. "\t\$unbox_plugin = " . "'" . addcslashes( UNBOX_BASENAME, "'\\\\" ) . "'" . ";\n"
			. "\tif ( is_file( WP_PLUGIN_DIR . '/' . \$unbox_plugin ) ) {\n"
			. "\t\tadd_filter( 'option_active_plugins', function () use ( \$unbox_plugin ) { return array( \$unbox_plugin ); }, PHP_INT_MAX );\n"
			. "\t\tadd_filter( 'site_option_active_sitewide_plugins', function () { return array(); }, PHP_INT_MAX );\n"
			. "\t\t\$unbox_no_theme = function () { return WP_CONTENT_DIR . '/unbox-no-theme'; };\n"
			. "\t\tadd_filter( 'template_directory', \$unbox_no_theme, PHP_INT_MAX );\n"
			. "\t\tadd_filter( 'stylesheet_directory', \$unbox_no_theme, PHP_INT_MAX );\n"
			. "\t}\n"
			. "}\n";
		return (bool) @file_put_contents( $path, $code );
	}

	public static function remove() {
		if ( is_file( self::path() ) ) {
			@unlink( self::path() );
		}
	}

	/** ほかに進行中のジョブが無ければ外す。 */
	public static function remove_if_idle( $except = '' ) {
		$jobs = Unbox_Storage::dir( 'jobs' );
		foreach ( (array) @scandir( $jobs ) as $id ) {
			if ( $id === $except || ! preg_match( '/^[a-f0-9]{16}$/', $id ) ) {
				continue;
			}
			$state = json_decode( (string) @file_get_contents( "$jobs/$id/state.json" ), true );
			if ( $state && $state['stage'] !== 'done' && time() - (int) $state['created'] < DAY_IN_SECONDS ) {
				return;
			}
		}
		self::remove();
	}
}
