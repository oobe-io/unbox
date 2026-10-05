<?php
/**
 * .wpress 形式の読み書き。
 *
 * 形式: 各ファイルの前に 4377 バイトのヘッダー（ファイル名 255 / サイズ 14 / 更新日時 12 / ディレクトリ 4096、
 * いずれも NUL 埋めの文字列）が付き、その直後に中身がそのまま続く。末尾は 4377 バイトの NUL ブロック。
 * All-in-One WP Migration が書き出す .wpress と相互に読み書きできる。
 *
 * WordPress に依存しない（CLI からも使う）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; multi-gigabyte archives are read and written with native file handles.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON and rendered as text by the admin script, never echoed as HTML.

if ( ! class_exists( 'Unbox_Exception' ) ) {
	class Unbox_Exception extends Exception {}
}

class Unbox_Wpress {
	const HEADER_SIZE = 4377;
	const PACK_FORMAT = 'a255a14a12a4096';

	/**
	 * WordPress のフォルダー直下（wp-content の外）に置くものの印。.unbox だけで使う。
	 * 例: __root__/static-resources/css/a.css → 取り込み先の ABSPATH/static-resources/css/a.css
	 */
	const ROOT_PREFIX = '__root__/';

	/** WordPress 本体と設定。wp-content の外のものとして運ばない・上書きしない。 */
	const CORE_ROOT = array( 'wp-admin', 'wp-includes', 'wp-content', 'wp-config.php', 'wp-config-sample.php', 'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php', 'license.txt', 'readme.html' );

	/** wp-content の外のものとして運んでよい名前か（直下の名前で判定）。 */
	public static function is_movable_root( $name, $content_dirname = 'wp-content' ) {
		$name = (string) $name;
		if ( $name === '' || $name[0] === '.' || strpos( $name, '/' ) !== false || strpos( $name, '\\' ) !== false ) {
			return false;
		}
		return ! in_array( $name, self::CORE_ROOT, true ) && $name !== $content_dirname;
	}

	/**
	 * アーカイブ内のパスを検査して正規化する。外へ出るパスは拒否する。
	 */
	public static function safe_relpath( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		if ( $path === '' || strpos( $path, "\0" ) !== false ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: path */ __( 'Invalid path in the archive: %s', 'unbox-by-oobe' ), $path ) );
		}
		if ( $path[0] === '/' || preg_match( '#^[A-Za-z]:#', $path ) ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: path */ __( 'The archive contains an absolute path: %s', 'unbox-by-oobe' ), $path ) );
		}
		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( $part === '' || $part === '.' ) {
				continue;
			}
			if ( $part === '..' ) {
				throw new Unbox_Exception( sprintf( /* translators: %s: path */ __( 'The archive contains a path pointing outside: %s', 'unbox-by-oobe' ), $path ) );
			}
			$parts[] = $part;
		}
		if ( ! $parts ) {
			throw new Unbox_Exception( __( 'The archive contains an empty path', 'unbox-by-oobe' ) );
		}
		return implode( '/', $parts );
	}
}

class Unbox_Wpress_Reader {
	/** 書きかけのファイルに付ける名前 */
	const PART_SUFFIX = '.unbox-part';

	private $path;
	private $fh;
	private $size;

	public function __construct( $path ) {
		$this->path = $path;
		$this->fh   = @fopen( $path, 'rb' );
		if ( ! $this->fh ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Cannot open the archive: %s', 'unbox-by-oobe' ), $path ) );
		}
		$this->size = self::filesize( $path, $this->fh );
	}

	public function __destruct() {
		if ( $this->fh ) {
			fclose( $this->fh );
		}
	}

	/** 2GB を超えるファイルでも正しいサイズを返す（64bit PHP 前提）。 */
	public static function filesize( $path, $fh = null ) {
		if ( $fh ) {
			$stat = fstat( $fh );
			return (int) $stat['size'];
		}
		clearstatcache( true, $path );
		return (int) filesize( $path );
	}

	public function size() {
		return $this->size;
	}

	/** 末尾が終端ブロックで終わっているか（書き出しが最後まで終わっているか）。 */
	public function is_valid() {
		if ( $this->size < Unbox_Wpress::HEADER_SIZE ) {
			return false;
		}
		fseek( $this->fh, $this->size - Unbox_Wpress::HEADER_SIZE );
		$block = fread( $this->fh, Unbox_Wpress::HEADER_SIZE );
		return $block === str_repeat( "\0", Unbox_Wpress::HEADER_SIZE );
	}

	/**
	 * $offset にあるヘッダーを読む。終端なら null。
	 *
	 * @return array|null [ 'name' => 相対パス, 'size' => int, 'mtime' => int, 'data' => 中身の開始位置, 'next' => 次のヘッダー位置 ]
	 */
	public function header_at( $offset ) {
		if ( $offset + Unbox_Wpress::HEADER_SIZE > $this->size ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: byte offset */ __( 'The archive is truncated (at byte %s)', 'unbox-by-oobe' ), $offset ) );
		}
		fseek( $this->fh, $offset );
		$block = fread( $this->fh, Unbox_Wpress::HEADER_SIZE );
		if ( strlen( $block ) !== Unbox_Wpress::HEADER_SIZE ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: byte offset */ __( 'Cannot read a header (at byte %s)', 'unbox-by-oobe' ), $offset ) );
		}
		if ( $block === str_repeat( "\0", Unbox_Wpress::HEADER_SIZE ) ) {
			return null;
		}
		$h    = unpack( 'a255name/a14size/a12mtime/a4096path', $block );
		$name = rtrim( $h['name'], "\0" );
		$dir  = trim( rtrim( $h['path'], "\0" ) );
		$size = trim( rtrim( $h['size'], "\0" ) );
		if ( $size === '' || ! ctype_digit( $size ) ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: byte offset */ __( 'Cannot read the size in a header (at byte %s). The archive may be corrupted', 'unbox-by-oobe' ), $offset ) );
		}
		$rel  = ( $dir === '' || $dir === '.' ) ? $name : $dir . '/' . $name;
		$data = $offset + Unbox_Wpress::HEADER_SIZE;
		$len  = (int) $size;
		if ( $data + $len > $this->size ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file name */ __( 'The archive is truncated: %s', 'unbox-by-oobe' ), $rel ) );
		}
		return array(
			'name'  => Unbox_Wpress::safe_relpath( $rel ),
			'size'  => $len,
			'mtime' => (int) trim( rtrim( $h['mtime'], "\0" ) ),
			'data'  => $data,
			'next'  => $data + $len,
		);
	}

	/**
	 * エントリーの中身を $dest へ書き出す。$written バイト済みの続きから、$deadline（microtime）まで。
	 *
	 * @return bool 書き終えたら true
	 */
	public function extract_to( array $entry, $dest, &$written, $deadline = 0 ) {
		// 書きかけのファイルが読み込まれないよう、別名で書き切ってから本来の名前に付け替える
		$part = $dest . self::PART_SUFFIX;
		$dir  = dirname( $dest );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: folder path */ __( 'Cannot create the folder: %s', 'unbox-by-oobe' ), $dir ) );
		}
		$out = @fopen( $part, $written > 0 ? 'cb' : 'wb' );
		if ( ! $out ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Cannot write the file: %s', 'unbox-by-oobe' ), $dest ) );
		}
		if ( $written > 0 ) {
			ftruncate( $out, $written );
			fseek( $out, $written );
		}
		fseek( $this->fh, $entry['data'] + $written );
		while ( $written < $entry['size'] ) {
			$chunk = fread( $this->fh, (int) min( 1048576, $entry['size'] - $written ) );
			if ( $chunk === false || $chunk === '' ) {
				fclose( $out );
				throw new Unbox_Exception( sprintf( /* translators: %s: file name */ __( 'Cannot read the archive: %s', 'unbox-by-oobe' ), $entry['name'] ) );
			}
			if ( fwrite( $out, $chunk ) !== strlen( $chunk ) ) {
				fclose( $out );
				throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Failed to write (the disk may be full): %s', 'unbox-by-oobe' ), $dest ) );
			}
			$written += strlen( $chunk );
			if ( $deadline && microtime( true ) > $deadline && $written < $entry['size'] ) {
				fclose( $out );
				return false;
			}
		}
		fclose( $out );
		if ( $entry['mtime'] > 0 ) {
			@touch( $part, $entry['mtime'] );
		}
		if ( ! @rename( $part, $dest ) ) {
			// 置き換え先を消してからでないと付け替えられない環境（Windows）向け
			@unlink( $dest );
			if ( ! @rename( $part, $dest ) ) {
				throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Cannot write the file: %s', 'unbox-by-oobe' ), $dest ) );
			}
		}
		return true;
	}

	/** 小さいエントリー（package.json 等）を文字列で読む。 */
	public function read_string( array $entry, $max = 16777216 ) {
		if ( $entry['size'] > $max ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file name */ __( 'Too large to read: %s', 'unbox-by-oobe' ), $entry['name'] ) );
		}
		if ( $entry['size'] === 0 ) {
			return '';
		}
		fseek( $this->fh, $entry['data'] );
		return (string) fread( $this->fh, $entry['size'] );
	}

	/** 中身を $chunk_size ごとに読み出すジェネレーター（変換用）。 */
	public function stream( array $entry, $from = 0, $chunk_size = 1048576 ) {
		$pos = $from;
		while ( $pos < $entry['size'] ) {
			fseek( $this->fh, $entry['data'] + $pos );
			$chunk = fread( $this->fh, (int) min( $chunk_size, $entry['size'] - $pos ) );
			if ( $chunk === false || $chunk === '' ) {
				throw new Unbox_Exception( sprintf( /* translators: %s: file name */ __( 'Cannot read the archive: %s', 'unbox-by-oobe' ), $entry['name'] ) );
			}
			$pos += strlen( $chunk );
			yield $chunk;
		}
	}
}

class Unbox_Wpress_Writer {
	private $path;
	private $fh;

	/**
	 * @param int|null $resume_size 途中から再開するとき、前回確定したサイズ。そこまで切り詰めて続きを書く。
	 */
	public function __construct( $path, $resume_size = null ) {
		$this->path = $path;
		$this->fh   = @fopen( $path, 'c+b' );
		if ( ! $this->fh ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Cannot write the archive: %s', 'unbox-by-oobe' ), $path ) );
		}
		if ( $resume_size !== null ) {
			ftruncate( $this->fh, $resume_size );
		}
		fseek( $this->fh, 0, SEEK_END );
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

	public static function header( $relpath, $size, $mtime ) {
		$relpath = Unbox_Wpress::safe_relpath( $relpath );
		$name    = basename( $relpath );
		$dir     = dirname( $relpath );
		if ( strlen( $name ) > 255 || strlen( $dir ) > 4096 ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'The path is too long for the archive: %s', 'unbox-by-oobe' ), $relpath ) );
		}
		return pack( Unbox_Wpress::PACK_FORMAT, $name, (string) $size, (string) $mtime, $dir === '' ? '.' : $dir );
	}

	public function add_string( $relpath, $data, $mtime = null ) {
		$this->write( self::header( $relpath, strlen( $data ), $mtime === null ? time() : $mtime ) );
		$this->write( $data );
	}

	/**
	 * ファイルを追加する。$state は途中再開用（[ 'written' => 中身の書き込み済みバイト, 'size' => ヘッダーに書いたサイズ ]）。
	 * ヘッダーに書いたサイズぴったりを書く（途中でファイルが縮んだら NUL で埋め、伸びたら切る）。
	 *
	 * @return bool 書き終えたら true
	 */
	public function add_file( $src, $relpath, array &$state, $deadline = 0 ) {
		// ヘッダーを書く前に開けるか確かめる（読めないファイルはヘッダーごと出さずに済むように）
		$in = @fopen( $src, 'rb' );
		if ( ! $in ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Cannot read the file: %s', 'unbox-by-oobe' ), $src ) );
		}
		if ( ! isset( $state['size'] ) ) {
			$stat  = fstat( $in );
			$state = array( 'size' => (int) $stat['size'], 'written' => 0 );
			$this->write( self::header( $relpath, $state['size'], (int) $stat['mtime'] ) );
		}
		fseek( $in, $state['written'] );
		while ( $state['written'] < $state['size'] ) {
			$want  = (int) min( 1048576, $state['size'] - $state['written'] );
			$chunk = fread( $in, $want );
			if ( $chunk === false || $chunk === '' ) {
				$chunk = str_repeat( "\0", $want );
			}
			$this->write( $chunk );
			$state['written'] += strlen( $chunk );
			if ( $deadline && microtime( true ) > $deadline && $state['written'] < $state['size'] ) {
				fclose( $in );
				return false;
			}
		}
		fclose( $in );
		return true;
	}

	public function finish() {
		$this->write( str_repeat( "\0", Unbox_Wpress::HEADER_SIZE ) );
		$this->close();
	}
}
