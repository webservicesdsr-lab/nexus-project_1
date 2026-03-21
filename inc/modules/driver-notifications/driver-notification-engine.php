<?php
/**
 * ==========================================================
 * KNX Driver Notifications — Engine (Schema-Aligned)
 * ==========================================================
 * Fully aligned to real DB schema:
 * - knx_drivers.status = 'active'
 * - knx_drivers.deleted_at IS NULL
 * - knx_driver_cities pivot
 *
 * No is_active column assumed.
 * No runtime DDL.
 * Fail-closed by design.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

/**
 * Broadcast order_available notification to all active drivers in the order's city.
 */
function knx_dn_broadcast_order_available($order_id) {

    $order_id = (int) $order_id;

    $result = [
        'sent'    => 0,
        'failed'  => 0,
        'skipped' => 0,
        'errors'  => [],
    ];

    if (!knx_dn_table_exists_live()) {
        $result['errors'][] = 'notifications_table_missing';
        return $result;
    }

    if ($order_id <= 0) {
        $result['errors'][] = 'invalid_order_id';
        return $result;
    }

    global $wpdb;

    $orders_table = $wpdb->prefix . 'knx_orders';

    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, order_number, city_id, hub_id, delivery_address, total
         FROM {$orders_table}
         WHERE id = %d
         LIMIT 1",
        $order_id
    ));

    if (!$order) {
        $result['errors'][] = 'order_not_found';
        return $result;
    }

    $city_id = (int) ($order->city_id ?? 0);
    if ($city_id <= 0) {
        $result['errors'][] = 'city_id_missing';
        return $result;
    }

    // Resolve city name
    $cities_table = $wpdb->prefix . 'knx_cities';
    $city_name = '';
    $city_row = $wpdb->get_row($wpdb->prepare(
        "SELECT name FROM {$cities_table} WHERE id = %d LIMIT 1",
        $city_id
    ));
    if ($city_row && isset($city_row->name)) {
        $city_name = (string) $city_row->name;
    }

    // Resolve hub name
    $hub_name = '';
    $hub_id = (int) ($order->hub_id ?? 0);
    if ($hub_id > 0) {
        $hubs_table = $wpdb->prefix . 'knx_hubs';
        $hub_row = $wpdb->get_row($wpdb->prepare(
            "SELECT name FROM {$hubs_table} WHERE id = %d LIMIT 1",
            $hub_id
        ));
        if ($hub_row && isset($hub_row->name)) {
            $hub_name = (string) $hub_row->name;
        }
    }

    $drivers = knx_dn_get_drivers_for_city($city_id);

    if (empty($drivers)) {
        $result['errors'][] = 'no_drivers_in_city';
        return $result;
    }

    $event_type = 'order_available';
    $channel    = 'email';
    $order_total_formatted = '$' . number_format((float) ($order->total ?? 0), 2);

    foreach ($drivers as $driver) {

        $driver_id = (int) ($driver->id ?? 0);
        if ($driver_id <= 0) {
            $result['skipped']++;
            continue;
        }

        if (knx_dn_check_idempotency($order_id, $driver_id, $event_type)) {
            $result['skipped']++;
            continue;
        }

        $driver_email = knx_dn_resolve_driver_email($driver);
        if ($driver_email === '') {
            $result['skipped']++;
            continue;
        }

        $driver_name = (string) ($driver->full_name ?? '');
        if ($driver_name === '') {
            $driver_name = 'Driver';
        }

        $payload = [
            'order_id'         => $order_id,
            'order_number'     => (string) ($order->order_number ?? ''),
            'city_id'          => $city_id,
            'city_name'        => $city_name,
            'hub_id'           => $hub_id,
            'hub_name'         => $hub_name,
            'delivery_address' => (string) ($order->delivery_address ?? ''),
            'order_total'      => $order_total_formatted,
            'driver_id'        => $driver_id,
            'driver_name'      => $driver_name,
            'driver_email'     => $driver_email,
            'channel'          => $channel,
        ];

        $payload_json = wp_json_encode($payload);

        $notification_id = knx_dn_insert_notification([
            'order_id'     => $order_id,
            'driver_id'    => $driver_id,
            'city_id'      => $city_id,
            'event_type'   => $event_type,
            'channel'      => $channel,
            'payload_json' => $payload_json,
            'status'       => 'pending',
        ]);

        if ($notification_id === false) {
            $result['skipped']++;
            continue;
        }

        $sent = knx_dn_dispatch($channel, $driver_email, $event_type, [
            'driver_name'      => $driver_name,
            'order_number'     => (string) ($order->order_number ?? ''),
            'hub_name'         => $hub_name,
            'city_name'        => $city_name,
            'delivery_address' => (string) ($order->delivery_address ?? ''),
            'order_total'      => $order_total_formatted,
            'dashboard_url'    => site_url('/driver-live-orders'),
        ]);

        knx_dn_increment_attempts($notification_id);

        if ($sent) {
            knx_dn_update_status($notification_id, 'sent', true);
            $result['sent']++;
        } else {
            knx_dn_update_status($notification_id, 'failed', false);
            $result['failed']++;
        }
    }

    return $result;
}

/**
 * Dispatch a notification to a specific channel.
 *
 * @param string $channel      'email' (future: 'push', 'sms')
 * @param string $recipient    Email address (or phone, or push token)
 * @param string $event_type   e.g. 'order_available'
 * @param array  $template_vars  Variables for the template renderer
 * @return bool  true on success
 */
function knx_dn_dispatch($channel, $recipient, $event_type, $template_vars) {
    switch ($channel) {
        case 'email':
            $email = knx_dn_render_email_template($event_type, $template_vars);
            if (empty($email) || empty($email['subject']) || empty($email['html'])) {
                return false;
            }
            return knx_dn_send_email($recipient, $email['subject'], $email['html']);

        default:
            return false;
    }
}

/**
 * Get active drivers for city (SCHEMA ALIGNED)
 */
function knx_dn_get_drivers_for_city($city_id) {

    global $wpdb;

    $city_id = (int) $city_id;
    if ($city_id <= 0) return [];

    $drivers_table       = $wpdb->prefix . 'knx_drivers';
    $driver_cities_table = $wpdb->prefix . 'knx_driver_cities';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT d.id, d.user_id, d.full_name, d.phone, d.email
         FROM {$drivers_table} d
         INNER JOIN {$driver_cities_table} dc
            ON dc.driver_id = d.id
         WHERE dc.city_id = %d
           AND d.status = 'active'
           AND d.deleted_at IS NULL
         ORDER BY d.id ASC",
        $city_id
    ));

    if (!is_array($rows)) return [];

    return $rows;
}

/**
 * Resolve driver email (schema aligned)
 */
function knx_dn_resolve_driver_email($driver) {

    if (isset($driver->email) && is_email((string) $driver->email)) {
        return (string) $driver->email;
    }

    $user_id = isset($driver->user_id) ? (int) $driver->user_id : 0;
    if ($user_id <= 0) return '';

    global $wpdb;
    $users_table = $wpdb->prefix . 'knx_users';

    $email = $wpdb->get_var($wpdb->prepare(
        "SELECT email FROM {$users_table} WHERE id = %d LIMIT 1",
        $user_id
    ));

    if (!empty($email) && is_email((string) $email)) {
        return (string) $email;
    }

    return '';
}