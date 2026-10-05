<?php
/**
 * 取り込み（.unbox / .wpress）。
 *
 * 順番: 中身を確かめる → 確認（移行先 URL を決める）→ ファイル展開 → DB を一時テーブルへ入れる → 入れ替え
 *
 * DB は今のテーブルへ直接上書きせず、一時的な接頭辞（ub○○○○_）のテーブルへ入れてから、
 * 最後に RENAME TABLE で一度に入れ替える。途中で止まっても今のサイトの DB は壊れない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; multi-gigabyte archives are read and written with native file handles.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON and rendered as text by the admin script, never echoed as HTML.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Statements replayed from the SQL dump are complete statements, not built from user input; classify() only lets through statements on Unbox's own temporary tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads the raw stored value (bypassing filters) or temporary tables during migration; caching would return stale data.

class Unbox_Import {
	const SPECIAL = array( 'package.json', 'database.sql', 'multisite.json' );

	public static function start( array $archive, $uploaded ) {
		if ( is_multisite() ) {
			throw new Unbox_Exception( __( 'Multisite is not supported yet', 'unbox-by-oobe' ) );
		}
		if ( preg_match( '/\.zip$/i', $archive['path'] ) ) {
			throw new Unbox_Exception( __( 'Import LocalWP zips with "Import site" in LocalWP. Only .unbox and .wpress files can be imported here', 'unbox-by-oobe' ) );
		}
		$job = Unbox_Job::create(
			'import',
			array(
				'archive'  => $archive['path'],
				'name'     => $archive['name'],
				'uploaded' => (bool) $uploaded,
				'stage'    => 'scan',
				'scan'     => array( 'offset' => 0, 'count' => 0, 'special' => array(), 'roots' => array() ),
			)
		);
		$job->log( sprintf( /* translators: %s: file name */ __( 'Preparing to import: %s', 'unbox-by-oobe' ), $archive['name'] ) );
		$job->save();
		return $job;
	}

	public static function step( Unbox_Job $job, $deadline ) {
		$d = &$job->data;
		switch ( $d['stage'] ) {
			case 'scan':
				self::scan( $job, $deadline );
				break;
			case 'files':
				if ( self::files( $job, $deadline ) ) {
					$job->log( sprintf( /* translators: %s: number of files */ __( 'Extracted %s files', 'unbox-by-oobe' ), number_format_i18n( $d['ext']['count'] ) ) );
					$d['stage'] = 'db_extract';
				}
				break;
			case 'db_extract':
				if ( self::db_extract( $job, $deadline ) ) {
					$d['stage'] = 'db';
				}
				break;
			case 'db':
				if ( self::db( $job, $deadline ) ) {
					$job->log( sprintf( /* translators: %s: number of SQL statements */ __( 'Imported the database into temporary tables (%s statements)', 'unbox-by-oobe' ), number_format_i18n( $d['sql']['count'] ) ) );
					$d['stage'] = 'swap';
				}
				break;
			case 'swap':
				// 入れ替えるとログイン状態が切れるので、後片付けまでこのリクエストで終わらせる
				return self::swap( $job );
		}
		return self::progress( $job );
	}

	public static function progress( Unbox_Job $job ) {
		$d    = $job->data;
		$size = max( 1, Unbox_Wpress_Reader::filesize( $d['archive'] ) );
		switch ( $d['stage'] ) {
			case 'scan':
				return array( 'percent' => 100 * $d['scan']['offset'] / $size, 'message' => sprintf( /* translators: %s: number of files */ __( 'Checking the contents (%s files)', 'unbox-by-oobe' ), number_format_i18n( $d['scan']['count'] ) ) );
			case 'confirm':
				return array( 'percent' => 100, 'message' => __( 'Waiting for confirmation', 'unbox-by-oobe' ), 'confirm' => self::summary( $job ) );
			case 'files':
				return array( 'percent' => 60 * $d['ext']['offset'] / $size, 'message' => sprintf( /* translators: %1$s: files done, %2$s: total files */ __( 'Extracting files (%1$s / %2$s)', 'unbox-by-oobe' ), number_format_i18n( $d['ext']['count'] ), number_format_i18n( $d['scan']['count'] ) ) );
			case 'db_extract':
				return array( 'percent' => 62, 'message' => __( 'Extracting the database', 'unbox-by-oobe' ) );
			case 'db':
				$total = max( 1, (int) $d['scan']['special']['database.sql']['size'] );
				return array( 'percent' => 65 + 33 * min( 1, $d['sql']['offset'] / $total ), 'message' => sprintf( /* translators: %1$s: size done, %2$s: total size */ __( 'Importing the database (%1$s / %2$s)', 'unbox-by-oobe' ), size_format( $d['sql']['offset'], 1 ), size_format( $total, 1 ) ) );
			case 'swap':
				return array( 'percent' => 99, 'message' => __( 'Swapping the database', 'unbox-by-oobe' ) );
		}
		return array( 'percent' => 0, 'message' => '' );
	}

	// ---------------------------------------------------------------- 中身の確認

	private static function scan( Unbox_Job $job, $deadline ) {
		$d  = &$job->data;
		$s  = &$d['scan'];
		$rd = new Unbox_Wpress_Reader( $d['archive'] );
		if ( $s['offset'] === 0 && ! $rd->is_valid() ) {
			throw new Unbox_Exception( __( 'The end of the file was not found. The upload or download may have been cut off, or this is not a .unbox / .wpress file', 'unbox-by-oobe' ) );
		}
		while ( true ) {
			$e = $rd->header_at( $s['offset'] );
			if ( $e === null ) {
				break;
			}
			if ( in_array( $e['name'], self::SPECIAL, true ) ) {
				$s['special'][ $e['name'] ] = $e;
			}
			if ( strpos( $e['name'], Unbox_Wpress::ROOT_PREFIX ) === 0 ) {
				$top = explode( '/', substr( $e['name'], strlen( Unbox_Wpress::ROOT_PREFIX ) ) )[0];
				$s['roots'][ $top ] = true;
			}
			$s['count']++;
			$s['offset'] = $e['next'];
			if ( microtime( true ) > $deadline ) {
				return;
			}
		}
		if ( isset( $s['special']['multisite.json'] ) ) {
			throw new Unbox_Exception( __( 'Multisite archives are not supported yet', 'unbox-by-oobe' ) );
		}
		if ( ! isset( $s['special']['package.json'] ) ) {
			throw new Unbox_Exception( __( 'package.json is missing. This does not look like a file made by Unbox or All-in-One WP Migration', 'unbox-by-oobe' ) );
		}
		$pkg = json_decode( $rd->read_string( $s['special']['package.json'] ), true );
		if ( ! is_array( $pkg ) || empty( $pkg['HomeURL'] ) ) {
			throw new Unbox_Exception( __( 'Cannot read package.json', 'unbox-by-oobe' ) );
		}
		if ( ! empty( $pkg['Encrypted'] ) ) {
			throw new Unbox_Exception( __( 'Encrypted archives are not supported yet', 'unbox-by-oobe' ) );
		}
		if ( ! empty( $pkg['Compression']['Enabled'] ) ) {
			throw new Unbox_Exception( __( 'Compressed archives are not supported yet', 'unbox-by-oobe' ) );
		}
		$d['package'] = $pkg;
		$d['stage']   = 'confirm';
		$job->log( sprintf( /* translators: %1$s: number of files, %2$s: URL */ __( 'Checked the contents (%1$s files, source site %2$s)', 'unbox-by-oobe' ), number_format_i18n( $s['count'] ), $pkg['HomeURL'] ) );
	}

	public static function summary( Unbox_Job $job ) {
		$d   = $job->data;
		$pkg = $d['package'];
		return array(
			'name'        => $d['name'],
			'size'        => Unbox_Wpress_Reader::filesize( $d['archive'] ),
			'count'       => $d['scan']['count'],
			'has_db'      => isset( $d['scan']['special']['database.sql'] ),
			'home'        => $pkg['HomeURL'],
			'site'        => isset( $pkg['SiteURL'] ) ? $pkg['SiteURL'] : $pkg['HomeURL'],
			'wp'          => isset( $pkg['WordPress']['Version'] ) ? $pkg['WordPress']['Version'] : '',
			'php'         => isset( $pkg['PHP']['Version'] ) ? $pkg['PHP']['Version'] : '',
			'plugins'     => isset( $pkg['Plugins'] ) ? count( (array) $pkg['Plugins'] ) : null,
			'theme'       => isset( $pkg['Stylesheet'] ) ? $pkg['Stylesheet'] : '',
			'roots'       => self::root_names( $d, true ),
			'roots_refused' => self::root_names( $d, false ),
			'generator'   => isset( $pkg['Generator'] ) ? $pkg['Generator'] : 'All-in-One WP Migration ' . ( isset( $pkg['Plugin']['Version'] ) ? $pkg['Plugin']['Version'] : '' ),
			'target'      => home_url(),
			'current_php' => PHP_VERSION,
			'current_wp'  => get_bloginfo( 'version' ),
		);
	}

	/** 確認画面で「取り込む」を押したとき。 */
	public static function confirm( Unbox_Job $job, $target ) {
		$d = &$job->data;
		if ( $d['stage'] !== 'confirm' ) {
			throw new Unbox_Exception( __( 'This job is not waiting for confirmation', 'unbox-by-oobe' ) );
		}
		$target = untrailingslashit( esc_url_raw( trim( (string) $target ) ) );
		if ( ! preg_match( '#^https?://[^/\s]+#i', $target ) ) {
			throw new Unbox_Exception( __( 'The destination URL is not valid', 'unbox-by-oobe' ) );
		}
		global $wpdb;
		$pkg            = $d['package'];
		$d['target']    = $target;
		$d['tmp']       = 'ub' . strtolower( wp_generate_password( 4, false ) ) . '_';
		$d['pairs']     = self::pairs( $pkg, $target );
		$d['prefix']    = $wpdb->base_prefix;
		$d['ext']       = array( 'offset' => 0, 'cur' => null, 'written' => 0, 'count' => 0, 'skipped' => 0 );
		$d['sql']       = array( 'offset' => 0, 'count' => 0, 'errors' => array(), 'error_count' => 0, 'refused' => 0, 'started' => false );
		$d['stage']     = 'files';
		$job->log( sprintf( /* translators: %s: URL */ __( 'Started importing (destination %s)', 'unbox-by-oobe' ), $target ) );
	}

	/** 取り込みをやめたとき、書きかけのファイルを消す。 */
	public static function discard_partial( array $d ) {
		if ( empty( $d['ext']['cur']['name'] ) ) {
			return;
		}
		$dest = self::destination( $d['ext']['cur']['name'], self::skip_prefixes() );
		if ( $dest !== null && is_file( $dest . Unbox_Wpress_Reader::PART_SUFFIX ) ) {
			wp_delete_file( $dest . Unbox_Wpress_Reader::PART_SUFFIX );
		}
	}

	/** 置き換える組（移行元 → 移行先）。長いものから順に。 */
	private static function pairs( array $pkg, $target ) {
		$pairs = Unbox_Replacer::package_pairs( $pkg, $target );
		if ( ! empty( $pkg['WordPress']['Content'] ) && $pkg['WordPress']['Content'] !== Unbox_Paths::content_dir() ) {
			$old_dir                                         = untrailingslashit( $pkg['WordPress']['Content'] );
			$pairs[ $old_dir ]                               = Unbox_Paths::content_dir();
			$pairs[ str_replace( '/', '\\/', $old_dir ) ]    = str_replace( '/', '\\/', Unbox_Paths::content_dir() );
		}
		uksort(
			$pairs,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return apply_filters( 'unbox_import_replace_pairs', $pairs, $pkg, $target );
	}

	// ---------------------------------------------------------------- ファイル

	/** 展開しないもの（wp-content からの相対パスの先頭）。 */
	private static function skip_prefixes() {
		return apply_filters(
			'unbox_import_skip',
			array(
				'plugins/' . dirname( UNBOX_BASENAME ) . '/',
				Unbox_Storage::dirname() . '/',
				'plugins/all-in-one-wp-migration/storage/',
				'ai1wm-backups/',
			)
		);
	}

	private static function files( Unbox_Job $job, $deadline ) {
		$d    = &$job->data;
		$x    = &$d['ext'];
		$rd   = new Unbox_Wpress_Reader( $d['archive'] );
		$skip = self::skip_prefixes();
		while ( true ) {
			if ( $x['cur'] === null ) {
				$e = $rd->header_at( $x['offset'] );
				if ( $e === null ) {
					return true;
				}
				$x['cur']     = $e;
				$x['written'] = 0;
			}
			$e    = $x['cur'];
			$dest = self::destination( $e['name'], $skip );
			if ( $dest === null && strpos( $e['name'], Unbox_Wpress::ROOT_PREFIX ) === 0 && empty( $x['root_refused'] ) ) {
				$x['root_refused'] = true;
				$job->log( sprintf( /* translators: %s: file name */ __( 'Skipped files that would overwrite WordPress core or wp-config.php (e.g. %s)', 'unbox-by-oobe' ), $e['name'] ) );
			}
			if ( $dest !== null ) {
				if ( ! $rd->extract_to( $e, $dest, $x['written'], $deadline ) ) {
					return false;
				}
				$x['count']++;
			} else {
				$x['skipped']++;
			}
			$x['offset'] = $e['next'];
			$x['cur']    = null;
			if ( microtime( true ) > $deadline ) {
				return false;
			}
		}
	}

	/** アーカイブに入っている WordPress の外のもの（$movable=false なら、置かずに飛ばすもの）。 */
	private static function root_names( array $d, $movable ) {
		$content = Unbox_Paths::content_name();
		$out     = array();
		foreach ( array_keys( isset( $d['scan']['roots'] ) ? $d['scan']['roots'] : array() ) as $name ) {
			if ( Unbox_Wpress::is_movable_root( $name, $content ) === $movable ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * アーカイブ内の名前から展開先を決める。展開しないものは null。
	 * - ふつうのファイル → wp-content の下
	 * - __root__/○○ → WordPress のフォルダー直下（本体・wp-config.php・wp-content にあたるものは拒否）
	 */
	private static function destination( $name, array $skip ) {
		if ( in_array( $name, self::SPECIAL, true ) ) {
			return null;
		}
		if ( strpos( $name, Unbox_Wpress::ROOT_PREFIX ) === 0 ) {
			$rel     = substr( $name, strlen( Unbox_Wpress::ROOT_PREFIX ) );
			$root    = Unbox_Paths::site_root();
			$content = Unbox_Paths::content_name();
			if ( $rel === '' || ! Unbox_Wpress::is_movable_root( explode( '/', $rel )[0], $content ) ) {
				return null;
			}
			return $root . '/' . $rel;
		}
		foreach ( $skip as $p ) {
			if ( strpos( $name, $p ) === 0 ) {
				return null;
			}
		}
		return Unbox_Paths::content_dir() . '/' . $name;
	}

	private static function db_extract( Unbox_Job $job, $deadline ) {
		$d = &$job->data;
		if ( ! isset( $d['scan']['special']['database.sql'] ) ) {
			throw new Unbox_Exception( __( 'The archive has no database.sql', 'unbox-by-oobe' ) );
		}
		if ( ! isset( $d['dbx'] ) ) {
			$d['dbx'] = 0;
		}
		$rd = new Unbox_Wpress_Reader( $d['archive'] );
		return $rd->extract_to( $d['scan']['special']['database.sql'], $job->dir( 'database.sql' ), $d['dbx'], $deadline );
	}

	// ---------------------------------------------------------------- DB

	/** @return wpdb */
	private static function wpdb() {
		global $wpdb;
		return $wpdb;
	}

	private static function collations() {
		$list = array();
		foreach ( (array) self::wpdb()->get_col( 'SHOW COLLATION' ) as $name ) {
			$list[ strtolower( $name ) ] = true;
		}
		return $list;
	}

	private static function tmp_tables( $tmp ) {
		$wpdb = self::wpdb();
		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tmp ) . '%' ) );
	}

	private static function drop_table( $table ) {
		$wpdb = self::wpdb();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	/**
	 * ダンプの1文を流す。成功したら空文字、失敗したらエラーの文。
	 *
	 * 大きな文をそのまま渡すため、wpdb の文字コードの点検（文全体を走査して書き換えうる）は切る。
	 * エラーは画面に出さず、呼び出し側で記録する。
	 */
	private static function run( $stmt ) {
		$wpdb                      = self::wpdb();
		$wpdb->check_current_query = false;
		$ok                        = $wpdb->query( $stmt ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- A complete statement from the SQL dump; classify() only lets through statements on Unbox's own temporary tables.
		$error                     = $ok === false ? ( $wpdb->last_error !== '' ? $wpdb->last_error : 'error' ) : '';
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$wpdb->queries = array(); // 1MB 近い文をためない
		}
		$wpdb->flush();
		return $error;
	}

	/** 取り込みの間、DB のエラーを画面に出さない（応答の JSON が壊れるため）。 */
	private static function quiet() {
		self::wpdb()->suppress_errors( true );
		self::wpdb()->hide_errors();
	}

	private static function db( Unbox_Job $job, $deadline ) {
		$d   = &$job->data;
		$s   = &$d['sql'];
		$wpdb = self::wpdb();
		self::quiet();
		$wpdb->set_charset( $wpdb->dbh, 'utf8mb4' );
		$wpdb->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		if ( ! $s['started'] ) {
			foreach ( self::tmp_tables( $d['tmp'] ) as $t ) {
				self::drop_table( $t );
			}
			$s['started'] = true;
		}
		$collations = self::collations();
		$replacer   = new Unbox_Replacer( $d['pairs'] );
		$reader     = new Unbox_Sql_Reader( $job->dir( 'database.sql' ), $s['offset'] );
		while ( true ) {
			$stmt = $reader->next();
			if ( $stmt === null ) {
				return true;
			}
			$stmt = Unbox_Sql::replace_prefix( $stmt, $d['tmp'], $d['prefix'] );
			$kind = self::classify( $stmt, $d['tmp'] );
			if ( $kind === 'skip' ) {
				// 一時テーブル以外を触る文は流さない（今のサイトのテーブルを直接壊さないため）
				$s['refused']++;
				if ( $s['refused'] <= 5 ) {
					$job->log( sprintf( /* translators: %s: SQL */ __( 'Statement not executed: %s', 'unbox-by-oobe' ), mb_substr( $stmt, 0, 120 ) ) );
				}
			} else {
				if ( $kind === 'create' ) {
					$stmt = Unbox_Sql::fix_collations( $stmt, $collations );
				}
				$stmt = Unbox_Sql::replace_values( $stmt, $replacer );
				$error = self::run( $stmt );
				if ( $error !== '' ) {
					$s['error_count']++;
					if ( count( $s['errors'] ) < 10 ) {
						$s['errors'][] = $error . ' / ' . mb_substr( $stmt, 0, 160 );
					}
				}
				$s['count']++;
			}
			$s['offset'] = $reader->tell();
			if ( microtime( true ) > $deadline ) {
				return false;
			}
		}
	}

	/** 文の種類。一時テーブル以外を触るものは skip。 */
	private static function classify( $stmt, $tmp ) {
		if ( preg_match( '/^(SET\s|START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK)/i', $stmt ) ) {
			return 'other';
		}
		if ( preg_match( '#^/\*!\d+\s+SET\s#i', $stmt ) ) {
			return 'other';
		}
		if ( preg_match( '/^(DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE)\s+`?([^`\s(]+)`?/i', $stmt, $m ) ) {
			if ( strpos( $m[2], $tmp ) !== 0 ) {
				return 'skip';
			}
			return stripos( $m[1], 'CREATE' ) === 0 ? 'create' : 'other';
		}
		return 'skip';
	}

	// ---------------------------------------------------------------- 入れ替え

	private static function swap( Unbox_Job $job ) {
		$d      = &$job->data;
		$wpdb   = self::wpdb();
		$tmp    = $d['tmp'];
		$prefix = $d['prefix'];
		self::quiet();
		$tables = self::tmp_tables( $tmp );
		foreach ( array( 'options', 'users', 'usermeta', 'posts' ) as $need ) {
			if ( ! in_array( $tmp . $need, $tables, true ) ) {
				$detail = $d['sql']['errors'] ? ' ' . sprintf( /* translators: %s: error message */ __( 'First error: %s', 'unbox-by-oobe' ), $d['sql']['errors'][0] ) : '';
				throw new Unbox_Exception( sprintf( /* translators: %s: table name */ __( 'The database import is incomplete, so the swap was cancelled (the %s table is missing). The current database is unchanged.', 'unbox-by-oobe' ), $need ) . $detail );
			}
		}
		$old    = 'ubold' . strtolower( wp_generate_password( 3, false ) ) . '_';
		$pairs  = array();
		$drop   = array();
		$exists = array_flip( (array) $wpdb->get_col( 'SHOW TABLES' ) );
		foreach ( $tables as $t ) {
			$suffix = substr( $t, strlen( $tmp ) );
			$target = $prefix . $suffix;
			if ( isset( $exists[ $target ] ) ) {
				$retired = substr( $old . $suffix, 0, 64 );
				$pairs[] = $target;
				$pairs[] = $retired;
				$drop[]  = $retired;
			}
			$pairs[] = $t;
			$pairs[] = $target;
		}
		// 全部を1文で入れ替える（途中で失敗しても、今のテーブルはそのまま）
		$list = implode( ', ', array_fill( 0, count( $pairs ) / 2, '%i TO %i' ) );
		if ( $wpdb->query( $wpdb->prepare( "RENAME TABLE $list", $pairs ) ) === false ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $list holds only %i placeholders.
			throw new Unbox_Exception( sprintf( /* translators: %s: error message */ __( 'Failed to swap the database: %s (the current database is unchanged)', 'unbox-by-oobe' ), $wpdb->last_error ) );
		}
		foreach ( $drop as $t ) {
			self::drop_table( $t );
		}
		$job->log( __( 'Swapped the database', 'unbox-by-oobe' ) );
		self::finalize( $job );

		$result = array(
			'login'       => $d['target'] . '/wp-login.php',
			'target'      => $d['target'],
			'errors'      => $d['sql']['errors'],
			'error_count' => $d['sql']['error_count'],
			'refused'     => $d['sql']['refused'],
			'log'         => $d['log'],
		);
		@unlink( $job->dir( 'database.sql' ) );
		if ( $d['uploaded'] ) {
			@unlink( $d['archive'] );
		}
		$job->destroy();
		return array( 'percent' => 100, 'message' => __( 'Import finished', 'unbox-by-oobe' ), 'done' => true, 'result' => $result );
	}

	/** 入れ替え後の仕上げ。WordPress のキャッシュは古いので SQL で直接書く。 */
	private static function finalize( Unbox_Job $job ) {
		$wpdb    = self::wpdb();
		$d       = $job->data;
		$options = $d['prefix'] . 'options';
		$pkg     = $d['package'];
		$site    = $d['target'];
		$old_home = untrailingslashit( $pkg['HomeURL'] );
		$old_site = untrailingslashit( isset( $pkg['SiteURL'] ) ? $pkg['SiteURL'] : $pkg['HomeURL'] );
		if ( $old_site !== $old_home && strpos( $old_site, $old_home ) === 0 ) {
			$site .= substr( $old_site, strlen( $old_home ) );
		}
		$get = function ( $name ) use ( $wpdb, $options ) {
			return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $options, $name ) );
		};
		$set = function ( $name, $value ) use ( $wpdb, $options ) {
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)', $options, $name, $value, 'yes' ) );
		};
		$set( 'home', $d['target'] );
		$set( 'siteurl', $site );

		// All-in-One WP Migration の .wpress は有効なプラグインとテーマを DB ではなく package.json に入れている
		$plugins = $get( 'active_plugins' );
		$plugins = $plugins === null ? ( isset( $pkg['Plugins'] ) ? (array) $pkg['Plugins'] : array() ) : @unserialize( $plugins, array( 'allowed_classes' => false ) );
		if ( ! is_array( $plugins ) ) {
			$plugins = array();
		}
		$plugins = array_values( array_unique( array_merge( $plugins, array( UNBOX_BASENAME ) ) ) );
		$set( 'active_plugins', serialize( $plugins ) );
		foreach ( array( 'template' => 'Template', 'stylesheet' => 'Stylesheet' ) as $opt => $key ) {
			if ( $get( $opt ) === null && ! empty( $pkg[ $key ] ) ) {
				$set( $opt, $pkg[ $key ] );
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name IN (%s, %s)', $options, 'rewrite_rules', 'unbox_secret' ) );
		wp_cache_flush();
	}
}
