=== Unbox ===
Contributors: oobeio
Tags: migration, backup, export, import, localwp
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Move a whole WordPress site to another server or into LocalWP. No size limit, no account, nothing sent anywhere.

== Description ==

Unbox packs your whole site – files and database – into a single file, and unpacks it on another WordPress so it works right out of the box.

= Features =

* **No size limit.** Large sites are exported and imported in small steps, and uploads are sent in chunks that fit your server's limits. If the server rejects a chunk, Unbox automatically retries with a smaller one.
* **Export for LocalWP.** Create a zip that LocalWP's "Import site" accepts as-is. URLs are already rewritten to `http://your-site.local`, and WordPress core can be included so the local copy runs the same version as the original.
* **Safe database swap.** The database is imported into temporary tables first and swapped in at the very end with a single `RENAME TABLE`. If anything fails along the way, the current site's database is left untouched.
* **Works behind Basic authentication, WAFs and reverse proxies.** Your browser drives each step; the server never sends requests to itself.
* **Serialized data aware.** URLs inside serialized PHP values, JSON and URL-encoded strings are replaced and their lengths recalculated. Values are never passed to `unserialize()`.
* **Folders outside wp-content.** Optionally include folders that sit next to WordPress (for example static HTML or asset folders) in `.unbox` and LocalWP exports.
* **.wpress compatible.** Import `.wpress` files made by All-in-One WP Migration, or export in that format when the destination uses it.
* **Private by design.** Exports are stored in a folder with an unguessable name and can only be downloaded by an administrator through the admin screen. Unbox makes no external requests and collects no data.

= How it works =

1. On the source site, go to **Tools → Unbox**, choose a format and click **Export**.
2. Download the file.
3. On the destination site, install Unbox, go to **Tools → Unbox → Import** and drop the file. Check the destination URL and start the import.
4. Log in again with a user from the source site.

For LocalWP, choose **LocalWP zip**, download it, and drop it into LocalWP ("+" → "Import an existing site").

= Limitations =

* Multisite is not supported yet.
* Files outside wp-content are copied as they are; URLs written inside those files are not rewritten.
* Empty folders are not included in archives.

== Installation ==

1. Install Unbox from **Plugins → Add New**, or upload the zip from **Plugins → Add New → Upload Plugin**.
2. Activate it.
3. Open **Tools → Unbox**.

== Frequently Asked Questions ==

= Is there really no size limit? =

Unbox has no limit of its own. Exports are written in steps of up to 20 seconds, and uploads are split into chunks. You still need enough free disk space on the server for the export file.

= My upload stops with an error. =

Some servers or security plugins reject large requests. Unbox lowers the chunk size automatically when the server answers "too large". For very large files you can also upload the file by FTP to the folder shown on the Import tab and import it from the **Files** tab.

= Where are the export files stored? =

In `wp-content/unbox-<random>/archives/`. The folder name is derived from your site's secret keys, and the folder is protected against direct access. Delete files from the **Files** tab when you no longer need them.

= Does Unbox send any data to external servers? =

No. Unbox makes no external requests.

= How do I report a security issue? =

Please email morooka@oobe-io.com instead of posting in the support forum. We will respond as quickly as we can.

= Can I import files made by All-in-One WP Migration? =

Yes, unencrypted and uncompressed `.wpress` files can be imported.

== Screenshots ==

1. Export: choose .unbox, .wpress or a LocalWP zip, folders outside wp-content, and what to leave out.
2. Export finished, ready to download.
3. Files: download, import or delete exports. Backups from All-in-One WP Migration are listed too.
4. Import: check the source site and the destination URL before anything is overwritten.

== Changelog ==

= 0.3.0 =
* The interface is now translatable. Japanese translation included.

= 0.2.0 =
* Folders and files outside wp-content can be included in .unbox and LocalWP exports.

= 0.1.1 =
* Fixed large uploads sending the rest of the file at once after the first chunk.
* Uploads automatically retry with smaller chunks when the server rejects them.
* The plugin no longer loads on the front end.

= 0.1.0 =
* First release.
