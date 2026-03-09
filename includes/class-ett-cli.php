<?php
/**
 * ETT_CLI — WP-CLI commands for ETT Price Helper.
 *
 * Only loaded when WP-CLI is active.  Provides:
 *
 *   wp ett-prices run             — run a full prices + history cycle
 *   wp ett-prices status          — show the last job status
 *   wp ett-prices regen-token     — regenerate the HTTP runner token
 *
 * Typical crontab entry (runs daily at 03:00 server time):
 *
 *   0 3 * * * /usr/local/bin/wp --path=/var/www/html ett-prices run --quiet >> /var/log/ett-prices.log 2>&1
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) return;

/**
 * Manage EVE Trade Tools price data imports.
 */
class ETT_CLI extends WP_CLI_Command {

    /**
     * Run a complete prices + history import cycle synchronously.
     *
     * Unlike the WP-Cron scheduler, this command blocks until the entire run
     * finishes, making it safe to call from a server crontab entry.  ESI
     * backoff periods are handled internally via real sleeps rather than
     * chaining cron events.
     *
     * ## OPTIONS
     *
     * [--quiet]
     * : Suppress per-step progress lines; only print final result.
     *
     * ## EXAMPLES
     *
     *   # Run interactively with progress output
     *   wp ett-prices run
     *
     *   # Silent mode for crontab (exit code signals success/failure)
     *   wp ett-prices run --quiet
     *
     * @when after_wp_load
     */
    public function run(array $args, array $assoc_args) : void {
        $quiet = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'quiet', false);

        $logger = $quiet
            ? null
            : function(string $msg) {
                \WP_CLI::log('[' . date('H:i:s') . '] ' . $msg); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            };

        if (!$quiet) \WP_CLI::log('Starting ETT price run…');

        $result = ETT_Runner::run_to_completion($logger);

        if ($result['ok']) {
            $msg = 'Run complete.';
            if (!empty($result['prices']['last_msg'])) {
                $msg .= ' Prices: ' . $result['prices']['last_msg'];
            }
            if (!empty($result['history']['last_msg'])) {
                $msg .= ' | History: ' . $result['history']['last_msg'];
            }
            \WP_CLI::success($msg);
        } else {
            \WP_CLI::error($result['error'] ?? 'Unknown error', false);
            exit(1);
        }
    }

    /**
     * Show the status of the most recent prices job.
     *
     * ## EXAMPLES
     *
     *   wp ett-prices status
     *
     * @when after_wp_load
     */
    public function status(array $args, array $assoc_args) : void {
        if (!ETT_ExternalDB::is_configured()) {
            \WP_CLI::error('External database is not configured.');
        }

        try {
            $pdo  = ETT_ExternalDB::pdo();
            $stmt = $pdo->query("
                SELECT job_id, status, started_at, finished_at, progress_json
                FROM ett_jobs
                WHERE job_type = 'prices'
                ORDER BY started_at DESC
                LIMIT 1
            ");
            $row = $stmt ? $stmt->fetch() : null;
        } catch (\Throwable $e) {
            \WP_CLI::error('DB error: ' . $e->getMessage());
        }

        if (!$row) {
            \WP_CLI::log('No prices jobs found.');
            return;
        }

        $prog    = json_decode($row['progress_json'] ?? '{}', true) ?: [];
        $last    = get_option('ett_last_price_run_completed_at', 'never');

        \WP_CLI\Utils\format_items('table', [[
            'job_id'      => $row['job_id'],
            'status'      => $row['status'],
            'started_at'  => $row['started_at'],
            'finished_at' => $row['finished_at'] ?? '—',
            'last_msg'    => $prog['last_msg'] ?? '—',
            'driver'      => $prog['driver']   ?? '—',
        ]], ['job_id', 'status', 'started_at', 'finished_at', 'last_msg', 'driver']);

        \WP_CLI::log("Last completed run: {$last}");
    }

    /**
     * Regenerate the HTTP runner secret token.
     *
     * After regenerating, update any server crontab curl commands with the
     * new token.  The old token is immediately invalidated.
     *
     * ## EXAMPLES
     *
     *   wp ett-prices regen-token
     *
     * @when after_wp_load
     */
    public function regen_token(array $args, array $assoc_args) : void {
        $token = ETT_Runner::regenerate_token();
        \WP_CLI::success('New token: ' . $token);
        \WP_CLI::log('Update your crontab curl command with the new token.');
    }
}

\WP_CLI::add_command('ett-prices', 'ETT_CLI');
