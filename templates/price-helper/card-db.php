<?php if (!defined('ABSPATH')) exit; ?>
<div class="ett-card">
	<h2>External Database</h2>

	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-db-form">
		<?php wp_nonce_field('ett_save_db'); ?>
		<input type="hidden" name="action" value="ett_save_db"/>

		<div class="ett-row">
			<label>Host</label>
			<input type="text" name="host" value="<?php echo esc_attr($db['host']); ?>"/>
		</div>

		<div class="ett-row">
			<label>Port</label>
			<input type="number" name="port" value="<?php echo esc_attr($db['port']); ?>"/>
		</div>

		<div class="ett-row">
			<label>Database Name</label>
			<input type="text" name="dbname" value="<?php echo esc_attr($db['dbname']); ?>"/>
		</div>

		<div class="ett-row">
			<label>Database User Name</label>
			<input type="text" name="user" value="<?php echo esc_attr($db['user']); ?>"/>
		</div>

		<div class="ett-row">
			<label>Password</label>
			<input type="password" name="pass" value="" placeholder="(leave blank to keep existing)"/>
			<p class="description">Password is stored encrypted in wp_options. Leave blank to keep current.</p>
		</div>

		<?php submit_button('Save DB Settings', 'primary', 'submit', false); ?>
	</form>

	<div class="ett-status">
		<p><strong>Status:</strong>
			<?php if (!$db_test): ?>
				<span id="ett-db-status-text" class="ett-bad">Not configured.</span>
			<?php else: ?>
				<span id="ett-db-status-text" class="<?php echo esc_attr($db_test['ok'] ? 'ett-ok' : 'ett-bad'); ?>">
					<?php echo esc_html($db_test['message']); ?>
				</span>
			<?php endif; ?>
		</p>

		<p><strong>Schema:</strong>
			<span id="ett-db-schema-text" class="<?php echo esc_attr($schema_ok ? 'ett-ok' : 'ett-bad'); ?>">
				<?php echo $schema_ok ? 'Ready' : 'Not ready'; ?>
			</span>
		</p>
	</div>
</div>
