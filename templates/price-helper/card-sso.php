<?php if (!defined('ABSPATH')) exit; ?>
<div class="ett-card">
	<h2>EVE SSO</h2>

	<div class="ett-muted ett-muted-block">
		<p><strong>Create an EVE Developers application</strong></p>
		<ol class="ett-list-decimal">
			<li>Go to <a href="https://developers.eveonline.com">https://developers.eveonline.com</a> and log in.</li>
			<li>Create a new application.</li>
			<li>Set the application <strong>Callback URL</strong> to the value shown below (exact match required).</li>
			<div class="ett-row">
				<label>Callback URL <span class="ett-muted">(universal &mdash; handles all ETT plugins)</span></label>
				<input type="text" readonly value="<?php echo esc_attr(ETT_Admin::unified_callback_url()); ?>" onclick="this.select();"/>
			</div>
			<li>Set the application <strong>Scopes</strong> to the following:</li>
		</ol>

		<p class="description ett-mt-8"><strong>Required by ETT Price Helper:</strong></p>
		<ul class="ett-list-disc ett-tight">
			<li><code>esi-universe.read_structures.v1</code></li>
			<li><code>esi-markets.structure_markets.v1</code></li>
			<li><code>esi-search.search_structures.v1</code></li>
		</ul>

		<p class="description ett-mt-8"><strong>Required by ETT Reprocess Trading</strong> (if installed):</p>
		<ul class="ett-list-disc ett-tight">
			<li><code>esi-skills.read_skills.v1</code></li>
			<li><code>esi-characters.read_standings.v1</code></li>
		</ul>

		<p class="description ett-mt-8">
			After creating the app, copy the Client ID and Secret into this page and click "Save SSO Settings", then "Connect EVE SSO".
		</p>
	</div>

	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-sso-form">
		<?php wp_nonce_field('ett_save_sso'); ?>
		<input type="hidden" name="action" value="ett_save_sso"/>

		<div class="ett-row">
			<label>Client ID</label>
			<input type="text" name="ett_sso_client_id" value="<?php echo esc_attr($client_id); ?>" placeholder="SSO application Client ID"/>
		</div>

		<div class="ett-row">
			<label>Client Secret</label>
			<input type="password" name="ett_sso_client_secret" value="" placeholder="<?php echo $client_secret !== '' ? '(saved — leave blank to keep)' : 'SSO application Secret Key'; ?>"/>
		</div>

		<?php submit_button('Save SSO Settings', 'secondary', 'submit', false); ?>
	</form>

	<div class="ett-mt-10">
		<?php if ($sso_authed): ?>
			<div class="ett-status ett-sso-status ett-ok">
				<strong>Status:</strong>
				Authenticated<?php echo $char_name ? ' as ' . esc_html($char_name) : ''; ?>.
			</div>

			<div class="ett-actions ett-mt-10">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ett-inline-form">
					<?php wp_nonce_field('ett_sso_disconnect'); ?>
					<input type="hidden" name="action" value="ett_sso_disconnect"/>
					<button type="submit" class="button">Disconnect</button>
				</form>
			</div>

		<?php else: ?>
			<div class="ett-status ett-sso-status ett-bad">
				<strong>Status:</strong>
				Not authenticated. Secondary Market dropdowns are disabled.
			</div>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ett-mt-10">
				<?php wp_nonce_field('ett_sso_start'); ?>
				<input type="hidden" name="action" value="ett_sso_start"/>
				<button id="ett-btn-sso-connect" type="submit" class="button button-primary" <?php disabled(empty($client_id) || empty($client_secret)); ?>>
					Connect EVE SSO
				</button>
				<?php if (empty($client_id) || empty($client_secret)): ?>
					<p class="description" id="ett-sso-connect-help">Enter Client ID and Secret, save, then connect.</p>
				<?php endif; ?>
			</form>
		<?php endif; ?>
	</div>
</div>
