<?php
/**
 * 書き出し。ファイル一覧 → DB → アーカイブ の順に、1リクエストずつ進める。
 *
 * 形式:
 * - unbox   … Unbox の標準形式（.unbox）。中身は .wpress と同じ形式
 * - wpress  … All-in-One WP Migration で取り込める .wpress
 * - localwp … LocalWP の「Import」にそのまま渡せる zip
 *              files/（WordPress 本体）＋ files/wp-content/ ＋ database.sql
 *              URL は書き出しの時点で http://○○.local に置き換え済み
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; multi-gigabyte archives are read and written with native file handles.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON and rendered as text by the admin script, never echoed as HTML.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads the raw stored value (bypassing filters) or temporary tables during migration; caching would return stale data.

class Unbox_Export {
	const CORE_FILES = array( 'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php', 'license.txt', 'readme.html' );

	/** 画面から受け取った設定を整える。 */
	public static function normalize_options( array $in ) {
		$format = isset( $in['format'] ) && in_array( $in['format'], array( 'unbox', 'wpress', 'localwp' ), true ) ? $in['format'] : 'unbox';
		$opt    = array(
			'format'                   => $format,
			'local_name'               => '',
			'include_core'             => ! empty( $in['include_core'] ),
			'exclude_media'            => ! empty( $in['exclude_media'] ),
			'exclude_inactive_plugins' => ! empty( $in['exclude_inactive_plugins'] ),
			'exclude_inactive_themes'  => ! empty( $in['exclude_inactive_themes'] ),
			'exclude_cache'            => ! empty( $in['exclude_cache'] ),
			'exclude_revisions'        => ! empty( $in['exclude_revisions'] ),
			'exclude_spam'             => ! empty( $in['exclude_spam'] ),
			'exclude_transients'       => ! empty( $in['exclude_transients'] ),
			'custom_excludes'          => array(),
			'root_items'               => array(),
		);
		if ( $format === 'localwp' ) {
			$name = strtolower( isset( $in['local_name'] ) ? (string) $in['local_name'] : '' );
			$name = preg_replace( '/\.local$/', '', $name );
			$name = trim( preg_replace( '/[^a-z0-9\-]+/', '-', $name ), '-' );
			if ( $name === '' ) {
				throw new Unbox_Exception( __( 'Enter the LocalWP site name (letters, numbers and hyphens)', 'unbox-by-oobe' ) );
			}
			$opt['local_name'] = $name;
		}
		$lines = isset( $in['custom_excludes'] ) ? preg_split( '/\r\n|\r|\n/', (string) $in['custom_excludes'] ) : array();
		foreach ( $lines as $line ) {
			$line = trim( str_replace( '\\', '/', $line ), " /\t" );
			if ( $line === '' ) {
				continue;
			}
			$line = preg_replace( '#^wp-content/#', '', $line );
			try {
				$opt['custom_excludes'][] = Unbox_Wpress::safe_relpath( $line );
			} catch ( Unbox_Exception $e ) {
				throw new Unbox_Exception( sprintf( /* translators: %s: path */ __( 'Invalid exclusion: %s', 'unbox-by-oobe' ), $line ) );
			}
		}
		$roots = isset( $in['root_items'] ) ? $in['root_items'] : array();
		if ( ! is_array( $roots ) ) {
			$roots = preg_split( '/\r\n|\r|\n/', (string) $roots );
		}
		$allowed = array_column( self::root_candidates(), 'name' );
		foreach ( $roots as $name ) {
			$name = trim( (string) $name );
			if ( $name === '' ) {
				continue;
			}
			if ( ! in_array( $name, $allowed, true ) ) {
				throw new Unbox_Exception( sprintf( /* translators: %s: file or folder name */ __( 'This item cannot be selected from outside WordPress: %s', 'unbox-by-oobe' ), $name ) );
			}
			$opt['root_items'][] = $name;
		}
		if ( $opt['root_items'] && $format === 'wpress' ) {
			throw new Unbox_Exception( __( 'Folders outside WordPress cannot be included in .wpress (All-in-One WP Migration compatible). Choose .unbox or LocalWP zip', 'unbox-by-oobe' ) );
		}
		return apply_filters( 'unbox_export_options', $opt, $in );
	}

	/** wp-content の外の候補（WordPress のフォルダー直下にある、本体以外のフォルダー・ファイル）。 */
	public static function root_candidates() {
		$root    = untrailingslashit( ABSPATH );
		$content = dirname( WP_CONTENT_DIR ) === $root ? basename( WP_CONTENT_DIR ) : 'wp-content';
		$list    = array();
		foreach ( (array) @scandir( $root ) as $name ) {
			if ( ! Unbox_Wpress::is_movable_root( $name, $content ) || is_link( "$root/$name" ) ) {
				continue;
			}
			$list[] = array( 'name' => $name, 'dir' => is_dir( "$root/$name" ) );
		}
		return $list;
	}

	public static function format_label( $format ) {
		$labels = array( 'unbox' => '.unbox', 'wpress' => __( '.wpress (All-in-One WP Migration compatible)', 'unbox-by-oobe' ), 'localwp' => __( 'LocalWP zip', 'unbox-by-oobe' ) );
		return isset( $labels[ $format ] ) ? $labels[ $format ] : $format;
	}

	/** LocalWP 用の推奨サイト名（ホスト名の先頭のラベル）。 */
	public static function suggest_local_name() {
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host  = preg_replace( '/^www\./', '', $host );
		$label = explode( '.', $host )[0];
		$path  = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$name  = $label . ( $path !== '' ? '-' . str_replace( '/', '-', $path ) : '' );
		return trim( preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( $name ) ), '-' );
	}

	public static function start( array $opt ) {
		if ( is_multisite() ) {
			throw new Unbox_Exception( __( 'Multisite is not supported yet', 'unbox-by-oobe' ) );
		}
		$job = Unbox_Job::create(
			'export',
			array(
				'opt'   => $opt,
				'stage' => 'enumerate',
				'home'  => home_url(),
				'site'  => site_url(),
				'raw'   => array( 'home' => self::raw_option( 'home' ), 'siteurl' => self::raw_option( 'siteurl' ) ),
			)
		);
		$job->log( sprintf( /* translators: %s: format name */ __( 'Started exporting (format: %s)', 'unbox-by-oobe' ), self::format_label( $opt['format'] ) ) );
		$job->save();
		Unbox_Guard::install();
		return $job;
	}

	/** 1リクエスト分すすめる。 */
	public static function step( Unbox_Job $job, $deadline ) {
		$d = &$job->data;
		switch ( $d['stage'] ) {
			case 'enumerate':
				if ( self::enumerate( $job, $deadline ) ) {
					$job->log( sprintf( /* translators: %1$s: number of files, %2$s: size */ __( 'Found %1$s files (%2$s)', 'unbox-by-oobe' ), number_format_i18n( $d['enum']['count'] ), size_format( $d['enum']['bytes'], 1 ) ) );
					$d['stage'] = 'database';
				}
				break;
			case 'database':
				$replacer = null;
				if ( $d['opt']['format'] === 'localwp' ) {
					$replacer = new Unbox_Replacer( self::localwp_pairs( $d ) );
				}
				$dumper = new Unbox_Dumper( $d['opt'], $replacer );
				if ( ! isset( $d['db'] ) ) {
					$d['db'] = array();
				}
				if ( $dumper->step( $d['db'], $job->dir( 'database.sql' ), $deadline ) ) {
					$job->log( sprintf( /* translators: %1$d: tables, %2$s: rows, %3$s: size */ __( 'Exported the database (%1$d tables, %2$s rows, %3$s)', 'unbox-by-oobe' ), count( $d['db']['tables'] ), number_format_i18n( $d['db']['rows'] ), size_format( filesize( $job->dir( 'database.sql' ) ), 1 ) ) );
					$d['stage'] = 'archive';
				}
				break;
			case 'archive':
				if ( self::archive( $job, $deadline ) ) {
					self::finish( $job );
				}
				break;
		}
		return self::progress( $job );
	}

	public static function progress( Unbox_Job $job ) {
		$d = $job->data;
		switch ( $d['stage'] ) {
			case 'enumerate':
				$n = isset( $d['enum']['count'] ) ? $d['enum']['count'] : 0;
				return array( 'percent' => 2, 'message' => sprintf( /* translators: %s: number of files */ __( 'Counting files (%s)', 'unbox-by-oobe' ), number_format_i18n( $n ) ) );
			case 'database':
				$t = isset( $d['db']['tables'] ) ? count( $d['db']['tables'] ) : 0;
				$i = isset( $d['db']['i'] ) ? $d['db']['i'] : 0;
				return array( 'percent' => 5 + ( $t ? 20 * $i / $t : 0 ), 'message' => sprintf( /* translators: %1$d: tables done, %2$d: total tables */ __( 'Exporting the database (%1$d / %2$d tables)', 'unbox-by-oobe' ), $i, $t ) );
			case 'archive':
				$total = max( 1, $d['enum']['bytes'] );
				$done  = isset( $d['arc']['bytes'] ) ? $d['arc']['bytes'] : 0;
				return array( 'percent' => 25 + 75 * min( 1, $done / $total ), 'message' => sprintf( /* translators: %1$s: size done, %2$s: total size */ __( 'Building the archive (%1$s / %2$s)', 'unbox-by-oobe' ), size_format( $done, 1 ), size_format( $total, 1 ) ) );
			case 'done':
				return array( 'percent' => 100, 'message' => __( 'Export finished', 'unbox-by-oobe' ), 'done' => true, 'result' => $d['result'] );
		}
		return array( 'percent' => 0, 'message' => '' );
	}

	/** LocalWP 用: 今の URL → http://○○.local */
	private static function localwp_pairs( array $d ) {
		$pairs = Unbox_Replacer::package_pairs( self::url_package( $d ), 'http://' . $d['opt']['local_name'] . '.local' );
		return apply_filters( 'unbox_localwp_replace_pairs', $pairs, $d );
	}

	/**
	 * package.json に入れる URL。wp-config の WP_HOME などで画面上の URL と DB の値が違うときは、
	 * DB 側を InternalHomeURL / InternalSiteURL に入れる（All-in-One WP Migration と同じ項目名）。
	 */
	private static function url_package( array $d ) {
		$urls = array( 'SiteURL' => $d['site'], 'HomeURL' => $d['home'] );
		$raw  = isset( $d['raw'] ) ? $d['raw'] : array();
		if ( ! empty( $raw['siteurl'] ) && rtrim( $raw['siteurl'], '/' ) !== rtrim( $d['site'], '/' ) ) {
			$urls['InternalSiteURL'] = rtrim( $raw['siteurl'], '/' );
		}
		if ( ! empty( $raw['home'] ) && rtrim( $raw['home'], '/' ) !== rtrim( $d['home'], '/' ) ) {
			$urls['InternalHomeURL'] = rtrim( $raw['home'], '/' );
		}
		return $urls;
	}

	private static function raw_option( $name ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
	}

	/**
	 * 有効なプラグイン。移行処理のリクエストでは Unbox_Guard が get_option を差し替えているので、DB から直接読む。
	 */
	public static function active_plugins() {
		global $wpdb;
		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'active_plugins' ) );
		$list = $raw ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : array();
		return is_array( $list ) ? array_values( $list ) : array();
	}

	// ---------------------------------------------------------------- ファイル一覧

	/** wp-content からの相対パスで、除外するものの一覧。 */
	private static function excludes( array $opt ) {
		$ex = array(
			Unbox_Storage::dirname(),
			'ai1wm-backups',
			'plugins/all-in-one-wp-migration/storage',
			'mu-plugins/' . Unbox_Guard::FILE,
			'upgrade',
			'upgrade-temp-backup',
			'updraft',
			'backups-dup-lite',
			'backup-db',
		);
		if ( $opt['exclude_cache'] ) {
			$ex[] = 'cache';
			$ex[] = 'et-cache';
			$ex[] = 'litespeed';
		}
		if ( $opt['exclude_media'] ) {
			$ex[] = 'uploads';
		}
		if ( $opt['exclude_inactive_plugins'] ) {
			$active = self::active_plugins();
			$keep   = array( dirname( UNBOX_BASENAME ) => true );
			foreach ( $active as $p ) {
				$keep[ strpos( $p, '/' ) === false ? $p : dirname( $p ) ] = true;
			}
			foreach ( (array) @scandir( WP_PLUGIN_DIR ) as $f ) {
				if ( $f === '.' || $f === '..' || $f === 'index.php' || isset( $keep[ $f ] ) ) {
					continue;
				}
				$ex[] = 'plugins/' . $f;
			}
		}
		if ( $opt['exclude_inactive_themes'] ) {
			$keep = array( get_template() => true, get_stylesheet() => true );
			foreach ( (array) @scandir( get_theme_root() ) as $f ) {
				if ( $f === '.' || $f === '..' || $f === 'index.php' || isset( $keep[ $f ] ) ) {
					continue;
				}
				$ex[] = 'themes/' . $f;
			}
		}
		$ex = array_merge( $ex, $opt['custom_excludes'] );
		return array_fill_keys( apply_filters( 'unbox_export_excludes', $ex, $opt ), true );
	}

	private static function enumerate( Unbox_Job $job, $deadline ) {
		$d = &$job->data;
		if ( ! isset( $d['enum'] ) ) {
			$content = 'files/wp-content/';
			$queue   = array();
			if ( $d['opt']['format'] === 'localwp' ) {
				if ( $d['opt']['include_core'] ) {
					$queue[] = array( untrailingslashit( ABSPATH ) . '/wp-admin', 'files/wp-admin/', null );
					$queue[] = array( untrailingslashit( ABSPATH ) . '/wp-includes', 'files/wp-includes/', null );
				}
			} else {
				$content = '';
			}
			$queue[] = array( WP_CONTENT_DIR, $content, '' );
			$d['enum'] = array( 'queue' => $queue, 'count' => 0, 'bytes' => 0, 'roots' => false );
		}
		$e  = &$d['enum'];
		$ex = self::excludes( $d['opt'] );
		$fh = fopen( $job->dir( 'files.jsonl' ), 'ab' );
		if ( ! $e['roots'] ) {
			if ( $d['opt']['format'] === 'localwp' && $d['opt']['include_core'] ) {
				foreach ( self::CORE_FILES as $f ) {
					$p = untrailingslashit( ABSPATH ) . '/' . $f;
					if ( is_file( $p ) ) {
						self::add_line( $fh, $e, $p, 'files/' . $f );
					}
				}
			}
			// wp-content の外（WordPress のフォルダー直下）で選ばれたもの
			$prefix = $d['opt']['format'] === 'localwp' ? 'files/' : Unbox_Wpress::ROOT_PREFIX;
			foreach ( $d['opt']['root_items'] as $name ) {
				$p = untrailingslashit( ABSPATH ) . '/' . $name;
				if ( is_dir( $p ) ) {
					$e['queue'][] = array( $p, $prefix . $name . '/', null );
				} elseif ( is_file( $p ) ) {
					self::add_line( $fh, $e, $p, $prefix . $name );
				}
			}
			$e['roots'] = true;
		}
		while ( $e['queue'] ) {
			list( $dir, $prefix, $rel ) = array_shift( $e['queue'] );
			$items = @scandir( $dir );
			if ( $items === false ) {
				$job->log( sprintf( /* translators: %s: folder path */ __( 'Skipped an unreadable folder: %s', 'unbox-by-oobe' ), $dir ) );
				continue;
			}
			foreach ( $items as $f ) {
				if ( $f === '.' || $f === '..' ) {
					continue;
				}
				$path = $dir . '/' . $f;
				$r    = $rel === null ? null : ( $rel === '' ? $f : $rel . '/' . $f );
				if ( $r !== null && isset( $ex[ $r ] ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( is_link( $path ) ) {
						$job->log( sprintf( /* translators: %s: path */ __( 'Skipped a symbolic link: %s', 'unbox-by-oobe' ), $r !== null ? $r : $path ) );
						continue;
					}
					$e['queue'][] = array( $path, $prefix . $f . '/', $r );
				} elseif ( is_file( $path ) ) {
					if ( ! is_readable( $path ) ) {
						$job->log( sprintf( /* translators: %s: path */ __( 'Skipped an unreadable file: %s', 'unbox-by-oobe' ), $r !== null ? $r : $path ) );
						continue;
					}
					self::add_line( $fh, $e, $path, $prefix . $f );
				}
			}
			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
		fclose( $fh );
		return ! $e['queue'];
	}

	private static function add_line( $fh, array &$e, $path, $name ) {
		$size = (int) @filesize( $path );
		fwrite( $fh, json_encode( array( $path, $name ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		$e['count']++;
		$e['bytes'] += $size;
	}

	// ---------------------------------------------------------------- アーカイブ

	private static function package_json( array $d ) {
		global $wp_version, $wpdb;
		return wp_json_encode(
			self::url_package( $d ) + array(
				'Plugin'    => array( 'Version' => UNBOX_VERSION ),
				'WordPress' => array( 'Version' => $wp_version, 'Content' => WP_CONTENT_DIR ),
				'Database'  => array( 'Version' => $wpdb->db_version(), 'Prefix' => $wpdb->base_prefix ),
				'PHP'       => array( 'Version' => PHP_VERSION ),
				'Plugins'   => array_values( self::active_plugins() ),
				'Template'  => get_template(),
				'Stylesheet' => get_stylesheet(),
				'RootItems' => $d['opt']['root_items'],
				'Generator' => 'Unbox ' . UNBOX_VERSION,
			),
			JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);
	}

	private static function archive( Unbox_Job $job, $deadline ) {
		$d      = &$job->data;
		$is_zip = $d['opt']['format'] === 'localwp';
		$out    = $job->dir( 'archive.tmp' );
		if ( ! isset( $d['arc'] ) ) {
			@unlink( $out );
			@unlink( $job->dir( 'central.jsonl' ) );
			$d['arc'] = array( 'size' => 0, 'cd' => 0, 'line' => 0, 'cur' => null, 'state' => array(), 'bytes' => 0, 'head' => false );
		}
		$a      = &$d['arc'];
		$writer = $is_zip ? new Unbox_Zip_Writer( $out, $job->dir( 'central.jsonl' ), $a['size'], $a['cd'] ) : new Unbox_Wpress_Writer( $out, $a['size'] );

		if ( ! $a['head'] ) {
			if ( ! $is_zip ) {
				$writer->add_string( 'package.json', self::package_json( $d ) );
			}
			// DB は大きいことがあるので、ふつうのファイルと同じく途中で区切れる扱いにする
			$a['cur']   = array( $job->dir( 'database.sql' ), 'database.sql' );
			$a['state'] = array();
			$a['head']  = true;
			$a['size']  = $writer->tell();
		}

		$list = fopen( $job->dir( 'files.jsonl' ), 'rb' );
		fseek( $list, $a['line'] );
		$finished = false;
		while ( true ) {
			if ( $a['cur'] === null ) {
				$line = fgets( $list );
				if ( $line === false ) {
					$finished = true;
					break;
				}
				$a['cur']   = json_decode( $line, true );
				$a['line']  = ftell( $list );
				$a['state'] = array();
			}
			list( $src, $name ) = $a['cur'];
			$before = isset( $a['state']['written'] ) ? $a['state']['written'] : 0;
			try {
				$done = $writer->add_file( $src, $name, $a['state'], $deadline );
			} catch ( Unbox_Exception $e ) {
				if ( ! isset( $a['state']['size'] ) && ! isset( $a['state']['offset'] ) ) {
					// まだ何も書いていない（消えた・読めない）ファイルは飛ばす
					$job->log( sprintf( /* translators: %1$s: file name, %2$s: reason */ __( 'Skipped: %1$s (%2$s)', 'unbox-by-oobe' ), $name, $e->getMessage() ) );
					$a['cur'] = null;
					continue;
				}
				throw $e;
			}
			if ( $name !== 'database.sql' ) {
				$a['bytes'] += $a['state']['written'] - $before;
			}
			if ( $done ) {
				$a['cur'] = null;
			}
			$a['size'] = $writer->tell();
			if ( $is_zip ) {
				$a['cd'] = $writer->cd_size();
			}
			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
		fclose( $list );
		if ( $finished ) {
			$writer->finish();
			return true;
		}
		$writer->close();
		return false;
	}

	private static function finish( Unbox_Job $job ) {
		$d      = &$job->data;
		$is_zip = $d['opt']['format'] === 'localwp';
		$src    = $job->dir( 'archive.tmp' );
		$host   = (string) wp_parse_url( $d['home'], PHP_URL_HOST );
		$path   = trim( (string) wp_parse_url( $d['home'], PHP_URL_PATH ), '/' );
		$base   = Unbox_Storage::safe_filename( $host . ( $path !== '' ? '-' . str_replace( '/', '-', $path ) : '' ) );
		$name   = $base . '-' . wp_date( 'Ymd-His' ) . ( $is_zip ? '-localwp.zip' : '.' . $d['opt']['format'] );
		$dest   = Unbox_Storage::unique_path( Unbox_Storage::dir( 'archives' ), $name );
		if ( ! rename( $src, $dest ) ) {
			throw new Unbox_Exception( __( 'Could not move the finished file', 'unbox-by-oobe' ) );
		}
		$size = Unbox_Wpress_Reader::filesize( $dest );
		$job->log( sprintf( /* translators: %1$s: file name, %2$s: size */ __( 'Done: %1$s (%2$s)', 'unbox-by-oobe' ), basename( $dest ), size_format( $size, 1 ) ) );
		$d['stage']  = 'done';
		$d['result'] = array(
			'id'         => 'own:' . basename( $dest ),
			'name'       => basename( $dest ),
			'size'       => $size,
			'format'     => $d['opt']['format'],
			'local_name' => $d['opt']['local_name'],
		);
		foreach ( array( 'database.sql', 'files.jsonl', 'central.jsonl' ) as $f ) {
			@unlink( $job->dir( $f ) );
		}
		Unbox_Guard::remove_if_idle( $job->id );
	}
}
