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
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- A migration has to read every table directly; caching would only waste memory.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names are passed to prepare() with %i; the WHERE fragments are built from prepared pieces.

class Unbox_Dumper {
	private $prefix;
	private $opt;
	/** @var Unbox_Replacer|null */
	private $replacer;

	public function __construct( array $opt, Unbox_Replacer $replacer = null ) {
		global $wpdb;
		$this->prefix   = $wpdb->base_prefix;
		$this->opt      = $opt;
		$this->replacer = $replacer;
		// エラーは例外にして JSON で返す。画面に HTML で出すと応答が壊れる
		$wpdb->suppress_errors( true );
		$wpdb->hide_errors();
	}

	/** 読み出しに失敗したら止める。 */
	private function check() {
		global $wpdb;
		if ( $wpdb->last_error !== '' ) {
			throw new Unbox_Exception( sprintf( /* translators: %1$s: error message, %2$s: SQL */ __( 'Failed to read the database: %1$s / %2$s', 'unbox-by-oobe' ), $wpdb->last_error, substr( (string) $wpdb->last_query, 0, 200 ) ) );
		}
	}

	public function tables() {
		global $wpdb;
		$rows = $wpdb->get_col( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
		$this->check();
		$list = array();
		foreach ( $rows as $name ) {
			if ( strpos( $name, $this->prefix ) === 0 ) {
				$list[] = $name;
			}
		}
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
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
		$this->check();
		$sql = $row[1];
		$sql = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `' . $this->out_name( $table ) . '`', $sql );
		// 外部キーは並び順で失敗しやすいので外す（All-in-One WP Migration と同じ扱い）
		$sql = preg_replace( array( '/,\s+CONSTRAINT[^\n]+REFERENCES[^\n]+/i' ), '', $sql );
		return $sql;
	}

	/** 1列の整数主キーなら列名、そうでなければ null。 */
	private function int_primary_key( $table ) {
		global $wpdb;
		$keys = $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A );
		$this->check();
		$cols = array();
		foreach ( $keys as $key ) {
			$cols[] = $key['Column_name'];
		}
		if ( count( $cols ) !== 1 ) {
			return array( null, $cols );
		}
		$col = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $table, $cols[0] ), ARRAY_A );
		$this->check();
		if ( $col && preg_match( '/int/i', $col['Type'] ) ) {
			return array( $cols[0], $cols );
		}
		return array( null, $cols );
	}

	/** 書き出さない行の条件。prepare() に渡す [ 条件, 値 ] を返す。 */
	private function where( $table ) {
		global $wpdb;
		$name  = substr( $table, strlen( $this->prefix ) );
		$where = array( '1=1' );
		$args  = array();
		if ( $name === 'options' ) {
			if ( ! empty( $this->opt['exclude_transients'] ) ) {
				$where[] = '`option_name` NOT LIKE %s AND `option_name` NOT LIKE %s';
				$args[]  = $wpdb->esc_like( '_transient_' ) . '%';
				$args[]  = $wpdb->esc_like( '_site_transient_' ) . '%';
			}
			// URL の対応表は移行先で作り直させる（古い表が残ると個別ページが 404 になる）
			$where[] = '`option_name` NOT IN (%s, %s)';
			$args[]  = 'unbox_secret';
			$args[]  = 'rewrite_rules';
		}
		if ( $name === 'posts' && ! empty( $this->opt['exclude_revisions'] ) ) {
			$where[] = '`post_type` <> %s';
			$args[]  = 'revision';
		}
		if ( $name === 'comments' && ! empty( $this->opt['exclude_spam'] ) ) {
			$where[] = '`comment_approved` <> %s';
			$args[]  = 'spam';
		}
		return array( implode( ' AND ', $where ), $args );
	}

	/** 次の 1000 行を読む SQL。 */
	private function select( $table, $pk, array $pk_cols, array $state ) {
		global $wpdb;
		list( $where, $args ) = $this->where( $table );
		if ( $pk ) {
			if ( $state['last'] !== null ) {
				$where .= ' AND %i > %d';
				$args[] = $pk;
				$args[] = (int) $state['last'];
			}
			$args[] = $pk;
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The number of placeholders in $where varies; $args always matches it.
			return $wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY %i LIMIT 1000", array_merge( array( $table ), $args ) );
		}
		if ( $pk_cols ) {
			$order = implode( ', ', array_fill( 0, count( $pk_cols ), '%i' ) );
			$args  = array_merge( $args, $pk_cols );
		} else {
			$order = '1';
		}
		$args[] = (int) $state['offset'];
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The number of placeholders in $where and $order varies; $args always matches them.
		return $wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY $order LIMIT %d, 1000", array_merge( array( $table ), $args ) );
	}

	/**
	 * 期限まで書き出す。
	 *
	 * @param array  $state [ 'i' => テーブル番号, 'last' => 最後に出した主キー, 'offset' => LIMIT の位置, 'ddl' => CREATE を書いたか ]
	 * @return bool 全テーブル書き終えたら true
	 */
	public function step( array &$state, $file, $deadline ) {
		global $wpdb;
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
			throw new Unbox_Exception( sprintf( /* translators: %s: file path */ __( 'Cannot write the SQL file: %s', 'unbox-by-oobe' ), $file ) );
		}
		$wpdb->query( "SET SESSION sql_mode = ''" );
		$wpdb->set_charset( $wpdb->dbh, 'utf8mb4' );

		while ( $state['i'] < count( $state['tables'] ) ) {
			$table = $state['tables'][ $state['i'] ];
			$out   = $this->out_name( $table );
			if ( ! $state['ddl'] ) {
				fwrite( $fh, "DROP TABLE IF EXISTS `$out`;\n" . $this->create_table( $table ) . ";\n\n" );
				$state['ddl'] = true;
			}
			list( $pk, $pk_cols ) = $this->int_primary_key( $table );
			$sql  = $this->select( $table, $pk, $pk_cols, $state );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql comes from select(), which builds it with $wpdb->prepare().
			$rows = $wpdb->get_results( $sql, ARRAY_N );
			$this->check();
			$fields = $this->fields();
			$binary = array();
			$pk_idx = null;
			foreach ( $fields as $idx => $f ) {
				$binary[ $idx ] = ( (int) $f['charsetnr'] === 63 && in_array( (int) $f['type'], array( MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING ), true ) );
				if ( $pk !== null && $f['name'] === $pk ) {
					$pk_idx = $idx;
				}
			}
			$prefix_col = $this->prefix_column( $table, $fields );
			$count      = 0;
			$buf        = '';
			foreach ( $rows as $row ) {
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
						$vals[] = "'" . Unbox_Sql::escape( $v ) . "'";
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
			$rows = null;
			$wpdb->flush();
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

	/** 直前に読んだ結果の列（名前・型・文字コード）。 */
	private function fields() {
		global $wpdb;
		$names    = (array) $wpdb->get_col_info( 'name', -1 );
		$types    = (array) $wpdb->get_col_info( 'type', -1 );
		$charsets = (array) $wpdb->get_col_info( 'charsetnr', -1 );
		$fields   = array();
		foreach ( $names as $idx => $name ) {
			$fields[ $idx ] = array(
				'name'      => $name,
				'type'      => isset( $types[ $idx ] ) ? $types[ $idx ] : 0,
				'charsetnr' => isset( $charsets[ $idx ] ) ? $charsets[ $idx ] : 0,
			);
		}
		return $fields;
	}

	/** 値に接頭辞が入る列（options.option_name / usermeta.meta_key）の位置。 */
	private function prefix_column( $table, array $fields ) {
		$name = substr( $table, strlen( $this->prefix ) );
		$col  = $name === 'options' ? 'option_name' : ( $name === 'usermeta' ? 'meta_key' : null );
		if ( ! $col ) {
			return null;
		}
		foreach ( $fields as $idx => $f ) {
			if ( $f['name'] === $col ) {
				return $idx;
			}
		}
		return null;
	}
}
