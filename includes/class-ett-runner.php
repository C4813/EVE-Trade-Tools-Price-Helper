<?php
/**
 * ETT_Runner — Tick-based system cron runner.
 *
 * Designed for shared hosting where PHP execution time limits make it
 * impossible to complete a 10-20 minute price run in a single HTTP request.
 *
 * HOW IT WORKS
 * ─────────────
 * Instead of running to completion in one call, the runner does one batch of
 * work per request (matching your existing batch size / time settings) and
 * then returns.  Job state is persisted in the external DB between calls.
 *
 * Set your external cron (cron-job.org, server crontab, etc.) to call the
 * endpoint every minute.  The full run completes across many short requests,
 * each well within any host's PHP time limit:
 *
 *   https://example.com/?ett_ph_run=<token>     (every 1 minute)
 *
 * WHAT EACH CALL DOES
 * ────────────────────
 *  1. If a prices or history job is in progress → do one tick → return.
 *  2. If no job is running and a new run is due  → create job + first tick → return.
 *  3. If no job is running and nothing is due    → return immediately (no-op).
 *
 * "Due" means: (now - last_completed_at) >= freq_hours, mirroring the
 * existing WP-Cron schedule settings so both methods stay in sync.
 *
 * WP-CLI (server with shell access)
 * ────────────────────────────────────────────
 * The CLI command calls run_to_completion() which loops internally, so it
 * still finishes in one invocation.  See class-ett-cli.php.
 */
if (!defined('ABSPATH')) exit;

class ETT_Runner {

    const OPT_TOKEN           = 'ett_ph_runner_token';
    const MAX_RUN_SECONDS     = 3600; // safety ceiling for CLI run_to_completion()
    const MAX_REQUEST_SECONDS = 55;   // fallback ceiling per HTTP request when max_execution_time = 0

    // ── Token management ──────────────────────────────────────────────────

    public static function get_or_create_token() : string {
        $token = (string) get_option(self::OPT_TOKEN, '');
        if ($token === '') {
            $token = self::generate_token();
            update_option(self::OPT_TOKEN, $token, false);
        }
        return $token;
    }

    public static function regenerate_token() : string {
        $token = self::generate_token();
        update_option(self::OPT_TOKEN, $token, false);
        return $token;
    }

    private static function generate_token() : string {
        return bin2hex(random_bytes(24)); // 48-char hex
    }

    // ── HTTP endpoint ─────────────────────────────────────────────────────

    /**
     * Hooked to 'init' (priority 1).
     * Handles GET/POST /?ett_ph_run=<token>
     *
     * Each call performs ONE tick of work and returns JSON immediately.
     * Designed to be called every minute by an external cron service.
     */
    public static function maybe_handle_request() : void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- cron endpoint authenticated via secret token; nonce is impossible for external callers; see hash_equals() check below
        $token_param = isset( $_REQUEST['ett_ph_run'] )
            ? sanitize_text_field( wp_unslash( $_REQUEST['ett_ph_run'] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($token_param === '') return;

        $stored = (string) get_option(self::OPT_TOKEN, '');
        if ($stored === '' || !hash_equals($stored, $token_param)) {
            status_header(403);
            header('Content-Type: application/json');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo json_encode(['ok' => false, 'error' => 'Invalid token']);
            exit;
        }

        if (ob_get_level()) @ob_end_clean(); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
        @ini_set('zlib.output_compression', '0'); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- required to disable output compression for streaming JSON responses
        header('Content-Type: application/json');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-store');

        $result = self::tick();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Single-tick runner (HTTP path) ────────────────────────────────────

    /**
     * Called once per external cron hit.  Does exactly one batch of work.
     *
     * Decision tree:
     *   - Active job exists  → tick it
     *   - No active job, run is due → start prices job + first tick
     *   - No active job, nothing due → no-op
     */
    public static function tick() : array {
        if (!ETT_ExternalDB::is_configured()) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'External database not configured.'];
        }

        try {
            ETT_ExternalDB::ensure_schema();
            $pdo = ETT_ExternalDB::pdo();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'DB connection failed: ' . $e->getMessage()];
        }

        // ── Find any active job ───────────────────────────────────────────
        try {
            $stmt = $pdo->query("
                SELECT * FROM ett_jobs
                WHERE status IN ('queued','running')
                ORDER BY started_at ASC
                LIMIT 1
            ");
            $active_job = $stmt ? $stmt->fetch() : null;
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'DB query failed: ' . $e->getMessage()];
        }

        if ($active_job) {
            return self::tick_job($pdo, $active_job);
        }

        // ── No active job — is a new run due? ─────────────────────────────
        if (!self::is_run_due()) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'No run due yet.', 'debug' => self::get_due_debug()];
        }

        // ── Start a new prices job ────────────────────────────────────────
        try {
            $job_id = self::create_job($pdo, 'prices', 'system-cron');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not create job: ' . $e->getMessage()];
        }

        $stmt = $pdo->prepare('SELECT * FROM ett_jobs WHERE job_id = :id');
        $stmt->execute([':id' => $job_id]);
        $new_job = $stmt->fetch();

        return self::tick_job($pdo, $new_job);
    }

    /**
     * Work on the given job for as long as this HTTP request safely can.
     *
     * Rather than doing one batch and returning, we loop continuously until
     * we are close to the PHP execution time limit, then save state and exit.
     * The next external cron ping (≤ 1 minute away) picks up immediately,
     * so throughput matches the WP-Cron tick-chain and run times stay short.
     *
     * Safety margin: we stop 5 seconds before max_execution_time so PHP
     * never hard-kills the process mid-write.  On hosts that return 0
     * (unlimited) we cap at MAX_REQUEST_SECONDS as a sanity ceiling.
     */
    private static function tick_job(\PDO $pdo, $job) : array {
        if (!$job) {
            return ['ok' => false, 'error' => 'Job row missing.'];
        }

        $job_id   = (string) $job['job_id'];
        $job_type = (string) $job['job_type'];

        if (in_array($job['status'], ['done', 'error', 'cancelled'], true)) {
            return ['ok' => true, 'job_id' => $job_id, 'status' => $job['status'], 'skipped' => true];
        }

        // ── Concurrent-ping guard ─────────────────────────────────────────
        // If another request updated the heartbeat within the last 30 seconds,
        // a previous ping is still actively working this job — bow out to avoid
        // two processes writing to the same row simultaneously (deadlocks).
        $hb = $job['heartbeat_at'] ?? '';
        if ($hb) {
            $hb_ts = strtotime(str_replace(' ', 'T', $hb));
            if ($hb_ts && (time() - $hb_ts) < 30) {
                return ['ok' => true, 'job_id' => $job_id, 'skipped' => true, 'reason' => 'Another request is already working this job.'];
            }
        }

        $progress = json_decode($job['progress_json'] ?? '{}', true) ?: [];

        // ── Respect ESI backoff ───────────────────────────────────────────
        // If backoff is active at the start of this request, return immediately.
        // The next ping (within 1 minute) will retry once the backoff has expired.
        $sleep_until = (int)($progress['sleep_until'] ?? 0);
        if ($sleep_until > time()) {
            $wait = $sleep_until - time();
            return [
                'ok'       => true,
                'job_id'   => $job_id,
                'status'   => 'backoff',
                'retry_in' => $wait,
                'last_msg' => "ESI backoff active: {$wait}s remaining. Will resume next ping.",
            ];
        }

        // ── Work deadline ─────────────────────────────────────────────────
        // Use microtime(true) as the baseline — not REQUEST_TIME — because by
        // the time WordPress boots and we reach this point, several seconds of
        // the PHP time limit may already be used up. We measure from *now* and
        // use the remaining budget, capped at MAX_REQUEST_SECONDS as a ceiling.
        $max_exec      = (int) ini_get('max_execution_time');
        $now_float     = microtime(true);
        $safety_margin = 5;

        if ($max_exec > 0) {
            // How much of the time limit is left from this moment
            $elapsed          = $now_float - (float) sanitize_text_field( wp_unslash( $_SERVER['REQUEST_TIME_FLOAT'] ?? $now_float ) );
            $remaining        = max(5, $max_exec - $elapsed - $safety_margin);
            $request_deadline = $now_float + min($remaining, self::MAX_REQUEST_SECONDS);
        } else {
            $request_deadline = $now_float + self::MAX_REQUEST_SECONDS;
        }

        // ── Inner-batch limits (unchanged from WP-Cron path) ─────────────
        [$max_pages_per_batch, $max_batch_seconds] = self::get_batch_limits();

        try {
            self::update_status($pdo, $job_id, 'running');

            $total_pages = 0;
            $batches     = 0;

            // Outer loop: keep starting new batches until the request deadline
            while (microtime(true) < $request_deadline) {

                // Re-check backoff at the top of every batch (ESI may have set
                // it during the previous batch).
                $sleep_until = (int)($progress['sleep_until'] ?? 0);
                if ($sleep_until > time()) {
                    // Backoff is short enough to wait out within this request
                    $wait = $sleep_until - time();
                    if ($wait <= ($request_deadline - microtime(true) - $safety_margin)) {
                        sleep(max(1, $wait));
                        $progress['sleep_until'] = 0;
                    } else {
                        // Not enough time left this request — hand off to next ping
                        break;
                    }
                }

                // Inner batch loop
                $batch_deadline = microtime(true) + $max_batch_seconds;
                $pages_this_batch = 0;

                do {
                    if ($job_type === 'history') {
                        $progress = self::step_history($pdo, $progress);
                    } else {
                        $progress = self::step_prices($pdo, $progress, $job_id);
                    }

                    self::heartbeat($pdo, $job_id, $progress);
                    $pages_this_batch++;
                    $total_pages++;

                    if (($progress['phase'] ?? '') === 'done') break 2; // done — exit both loops

                    if ((int)($progress['sleep_until'] ?? 0) > time()) break; // backoff set mid-batch

                } while ($pages_this_batch < $max_pages_per_batch && microtime(true) < $batch_deadline);

                $batches++;
            }

            $phase = $progress['phase'] ?? '';

            if ($phase === 'done') {
                self::finish_job($pdo, $job_id, 'done', $progress);

                if ($job_type === 'prices') {
                    update_option('ett_last_price_run_completed_at', current_time('mysql'), false);
                    try {
                        self::create_job($pdo, 'history', 'system-cron');
                    } catch (\Throwable $e) { /* non-fatal */ }
                }

                return [
                    'ok'          => true,
                    'job_id'      => $job_id,
                    'job_type'    => $job_type,
                    'status'      => 'done',
                    'last_msg'    => $progress['last_msg'] ?? '',
                    'total_pages' => $total_pages,
                    'batches'     => $batches,
                ];
            }

            // Ran out of request time — state is saved, next ping continues
            return [
                'ok'          => true,
                'job_id'      => $job_id,
                'job_type'    => $job_type,
                'status'      => 'running',
                'phase'       => $phase,
                'last_msg'    => $progress['last_msg'] ?? '',
                'total_pages' => $total_pages,
                'batches'     => $batches,
            ];

        } catch (\Throwable $e) {
            try {
                $progress['phase']    = 'error';
                $progress['last_msg'] = 'Exception: ' . $e->getMessage();
                self::finish_job($pdo, $job_id, 'error', $progress, $e->getMessage());
            } catch (\Throwable $ignored) {}
            return ['ok' => false, 'error' => $e->getMessage(), 'job_id' => $job_id];
        }
    }

    // ── Schedule check ────────────────────────────────────────────────────

    /**
     * Returns true if enough time has passed since the last completed run,
     * based on the existing OPT_SCHED_FREQ_HOURS / OPT_SCHED_START_TIME settings.
     */
    private static function is_run_due() : bool {
        // Respect the pause/cancel schedule toggle
        if (get_option(ETT_Admin::OPT_SCHED_ENABLED, '1') === '0') {
            return false;
        }

        $freq_hours = (int) get_option(ETT_Admin::OPT_SCHED_FREQ_HOURS, 24);
        if ($freq_hours < 1) $freq_hours = 1;

        $start_time = (string) get_option(ETT_Admin::OPT_SCHED_START_TIME, '03:00');
        $tz         = wp_timezone();
        $now        = new DateTime('now', $tz);

        // Find the most recent scheduled slot at or before now.
        // Start from today's configured start time, then step back by freq_hours
        // until we land on or before the current time.
        $parts = explode(':', $start_time);
        $slot  = clone $now;
        $slot->setTime((int)($parts[0] ?? 3), (int)($parts[1] ?? 0), 0);

        while ($slot > $now) {
            $slot->modify("-{$freq_hours} hours");
        }

        // If we've never run, due as soon as the first slot has passed
        $last_raw = (string) get_option('ett_last_price_run_completed_at', '');
        if ($last_raw === '') {
            return true; // slot <= now is already guaranteed by the loop above
        }

        $last = DateTime::createFromFormat('Y-m-d H:i:s', $last_raw, $tz);
        if (!$last instanceof DateTime) return true;

        // Due if the last run happened before the most recent scheduled slot
        return $last < $slot;
    }

    public static function get_due_debug() : array {
        $freq_hours = (int) get_option(ETT_Admin::OPT_SCHED_FREQ_HOURS, 24);
        if ($freq_hours < 1) $freq_hours = 1;

        $start_time = (string) get_option(ETT_Admin::OPT_SCHED_START_TIME, '03:00');
        $last_raw   = (string) get_option('ett_last_price_run_completed_at', '');
        $tz         = wp_timezone();
        $now        = new DateTime('now', $tz);

        // Compute the most recent scheduled slot <= now
        $parts = explode(':', $start_time);
        $slot  = clone $now;
        $slot->setTime((int)($parts[0] ?? 3), (int)($parts[1] ?? 0), 0);
        while ($slot > $now) {
            $slot->modify("-{$freq_hours} hours");
        }

        // Next slot after now
        $next_slot = clone $slot;
        $next_slot->modify("+{$freq_hours} hours");

        $last = $last_raw !== ''
            ? DateTime::createFromFormat('Y-m-d H:i:s', $last_raw, $tz)
            : null;

        $due = ($last === null) ? true : ($last < $slot);

        return [
            'last_run'        => $last_raw !== '' ? $last_raw . ' (' . $tz->getName() . ')' : null,
            'last_slot'       => $slot->format('Y-m-d H:i:s') . ' (' . $tz->getName() . ')',
            'next_slot'       => $next_slot->format('Y-m-d H:i:s') . ' (' . $tz->getName() . ')',
            'freq_hours'      => $freq_hours,
            'start_time'      => $start_time,
            'now'             => $now->format('Y-m-d H:i:s') . ' (' . $tz->getName() . ')',
            'due'             => $due,
        ];
    }

    // ── CLI: run to completion (WP-CLI only, loops internally) ───────────

    public static function run_to_completion(?callable $logger = null) : array {
        $deadline = time() + self::MAX_RUN_SECONDS;

        if (!ETT_ExternalDB::is_configured()) {
            return ['ok' => false, 'error' => 'External database not configured.'];
        }

        try {
            ETT_ExternalDB::ensure_schema();
            $pdo = ETT_ExternalDB::pdo();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'DB connection failed: ' . $e->getMessage()];
        }

        try {
            $stmt   = $pdo->query("SELECT job_id FROM ett_jobs WHERE job_type='prices' AND status IN ('queued','running') ORDER BY started_at DESC LIMIT 1");
            $active = $stmt ? $stmt->fetchColumn() : false;
            if ($active) {
                return ['ok' => false, 'error' => "A prices job is already running ({$active}). Skipping."];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'DB query failed: ' . $e->getMessage()];
        }

        $prices_job_id = self::create_job($pdo, 'prices', 'system-cron');
        if ($logger) $logger("Started prices job: {$prices_job_id}");

        $prices_result = self::drive_to_done($pdo, $prices_job_id, $deadline, 'prices', $logger);
        if (!$prices_result['ok']) return $prices_result;

        update_option('ett_last_price_run_completed_at', current_time('mysql'), false);
        if ($logger) $logger('Prices complete. Starting history job…');

        $history_job_id = self::create_job($pdo, 'history', 'system-cron');
        if ($logger) $logger("Started history job: {$history_job_id}");

        $history_result = self::drive_to_done($pdo, $history_job_id, $deadline, 'history', $logger);

        return [
            'ok'             => true,
            'prices_job_id'  => $prices_job_id,
            'history_job_id' => $history_job_id,
            'prices'         => $prices_result,
            'history'        => $history_result,
        ];
    }

    private static function drive_to_done(\PDO $pdo, string $job_id, int $deadline, string $job_type, ?callable $logger) : array {
        try {
            self::update_status($pdo, $job_id, 'running');
            $s = $pdo->prepare('SELECT * FROM ett_jobs WHERE job_id = :id');
            $s->execute([':id' => $job_id]);
            $row      = $s->fetch();
            $progress = $row ? (json_decode($row['progress_json'], true) ?: []) : [];

            while (true) {
                if (time() >= $deadline) {
                    $progress['last_msg'] = 'Hard timeout reached.';
                    self::finish_job($pdo, $job_id, 'error', $progress, 'Hard timeout');
                    return ['ok' => false, 'error' => 'Hard timeout reached.', 'job_id' => $job_id];
                }

                if ($job_type === 'history') {
                    $progress = self::step_history($pdo, $progress);
                } else {
                    $progress = self::step_prices($pdo, $progress, $job_id);
                }

                self::heartbeat($pdo, $job_id, $progress);
                $phase = $progress['phase'] ?? '';

                if ($phase === 'done') {
                    self::finish_job($pdo, $job_id, 'done', $progress);
                    if ($logger) $logger(ucfirst($job_type) . ' done: ' . ($progress['last_msg'] ?? ''));
                    return ['ok' => true, 'job_id' => $job_id, 'last_msg' => $progress['last_msg'] ?? ''];
                }

                if ($phase === 'error') {
                    $err = $progress['last_msg'] ?? 'Unknown error';
                    self::finish_job($pdo, $job_id, 'error', $progress, $err);
                    return ['ok' => false, 'error' => $err, 'job_id' => $job_id];
                }

                $sleep_until = (int)($progress['sleep_until'] ?? 0);
                if ($sleep_until > time()) {
                    $wait = $sleep_until - time();
                    if ($logger) $logger("ESI backoff: sleeping {$wait}s…");
                    sleep(max(1, $wait));
                }
            }
        } catch (\Throwable $e) {
            try {
                $progress = isset($progress) && is_array($progress) ? $progress : [];
                $progress['phase']    = 'error';
                $progress['last_msg'] = 'Exception: ' . $e->getMessage();
                self::finish_job($pdo, $job_id, 'error', $progress, $e->getMessage());
            } catch (\Throwable $ignored) {}
            return ['ok' => false, 'error' => $e->getMessage(), 'job_id' => $job_id];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private static function step_prices(\PDO $pdo, array $progress, string $job_id) : array {
        static $m = null;
        if ($m === null) { $m = new \ReflectionMethod(ETT_Jobs::class, 'step_prices'); $m->setAccessible(true); }
        return $m->invoke(null, $pdo, $progress, $job_id);
    }

    private static function step_history(\PDO $pdo, array $progress) : array {
        static $m = null;
        if ($m === null) { $m = new \ReflectionMethod(ETT_Jobs::class, 'step_history'); $m->setAccessible(true); }
        return $m->invoke(null, $pdo, $progress);
    }

    private static function get_batch_limits() : array {
        static $m = null;
        if ($m === null) { $m = new \ReflectionMethod(ETT_Jobs::class, 'get_batch_limits'); $m->setAccessible(true); }
        return $m->invoke(null);
    }

    /**
     * Execute a callable that does DB writes, retrying up to $retries times
     * on deadlock (SQLSTATE 40001 / errno 1213).
     */
    private static function with_deadlock_retry(callable $fn, int $retries = 3) {
        $attempt = 0;
        while (true) {
            try {
                return $fn();
            } catch (\PDOException $e) {
                $code = $e->getCode();
                $msg  = $e->getMessage();
                $is_deadlock = ($code === '40001' || strpos($msg, '1213') !== false);
                if ($is_deadlock && $attempt < $retries) {
                    $attempt++;
                    usleep(50000 * $attempt); // 50ms, 100ms, 150ms back-off
                    continue;
                }
                throw $e;
            }
        }
    }

    private static function update_status(\PDO $pdo, string $job_id, string $status) : void {
        self::with_deadlock_retry(function() use ($pdo, $job_id, $status) {
            $s = $pdo->prepare('UPDATE ett_jobs SET status=:s WHERE job_id=:id');
            $s->execute([':s' => $status, ':id' => $job_id]);
        });
    }

    private static function heartbeat(\PDO $pdo, string $job_id, array $progress) : void {
        $now = current_time('mysql');
        $pj  = json_encode($progress, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        self::with_deadlock_retry(function() use ($pdo, $job_id, $pj, $now) {
            $s = $pdo->prepare('UPDATE ett_jobs SET progress_json=:pj, heartbeat_at=:hb WHERE job_id=:id');
            $s->execute([':pj' => $pj === false ? '{}' : $pj, ':hb' => $now, ':id' => $job_id]);
        });
    }

    private static function finish_job(\PDO $pdo, string $job_id, string $status, array $progress, ?string $err = null) : void {
        self::with_deadlock_retry(function() use ($pdo, $job_id, $status, $progress, $err) {
            static $m = null;
            if ($m === null) { $m = new \ReflectionMethod(ETT_Jobs::class, 'finish'); $m->setAccessible(true); }
            $m->invoke(null, $pdo, $job_id, $status, $progress, $err);
        });
    }

    private static function create_job(\PDO $pdo, string $job_type, string $driver) : string {
        static $m = null;
        if ($m === null) { $m = new \ReflectionMethod(ETT_Jobs::class, 'create_job'); $m->setAccessible(true); }
        return $m->invoke(null, $pdo, $job_type, $driver);
    }

    // ── AJAX ──────────────────────────────────────────────────────────────

    public static function init_ajax() : void {
        add_action('wp_ajax_ett_runner_regen_token', [__CLASS__, 'ajax_regen_token']);
    }

    public static function ajax_regen_token() : void {
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);
        check_ajax_referer('ett_admin');
        $token = self::regenerate_token();
        wp_send_json_success(['token' => $token]);
    }
}
