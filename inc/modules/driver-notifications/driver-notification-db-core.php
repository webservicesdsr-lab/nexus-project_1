<?php
/**
 * ==========================================================
 * KNX Driver Notifications — DB Core Layer
 * ==========================================================
 * Schema is authoritative in nexus-schema-y05.sql.
 * This file contains NO runtime DDL.
 * If table does not exist → system fails closed.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

function knx_dn_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'knx_driver_notifications';
}

function knx_dn_table_exists_live() {
    global $wpdb;
    $table = knx_dn_table_name();
    $like  = $wpdb->esc_like($table);
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like));
    return ($found === $table);
}

/**
 * Check whether a driver is currently active in the knx_drivers table.
 *
 * Defensive: supports `status` varchar ('active'/'inactive')
 * OR `is_active` tinyint (1/0) columns, matching the
 * defensive pattern used throughout the codebase.
 *
 * Used by the worker to skip dispatch for drivers that were
 * deactivated after their notification row was enqueued.
 *
 * @param int $driver_id  PK of knx_drivers
 * @return bool
 */
function knx_dn_is_driver_active($driver_id) {
    global $wpdb;

    $driver_id = (int) $driver_id;
    if ($driver_id <= 0) return false;

    $drivers_table = $wpdb->prefix . 'knx_drivers';

    $cols_raw = $wpdb->get_results("SHOW COLUMNS FROM {$drivers_table}", ARRAY_A);
    $col_names = is_array($cols_raw)
        ? array_map(function ($c) { return $c['Field']; }, $cols_raw)
        : [];

    // Build active-check SQL
    $active_sql = '1=1';
    if (in_array('status', $col_names, true)) {
        $active_sql = "status = 'active'";
    } elseif (in_array('is_active', $col_names, true)) {
        $active_sql = 'is_active = 1';
    } elseif (in_array('active', $col_names, true)) {
        $active_sql = 'active = 1';
    }

    $deleted_sql = '';
    if (in_array('deleted_at', $col_names, true)) {
        $deleted_sql = 'AND deleted_at IS NULL';
    }

    $found = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$drivers_table}
         WHERE id = %d
           AND {$active_sql}
           {$deleted_sql}
         LIMIT 1",
        $driver_id
    ));

    return !empty($found);
}

function knx_dn_check_idempotency($order_id, $driver_id, $event_type) {
    global $wpdb;
    $table = knx_dn_table_name();

    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table}
         WHERE order_id = %d
           AND driver_id = %d
           AND event_type = %s
         LIMIT 1",
        (int)$order_id,
        (int)$driver_id,
        (string)$event_type
    ));
}

function knx_dn_insert_notification($data) {
    global $wpdb;
    $table = knx_dn_table_name();

    $row = [
        'order_id'     => (int)$data['order_id'],
        'driver_id'    => (int)$data['driver_id'],
        'city_id'      => (int)$data['city_id'],
        'event_type'   => (string)$data['event_type'],
        'channel'      => (string)$data['channel'],
        'payload_json' => (string)$data['payload_json'],
        'status'       => (string)$data['status'],
        'attempts'     => 0,
        'created_at'   => current_time('mysql'),
    ];

    $formats = ['%d','%d','%d','%s','%s','%s','%s','%d','%s'];

    $ok = $wpdb->insert($table, $row, $formats);
    if ($ok === false) return false;

    return (int)$wpdb->insert_id;
}

function knx_dn_update_status($notification_id, $status, $set_sent_at = false) {
    global $wpdb;
    $table = knx_dn_table_name();

    if ($set_sent_at) {
        // Use raw SQL so sent_at gets a real datetime, not a quoted NULL
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

function knx_dn_increment_attempts($notification_id) {
    global $wpdb;
    $table = knx_dn_table_name();

    return ($wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET attempts = attempts + 1
         WHERE id = %d",
        (int)$notification_id
    )) !== false);
}

function knx_dn_get_attempts($notification_id) {
    global $wpdb;
    $table = knx_dn_table_name();
    $v = $wpdb->get_var($wpdb->prepare("SELECT attempts FROM {$table} WHERE id = %d LIMIT 1", (int)$notification_id));
    return ($v === null) ? 0 : (int)$v;
}

function knx_dn_set_last_error($notification_id, $error_text) {
    global $wpdb;
    $table = knx_dn_table_name();
    return ($wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET last_error = %s WHERE id = %d",
        (string)$error_text,
        (int)$notification_id
    )) !== false);
}

function knx_dn_set_available_at($notification_id, $datetime_mysql) {
    global $wpdb;
    $table = knx_dn_table_name();
    return ($wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET available_at = %s WHERE id = %d",
        (string)$datetime_mysql,
        (int)$notification_id
    )) !== false);
}

function knx_dn_clear_available_at($notification_id) {
    global $wpdb;
    $table = knx_dn_table_name();
    return ($wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET available_at = NULL WHERE id = %d",
        (int)$notification_id
    )) !== false);
}
