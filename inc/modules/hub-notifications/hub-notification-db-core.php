<?php
/**
 * ==========================================================
 * KNX Hub Notifications — DB Core Layer
 * ==========================================================
 * Mirrors driver-notification-db-core.php for hub-scoped rows.
 *
 * Table: {prefix}_knx_hub_notifications
 * Schema is authoritative in nexus-schema-y05.sql.
 * This file contains NO runtime DDL.
 * If table does not exist → system fails closed.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

function knx_hn_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'knx_hub_notifications';
}

function knx_hn_table_exists_live() {
    global $wpdb;
    $table = knx_hn_table_name();
    $like  = $wpdb->esc_like($table);
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like));
    return ($found === $table);
}

/**
 * Auto-provision table if missing.
 * Called once per request, idempotent.
 */
function knx_hn_ensure_table() {
    if (knx_hn_table_exists_live()) return true;

    global $wpdb;
    $table   = knx_hn_table_name();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        `order_id` bigint UNSIGNED NOT NULL DEFAULT 0,
        `hub_id` bigint UNSIGNED NOT NULL,
        `event_type` varchar(50) NOT NULL,
        `channel` varchar(30) NOT NULL DEFAULT 'soft-push',
        `payload_json` JSON NOT NULL,
        `status` enum('pending','processing','delivered','failed') NOT NULL DEFAULT 'pending',
        `attempts` int UNSIGNED NOT NULL DEFAULT 0,
        `last_error` text DEFAULT NULL,
        `available_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `sent_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_hub_id` (`hub_id`),
        KEY `idx_channel_status_created` (`channel`,`status`,`created_at`),
        KEY `idx_hub_channel_status_created` (`hub_id`,`channel`,`status`,`created_at`),
        KEY `idx_hub_channel_status_available` (`hub_id`,`channel`,`status`,`available_at`)
    ) ENGINE=InnoDB {$charset};";

    $wpdb->query($sql);

    return knx_hn_table_exists_live();
}

/**
 * Insert a hub notification row.
 *
 * @param array $data
 * @return int|false  Inserted row ID or false on failure.
 */
function knx_hn_insert_notification($data) {
    global $wpdb;
    $table = knx_hn_table_name();

    $row = [
        'order_id'     => (int) ($data['order_id'] ?? 0),
        'hub_id'       => (int) ($data['hub_id'] ?? 0),
        'event_type'   => (string) ($data['event_type'] ?? ''),
        'channel'      => (string) ($data['channel'] ?? 'soft-push'),
        'payload_json' => (string) ($data['payload_json'] ?? '{}'),
        'status'       => (string) ($data['status'] ?? 'pending'),
        'attempts'     => 0,
        'created_at'   => current_time('mysql'),
    ];

    $formats = ['%d','%d','%s','%s','%s','%s','%d','%s'];

    $ok = $wpdb->insert($table, $row, $formats);
    if ($ok === false) return false;

    return (int) $wpdb->insert_id;
}

/**
 * Update notification status.
 *
 * @param int    $notification_id
 * @param string $status
 * @param bool   $set_sent_at
 * @return bool
 */
function knx_hn_update_status($notification_id, $status, $set_sent_at = false) {
    global $wpdb;
    $table = knx_hn_table_name();

    if ($set_sent_at) {
        return ($wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, sent_at = %s WHERE id = %d",
            (string) $status,
            current_time('mysql'),
            (int) $notification_id
        )) !== false);
    }

    return ($wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET status = %s WHERE id = %d",
        (string) $status,
        (int) $notification_id
    )) !== false);
}

/**
 * Increment attempt counter.
 */
function knx_hn_increment_attempts($notification_id) {
    global $wpdb;
    $table = knx_hn_table_name();
    return ($wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d",
        (int) $notification_id
    )) !== false);
}

/**
 * Set last error text.
 */
function knx_hn_set_last_error($notification_id, $error_text) {
    global $wpdb;
    $table = knx_hn_table_name();
    return ($wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET last_error = %s WHERE id = %d",
        (string) $error_text,
        (int) $notification_id
    )) !== false);
}

/**
 * Clear available_at (lease release).
 */
function knx_hn_clear_available_at($notification_id) {
    global $wpdb;
    $table = knx_hn_table_name();
    return ($wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET available_at = NULL WHERE id = %d",
        (int) $notification_id
    )) !== false);
}
