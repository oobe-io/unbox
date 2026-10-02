<?php
/**
 * WordPress に依存しない部品のテスト。
 *   php dev/test-core.php [作業フォルダ]
 */
// WordPress の外で動かすので、翻訳関数は素通しにする
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
// 中のファイルは ABSPATH が無いと止まる作りなので、ここで決めておく
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
require __DIR__ . '/../includes/class-unbox-wpress.php';
require __DIR__ . '/../includes/class-unbox-zip.php';
require __DIR__ . '/../includes/class-unbox-replacer.php';
require __DIR__ . '/../includes/class-unbox-sql.php';

$tmp   = isset( $argv[1] ) ? $argv[1] : sys_get_temp_dir() . '/unbox-test-' . getmypid();
@mkdir( $tmp, 0755, true );
$fails = 0;
function ok( $cond, $label ) {
	global $fails;
	echo ( $cond ? "  ok   " : "  FAIL " ) . $label . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

echo "Replacer\n";
$r    = new Unbox_Replacer( Unbox_Replacer::url_pairs( 'https://www.example.com/article', 'http://example.local' ) );
$data = array(
	'url'    => 'https://www.example.com/article/wp-content/uploads/a.jpg',
	'plain'  => 'http://example.com/article/x',
	'nested' => serialize( array( 'deep' => 'https://www.example.com/article/' ) ),
	'json'   => json_encode( array( 'u' => 'https://www.example.com/article/y' ) ),
	'other'  => 'https://www.example.com.au/',
	'obj'    => new ArrayObject( array( 1 ) ),
	'jp'     => '日本語 https://www.example.com/article',
);
$s    = serialize( $data );
$out  = $r->replace( $s );
$u    = @unserialize( $out );
ok( is_array( $u ), 'シリアライズのまま読める' );
ok( $u['url'] === 'http://example.local/wp-content/uploads/a.jpg', 'URL（https+www → local）' );
ok( $u['plain'] === 'http://example.local/x', 'URL（http・www なし）' );
ok( unserialize( $u['nested'] )['deep'] === 'http://example.local/', '二重シリアライズの中' );
ok( json_decode( $u['json'], true )['u'] === 'http://example.local/y', 'JSON の \\/ 表記' );
ok( $u['jp'] === '日本語 http://example.local', 'マルチバイト込みの文字数' );
ok( $r->replace( 'no url here' ) === 'no url here', '対象なしは素通し' );
$broken = 'a:1:{s:3:"url";s:99:"https://www.example.com/article";}';
ok( $r->replace( $broken ) === 'a:1:{s:3:"url";s:99:"http://example.local";}', '壊れたシリアライズは普通に置換' );

$pp = new Unbox_Replacer( Unbox_Replacer::package_pairs( array( 'HomeURL' => 'https://a.example.com', 'SiteURL' => 'https://a.example.com/wp', 'InternalHomeURL' => 'http://old.example.com', 'InternalSiteURL' => 'http://old.example.com/wp' ), 'http://x.local' ) );
ok( $pp->replace( 'https://a.example.com/p/ https://a.example.com/wp/wp-admin/ http://old.example.com/q/ http://old.example.com/wp/x' ) === 'http://x.local/p/ http://x.local/wp/wp-admin/ http://x.local/q/ http://x.local/wp/x', 'package.json の Internal URL・サブフォルダー' );

echo "SQL\n";
$vals = array( "a'b", "x\\y", "line\nbreak", "nul\0z", '日本語', 'q"q', "100%_\\%" );
foreach ( $vals as $v ) {
	ok( Unbox_Sql::unescape( Unbox_Sql::escape( $v ) ) === $v, 'エスケープ往復: ' . json_encode( $v ) );
}
$stmt = "INSERT INTO `SERVMASK_PREFIX_options` VALUES (1,'SERVMASK_PREFIX_user_roles','a:1:{s:3:\\\"url\\\";s:31:\\\"https://www.example.com/article\\\";}','yes')";
$p    = Unbox_Sql::replace_prefix( $stmt, 'omtmp_', 'wp_' );
ok( strpos( $p, '`omtmp_options`' ) !== false && strpos( $p, "'wp_user_roles'" ) !== false, '接頭辞（テーブル名と値で別）' );
$p2 = Unbox_Sql::replace_values( $p, $r );
ok( strpos( $p2, 's:20:\\"http://example.local\\"' ) !== false, 'SQL 内のシリアライズ置換: ' . $p2 );
ok( Unbox_Sql::is_complete( "INSERT INTO t VALUES ('a;')" ) && ! Unbox_Sql::is_complete( "INSERT INTO t VALUES ('a;" ), '引用符の閉じ判定' );
$ct = "CREATE TABLE `x` (\n `a` text COLLATE utf8mb4_0900_ai_ci\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
$fx = Unbox_Sql::fix_collations( $ct, array( 'utf8mb4_unicode_520_ci' => 1, 'utf8mb4_unicode_ci' => 1 ) );
ok( strpos( $fx, '0900' ) === false && substr_count( $fx, 'utf8mb4_unicode_520_ci' ) === 2, '照合順序の置き換え' );

$sqlf = "$tmp/t.sql";
file_put_contents( $sqlf, "-- comment\n\nCREATE TABLE `a` (\n `x` int\n);\nINSERT INTO `a` VALUES ('semi;\\nx'),('b');\nINSERT INTO `a` VALUES ('end');\n" );
$rd = new Unbox_Sql_Reader( $sqlf );
$st = array();
while ( ( $s1 = $rd->next() ) !== null ) {
	$st[] = $s1;
}
ok( count( $st ) === 3 && $st[1] === "INSERT INTO `a` VALUES ('semi;\\nx'),('b')", '1文ずつ読む' );

echo "CRC\n";
$a = random_bytes( 1000003 );
$b = random_bytes( 777 );
ok( Unbox_Zip_Writer::crc32_combine( crc32( $a ), crc32( $b ), strlen( $b ) ) === crc32( $a . $b ), 'crc32_combine' );
ok( Unbox_Zip_Writer::crc32_combine( 0, crc32( $b ), strlen( $b ) ) === crc32( $b ), 'crc32_combine（先頭）' );

echo "wpress\n";
@mkdir( "$tmp/src/sub", 0755, true );
file_put_contents( "$tmp/src/a.txt", 'hello' );
file_put_contents( "$tmp/src/sub/big.bin", random_bytes( 3 * 1048576 + 5 ) );
file_put_contents( "$tmp/src/sub/empty.txt", '' );
@unlink( "$tmp/t.wpress" );
$w = new Unbox_Wpress_Writer( "$tmp/t.wpress" );
$w->add_string( 'package.json', '{"SiteURL":"x"}' );
foreach ( array( 'a.txt', 'sub/big.bin', 'sub/empty.txt' ) as $f ) {
	$state = array();
	// 1MB ごとに中断させて再開を試す
	while ( ! $w->add_file( "$tmp/src/$f", $f, $state, microtime( true ) - 1 ) ) {
		$size = $w->tell();
		$w->close();
		$w = new Unbox_Wpress_Writer( "$tmp/t.wpress", $size );
	}
}
$w->finish();
$rd  = new Unbox_Wpress_Reader( "$tmp/t.wpress" );
ok( $rd->is_valid(), '終端ブロック' );
$off = 0;
$names = array();
while ( ( $e = $rd->header_at( $off ) ) !== null ) {
	$names[] = $e['name'];
	if ( $e['name'] === 'sub/big.bin' ) {
		$written = 0;
		while ( ! $rd->extract_to( $e, "$tmp/out/sub/big.bin", $written, microtime( true ) - 1 ) ) {
		}
		ok( md5_file( "$tmp/out/sub/big.bin" ) === md5_file( "$tmp/src/sub/big.bin" ), '分割して展開しても一致' );
	}
	$off = $e['next'];
}
ok( $names === array( 'package.json', 'a.txt', 'sub/big.bin', 'sub/empty.txt' ), 'エントリー一覧: ' . implode( ',', $names ) );
try {
	Unbox_Wpress::safe_relpath( '../etc/passwd' );
	ok( false, '../ を拒否' );
} catch ( Unbox_Exception $e ) {
	ok( true, '../ を拒否' );
}

echo "zip\n";
@unlink( "$tmp/t.zip" );
@unlink( "$tmp/t.cd" );
$z = new Unbox_Zip_Writer( "$tmp/t.zip", "$tmp/t.cd" );
$z->add_string( 'database.sql', "SELECT 1;\n" );
foreach ( array( 'a.txt', 'sub/big.bin', 'sub/empty.txt' ) as $f ) {
	$state = array();
	while ( ! $z->add_file( "$tmp/src/$f", "files/wp-content/$f", $state, microtime( true ) - 1 ) ) {
		$size = $z->tell();
		$z->close();
		$z = new Unbox_Zip_Writer( "$tmp/t.zip", "$tmp/t.cd", $size );
	}
}
$z->finish();
$za = new ZipArchive();
ok( $za->open( "$tmp/t.zip", ZipArchive::CHECKCONS ) === true && $za->numFiles === 4, 'ZipArchive で開ける（整合性チェック付き）' );
ok( $za->getFromName( 'files/wp-content/sub/big.bin' ) === file_get_contents( "$tmp/src/sub/big.bin" ), '中身が一致' );
$za->close();
exec( 'unzip -tq ' . escapeshellarg( "$tmp/t.zip" ) . ' 2>&1', $o, $rc );
ok( $rc === 0, 'unzip -t: ' . implode( ' ', $o ) );

echo $fails ? "\n$fails 件失敗\n" : "\nすべて成功\n";
exit( $fails ? 1 : 0 );
