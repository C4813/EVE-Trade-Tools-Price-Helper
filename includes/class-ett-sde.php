<?php
if (!defined('ABSPATH')) exit;

/**
 * ETT_SDE
 *
 * Imports EVE Static Data Export (SDE) YAML files from the ZIP distributed at
 * https://developers.eveonline.com/static-data
 *
 * Populates six external-DB tables used by ETT plugins:
 *   ett_invMarketGroups        ← marketGroups.yaml
 *   ett_invMetaGroups          ← metaGroups.yaml
 *   ett_invTypes               ← types.yaml  (also populates invMetaTypes)
 *   ett_invMetaTypes           ← types.yaml  (metaGroupID is a field on each type)
 *   ett_invTypeMaterials       ← typeMaterials.yaml
 *   ett_industryActivityProducts ← blueprints.yaml (manufacturing products only)
 *
 * All parsers stream the ZIP entries line-by-line so that even the largest
 * files (types.yaml ~200 MB, blueprints.yaml ~500 MB) never need to be fully
 * held in memory.
 */
final class ETT_SDE {

	/** Rows written per DB transaction — balance between memory and commit overhead. */
	const BATCH_SIZE = 500;

	/** Files that must be present somewhere in the ZIP (matched by basename). */
	const REQUIRED_FILES = [
		'marketGroups.yaml',
		'metaGroups.yaml',
		'types.yaml',
		'typeMaterials.yaml',
		'blueprints.yaml',
	];

	/** Optional files — imported if present, silently skipped if absent. */
	const OPTIONAL_FILES = [
		'mapSolarSystems.yaml',
	];

	// ── Public entry point ─────────────────────────────────────────────────

	/**
	 * Open the SDE ZIP, find the five required YAML files, and import them all.
	 *
	 * @param string $zip_path Absolute path to the uploaded SDE ZIP.
	 * @param PDO    $pdo      Connection to the external DB (schema must already exist).
	 * @return array{invMarketGroups:int,invMetaGroups:int,invTypes:int,invMetaTypes:int,invTypeMaterials:int,industryActivityProducts:int,imported_at:string}
	 * @throws Exception on any fatal error.
	 */
	public static function import_from_zip(string $zip_path, PDO $pdo): array {
		$zip = new ZipArchive();
		$res = $zip->open($zip_path);
		if ($res !== true) {
			throw new Exception(sprintf(
				'Failed to open ZIP file (ZipArchive error %d). Ensure the file is a valid EVE SDE ZIP.',
				(int) $res
			));
		}

		try {
			// Locate every required file within the archive (path-agnostic).
			$entries = [];
			foreach (self::REQUIRED_FILES as $file) {
				$entry = self::find_entry($zip, $file);
				if ($entry === null) {
					throw new Exception(sprintf(
						'Required file "%s" not found in the ZIP. Please upload the complete EVE SDE ZIP from developers.eveonline.com.',
						$file
					));
				}
				$entries[$file] = $entry;
			}

			// Optional files — skip silently if absent.
			$optional_entries = [];
			foreach (self::OPTIONAL_FILES as $file) {
				$entry = self::find_entry($zip, $file);
				if ($entry !== null) {
					$optional_entries[$file] = $entry;
				}
			}

			$mg_count  = self::import_market_groups($zip, $entries['marketGroups.yaml'], $pdo);
			$mgg_count = self::import_meta_groups($zip, $entries['metaGroups.yaml'], $pdo);
			$ty_counts = self::import_types($zip, $entries['types.yaml'], $pdo);
			$itm_count = self::import_type_materials($zip, $entries['typeMaterials.yaml'], $pdo);
			$bp_count  = self::import_blueprints($zip, $entries['blueprints.yaml'], $pdo);
			$ss_count  = isset($optional_entries['mapSolarSystems.yaml'])
				? self::import_solar_systems($zip, $optional_entries['mapSolarSystems.yaml'], $pdo)
				: null;

			$result = [
				'invMarketGroups'          => $mg_count,
				'invMetaGroups'            => $mgg_count,
				'invTypes'                 => $ty_counts['types'],
				'invMetaTypes'             => $ty_counts['meta_types'],
				'invTypeMaterials'         => $itm_count,
				'industryActivityProducts' => $bp_count,
				'imported_at'              => gmdate('Y-m-d H:i:s') . ' UTC',
			];
			if ($ss_count !== null) {
				$result['mapSolarSystems'] = $ss_count;
			}
			return $result;
		} finally {
			$zip->close();
		}
	}

	// ── Step labels (JS mirrors this order) ───────────────────────────────
	const STEPS = [
		1 => ['file' => 'marketGroups.yaml',    'label' => 'invMarketGroups',           'optional' => false],
		2 => ['file' => 'metaGroups.yaml',       'label' => 'invMetaGroups',             'optional' => false],
		3 => ['file' => 'types.yaml',            'label' => 'invTypes + invMetaTypes',   'optional' => false],
		4 => ['file' => 'typeMaterials.yaml',    'label' => 'invTypeMaterials',          'optional' => false],
		5 => ['file' => 'blueprints.yaml',       'label' => 'industryActivityProducts',  'optional' => false],
		6 => ['file' => 'mapSolarSystems.yaml',  'label' => 'mapSolarSystems',           'optional' => true],
	];

	/**
	 * Import a single SDE file by step number (1–5).
	 * Opens and closes the ZIP on each call so each AJAX request is independent.
	 *
	 * @return array{label:string,count:int,meta_count?:int}
	 * @throws Exception
	 */
	public static function import_step(int $step, string $zip_path, PDO $pdo): array {
		if (!isset(self::STEPS[$step])) {
			throw new Exception('Invalid import step: ' . esc_html((string) $step));
		}

		$zip = new ZipArchive();
		$res = $zip->open($zip_path);
		if ($res !== true) {
			throw new Exception(sprintf('Failed to open ZIP (ZipArchive error %d)', (int) $res));
		}

		try {
			$info  = self::STEPS[$step];
			$entry = self::find_entry($zip, $info['file']);
			if ($entry === null) {
				// Optional steps (e.g. mapSolarSystems.yaml) may legitimately be absent.
				if (!empty($info['optional'])) {
					return ['label' => $info['label'], 'count' => 0, 'skipped' => true];
				}
				throw new Exception(sprintf('"%s" not found in ZIP.', $info['file']));
			}

			switch ($step) {
				case 1: $count = self::import_market_groups($zip, $entry, $pdo);
					return ['label' => $info['label'], 'count' => $count];
				case 2: $count = self::import_meta_groups($zip, $entry, $pdo);
					return ['label' => $info['label'], 'count' => $count];
				case 3: $counts = self::import_types($zip, $entry, $pdo);
					return ['label' => $info['label'], 'count' => $counts['types'], 'meta_count' => $counts['meta_types']];
				case 4: $count = self::import_type_materials($zip, $entry, $pdo);
					return ['label' => $info['label'], 'count' => $count];
				case 5: $count = self::import_blueprints($zip, $entry, $pdo);
					return ['label' => $info['label'], 'count' => $count];
				case 6: $count = self::import_solar_systems($zip, $entry, $pdo);
					return ['label' => $info['label'], 'count' => $count];
			}
		} finally {
			$zip->close();
		}

		throw new Exception('Unhandled step'); // unreachable
	}

	// ── ZIP helpers ────────────────────────────────────────────────────────

	/**
	 * Find a file by basename anywhere inside the ZIP (handles nested paths like sde/fsd/file.yaml).
	 */
	private static function find_entry(ZipArchive $zip, string $filename): ?string {
		$n = $zip->numFiles;
		for ($i = 0; $i < $n; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name !== false && basename($name) === $filename) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * Open a ZIP entry as a readable stream. Throws on failure.
	 *
	 * @return resource
	 */
	private static function open_stream(ZipArchive $zip, string $entry) {
		$stream = $zip->getStream($entry);
		if ($stream === false) {
			throw new Exception('Failed to open ZIP entry: ' . esc_html($entry));
		}
		return $stream;
	}

	// ── Batched-insert helper ──────────────────────────────────────────────

	/**
	 * Commit a batch of rows and clear it.
	 *
	 * @param \PDOStatement $stmt
	 * @param array[]       $batch  Array of parameter arrays, passed by reference.
	 * @param int           $count  Row counter, passed by reference.
	 */
	private static function flush_batch(PDO $pdo, \PDOStatement $stmt, array &$batch, int &$count): void {
		if (empty($batch)) return;
		$pdo->beginTransaction();
		try {
			foreach ($batch as $row) {
				$stmt->execute($row);
				$count++;
			}
			$pdo->commit();
		} catch (\PDOException $e) {
			$pdo->rollBack();
			throw $e;
		}
		$batch = [];
	}

	// ── YAML string unquoter ───────────────────────────────────────────────

	/**
	 * Strip YAML quoting from a scalar value extracted from a line.
	 * Handles single-quoted, double-quoted, and plain scalars.
	 */
	private static function yaml_str(string $raw): string {
		$v = trim($raw);
		if ($v === '' || $v === '~' || $v === 'null') return '';

		// Single-quoted: '' inside is an escaped single quote.
		if (strlen($v) >= 2 && $v[0] === "'" && $v[-1] === "'") {
			return str_replace("''", "'", substr($v, 1, -1));
		}

		// Double-quoted: handle basic JSON-style escapes.
		if (strlen($v) >= 2 && $v[0] === '"' && $v[-1] === '"') {
			$inner = substr($v, 1, -1);
			return str_replace(
				['\\\\', '\\"', '\\n', '\\r', '\\t'],
				['\\',   '"',   "\n",  "\r",  "\t"],
				$inner
			);
		}

		return $v;
	}

	// ── marketGroups.yaml ─────────────────────────────────────────────────

	/**
	 * Parse marketGroups.yaml and populate ett_invMarketGroups.
	 *
	 * Top-level structure (each entry):
	 *   INT:
	 *     description:
	 *       en: text (may wrap to continuation line)
	 *     hasTypes: true|false
	 *     iconID: int
	 *     name:
	 *       en: text
	 *     parentGroupID: int   (optional)
	 */
	private static function import_market_groups(ZipArchive $zip, string $entry, PDO $pdo): int {
		$pdo->exec('TRUNCATE TABLE ett_invMarketGroups');

		$stmt = $pdo->prepare(
			'INSERT INTO ett_invMarketGroups (market_group_id, parent_group_id, name, description, has_types)
			 VALUES (:id,:pid,:name,:desc,:has)'
		);

		$stream = self::open_stream($zip, $entry);
		$count  = 0;
		$batch  = [];

		$cur_id  = null;
		$cur     = null;
		$section = null;  // current 2-indent section name
		$in_en   = false; // reading the English value for name/description

		$flush_cur = function () use (&$cur_id, &$cur, $pdo, $stmt, &$batch, &$count): void {
			if ($cur_id === null || $cur === null) return;
			$batch[] = [
				':id'   => $cur_id,
				':pid'  => $cur['parent_group_id'],
				':name' => $cur['name'],
				':desc' => $cur['description'],
				':has'  => $cur['has_types'],
			];
			if (count($batch) >= self::BATCH_SIZE) {
				self::flush_batch($pdo, $stmt, $batch, $count);
			}
		};

		try {
			while (($line = fgets($stream)) !== false) {
				$raw     = rtrim($line, "\r\n");
				$indent  = strlen($raw) - strlen(ltrim($raw, ' '));
				$content = ltrim($raw, ' ');

				if ($content === '') { $in_en = false; continue; }

				// 0-indent integer key → new entry
				if ($indent === 0 && preg_match('/^(\d+):/', $content, $m)) {
					$flush_cur();
					$cur_id  = (int) $m[1];
					$cur     = ['parent_group_id' => null, 'name' => '', 'description' => null, 'has_types' => 0];
					$section = null;
					$in_en   = false;
					continue;
				}

				if ($cur_id === null) continue;

				// 2-indent: direct scalar keys or section headers
				if ($indent === 2 && preg_match('/^(\w+):\s*(.*)$/', $content, $m)) {
					$key = $m[1];
					$val = trim($m[2]);
					$section = $key;
					$in_en   = false;
					if ($key === 'parentGroupID' && $val !== '') {
						$cur['parent_group_id'] = (int) $val;
					} elseif ($key === 'hasTypes' && $val !== '') {
						$cur['has_types'] = ($val === 'true') ? 1 : 0;
					}
					continue;
				}

				// 4-indent: language key inside name or description block
				if ($indent === 4 && preg_match('/^(\w+):\s*(.*)$/', $content, $m)) {
					$lang    = $m[1];
					$val_raw = $m[2];
					$in_en   = ($lang === 'en');
					if ($in_en) {
						$val = self::yaml_str($val_raw);
						if ($section === 'name') {
							$cur['name'] = $val;
						} elseif ($section === 'description') {
							$cur['description'] = ($val === '') ? null : $val;
						}
					}
					continue;
				}

				// Continuation line for a multi-line English value (e.g. long descriptions).
				// Detected when: we were reading an 'en' value and this line has more indent and no key: pattern.
				if ($in_en && $indent > 4 && !preg_match('/^\w[\w\d]*:/', $content)) {
					if ($section === 'name') {
						$cur['name'] .= ' ' . $content;
					} elseif ($section === 'description') {
						$cur['description'] = ($cur['description'] ?? '') . ' ' . $content;
					}
					continue;
				}

				// Any other structural line resets the en-continuation tracker
				if ($indent <= 4) $in_en = false;
			}

			$flush_cur();
			self::flush_batch($pdo, $stmt, $batch, $count);
		} finally {
			fclose($stream); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return $count;
	}

	// ── metaGroups.yaml ───────────────────────────────────────────────────

	/**
	 * Parse metaGroups.yaml and populate ett_invMetaGroups.
	 *
	 * Top-level structure:
	 *   INT:
	 *     name:
	 *       en: text
	 *     color: ...  (ignored)
	 *     iconID: ... (ignored)
	 */
	private static function import_meta_groups(ZipArchive $zip, string $entry, PDO $pdo): int {
		$pdo->exec('TRUNCATE TABLE ett_invMetaGroups');

		$stmt = $pdo->prepare(
			'INSERT INTO ett_invMetaGroups (meta_group_id, name, description) VALUES (:id,:name,:desc)'
		);

		$stream = self::open_stream($zip, $entry);
		$count  = 0;
		$batch  = [];

		$cur_id  = null;
		$cur     = null;
		$section = null;
		$in_en   = false;

		$flush_cur = function () use (&$cur_id, &$cur, $pdo, $stmt, &$batch, &$count): void {
			if ($cur_id === null || $cur === null) return;
			$batch[] = [':id' => $cur_id, ':name' => $cur['name'], ':desc' => $cur['description']];
			if (count($batch) >= self::BATCH_SIZE) {
				self::flush_batch($pdo, $stmt, $batch, $count);
			}
		};

		try {
			while (($line = fgets($stream)) !== false) {
				$raw     = rtrim($line, "\r\n");
				$indent  = strlen($raw) - strlen(ltrim($raw, ' '));
				$content = ltrim($raw, ' ');

				if ($content === '') { $in_en = false; continue; }

				if ($indent === 0 && preg_match('/^(\d+):/', $content, $m)) {
					$flush_cur();
					$cur_id  = (int) $m[1];
					$cur     = ['name' => '', 'description' => null];
					$section = null;
					$in_en   = false;
					continue;
				}

				if ($cur_id === null) continue;

				if ($indent === 2 && preg_match('/^(\w+):\s*(.*)$/', $content, $m)) {
					$section = $m[1];
					$in_en   = false;
					continue;
				}

				if ($indent === 4 && preg_match('/^(\w+):\s*(.*)$/', $content, $m)) {
					$lang  = $m[1];
					$in_en = ($lang === 'en');
					if ($in_en) {
						$val = self::yaml_str($m[2]);
						if ($section === 'name') $cur['name'] = $val;
						elseif ($section === 'description') $cur['description'] = $val ?: null;
					}
					continue;
				}

				if ($in_en && $indent > 4 && !preg_match('/^\w[\w\d]*:/', $content)) {
					if ($section === 'name') $cur['name'] .= ' ' . $content;
					elseif ($section === 'description') $cur['description'] = ($cur['description'] ?? '') . ' ' . $content;
					continue;
				}

				if ($indent <= 4) $in_en = false;
			}

			$flush_cur();
			self::flush_batch($pdo, $stmt, $batch, $count);
		} finally {
			fclose($stream); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return $count;
	}

	// ── types.yaml ────────────────────────────────────────────────────────

	/**
	 * Parse types.yaml and populate BOTH ett_invTypes and ett_invMetaTypes.
	 *
	 * Every type entry has metaGroupID directly as a 2-indent field, so there is
	 * no separate invMetaTypes source file in the SDE — we extract both in one pass.
	 *
	 * Relevant fields per entry:
	 *   INT:
	 *     marketGroupID: int   (optional)
	 *     metaGroupID:   int   (optional → invMetaTypes row)
	 *     name:
	 *       en: text
	 *     portionSize:   int   (optional, default 1)
	 *     published:     true|false
	 *
	 * @return array{types:int,meta_types:int}
	 */
	private static function import_types(ZipArchive $zip, string $entry, PDO $pdo): array {
		$pdo->exec('TRUNCATE TABLE ett_invTypes');
		$pdo->exec('TRUNCATE TABLE ett_invMetaTypes');

		$stmt_type = $pdo->prepare(
			'INSERT INTO ett_invTypes (type_id, name, market_group_id, published, portionSize)
			 VALUES (:id,:name,:mg,:pub,:portion)'
		);
		$stmt_meta = $pdo->prepare(
			'INSERT INTO ett_invMetaTypes (type_id, meta_group_id) VALUES (:tid,:mgid)'
		);

		$stream = self::open_stream($zip, $entry);

		$type_count = 0;
		$meta_count = 0;
		$type_batch = [];
		$meta_batch = [];

		$cur_id  = null;
		$cur     = null;
		$section = null;
		$in_en   = false;

		$flush_cur = function () use (
			&$cur_id, &$cur, $pdo,
			$stmt_type, $stmt_meta,
			&$type_batch, &$meta_batch,
			&$type_count, &$meta_count
		): void {
			if ($cur_id === null || $cur === null) return;

			$type_batch[] = [
				':id'      => $cur_id,
				':name'    => $cur['name'],
				':mg'      => $cur['market_group_id'],
				':pub'     => $cur['published'],
				':portion' => $cur['portion_size'],
			];

			if ($cur['meta_group_id'] !== null) {
				$meta_batch[] = [':tid' => $cur_id, ':mgid' => $cur['meta_group_id']];
			}

			// Flush when either batch reaches the threshold.
			if (count($type_batch) >= self::BATCH_SIZE) {
				$pdo->beginTransaction();
				try {
					foreach ($type_batch as $row) { $stmt_type->execute($row); $type_count++; }
					foreach ($meta_batch as $row) { $stmt_meta->execute($row); $meta_count++; }
					$pdo->commit();
				} catch (\PDOException $e) {
					$pdo->rollBack();
					throw $e;
				}
				$type_batch = [];
				$meta_batch = [];
			}
		};

		try {
			while (($line = fgets($stream)) !== false) {
				$raw     = rtrim($line, "\r\n");
				$indent  = strlen($raw) - strlen(ltrim($raw, ' '));
				$content = ltrim($raw, ' ');

				if ($content === '') { $in_en = false; continue; }

				// New top-level type
				if ($indent === 0 && preg_match('/^(\d+):/', $content, $m)) {
					$flush_cur();
					$cur_id  = (int) $m[1];
					$cur     = [
						'name'            => '',
						'market_group_id' => null,
						'published'       => 1,
						'portion_size'    => 1,
						'meta_group_id'   => null,
					];
					$section = null;
					$in_en   = false;
					continue;
				}

				if ($cur_id === null) continue;

				// 2-indent: scalar fields or section headers
				if ($indent === 2 && preg_match('/^(\w+):\s*(.*)$/', $content, $m)) {
					$key = $m[1];
					$val = trim($m[2]);
					$section = $key;
					$in_en   = false;

					switch ($key) {
						case 'marketGroupID':
							if ($val !== '') $cur['market_group_id'] = (int) $val;
							break;
						case 'metaGroupID':
							if ($val !== '') $cur['meta_group_id'] = (int) $val;
							break;
						case 'portionSize':
							if ($val !== '') $cur['portion_size'] = max(1, (int) $val);
							break;
						case 'published':
							$cur['published'] = ($val === 'true' || $val === '1') ? 1 : 0;
							break;
					}
					continue;
				}

				// Skip block-scalar content (descriptions use | or > — we don't store them).
				// A block scalar marker on the value position means subsequent lines at
				// greater indent are content; we ignore them until a new 2-indent key.
				if ($section === 'description') continue;

				// 4-indent: language keys inside the 'name' section
				if ($section === 'name' && $indent === 4 && preg_match('/^(\w+):\s*(.*)$/', $content, $m)) {
					$lang  = $m[1];
					$in_en = ($lang === 'en');
					if ($in_en) $cur['name'] = self::yaml_str(trim($m[2]));
					continue;
				}

				// Continuation of a multi-line English name (rare but handled)
				if ($section === 'name' && $in_en && $indent > 4 && !preg_match('/^\w[\w\d]*:/', $content)) {
					$cur['name'] .= ' ' . $content;
					continue;
				}

				if ($indent <= 4) $in_en = false;
			}

			$flush_cur();

			// Final partial batches
			if (!empty($type_batch)) {
				$pdo->beginTransaction();
				try {
					foreach ($type_batch as $row) { $stmt_type->execute($row); $type_count++; }
					foreach ($meta_batch as $row) { $stmt_meta->execute($row); $meta_count++; }
					$pdo->commit();
				} catch (\PDOException $e) {
					$pdo->rollBack();
					throw $e;
				}
			}
		} finally {
			fclose($stream); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return ['types' => $type_count, 'meta_types' => $meta_count];
	}

	// ── typeMaterials.yaml ────────────────────────────────────────────────

	/**
	 * Parse typeMaterials.yaml and populate ett_invTypeMaterials.
	 *
	 * Structure:
	 *   INT:           ← type_id
	 *     materials:
	 *     - materialTypeID: int
	 *       quantity:       int
	 */
	private static function import_type_materials(ZipArchive $zip, string $entry, PDO $pdo): int {
		$pdo->exec('TRUNCATE TABLE ett_invTypeMaterials');

		$stmt = $pdo->prepare(
			'INSERT INTO ett_invTypeMaterials (type_id, material_type_id, quantity)
			 VALUES (:tid,:mid,:qty)'
		);

		$stream = self::open_stream($zip, $entry);
		$count  = 0;
		$batch  = [];

		$cur_type_id = null;
		$cur_mat_id  = null;
		$cur_qty     = null;

		// Commit a complete material row (type+mat+qty all known and > 0).
		$flush_mat = function () use (&$cur_type_id, &$cur_mat_id, &$cur_qty, $pdo, $stmt, &$batch, &$count): void {
			if ($cur_type_id !== null && $cur_mat_id !== null && $cur_qty !== null && $cur_qty > 0) {
				$batch[] = [':tid' => $cur_type_id, ':mid' => $cur_mat_id, ':qty' => $cur_qty];
				if (count($batch) >= self::BATCH_SIZE) {
					self::flush_batch($pdo, $stmt, $batch, $count);
				}
			}
			$cur_mat_id = null;
			$cur_qty    = null;
		};

		try {
			while (($line = fgets($stream)) !== false) {
				$raw     = rtrim($line, "\r\n");
				$indent  = strlen($raw) - strlen(ltrim($raw, ' '));
				$content = ltrim($raw, ' ');

				if ($content === '') continue;

				// New type (0-indent digit key)
				if ($indent === 0 && preg_match('/^(\d+):/', $content, $m)) {
					$flush_mat();
					$cur_type_id = (int) $m[1];
					continue;
				}

				if ($cur_type_id === null) continue;

				// `  materials:` section header — nothing to extract
				if ($indent === 2 && rtrim($content) === 'materials:') continue;

				// `  - materialTypeID: N` — start of a new list item
				if ($indent === 2 && str_starts_with($content, '- ')) {
					$flush_mat();
					$rest = ltrim(substr($content, 2));
					if (preg_match('/^materialTypeID:\s*(\d+)/', $rest, $m)) {
						$cur_mat_id = (int) $m[1];
					} elseif (preg_match('/^typeID:\s*(\d+)/', $rest, $m)) {
						// Some SDE versions use typeID instead of materialTypeID
						$cur_mat_id = (int) $m[1];
					}
					continue;
				}

				// `    materialTypeID: N` or `    typeID: N` — inside a list item (alt ordering)
				if ($indent === 4 && $cur_mat_id === null && preg_match('/^(?:materialTypeID|typeID):\s*(\d+)/', $content, $m)) {
					$cur_mat_id = (int) $m[1];
					continue;
				}

				// `    quantity: N`
				if ($indent === 4 && preg_match('/^quantity:\s*(\d+)/', $content, $m)) {
					$cur_qty = (int) $m[1];
					continue;
				}
			}

			$flush_mat();
			self::flush_batch($pdo, $stmt, $batch, $count);
		} finally {
			fclose($stream); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return $count;
	}

	// ── blueprints.yaml ───────────────────────────────────────────────────

	/**
	 * Parse blueprints.yaml and populate ett_industryActivityProducts
	 * with the product typeID of every manufacturing activity (activityID = 1).
	 *
	 * Relevant structure (much of the file is ignored):
	 *   INT:                        ← blueprint type ID (not stored)
	 *     activities:
	 *       manufacturing:
	 *         products:
	 *         - quantity: 1
	 *           typeID: 817         ← this is what we need
	 *
	 * Uses INSERT IGNORE since the same product typeID can appear in multiple
	 * blueprint variants (e.g. original + copy).
	 *
	 * Also populates ett_blueprint_products (blueprint_type_id ->
	 * product_type_id) in the same pass, for the contract-price feature —
	 * a separate table from ett_industryActivityProducts above, which stays
	 * completely untouched here (still just an existence check other code
	 * already relies on via a LEFT JOIN on product_type_id alone).
	 */
	private static function import_blueprints(ZipArchive $zip, string $entry, PDO $pdo): int {
		$pdo->exec('TRUNCATE TABLE ett_industryActivityProducts');
		$pdo->exec('TRUNCATE TABLE ett_blueprint_products');

		$stmt = $pdo->prepare(
			'INSERT IGNORE INTO ett_industryActivityProducts (product_type_id) VALUES (:pid)'
		);
		$stmt_bp = $pdo->prepare(
			'INSERT IGNORE INTO ett_blueprint_products (blueprint_type_id, product_type_id) VALUES (:bpid, :pid)'
		);

		$stream = self::open_stream($zip, $entry);
		$count  = 0;
		$batch  = [];
		$batch_bp = [];

		/**
		 * Depth tracker:
		 *   0 = top-level / unknown
		 *   1 = inside activities:
		 *   2 = inside activities.manufacturing:
		 *   3 = inside activities.manufacturing.products:
		 */
		$depth = 0;
		$current_blueprint_id = 0;

		try {
			while (($line = fgets($stream)) !== false) {
				$raw     = rtrim($line, "\r\n");
				$indent  = strlen($raw) - strlen(ltrim($raw, ' '));
				$content = ltrim($raw, ' ');

				if ($content === '') continue;

				// New blueprint (0-indent) — the line itself is the
				// blueprint's own type ID, e.g. "681:". Captured here so
				// any product found deeper in this block can be paired
				// with it; ett_industryActivityProducts never needed this,
				// which is why it was previously discarded entirely.
				if ($indent === 0) {
					$depth = 0;
					$current_blueprint_id = 0;
					if (preg_match('/^(\d+):/', $content, $bm)) {
						$current_blueprint_id = (int) $bm[1];
					}
					continue;
				}

				// 2-indent: direct children of blueprint
				if ($indent === 2) {
					$depth = (rtrim($content) === 'activities:') ? 1 : 0;
					continue;
				}

				if ($depth < 1) continue;

				// 4-indent: activity names
				if ($indent === 4) {
					$depth = (rtrim($content) === 'manufacturing:') ? 2 : 1;
					continue;
				}

				if ($depth < 2) continue;

				// 6-indent: sub-sections of manufacturing
				if ($indent === 6) {
					if (rtrim($content) === 'products:') {
						$depth = 3;
					} elseif (!str_starts_with($content, '- ')) {
						// Any non-list key (materials:, skills:, time:) exits products context
						$depth = 2;
					}
					// List items (- ...) within products stay at depth 3 (no change)
					continue;
				}

				if ($depth < 3) continue;

				// Within products: any line containing typeID extracts the product.
				// Handles both `- typeID: N` (indent=6) and `  typeID: N` (indent=8).
				// We strip a leading `- ` before matching.
				$trimmed = ltrim($content, '-');
				$trimmed = ltrim($trimmed);
				if (preg_match('/^typeID:\s*(\d+)/', $trimmed, $m)) {
					$pid = (int) $m[1];
					if ($pid > 0) {
						$batch[] = [':pid' => $pid];
						if (count($batch) >= self::BATCH_SIZE) {
							self::flush_batch($pdo, $stmt, $batch, $count);
						}
						if ($current_blueprint_id > 0) {
							$batch_bp[] = [':bpid' => $current_blueprint_id, ':pid' => $pid];
							if (count($batch_bp) >= self::BATCH_SIZE) {
								$bp_count = 0;
								self::flush_batch($pdo, $stmt_bp, $batch_bp, $bp_count);
							}
						}
					}
				}
			}

			self::flush_batch($pdo, $stmt, $batch, $count);
			$bp_count = 0;
			self::flush_batch($pdo, $stmt_bp, $batch_bp, $bp_count);
		} finally {
			fclose($stream); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return $count;
	}

	// ── mapSolarSystems.yaml ──────────────────────────────────────────────

	/**
	 * Parse mapSolarSystems.yaml and populate ett_mapSolarSystems.
	 *
	 * Top-level structure (key = solarSystemID):
	 *   30000001:
	 *     name:
	 *       en: Tanoo
	 *     regionID: 10000001
	 *     constellationID: ...  (ignored)
	 *     ...
	 */
	private static function import_solar_systems(ZipArchive $zip, string $entry, PDO $pdo): int {
		$pdo->exec('TRUNCATE TABLE ett_mapSolarSystems');

		$stmt = $pdo->prepare(
			'INSERT INTO ett_mapSolarSystems (solar_system_id, name, region_id)
			 VALUES (:id, :name, :rid)'
		);

		$stream = self::open_stream($zip, $entry);
		$count  = 0;
		$batch  = [];

		$cur_id   = null;
		$cur      = null;
		$section  = null;
		$in_en    = false;

		$flush_cur = function () use (&$cur_id, &$cur, $pdo, $stmt, &$batch, &$count): void {
			if ($cur_id === null || $cur === null) return;
			if ($cur['name'] === '' || $cur['region_id'] === 0) return;
			$batch[] = [
				':id'   => $cur_id,
				':name' => $cur['name'],
				':rid'  => $cur['region_id'],
			];
			if (count($batch) >= self::BATCH_SIZE) {
				self::flush_batch($pdo, $stmt, $batch, $count);
			}
		};

		try {
			while (($line = fgets($stream)) !== false) {
				$raw     = rtrim($line, "\r\n");
				$indent  = strlen($raw) - strlen(ltrim($raw, ' '));
				$content = ltrim($raw, ' ');

				if ($content === '') { $in_en = false; continue; }

				// 0-indent integer key → new solar system entry
				if ($indent === 0 && preg_match('/^(\d+):/', $content, $m)) {
					$flush_cur();
					$cur_id  = (int) $m[1];
					$cur     = ['name' => '', 'region_id' => 0];
					$section = null;
					$in_en   = false;
					continue;
				}

				if ($cur_id === null) continue;

				// 2-indent: direct scalar keys or section headers
				if ($indent === 2 && preg_match('/^(\w+):\s*(.*)$/', $content, $m)) {
					$key = $m[1];
					$val = trim($m[2]);
					$section = $key;
					$in_en   = false;
					if ($key === 'regionID' && $val !== '') {
						$cur['region_id'] = (int) $val;
					}
					continue;
				}

				// 4-indent: language key inside name block
				if ($indent === 4 && preg_match('/^(\w+):\s*(.*)$/', $content, $m)) {
					$lang    = $m[1];
					$val_raw = $m[2];
					$in_en   = ($lang === 'en');
					if ($in_en && $section === 'name') {
						$cur['name'] = self::yaml_str($val_raw);
					}
					continue;
				}

				if ($indent <= 4) $in_en = false;
			}

			$flush_cur();
			self::flush_batch($pdo, $stmt, $batch, $count);
		} finally {
			fclose($stream); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return $count;
	}
}
