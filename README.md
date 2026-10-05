# Unbox by oobe

Move a whole WordPress site to another server or into LocalWP. No size limit, no account, nothing sent anywhere.

WordPress サイトを丸ごと書き出して、別のサーバーや LocalWP に取り込むプラグインです。容量の上限はありません。外部への送信もしません。

## Features / できること

- **No size limit / 容量の上限なし**：書き出し・取り込みは少しずつ進み、アップロードもサーバーの上限に合わせて分割します。サーバーに断られたら、分割を小さくして自動で送り直します。
- **LocalWP zip / LocalWP 用 zip**：LocalWP の「Import site」にそのまま渡せる zip を書き出せます。URL は `http://○○.local` に置き換え済みで、WordPress 本体も同梱できます。
- **Safe database swap / DB は最後に一度で入れ替え**：DB は一時テーブルに入れてから、最後に `RENAME TABLE` で入れ替えます。途中で止まっても、今のサイトの DB はそのままです。
- **Works behind Basic auth, WAFs and reverse proxies / 認証やプロキシの内側でも動く**：各段階はブラウザが呼び出し、サーバーが自分自身へリクエストを投げることはありません。
- **Serialized data aware / シリアライズ対応**：シリアライズされた値・JSON・URL エンコードの中の URL も置き換え、文字数を数え直します。`unserialize()` は使いません。
- **Folders outside wp-content / wp-content の外のフォルダー**：WordPress のフォルダー直下にある静的ページなどのフォルダーも、選んで一緒に運べます（.unbox と LocalWP 用 zip）。
- **.wpress compatible / .wpress 互換**：All-in-One WP Migration の `.wpress` を取り込めます。`.wpress` で書き出すこともできます。

Requirements / 動作環境: WordPress 6.2+, PHP 7.4+, single site（マルチサイトは未対応）

## Usage / 使い方

1. 「ツール → Unbox」で形式を選んで「書き出す」→ ダウンロード
2. 移行先にも Unbox を入れて「取り込み」タブにファイルをドロップ → 移行先の URL を確認して取り込む
3. 終わったら、元のサイトのユーザーでログインし直す

LocalWP なら「LocalWP 用 zip」で書き出し、LocalWP の「+」→「Import an existing site」で選びます。

## Command-line tool / コマンド

`cli/unbox-cli.php` は WordPress なしで動きます（PHP 7.4+）。WordPress.org の配布物には含まれません。

```
php cli/unbox-cli.php info    site.unbox
php cli/unbox-cli.php extract site.unbox output-folder
php cli/unbox-cli.php localwp site.unbox site-name [output.zip]
```

`localwp` converts a `.unbox` / `.wpress` file into a LocalWP zip. / `.unbox`・`.wpress` を LocalWP 用 zip に変換します。

## How it works / 仕組み

- `.unbox` uses the same container format as `.wpress`: a 4377-byte header (name 255 / size 14 / mtime 12 / path 4096) before each file, ending with a 4377-byte NUL block. The database is `database.sql`, with the table prefix replaced by `SERVMASK_PREFIX_`. Files outside wp-content are stored under `__root__/`.
- Each request runs for up to 20 seconds (or half of `max_execution_time`) and can stop and resume in the middle of a file or SQL dump. Zip CRCs are carried across requests with `crc32_combine`.
- Imported files are written under a temporary name (`.unbox-part`) and renamed when complete, so a half-written file is never loaded by the next request.

## Hooks / フック

- `unbox_loaded` (action)
- `unbox_export_options`, `unbox_export_excludes`, `unbox_localwp_replace_pairs`
- `unbox_import_replace_pairs`, `unbox_import_skip`
- `unbox_step_seconds`, `unbox_upload_chunk_size`

## Development / 開発

- `php dev/test-core.php` – tests for the parts that do not need WordPress
- `sh dev/build.sh` – builds the distributable zip into `dist/` (without `cli/`, `dev/` and store assets)
- `.wordpress-org/` – icons, banners and screenshots for the WordPress.org plugin page

## Security / セキュリティ

See [SECURITY.md](SECURITY.md).

## License

GPL-3.0-or-later © oobe
