<?php if (!defined('ABSPATH')) exit; ?>
<div class="ett-card">
	<h2>Schedule</h2>
	<p>Automatic runs use the site timezone: <strong><?php echo esc_html($tz); ?></strong></p>

	<?php if (!empty($_GET['sched_saved'])): // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
	<div class="notice notice-success inline"><p><strong>Saved.</strong> Schedule updated.</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-sched-form">
		<?php wp_nonce_field('ett_save_schedule'); ?>
		<input type="hidden" name="action" value="ett_save_schedule"/>

		<div class="ett-row">
			<label>Start time</label>
			<input type="time" class="ett-sched-input" name="start_time" value="<?php echo esc_attr($sched_start_time); ?>" required />
		</div>

		<div class="ett-row">
			<label>Run every (hours)</label>
			<select name="freq_hours" class="ett-sched-input">
				<?php
				$options = [1,2,3,4,6,8,12,24,48,72,168];
				if (!in_array($sched_freq_hours, $options, true)) $options[] = $sched_freq_hours;
				sort($options);
				foreach ($options as $h){
					echo '<option value="' . esc_attr($h) . '" ' . selected($sched_freq_hours, $h, false) . '>' . esc_html($h) . '</option>';
				}
				?>
			</select>
		</div>

		<div id="ett-sched-rate-warning" class="ett-sched-warning ett-hidden">
			<strong>Warning:</strong> Running every 1–2 hours may trigger ESI rate limiting. It is recommended to use 4 hours or more unless you understand the load implications.
		</div>

		<div class="ett-row" style="margin-top:8px">
			<label>Next scheduled run</label>
			<span id="ett-next-run" style="font-size:13px;color:#50575e"><?php
				echo esc_html($sched_enabled ? ($next_slot_display ?? 'Unknown') : 'Schedule paused');
			?></span>
		</div>

		<h3>Cron setup</h3>
		<p>The schedule requires an external cron service pinging the endpoint below every minute. The Start time and Run every settings above control when a run actually kicks off — the pings just keep an active run moving and check whether a new one is due.</p>

		<table class="form-table" style="margin-top:0">
			<tr>
				<th style="width:180px">Option A — HTTP<br><small style="font-weight:normal">(any host, no SSH)</small></th>
				<td>
					<div style="display:flex;align-items:center;gap:8px">
						<code id="ett-curl-cmd" style="display:block;flex:1;background:#f6f6f6;padding:8px 10px;border:1px solid #ddd;border-radius:4px;word-break:break-all"><?php echo esc_html($curl_cmd); ?></code>
						<button type="button" class="button button-small ett-copy-btn" data-target="ett-curl-cmd">Copy</button>
					</div>
					<p class="description" style="margin-top:6px">Set your cron service to call this URL every minute. Each request works for the full PHP execution window before saving state, so a 10–20 minute run completes across only a handful of pings.</p>
				</td>
			</tr>
			<tr>
				<th>HTTP token</th>
				<td>
					<div style="display:flex;align-items:center;gap:8px">
						<code id="ett-runner-token" style="letter-spacing:.05em"><?php echo esc_html($runner_token); ?></code>
						<button type="button" class="button button-small" id="ett-regen-token">Regenerate</button>
					</div>
					<p class="description" style="margin-top:4px">Regenerating invalidates the old token immediately — update your cron URL afterwards.</p>
				</td>
			</tr>
			<tr>
				<th>Option B — WP-CLI<br><small style="font-weight:normal">(requires SSH)</small></th>
				<td>
					<div style="display:flex;align-items:center;gap:8px">
						<code id="ett-cli-cmd" style="display:block;flex:1;background:#f6f6f6;padding:8px 10px;border:1px solid #ddd;border-radius:4px;word-break:break-all"><?php echo esc_html($cli_cmd); ?></code>
						<button type="button" class="button button-small ett-copy-btn" data-target="ett-cli-cmd">Copy</button>
					</div>
					<p class="description" style="margin-top:6px">Runs entirely in PHP-CLI with no HTTP overhead. Set your server crontab to <code>* * * * *</code>. Adjust <code>wp</code> to the full path of your WP-CLI binary if needed.</p>
				</td>
			</tr>
		</table>

		<div style="margin-top:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<?php submit_button('Save Schedule', 'primary', 'submit', false, ['style' => 'margin:0']); ?>
			<button type="button" id="ett-cancel-schedule-btn" class="button <?php echo $sched_enabled ? 'button-secondary' : 'button-primary'; ?>" style="margin:0">
				<?php echo $sched_enabled ? 'Pause Schedule' : 'Resume Schedule'; ?>
			</button>
		</div>
	</form>
</div>
