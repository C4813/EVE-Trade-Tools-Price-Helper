<?php
if (!defined('ABSPATH')) exit;

class ETT_ESI {
	const BASE = 'https://esi.evetech.net/latest';

	private static function header_get($hdrs, string $key){
		if (is_array($hdrs)){
			foreach ($hdrs as $k => $v){
				if (strcasecmp((string)$k, $key) === 0) return $v;
			}
			return null;
		}

		if (is_object($hdrs) && method_exists($hdrs, 'getAll')){
			$all = $hdrs->getAll();
			foreach ($all as $k => $v){
				if (strcasecmp((string)$k, $key) === 0) return $v;
			}
			return null;
		}

		if (is_object($hdrs) && method_exists($hdrs, 'offsetGet')){
			return $hdrs[$key] ?? null;
		}

		return null;
	}

	private static function orders_page_common($resp) : array{
		if (is_wp_error($resp)){
			return [
				'ok' => false,
				'code' => 0,
				'orders' => [],
				'rate_limited' => false,
				'retry_after' => 5,
				'note' => $resp->get_error_message(),
				'remain' => null,
				'reset' => null,
			];
		}

		$code = (int)wp_remote_retrieve_response_code($resp);
		$hdrs = wp_remote_retrieve_headers($resp);

		$remain = self::header_get($hdrs, 'x-esi-error-limit-remain');
		$reset = self::header_get($hdrs, 'x-esi-error-limit-reset');
		$remain = ($remain === null) ? null : (int)$remain;
		$reset = ($reset === null) ? null : (int)$reset;

		if ($code === 404){
			return [
				'ok' => true,
				'code' => 404,
				'orders' => [],
				'rate_limited' => false,
				'retry_after' => null,
				'note' => null,
				'remain' => $remain,
				'reset' => $reset,
			];
		}

		if ($code === 420 || $code === 429){
			$ra = self::header_get($hdrs, 'retry-after');
			$retry_after = ($ra !== null && is_numeric($ra)) ? (int)$ra : null;
			if ($retry_after === null && $reset !== null && $reset > 0) $retry_after = $reset;
			if ($retry_after === null) $retry_after = 5;

			$body = substr((string)wp_remote_retrieve_body($resp), 0, 200);

			return [
				'ok' => false,
				'code' => $code,
				'orders' => [],
				'rate_limited' => true,
				'retry_after' => $retry_after,
				'note' => $body,
				'remain' => $remain,
				'reset' => $reset,
			];
		}

		if ($code < 200 || $code >= 300){
			$body = substr((string)wp_remote_retrieve_body($resp), 0, 200);

			return [
				'ok' => false,
				'code' => $code,
				'orders' => [],
				'rate_limited' => false,
				'retry_after' => 5,
				'note' => $body,
				'remain' => $remain,
				'reset' => $reset,
			];
		}

		$data = json_decode(wp_remote_retrieve_body($resp), true);
		$orders = is_array($data) ? $data : [];

		return [
			'ok' => true,
			'code' => $code,
			'orders' => $orders,
			'rate_limited' => false,
			'retry_after' => null,
			'note' => null,
			'remain' => $remain,
			'reset' => $reset,
		];
	}

	public static function region_orders_page(int $region_id, int $page) : array{
		$url = self::BASE . "/markets/{$region_id}/orders/?order_type=all&page={$page}";
		$resp = wp_remote_get($url, [
			'timeout' => 30,
			'headers' => [
				'Accept' => 'application/json',
				'User-Agent' => 'WordPress/ETT-Price-Helper; ' . home_url('/'),
			],
		]);

		return self::orders_page_common($resp);
	}

	public static function structure_orders_page(int $structure_id, int $page, string $access_token) : array{
		$url = self::BASE . "/markets/structures/{$structure_id}/?page={$page}";
		$resp = wp_remote_get($url, [
			'timeout' => 30,
			'headers' => [
				'Accept' => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
				'User-Agent' => 'WordPress/ETT-Price-Helper; ' . home_url('/'),
			],
		]);

		return self::orders_page_common($resp);
	}

	public static function meta_status() : array{
		$compat = gmdate('Y-m-d', time() - (11 * 3600));
		$url = "https://esi.evetech.net/meta/status/?datasource=tranquility&compatibility_date={$compat}";

		$resp = wp_remote_get($url, [
			'timeout' => 10,
			'headers' => [
				'Accept' => 'application/json',
				'User-Agent' => 'WordPress/ETT-Price-Helper; ' . home_url('/'),
				'X-Compatibility-Date' => $compat,
			],
		]);

		if (is_wp_error($resp)){
			return [
				'overall' => 'Down',
				'color' => 'bad',
				'note' => $resp->get_error_message(),
			];
		}

		$code = (int)wp_remote_retrieve_response_code($resp);
		if ($code < 200 || $code >= 300){
			$body = substr((string)wp_remote_retrieve_body($resp), 0, 200);
			return [
				'overall' => 'Down',
				'color' => 'bad',
				'note' => "ESI HTTP {$code}: {$body}",
			];
		}

		$data = json_decode(wp_remote_retrieve_body($resp), true);
		if (!is_array($data)){
			return [
				'overall' => 'Down',
				'color' => 'bad',
				'note' => 'Invalid JSON from ESI meta/status',
			];
		}

		$rows = null;
		if (isset($data['endpoints']) && is_array($data['endpoints'])) $rows = $data['endpoints'];
		else if (isset($data['routes']) && is_array($data['routes'])) $rows = $data['routes'];
		else if (isset($data['services']) && is_array($data['services'])) $rows = $data['services'];
		else if (array_keys($data) === range(0, count($data) - 1)) $rows = $data;

		$states = [];

		if (is_array($rows)){
			foreach ($rows as $row){
				if (!is_array($row)) continue;

				$route = (string)($row['route'] ?? $row['path'] ?? $row['endpoint'] ?? '');
				$method = strtoupper((string)($row['method'] ?? ''));
				$status = (string)($row['status'] ?? $row['state'] ?? $row['health'] ?? '');

				$methodOk = ($method === '' || $method === 'GET');

				if ($methodOk && $route !== '' && stripos($route, '/markets/') !== false && stripos($route, '/orders') !== false){
					if ($status !== '') $states[] = $status;
				}
			}

			if (empty($states)){
				foreach ($rows as $row){
					if (!is_array($row)) continue;
					$status = (string)($row['status'] ?? $row['state'] ?? $row['health'] ?? '');
					if ($status !== '') $states[] = $status;
				}
			}
		}

		if (empty($states)){
			$top = strtolower(trim((string)($data['status'] ?? $data['overall'] ?? $data['state'] ?? $data['health'] ?? '')));
			if ($top !== ''){
				if ($top === 'ok' || $top === 'green' || $top === 'up') $states[] = 'OK';
				else if ($top === 'recovering') $states[] = 'Recovering';
				else if ($top === 'degraded' || $top === 'yellow') $states[] = 'Degraded';
				else if ($top === 'down' || $top === 'red' || $top === 'offline') $states[] = 'Down';
			}
		}

		$overall = self::worst_status($states);

		$noteSchema = '';
		if (empty($states)){
			$keys = array_keys($data);
			$noteSchema = 'No usable status fields found in meta/status JSON. Top-level keys: ' . implode(', ', array_slice($keys, 0, 20));
		}

		$color = 'ok';
		if ($overall === 'Degraded' || $overall === 'Recovering') $color = 'warn';
		if ($overall === 'Down') $color = 'bad';

		return [
			'overall' => $overall,
			'color' => $color,
			'note' => $noteSchema,
		];
	}

	private static function worst_status(array $states) : string{
		$rank = [
			'Down' => 4,
			'Recovering' => 3,
			'Degraded' => 2,
			'OK' => 1,
		];

		$best = 'OK';
		$bestRank = 0;

		foreach ($states as $s){
			$s = (string)$s;
			if ($s === '') continue;

			$r = $rank[$s] ?? 0;
			if ($r > $bestRank){
				$bestRank = $r;
				$best = $s;
			}
		}

		return $bestRank > 0 ? $best : 'Down';
	}
}
