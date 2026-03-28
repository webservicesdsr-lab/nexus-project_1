<?php
/**
 * KNX Push Worker — WP-CLI bridge
 * Registers `wp knx worker run` to process pending provider rows.
 */
if (!defined('ABSPATH')) exit;

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('knx worker', function($args, $assoc_args) {
        $sub = isset($args[0]) ? $args[0] : 'run';

        if ($sub === 'status') {
            global $wpdb;
            $table = knx_dn_table_name();
            $pending = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'"));
            $processing = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'processing'"));
            $failed = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'failed'"));
            $delivered = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'delivered'"));
            WP_CLI::line("KNX Worker Status:");
            WP_CLI::line("  pending: " . $pending);
            WP_CLI::line("  processing: " . $processing);
            WP_CLI::line("  failed: " . $failed);
            WP_CLI::line("  delivered: " . $delivered);
            return 0;
        }

        // Default: run
        // Lock to avoid concurrent runs
        if (get_transient('knx_dn_worker_lock')) {
            WP_CLI::log('knx worker: already running (lock present).');
            return 0;
        }
        set_transient('knx_dn_worker_lock', 1, 300); // 5 minutes

        try {
            if ($sub === 'cleanup') {
                global $wpdb;
                $table = knx_dn_table_name();

                // Failed rows cleanup
                $failed_days = intval(get_option('knx_dn_failed_retention_days', 30));
                $failed_before_sql = $wpdb->prepare("DATE_SUB(NOW(), INTERVAL %d DAY)", intval($failed_days));
                $failed_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND (COALESCE(sent_at, created_at) <= {$failed_before_sql})"));
                if ($failed_count === 0) {
                    WP_CLI::line("No failed rows older than {$failed_days} days.");
                } else {
                    $wpdb->query("DELETE FROM {$table} WHERE status = 'failed' AND (COALESCE(sent_at, created_at) <= {$failed_before_sql})");
                    WP_CLI::line("Deleted {$failed_count} failed rows older than {$failed_days} days.");
                }

                // Delivered rows cleanup
                $deliv_days = intval(get_option('knx_dn_delivered_retention_days', 90));
                $deliv_before_sql = $wpdb->prepare("DATE_SUB(NOW(), INTERVAL %d DAY)", intval($deliv_days));
                $deliv_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'delivered' AND (COALESCE(sent_at, created_at) <= {$deliv_before_sql})"));
                if ($deliv_count === 0) {
                    WP_CLI::line("No delivered rows older than {$deliv_days} days.");
                } else {
                    $wpdb->query("DELETE FROM {$table} WHERE status = 'delivered' AND (COALESCE(sent_at, created_at) <= {$deliv_before_sql})");
                    WP_CLI::line("Deleted {$deliv_count} delivered rows older than {$deliv_days} days.");
                }

                WP_CLI::success("Cleanup complete. (failed: {$failed_count}, delivered: {$deliv_count})");
                delete_transient('knx_dn_worker_lock');
                return 0;
            }

            if ($sub === 'backfill-prefs') {
                global $wpdb;
                $drivers_table = $wpdb->prefix . 'knx_drivers';
                $users = $wpdb->get_results("SELECT ID FROM {$wpdb->users}");
                $updated = 0;

                foreach ($users as $u) {
                    $uid = (int)$u->ID;
                    $device = get_user_meta($uid, 'knx_device_type', true);
                    $channel = get_user_meta($uid, 'knx_pref_channel', true);
                    $ntfy = get_user_meta($uid, 'knx_ntfy_id', true);
                    $soft = get_user_meta($uid, 'knx_soft_push_enabled', true);

                    $fields = [];
                    $values = [];

                    if ($device !== '') {
                        $fields[] = "device_type = %s";
                        $values[] = $device;
                    }
                    if ($channel !== '') {
                        $fields[] = "pref_channel = %s";
                        $values[] = $channel;
                    }
                    if ($ntfy !== '') {
                        $fields[] = "ntfy_id = %s";
                        $values[] = $ntfy;
                    }
                    if ($soft !== '') {
                        $fields[] = "app_installed = %d";
                        $values[] = ($soft === '1' || $soft === 1) ? 1 : 0;
                    }

                    if (empty($fields)) {
                        continue;
                    }

                    $driver_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$drivers_table} WHERE user_id = %d LIMIT 1",
                        $uid
                    ));
                    if (empty($driver_id)) {
                        continue;
                    }

                    $sql = "UPDATE {$drivers_table} SET " . implode(', ', $fields) . " WHERE id = %d";
                    $values[] = (int)$driver_id;
                    $wpdb->query($wpdb->prepare($sql, $values));
                    $updated++;
                }

                WP_CLI::success("Backfilled prefs for {$updated} driver rows.");
                delete_transient('knx_dn_worker_lock');
                return 0;
            }

            $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 20;
            $processed = knx_dn_worker_run($limit);
            WP_CLI::success("Processed {$processed} rows.");
            delete_transient('knx_dn_worker_lock');
            return 0;
        } catch (Exception $e) {
            delete_transient('knx_dn_worker_lock');
            WP_CLI::error('knx worker failed: ' . $e->getMessage());
            return 1;
        }
    });
}