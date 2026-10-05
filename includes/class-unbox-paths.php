<?php
/**
 * 移行で扱うフォルダーの場所を1か所で決める。
 *
 * サイトを丸ごと運ぶので、WordPress のフォルダー・wp-content・プラグインのフォルダーそのものが対象になる。
 * wp-content の場所と WordPress のフォルダーを返す関数は WordPress に無いため、ここでだけ定数を読む。
 * 設定で場所を変えたサイト（wp-content を外に出した構成など）でも、その値に従う。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Unbox_Paths {
	/** WordPress 本体のフォルダー（wp-admin・wp-includes がある所）。 */
	public static function site_root() {
		return untrailingslashit( ABSPATH );
	}

	/** wp-content のフォルダー。 */
	public static function content_dir() {
		return untrailingslashit( WP_CONTENT_DIR );
	}

	/** プラグインのフォルダー（このプラグインが入っているフォルダーの親）。 */
	public static function plugins_dir() {
		return dirname( untrailingslashit( UNBOX_DIR ) );
	}

	/** WordPress のフォルダー直下から見た wp-content の名前（直下に無ければ wp-content）。 */
	public static function content_name() {
		$content = self::content_dir();
		return dirname( $content ) === self::site_root() ? basename( $content ) : 'wp-content';
	}
}
