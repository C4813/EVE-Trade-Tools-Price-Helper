<?php if (!defined('ABSPATH')) exit; ?>
<div class="ett-card">
	<h2>Market Groups</h2>
	<p>Select market groups to define which typeIDs will be generated.</p>

	<div class="ett-warning-box">
		<strong>Warning:</strong> Selecting a large number of market groups — especially all groups — can generate a very large typeID list and significantly increase database load and price run duration.
		<br><br>
		Only select the market groups you actually require.
	</div>

	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-selection-form">
		<?php wp_nonce_field('ett_save_selection'); ?>
		<input type="hidden" name="action" value="ett_save_selection"/>

		<div class="ett-grid">
			<div>
				<label><strong>Filter</strong></label>
				<p class="ett-mt-6 ett-mb-0">
					<input type="text" id="ett-mg-filter" placeholder="Type to filter market groups..."/>
				</p>
			</div>
		</div>

		<div class="ett-tree" id="ett-mg-tree">
			<?php if (!$schema_ok): ?>
				<p class="ett-muted">Configure external DB + run the SDE import to load market groups.</p>
			<?php else: ?>
				<?php echo $market_tree_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by render_tree() ?>
			<?php endif; ?>
		</div>

		<?php
		$btn_attrs = ['id' => 'ett-save-selection'];
		if (empty($selected_groups)) $btn_attrs['disabled'] = 'disabled';
		submit_button('Save Selection', 'primary', 'submit', false, $btn_attrs);
		?>
	</form>

	<div class="ett-actions ett-mg-actions">
		<button class="button button-secondary" id="ett-btn-generate" type="button" <?php disabled(!$schema_ok); ?>>Generate TypeIDs</button>
	</div>

	<p class="description ett-mt-8">
		<strong>Generate TypeIDs</strong> saves a static list of typeIDs for the currently selected market groups.
	</p>

	<div class="ett-typeid-count">
		<strong>Currently Stored TypeIDs:</strong>
		<span id="ett-current-typeids"><?php echo esc_html($typeid_display); ?></span>
	</div>
</div>
