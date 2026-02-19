<?php
if (!defined('ABSPATH')) exit;

class ETT_ExternalDB {
	const OPT = 'ett_extdb_settings';

	public static function defaults() : array{
		return [
			'host' => '',
			'port' => 3306,
			'dbname' => '',
			'user' => '',
            'pass_enc' => '',
            'pass_iv' => '',
            'pass_mac' => '',
		];
	}

	public static function get() : array{
		return wp_parse_args(get_option(self::OPT, []), self::defaults());
	}

	public static function save($host, $port, $dbname, $user, $pass_plain) : void{
		$enc = ETT_Crypto::encrypt_triplet((string)$pass_plain);
        update_option(self::OPT, [
        	'host' => (string)$host,
        	'port' => (int)$port,
        	'dbname' => (string)$dbname,
        	'user' => (string)$user,
        	'pass_enc' => $enc['ciphertext'],
        	'pass_iv' => $enc['iv'],
        	'pass_mac' => $enc['mac'],
        ], false);
	}

	public static function is_configured() : bool{
		$s = self::get();
		return !empty($s['host']) && !empty($s['dbname']) && !empty($s['user']);
	}

	// This plugin stores price data in an external database (not the WordPress DB).
	// Using PDO here is intentional; $wpdb is not suitable for managing an external schema.
	// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
	public static function pdo() : PDO{
		$s = self::get();
		if (!self::is_configured()) throw new Exception('External DB is not configured.');
        // Basic DSN hardening: allow only hostname/IP-like values
        if (!preg_match('/^[A-Za-z0-9.\-]+$/', (string)$s['host'])) {
        	throw new Exception('Invalid DB host.');
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', (string)$s['dbname'])) {
        	throw new Exception('Invalid DB name.');
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', (string)$s['user'])) {
        	throw new Exception('Invalid DB user.');
        }

        $pass = ETT_Crypto::decrypt_triplet((string)$s['pass_enc'], (string)$s['pass_iv'], (string)$s['pass_mac']);
        
        if (($s['pass_enc'] !== '' || $s['pass_iv'] !== '' || $s['pass_mac'] !== '') && $pass === '') {
            throw new Exception('External DB password could not be decrypted (invalid/missing IV or MAC). Re-save DB settings.');
        }

        $port = (int)($s['port'] ?? 3306);
        if ($port < 1 || $port > 65535) {
            throw new Exception('Invalid DB port (must be 1–65535).');
        }
        
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $s['host'],
            $port,
            $s['dbname']
        );

		return new PDO($dsn, $s['user'], $pass, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => true,
		]);
	}
	// phpcs:enable WordPress.DB.RestrictedClasses.mysql__PDO

	public static function test_connection() : array{
		try {
			$pdo = self::pdo();
			$v = $pdo->query('SELECT VERSION() AS v')->fetch();
			return ['ok' => true, 'message' => 'Connected. MySQL: ' . ($v['v'] ?? 'unknown')];
		} catch (Exception $e) {
			return ['ok' => false, 'message' => $e->getMessage()];
		}
	}

	public static function ensure_schema() : void{
		$pdo = self::pdo();

		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_invMarketGroups (
			market_group_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			parent_group_id BIGINT UNSIGNED NULL,
			name VARCHAR(255) NOT NULL,
			description TEXT NULL,
			has_types TINYINT(1) NOT NULL DEFAULT 0,
			KEY parent_group_id (parent_group_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_invTypes (
			type_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			name VARCHAR(255) NOT NULL,
			market_group_id BIGINT UNSIGNED NULL,
			published TINYINT(1) NOT NULL DEFAULT 1,
			KEY market_group_id (market_group_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_invMetaGroups (
			meta_group_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			name VARCHAR(255) NOT NULL,
			description TEXT NULL
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_invMetaTypes (
			type_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			parent_type_id BIGINT UNSIGNED NULL,
			meta_group_id BIGINT UNSIGNED NOT NULL,
			KEY meta_group_id (meta_group_id),
			KEY parent_type_id (parent_type_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ett_invTypeMaterials (
            type_id BIGINT UNSIGNED NOT NULL,
            material_type_id BIGINT UNSIGNED NOT NULL,
            quantity BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (type_id, material_type_id),
            KEY material_type_id (material_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_industryActivityProducts (
			product_type_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_selected_typeids (
			type_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			generated_at DATETIME NOT NULL
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$col = $pdo->prepare("SELECT COUNT(*) AS c
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = 'ett_selected_typeids'
				AND COLUMN_NAME = 'meta_tier'");
		$col->execute();
		$has = (int)($col->fetch()['c'] ?? 0);

		if ($has === 0){
			$pdo->exec("ALTER TABLE ett_selected_typeids
				ADD COLUMN meta_tier VARCHAR(16) NOT NULL DEFAULT '' AFTER generated_at");
			$pdo->exec("CREATE INDEX meta_tier ON ett_selected_typeids (meta_tier)");
		}

		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_prices (
			hub_key VARCHAR(32) NOT NULL,
			region_id BIGINT UNSIGNED NOT NULL,
			station_id BIGINT UNSIGNED NOT NULL,
			type_id BIGINT UNSIGNED NOT NULL,
			sell_min DECIMAL(20,2) NULL,
			buy_max DECIMAL(20,2) NULL,
			sell_volume BIGINT UNSIGNED NULL,
			buy_volume BIGINT UNSIGNED NULL,
			fetched_at DATETIME NOT NULL,
			PRIMARY KEY (hub_key, type_id),
			KEY type_id (type_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_jobs (
			job_id CHAR(36) NOT NULL PRIMARY KEY,
			job_type VARCHAR(32) NOT NULL,
			status VARCHAR(16) NOT NULL,
			progress_json MEDIUMTEXT NOT NULL,
			heartbeat_at DATETIME NOT NULL,
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			last_error TEXT NULL
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}

}
