<?php if (!defined('ABSPATH')) exit; ?>
<div class="ett-card">
	<h2>EVE SDE Import</h2>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag
	if (!empty($_GET['db_err'])): ?>
		<div class="notice notice-error">
			<p><strong>DB Error:</strong>
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo esc_html(sanitize_text_field(wp_unslash($_GET['db_err'])));
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if (!empty($_GET['err'])): ?>
		<div class="notice notice-error">
			<p><strong>Error:</strong>
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo esc_html(sanitize_text_field(wp_unslash($_GET['err'])));
				?>
			</p>
		</div>
	<?php endif; ?>

	<p>
		Upload the EVE Static Data Export (SDE) ZIP from
		<a href="https://developers.eveonline.com/static-data" target="_blank" rel="noopener">developers.eveonline.com</a>
		to populate the required reference tables in the external database.
	</p>

	<p>The following files are extracted from the ZIP automatically (nested paths such as <code>sde/fsd/</code> are handled):</p>
	<ul class="ett-list-disc">
		<li><code>marketGroups.yaml</code> &rarr; <code>ett_invMarketGroups</code></li>
		<li><code>metaGroups.yaml</code> &rarr; <code>ett_invMetaGroups</code></li>
		<li><code>types.yaml</code> &rarr; <code>ett_invTypes</code> + <code>ett_invMetaTypes</code></li>
		<li><code>typeMaterials.yaml</code> &rarr; <code>ett_invTypeMaterials</code></li>
		<li><code>blueprints.yaml</code> &rarr; <code>ett_industryActivityProducts</code></li>
	</ul>

	<p class="description">
		This import is only required once after activation, and again whenever CCP releases an updated SDE.
		All YAML files are parsed by a streaming line-by-line reader so memory use stays low even for the
		largest files (types.yaml ~200&nbsp;MB, blueprints.yaml ~500&nbsp;MB uncompressed).
	</p>

	<div class="notice notice-warning inline" style="margin:12px 0;">
		<p>
			<strong>Server requirements:</strong> The full SDE ZIP is ~1&nbsp;GB. Your server must allow large
			uploads (<code>upload_max_filesize</code>, <code>post_max_size</code>) and a long execution window
			(<code>max_execution_time = 0</code> or equivalent). If your host imposes strict limits, download the SDE
			ZIP directly to the server via SSH/FTP and enter its absolute path in the field below instead of uploading.
		</p>
	</div>

	<p><strong>Last import:</strong> <span id="ett-last-import"><?php echo esc_html($last_import_txt); ?></span></p>

	<div class="ett-muted ett-mt-6<?php echo $details_txt ? '' : ' ett-hidden'; ?>" id="ett-last-import-details-wrap">
		<p><strong>Last import details:</strong> <span id="ett-last-import-details"><?php echo esc_html($details_txt); ?></span></p>
	</div>

	<!-- Progress panel — hidden until an import starts -->
	<div id="ett-sde-progress" style="display:none; margin:16px 0;">
		<p id="ett-sde-progress-status" style="margin:0 0 6px; font-weight:600;"></p>
		<div style="background:#e0e0e0; border-radius:3px; height:18px; overflow:hidden;">
			<div id="ett-sde-progress-bar"
			     style="background:#2271b1; height:100%; width:0%; transition:width 0.3s ease;"></div>
		</div>
		<ul id="ett-sde-progress-log"
		    style="margin:8px 0 0; padding:0; list-style:none; font-size:12px; color:#666;"></ul>
	</div>

	<p class="description" id="ett-sde-no-db" style="color:#b32d2e;<?php echo $schema_ok ? 'display:none;' : ''; ?>">
		External database is not connected or schema is not initialised. Configure and test the database connection first.
	</p>

	<div id="ett-sde-forms" <?php echo $schema_ok ? '' : 'style="display:none;"'; ?>>

		<hr style="margin:18px 0 14px;">

		<h3 style="margin:0 0 8px;">Option A &mdash; Upload ZIP</h3>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
		      enctype="multipart/form-data" id="ett-sde-upload-form">
			<?php wp_nonce_field('ett_import_sde'); ?>
			<input type="hidden" name="action" value="ett_import_sde"/>
			<input type="hidden" name="ett_sde_source" value="upload"/>
			<p>
				<input type="file" name="sde_zip" id="ett-sde-zip" accept=".zip" required
				       style="max-width:420px;"/>
			</p>
			<?php submit_button('Import from Uploaded ZIP', 'secondary', 'submit', false); ?>
		</form>

		<hr style="margin:18px 0 14px;">

		<h3 style="margin:0 0 8px;">Option B &mdash; Server-side file path</h3>
		<p class="description">If you have placed the SDE ZIP on the server directly (e.g. via SSH or FTP), enter its absolute path here.</p>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-sde-path-form">
			<?php wp_nonce_field('ett_import_sde'); ?>
			<input type="hidden" name="action" value="ett_import_sde"/>
			<input type="hidden" name="ett_sde_source" value="path"/>
			<p>
				<input type="text" name="sde_zip_path" id="ett-sde-zip-path"
				       placeholder="/home/user/sde.zip" class="regular-text"
				       style="max-width:420px;"/>
			</p>
			<?php submit_button('Import from Server Path', 'secondary', 'submit', false); ?>
		</form>

	</div>
</div>
