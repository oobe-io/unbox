<?php
/**
 * 削除時: 一時ファイルと仕掛けを片付ける。書き出したアーカイブ（archives/）は残す。
 * 消したい場合は wp-content/unbox-○○/ を手で削除する。
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
require_once __DIR__ . '/includes/class-unbox-wpress.php';
require_once __DIR__ . '/includes/class-unbox-storage.php';
require_once __DIR__ . '/includes/class-unbox-guard.php';

Unbox_Guard::remove();
Unbox_Storage::rrmdir( Unbox_Storage::dir( 'jobs' ) );
Unbox_Storage::rrmdir( Unbox_Storage::dir( 'uploads' ) );
