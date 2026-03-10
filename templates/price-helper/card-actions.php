<?php if (!defined('ABSPATH')) exit; ?>
<div class="ett-card">
	<h2>Actions</h2>

	<p><strong>Fetch All</strong> pulls prices then automatically runs the history fetch. <strong>Fetch Prices</strong> pulls prices only. <strong>Fetch History</strong> runs the history fetch only.</p>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag
	if (!empty($_GET['perf_saved'])): ?>
		<div class="notice notice-success">
			<p><strong>Saved:</strong> Performance settings updated.</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-perf-form">
		<?php wp_nonce_field('ett_perf', 'ett_perf_nonce'); ?>
		<input type="hidden" name="action" value="ett_save_perf"/>

		<details class="ett-details">
			<summary class="ett-summary">Advanced performance</summary>

			<div class="ett-row ett-mt-10">
				<label>Max pages per tick/call</label>
				<input
					type="number"
					class="ett-sched-input"
					name="batch_max_pages"
					min="1"
					max="50"
					value="<?php echo esc_attr($batch_max_pages); ?>"
				/>
				<p class="description">Higher = faster, but increases timeout risk and ESI load. Start at 5–10.</p>
			</div>

			<div class="ett-row">
				<label>Max seconds per tick/call</label>
				<input
					type="number"
					class="ett-sched-input"
					name="batch_max_seconds"
					min="1"
					max="25"
					value="<?php echo esc_attr($batch_max_seconds); ?>"
				/>
				<p class="description">Time budget per tick/call. Keep conservative on shared hosting (8–12s).</p>
			</div>

			<div class="ett-row">
				<label>History fetch concurrency</label>
				<input
					type="number"
					class="ett-sched-input"
					name="history_batch_size"
					min="1"
					max="50"
					value="<?php echo esc_attr($history_batch_size); ?>"
				/>
				<p class="description">Number of parallel ESI requests per history step (1–50). Lower this if you are hitting rate limits during the history fetch. Default: 20.</p>
			</div>

			<p class="ett-mt-10">
				<button type="submit" class="button button-secondary">Save performance settings</button>
			</p>
		</details>
	</form>

	<div class="ett-actions">
		<button class="button button-primary"   id="ett-btn-run"         title="Fetch prices then automatically run the history fetch." <?php disabled(!$schema_ok); ?>>Fetch All</button>
		<button class="button button-secondary" id="ett-btn-run-prices"  title="Fetch prices only."                                     <?php disabled(!$schema_ok); ?>>Fetch Prices</button>
		<button class="button button-secondary" id="ett-btn-run-history" title="Run the history fetch only."                            <?php disabled(!$schema_ok); ?>>Fetch History</button>
		<button class="button"                  id="ett-btn-cancel"      disabled>Cancel</button>
	</div>

	<div class="ett-last-run">
		<strong>Last price run completed:</strong>
		<span id="ett-last-price-run">
			<?php
			if ($lastRun){
				echo esc_html($lastRun . ' (' . $tz . ')');
			} else {
				echo 'Never';
			}
			?>
		</span>
	</div>

	<div class="ett-confirm ett-hidden" id="ett-run-confirm">
		<div class="ett-confirm-box">
			<div class="ett-confirm-text" id="ett-run-confirm-text"></div>
			<div class="ett-confirm-actions">
				<button type="button" class="button button-primary" id="ett-run-confirm-yes">Yes</button>
				<button type="button" class="button" id="ett-run-confirm-no">No</button>
			</div>
		</div>
	</div>

	<div class="ett-progress">
		<div class="ett-progress-head">
			<div>
				<div class="ett-title">Job Progress</div>
				<div class="ett-sub" id="ett-job-phase">Idle.</div>
				<div class="ett-sub" id="ett-job-msg">Idle.</div>
				<div class="ett-sub ett-hidden" id="ett-job-warn"></div>
			</div>

			<div class="ett-status-stack">
				<div class="ett-heartbeat" id="ett-esi">
					<span class="ett-dot"></span>
					<span class="ett-hb-text" id="ett-esi-text">ESI: Checking...</span>
				</div>
				<div class="ett-heartbeat" id="ett-heartbeat">
					<span class="ett-dot"></span>
					<span class="ett-hb-text">No heartbeat</span>
				</div>
			</div>
		</div>

		<div class="ett-kpis">
			<div class="ett-kpi"><div class="ett-k">Elapsed</div><div class="ett-v" id="ett-kpi-elapsed">—</div></div>
			<div class="ett-kpi"><div class="ett-k">Hub</div><div class="ett-v" id="ett-kpi-hub">—</div></div>
			<div class="ett-kpi"><div class="ett-k">Page</div><div class="ett-v" id="ett-kpi-page">—</div></div>
			<div class="ett-kpi"><div class="ett-k">Orders Seen</div><div class="ett-v" id="ett-kpi-orders">—</div></div>
			<div class="ett-kpi"><div class="ett-k">Matched Orders</div><div class="ett-v" id="ett-kpi-matched">—</div></div>
			<div class="ett-kpi"><div class="ett-k">Rows Written</div><div class="ett-v" id="ett-kpi-written">—</div></div>
		</div>

		<pre class="ett-json" id="ett-progress-json">{}</pre>

		<div class="ett-warning ett-hidden" id="ett-stalled">
			<span id="ett-stalled-text"></span>
		</div>
	</div>

	<div class="ett-progress ett-mt-10" id="ett-history-progress">
		<div class="ett-progress-head">
			<div>
				<div class="ett-title">History Fetch Progress</div>
				<div class="ett-sub" id="ett-history-phase">Idle.</div>
				<div class="ett-sub" id="ett-history-msg">—</div>
			</div>

			<div class="ett-status-stack">
				<div class="ett-heartbeat" id="ett-history-esi">
					<span class="ett-dot"></span>
					<span class="ett-hb-text" id="ett-history-esi-text">ESI: Checking...</span>
				</div>

				<div class="ett-heartbeat" id="ett-history-heartbeat" style="display:none;">
					<span class="ett-dot"></span>
					<span class="ett-hb-text">No heartbeat</span>
				</div>
			</div>
		</div>

		<div class="ett-kpis" style="grid-template-columns:repeat(6,1fr);">
			<div class="ett-kpi"><div class="ett-k">Elapsed</div><div class="ett-v" id="ett-history-kpi-elapsed">—</div></div>
			<div class="ett-kpi"><div class="ett-k">Hub</div><div class="ett-v" id="ett-history-kpi-hub">—</div></div>
			<div class="ett-kpi"><div class="ett-k">Items Done</div><div class="ett-v" id="ett-history-kpi-done">—</div></div>
			<div class="ett-kpi"><div class="ett-k">Items Total</div><div class="ett-v" id="ett-history-kpi-total">—</div></div>
			<div class="ett-kpi"><div class="ett-k">Rows Written</div><div class="ett-v" id="ett-history-kpi-written">—</div></div>
			<div class="ett-kpi"><div class="ett-k">Concurrency</div><div class="ett-v" id="ett-history-kpi-concurrency">—</div></div>
		</div>

		<div style="margin-top:10px;background:#e2e4e7;border-radius:6px;height:10px;overflow:hidden;">
			<div id="ett-history-bar" style="height:100%;background:#00a32a;width:0%;transition:width 0.3s;"></div>
		</div>
		<div style="text-align:right;font-size:12px;color:#646970;margin-top:3px;" id="ett-history-bar-pct">0%</div>

		<pre class="ett-json" id="ett-history-progress-json">{}</pre>
	</div>
</div>
