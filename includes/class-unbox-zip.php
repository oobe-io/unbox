<?php
/**
 * 無圧縮（store）の zip を少しずつ書き出す。
 *
 * ZipArchive は close() の時点でまとめて書くため、数GB のサイトだと1リクエストに収まらない。
 * ここでは1ファイルの途中でも中断・再開できるように、ヘッダーを先に書いて CRC を後から書き戻す。
 * 4GB を超えるファイル・アーカイブは ZIP64 で書く。
 *
 * WordPress に依存しない（CLI からも使う）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; multi-gigabyte archives are read and written with native file handles.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON and rendered as text by the admin script, never echoed as HTML.

class Unbox_Zip_Writer {
	private $path;
	private $fh;
	private $cd_path;

	/**
	 * @param string   $path        出力する zip
	 * @param string   $cd_path     中央ディレクトリの控え（1行1エントリの JSON）
	 * @param int|null $resume_size 途中から再開するとき、前回確定したサイズ
	 * @param int|null $resume_cd   同じく、中央ディレクトリの控えの確定サイズ
	 */
	public function __construct( $path, $cd_path, $resume_size = null, $resume_cd = null ) {
		$this->path    = $path;
		$this->cd_path = $cd_path;
		$this->fh      = @fopen( $path, 'c+b' );
		if ( ! $this->fh ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Cannot write the zip: %s', 'unbox-by-oobe' ), $path ) );
		}
		if ( $resume_size !== null ) {
			ftruncate( $this->fh, $resume_size );
		}
		if ( $resume_cd !== null && file_exists( $cd_path ) ) {
			$cd = fopen( $cd_path, 'c' );
			ftruncate( $cd, $resume_cd );
			fclose( $cd );
		}
		fseek( $this->fh, 0, SEEK_END );
	}

	public function cd_size() {
		clearstatcache( true, $this->cd_path );
		return file_exists( $this->cd_path ) ? (int) filesize( $this->cd_path ) : 0;
	}

	public function __destruct() {
		$this->close();
	}

	public function close() {
		if ( $this->fh ) {
			fflush( $this->fh );
			fclose( $this->fh );
			$this->fh = null;
		}
	}

	public function tell() {
		return ftell( $this->fh );
	}

	private function write( $data ) {
		if ( fwrite( $this->fh, $data ) !== strlen( $data ) ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Failed to write (the disk may be full): %s', 'unbox-by-oobe' ), $this->path ) );
		}
	}

	private static function dos_time( $ts ) {
		$d = getdate( $ts ? $ts : time() );
		if ( $d['year'] < 1980 ) {
			return array( 0, ( 1 << 5 ) | 1 );
		}
		$time = ( $d['hours'] << 11 ) | ( $d['minutes'] << 5 ) | ( $d['seconds'] >> 1 );
		$date = ( ( $d['year'] - 1980 ) << 9 ) | ( $d['mon'] << 5 ) | $d['mday'];
		return array( $time, $date );
	}

	/** ローカルヘッダーを書き、エントリーの状態を返す。 */
	private function begin_entry( $name, $size, $mtime ) {
		$name   = Unbox_Wpress::safe_relpath( $name );
		$zip64  = $size >= 0xFFFFFFFF;
		$offset = $this->tell();
		list( $t, $d ) = self::dos_time( $mtime );
		$extra = $zip64 ? pack( 'vvPP', 0x0001, 16, $size, $size ) : '';
		$this->write(
			pack( 'VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, 0x0800, 0, $t, $d, 0, $zip64 ? 0xFFFFFFFF : $size, $zip64 ? 0xFFFFFFFF : $size, strlen( $name ), strlen( $extra ) )
			. $name . $extra
		);
		return array(
			'name'    => $name,
			'size'    => (int) $size,
			'written' => 0,
			'crc'     => 0,
			'offset'  => $offset,
			'time'    => $t,
			'date'    => $d,
		);
	}

	/** 中身を書き終えたら CRC を書き戻し、中央ディレクトリの控えに追記する。 */
	private function end_entry( array $state ) {
		$end = $this->tell();
		fseek( $this->fh, $state['offset'] + 14 );
		$this->write( pack( 'V', $state['crc'] ) );
		fseek( $this->fh, $end );
		if ( file_put_contents( $this->cd_path, json_encode( $state ) . "\n", FILE_APPEND ) === false ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Cannot write a working file: %s', 'unbox-by-oobe' ), $this->cd_path ) );
		}
	}

	public function add_string( $name, $data, $mtime = null ) {
		$state        = $this->begin_entry( $name, strlen( $data ), $mtime === null ? time() : $mtime );
		$this->write( $data );
		$state['crc']     = crc32( $data );
		$state['written'] = strlen( $data );
		$this->end_entry( $state );
	}

	/**
	 * ファイルを追加する。$state が空なら新規、途中ならその続きから。
	 *
	 * @return bool 書き終えたら true
	 */
	public function add_file( $src, $name, array &$state, $deadline = 0 ) {
		// ヘッダーを書く前に開けるか確かめる
		$in = @fopen( $src, 'rb' );
		if ( ! $in ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Cannot read the file: %s', 'unbox-by-oobe' ), $src ) );
		}
		if ( ! isset( $state['offset'] ) ) {
			$stat  = fstat( $in );
			$state = $this->begin_entry( $name, (int) $stat['size'], (int) $stat['mtime'] );
		}
		fseek( $in, $state['written'] );
		while ( $state['written'] < $state['size'] ) {
			$want  = (int) min( 1048576, $state['size'] - $state['written'] );
			$chunk = fread( $in, $want );
			if ( $chunk === false || $chunk === '' ) {
				$chunk = str_repeat( "\0", $want );
			}
			$this->write( $chunk );
			$state['crc']      = self::crc32_combine( $state['crc'], crc32( $chunk ), strlen( $chunk ) );
			$state['written'] += strlen( $chunk );
			if ( $deadline && microtime( true ) > $deadline && $state['written'] < $state['size'] ) {
				fclose( $in );
				return false;
			}
		}
		fclose( $in );
		$this->end_entry( $state );
		return true;
	}

	/** ストリームの続きを書く版（.wpress からの変換用）。 */
	public function add_stream( $name, $size, $mtime, $chunks ) {
		$state = $this->begin_entry( $name, $size, $mtime );
		foreach ( $chunks as $chunk ) {
			$this->write( $chunk );
			$state['crc']      = self::crc32_combine( $state['crc'], crc32( $chunk ), strlen( $chunk ) );
			$state['written'] += strlen( $chunk );
		}
		if ( $state['written'] !== $state['size'] ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file name */ __( 'Size mismatch: %s', 'unbox-by-oobe' ), $name ) );
		}
		$this->end_entry( $state );
	}

	/** 中央ディレクトリと終端レコードを書いて閉じる。 */
	public function finish() {
		$cd_start = $this->tell();
		$count    = 0;
		$lines    = @fopen( $this->cd_path, 'rb' );
		if ( $lines ) {
			while ( ( $line = fgets( $lines ) ) !== false ) {
				$e = json_decode( $line, true );
				if ( ! $e ) {
					continue;
				}
				$need_size   = $e['size'] >= 0xFFFFFFFF;
				$need_offset = $e['offset'] >= 0xFFFFFFFF;
				$extra       = '';
				if ( $need_size ) {
					$extra .= pack( 'PP', $e['size'], $e['size'] );
				}
				if ( $need_offset ) {
					$extra .= pack( 'P', $e['offset'] );
				}
				if ( $extra !== '' ) {
					$extra = pack( 'vv', 0x0001, strlen( $extra ) ) . $extra;
				}
				$zip64 = $extra !== '';
				$this->write(
					pack(
						'VvvvvvvVVVvvvvvVV',
						0x02014b50,
						( 3 << 8 ) | 45,
						$zip64 ? 45 : 20,
						0x0800,
						0,
						$e['time'],
						$e['date'],
						$e['crc'],
						$need_size ? 0xFFFFFFFF : $e['size'],
						$need_size ? 0xFFFFFFFF : $e['size'],
						strlen( $e['name'] ),
						strlen( $extra ),
						0,
						0,
						0,
						( 0100644 << 16 ) & 0xFFFFFFFF,
						$need_offset ? 0xFFFFFFFF : $e['offset']
					) . $e['name'] . $extra
				);
				$count++;
			}
			fclose( $lines );
		}
		$cd_end  = $this->tell();
		$cd_size = $cd_end - $cd_start;
		if ( $count >= 0xFFFF || $cd_start >= 0xFFFFFFFF || $cd_size >= 0xFFFFFFFF ) {
			$this->write( pack( 'VPvvVVPPPP', 0x06064b50, 44, ( 3 << 8 ) | 45, 45, 0, 0, $count, $count, $cd_size, $cd_start ) );
			$this->write( pack( 'VVPV', 0x07064b50, 0, $cd_end, 1 ) );
			$this->write( pack( 'VvvvvVVv', 0x06054b50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0 ) );
		} else {
			$this->write( pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $cd_size, $cd_start, 0 ) );
		}
		$this->close();
		@unlink( $this->cd_path );
	}

	/**
	 * crc32(A . B) を crc32(A)・crc32(B)・strlen(B) から求める（zlib の crc32_combine の移植）。
	 * 1ファイルを複数リクエストに分けて書くときに、途中までの CRC を持ち越すために使う。
	 */
	public static function crc32_combine( $crc1, $crc2, $len2 ) {
		static $cache = array();
		if ( $len2 <= 0 ) {
			return $crc1;
		}
		if ( $crc1 === 0 ) {
			return $crc2 & 0xFFFFFFFF;
		}
		// 長さごとの変換は線形なので、基底ベクトル 32 本の行き先を覚えておけば2回目以降は速い
		if ( ! isset( $cache[ $len2 ] ) ) {
			if ( count( $cache ) > 8 ) {
				$cache = array();
			}
			$mat = array();
			for ( $k = 0; $k < 32; $k++ ) {
				$mat[ $k ] = self::crc32_shift( 1 << $k, $len2 );
			}
			$cache[ $len2 ] = $mat;
		}
		return ( self::gf2_times( $cache[ $len2 ], $crc1 ) ^ $crc2 ) & 0xFFFFFFFF;
	}

	private static function crc32_shift( $crc1, $len2 ) {
		$odd    = array( 0xEDB88320 );
		$row    = 1;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[ $n ] = $row;
			$row     <<= 1;
		}
		$even = self::gf2_square( $odd );
		$odd  = self::gf2_square( $even );
		do {
			$even = self::gf2_square( $odd );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $even, $crc1 );
			}
			$len2 >>= 1;
			if ( $len2 === 0 ) {
				break;
			}
			$odd = self::gf2_square( $even );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $odd, $crc1 );
			}
			$len2 >>= 1;
		} while ( $len2 !== 0 );
		return $crc1 & 0xFFFFFFFF;
	}

	private static function gf2_times( array $mat, $vec ) {
		$sum = 0;
		$i   = 0;
		while ( $vec ) {
			if ( $vec & 1 ) {
				$sum ^= $mat[ $i ];
			}
			$vec >>= 1;
			$i++;
		}
		return $sum & 0xFFFFFFFF;
	}

	private static function gf2_square( array $mat ) {
		$sq = array();
		for ( $n = 0; $n < 32; $n++ ) {
			$sq[ $n ] = self::gf2_times( $mat, $mat[ $n ] );
		}
		return $sq;
	}
}
