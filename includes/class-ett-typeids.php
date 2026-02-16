<?php
if (!defined('ABSPATH')) exit;

// This plugin operates on an external schema via PDO. This is intentional.
// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
class ETT_TypeIDs {
	public static function generate(PDO $pdo, array $selected_market_groups) : int {
		$selected_market_groups = array_values(array_unique(array_map('intval', $selected_market_groups)));
		if (empty($selected_market_groups)) {
			throw new Exception('No market groups selected.');
		}

		$all_groups = ETT_Market::expand_descendants($pdo, $selected_market_groups);

		$pdo->exec('TRUNCATE TABLE ett_selected_typeids');

		$now = current_time('mysql');
		$in = implode(',', array_fill(0, count($all_groups), '?'));

		$sql = "
			INSERT INTO ett_selected_typeids (type_id, generated_at, meta_tier)
			SELECT
				t.type_id,
				? AS generated_at,
				CASE
					WHEN mg.name = 'Tech II' THEN 'T2'
					WHEN mg.name IN ('Faction','Deadspace','Officer') THEN mg.name
					WHEN mo.product_type_id IS NOT NULL THEN 'T1'
					WHEN mg.name = 'Tech I' THEN 'Meta'
					ELSE 'Other'
				END AS meta_tier
			FROM ett_types t
			LEFT JOIN ett_meta_types mt
				ON mt.type_id = t.type_id
			LEFT JOIN ett_meta_groups mg
				ON mg.meta_group_id = mt.meta_group_id
			LEFT JOIN ett_mfg_outputs mo
				ON mo.product_type_id = t.type_id
			WHERE t.published = 1
				AND t.market_group_id IN ($in)
		";

		$stmt = $pdo->prepare($sql);
		$stmt->execute(array_merge([$now], $all_groups));

		return (int)$stmt->rowCount();
	}

	public static function count(PDO $pdo) : int {
		$row = $pdo->query('SELECT COUNT(*) AS c FROM ett_selected_typeids')->fetch();
		return (int)($row['c'] ?? 0);
	}

	public static function all(PDO $pdo) : array {
		return $pdo->query('SELECT type_id FROM ett_selected_typeids')->fetchAll(PDO::FETCH_COLUMN);
	}
}
// phpcs:enable WordPress.DB.RestrictedClasses.mysql__PDO
