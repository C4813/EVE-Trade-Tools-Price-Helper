<?php if (!defined('ABSPATH')) exit; ?>
<div class="ett-card">
	<h2>Fuzzwork Import</h2>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag
	if (!empty($_GET['db_err'])): ?>
		<div class="notice notice-error">
			<p><strong>DB Error:</strong>
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice content
				echo esc_html(sanitize_text_field(wp_unslash($_GET['db_err'])));
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag
	if (!empty($_GET['err'])): ?>
		<div class="notice notice-error">
			<p><strong>Error:</strong>
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice content
				echo esc_html(sanitize_text_field(wp_unslash($_GET['err'])));
				?>
			</p>
		</div>
	<?php endif; ?>

	<p>Imports the following tables from Fuzzwork (<code>/dump/latest/</code>) into the external DB:</p>
	<ul class="ett-list-disc">
		<li><code>invMarketGroups</code></li>
		<li><code>invTypes</code> (nodescription CSV)</li>
		<li><code>invMetaGroups</code></li>
		<li><code>invMetaTypes</code></li>
		<li><code>industryActivityProducts</code> (blueprint activity outputs)</li>
		<li><code>invTypeMaterials</code> (CSV bz2)</li>
	</ul>

	<p class="description">
		This data is used to build market group selection, generate the typeID list, and persist
		<code>meta_tier</code> (T1/Meta/T2/Faction/Deadspace/Officer/Other).
	</p>

	<p><i>
		It is only necessary to run this once after plugin activation, and thereafter when
		<a href="https://www.fuzzwork.co.uk/dump/latest/">fuzzwork.co.uk/dump/latest/</a> is updated.
	</i></p>

	<p><strong>Last import:</strong> <span id="ett-last-import"><?php echo esc_html($last_import_txt); ?></span></p>

	<div class="ett-muted ett-mt-6<?php echo $details_txt ? '' : ' ett-hidden'; ?>" id="ett-last-import-details-wrap">
		<p><strong>Last import details:</strong> <span id="ett-last-import-details"><?php echo esc_html($details_txt); ?></span></p>
	</div>

	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-fuzzwork-form">
		<?php wp_nonce_field('ett_import_fuzzwork'); ?>
		<input type="hidden" name="action" value="ett_import_fuzzwork"/>
		<?php submit_button('Import from Fuzzwork (latest)', 'secondary', 'submit', false); ?>
	</form>
</div>
