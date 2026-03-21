<?php if (!defined('ABSPATH')) exit;
/**
 * Template: Private Trade Hubs card.
 *
 * Variables supplied by ETT_Admin::render():
 *   $private_hubs  array  — saved private hub configs, each:
 *                           [ 'hub_index'=>int, 'system_name'=>string, 'system_id'=>int,
 *                             'region_id'=>int, 'char_source'=>'primary'|'private',
 *                             'char_id'=>int, 'char_name'=>string,
 *                             'structures'=>[['id'=>int,'name'=>string,'enabled'=>bool]], ... ]
 *   $sso_authed    bool   — whether the primary SSO character is authenticated
 *   $primary_char_name string
 */
?>
<div class="ett-card" id="ett-private-hubs-card">
	<h2>Private Trade Hubs</h2>
	<p>Add private market structures (e.g. alliance citadels) as additional trade hubs. Each hub requires a system name and a character that has docking access to the structures in that system.</p>
	<p>Private hubs appear in the price database using their system name as the hub key (e.g. <code>c-n4od</code>) and are automatically available in EVE Trade Tools Reprocess Trading once price data has been pulled.</p>

	<div id="ett-private-hub-list">
		<?php foreach ($private_hubs as $hub): ?>
		<?php
			$idx          = (int) $hub['hub_index'];
			$char_source  = (string) ($hub['char_source'] ?? 'primary');
			$priv_authed  = !empty($hub['char_id']) && !empty($hub['char_name']);
			$system_name  = (string) ($hub['system_name'] ?? '');
			$system_id    = (int) ($hub['system_id'] ?? 0);
			$structures   = is_array($hub['structures'] ?? null) ? $hub['structures'] : [];
		?>
		<div class="ett-private-hub-entry" data-hub-index="<?php echo esc_attr($idx); ?>">
			<div class="ett-private-hub-header">
				<strong>Private Hub <?php echo esc_html($idx); ?></strong>
				<button type="button" class="button ett-btn-remove-private-hub" data-hub-index="<?php echo esc_attr($idx); ?>">Remove</button>
			</div>

			<!-- Character source -->
			<div class="ett-row">
				<label>Market Character</label>
				<select class="ett-priv-char-source" name="ett_private_hub[<?php echo esc_attr($idx); ?>][char_source]">
					<option value="primary" <?php selected($char_source, 'primary'); ?>>
						Use primary SSO character<?php echo $sso_authed && $primary_char_name ? ' (' . esc_html($primary_char_name) . ')' : ''; ?>
					</option>
					<option value="private" <?php selected($char_source, 'private'); ?>>
						Use a separate private character
					</option>
				</select>
			</div>

			<!-- Private character auth (shown only when source=private) -->
			<div class="ett-priv-auth-section" style="<?php echo $char_source === 'private' ? '' : 'display:none;'; ?>">
				<div class="ett-mt-8">
					<?php if ($priv_authed && $char_source === 'private'): ?>
						<div class="ett-status ett-sso-status ett-ok">
							<strong>Status:</strong> Authenticated as <?php echo esc_html($hub['char_name']); ?>.
						</div>
						<div class="ett-actions ett-mt-10">
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ett-inline-form">
								<?php wp_nonce_field('ett_priv_disconnect_' . $idx); ?>
								<input type="hidden" name="action" value="ett_priv_sso_disconnect"/>
								<input type="hidden" name="hub_index" value="<?php echo esc_attr($idx); ?>"/>
								<button type="submit" class="button">Disconnect private character</button>
							</form>
						</div>
					<?php else: ?>
						<div class="ett-status ett-sso-status ett-bad">
							<strong>Status:</strong> No private character authenticated.
						</div>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ett-mt-10">
							<?php wp_nonce_field('ett_priv_sso_start_' . $idx); ?>
							<input type="hidden" name="action" value="ett_priv_sso_start"/>
							<input type="hidden" name="hub_index" value="<?php echo esc_attr($idx); ?>"/>
							<button type="submit" class="button button-primary"
								<?php disabled(empty(get_option(ETT_Admin::OPT_SSO_CLIENT_ID))); ?>>
								Connect Private Character
							</button>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<!-- System search -->
			<div class="ett-row ett-mt-10">
				<label>System Name</label>
				<div class="ett-system-search-wrap" style="position:relative;display:inline-block;">
					<input type="text"
						class="ett-system-search regular-text"
						name="ett_private_hub[<?php echo esc_attr($idx); ?>][system_name]"
						value="<?php echo esc_attr($system_name); ?>"
						placeholder="e.g. C-N4OD"
						autocomplete="off"
						data-hub-index="<?php echo esc_attr($idx); ?>"
					/>
					<input type="hidden"
						class="ett-system-id"
						name="ett_private_hub[<?php echo esc_attr($idx); ?>][system_id]"
						value="<?php echo esc_attr($system_id > 0 ? $system_id : ''); ?>"
					/>
					<input type="hidden"
						class="ett-system-region-id"
						value="<?php echo esc_attr((int) ($hub['region_id'] ?? 0) > 0 ? (int) $hub['region_id'] : ''); ?>"
					/>
					<ul class="ett-system-autocomplete" style="display:none;"></ul>
				</div>
				<button type="button" class="button ett-btn-fetch-structures" data-hub-index="<?php echo esc_attr($idx); ?>"
					<?php disabled($system_id <= 0); ?>>
					Fetch Structures
				</button>
			</div>

			<!-- Structures list -->
			<div class="ett-priv-structures" data-hub-index="<?php echo esc_attr($idx); ?>">
				<?php if (!empty($structures)): ?>
					<p class="description">Select which structures to include in the price pull:</p>
					<ul class="ett-priv-structure-list">
						<?php foreach ($structures as $st): ?>
						<li>
							<label>
								<input type="checkbox"
									name="ett_private_hub[<?php echo esc_attr($idx); ?>][structures][<?php echo esc_attr($st['id']); ?>]"
									value="1"
									<?php checked(!empty($st['enabled'])); ?>
								/>
								<?php echo esc_html($st['name']); ?>
								<span class="ett-id">(<?php echo esc_html($st['id']); ?>)</span>
							</label>
						</li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ($system_id > 0): ?>
					<p class="description">No structures fetched yet. Click "Fetch Structures" to load available structures.</p>
				<?php else: ?>
					<p class="description">Enter a system name and click "Fetch Structures".</p>
				<?php endif; ?>
			</div>

			<div class="ett-actions ett-mt-10">
				<button type="button" class="button button-primary ett-btn-save-private-hub" data-hub-index="<?php echo esc_attr($idx); ?>">
					Save Hub <?php echo esc_html($idx); ?>
				</button>
			</div>
			<hr style="margin:16px 0 0;">
		</div><!-- /.ett-private-hub-entry -->
		<?php endforeach; ?>
	</div><!-- /#ett-private-hub-list -->

	<div class="ett-actions ett-mt-10">
		<button type="button" class="button" id="ett-btn-add-private-hub">+ Add Private Hub</button>
	</div>

	<div id="ett-private-hub-template" style="display:none;">
		<!-- Populated by JS for new hubs -->
	</div>
</div>
