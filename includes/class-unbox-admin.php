<?php
/**
 * 管理画面（ツール → Unbox）と、画面から呼ぶ処理の入口。
 *
 * 処理はすべてブラウザが1段ずつ呼ぶ方式。サーバーが自分自身へリクエストを投げないので、
 * Basic 認証・WAF・リバースプロキシの内側でも止まりにくい。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; multi-gigabyte archives are read and written with native file handles.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON and rendered as text by the admin script, never echoed as HTML.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads the raw stored value (bypassing filters) or temporary tables during migration; caching would return stale data.

class Unbox_Admin {
	const CAP   = 'manage_options';
	const NONCE = 'unbox';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'plugin_action_links_' . UNBOX_BASENAME, array( __CLASS__, 'action_links' ) );
		foreach ( array( 'export_start', 'import_prepare', 'import_confirm', 'step', 'cancel', 'upload', 'delete', 'list', 'download' ) as $a ) {
			add_action( 'wp_ajax_unbox_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function menu() {
		$hook = add_management_page( 'Unbox', 'Unbox', self::CAP, 'unbox', array( __CLASS__, 'render' ) );
		add_action( 'admin_print_scripts-' . $hook, array( __CLASS__, 'assets' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=unbox' ) ) . '">' . esc_html( __( 'Open', 'unbox-by-oobe' ) ) . '</a>' );
		return $links;
	}

	public static function assets() {
		wp_enqueue_style( 'unbox', UNBOX_URL . 'assets/admin.css', array(), UNBOX_VERSION );
		wp_enqueue_script( 'unbox', UNBOX_URL . 'assets/admin.js', array(), UNBOX_VERSION, true );
		wp_localize_script(
			'unbox',
			'UNBOX',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'chunk'     => self::chunk_size(),
				'home'      => home_url(),
				'localName' => Unbox_Export::suggest_local_name(),
				'i18n'      => self::js_strings(),
			)
		);
	}


	/** 画面の JavaScript で使う文言（英語 => 訳）。 */
	private static function js_strings() {
		return array(
			/* translators: placeholders are filled in by the admin screen script */
			'Unexpected server response (HTTP %s). ' => __( 'Unexpected server response (HTTP %s). ', 'unbox-by-oobe' ),
			'An error occurred' => __( 'An error occurred', 'unbox-by-oobe' ),
			'Cannot reach the server' => __( 'Cannot reach the server', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'Retrying (%1$s/%2$s)' => __( 'Retrying (%1$s/%2$s)', 'unbox-by-oobe' ),
			'Close' => __( 'Close', 'unbox-by-oobe' ),
			'Cancel' => __( 'Cancel', 'unbox-by-oobe' ),
			'Cancel? (During an import, files already extracted are not reverted. The database has not been swapped yet.)' => __( 'Cancel? (During an import, files already extracted are not reverted. The database has not been swapped yet.)', 'unbox-by-oobe' ),
			'Export' => __( 'Export', 'unbox-by-oobe' ),
			'Preparing' => __( 'Preparing', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'Export finished: %1$s (%2$s)' => __( 'Export finished: %1$s (%2$s)', 'unbox-by-oobe' ),
			'Download the zip' => __( 'Download the zip', 'unbox-by-oobe' ),
			'In LocalWP, click "+" at the bottom left, then "Import an existing site", and choose the zip (or drag it in)' => __( 'In LocalWP, click "+" at the bottom left, then "Import an existing site", and choose the zip (or drag it in)', 'unbox-by-oobe' ),
			'Any site name is fine (URLs are replaced to match the name LocalWP gives)' => __( 'Any site name is fine (URLs are replaced to match the name LocalWP gives)', 'unbox-by-oobe' ),
			'Download' => __( 'Download', 'unbox-by-oobe' ),
			'Choose a .unbox or .wpress file (LocalWP zips are imported in LocalWP)' => __( 'Choose a .unbox or .wpress file (LocalWP zips are imported in LocalWP)', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'Uploading (%1$s / %2$s)' => __( 'Uploading (%1$s / %2$s)', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'%s/s' => __( '%s/s', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'Resending in %s chunks to fit the server limit' => __( 'Resending in %s chunks to fit the server limit', 'unbox-by-oobe' ),
			'Checking the contents' => __( 'Checking the contents', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'The source site runs PHP %1$s and this server runs PHP %2$s (different major versions). Some plugins or themes may not work.' => __( 'The source site runs PHP %1$s and this server runs PHP %2$s (different major versions). Some plugins or themes may not work.', 'unbox-by-oobe' ),
			'This archive contains no database.' => __( 'This archive contains no database.', 'unbox-by-oobe' ),
			'File' => __( 'File', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'(%1$s, %2$s files)' => __( '(%1$s, %2$s files)', 'unbox-by-oobe' ),
			'Source site' => __( 'Source site', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'(this server: %1$s / %2$s)' => __( '(this server: %1$s / %2$s)', 'unbox-by-oobe' ),
			'Theme' => __( 'Theme', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'(%s active plugins)' => __( '(%s active plugins)', 'unbox-by-oobe' ),
			'Outside WordPress' => __( 'Outside WordPress', 'unbox-by-oobe' ),
			'(placed directly in this site\'s WordPress folder; items with the same name are overwritten)' => __( '(placed directly in this site\'s WordPress folder; items with the same name are overwritten)', 'unbox-by-oobe' ),
			'Not placed' => __( 'Not placed', 'unbox-by-oobe' ),
			'(skipped so that WordPress core and settings are not overwritten)' => __( '(skipped so that WordPress core and settings are not overwritten)', 'unbox-by-oobe' ),
			'Created by' => __( 'Created by', 'unbox-by-oobe' ),
			'Destination URL (this site\'s URL)' => __( 'Destination URL (this site\'s URL)', 'unbox-by-oobe' ),
			'This site\'s files and database will be overwritten with the archive. To be able to go back, export this site first. You will be logged out when it finishes; log in again with a user from the source site.' => __( 'This site\'s files and database will be overwritten with the archive. To be able to go back, export this site first. You will be logged out when it finishes; log in again with a user from the source site.', 'unbox-by-oobe' ),
			'I understand this site will be overwritten' => __( 'I understand this site will be overwritten', 'unbox-by-oobe' ),
			'Import' => __( 'Import', 'unbox-by-oobe' ),
			'button:Import' => _x( 'Import', 'button', 'unbox-by-oobe' ),
			'Don\'t import' => __( 'Don\'t import', 'unbox-by-oobe' ),
			'Check "I understand this site will be overwritten" first' => __( 'Check "I understand this site will be overwritten" first', 'unbox-by-oobe' ),
			'Starting the import' => __( 'Starting the import', 'unbox-by-oobe' ),
			'Import finished. Please log in again.' => __( 'Import finished. Please log in again.', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'%s errors occurred while importing the database (most are harmless, but check the site).' => __( '%s errors occurred while importing the database (most are harmless, but check the site).', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'%s SQL statements were not executed for safety (see the log).' => __( '%s SQL statements were not executed for safety (see the log).', 'unbox-by-oobe' ),
			'Saving the permalink settings once makes sure page URLs work.' => __( 'Saving the permalink settings once makes sure page URLs work.', 'unbox-by-oobe' ),
			'Go to login' => __( 'Go to login', 'unbox-by-oobe' ),
			'Nothing yet' => __( 'Nothing yet', 'unbox-by-oobe' ),
			'Delete' => __( 'Delete', 'unbox-by-oobe' ),
			/* translators: placeholders are filled in by the admin screen script */
			'Delete %s? This cannot be undone.' => __( 'Delete %s? This cannot be undone.', 'unbox-by-oobe' ),
		);
	}

	/** アップロード1回分の大きさ。サーバーの上限（upload_max_filesize / post_max_size）の 8 割、最大 16MB。 */
	public static function chunk_size() {
		$max = (int) wp_max_upload_size();
		if ( $max <= 0 ) {
			$max = 2 * MB_IN_BYTES;
		}
		return (int) apply_filters( 'unbox_upload_chunk_size', max( 256 * KB_IN_BYTES, min( 16 * MB_IN_BYTES, floor( $max * 0.8 ) ) ) );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		self::cleanup_old_jobs();
		include UNBOX_DIR . 'views/page.php';
	}

	/** 1日以上前のジョブの残骸を片付ける。 */
	private static function cleanup_old_jobs() {
		$dir = Unbox_Storage::dir( 'jobs' );
		foreach ( (array) @scandir( $dir ) as $id ) {
			if ( preg_match( '/^[a-f0-9]{16}$/', $id ) && filemtime( "$dir/$id" ) < time() - DAY_IN_SECONDS ) {
				Unbox_Storage::rrmdir( "$dir/$id" );
			}
		}
		$up = Unbox_Storage::dir( 'uploads' );
		foreach ( (array) @scandir( $up ) as $f ) {
			if ( substr( $f, -5 ) === '.part' && filemtime( "$up/$f" ) < time() - DAY_IN_SECONDS ) {
				@unlink( "$up/$f" );
			}
		}
		Unbox_Guard::remove_if_idle();
	}

	// ---------------------------------------------------------------- 共通

	private static function begin() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission', 'unbox-by-oobe' ) ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired. Please reload it', 'unbox-by-oobe' ) ), 403 );
		}
		@set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running export, import and download requests.
		@ignore_user_abort( true );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		try {
			Unbox_Storage::ensure();
		} catch ( Unbox_Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** 1リクエストで使ってよい時間（秒）。プロキシの 60 秒切りに当たらないよう短めに。 */
	private static function deadline() {
		$limit = (int) ini_get( 'max_execution_time' );
		$sec   = $limit > 0 ? min( 20, max( 3, $limit * 0.5 ) ) : 20;
		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		return $start + apply_filters( 'unbox_step_seconds', $sec );
	}

	private static function fail( Exception $e, Unbox_Job $job = null ) {
		if ( $job ) {
			$job->log( sprintf( /* translators: %s: error message */ __( 'Error: %s', 'unbox-by-oobe' ), $e->getMessage() ) );
			$job->data['error'] = $e->getMessage();
			try {
				$job->save();
			} catch ( Exception $ignored ) {
				unset( $ignored );
			}
			$job->unlock();
		}
		wp_send_json_error( array( 'message' => $e->getMessage(), 'log' => $job ? $job->data['log'] : array() ) );
	}

	/**
	 * 送られてきた値。begin() でノンスと権限を確かめたあとにだけ呼ぶ。
	 * 複数行の入力（除外の指定）があるので sanitize_textarea_field で整える。
	 */
	private static function post( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in begin() before any call.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in begin(); sanitized just below.
		$value = wp_unslash( $_POST[ $key ] );
		return is_array( $value ) ? map_deep( $value, 'sanitize_textarea_field' ) : sanitize_textarea_field( (string) $value );
	}

	// ---------------------------------------------------------------- 書き出し・取り込み

	public static function ajax_export_start() {
		self::begin();
		try {
			$opt = Unbox_Export::normalize_options( (array) self::post( 'options', array() ) );
			$job = Unbox_Export::start( $opt );
			wp_send_json_success( array( 'job' => $job->id ) );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	public static function ajax_import_prepare() {
		self::begin();
		try {
			$archive = Unbox_Storage::resolve_archive( self::post( 'archive' ) );
			$job     = Unbox_Import::start( $archive, self::post( 'uploaded' ) === '1' );
			wp_send_json_success( array( 'job' => $job->id ) );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	public static function ajax_import_confirm() {
		self::begin();
		$job = null;
		try {
			$job = Unbox_Job::load( self::post( 'job' ) );
			if ( ! $job->lock() ) {
				throw new Unbox_Exception( __( 'Already in progress', 'unbox-by-oobe' ) );
			}
			Unbox_Import::confirm( $job, self::post( 'target' ) );
			$job->save();
			$job->unlock();
			wp_send_json_success( array( 'job' => $job->id ) );
		} catch ( Exception $e ) {
			self::fail( $e, $job );
		}
	}

	public static function ajax_step() {
		self::begin();
		$job = null;
		try {
			$job = Unbox_Job::load( self::post( 'job' ) );
			if ( ! empty( $job->data['error'] ) ) {
				throw new Unbox_Exception( $job->data['error'] );
			}
			if ( ! $job->lock() ) {
				wp_send_json_success( array( 'busy' => true ) );
			}
			$deadline = self::deadline();
			if ( $job->data['type'] === 'export' ) {
				$res = $job->data['stage'] === 'done' ? Unbox_Export::progress( $job ) : Unbox_Export::step( $job, $deadline );
			} else {
				$res = $job->data['stage'] === 'confirm' ? Unbox_Import::progress( $job ) : Unbox_Import::step( $job, $deadline );
			}
			if ( empty( $res['done'] ) || $job->data['type'] === 'export' ) {
				$job->save();
				$job->unlock();
			}
			if ( ! empty( $res['done'] ) && $job->data['type'] === 'export' ) {
				$res['log'] = $job->data['log'];
				$job->destroy();
			}
			$res['percent'] = round( $res['percent'], 1 );
			wp_send_json_success( $res );
		} catch ( Exception $e ) {
			self::fail( $e, $job );
		}
	}

	public static function ajax_cancel() {
		self::begin();
		try {
			$job = Unbox_Job::load( self::post( 'job' ) );
			$d   = $job->data;
			if ( $d['type'] === 'import' && ! empty( $d['tmp'] ) ) {
				global $wpdb;
				$like = str_replace( '_', '\\_', $d['tmp'] ) . '%';
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $t ) {
					if ( preg_match( '/^[A-Za-z0-9_]+$/', $t ) ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- A table name from SHOW TABLES, checked against [A-Za-z0-9_] above.
						$wpdb->query( "DROP TABLE IF EXISTS `$t`" );
					}
				}
			}
			if ( $d['type'] === 'import' && ! empty( $d['uploaded'] ) && self::post( 'keep' ) !== '1' ) {
				@unlink( $d['archive'] );
			}
			$job->destroy();
			Unbox_Guard::remove_if_idle();
			wp_send_json_success();
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	// ---------------------------------------------------------------- ファイル

	public static function ajax_list() {
		self::begin();
		wp_send_json_success( array( 'archives' => Unbox_Storage::list_archives() ) );
	}

	public static function ajax_delete() {
		self::begin();
		try {
			$a = Unbox_Storage::resolve_archive( self::post( 'archive' ) );
			if ( $a['source'] !== 'own' ) {
				throw new Unbox_Exception( __( 'This file is not in the Unbox folder, so it cannot be deleted here', 'unbox-by-oobe' ) );
			}
			if ( ! @unlink( $a['path'] ) ) {
				throw new Unbox_Exception( __( 'Could not delete the file', 'unbox-by-oobe' ) );
			}
			wp_send_json_success( array( 'archives' => Unbox_Storage::list_archives() ) );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	/**
	 * 分割アップロード。ブラウザがファイルを chunk_size ごとに送り、ここで .part に継ぎ足す。
	 * offset が合わなければ今のサイズを返し、ブラウザはそこから送り直す。
	 */
	public static function ajax_upload() {
		// post_max_size を超えると PHP は中身を捨てる（認証の値も消える）。分割を小さくして送り直すよう返す
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only checks whether PHP discarded the body (it then also discards the nonce).
		if ( empty( $_POST ) && empty( $_FILES ) && ! empty( $_SERVER['CONTENT_LENGTH'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The upload chunk exceeded the server limit', 'unbox-by-oobe' ), 'code' => 'too_large' ), 413 );
		}
		self::begin();
		try {
			$id     = (string) self::post( 'upload' );
			$name   = Unbox_Storage::safe_filename( (string) self::post( 'name' ) );
			$total  = (int) self::post( 'total' );
			$offset = (int) self::post( 'offset' );
			if ( ! preg_match( '/^[a-z0-9]{16}$/', $id ) || ! preg_match( '/\.(unbox|wpress)$/i', $name ) ) {
				throw new Unbox_Exception( __( 'Choose a .unbox or .wpress file', 'unbox-by-oobe' ) );
			}
			$part = Unbox_Storage::dir( 'uploads' ) . '/' . $id . '.part';
			clearstatcache( true, $part );
			$have = file_exists( $part ) ? Unbox_Wpress_Reader::filesize( $part ) : 0;
			if ( $offset !== $have ) {
				wp_send_json_success( array( 'offset' => $have ) );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in begin(); a temporary file path from PHP, checked with is_uploaded_file().
			$tmp = isset( $_FILES['chunk']['tmp_name'] ) ? (string) $_FILES['chunk']['tmp_name'] : '';
			if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
				$err = isset( $_FILES['chunk']['error'] ) ? (int) $_FILES['chunk']['error'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in begin().
				if ( in_array( $err, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
					wp_send_json_error( array( 'message' => __( 'The upload chunk exceeded the server limit', 'unbox-by-oobe' ), 'code' => 'too_large' ), 413 );
				}
				throw new Unbox_Exception( sprintf( /* translators: %s: PHP upload error code */ __( 'The upload was not received (error %s). The chunk may be larger than the server limit', 'unbox-by-oobe' ), $err ) );
			}
			$in  = fopen( $tmp, 'rb' );
			$out = fopen( $part, 'ab' );
			if ( ! $in || ! $out ) {
				throw new Unbox_Exception( __( 'Cannot write the upload', 'unbox-by-oobe' ) );
			}
			$copied = stream_copy_to_stream( $in, $out );
			fclose( $in );
			fclose( $out );
			$have += (int) $copied;
			if ( $have >= $total ) {
				$dest = Unbox_Storage::unique_path( Unbox_Storage::dir( 'archives' ), $name );
				if ( ! rename( $part, $dest ) ) {
					throw new Unbox_Exception( __( 'Could not move the uploaded file', 'unbox-by-oobe' ) );
				}
				wp_send_json_success( array( 'offset' => $have, 'done' => true, 'archive' => 'own:' . basename( $dest ) ) );
			}
			wp_send_json_success( array( 'offset' => $have ) );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	/** ダウンロード。ファイルは公開フォルダーに置かず、ここを通して渡す。 */
	public static function ajax_download() {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_die( esc_html( __( 'You do not have permission, or this page has expired', 'unbox-by-oobe' ) ), 403 );
		}
		try {
			$a = Unbox_Storage::resolve_archive( isset( $_GET['archive'] ) ? sanitize_text_field( wp_unslash( $_GET['archive'] ) ) : '' );
		} catch ( Unbox_Exception $e ) {
			wp_die( esc_html( $e->getMessage() ), 404 );
		}
		@set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running export, import and download requests.
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$size = Unbox_Wpress_Reader::filesize( $a['path'] );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $a['name'] . '"' );
		header( 'Content-Length: ' . $size );
		header( 'X-Accel-Buffering: no' );
		$fh = fopen( $a['path'], 'rb' );
		while ( ! feof( $fh ) && ! connection_aborted() ) {
			echo fread( $fh, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file download, not HTML.
			flush();
		}
		fclose( $fh );
		exit;
	}
}
