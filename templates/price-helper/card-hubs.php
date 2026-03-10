<?php if (!defined('ABSPATH')) exit; ?>
<div class="ett-card">
	<h2>Trade Hubs</h2>
	<p>Select trade hubs to call market data from.</p>
	<p>Secondary/Tertiary Market dropdown is filtered to the paired system and requires SSO + refreshed structures.</p>
	<p>Paired Systems: Jita/Perimeter, Amarr/Ashab, Rens/Frarn, Dodixie/Botane, Hek/Hek</p>
	<p><i>If you cannot find the structure you are looking for, <b>the character you have authed with above requires docking access to that structure.</b></i></p>

	<div class="ett-hub-row ett-hub-head">
		<div class="ett-hub-check"><strong>Hub</strong></div>
		<div class="ett-hub-secondary"><strong>Secondary Market</strong></div>
		<div class="ett-hub-tertiary"><strong>Tertiary Market</strong></div>
	</div>

	<form method="post" action="#" id="ett-hubs-form">
		<div class="ett-hubs">
			<?php
			$pairs    = ETT_Admin::secondary_pairs();
			$all_hubs = ETT_Admin::hubs();
			foreach ($all_hubs as $key => $hub):
				$is_checked         = in_array($key, $selected_hubs, true);
				$selected_structure = isset($secondary_structures[$key]) ? (int)$secondary_structures[$key] : 0;
				$selected_tertiary  = isset($tertiary_structures[$key]) ? (int)$tertiary_structures[$key] : 0;

				$paired_system_id = isset($pairs[$key]['system_id']) ? (int)$pairs[$key]['system_id'] : 0;

				$choices = [];
				if ($paired_system_id && !empty($cache)){
					foreach ($cache as $st){
						if (!is_array($st)) continue;
						if (empty($st['structure_id']) || empty($st['name']) || empty($st['solar_system_id'])) continue;
						if ((int)$st['solar_system_id'] !== $paired_system_id) continue;
						$choices[] = $st;
					}
				}

				$disable_secondary = (!$is_checked) || (!$sso_authed) || empty($cache);
				$disable_tertiary  = $disable_secondary;
			?>
				<div class="ett-hub-row">
					<label class="ett-hub-check">
						<input type="checkbox" name="ett_hubs[]" value="<?php echo esc_attr($key); ?>" <?php checked($is_checked); ?> />
						<?php echo esc_html($hub['label']); ?>
					</label>

					<select name="ett_secondary_structure[<?php echo esc_attr($key); ?>]" class="ett-hub-secondary" <?php disabled($disable_secondary); ?>>
						<option value="0" <?php selected($selected_structure, 0); ?>>
							<?php
							if (!$sso_authed)        echo 'Authenticate to load structures';
							elseif (empty($cache))   echo 'Click "Refresh structures"';
							else                     echo 'No secondary market';
							?>
						</option>

						<?php foreach ($choices as $st):
							$sid    = (int)$st['structure_id'];
							$nm     = (string)$st['name'];
							$ticker = isset($st['owner_ticker']) ? trim((string)$st['owner_ticker']) : '';
							$owner  = isset($st['owner_name'])   ? trim((string)$st['owner_name'])   : '';

							$suffix = '';
							if ($ticker !== '' && $owner !== '') $suffix = ' — [' . $ticker . '] ' . $owner;
							elseif ($owner !== '')               $suffix = ' — ' . $owner;
							$label = $nm . $suffix;
						?>
							<option value="<?php echo esc_attr($sid); ?>" <?php selected($selected_structure, $sid); ?>>
								<?php echo esc_html($label); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<select name="ett_tertiary_structure[<?php echo esc_attr($key); ?>]" class="ett-hub-tertiary" <?php disabled($disable_tertiary); ?>>
						<option value="0" <?php selected($selected_tertiary, 0); ?>>
							<?php
							if (!$sso_authed)        echo 'Authenticate to load structures';
							elseif (empty($cache))   echo 'Click "Refresh structures"';
							else                     echo 'No tertiary market';
							?>
						</option>

						<?php foreach ($choices as $st):
							$sid    = (int)$st['structure_id'];
							$nm     = (string)$st['name'];
							$ticker = isset($st['owner_ticker']) ? trim((string)$st['owner_ticker']) : '';
							$owner  = isset($st['owner_name'])   ? trim((string)$st['owner_name'])   : '';

							$suffix = '';
							if ($ticker !== '' && $owner !== '') $suffix = ' — [' . $ticker . '] ' . $owner;
							elseif ($owner !== '')               $suffix = ' — ' . $owner;
							$label = $nm . $suffix;
						?>
							<option value="<?php echo esc_attr($sid); ?>" <?php selected($selected_tertiary, $sid); ?>>
								<?php echo esc_html($label); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="ett-actions ett-mt-10">
			<button type="button" class="button button-secondary" id="ett-btn-refresh-structures" <?php disabled(!$sso_authed); ?>>Refresh structures</button>
		</div>

		<p class="description" id="ett-structures-cache-meta">
			<?php
			if (!$sso_authed){
				echo 'Authenticate first to refresh structures.';
			} elseif ($cache_at){
				echo 'Last refreshed: ' . esc_html(gmdate('Y-m-d H:i:s', $cache_at)) . ' UTC. Cached structures: ' . esc_html((string)count($cache)) . '.';
			} else {
				echo 'Structures have not been refreshed yet.';
			}
			?>
		</p>

		<?php submit_button('Save Trade Hubs', 'primary', 'submit', false, ['id' => 'ett-save-hubs']); ?>
	</form>
</div>
