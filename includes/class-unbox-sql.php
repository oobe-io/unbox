<?php
/**
 * SQL ダンプの読み書きに使う道具。
 *
 * - Unbox_Sql::rewrite()  文字列リテラルとそれ以外（テーブル名など）を分けて書き換える
 * - Unbox_Sql_Reader     ダンプから1文ずつ読み出す（途中位置から再開できる）
 *
 * WordPress に依存しない（CLI からも使う）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; multi-gigabyte archives are read and written with native file handles.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON and rendered as text by the admin script, never echoed as HTML.

class Unbox_Sql {
	/** All-in-One WP Migration と同じ、テーブル接頭辞の置き場所を示す文字列 */
	const PREFIX_PLACEHOLDER = 'SERVMASK_PREFIX_';

	public static function escape( $s ) {
		return strtr(
			(string) $s,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	public static function unescape( $raw, $quote = "'" ) {
		if ( strpos( $raw, '\\' ) === false && strpos( $raw, $quote . $quote ) === false ) {
			return $raw;
		}
		return preg_replace_callback(
			'/\\\\(.)|' . preg_quote( $quote . $quote, '/' ) . '/s',
			function ( $m ) use ( $quote ) {
				if ( ! isset( $m[1] ) ) {
					return $quote;
				}
				switch ( $m[1] ) {
					case '0':
						return "\0";
					case 'n':
						return "\n";
					case 'r':
						return "\r";
					case 't':
						return "\t";
					case 'b':
						return "\x08";
					case 'Z':
						return "\x1a";
					case '%':
						return '\\%';
					case '_':
						return '\\_';
				}
				return $m[1];
			},
			$raw
		);
	}

	/**
	 * 文を、リテラル以外の部分（$outside_fn）とリテラルの中身（$literal_fn）に分けて書き換える。
	 * $literal_fn には SQL エスケープを外した値が渡り、返した値は再エスケープされる。
	 */
	public static function rewrite( $sql, $outside_fn = null, $literal_fn = null ) {
		$len     = strlen( $sql );
		$pos     = 0;
		$out     = '';
		$outside = '';
		while ( $pos < $len ) {
			$n        = strcspn( $sql, "'\"`", $pos );
			$outside .= substr( $sql, $pos, $n );
			$pos     += $n;
			if ( $pos >= $len ) {
				break;
			}
			$q = $sql[ $pos ];
			if ( $q === '`' ) {
				$end = self::find_close( $sql, $pos + 1, '`', false );
				$outside .= substr( $sql, $pos, $end - $pos + 1 );
				$pos      = $end + 1;
				continue;
			}
			$out    .= $outside_fn ? call_user_func( $outside_fn, $outside ) : $outside;
			$outside = '';
			$end     = self::find_close( $sql, $pos + 1, $q, true );
			$raw     = substr( $sql, $pos + 1, $end - $pos - 1 );
			if ( $literal_fn ) {
				$val = self::unescape( $raw, $q );
				$new = call_user_func( $literal_fn, $val );
				$out .= ( $new === $val ) ? $q . $raw . $q : "'" . self::escape( $new ) . "'";
			} else {
				$out .= $q . $raw . $q;
			}
			$pos = $end + 1;
		}
		$out .= $outside_fn ? call_user_func( $outside_fn, $outside ) : $outside;
		return $out;
	}

	/** 閉じ引用符の位置を返す。見つからなければ例外。 */
	private static function find_close( $sql, $i, $q, $backslash ) {
		$len  = strlen( $sql );
		$stop = $backslash ? $q . '\\' : $q;
		while ( true ) {
			$i += strcspn( $sql, $stop, $i );
			if ( $i >= $len ) {
				throw new Unbox_Exception( __( 'Unclosed quote in SQL', 'unbox' ) );
			}
			if ( $sql[ $i ] === '\\' ) {
				$i += 2;
				continue;
			}
			if ( $i + 1 < $len && $sql[ $i + 1 ] === $q ) {
				$i += 2;
				continue;
			}
			return $i;
		}
	}

	/** 引用符がすべて閉じているか。 */
	public static function is_complete( $sql ) {
		try {
			self::rewrite( $sql );
			return true;
		} catch ( Unbox_Exception $e ) {
			return false;
		}
	}

	/**
	 * 接頭辞の置き場所を差し替える。テーブル名側（リテラル外）と値側（option_name 等）で別の接頭辞にできる。
	 */
	public static function replace_prefix( $sql, $table_prefix, $value_prefix ) {
		if ( strpos( $sql, self::PREFIX_PLACEHOLDER ) === false ) {
			return $sql;
		}
		// よくある形（INSERT 文の先頭だけに出てくる）は速く処理する
		if ( preg_match( '/^INSERT INTO `' . self::PREFIX_PLACEHOLDER . '/', $sql ) ) {
			$head_end = strpos( $sql, '`', 13 ) + 1;
			$rest     = substr( $sql, $head_end );
			$head     = str_replace( self::PREFIX_PLACEHOLDER, $table_prefix, substr( $sql, 0, $head_end ) );
			if ( strpos( $rest, self::PREFIX_PLACEHOLDER ) === false ) {
				return $head . $rest;
			}
			$sql = $head . $rest;
		}
		return self::rewrite(
			$sql,
			function ( $s ) use ( $table_prefix ) {
				return str_replace( self::PREFIX_PLACEHOLDER, $table_prefix, $s );
			},
			function ( $v ) use ( $value_prefix ) {
				return str_replace( self::PREFIX_PLACEHOLDER, $value_prefix, $v );
			}
		);
	}

	/** 値の置き換え（URL 等）。対象が含まれない文はそのまま返す。 */
	public static function replace_values( $sql, Unbox_Replacer $replacer ) {
		if ( $replacer->is_empty() ) {
			return $sql;
		}
		$found = false;
		foreach ( array_keys( $replacer->pairs() ) as $needle ) {
			if ( strpos( $sql, self::escape( $needle ) ) !== false || strpos( $sql, $needle ) !== false ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return $sql;
		}
		return self::rewrite( $sql, null, array( $replacer, 'replace' ) );
	}

	/** CREATE TABLE 内の照合順序・文字コードを、取り込み先で使えるものに直す。 */
	public static function fix_collations( $sql, array $supported ) {
		if ( stripos( $sql, 'CREATE TABLE' ) !== 0 || ! $supported ) {
			return $sql;
		}
		$fallback = isset( $supported['utf8mb4_unicode_520_ci'] ) ? 'utf8mb4_unicode_520_ci' : ( isset( $supported['utf8mb4_unicode_ci'] ) ? 'utf8mb4_unicode_ci' : 'utf8_general_ci' );
		return self::rewrite(
			$sql,
			function ( $s ) use ( $supported, $fallback ) {
				$s = preg_replace_callback(
					'/(COLLATE\s*=?\s*)([A-Za-z0-9_]+)/i',
					function ( $m ) use ( $supported, $fallback ) {
						$c = strtolower( $m[2] );
						return $m[1] . ( isset( $supported[ $c ] ) ? $m[2] : $fallback );
					},
					$s
				);
				if ( strpos( $fallback, 'utf8mb4' ) !== 0 ) {
					$s = preg_replace( '/\butf8mb4\b/i', 'utf8', $s );
				}
				return $s;
			}
		);
	}
}

class Unbox_Sql_Reader {
	private $fh;
	private $path;

	public function __construct( $path, $offset = 0 ) {
		$this->path = $path;
		$this->fh   = @fopen( $path, 'rb' );
		if ( ! $this->fh ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Cannot open the SQL file: %s', 'unbox' ), $path ) );
		}
		fseek( $this->fh, $offset );
	}

	public function __destruct() {
		if ( $this->fh ) {
			fclose( $this->fh );
		}
	}

	public function tell() {
		return ftell( $this->fh );
	}

	public function size() {
		$stat = fstat( $this->fh );
		return (int) $stat['size'];
	}

	/** 次の1文（末尾の ; なし）。終わりなら null。 */
	public function next() {
		$stmt = '';
		while ( ( $line = fgets( $this->fh ) ) !== false ) {
			if ( $stmt === '' ) {
				$t = ltrim( $line );
				if ( $t === '' || strpos( $t, '--' ) === 0 || $t[0] === '#' || stripos( $t, 'DELIMITER ' ) === 0 ) {
					continue;
				}
			}
			$stmt .= $line;
			if ( preg_match( '/;\s*$/', $line ) && Unbox_Sql::is_complete( $stmt ) ) {
				return rtrim( trim( $stmt ), ';' );
			}
		}
		$stmt = trim( $stmt );
		return $stmt === '' ? null : rtrim( $stmt, ';' );
	}
}
