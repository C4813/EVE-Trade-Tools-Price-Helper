<?php
if (!defined('ABSPATH')) exit;

class ETT_Fuzzwork {
	const INV_MARKETGROUPS_BZ2 = 'https://www.fuzzwork.co.uk/dump/latest/invMarketGroups.sql.bz2';
	const INV_TYPES_CSV         = 'https://www.fuzzwork.co.uk/dump/latest/invTypes-nodescription.csv';
	const INV_METAGROUPS_BZ2    = 'https://www.fuzzwork.co.uk/dump/latest/invMetaGroups.sql.bz2';
	const INV_METATYPES_BZ2     = 'https://www.fuzzwork.co.uk/dump/latest/invMetaTypes.sql.bz2';
	const IAP_BZ2               = 'https://www.fuzzwork.co.uk/dump/latest/industryActivityProducts.sql.bz2';
	const INV_TYPE_MATERIALS_CSV_BZ2 = 'https://www.fuzzwork.co.uk/dump/latest/invTypeMaterials.csv.bz2';


	// Sanity limits (hardening against corrupted/unexpected downloads)
	const MAX_DOWNLOAD_BYTES      = 300000000; // 300 MB
	const MAX_IMPORT_LINE_BYTES   = 2000000;   // 2 MB per SQL line
	const MAX_BUFFER_BYTES        = 8000000;   // 8 MB rolling buffer safety

	public static function ensure_ready() : void {
		if (!function_exists('bzopen')) {
			throw new Exception('PHP bz2 extension is not available (bzopen).');
		}
	}


	public static function import_all(PDO $pdo) : array{
		self::ensure_ready();

		$uploads = wp_upload_dir();
		$base = trailingslashit($uploads['basedir']) . 'ett-price-helper/';
		$dir  = trailingslashit($base . 'fuzzwork/');

		if (!file_exists($dir)) {
			wp_mkdir_p($dir);
		}

		// Deny web access to downloaded dumps in common environments.
		$ht = $dir . '.htaccess';
		if (!file_exists($ht)) {
			@file_put_contents($ht, "Deny from all\n");
		}

		$webcfg = $dir . 'web.config';
		if (!file_exists($webcfg)) {
			@file_put_contents(
				$webcfg,
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n"
			);
		}

		$mg_bz2  = $dir . 'invMarketGroups.sql.bz2';
		$mgg_bz2 = $dir . 'invMetaGroups.sql.bz2';
		$mtt_bz2 = $dir . 'invMetaTypes.sql.bz2';
		$iap_bz2 = $dir . 'industryActivityProducts.sql.bz2';
		$ty_csv  = $dir . 'invTypes-nodescription.csv';
		$itm_bz2 = $dir . 'invTypeMaterials.csv.bz2';

		try { self::download_to_file(self::INV_MARKETGROUPS_BZ2, $mg_bz2); }
		catch (Exception $e){ throw new Exception(sprintf('Download invMarketGroups failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

		try { self::download_to_file(self::INV_METAGROUPS_BZ2, $mgg_bz2); }
		catch (Exception $e){ throw new Exception(sprintf('Download invMetaGroups failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

		try { self::download_to_file(self::INV_METATYPES_BZ2, $mtt_bz2); }
		catch (Exception $e){ throw new Exception(sprintf('Download invMetaTypes failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

		try { self::download_to_file(self::IAP_BZ2, $iap_bz2); }
		catch (Exception $e){ throw new Exception(sprintf('Download industryActivityProducts failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

		try { self::download_to_file(self::INV_TYPES_CSV, $ty_csv); }
		catch (Exception $e){ throw new Exception(sprintf('Download invTypes CSV failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

        try { self::download_to_file(self::INV_TYPE_MATERIALS_CSV_BZ2, $itm_bz2); }
        catch (Exception $e){ throw new Exception(sprintf('Download invTypeMaterials failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

		try { $mg_count = self::import_market_groups_from_bz2_sql($pdo, $mg_bz2); }
		catch (Exception $e){ throw new Exception(sprintf('Import invMarketGroups failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

		try { $mgg_count = self::import_meta_groups_from_bz2_sql($pdo, $mgg_bz2); }
		catch (Exception $e){ throw new Exception(sprintf('Import invMetaGroups failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

		try { $mtt_count = self::import_meta_types_from_bz2_sql($pdo, $mtt_bz2); }
		catch (Exception $e){ throw new Exception(sprintf('Import invMetaTypes failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

		try { $mfg_count = self::import_mfg_outputs_from_iap_bz2_sql($pdo, $iap_bz2); }
		catch (Exception $e){ throw new Exception(sprintf('Import industryActivityProducts failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

		try { $ty_count = self::import_types_from_csv($pdo, $ty_csv); }
		catch (Exception $e){ throw new Exception(sprintf('Import invTypes CSV failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

        try { $itm_count = self::import_type_materials_from_csv_bz2($pdo, $itm_bz2); }
        catch (Exception $e){ throw new Exception(sprintf('Import invTypeMaterials failed: %s', esc_html(wp_strip_all_tags($e->getMessage())))); }

		return [
            'invMarketGroups'          => $mg_count,
            'invTypes'                 => $ty_count,
            'invMetaGroups'            => $mgg_count,
            'invMetaTypes'             => $mtt_count,
            'industryActivityProducts' => $mfg_count,
            'invTypeMaterials'         => $itm_count,
			'imported_at'   => gmdate('Y-m-d H:i:s') . ' UTC',
		];
	}

	private static function download_to_file($url, $path) : void{
		if (file_exists($path)) {
			wp_delete_file($path);
		}

		$resp = wp_remote_get($url, [
			'timeout'     => 300,
			'redirection' => 5,
			'headers'     => [
				'User-Agent' => 'WordPress/ETT-Price-Helper; ' . home_url('/'),
				'Accept'     => '*/*',
			],
			'stream'   => true,
			'filename' => $path,
		]);

		if (is_wp_error($resp)){
			wp_delete_file($path);
			throw new Exception(sprintf(
				'Download failed for %s: %s',
				esc_url_raw($url),
				esc_html(wp_strip_all_tags($resp->get_error_message()))
			));
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		if ($code < 200 || $code >= 300){
			wp_delete_file($path);
			throw new Exception(sprintf('Download failed HTTP %d for %s', (int)$code, esc_url_raw($url)));
		}

		if (!file_exists($path)) {
			throw new Exception(sprintf('Download failed: file not created: %s', esc_html(wp_strip_all_tags($path))));
		}

		$size = @filesize($path);
		if ($size === false || $size <= 0){
			wp_delete_file($path);
			throw new Exception(sprintf(
				'Download failed: empty file for %s -> %s',
				esc_url_raw($url),
				esc_html(wp_strip_all_tags($path))
			));
		}

		if ($size > self::MAX_DOWNLOAD_BYTES){
			wp_delete_file($path);
			throw new Exception(sprintf(
				'Download failed: file too large (%d bytes) for %s -> %s',
				(int)$size,
				esc_url_raw($url),
				esc_html(wp_strip_all_tags($path))
			));
		}
	}

	private static function import_market_groups_from_bz2_sql(PDO $pdo, string $bz2_path) : int{
		$pdo->exec('TRUNCATE TABLE ett_invMarketGroups');

		$bz = bzopen($bz2_path, 'r');
		if (!$bz) throw new Exception('Failed opening bz2: ' . esc_html(wp_strip_all_tags($bz2_path)));

		$insert = $pdo->prepare("INSERT INTO ett_invMarketGroups (market_group_id, parent_group_id, name, description, has_types)
			VALUES (:id,:pid,:name,:desc,:has)");

		$buffer = '';
		$count = 0;

		$processLine = function(string $line) use ($insert, &$count) : void {
			$line = trim($line);
			if ($line === '') return;

			if (strlen($line) > self::MAX_IMPORT_LINE_BYTES){
				throw new Exception('Import aborted: line exceeded MAX_IMPORT_LINE_BYTES');
			}

			if (stripos($line, 'INSERT INTO') !== 0) return;
			if (stripos($line, 'invMarketGroups') === false) return;

			$values_pos = stripos($line, 'VALUES');
			if ($values_pos === false) return;

			$values_str = rtrim(trim(substr($line, $values_pos + 6)), ';');
			$tuples = self::split_sql_tuples($values_str);

			foreach ($tuples as $tuple){
				$cols = self::parse_sql_tuple($tuple);

				$id   = (int)$cols[0];
				$pid  = ($cols[1] === 'NULL') ? null : (int)$cols[1];
				$name = self::sql_unescape_string($cols[2]);
				$desc = ($cols[3] === 'NULL') ? null : self::sql_unescape_string($cols[3]);
				$has  = (int)$cols[5];

				$insert->execute([
					':id'   => $id,
					':pid'  => $pid,
					':name' => $name,
					':desc' => $desc,
					':has'  => $has,
				]);

				$count++;
			}
		};

		try {
			while (!feof($bz)){
				$chunk = bzread($bz, 8192);
				if ($chunk === false) throw new Exception('Import aborted: bzread() failed');

				$buffer .= $chunk;

				if (strlen($buffer) > self::MAX_BUFFER_BYTES){
					throw new Exception('Import aborted: buffer exceeded MAX_BUFFER_BYTES');
				}

				while (($pos = strpos($buffer, "\n")) !== false){
					$line = substr($buffer, 0, $pos);
					$buffer = substr($buffer, $pos + 1);
					$processLine($line);
				}
			}

			$tail = trim($buffer);
			if ($tail !== '') $processLine($tail);
		} finally {
			bzclose($bz);
		}

		return $count;
	}

	private static function import_meta_groups_from_bz2_sql(PDO $pdo, string $bz2_path) : int{
		$pdo->exec('TRUNCATE TABLE ett_invMetaGroups');

		$table_name = 'invMetaGroups';
		$idx = self::get_sql_column_index_map($bz2_path, $table_name, ['metaGroupID','metaGroupName','description']);

		foreach (['metaGroupID','metaGroupName','description'] as $need){
			if (!isset($idx[$need])) {
				throw new Exception(sprintf('invMetaGroups missing expected column: %s', esc_html(wp_strip_all_tags($need))));
			}
		}

		$bz = bzopen($bz2_path, 'r');
		if (!$bz) throw new Exception('Failed opening bz2: ' . esc_html(wp_strip_all_tags($bz2_path)));

		$insert = $pdo->prepare("INSERT INTO ett_invMetaGroups (meta_group_id, name, description)
			VALUES (:id,:name,:desc)");

		$buffer = '';
		$count = 0;

		$processLine = function(string $line) use ($insert, &$count, $idx) : void {
			$line = trim($line);
			if ($line === '') return;

			if (strlen($line) > self::MAX_IMPORT_LINE_BYTES){
				throw new Exception('Import aborted: line exceeded MAX_IMPORT_LINE_BYTES');
			}

			if (stripos($line, 'INSERT INTO') !== 0) return;
			if (stripos($line, 'invMetaGroups') === false) return;

			$values_pos = stripos($line, 'VALUES');
			if ($values_pos === false) return;

			$values_str = rtrim(trim(substr($line, $values_pos + 6)), ';');
			$tuples = self::split_sql_tuples($values_str);

			foreach ($tuples as $tuple){
				$cols = self::parse_sql_tuple($tuple);

				$id   = (int)$cols[$idx['metaGroupID']];
				$name = self::sql_unescape_string($cols[$idx['metaGroupName']]);
				$desc = ($cols[$idx['description']] === 'NULL') ? null : self::sql_unescape_string($cols[$idx['description']]);

				$insert->execute([
					':id'   => $id,
					':name' => $name,
					':desc' => $desc,
				]);

				$count++;
			}
		};

		try {
			while (!feof($bz)){
				$chunk = bzread($bz, 8192);
				if ($chunk === false) throw new Exception('Import aborted: bzread() failed');

				$buffer .= $chunk;

				if (strlen($buffer) > self::MAX_BUFFER_BYTES){
					throw new Exception('Import aborted: buffer exceeded MAX_BUFFER_BYTES');
				}

				while (($pos = strpos($buffer, "\n")) !== false){
					$line = substr($buffer, 0, $pos);
					$buffer = substr($buffer, $pos + 1);
					$processLine($line);
				}
			}

			$tail = trim($buffer);
			if ($tail !== '') $processLine($tail);
		} finally {
			bzclose($bz);
		}

		return $count;
	}

	private static function import_meta_types_from_bz2_sql(PDO $pdo, string $bz2_path) : int{
		$pdo->exec('TRUNCATE TABLE ett_invMetaTypes');

		$table_name = 'invMetaTypes';
		$idx = self::get_sql_column_index_map($bz2_path, $table_name, ['typeID','metaGroupID']);

		foreach (['typeID','metaGroupID'] as $need){
			if (!isset($idx[$need])) {
				throw new Exception(sprintf('invMetaTypes missing expected column: %s', esc_html(wp_strip_all_tags($need))));
			}
		}

		$bz = bzopen($bz2_path, 'r');
		if (!$bz) throw new Exception('Failed opening bz2: ' . esc_html(wp_strip_all_tags($bz2_path)));

		$insert = $pdo->prepare("INSERT INTO ett_invMetaTypes (type_id, meta_group_id)
			VALUES (:tid,:mgid)");

		$buffer = '';
		$count = 0;

		$processLine = function(string $line) use ($insert, &$count, $idx) : void {
			$line = trim($line);
			if ($line === '') return;

			if (strlen($line) > self::MAX_IMPORT_LINE_BYTES){
				throw new Exception('Import aborted: line exceeded MAX_IMPORT_LINE_BYTES');
			}

			if (stripos($line, 'INSERT INTO') !== 0) return;
			if (stripos($line, 'invMetaTypes') === false) return;

			$values_pos = stripos($line, 'VALUES');
			if ($values_pos === false) return;

			$values_str = rtrim(trim(substr($line, $values_pos + 6)), ';');
			$tuples = self::split_sql_tuples($values_str);

			foreach ($tuples as $tuple){
				$cols = self::parse_sql_tuple($tuple);

				$tid  = (int)$cols[$idx['typeID']];
				$mgid = (int)$cols[$idx['metaGroupID']];

				$insert->execute([
					':tid'  => $tid,
					':mgid' => $mgid,
				]);

				$count++;
			}
		};

		try {
			while (!feof($bz)){
				$chunk = bzread($bz, 8192);
				if ($chunk === false) throw new Exception('Import aborted: bzread() failed');

				$buffer .= $chunk;

				if (strlen($buffer) > self::MAX_BUFFER_BYTES){
					throw new Exception('Import aborted: buffer exceeded MAX_BUFFER_BYTES');
				}

				while (($pos = strpos($buffer, "\n")) !== false){
					$line = substr($buffer, 0, $pos);
					$buffer = substr($buffer, $pos + 1);
					$processLine($line);
				}
			}

			$tail = trim($buffer);
			if ($tail !== '') $processLine($tail);
		} finally {
			bzclose($bz);
		}

		return $count;
	}

	private static function import_mfg_outputs_from_iap_bz2_sql(PDO $pdo, string $bz2_path) : int{
		$pdo->exec('TRUNCATE TABLE ett_industryActivityProducts');

		$table_name = 'industryActivityProducts';
		$idx = self::get_sql_column_index_map($bz2_path, $table_name, ['productTypeID','activityID']);

		foreach (['productTypeID','activityID'] as $need){
			if (!isset($idx[$need])) {
				throw new Exception(sprintf('industryActivityProducts missing expected column: %s', esc_html(wp_strip_all_tags($need))));
			}
		}

		$bz = bzopen($bz2_path, 'r');
		if (!$bz) throw new Exception('Failed opening bz2: ' . esc_html(wp_strip_all_tags($bz2_path)));

		$insert = $pdo->prepare("INSERT IGNORE INTO ett_industryActivityProducts (product_type_id)
			VALUES (:pid)");

		$buffer = '';
		$count = 0;

		$processLine = function(string $line) use ($insert, &$count, $idx) : void {
			$line = trim($line);
			if ($line === '') return;

			if (strlen($line) > self::MAX_IMPORT_LINE_BYTES){
				throw new Exception('Import aborted: line exceeded MAX_IMPORT_LINE_BYTES');
			}

			if (stripos($line, 'INSERT INTO') !== 0) return;
			if (stripos($line, 'industryActivityProducts') === false) return;

			$values_pos = stripos($line, 'VALUES');
			if ($values_pos === false) return;

			$values_str = rtrim(trim(substr($line, $values_pos + 6)), ';');
			$tuples = self::split_sql_tuples($values_str);

			foreach ($tuples as $tuple){
				$cols = self::parse_sql_tuple($tuple);

				$activity = (int)$cols[$idx['activityID']];
				if ($activity !== 1) continue; // manufacturing only

				$pid = (int)$cols[$idx['productTypeID']];
				if ($pid <= 0) continue;

				$insert->execute([':pid' => $pid]);
				$count++;
			}
		};

		try {
			while (!feof($bz)){
				$chunk = bzread($bz, 8192);
				if ($chunk === false) throw new Exception('Import aborted: bzread() failed');

				$buffer .= $chunk;

				if (strlen($buffer) > self::MAX_BUFFER_BYTES){
					throw new Exception('Import aborted: buffer exceeded MAX_BUFFER_BYTES');
				}

				while (($pos = strpos($buffer, "\n")) !== false){
					$line = substr($buffer, 0, $pos);
					$buffer = substr($buffer, $pos + 1);
					$processLine($line);
				}
			}

			$tail = trim($buffer);
			if ($tail !== '') $processLine($tail);
		} finally {
			bzclose($bz);
		}

		return $count;
	}

	private static function import_types_from_csv(PDO $pdo, string $csv_path) : int{
		$pdo->exec('TRUNCATE TABLE ett_invTypes');

		$fh = fopen($csv_path, 'r'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if (!$fh) throw new Exception('Failed opening CSV: ' . esc_html(wp_strip_all_tags($csv_path)));

		$first = fgetcsv($fh);
		if (!$first) throw new Exception('CSV appears empty');

		if (isset($first[0])) $first[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$first[0]);

		$lower = array_map(function($v){ return strtolower(trim((string)$v)); }, $first);
		$hasHeader = in_array('typeid', $lower, true) && in_array('typename', $lower, true);

		if ($hasHeader){
			$col = [];
			foreach ($lower as $i => $name) $col[$name] = $i;

			foreach (['typeid', 'typename', 'marketgroupid', 'published'] as $need){
				if (!isset($col[$need])) {
					throw new Exception(sprintf('CSV missing column: %s (header detected, but not found)', esc_html(wp_strip_all_tags($need))));
				}
			}
		} else {
			$col = [
				'typeid'        => 0,
				'typename'      => 2,
				'published'     => 9,
				'marketgroupid' => 10,
			];
			$pendingFirstDataRow = $first;
		}

		$insert = $pdo->prepare('INSERT INTO ett_invTypes (type_id, name, market_group_id, published) VALUES (:id,:name,:mg,:pub)');
		$count = 0;

		$toBoolInt = function($v){
			$s = strtolower(trim((string)$v));
			if ($s === 'true') return 1;
			if ($s === 'false') return 0;
			if ($s === '') return 0;
			return (int)$v;
		};

		$processRow = function(array $row) use (&$count, $insert, $col, $toBoolInt){
			$maxIdx = max($col['typeid'], $col['typename'], $col['marketgroupid'], $col['published']);
			if (count($row) <= $maxIdx) return;

			$id = (int)$row[$col['typeid']];
			if ($id <= 0) return;

			$name = (string)$row[$col['typename']];
			$mgraw = $row[$col['marketgroupid']];
			$mg = ($mgraw === '' ? null : (int)$mgraw);
			$pub = $toBoolInt($row[$col['published']]);

			$insert->execute([
				':id'   => $id,
				':name' => $name,
				':mg'   => $mg,
				':pub'  => $pub,
			]);

			$count++;
		};

		if (!$hasHeader && isset($pendingFirstDataRow)) $processRow($pendingFirstDataRow);

		while (($row = fgetcsv($fh)) !== false) $processRow($row);

		fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $count;
	}

    private static function import_type_materials_from_csv_bz2(PDO $pdo, string $bz2_path) : int {
        $pdo->exec('TRUNCATE TABLE ett_invTypeMaterials');
    
        $bz = bzopen($bz2_path, 'r');
        if (!$bz) {
            throw new Exception('Failed opening bz2: ' . esc_html(wp_strip_all_tags($bz2_path)));
        }
    
        $insert = $pdo->prepare('INSERT INTO ett_invTypeMaterials (type_id, material_type_id, quantity) VALUES (:tid,:mid,:qty)');
        $count  = 0;
    
        $buffer = '';
        $isFirstLine = true;
    
        // Default column mapping if no header is present (typical order)
        $col = [
            'typeid' => 0,
            'materialtypeid' => 1,
            'quantity' => 2,
        ];
    
        try {
            while (!feof($bz)) {
                $chunk = bzread($bz, 8192);
                if ($chunk === false) break;
    
                $buffer .= $chunk;
    
                if (strlen($buffer) > self::MAX_BUFFER_BYTES) {
                    throw new Exception('invTypeMaterials: buffer exceeded safety limit (corrupt/unexpected file).');
                }
    
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $nl), "\r\n");
                    $buffer = substr($buffer, $nl + 1);
    
                    if ($line === '') continue;
    
                    $row = str_getcsv($line);
    
                    // Handle header detection on first non-empty line
                    if ($isFirstLine) {
                        $isFirstLine = false;
    
                        if (isset($row[0])) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
                        $lower = array_map(function($v){ return strtolower(trim((string)$v)); }, $row);
    
                        $hasHeader = in_array('typeid', $lower, true)
                                  && in_array('materialtypeid', $lower, true)
                                  && in_array('quantity', $lower, true);
    
                        if ($hasHeader) {
                            $col = [];
                            foreach ($lower as $i => $name) $col[$name] = $i;
    
                            foreach (['typeid','materialtypeid','quantity'] as $need) {
                                if (!isset($col[$need])) {
                                    throw new Exception(sprintf('invTypeMaterials CSV missing column: %s', esc_html(wp_strip_all_tags($need))));
                                }
                            }
                            continue; // next line will be first data line
                        }
                        // else: first line is data, fall through and process it
                    }
    
                    $maxIdx = max($col['typeid'], $col['materialtypeid'], $col['quantity']);
                    if (count($row) <= $maxIdx) continue;
    
                    $tid = (int)$row[$col['typeid']];
                    $mid = (int)$row[$col['materialtypeid']];
                    $qtyRaw = $row[$col['quantity']];
    
                    if ($tid <= 0 || $mid <= 0) continue;
    
                    // quantity can be integer-like; treat empty as 0
                    $qty = ($qtyRaw === '' ? 0 : (int)$qtyRaw);
                    if ($qty <= 0) continue;
    
                    $insert->execute([
                        ':tid' => $tid,
                        ':mid' => $mid,
                        ':qty' => $qty,
                    ]);
    
                    $count++;
                }
            }
    
            // Process any remaining buffer as a last line (no trailing newline)
            $tail = trim($buffer);
            if ($tail !== '') {
                $row = str_getcsv($tail);
    
                $maxIdx = max($col['typeid'], $col['materialtypeid'], $col['quantity']);
                if (count($row) > $maxIdx) {
                    $tid = (int)$row[$col['typeid']];
                    $mid = (int)$row[$col['materialtypeid']];
                    $qtyRaw = $row[$col['quantity']];
                    if ($tid > 0 && $mid > 0) {
                        $qty = ($qtyRaw === '' ? 0 : (int)$qtyRaw);
                        if ($qty > 0) {
                            $insert->execute([':tid'=>$tid, ':mid'=>$mid, ':qty'=>$qty]);
                            $count++;
                        }
                    }
                }
            }
    
        } finally {
            bzclose($bz);
        }
    
        return $count;
    }

    private static function get_sql_column_index_map(string $bz2_path, string $table_name, array $expected_columns) : array{
    	$bz = bzopen($bz2_path, 'r');
    	if (!$bz) {
    		throw new Exception('Failed opening bz2: ' . esc_html(wp_strip_all_tags($bz2_path)));
    	}
    
    	$idx = [];
    	$pos = 0;
    
    	$buffer = '';
    	$inCreate = false;
    	$foundCreate = false;
    
    	try {
    		while (!feof($bz)) {
    			$chunk = bzread($bz, 8192);
    			if ($chunk === false) break;
    
    			$buffer .= $chunk;
    
    			if (strlen($buffer) > self::MAX_BUFFER_BYTES) {
    				// Safety: bail if the file is unexpectedly weird/corrupt
    				break;
    			}
    
    			while (($nl = strpos($buffer, "\n")) !== false) {
    				$line = rtrim(substr($buffer, 0, $nl), "\r\n");
    				$buffer = substr($buffer, $nl + 1);
    
    				$trim = trim($line);
    				if ($trim === '') continue;
    
    				if (!$inCreate) {
    					if (stripos($trim, 'CREATE TABLE') === 0 && stripos($trim, $table_name) !== false) {
    						$inCreate = true;
    						$foundCreate = true;
    						// continue to process subsequent lines (columns are usually on following lines)
    						continue;
    					}
    				} else {
    					// Column lines look like: `metaGroupID` int(11) NOT NULL,
    					if (preg_match('/^`?([A-Za-z0-9_]+)`?\s+/', $trim, $m)) {
    						$col = $m[1];
    
    						// Stop if we somehow matched something non-column (rare)
    						$upper = strtoupper($col);
    						if ($upper !== 'PRIMARY' && $upper !== 'KEY' && $upper !== 'UNIQUE' && $upper !== 'CONSTRAINT') {
    							if (!isset($idx[$col])) {
    								$idx[$col] = $pos++;
    							}
    						}
    					}
    
    					// End of CREATE TABLE block
    					if (strpos($trim, ');') !== false || $trim[0] === ')') {
    						break 2;
    					}
    				}
    			}
    		}
    	} finally {
    		bzclose($bz);
    	}
    
    	if (!$foundCreate) {
    		throw new Exception(sprintf(
    			'Could not determine column order for %s in %s',
    			esc_html(wp_strip_all_tags($table_name)),
    			esc_html(wp_strip_all_tags($bz2_path))
    		));
    	}
    
    	return $idx;
    }

	private static function split_sql_tuples(string $values_str) : array{
		$s = trim($values_str);
		$tuples = [];
		$depth = 0;
		$inStr = false;
		$esc = false;
		$start = null;

		$len = strlen($s);
		for ($i = 0; $i < $len; $i++){
			$ch = $s[$i];

			if ($inStr){
				if ($esc){
					$esc = false;
					continue;
				}
				if ($ch === '\\'){
					$esc = true;
					continue;
				}
				if ($ch === "'"){
					$inStr = false;
					continue;
				}
				continue;
			}

			if ($ch === "'"){
				$inStr = true;
				continue;
			}

			if ($ch === '('){
				if ($depth === 0) $start = $i;
				$depth++;
				continue;
			}

			if ($ch === ')'){
				$depth--;
				if ($depth === 0 && $start !== null){
					$tuples[] = substr($s, $start, $i - $start + 1);
					$start = null;
				}
				continue;
			}
		}

		return $tuples;
	}

	private static function parse_sql_tuple(string $tuple) : array{
		$t = trim($tuple);
		if ($t === '') return [];
		if ($t[0] === '(') $t = substr($t, 1);
		if (substr($t, -1) === ')') $t = substr($t, 0, -1);

		$out = [];
		$cur = '';
		$inStr = false;
		$esc = false;

		$len = strlen($t);
		for ($i = 0; $i < $len; $i++){
			$ch = $t[$i];

			if ($inStr){
				$cur .= $ch;
				if ($esc){
					$esc = false;
					continue;
				}
				if ($ch === '\\'){
					$esc = true;
					continue;
				}
				if ($ch === "'"){
					$inStr = false;
				}
				continue;
			}

			if ($ch === "'"){
				$inStr = true;
				$cur .= $ch;
				continue;
			}

			if ($ch === ','){
				$out[] = trim($cur);
				$cur = '';
				continue;
			}

			$cur .= $ch;
		}

		if ($cur !== '') $out[] = trim($cur);

		return $out;
	}

	private static function sql_unescape_string(string $sqlVal) : string{
		$v = trim($sqlVal);
		if ($v === 'NULL') return '';
		if ($v === '') return '';
		if ($v[0] === "'" && substr($v, -1) === "'"){
			$v = substr($v, 1, -1);
		}
		$v = str_replace(["\\\\", "\\'", '\\"', '\\n', '\\r', '\\t'], ["\\", "'", '"', "\n", "\r", "\t"], $v);
		return $v;
	}
}
