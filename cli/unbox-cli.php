<?php
/**
 * Unbox のコマンドライン版（WordPress なしで動く）。
 *
 *   php unbox-cli.php info    <file.unbox|.wpress>
 *   php unbox-cli.php extract <file.unbox|.wpress> <出力フォルダー>
 *   php unbox-cli.php localwp <file.unbox|.wpress> <サイト名> [出力.zip]
 *
 * localwp: .unbox / .wpress を LocalWP の「Import site」に渡せる zip へ変換する。
 *          URL は http://<サイト名>.local に置き換える。WordPress 本体は入らないので、LocalWP が最新版を入れる。
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

/*
 * WordPress の外で動くので、翻訳関数を自前で用意する。
 * 環境変数の言語が ja（または未設定）なら languages/unbox-ja.po の訳を使う。
 */
if ( ! function_exists( '__' ) ) {
	function unbox_cli_translations() {
		static $map = null;
		if ( $map !== null ) {
			return $map;
		}
		$map  = array();
		$lang = (string) ( getenv( 'LC_ALL' ) ?: ( getenv( 'LC_MESSAGES' ) ?: getenv( 'LANG' ) ) );
		if ( $lang !== '' && stripos( $lang, 'ja' ) !== 0 ) {
			return $map;
		}
		$po = @file_get_contents( __DIR__ . '/../languages/unbox-ja.po' );
		if ( $po === false ) {
			return $map;
		}
		$unquote = function ( $str ) {
			preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $str, $m );
			return stripcslashes( implode( '', $m[1] ) );
		};
		foreach ( preg_split( '/\n\s*\n/', $po ) as $block ) {
			if ( preg_match( '/(?:msgctxt\s+((?:"[^\n]*"\s*)+))?msgid\s+((?:"[^\n]*"\s*)+)msgstr\s+((?:"[^\n]*"\s*)+)/', $block, $m ) ) {
				$id  = ( $m[1] !== '' ? $unquote( $m[1] ) . "\4" : '' ) . $unquote( $m[2] );
				$str = $unquote( $m[3] );
				if ( $str !== '' ) {
					$map[ $id ] = $str;
				}
			}
		}
		return $map;
	}
	function __( $text, $domain = 'default' ) {
		$map = unbox_cli_translations();
		return isset( $map[ $text ] ) ? $map[ $text ] : $text;
	}
	function _x( $text, $context, $domain = 'default' ) {
		$map = unbox_cli_translations();
		return isset( $map[ $context . "\4" . $text ] ) ? $map[ $context . "\4" . $text ] : $text;
	}
}

/** 「名前: 値」の行を、名前の幅をそろえて出す。 */
function unbox_cli_row( $label, $value ) {
	$width = function_exists( 'mb_strwidth' ) ? mb_strwidth( $label ) : strlen( $label );
	echo $label, str_repeat( ' ', max( 1, 18 - $width ) ), $value, "\n";
}

// 中のファイルは ABSPATH が無いと止まる作りなので、ここで決めておく
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
require __DIR__ . '/../includes/class-unbox-wpress.php';
require __DIR__ . '/../includes/class-unbox-zip.php';
require __DIR__ . '/../includes/class-unbox-replacer.php';
require __DIR__ . '/../includes/class-unbox-sql.php';

function unbox_cli_usage() {
	fwrite( STDERR, __( 'Usage:', 'unbox' ) . "\n  php unbox-cli.php info    <file.unbox|.wpress>\n  php unbox-cli.php extract <file.unbox|.wpress> <" . __( 'output folder', 'unbox' ) . ">\n  php unbox-cli.php localwp <file.unbox|.wpress> <" . __( 'site name', 'unbox' ) . "> [output.zip]\n" );
	exit( 1 );
}

function unbox_cli_size( $n ) {
	$u = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	$i = 0;
	while ( $n >= 1024 && $i < 4 ) {
		$n /= 1024;
		$i++;
	}
	return ( $i ? number_format( $n, 1 ) : $n ) . ' ' . $u[ $i ];
}

/** 全エントリーを読み、package.json と database.sql の位置を返す。 */
function unbox_cli_scan( Unbox_Wpress_Reader $r ) {
	if ( ! $r->is_valid() ) {
		throw new Unbox_Exception( __( 'The end of the file was not found (it may be cut off, or it is not a .unbox / .wpress file)', 'unbox' ) );
	}
	$off  = 0;
	$list = array();
	while ( ( $e = $r->header_at( $off ) ) !== null ) {
		$list[] = $e;
		$off    = $e['next'];
	}
	return $list;
}

function unbox_cli_find( array $list, $name ) {
	foreach ( $list as $e ) {
		if ( $e['name'] === $name ) {
			return $e;
		}
	}
	return null;
}

function unbox_cli_progress( $done, $total ) {
	static $last = -1;
	$p = $total ? (int) floor( 100 * $done / $total ) : 100;
	if ( $p !== $last ) {
		fwrite( STDERR, "\r  " . $p . '%' );
		$last = $p;
	}
}

$cmd  = isset( $argv[1] ) ? $argv[1] : '';
$file = isset( $argv[2] ) ? $argv[2] : '';
if ( $file === '' || ! is_file( $file ) ) {
	unbox_cli_usage();
}

try {
	$r    = new Unbox_Wpress_Reader( $file );
	$list = unbox_cli_scan( $r );
	$pe   = unbox_cli_find( $list, 'package.json' );
	$pkg  = $pe ? json_decode( $r->read_string( $pe ), true ) : array();
	$db   = unbox_cli_find( $list, 'database.sql' );

	switch ( $cmd ) {
		case 'info':
			$bytes = 0;
			foreach ( $list as $e ) {
				$bytes += $e['size'];
			}
			unbox_cli_row( __( 'File', 'unbox' ), basename( $file ) . ' (' . unbox_cli_size( $r->size() ) . ')' );
			unbox_cli_row( __( 'Entries', 'unbox' ), sprintf( __( '%1$s (contents %2$s)', 'unbox' ), number_format( count( $list ) ), unbox_cli_size( $bytes ) ) );
			unbox_cli_row( __( 'Source site', 'unbox' ), isset( $pkg['HomeURL'] ) ? $pkg['HomeURL'] : '?' );
			if ( isset( $pkg['SiteURL'] ) && $pkg['SiteURL'] !== $pkg['HomeURL'] ) {
				unbox_cli_row( __( 'WordPress URL', 'unbox' ), $pkg['SiteURL'] );
			}
			unbox_cli_row( 'WordPress', isset( $pkg['WordPress']['Version'] ) ? $pkg['WordPress']['Version'] : '?' );
			unbox_cli_row( 'PHP', isset( $pkg['PHP']['Version'] ) ? $pkg['PHP']['Version'] : '?' );
			unbox_cli_row( 'DB', ( isset( $pkg['Database']['Version'] ) ? $pkg['Database']['Version'] : '?' ) . ' (database.sql ' . ( $db ? unbox_cli_size( $db['size'] ) : __( 'none', 'unbox' ) ) . ')' );
			unbox_cli_row( __( 'Theme', 'unbox' ), isset( $pkg['Stylesheet'] ) ? $pkg['Stylesheet'] : '?' );
			unbox_cli_row( __( 'Active plugins', 'unbox' ), isset( $pkg['Plugins'] ) ? implode( ', ', (array) $pkg['Plugins'] ) : '?' );
			$roots = array();
			foreach ( $list as $e ) {
				if ( strpos( $e['name'], Unbox_Wpress::ROOT_PREFIX ) === 0 ) {
					$roots[ explode( '/', substr( $e['name'], strlen( Unbox_Wpress::ROOT_PREFIX ) ) )[0] ] = true;
				}
			}
			if ( $roots ) {
				unbox_cli_row( __( 'Outside WordPress', 'unbox' ), implode( ', ', array_keys( $roots ) ) );
			}
			unbox_cli_row( __( 'Created by', 'unbox' ), isset( $pkg['Generator'] ) ? $pkg['Generator'] : 'All-in-One WP Migration ' . ( isset( $pkg['Plugin']['Version'] ) ? $pkg['Plugin']['Version'] : '' ) );
			break;

		case 'extract':
			$dest = isset( $argv[3] ) ? rtrim( $argv[3], '/' ) : '';
			if ( $dest === '' ) {
				unbox_cli_usage();
			}
			$total = 0;
			foreach ( $list as $e ) {
				$total += $e['size'];
			}
			$done = 0;
			foreach ( $list as $e ) {
				$w = 0;
				$r->extract_to( $e, $dest . '/' . $e['name'], $w );
				$done += $e['size'];
				unbox_cli_progress( $done, $total );
			}
			fwrite( STDERR, "\n" );
			echo sprintf( __( 'Extracted %1$s entries to %2$s', 'unbox' ), count( $list ), $dest ), "\n";
			break;

		case 'localwp':
			$name = strtolower( isset( $argv[3] ) ? preg_replace( '/\.local$/', '', $argv[3] ) : '' );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9\-]*$/', $name ) ) {
				throw new Unbox_Exception( __( 'Use lowercase letters, numbers and hyphens for the site name', 'unbox' ) );
			}
			if ( ! $db ) {
				throw new Unbox_Exception( __( 'The archive has no database.sql', 'unbox' ) );
			}
			$out = isset( $argv[4] ) ? $argv[4] : preg_replace( '/\.(unbox|wpress)$/i', '', $file ) . '-localwp.zip';
			if ( file_exists( $out ) ) {
				throw new Unbox_Exception( sprintf( __( 'The output already exists: %s', 'unbox' ), $out ) );
			}
			$new_home = 'http://' . $name . '.local';
			$replacer = new Unbox_Replacer( Unbox_Replacer::package_pairs( $pkg, $new_home ) );
			$prefix   = isset( $pkg['Database']['Prefix'] ) && preg_match( '/^[A-Za-z0-9_]+$/', $pkg['Database']['Prefix'] ) ? $pkg['Database']['Prefix'] : 'wp_';

			// SQL を作る（接頭辞を戻し、URL を置き換え、package.json にしかない有効プラグイン・テーマを足す）
			$tmp_sql = $out . '.sql.tmp';
			$tmp_cd  = $out . '.cd.tmp';
			$tmp_db  = $out . '.db.tmp';
			$w       = 0;
			$r->extract_to( $db, $tmp_db, $w );
			$in  = new Unbox_Sql_Reader( $tmp_db );
			$fh  = fopen( $tmp_sql, 'wb' );
			fwrite( $fh, "-- Unbox (converted from " . basename( $file ) . ")\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n" );
			fwrite( STDERR, __( 'Converting the database', 'unbox' ) . "\n" );
			while ( ( $stmt = $in->next() ) !== null ) {
				$stmt = Unbox_Sql::replace_prefix( $stmt, $prefix, $prefix );
				$stmt = Unbox_Sql::replace_values( $stmt, $replacer );
				fwrite( $fh, $stmt . ";\n" );
				unbox_cli_progress( $in->tell(), $in->size() );
			}
			unset( $in );
			$opt = '`' . $prefix . 'options`';
			$set = function ( $k, $v ) use ( $fh, $opt ) {
				fwrite( $fh, "INSERT INTO $opt (option_name, option_value, autoload) VALUES ('" . Unbox_Sql::escape( $k ) . "', '" . Unbox_Sql::escape( $v ) . "', 'yes') ON DUPLICATE KEY UPDATE option_value = IF(option_value = '' OR option_value = 'a:0:{}', VALUES(option_value), option_value);\n" );
			};
			if ( ! empty( $pkg['Plugins'] ) ) {
				$set( 'active_plugins', serialize( array_values( (array) $pkg['Plugins'] ) ) );
			}
			foreach ( array( 'template' => 'Template', 'stylesheet' => 'Stylesheet' ) as $k => $key ) {
				if ( ! empty( $pkg[ $key ] ) ) {
					$set( $k, $pkg[ $key ] );
				}
			}
			fclose( $fh );
			@unlink( $tmp_db );
			fwrite( STDERR, "\n" . __( 'Building the zip', 'unbox' ) . "\n" );

			$zip = new Unbox_Zip_Writer( $out, $tmp_cd );
			$st  = array();
			$zip->add_file( $tmp_sql, 'database.sql', $st );
			$special = array( 'package.json', 'database.sql', 'multisite.json' );
			$total   = 0;
			foreach ( $list as $e ) {
				if ( ! in_array( $e['name'], $special, true ) ) {
					$total += $e['size'];
				}
			}
			$done = 0;
			foreach ( $list as $e ) {
				if ( in_array( $e['name'], $special, true ) ) {
					continue;
				}
				$zname = strpos( $e['name'], Unbox_Wpress::ROOT_PREFIX ) === 0 ? 'files/' . substr( $e['name'], strlen( Unbox_Wpress::ROOT_PREFIX ) ) : 'files/wp-content/' . $e['name'];
				if ( strpos( $e['name'], Unbox_Wpress::ROOT_PREFIX ) === 0 && ! Unbox_Wpress::is_movable_root( explode( '/', substr( $e['name'], strlen( Unbox_Wpress::ROOT_PREFIX ) ) )[0] ) ) {
					continue;
				}
				$zip->add_stream( $zname, $e['size'], $e['mtime'], $r->stream( $e ) );
				$done += $e['size'];
				unbox_cli_progress( $done, $total );
			}
			$zip->finish();
			@unlink( $tmp_sql );
			fwrite( STDERR, "\n" );
			echo sprintf( __( 'Done: %1$s (%2$s)', 'unbox' ), $out, unbox_cli_size( Unbox_Wpress_Reader::filesize( $out ) ) ), "\n";
			echo __( 'In LocalWP, choose this zip with "Import an existing site". Any site name is fine (URLs are replaced to match the name LocalWP gives)', 'unbox' ), "\n";
			if ( $replacer->skipped ) {
				echo sprintf( __( 'Note: %s values could not be read as serialized data (they were replaced as plain text)', 'unbox' ), $replacer->skipped ), "\n";
			}
			break;

		default:
			unbox_cli_usage();
	}
} catch ( Exception $e ) {
	fwrite( STDERR, sprintf( __( 'Error: %s', 'unbox' ), $e->getMessage() ) . "\n" );
	exit( 1 );
}
