<?php
/**
 * KNX Push Worker
 * Provider worker that claims pending notification rows and processes them.
 *
 * Supported channels:
 * - ntfy
 * - email
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/push-sender.php';

/**
 * Process a single email notification row.
 *
 * @param object $row
 * @return array ['ok' => bool, 'error' => string|null]
 */
function knx_dn_push_process_email_row($row) {
    if (!function_exists('knx_dn_send_email_notification')) {
        return ['ok' => false, 'error' => 'email_provider_missing'];
    }

    $res = knx_dn_send_email_notification($row);

    if ($res === true) {
        return ['ok' => true, 'error' => null];
    }

    if (is_wp_error($res)) {
        return ['ok' => false, 'error' => $res->get_error_message()];
    }

    return ['ok' => false, 'error' => 'unknown_error'];
}

/**
 * Get the next pending row available to process.
 * Priority:
 * 1) ntfy
 * 2) email
 *
 * @return object|null
 */
function knx_dn_worker_get_next_row() {
    global $wpdb;

    if (!function_exists('knx_dn_table_exists_live') || !knx_dn_table_exists_live()) {
        return null;
    }

    $table = knx_dn_table_name();

    // First try ntfy
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, driver_id, channel, payload_json, status, available_at
         FROM {$table}
         WHERE channel = %s
           AND status = %s
           AND (available_at IS NULL OR available_at <= NOW())
         ORDER BY created_at ASC
         LIMIT 1",
        'ntfy',
        'pending'
    ));

    if ($row) {
        return $row;
    }

    // Then try email
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, driver_id, channel, payload_json, status, available_at
         FROM {$table}
         WHERE channel = %s
           AND status = %s
           AND (available_at IS NULL OR available_at <= NOW())
         ORDER BY created_at ASC
         LIMIT 1",
        'email',
        'pending'
    ));

    if ($row) {
        return $row;
    }

    return null;
}

/**
 * Process one available row.
 * Returns number of rows processed (0 or 1).
 *
 * @return int
 */
function knx_dn_worker_process_once() {
    global $wpdb;

    if (!function_exists('knx_dn_table_exists_live') || !knx_dn_table_exists_live()) {
        return 0;
    }

    $table = knx_dn_table_name();
    $row = knx_dn_worker_get_next_row();

    if (!$row || empty($row->id) || empty($row->channel)) {
        return 0;
    }

    $channel = (string) $row->channel;

    // Attempt to claim atomically: only set processing if still pending
    $updated = $wpdb->update(
        $table,
        ['status' => 'processing'],
        ['id' => (int) $row->id, 'status' => 'pending'],
        ['%s'],
        ['%d', '%s']
    );

    if ($updated === false || $updated === 0) {
        // Another worker claimed it
        return 0;
    }

    // Increment attempts
    if (function_exists('knx_dn_increment_attempts')) {
        knx_dn_increment_attempts((int) $row->id);
    }

    // ── Gate: skip dispatch if driver is no longer active ──
    // The driver may have been deactivated after the notification was enqueued.
    // Respect CRUD active/inactive status and mark row as failed immediately.
    if (!empty($row->driver_id) && function_exists('knx_dn_is_driver_active')) {
        if (!knx_dn_is_driver_active((int) $row->driver_id)) {
            if (function_exists('knx_dn_update_status')) {
                knx_dn_update_status((int) $row->id, 'failed', false);
            } else {
                $wpdb->update(
                    $table,
                    ['status' => 'failed'],
                    ['id' => (int) $row->id],
                    ['%s'],
                    ['%d']
                );
            }
            if (function_exists('knx_dn_set_last_error')) {
                knx_dn_set_last_error((int) $row->id, 'driver_inactive');
            }
            return 1; // consumed, not retried
        }
    }

    // Process by channel
    if ($channel === 'ntfy') {
        $result = knx_dn_push_process_ntfy_row($row);
    } elseif ($channel === 'email') {
        $result = knx_dn_push_process_email_row($row);
    } else {
        $result = ['ok' => false, 'error' => 'unsupported_channel'];
    }

    if (!empty($result['ok'])) {
        // Mark delivered and clear any backoff availability
        if (function_exists('knx_dn_update_status')) {
            knx_dn_update_status((int) $row->id, 'delivered', true);
        } else {
            $wpdb->update(
                $table,
                ['status' => 'delivered', 'sent_at' => current_time('mysql')],
                ['id' => (int) $row->id],
                ['%s', '%s'],
                ['%d']
            );
        }

        if (function_exists('knx_dn_clear_available_at')) {
            knx_dn_clear_available_at((int) $row->id);
        }

        return 1;
    }

    // Failure path
    $err = !empty($result['error'])
        ? (string) $result['error']
        : 'unknown';

    if (function_exists('knx_dn_set_last_error')) {
        knx_dn_set_last_error((int) $row->id, $err);
    } else {
        $wpdb->update(
            $table,
            ['last_error' => $err],
            ['id' => (int) $row->id],
            ['%s'],
            ['%d']
        );
    }

    $attempts = function_exists('knx_dn_get_attempts')
        ? knx_dn_get_attempts((int) $row->id)
        : null;

    $MAX_ATTEMPTS = intval(get_option('knx_dn_max_attempts', 3));
    $BACKOFF_BASE = intval(get_option('knx_dn_backoff_base_seconds', 30));
    $BACKOFF_MAX  = intval(get_option('knx_dn_backoff_max_seconds', 3600));

    if ($attempts === null) {
        if (function_exists('knx_dn_update_status')) {
            knx_dn_update_status((int) $row->id, 'failed', false);
        } else {
            $wpdb->update(
                $table,
                ['status' => 'failed'],
                ['id' => (int) $row->id],
                ['%s'],
                ['%d']
            );
        }

        if (function_exists('knx_dn_clear_available_at')) {
            knx_dn_clear_available_at((int) $row->id);
        }

        return 1;
    }

    if ($attempts >= $MAX_ATTEMPTS) {
        if (function_exists('knx_dn_update_status')) {
            knx_dn_update_status((int) $row->id, 'failed', false);
        } else {
            $wpdb->update(
                $table,
                ['status' => 'failed'],
                ['id' => (int) $row->id],
                ['%s'],
                ['%d']
            );
        }

        if (function_exists('knx_dn_clear_available_at')) {
            knx_dn_clear_available_at((int) $row->id);
        }

        return 1;
    }

    // Requeue with exponential backoff
    $exp = pow(2, max(0, $attempts - 1));
    $backoff_seconds = $BACKOFF_BASE * $exp;
    if ($backoff_seconds > $BACKOFF_MAX) {
        $backoff_seconds = $BACKOFF_MAX;
    }

    $available_ts = current_time('timestamp') + intval($backoff_seconds);
    $available_mysql = date('Y-m-d H:i:s', $available_ts);

    $wpdb->update(
        $table,
        ['status' => 'pending'],
        ['id' => (int) $row->id],
        ['%s'],
        ['%d']
    );

    if (function_exists('knx_dn_set_available_at')) {
        knx_dn_set_available_at((int) $row->id, $available_mysql);
    }

    return 1;
}

/**
 * Process up to $limit rows in a single run. Returns processed count.
 *
 * @param int $limit
 * @return int
 */
function knx_dn_worker_run($limit = 10) {
    $processed = 0;

    for ($i = 0; $i < $limit; $i++) {
        $did = knx_dn_worker_process_once();
        if ($did === 0) {
            break;
        }
        $processed += $did;
    }

    return $processed;
}

/**
 * Scheduled wrapper for WP-Cron.
 * Uses a transient lock to avoid concurrent executions.
 *
 * @param int $limit
 * @return int
 */
function knx_dn_worker_run_scheduled($limit = 10) {
    if (get_transient('knx_dn_worker_lock')) {
        return 0;
    }

    set_transient('knx_dn_worker_lock', 1, 300); // 5 minutes

    try {
        $count = knx_dn_worker_run($limit);
        delete_transient('knx_dn_worker_lock');
        return $count;
    } catch (Exception $e) {
        delete_transient('knx_dn_worker_lock');
        return 0;
    }
}

add_action('knx_dn_worker_cron', 'knx_dn_worker_run_scheduled');