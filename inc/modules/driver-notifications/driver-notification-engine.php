<?php
/**
 * ==========================================================
 * KNX Driver Notifications — Engine (Runtime Aligned)
 * ==========================================================
 * Canonical broadcast engine for driver notifications.
 *
 * Rules:
 * - Uses runtime-prefixed tables only via $wpdb->prefix . 'knx_*'
 * - Respects per-channel user switches:
 *   - knx_browser_push_enabled
 *   - knx_ntfy_enabled
 *   - knx_email_enabled
 * - Enqueues one row per enabled channel
 * - Does NOT assume legacy y05_ hardcoded names
 * - Does NOT use invalid status values outside schema enum
 *
 * Notification table enum:
 * - pending
 * - processing
 * - delivered
 * - failed
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

/**
 * Broadcast order_available notification to all active drivers in the order's city.
 *
 * @param int $order_id
 * @return array
 */
function knx_dn_broadcast_order_available($order_id) {
    global $wpdb;

    $order_id = (int) $order_id;

    $result = [
        'ok'            => true,
        'order_id'      => $order_id,
        'drivers_found' => 0,
        'enqueued'      => 0,
        'skipped'       => 0,
        'errors'        => [],
        'drivers'       => [],
    ];

    if (!function_exists('knx_dn_table_exists_live') || !knx_dn_table_exists_live()) {
        $result['ok'] = false;
        $result['errors'][] = 'notifications_table_missing';
        return $result;
    }

    if ($order_id <= 0) {
        $result['ok'] = false;
        $result['errors'][] = 'invalid_order_id';
        return $result;
    }

    $orders_table = $wpdb->prefix . 'knx_orders';

    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, city_id, hub_id, delivery_address, total
         FROM {$orders_table}
         WHERE id = %d
         LIMIT 1",
        $order_id
    ));

    if (!$order) {
        $result['ok'] = false;
        $result['errors'][] = 'order_not_found';
        return $result;
    }

    $city_id = (int) ($order->city_id ?? 0);
    if ($city_id <= 0) {
        $result['ok'] = false;
        $result['errors'][] = 'city_id_missing';
        return $result;
    }

    $hub_id = (int) ($order->hub_id ?? 0);

    $city_name = '';
    $cities_table = $wpdb->prefix . 'knx_cities';
    $city_row = $wpdb->get_row($wpdb->prepare(
        "SELECT name FROM {$cities_table} WHERE id = %d LIMIT 1",
        $city_id
    ));
    if ($city_row && isset($city_row->name)) {
        $city_name = (string) $city_row->name;
    }

    $hub_name = '';
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
    $result['drivers_found'] = is_array($drivers) ? count($drivers) : 0;

    if (empty($drivers)) {
        $result['errors'][] = 'no_drivers_in_city';
        return $result;
    }

    $event_type = 'order_available';
    $order_label = '#' . (string) $order_id;
    $order_total_formatted = '$' . number_format((float) ($order->total ?? 0), 2);
    $driver_ops_url = site_url('/driver-ops');

    foreach ($drivers as $driver) {
        $driver_id = isset($driver->id) ? (int) $driver->id : 0;
        $user_id   = isset($driver->user_id) ? (int) $driver->user_id : 0;

        if ($driver_id <= 0) {
            $result['skipped']++;
            continue;
        }

        $driver_name = isset($driver->full_name) && $driver->full_name !== ''
            ? (string) $driver->full_name
            : 'Driver';

        $driver_email = knx_dn_resolve_driver_email($driver);

        $driver_row = [
            'driver_id' => $driver_id,
            'user_id'   => $user_id,
            'channels'  => [],
        ];

        $browser_enabled = $user_id ? get_user_meta($user_id, 'knx_browser_push_enabled', true) : '';
        $browser_enabled = ($browser_enabled === '' || is_null($browser_enabled)) ? '1' : ($browser_enabled ? '1' : '0');

        $ntfy_enabled = $user_id ? get_user_meta($user_id, 'knx_ntfy_enabled', true) : '';
        $ntfy_enabled = ($ntfy_enabled === '' || is_null($ntfy_enabled)) ? '0' : ($ntfy_enabled ? '1' : '0');

        $email_enabled = $user_id ? get_user_meta($user_id, 'knx_email_enabled', true) : '';
        $email_enabled = ($email_enabled === '' || is_null($email_enabled)) ? '1' : ($email_enabled ? '1' : '0');

        $ntfy_id = $user_id ? get_user_meta($user_id, 'knx_ntfy_id', true) : '';

        // Browser push
        if ($browser_enabled === '1') {
            $browser_payload = [
                'title'      => 'New Order Available',
                'body'       => 'Order ' . $order_label . ' is available. Tap to view details.',
                'url'        => $driver_ops_url,
                'order_id'   => $order_id,
                'city_id'    => $city_id,
                'event_type' => $event_type,
            ];

            $inserted = false;
            if (function_exists('knx_dn_enqueue_soft_push_notification')) {
                $inserted = knx_dn_enqueue_soft_push_notification($driver_id, $city_id, $browser_payload);
            } elseif (function_exists('knx_dn_insert_notification')) {
                $clean_payload = function_exists('knx_dn_clean_payload_for_soft_push')
                    ? knx_dn_clean_payload_for_soft_push($browser_payload)
                    : $browser_payload;

                if (!empty($clean_payload)) {
                    $inserted = knx_dn_insert_notification([
                        'order_id'     => $order_id,
                        'driver_id'    => $driver_id,
                        'city_id'      => $city_id,
                        'event_type'   => $event_type,
                        'channel'      => 'soft-push',
                        'payload_json' => wp_json_encode($clean_payload),
                        'status'       => 'pending',
                        'available_at' => null,
                    ]);
                }
            }

            if (!empty($inserted)) {
                $result['enqueued']++;
                $driver_row['channels'][] = 'soft-push';
            } else {
                $result['skipped']++;
            }
        }

        // Phone push (ntfy)
        if ($ntfy_enabled === '1' && !empty($ntfy_id) && function_exists('knx_dn_insert_notification')) {
            $ntfy_payload = [
                'title'      => 'New Order Available',
                'body'       => 'Order ' . $order_label . ' is available. Tap to view details.',
                'url'        => $driver_ops_url,
                'order_id'   => $order_id,
                'city_id'    => $city_id,
                'event_type' => $event_type,
            ];

            $inserted = knx_dn_insert_notification([
                'order_id'     => $order_id,
                'driver_id'    => $driver_id,
                'city_id'      => $city_id,
                'event_type'   => $event_type,
                'channel'      => 'ntfy',
                'payload_json' => wp_json_encode($ntfy_payload),
                'status'       => 'pending',
                'available_at' => null,
            ]);

            if (!empty($inserted)) {
                $result['enqueued']++;
                $driver_row['channels'][] = 'ntfy';
            } else {
                $result['skipped']++;
            }
        }

        // Email
        if ($email_enabled === '1' && $driver_email !== '' && function_exists('knx_dn_insert_notification')) {
            $email_payload = [
                'order_id'         => $order_id,
                'order_number'     => $order_label,
                'city_id'          => $city_id,
                'city_name'        => $city_name,
                'hub_id'           => $hub_id,
                'hub_name'         => $hub_name,
                'delivery_address' => (string) ($order->delivery_address ?? ''),
                'order_total'      => $order_total_formatted,
                'driver_id'        => $driver_id,
                'driver_name'      => $driver_name,
                'email'            => $driver_email,
                'title'            => 'New Order Available',
                'body'             => 'Order ' . $order_label . ' is available. Log in to review it.',
                'url'              => $driver_ops_url,
                'event_type'       => $event_type,
            ];

            $inserted = knx_dn_insert_notification([
                'order_id'     => $order_id,
                'driver_id'    => $driver_id,
                'city_id'      => $city_id,
                'event_type'   => $event_type,
                'channel'      => 'email',
                'payload_json' => wp_json_encode($email_payload),
                'status'       => 'pending',
                'available_at' => null,
            ]);

            if (!empty($inserted)) {
                $result['enqueued']++;
                $driver_row['channels'][] = 'email';
            } else {
                $result['skipped']++;
            }
        }

        $result['drivers'][] = $driver_row;
    }

    return $result;
}

/**
 * Dispatch a notification to a specific channel.
 *
 * @param string $channel
 * @param string $recipient
 * @param string $event_type
 * @param array  $template_vars
 * @return bool
 */
function knx_dn_dispatch($channel, $recipient, $event_type, $template_vars) {
    switch ($channel) {
        case 'email':
            if (!function_exists('knx_dn_render_email_template') || !function_exists('knx_dn_send_email')) {
                return false;
            }

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
 * Get active drivers for city.
 *
 * Runtime-aligned:
 * - knx_drivers
 * - knx_driver_cities pivot
 * - active check: status = 'active' OR is_active = 1 (defensive)
 * - deleted_at IS NULL
 *
 * The drivers table may have a varchar `status` column ('active'/'inactive')
 * or a tinyint `is_active` column (1/0), depending on the migration state.
 * This function detects the available column and filters accordingly,
 * matching the defensive pattern used by knx_ops_get_drivers.
 *
 * @param int $city_id
 * @return array
 */
function knx_dn_get_drivers_for_city($city_id) {
    global $wpdb;

    $city_id = (int) $city_id;
    if ($city_id <= 0) return [];

    $drivers_table       = $wpdb->prefix . 'knx_drivers';
    $driver_cities_table = $wpdb->prefix . 'knx_driver_cities';

    // ── Detect which active-status column exists at runtime ──
    $cols_raw = $wpdb->get_results("SHOW COLUMNS FROM {$drivers_table}", ARRAY_A);
    $col_names = is_array($cols_raw)
        ? array_map(function ($c) { return $c['Field']; }, $cols_raw)
        : [];

    $active_sql = '1=1'; // fail-open only if NO status column found (should not happen)
    if (in_array('status', $col_names, true)) {
        $active_sql = "d.status = 'active'";
    } elseif (in_array('is_active', $col_names, true)) {
        $active_sql = 'd.is_active = 1';
    } elseif (in_array('active', $col_names, true)) {
        $active_sql = 'd.active = 1';
    }

    // ── Detect user-link column (user_id vs driver_user_id) ──
    $user_col = 'user_id';
    if (in_array('driver_user_id', $col_names, true)) {
        $user_col = 'driver_user_id';
    }

    $deleted_sql = '';
    if (in_array('deleted_at', $col_names, true)) {
        $deleted_sql = 'AND d.deleted_at IS NULL';
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT d.id, d.{$user_col} AS user_id, d.full_name, d.phone, d.email
         FROM {$drivers_table} d
         INNER JOIN {$driver_cities_table} dc
            ON dc.driver_id = d.id
         WHERE dc.city_id = %d
           AND {$active_sql}
           {$deleted_sql}
         ORDER BY d.id ASC",
        $city_id
    ));

    if (!is_array($rows)) {
        return [];
    }

    return $rows;
}

/**
 * Resolve driver email.
 *
 * Prefers:
 * - d.email from driver row
 * - wp_users.user_email fallback
 *
 * @param object $driver
 * @return string
 */
function knx_dn_resolve_driver_email($driver) {
    if (isset($driver->email) && is_email((string) $driver->email)) {
        return (string) $driver->email;
    }

    $user_id = isset($driver->user_id) ? (int) $driver->user_id : 0;
    if ($user_id <= 0) {
        return '';
    }

    $wp_user = get_userdata($user_id);
    if ($wp_user && !empty($wp_user->user_email) && is_email((string) $wp_user->user_email)) {
        return (string) $wp_user->user_email;
    }

    return '';
}