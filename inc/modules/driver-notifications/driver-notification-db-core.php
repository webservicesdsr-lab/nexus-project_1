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
