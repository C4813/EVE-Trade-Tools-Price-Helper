<?php if (!defined('ABSPATH')) exit; ?>
<div class="ett-card" style="margin-top:16px">
	<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
		<h3 style="margin:0">Run History</h3>
		<button type="button" id="ett-clear-history-btn" class="button button-secondary button-small">Clear History</button>
	</div>
	<div id="ett-run-history" class="ett-history-wrap">
		<?php if (!$job_history && !$job_history_err): ?>
			<p class="description">No runs found yet.</p>
		<?php elseif ($job_history_err): ?>
			<p class="description">Unable to load history: <?php echo esc_html($job_history_err); ?></p>
		<?php else: ?>
			<table class="widefat striped ett-history-table">
				<thead><tr>
					<th>Type</th><th>Started</th><th>Finished</th><th>Status</th><th>Driver</th><th>Last message</th>
				</tr></thead>
				<tbody>
				<?php foreach ($job_history as $row):
					$prog = [];
					try { $prog = json_decode($row['progress_json'] ?? '', true) ?: []; } catch (Exception $e){}
					$driver_raw  = $prog['driver'] ?? 'browser';
					$driver_lbl  = $driver_raw === 'browser' ? 'Manual' : 'Scheduled';
					$type_lbl    = ($row['job_type'] ?? 'prices') === 'history' ? 'History fetch' : 'Price run';
					$msg         = !empty($row['last_error']) ? $row['last_error'] : ($prog['last_msg'] ?? '');
					$finished    = !empty($row['finished_at']) ? esc_html($row['finished_at'] . " ({$tz})") : '&mdash;';
				?>
				<tr>
					<td><?php echo esc_html($type_lbl); ?></td>
					<td><?php echo esc_html(($row['started_at'] ?? '') . " ({$tz})"); ?></td>
					<td><?php echo $finished; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped at assignment, may contain safe &mdash; entity ?></td>
					<td><?php echo esc_html($row['status'] ?? ''); ?></td>
					<td><?php echo esc_html($driver_lbl); ?></td>
					<td><?php echo esc_html($msg); ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
