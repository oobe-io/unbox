<?php
/**
 * 作業フォルダとジョブ（書き出し・取り込みの途中経過）の置き場所。
 *
 * 置き場所は wp-content/unbox-<推測できない文字列>/。
 * .htaccess が効かない nginx でも URL を当てられないよう、名前は wp-config の鍵から作る。
 * DB を丸ごと入れ替えても同じ名前になるよう、オプションには保存しない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; multi-gigabyte archives are read and written with native file handles.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON and rendered as text by the admin script, never echoed as HTML.

class Unbox_Storage {
	public static function key() {
		$secret = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $c ) {
			if ( defined( $c ) && constant( $c ) !== 'put your unique phrase here' ) {
				$secret .= constant( $c );
			}
		}
		if ( $secret === '' ) {
			$secret = (string) get_option( 'unbox_secret' );
			if ( $secret === '' ) {
				$secret = wp_generate_password( 32, false );
				update_option( 'unbox_secret', $secret, false );
			}
		}
		return substr( hash_hmac( 'sha256', 'unbox-storage', $secret ), 0, 16 );
	}

	public static function dirname() {
		return 'unbox-' . self::key();
	}

	public static function dir( $sub = '' ) {
		$dir = WP_CONTENT_DIR . '/' . self::dirname();
		return $sub === '' ? $dir : $dir . '/' . $sub;
	}

	public static function ensure() {
		foreach ( array( '', 'archives', 'jobs', 'uploads' ) as $sub ) {
			$d = self::dir( $sub );
			if ( ! is_dir( $d ) && ! wp_mkdir_p( $d ) ) {
				throw new Unbox_Exception( sprintf( /* translators: %s: folder path */ __( 'Cannot create the working folder: %s (wp-content must be writable)', 'unbox-by-oobe' ), $d ) );
			}
			self::protect( $d );
		}
	}

	private static function protect( $dir ) {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $body ) {
			if ( ! file_exists( "$dir/$name" ) ) {
				@file_put_contents( "$dir/$name", $body );
			}
		}
	}

	/** 取り込み元として選べるファイル（自分の書き出し・アップロード分と、AIOWPM のバックアップ置き場）。 */
	public static function list_archives() {
		$list    = array();
		$sources = array(
			'own'   => self::dir( 'archives' ),
			'ai1wm' => WP_CONTENT_DIR . '/ai1wm-backups',
		);
		foreach ( $sources as $src => $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( (array) scandir( $dir ) as $f ) {
				if ( ! preg_match( '/\.(unbox|wpress|zip)$/i', $f ) || ! is_file( "$dir/$f" ) ) {
					continue;
				}
				$list[] = array(
					'id'       => $src . ':' . $f,
					'name'     => $f,
					'source'   => $src,
					'type'     => preg_match( '/\.zip$/i', $f ) ? 'localwp' : 'archive',
					'size'     => Unbox_Wpress_Reader::filesize( "$dir/$f" ),
					'mtime'    => filemtime( "$dir/$f" ),
					'deletable' => $src === 'own',
				);
			}
		}
		usort(
			$list,
			function ( $a, $b ) {
				return $b['mtime'] - $a['mtime'];
			}
		);
		return $list;
	}

	/** "own:ファイル名" 形式の ID から実パスを返す。 */
	public static function resolve_archive( $id ) {
		if ( ! preg_match( '/^(own|ai1wm):([A-Za-z0-9._\-]+\.(unbox|wpress|zip))$/i', (string) $id, $m ) ) {
			throw new Unbox_Exception( __( 'Invalid file', 'unbox-by-oobe' ) );
		}
		$dir  = $m[1] === 'own' ? self::dir( 'archives' ) : WP_CONTENT_DIR . '/ai1wm-backups';
		$path = $dir . '/' . $m[2];
		if ( ! is_file( $path ) ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file name */ __( 'File not found: %s', 'unbox-by-oobe' ), $m[2] ) );
		}
		return array( 'path' => $path, 'source' => strtolower( $m[1] ), 'name' => $m[2] );
	}

	/** ファイル名に使える形にする。 */
	public static function safe_filename( $name ) {
		$name = preg_replace( '/[^A-Za-z0-9._\-]+/', '-', (string) $name );
		$name = trim( $name, '-.' );
		return $name === '' ? 'archive' : $name;
	}

	/** 同じ名前があれば -2, -3 … を付けた空いている名前を返す。 */
	public static function unique_path( $dir, $name ) {
		$path = "$dir/$name";
		if ( ! file_exists( $path ) ) {
			return $path;
		}
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$base = substr( $name, 0, -( strlen( $ext ) + 1 ) );
		for ( $i = 2; ; $i++ ) {
			$path = "$dir/$base-$i.$ext";
			if ( ! file_exists( $path ) ) {
				return $path;
			}
		}
	}

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			@unlink( $dir );
			return;
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( $f === '.' || $f === '..' ) {
				continue;
			}
			self::rrmdir( "$dir/$f" );
		}
		@rmdir( $dir );
	}
}

class Unbox_Job {
	public $id;
	public $data = array();
	private $lock;

	public static function create( $type, array $data ) {
		Unbox_Storage::ensure();
		$job       = new self();
		$job->id   = bin2hex( random_bytes( 8 ) );
		$job->data = array_merge(
			array(
				'stage' => 'init',
				'log'   => array(),
			),
			$data,
			array(
				'type'    => $type,
				'created' => time(),
			)
		);
		wp_mkdir_p( $job->dir() );
		$job->save();
		return $job;
	}

	public static function load( $id ) {
		if ( ! preg_match( '/^[a-f0-9]{16}$/', (string) $id ) ) {
			throw new Unbox_Exception( __( 'Invalid job', 'unbox-by-oobe' ) );
		}
		$job     = new self();
		$job->id = $id;
		$raw     = @file_get_contents( $job->dir() . '/state.json' );
		if ( $raw === false ) {
			throw new Unbox_Exception( __( 'Job not found (it has already finished or was cancelled)', 'unbox-by-oobe' ) );
		}
		$job->data = json_decode( $raw, true );
		if ( ! is_array( $job->data ) ) {
			throw new Unbox_Exception( __( 'Cannot read the job state', 'unbox-by-oobe' ) );
		}
		return $job;
	}

	public function dir( $file = '' ) {
		$d = Unbox_Storage::dir( 'jobs/' . $this->id );
		return $file === '' ? $d : $d . '/' . $file;
	}

	public function save() {
		$tmp = $this->dir( 'state.json.tmp' );
		if ( file_put_contents( $tmp, wp_json_encode( $this->data ) ) === false || ! rename( $tmp, $this->dir( 'state.json' ) ) ) {
			throw new Unbox_Exception( __( 'Cannot save the job state (the disk may be full)', 'unbox-by-oobe' ) );
		}
	}

	/** 同じジョブを同時に2本動かさない。取れなければ false。 */
	public function lock() {
		$this->lock = fopen( $this->dir( 'lock' ), 'c' );
		return $this->lock && flock( $this->lock, LOCK_EX | LOCK_NB );
	}

	public function unlock() {
		if ( $this->lock ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
			$this->lock = null;
		}
	}

	public function log( $msg ) {
		$this->data['log'][] = $msg;
		if ( count( $this->data['log'] ) > 200 ) {
			$this->data['log'] = array_slice( $this->data['log'], -200 );
		}
	}

	public function destroy() {
		$this->unlock();
		Unbox_Storage::rrmdir( $this->dir() );
	}
}
