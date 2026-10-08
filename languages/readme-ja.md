# Unbox by oobe readme 日本語訳（translate.wordpress.org の Stable Readme 用）

翻訳サイトでは readme が段落・見出し・箇条書きごとに分かれて出る。太字やコードは翻訳サイト上では <strong> / <code> のタグとして出るので、タグは原文どおり残す。

## プラグイン名・短い説明

- Unbox by oobe
  → Unbox by oobe
- Move a whole WordPress site to another server or into LocalWP. No size limit, no account, nothing sent anywhere.
  → WordPress サイトを丸ごと別のサーバーや LocalWP へ移行します。容量の上限なし、アカウント登録なし、外部への送信もありません。

## 説明

- Description → 説明
- Unbox packs your whole site – files and database – into a single file, and unpacks it on another WordPress so it works right out of the box.
  → Unbox は、サイト全体 (ファイルとデータベース) を1つのファイルにまとめ、別の WordPress で展開して、そのまま動く状態にします。
- Features → 機能
- **No size limit.** Large sites are exported and imported in small steps, and uploads are sent in chunks that fit your server's limits. If the server rejects a chunk, Unbox automatically retries with a smaller one.
  → **容量の上限なし。** 大きなサイトも少しずつエクスポート・インポートし、アップロードはサーバーの上限に合わせて分割して送ります。サーバーに受け付けられなかった場合は、自動で小さく分け直して再送します。
- **Export for LocalWP.** Create a zip that LocalWP's "Import site" accepts as-is. URLs are already rewritten to `http://your-site.local`, and WordPress core can be included so the local copy runs the same version as the original.
  → **LocalWP 用のエクスポート。** LocalWP の「Import site」にそのまま渡せる zip を作成します。URL は `http://your-site.local` に置き換え済みで、WordPress 本体も含められるため、ローカルでも移行元と同じバージョンで動作します。
- **Safe database swap.** The database is imported into temporary tables first and swapped in at the very end with a single `RENAME TABLE`. If anything fails along the way, the current site's database is left untouched.
  → **安全なデータベースの入れ替え。** データベースはまず一時テーブルにインポートし、最後に1回の `RENAME TABLE` で入れ替えます。途中で失敗しても、現在のサイトのデータベースは変更されません。
- **Works behind Basic authentication, WAFs and reverse proxies.** Your browser drives each step; the server never sends requests to itself.
  → **Basic 認証・WAF・リバースプロキシの内側でも動作。** 各処理はブラウザーから進めるため、サーバーが自分自身にリクエストを送ることはありません。
- **Serialized data aware.** URLs inside serialized PHP values, JSON and URL-encoded strings are replaced and their lengths recalculated. Values are never passed to `unserialize()`.
  → **シリアライズされたデータに対応。** シリアライズされた PHP の値、JSON、URL エンコードされた文字列の中の URL も置き換え、文字数を計算し直します。値を `unserialize()` に渡すことはありません。
- **Folders outside wp-content.** Optionally include folders that sit next to WordPress (for example static HTML or asset folders) in `.unbox` and LocalWP exports.
  → **wp-content の外のフォルダー。** WordPress と同じ階層にあるフォルダー (静的な HTML やアセットのフォルダーなど) を、`.unbox` と LocalWP 用のエクスポートに含めることもできます。
- **.wpress compatible.** Import `.wpress` files made by All-in-One WP Migration, or export in that format when the destination uses it.
  → **.wpress 互換。** All-in-One WP Migration で作成した `.wpress` ファイルをインポートできます。移行先で使う場合は、その形式でエクスポートすることもできます。
- **Private by design.** Exports are stored in a folder with an unguessable name and can only be downloaded by an administrator through the admin screen. Unbox makes no external requests and collects no data.
  → **プライバシーに配慮した設計。** エクスポートしたファイルは推測できない名前のフォルダーに保存され、管理者が管理画面からのみダウンロードできます。Unbox は外部へのリクエストを行わず、データも収集しません。
- How it works → 使い方
- On the source site, go to **Tools → Unbox**, choose a format and click **Export**.
  → 移行元のサイトで **ツール → Unbox** を開き、形式を選んで **エクスポート** をクリックします。
- Download the file.
  → ファイルをダウンロードします。
- On the destination site, install Unbox, go to **Tools → Unbox → Import** and drop the file. Check the destination URL and start the import.
  → 移行先のサイトに Unbox をインストールし、**ツール → Unbox → インポート** を開いてファイルをドロップします。移行先の URL を確認して、インポートを開始します。
- Log in again with a user from the source site.
  → 移行元サイトのユーザーでログインし直します。
- For LocalWP, choose **LocalWP zip**, download it, and drop it into LocalWP ("+" → "Import an existing site").
  → LocalWP の場合は **LocalWP 用 zip** を選んでダウンロードし、LocalWP にドロップします (「+」→「Import an existing site」)。
- Limitations → 制限事項
- Multisite is not supported yet.
  → マルチサイトにはまだ対応していません。
- Files outside wp-content are copied as they are; URLs written inside those files are not rewritten.
  → wp-content の外のファイルはそのままコピーします。ファイル内に書かれた URL は書き換えません。
- Empty folders are not included in archives.
  → 空のフォルダーはアーカイブに含まれません。

## インストール

- Installation → インストール
- Install Unbox from **Plugins → Add New**, or upload the zip from **Plugins → Add New → Upload Plugin**.
  → **プラグイン → 新規プラグインを追加** から Unbox をインストールするか、**プラグイン → 新規プラグインを追加 → プラグインのアップロード** から zip をアップロードします。
- Activate it.
  → 有効化します。
- Open **Tools → Unbox**.
  → **ツール → Unbox** を開きます。

## よくある質問

- Frequently Asked Questions → よくある質問
- Is there really no size limit?
  → 本当に容量の上限はありませんか ?
- Unbox has no limit of its own. Exports are written in steps of up to 20 seconds, and uploads are split into chunks. You still need enough free disk space on the server for the export file.
  → Unbox 自体に上限はありません。エクスポートは最長20秒ずつ進め、アップロードは分割して送ります。ただし、エクスポートしたファイルを保存できるだけの空き容量がサーバーに必要です。
- My upload stops with an error.
  → アップロードがエラーで止まります。
- Some servers or security plugins reject large requests. Unbox lowers the chunk size automatically when the server answers "too large". For very large files you can also upload the file by FTP to the folder shown on the Import tab and import it from the **Files** tab.
  → サーバーやセキュリティプラグインによっては、大きなリクエストを受け付けないことがあります。サーバーから「too large」と返された場合、Unbox は分割の大きさを自動で小さくします。非常に大きなファイルは、「インポート」タブに表示されるフォルダーに FTP でアップロードし、**ファイル** タブからインポートすることもできます。
- Where are the export files stored?
  → エクスポートしたファイルはどこに保存されますか ?
- In `wp-content/unbox-<random>/archives/`. The folder name is derived from your site's secret keys, and the folder is protected against direct access. Delete files from the **Files** tab when you no longer need them.
  → `wp-content/unbox-<random>/archives/` に保存されます。フォルダー名はサイトの秘密鍵から作られ、直接アクセスできないよう保護されています。不要になったファイルは **ファイル** タブから削除してください。
- Does Unbox send any data to external servers?
  → Unbox は外部のサーバーにデータを送信しますか ?
- No. Unbox makes no external requests.
  → いいえ。Unbox は外部へのリクエストを行いません。
- How do I report a security issue?
  → セキュリティの問題はどこに報告すればよいですか ?
- Please email morooka@oobe-io.com instead of posting in the support forum. We will respond as quickly as we can.
  → サポートフォーラムには投稿せず、morooka@oobe-io.com へメールでお知らせください。できるだけ早く対応します。
- Can I import files made by All-in-One WP Migration?
  → All-in-One WP Migration で作成したファイルをインポートできますか ?
- Yes, unencrypted and uncompressed `.wpress` files can be imported.
  → はい。暗号化・圧縮されていない `.wpress` ファイルをインポートできます。

## スクリーンショット

- Screenshots → スクリーンショット
- Export: choose .unbox, .wpress or a LocalWP zip, folders outside wp-content, and what to leave out.
  → エクスポート: .unbox・.wpress・LocalWP 用 zip の形式、wp-content の外のフォルダー、除外するものを選びます。
- Export finished, ready to download.
  → エクスポートが完了し、ダウンロードできる状態です。
- Files: download, import or delete exports. Backups from All-in-One WP Migration are listed too.
  → ファイル: エクスポートしたファイルをダウンロード・インポート・削除できます。All-in-One WP Migration のバックアップも一覧に表示されます。
- Import: check the source site and the destination URL before anything is overwritten.
  → インポート: 上書きする前に、移行元サイトと移行先の URL を確認します。

## 変更履歴

- Changelog → 変更履歴
- No longer writes a file to mu-plugins during a migration. Imported files are written under a temporary name and renamed when complete, so a half-written file is never loaded.
  → 移行中に mu-plugins へファイルを書き込まないようにしました。インポートするファイルは一時的な名前で書き込み、完了してから名前を変えるため、書きかけのファイルが読み込まれることはありません。
- Folder locations are resolved in one place.
  → フォルダーの場所を1か所で決めるようにしました。
- Renamed to "Unbox by oobe".
  → 名前を「Unbox by oobe」に変更しました。
- Database access now goes through wpdb. Requires WordPress 6.2 or later.
  → データベースへのアクセスを wpdb 経由にしました。WordPress 6.2 以降が必要です。
- Translations are delivered through translate.wordpress.org.
  → 翻訳は translate.wordpress.org から配信されるようになりました。
- The interface is now translatable.
  → 画面を翻訳できるようにしました。
- Folders and files outside wp-content can be included in .unbox and LocalWP exports.
  → .unbox と LocalWP 用のエクスポートに、wp-content の外のフォルダーとファイルを含められるようにしました。
- Fixed large uploads sending the rest of the file at once after the first chunk.
  → 大きなファイルのアップロードで、2回目以降に残りをまとめて送ってしまう問題を修正しました。
- Uploads automatically retry with smaller chunks when the server rejects them.
  → サーバーに受け付けられなかった場合、アップロードを自動で小さく分け直して再送するようにしました。
- The plugin no longer loads on the front end.
  → サイトの表側ではプラグインを読み込まないようにしました。
- First release.
  → 初回リリース。
