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
			PDO::ATTR_EMULATE_PREPARES => false, // Use real server-side prepared statements
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
			portionSize BIGINT UNSIGNED NOT NULL DEFAULT 1,
			KEY market_group_id (market_group_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// Migration: add portionSize to existing installations that predate this column.
		$col = $pdo->prepare("SELECT COUNT(*) AS c
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = 'ett_invTypes'
				AND COLUMN_NAME = 'portionSize'");
		$col->execute();
		if ((int)($col->fetch()['c'] ?? 0) === 0) {
			$pdo->exec("ALTER TABLE ett_invTypes ADD COLUMN portionSize BIGINT UNSIGNED NOT NULL DEFAULT 1");
		}

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

		// Separate from ett_industryActivityProducts above (that one's an
		// existence-only check used elsewhere via a LEFT JOIN on
		// product_type_id — changing its primary key to a composite would
		// have made that JOIN fan out into duplicate rows wherever a product
		// has more than one blueprint source, e.g. invented T2 variants).
		// This table exists specifically for the contract-price feature,
		// which genuinely needs the blueprint's OWN type_id (to search
		// contracts for) alongside what it produces — a real many-to-many
		// relationship the other table was never designed to carry.
		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_blueprint_products (
			blueprint_type_id BIGINT UNSIGNED NOT NULL,
			product_type_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY (blueprint_type_id, product_type_id),
			KEY product_type_id (product_type_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// ── Contract Fetch (third scheduled step, after Prices/History) ────
		//
		// A contract's contents can never change once created — only its
		// status does (accepted, expired, or cancelled, at which point it
		// simply stops appearing in the live list). That means resolving
		// what's inside a given contract_id is a one-time cost: once
		// checked, the result stays valid forever, so every run after the
		// first only needs to check contract_ids never seen before.
		//
		// ett_contract_resolved is that permanent "have we already looked
		// inside this one" record — matched_blueprint_type_id is NULL for
		// contracts confirmed NOT to be one of our tracked blueprints
		// (avoids re-checking a known non-match), and set for confirmed
		// matches. Grows forever except for a housekeeping prune at the end
		// of each run (contract_ids no longer in the live list are removed
		// — pure storage cleanup, doesn't affect correctness either way
		// since a gone contract_id would just never be looked up again
		// regardless).
		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_contract_resolved (
			contract_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			matched_blueprint_type_id BIGINT UNSIGNED NULL,
			checked_at DATETIME NOT NULL,
			KEY matched_blueprint_type_id (matched_blueprint_type_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// The currently active (still actually purchasable) confirmed BPC
		// listings — this is what actually feeds the price calculation, and
		// unlike ett_contract_resolved above, THIS table must be correctness-
		// pruned every run: if a listing here is no longer in the live list
		// (accepted/expired/cancelled), it has to come out immediately, or
		// the price calculation would keep counting an offer nobody can
		// actually buy anymore. price/runs are exactly what that specific
		// contract listing showed at the time it was checked — never
		// recomputed or normalized here; the per-run division happens later,
		// when aggregating across all active listings for one blueprint.
		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_contract_bpc_active (
			contract_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			blueprint_type_id BIGINT UNSIGNED NOT NULL,
			price DECIMAL(20,2) NOT NULL,
			runs INT UNSIGNED NOT NULL,
			quantity INT UNSIGNED NOT NULL DEFAULT 1,
			material_efficiency TINYINT UNSIGNED NOT NULL DEFAULT 0,
			time_efficiency TINYINT UNSIGNED NOT NULL DEFAULT 0,
			checked_at DATETIME NOT NULL,
			KEY blueprint_type_id (blueprint_type_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// Migration: add quantity to existing installations that predate
		// same-type bulk listing support (e.g. 5x identical Capital Armor
		// Plates BPCs bundled in one contract) — price and runs already
		// represent the WHOLE bulk listing's totals (full contract price,
		// summed runs across every copy), quantity is purely how many
		// individual copies contributed to that sum, for display/context
		// only, never part of the per-run price ÷ runs math itself.
		$col = $pdo->prepare("SELECT COUNT(*) AS c
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = 'ett_contract_bpc_active'
				AND COLUMN_NAME = 'quantity'");
		$col->execute();
		if ((int)($col->fetch()['c'] ?? 0) === 0) {
			$pdo->exec("ALTER TABLE ett_contract_bpc_active ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1");
		}

		// Migration: add material_efficiency/time_efficiency to existing
		// installations that predate these columns.
		foreach (['material_efficiency', 'time_efficiency'] as $col_name) {
			$col = $pdo->prepare("SELECT COUNT(*) AS c
				FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
					AND TABLE_NAME = 'ett_contract_bpc_active'
					AND COLUMN_NAME = :col");
			$col->execute([':col' => $col_name]);
			if ((int)($col->fetch()['c'] ?? 0) === 0) {
				$pdo->exec("ALTER TABLE ett_contract_bpc_active ADD COLUMN {$col_name} TINYINT UNSIGNED NOT NULL DEFAULT 0");
			}
		}
		// ISK price per blueprint, after median-based outlier rejection
		// (anything under 50% of the median price among that blueprint's
		// active listings is discarded as a likely mistake/troll listing)
		// then taking the minimum of whatever survives. ett-build-costs
		// reads only this table; it never touches the two above directly.
		// Staging table for the listing phase of a contract-fetch run —
		// truncated at the start of each run, populated with every
		// item_exchange contract currently at the Jita trade station as
		// the region's pages are walked. Doubles as "what's actually live
		// right now" for pruning ett_contract_bpc_active and
		// ett_contract_resolved at the end of the same run, since by the
		// time listing finishes this table already holds the exact
		// current live set — no second fetch needed to know what to prune.
		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_contract_candidates (
			contract_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			price DECIMAL(20,2) NOT NULL,
			seen_at DATETIME NOT NULL
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// The final, ready-to-read output — one already-normalized per-run
		// ISK price per blueprint, after outlier rejection (computed within
		// each distinct run count separately — a 30-run copy being cheaper
		// per-run than a 2-run copy is normal bulk pricing, not a mistake)
		// then taking the minimum of whatever survives across all run-count
		// groups. Also records the SPECIFIC winning listing's own raw price,
		// run count, and ME%/TE% — not just the derived per-run rate — so
		// ett-build-costs can show the real contract's actual numbers (the
		// full price you'd actually pay, and that copy's real research
		// level) rather than a reconstructed figure.
		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_contract_bpc_prices (
			blueprint_type_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			per_run_price DECIMAL(20,2) NOT NULL,
			winning_price DECIMAL(20,2) NOT NULL,
			winning_runs INT UNSIGNED NOT NULL,
			winning_quantity INT UNSIGNED NOT NULL DEFAULT 1,
			material_efficiency TINYINT UNSIGNED NOT NULL DEFAULT 0,
			time_efficiency TINYINT UNSIGNED NOT NULL DEFAULT 0,
			contract_id BIGINT UNSIGNED NULL,
			sample_count INT UNSIGNED NOT NULL,
			computed_at DATETIME NOT NULL
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// Migration: add the winning-listing detail columns to existing
		// installations that predate them.
		foreach ([
			'winning_price' => 'DECIMAL(20,2) NOT NULL DEFAULT 0',
			'winning_runs' => 'INT UNSIGNED NOT NULL DEFAULT 1',
			'winning_quantity' => 'INT UNSIGNED NOT NULL DEFAULT 1',
			'material_efficiency' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
			'time_efficiency' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
			'contract_id' => 'BIGINT UNSIGNED NULL',
		] as $col_name => $col_def) {
			$col = $pdo->prepare("SELECT COUNT(*) AS c
				FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
					AND TABLE_NAME = 'ett_contract_bpc_prices'
					AND COLUMN_NAME = :col");
			$col->execute([':col' => $col_name]);
			if ((int)($col->fetch()['c'] ?? 0) === 0) {
				$pdo->exec("ALTER TABLE ett_contract_bpc_prices ADD COLUMN {$col_name} {$col_def}");
			}
		}

		// A contract mixing DIFFERENT blueprint types (or blueprints mixed
		// with non-blueprint items) can't be fairly priced per-item — no
		// way to know how the seller split the lump sum across genuinely
		// different things. But it might still be a genuine "complete
		// build pack" (a hull blueprint plus every component blueprint
		// that hull's build needs, all in one contract) — recognizing
		// that requires comparing this contract's FULL contents against
		// what a specific hull's build actually needs, which only
		// ett-build-costs knows how to determine. So rather than deciding
		// "pack or not" here, this just records everything found — every
		// distinct blueprint (grouped by type/ME%/TE%, since a contract
		// could bundle multiple identical copies of one type alongside
		// other types) plus the contract's own total price — for
		// ett-build-costs to later check against real hull requirements.
		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_contract_packs (
			contract_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			total_price DECIMAL(20,2) NOT NULL,
			has_non_blueprint_items TINYINT UNSIGNED NOT NULL DEFAULT 0,
			checked_at DATETIME NOT NULL
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// One row per distinct (blueprint_type_id, ME%, TE%) group found
		// within a pack candidate — quantity/runs_per_copy assume every
		// copy within the SAME group is identical, same homogeneity
		// principle already used for same-type bulk listings.
		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_contract_pack_items (
			contract_id BIGINT UNSIGNED NOT NULL,
			blueprint_type_id BIGINT UNSIGNED NOT NULL,
			material_efficiency TINYINT UNSIGNED NOT NULL DEFAULT 0,
			time_efficiency TINYINT UNSIGNED NOT NULL DEFAULT 0,
			quantity INT UNSIGNED NOT NULL,
			runs_per_copy INT UNSIGNED NOT NULL,
			PRIMARY KEY (contract_id, blueprint_type_id, material_efficiency, time_efficiency),
			KEY blueprint_type_id (blueprint_type_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// Every genuinely distinct (blueprint, ME%, TE%) combination that
		// survives outlier rejection, not just the single overall cheapest
		// per-run winner (that's still what ett_contract_bpc_prices
		// stores, unchanged — this is purely additive, existing behavior
		// is untouched). Needed because "cheapest to acquire" and
		// "cheapest overall once you account for how much extra material
		// waste a lower ME level causes" are different questions — a more
		// expensive, better-researched candidate can still win once real
		// material cost is factored in, but that comparison needs every
		// real option on the table, not just whichever had the lowest
		// sticker price.
		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_contract_bpc_candidates (
			blueprint_type_id BIGINT UNSIGNED NOT NULL,
			material_efficiency TINYINT UNSIGNED NOT NULL,
			time_efficiency TINYINT UNSIGNED NOT NULL,
			per_run_price DECIMAL(20,2) NOT NULL,
			winning_price DECIMAL(20,2) NOT NULL,
			winning_runs INT UNSIGNED NOT NULL,
			winning_quantity INT UNSIGNED NOT NULL DEFAULT 1,
			contract_id BIGINT UNSIGNED NULL,
			computed_at DATETIME NOT NULL,
			PRIMARY KEY (blueprint_type_id, material_efficiency, time_efficiency)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// Migration: add contract_id to existing installations that predate
		// it — needed so a Discord "Open Contract In-Game" button has a
		// real contract to point at. Nullable: a stray existing row from
		// before this column existed simply won't have one until the next
		// aggregation pass recomputes it (which happens on every normal
		// Contract Fetch run, no re-pull needed).
		$col = $pdo->prepare("SELECT COUNT(*) AS c
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = 'ett_contract_bpc_candidates'
				AND COLUMN_NAME = 'contract_id'");
		$col->execute();
		if ((int)($col->fetch()['c'] ?? 0) === 0) {
			$pdo->exec("ALTER TABLE ett_contract_bpc_candidates ADD COLUMN contract_id BIGINT UNSIGNED NULL");
		}

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

		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_market_history (
			hub_key VARCHAR(32) NOT NULL,
			type_id BIGINT UNSIGNED NOT NULL,
			avg_daily_volume DECIMAL(15,2) NULL,
			fetched_at DATETIME NOT NULL,
			PRIMARY KEY (hub_key, type_id),
			KEY type_id (type_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_adjusted_prices (
			type_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			adjusted_price DECIMAL(20,4) NULL,
			average_price DECIMAL(20,4) NULL,
			fetched_at DATETIME NOT NULL,
			KEY fetched_at (fetched_at)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$pdo->exec("CREATE TABLE IF NOT EXISTS ett_mapSolarSystems (
			solar_system_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			name VARCHAR(100) NOT NULL,
			region_id BIGINT UNSIGNED NOT NULL,
			KEY name (name),
			KEY region_id (region_id)
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
