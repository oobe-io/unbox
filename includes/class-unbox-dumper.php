<?php
/**
 * DB を SQL ファイルへ少しずつ書き出す。
 *
 * - .unbox / .wpress 用: テーブル接頭辞を SERVMASK_PREFIX_ に置き換える（All-in-One WP Migration と同じ）
 * - LocalWP 用: 接頭辞はそのまま、URL を移行先（○○.local）へ置き換えて書く
 *
 * 行は主キー順に 1000 行ずつ、結果をためずに読み出す（大きい postmeta でもメモリを食わない）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; multi-gigabyte archives are read and written with native file handles.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON and rendered as text by the admin script, never echoed as HTML.
// phpcs:disable WordPress.DB.RestrictedFunctions -- Raw mysqli is used for unbuffered reads of large tables and for replaying SQL dumps that may contain binary data, which wpdb would reject or buffer in memory.

class Unbox_Dumper {
	private $wpdb;
	private $dbh;
	private $prefix;
	private $opt;
	/** @var Unbox_Replacer|null */
	private $replacer;

	public function __construct( array $opt, Unbox_Replacer $replacer = null ) {
		global $wpdb;
		$this->wpdb     = $wpdb;
		$this->dbh      = $wpdb->dbh;
		$this->prefix   = $wpdb->base_prefix;
		$this->opt      = $opt;
		$this->replacer = $replacer;
		if ( ! ( $this->dbh instanceof mysqli ) ) {
			throw new Unbox_Exception( __( 'Only mysqli database connections are supported', 'unbox' ) );
		}
	}

	private function q( $sql, $mode = MYSQLI_STORE_RESULT ) {
		$res = mysqli_query( $this->dbh, $sql, $mode );
		if ( $res === false ) {
			throw new Unbox_Exception( sprintf( /* translators: %1$s: error message, %2$s: SQL */ __( 'Failed to read the database: %1$s / %2$s', 'unbox' ), mysqli_error( $this->dbh ), substr( $sql, 0, 200 ) ) );
		}
		return $res;
	}

	public function tables() {
		$res  = $this->q( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
		$list = array();
		while ( $row = mysqli_fetch_row( $res ) ) {
			if ( strpos( $row[0], $this->prefix ) === 0 ) {
				$list[] = $row[0];
			}
		}
		mysqli_free_result( $res );
		sort( $list );
		return $list;
	}

	private function out_name( $table ) {
		if ( $this->opt['format'] !== 'localwp' ) {
			return Unbox_Sql::PREFIX_PLACEHOLDER . substr( $table, strlen( $this->prefix ) );
		}
		return $table;
	}

	private function create_table( $table ) {
		$res = $this->q( 'SHOW CREATE TABLE `' . $table . '`' );
		$row = mysqli_fetch_row( $res );
		mysqli_free_result( $res );
		$sql = $row[1];
		$sql = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `' . $this->out_name( $table ) . '`', $sql );
		// 外部キーは並び順で失敗しやすいので外す（All-in-One WP Migration と同じ扱い）
		$sql = preg_replace( array( '/,\s+CONSTRAINT[^\n]+REFERENCES[^\n]+/i' ), '', $sql );
		return $sql;
	}

	/** 1列の整数主キーなら列名、そうでなければ null。 */
	private function int_primary_key( $table ) {
		$res  = $this->q( 'SHOW KEYS FROM `' . $table . "` WHERE Key_name = 'PRIMARY'" );
		$cols = array();
		while ( $row = mysqli_fetch_assoc( $res ) ) {
			$cols[] = $row['Column_name'];
		}
		mysqli_free_result( $res );
		if ( count( $cols ) !== 1 ) {
			return array( null, $cols );
		}
		$res  = $this->q( 'SHOW COLUMNS FROM `' . $table . '` WHERE Field = \'' . mysqli_real_escape_string( $this->dbh, $cols[0] ) . '\'' );
		$col  = mysqli_fetch_assoc( $res );
		mysqli_free_result( $res );
		if ( $col && preg_match( '/int/i', $col['Type'] ) ) {
			return array( $cols[0], $cols );
		}
		return array( null, $cols );
	}

	private function where( $table ) {
		$name  = substr( $table, strlen( $this->prefix ) );
		$where = array( '1=1' );
		if ( $name === 'options' ) {
			if ( ! empty( $this->opt['exclude_transients'] ) ) {
				$where[] = "`option_name` NOT LIKE '\\_transient\\_%' AND `option_name` NOT LIKE '\\_site\\_transient\\_%'";
			}
			// URL の対応表は移行先で作り直させる（古い表が残ると個別ページが 404 になる）
			$where[] = "`option_name` NOT IN ('unbox_secret', 'rewrite_rules')";
		}
		if ( $name === 'posts' && ! empty( $this->opt['exclude_revisions'] ) ) {
			$where[] = "`post_type` <> 'revision'";
		}
		if ( $name === 'comments' && ! empty( $this->opt['exclude_spam'] ) ) {
			$where[] = "`comment_approved` <> 'spam'";
		}
		return implode( ' AND ', $where );
	}

	/**
	 * 期限まで書き出す。
	 *
	 * @param array  $state [ 'i' => テーブル番号, 'last' => 最後に出した主キー, 'offset' => LIMIT の位置, 'ddl' => CREATE を書いたか ]
	 * @return bool 全テーブル書き終えたら true
	 */
	public function step( array &$state, $file, $deadline ) {
		$state += array( 'i' => 0, 'last' => null, 'offset' => 0, 'ddl' => false, 'tables' => null, 'rows' => 0 );
		if ( $state['tables'] === null ) {
			$state['tables'] = $this->tables();
			file_put_contents(
				$file,
				"-- Unbox SQL dump\n-- " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" .
				"SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n"
			);
		}
		$fh = fopen( $file, 'ab' );
		if ( ! $fh ) {
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Cannot write the SQL file: %s', 'unbox' ), $file ) );
		}
		$this->q( "SET SESSION sql_mode = ''" );
		@mysqli_set_charset( $this->dbh, 'utf8mb4' );

		while ( $state['i'] < count( $state['tables'] ) ) {
			$table = $state['tables'][ $state['i'] ];
			$out   = $this->out_name( $table );
			if ( ! $state['ddl'] ) {
				fwrite( $fh, "DROP TABLE IF EXISTS `$out`;\n" . $this->create_table( $table ) . ";\n\n" );
				$state['ddl'] = true;
			}
			list( $pk, $pk_cols ) = $this->int_primary_key( $table );
			$where = $this->where( $table );
			if ( $pk ) {
				$cond = $state['last'] === null ? '' : ' AND `' . $pk . '` > ' . (int) $state['last'];
				$sql  = "SELECT * FROM `$table` WHERE $where$cond ORDER BY `$pk` LIMIT 1000";
			} else {
				$order = $pk_cols ? implode( ', ', array_map( function ( $c ) { return '`' . $c . '`'; }, $pk_cols ) ) : '1';
				$sql   = "SELECT * FROM `$table` WHERE $where ORDER BY $order LIMIT " . (int) $state['offset'] . ', 1000';
			}
			$res    = $this->q( $sql, MYSQLI_USE_RESULT );
			$fields = mysqli_fetch_fields( $res );
			$binary = array();
			$pk_idx = null;
			foreach ( $fields as $idx => $f ) {
				$binary[ $idx ] = ( $f->charsetnr === 63 && in_array( $f->type, array( MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING ), true ) );
				if ( $pk !== null && $f->name === $pk ) {
					$pk_idx = $idx;
				}
			}
			$prefix_col = $this->prefix_column( $table, $fields );
			$count      = 0;
			$buf        = '';
			while ( $row = mysqli_fetch_row( $res ) ) {
				$vals = array();
				foreach ( $row as $idx => $v ) {
					if ( $v === null ) {
						$vals[] = 'NULL';
					} elseif ( $binary[ $idx ] ) {
						$vals[] = $v === '' ? "''" : '0x' . bin2hex( $v );
					} else {
						if ( $idx === $prefix_col && $this->opt['format'] !== 'localwp' && strpos( $v, $this->prefix ) === 0 ) {
							$v = Unbox_Sql::PREFIX_PLACEHOLDER . substr( $v, strlen( $this->prefix ) );
						} elseif ( $this->replacer ) {
							$v = $this->replacer->replace( $v );
						}
						$vals[] = "'" . mysqli_real_escape_string( $this->dbh, $v ) . "'";
					}
				}
				$tuple = '(' . implode( ',', $vals ) . ')';
				if ( $buf === '' ) {
					$buf = "INSERT INTO `$out` VALUES " . $tuple;
				} else {
					$buf .= ',' . $tuple;
				}
				if ( strlen( $buf ) > 1048576 ) {
					fwrite( $fh, $buf . ";\n" );
					$buf = '';
				}
				if ( $pk_idx !== null ) {
					$state['last'] = $row[ $pk_idx ];
				}
				$count++;
			}
			mysqli_free_result( $res );
			if ( $buf !== '' ) {
				fwrite( $fh, $buf . ";\n" );
			}
			$state['rows']   += $count;
			$state['offset'] += $count;
			if ( $count < 1000 ) {
				fwrite( $fh, "\n" );
				$state['i']++;
				$state['last']   = null;
				$state['offset'] = 0;
				$state['ddl']    = false;
			}
			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
		fclose( $fh );
		return $state['i'] >= count( $state['tables'] );
	}

	/** 値に接頭辞が入る列（options.option_name / usermeta.meta_key）の位置。 */
	private function prefix_column( $table, array $fields ) {
		$name = substr( $table, strlen( $this->prefix ) );
		$col  = $name === 'options' ? 'option_name' : ( $name === 'usermeta' ? 'meta_key' : null );
		if ( ! $col ) {
			return null;
		}
		foreach ( $fields as $idx => $f ) {
			if ( $f->name === $col ) {
				return $idx;
			}
		}
		return null;
	}
}
