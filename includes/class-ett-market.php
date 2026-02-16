<?php
if (!defined('ABSPATH')) exit;

class ETT_Market {
	public static function get_tree(PDO $pdo) : array {
		$rows = $pdo->query("
			SELECT market_group_id, parent_group_id, name
			FROM ett_market_groups
			ORDER BY name ASC
		")->fetchAll();

		$byParent = [];
		foreach ($rows as $r) {
			$pid = !empty($r['parent_group_id']) ? (int)$r['parent_group_id'] : 0;
			$byParent[$pid][] = [
				'id' => (int)$r['market_group_id'],
				'name' => (string)$r['name'],
			];
		}

		$build = function(int $parent) use (&$build, &$byParent) : array {
			$kids = $byParent[$parent] ?? [];
			$out = [];
			foreach ($kids as $k) {
				$out[] = [
					'id' => (int)$k['id'],
					'name' => (string)$k['name'],
					'children' => $build((int)$k['id']),
				];
			}
			return $out;
		};

		return $build(0);
	}

	public static function expand_descendants(PDO $pdo, array $selected) : array {
		$selected = array_values(array_unique(array_map('intval', $selected)));
		if (!$selected) return [];

		$rows = $pdo->query('SELECT market_group_id, parent_group_id FROM ett_market_groups')->fetchAll();

		$children = [];
		foreach ($rows as $r) {
			$pid = !empty($r['parent_group_id']) ? (int)$r['parent_group_id'] : 0;
			$children[$pid][] = (int)$r['market_group_id'];
		}

		$seen = [];
		$q = $selected;

		while ($q) {
			$gid = array_shift($q);
			if (isset($seen[$gid])) continue;

			$seen[$gid] = true;

			foreach (($children[$gid] ?? []) as $c) {
				if (!isset($seen[$c])) $q[] = $c;
			}
		}

		return array_map('intval', array_keys($seen));
	}
}
