<?php
/**
 * URL などの置き換え。シリアライズされた値は中を読んで文字数を数え直す。
 *
 * unserialize() は使わない（オブジェクトを復元すると任意のクラスが動きうるため）。
 * シリアライズ形式を文字列のまま辿り、s:N:"..." の中身だけを置き換えて N を書き直す。
 *
 * WordPress に依存しない（CLI からも使う）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Unbox_Replacer {
	/** @var array old => new（strtr 用） */
	private $pairs = array();
	private $needles = array();

	/** シリアライズとして読めず、置き換えを見送った値の数 */
	public $skipped = 0;

	public function __construct( array $pairs ) {
		foreach ( $pairs as $old => $new ) {
			$old = (string) $old;
			if ( $old === '' || $old === (string) $new ) {
				continue;
			}
			$this->pairs[ $old ] = (string) $new;
		}
		$this->needles = array_keys( $this->pairs );
	}

	public function is_empty() {
		return ! $this->pairs;
	}

	public function pairs() {
		return $this->pairs;
	}

	/**
	 * 移行元 URL → 移行先 URL の組から、実際に探す文字列の一覧を作る。
	 * http/https・www の有無・JSON の \/ ・URL エンコードの各表記を含める。
	 */
	public static function url_pairs( $old_url, $new_url ) {
		$old_url = rtrim( (string) $old_url, '/' );
		$new_url = rtrim( (string) $new_url, '/' );
		if ( $old_url === '' || $new_url === '' || $old_url === $new_url ) {
			return array();
		}
		$p = parse_url( $old_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This class also runs outside WordPress (command-line tool).
		if ( empty( $p['host'] ) ) {
			return array();
		}
		$rest  = ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . ( isset( $p['path'] ) ? $p['path'] : '' );
		$hosts = array( $p['host'] );
		if ( stripos( $p['host'], 'www.' ) === 0 ) {
			$hosts[] = substr( $p['host'], 4 );
		} elseif ( substr_count( $p['host'], '.' ) >= 1 && ! filter_var( $p['host'], FILTER_VALIDATE_IP ) ) {
			$hosts[] = 'www.' . $p['host'];
		}
		$pairs = array();
		foreach ( $hosts as $host ) {
			foreach ( array( 'http', 'https' ) as $scheme ) {
				$old = $scheme . '://' . $host . $rest;
				$pairs[ $old ]                          = $new_url;
				$pairs[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new_url );
				$pairs[ rawurlencode( $old ) ]          = rawurlencode( $new_url );
			}
		}
		return $pairs;
	}

	/**
	 * package.json の URL（HomeURL / SiteURL と、DB 側の表記 InternalHomeURL / InternalSiteURL）から、
	 * 移行先 $new_home への置き換えの組を作る。WordPress 本体がサブフォルダーにある場合はその分を保つ。
	 */
	public static function package_pairs( array $pkg, $new_home ) {
		$new_home = rtrim( (string) $new_home, '/' );
		$home     = rtrim( (string) $pkg['HomeURL'], '/' );
		$pairs    = array();
		foreach ( array( 'SiteURL', 'InternalSiteURL', 'HomeURL', 'InternalHomeURL' ) as $key ) {
			if ( empty( $pkg[ $key ] ) ) {
				continue;
			}
			$old = rtrim( (string) $pkg[ $key ], '/' );
			$new = $new_home;
			if ( strpos( $key, 'Site' ) !== false ) {
				// WordPress 本体のサブフォルダー（home からの差分）
				$base = ( $key === 'InternalSiteURL' && ! empty( $pkg['InternalHomeURL'] ) ) ? rtrim( $pkg['InternalHomeURL'], '/' ) : $home;
				if ( $old !== $base && strpos( $old, $base . '/' ) === 0 ) {
					$new .= substr( $old, strlen( $base ) );
				}
			}
			$pairs += self::url_pairs( $old, $new );
		}
		uksort(
			$pairs,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $pairs;
	}

	/** この文字列に置き換え対象が含まれるか（速い判定）。 */
	public function has_needle( $str ) {
		foreach ( $this->needles as $n ) {
			if ( strpos( $str, $n ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/** 値を1つ置き換える。シリアライズなら中を辿る。 */
	public function replace( $value ) {
		if ( ! $this->pairs || $value === null || $value === '' ) {
			return $value;
		}
		$value = (string) $value;
		if ( ! $this->has_needle( $value ) ) {
			return $value;
		}
		if ( self::looks_serialized( $value ) ) {
			try {
				$pos = 0;
				$out = $this->walk( $value, $pos );
				if ( $pos === strlen( $value ) || trim( substr( $value, $pos ) ) === '' ) {
					return $out;
				}
			} catch ( Exception $e ) {
				// 下へ
			}
			// シリアライズとして読めない値は PHP 側でも元から読めないので、ふつうに置き換えても悪化しない
			$this->skipped++;
		}
		return strtr( $value, $this->pairs );
	}

	public static function looks_serialized( $v ) {
		$v = trim( $v );
		if ( strlen( $v ) < 4 || $v[1] !== ':' ) {
			return $v === 'N;';
		}
		$last = substr( $v, -1 );
		if ( $last !== ';' && $last !== '}' ) {
			return false;
		}
		return strpos( 'aOsibdNCE', $v[0] ) !== false;
	}

	/** シリアライズ形式を1値ぶん読み、置き換え済みの表記を返す。 */
	private function walk( $s, &$pos ) {
		$len = strlen( $s );
		if ( $pos >= $len ) {
			throw new Exception( 'eof' );
		}
		$t = $s[ $pos ];
		switch ( $t ) {
			case 'N':
				$this->expect( $s, $pos, 'N;' );
				return 'N;';
			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $pos );
				if ( $end === false || $s[ $pos + 1 ] !== ':' ) {
					throw new Exception( 'scalar' );
				}
				$out = substr( $s, $pos, $end - $pos + 1 );
				$pos = $end + 1;
				return $out;
			case 's':
				$str = $this->read_len_string( $s, $pos, 's' );
				$this->expect( $s, $pos, ';' );
				$new = $this->replace( $str );
				return 's:' . strlen( $new ) . ':"' . $new . '";';
			case 'E':
				$start = $pos;
				$this->read_len_string( $s, $pos, 'E' );
				$this->expect( $s, $pos, ';' );
				return substr( $s, $start, $pos - $start );
			case 'a':
				$pos  += 2;
				$count = $this->read_int( $s, $pos, ':' );
				$this->expect( $s, $pos, '{' );
				$out = 'a:' . $count . ':{';
				for ( $i = 0; $i < $count * 2; $i++ ) {
					$out .= $this->walk( $s, $pos );
				}
				$this->expect( $s, $pos, '}' );
				return $out . '}';
			case 'O':
				$class = $this->read_len_string( $s, $pos, 'O' );
				$this->expect( $s, $pos, ':' );
				$count = $this->read_int( $s, $pos, ':' );
				$this->expect( $s, $pos, '{' );
				$out = 'O:' . strlen( $class ) . ':"' . $class . '":' . $count . ':{';
				for ( $i = 0; $i < $count * 2; $i++ ) {
					$out .= $this->walk( $s, $pos );
				}
				$this->expect( $s, $pos, '}' );
				return $out . '}';
			case 'C':
				// 独自シリアライズ（Serializable）は中身の形式が分からないので触らない
				$start = $pos;
				$this->read_len_string( $s, $pos, 'C' );
				$this->expect( $s, $pos, ':' );
				$n = $this->read_int( $s, $pos, ':' );
				$this->expect( $s, $pos, '{' );
				$pos += $n;
				$this->expect( $s, $pos, '}' );
				return substr( $s, $start, $pos - $start );
		}
		throw new Exception( 'type' );
	}

	private function expect( $s, &$pos, $str ) {
		if ( substr( $s, $pos, strlen( $str ) ) !== $str ) {
			throw new Exception( 'expect' );
		}
		$pos += strlen( $str );
	}

	private function read_int( $s, &$pos, $terminator ) {
		$end = strpos( $s, $terminator, $pos );
		if ( $end === false ) {
			throw new Exception( 'int' );
		}
		$num = substr( $s, $pos, $end - $pos );
		if ( $num === '' || ! ctype_digit( $num ) ) {
			throw new Exception( 'int' );
		}
		$pos = $end + 1;
		return (int) $num;
	}

	/** X:N:"...." を読んで中身を返す（$pos は閉じ引用符の次へ）。 */
	private function read_len_string( $s, &$pos, $type ) {
		$this->expect( $s, $pos, $type . ':' );
		$n = $this->read_int( $s, $pos, ':' );
		$this->expect( $s, $pos, '"' );
		if ( $pos + $n + 1 > strlen( $s ) || $s[ $pos + $n ] !== '"' ) {
			throw new Exception( 'len' );
		}
		$str  = substr( $s, $pos, $n );
		$pos += $n + 1;
		return $str;
	}
}
