<?php
/**
 * 管理画面。動きは assets/admin.js。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$unbox_local = Unbox_Export::suggest_local_name();
$unbox_roots = Unbox_Export::root_candidates();
?>
<div class="wrap unbox">
	<h1 class="unbox-title">Unbox</h1>
	<p class="unbox-lead"><?php esc_html_e( 'Export a whole site and import it into another WordPress. There is no size limit.', 'unbox-by-oobe' ); ?></p>

	<nav class="unbox-tabs" role="tablist">
		<button type="button" class="unbox-tab is-active" data-tab="export" role="tab"><?php esc_html_e( 'Export', 'unbox-by-oobe' ); ?></button>
		<button type="button" class="unbox-tab" data-tab="import" role="tab"><?php esc_html_e( 'Import', 'unbox-by-oobe' ); ?></button>
		<button type="button" class="unbox-tab" data-tab="files" role="tab"><?php esc_html_e( 'Files', 'unbox-by-oobe' ); ?></button>
	</nav>

	<!-- 書き出し -->
	<section class="unbox-panel is-active" data-panel="export">
		<form id="unbox-export-form" class="unbox-card">
			<h2><?php esc_html_e( 'Format', 'unbox-by-oobe' ); ?></h2>
			<label class="unbox-choice">
				<input type="radio" name="format" value="unbox" checked>
				<span>
					<strong>.unbox</strong>
					<small><?php esc_html_e( 'For importing into WordPress on another server with Unbox.', 'unbox-by-oobe' ); ?></small>
				</span>
			</label>
			<label class="unbox-choice">
				<input type="radio" name="format" value="wpress">
				<span>
					<strong><?php esc_html_e( '.wpress (All-in-One WP Migration compatible)', 'unbox-by-oobe' ); ?></strong>
					<small><?php esc_html_e( 'For when the destination uses All-in-One WP Migration. Unbox can import it too.', 'unbox-by-oobe' ); ?></small>
				</span>
			</label>
			<label class="unbox-choice">
				<input type="radio" name="format" value="localwp">
				<span>
					<strong><?php esc_html_e( 'LocalWP zip', 'unbox-by-oobe' ); ?></strong>
					<small><?php esc_html_e( 'Can be passed straight to "Import site" in LocalWP. URLs are replaced at export time.', 'unbox-by-oobe' ); ?></small>
				</span>
			</label>

			<div class="unbox-localwp" hidden>
				<label class="unbox-field">
					<span><?php esc_html_e( 'LocalWP site name', 'unbox-by-oobe' ); ?></span>
					<span class="unbox-inline"><input type="text" name="local_name" value="<?php echo esc_attr( $unbox_local ); ?>" pattern="[a-z0-9\-]+" class="regular-text">.local</span>
					<small>
						<?php
						printf(
							/* translators: %s: URL such as http://example.local */
							esc_html__( 'URLs in the zip will be %s. If you give the site a different name in LocalWP, LocalWP replaces them to match.', 'unbox-by-oobe' ),
							'<code class="unbox-local-url">http://' . esc_html( $unbox_local ) . '.local</code>'
						);
						?>
					</small>
				</label>
				<label class="unbox-check"><input type="checkbox" name="include_core" value="1" checked> <?php esc_html_e( 'Include WordPress core (the site starts with the same version as the original)', 'unbox-by-oobe' ); ?></label>
			</div>

			<?php if ( $unbox_roots ) : ?>
			<div class="unbox-roots">
				<h2><?php esc_html_e( 'Folders and files outside WordPress', 'unbox-by-oobe' ); ?></h2>
				<p class="unbox-note"><?php esc_html_e( 'Items directly in the WordPress folder, other than wp-content. Check the ones to include. On import they are placed in the same location at the destination (URLs inside them are not rewritten).', 'unbox-by-oobe' ); ?></p>
				<p class="unbox-roots-off" hidden><?php esc_html_e( 'Not available for .wpress (All-in-One WP Migration compatible).', 'unbox-by-oobe' ); ?></p>
				<div class="unbox-roots-list">
					<?php foreach ( $unbox_roots as $unbox_root ) : ?>
						<label class="unbox-check"><input type="checkbox" name="root_items" value="<?php echo esc_attr( $unbox_root['name'] ); ?>"> <?php echo esc_html( $unbox_root['name'] . ( $unbox_root['dir'] ? '/' : '' ) ); ?></label>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Exclude', 'unbox-by-oobe' ); ?></h2>
			<label class="unbox-check"><input type="checkbox" name="exclude_cache" value="1" checked> <?php esc_html_e( 'Cache (wp-content/cache etc.)', 'unbox-by-oobe' ); ?></label>
			<label class="unbox-check"><input type="checkbox" name="exclude_transients" value="1" checked> <?php esc_html_e( 'Temporary data (transients)', 'unbox-by-oobe' ); ?></label>
			<label class="unbox-check"><input type="checkbox" name="exclude_spam" value="1" checked> <?php esc_html_e( 'Spam comments', 'unbox-by-oobe' ); ?></label>
			<label class="unbox-check"><input type="checkbox" name="exclude_revisions" value="1"> <?php esc_html_e( 'Post revisions', 'unbox-by-oobe' ); ?></label>
			<label class="unbox-check"><input type="checkbox" name="exclude_inactive_plugins" value="1"> <?php esc_html_e( 'Inactive plugins', 'unbox-by-oobe' ); ?></label>
			<label class="unbox-check"><input type="checkbox" name="exclude_inactive_themes" value="1"> <?php esc_html_e( 'Unused themes', 'unbox-by-oobe' ); ?></label>
			<label class="unbox-check"><input type="checkbox" name="exclude_media" value="1"> <?php esc_html_e( 'Media (uploads)', 'unbox-by-oobe' ); ?></label>
			<label class="unbox-field">
				<span><?php esc_html_e( 'Other folders or files to exclude (paths from wp-content, one per line)', 'unbox-by-oobe' ); ?></span>
				<textarea name="custom_excludes" rows="3" class="large-text code" placeholder="uploads/backup&#10;themes/old-theme"></textarea>
			</label>

			<p><button type="submit" class="button button-primary button-hero"><?php echo esc_html_x( 'Export', 'button', 'unbox-by-oobe' ); ?></button></p>
		</form>
	</section>

	<!-- 取り込み -->
	<section class="unbox-panel" data-panel="import" hidden>
		<div class="unbox-card">
			<h2><?php esc_html_e( 'Choose a .unbox or .wpress file', 'unbox-by-oobe' ); ?></h2>
			<label class="unbox-drop" id="unbox-drop">
				<input type="file" id="unbox-file" accept=".unbox,.wpress">
				<span><?php esc_html_e( 'Drop a .unbox or .wpress file here, or click to choose one', 'unbox-by-oobe' ); ?></span>
				<small>
					<?php
					printf(
						/* translators: %s: size such as 16 MB */
						esc_html__( 'No size limit. Uploaded in %s chunks.', 'unbox-by-oobe' ),
						esc_html( size_format( Unbox_Admin::chunk_size() ) )
					);
					?>
				</small>
			</label>
			<p class="unbox-note">
				<?php
				printf(
					/* translators: 1: folder path for Unbox files, 2: All-in-One WP Migration backup folder */
					esc_html__( 'Large files can be uploaded by FTP to %1$s and then imported from the "Files" tab. Files in %2$s from All-in-One WP Migration can be chosen too.', 'unbox-by-oobe' ),
					'<code>' . esc_html( 'wp-content/' . Unbox_Storage::dirname() . '/archives/' ) . '</code>',
					'<code>wp-content/ai1wm-backups/</code>'
				);
				?>
			</p>
		</div>
	</section>

	<!-- ファイル -->
	<section class="unbox-panel" data-panel="files" hidden>
		<div class="unbox-card">
			<h2><?php esc_html_e( 'Exported and importable files', 'unbox-by-oobe' ); ?></h2>
			<table class="widefat striped unbox-files">
				<thead><tr><th><?php esc_html_e( 'File', 'unbox-by-oobe' ); ?></th><th><?php esc_html_e( 'Size', 'unbox-by-oobe' ); ?></th><th><?php esc_html_e( 'Date', 'unbox-by-oobe' ); ?></th><th><?php esc_html_e( 'Location', 'unbox-by-oobe' ); ?></th><th></th></tr></thead>
				<tbody id="unbox-file-rows"><tr><td colspan="5"><?php esc_html_e( 'Loading…', 'unbox-by-oobe' ); ?></td></tr></tbody>
			</table>
			<p class="unbox-note"><?php esc_html_e( 'These files are stored where they cannot be opened directly by URL. Delete them when you no longer need them.', 'unbox-by-oobe' ); ?></p>
		</div>
	</section>

	<!-- 進み具合（書き出し・取り込み共通） -->
	<div class="unbox-modal" id="unbox-modal" hidden>
		<div class="unbox-modal-box" role="dialog" aria-modal="true" aria-labelledby="unbox-modal-title">
			<h2 id="unbox-modal-title"></h2>
			<div class="unbox-progress"><div class="unbox-progress-bar" id="unbox-bar"></div></div>
			<p class="unbox-status" id="unbox-status"></p>
			<div id="unbox-modal-body"></div>
			<details class="unbox-log" id="unbox-log-wrap" hidden><summary><?php esc_html_e( 'Log', 'unbox-by-oobe' ); ?></summary><pre id="unbox-log"></pre></details>
			<p class="unbox-actions" id="unbox-actions"></p>
		</div>
	</div>
</div>
