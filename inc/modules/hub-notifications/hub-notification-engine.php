<?php
/**
 * ==========================================================
 * KNX Hub Notifications — Engine
 * ==========================================================
 * Hub-scoped notification broadcast.
 *
 * Key design:
 * - Notifies ONLY the hub that owns the order (hub_id).
 * - Never notifies other hubs. Scope is 100% hub-isolated.
 * - Finds ALL managers of that hub via knx_hub_managers pivot.
 * - Per-hub notification settings via knx_hub_management_settings:
 *   knx_hub_{hub_id}_ntfy_enabled  → '0'/'1'
 *   knx_hub_{hub_id}_ntfy_topic    → string (ntfy topic)
 *   knx_hub_{hub_id}_email_enabled → '0'/'1'
 *   knx_hub_{hub_id}_email_to      → comma-separated emails
 * - Channels:
 *   1) soft-push (browser polling — one row per manager)
 *   2) ntfy (per-hub topic — one notification per hub)
 *   3) email (per-hub recipient list — one email per hub)
 *
 * Non-blocking: wrapped in try/catch at hook level.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

/**
 * Broadcast hub_new_order notification to the hub that owns the order.
 *
 * @param int $order_id
 * @return array
 */
function knx_hn_broadcast_hub_new_order($order_id) {
    global $wpdb;

    $order_id = (int) $order_id;

    $result = [
        'ok'       => true,
        'order_id' => $order_id,
        'hub_id'   => 0,
        'enqueued' => 0,
        'skipped'  => 0,
        'errors'   => [],
    ];

    if (!knx_hn_ensure_table()) {
        $result['ok'] = false;
        $result['errors'][] = 'hub_notifications_table_missing';
        return $result;
    }

    if ($order_id <= 0) {
        $result['ok'] = false;
        $result['errors'][] = 'invalid_order_id';
        return $result;
    }

    $orders_table = $wpdb->prefix . 'knx_orders';
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, hub_id, total, customer_name, fulfillment_type
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

    $hub_id = (int) ($order->hub_id ?? 0);
    $result['hub_id'] = $hub_id;

    if ($hub_id <= 0) {
        $result['ok'] = false;
        $result['errors'][] = 'hub_id_missing';
        return $result;
    }

    // ── Resolve hub name ──────────────────────────────────
    $hubs_table = $wpdb->prefix . 'knx_hubs';
    $hub_row = $wpdb->get_row($wpdb->prepare(
        "SELECT name FROM {$hubs_table} WHERE id = %d LIMIT 1",
        $hub_id
    ));
    $hub_name = ($hub_row && isset($hub_row->name)) ? (string) $hub_row->name : 'Hub #' . $hub_id;

    // ── Resolve items summary ─────────────────────────────
    $items_summary = '';
    $order_items_table = $wpdb->prefix . 'knx_order_items';
    $table_check = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $wpdb->esc_like($order_items_table)
    ));
    if ($table_check) {
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT name_snapshot, quantity FROM {$order_items_table} WHERE order_id = %d ORDER BY id ASC LIMIT 8",
            $order_id
        ));
        if ($items && is_array($items)) {
            $parts = [];
            foreach ($items as $item) {
                $qty  = (int) ($item->quantity ?? 1);
                $name = (string) ($item->name_snapshot ?? '');
                $parts[] = $qty . 'x ' . $name;
            }
            $items_summary = implode(', ', $parts);
        }
    }

    // ── Common payload ────────────────────────────────────
    $order_label = '#' . (string) $order_id;
    $order_total_formatted = '$' . number_format((float) ($order->total ?? 0), 2);
    $customer_name = (string) ($order->customer_name ?? 'Customer');
    $fulfillment_type = (string) ($order->fulfillment_type ?? 'delivery');
    $hub_orders_url = site_url('/hub-orders');

    // ── 1. Soft-Push: one row per hub manager ─────────────
    $managers_table = $wpdb->prefix . 'knx_hub_managers';
    $users_table    = $wpdb->prefix . 'knx_users';

    $managers = $wpdb->get_results($wpdb->prepare(
        "SELECT hm.user_id
         FROM {$managers_table} hm
         INNER JOIN {$users_table} u ON u.id = hm.user_id
         WHERE hm.hub_id = %d
           AND u.status = 'active'",
        $hub_id
    ));

    if ($managers && is_array($managers)) {
        foreach ($managers as $mgr) {
            $mgr_user_id = (int) $mgr->user_id;
            if ($mgr_user_id <= 0) continue;

            $push_payload = [
                'title'      => 'New Order ' . $order_label,
                'body'       => $customer_name . ' — ' . $order_total_formatted . ' (' . ucfirst($fulfillment_type) . ')',
                'url'        => $hub_orders_url,
                'order_id'   => $order_id,
                'hub_id'     => $hub_id,
                'user_id'    => $mgr_user_id,
                'event_type' => 'hub_new_order',
            ];

            $inserted = knx_hn_insert_notification([
                'order_id'     => $order_id,
                'hub_id'       => $hub_id,
                'event_type'   => 'hub_new_order',
                'channel'      => 'soft-push',
                'payload_json' => wp_json_encode($push_payload),
                'status'       => 'pending',
            ]);

            if ($inserted) {
                $result['enqueued']++;
            } else {
                $result['skipped']++;
            }
        }
    }

    // ── 2. ntfy: per-hub topic ────────────────────────────
    $settings_table = $wpdb->prefix . 'knx_hub_management_settings';
    $ntfy_enabled = knx_hn_get_hub_setting($hub_id, 'ntfy_enabled');
    $ntfy_topic   = knx_hn_get_hub_setting($hub_id, 'ntfy_topic');

    if ($ntfy_enabled === '1' && !empty($ntfy_topic)) {
        $ntfy_payload = [
            'title'      => 'New Order ' . $order_label,
            'body'       => $customer_name . ' — ' . $order_total_formatted . ' (' . ucfirst($fulfillment_type) . ')',
            'url'        => $hub_orders_url,
            'order_id'   => $order_id,
            'hub_id'     => $hub_id,
            'ntfy_topic' => $ntfy_topic,
            'event_type' => 'hub_new_order',
        ];

        $inserted = knx_hn_insert_notification([
            'order_id'     => $order_id,
            'hub_id'       => $hub_id,
            'event_type'   => 'hub_new_order',
            'channel'      => 'ntfy',
            'payload_json' => wp_json_encode($ntfy_payload),
            'status'       => 'pending',
        ]);

        if ($inserted) {
            $result['enqueued']++;
        } else {
            $result['skipped']++;
        }
    }

    // ── 3. Email: per-hub recipient list ──────────────────
    $email_enabled = knx_hn_get_hub_setting($hub_id, 'email_enabled');
    $email_to      = knx_hn_get_hub_setting($hub_id, 'email_to');

    if ($email_enabled === '1' && !empty($email_to)) {
        $email_payload = [
            'order_id'         => $order_id,
            'order_number'     => $order_label,
            'hub_id'           => $hub_id,
            'hub_name'         => $hub_name,
            'customer_name'    => $customer_name,
            'items_summary'    => $items_summary,
            'order_total'      => $order_total_formatted,
            'fulfillment_type' => $fulfillment_type,
            'email_to'         => $email_to,
            'orders_url'       => $hub_orders_url,
            'event_type'       => 'hub_new_order',
        ];

        $inserted = knx_hn_insert_notification([
            'order_id'     => $order_id,
            'hub_id'       => $hub_id,
            'event_type'   => 'hub_new_order',
            'channel'      => 'email',
            'payload_json' => wp_json_encode($email_payload),
            'status'       => 'pending',
        ]);

        if ($inserted) {
            $result['enqueued']++;
        } else {
            $result['skipped']++;
        }
    }

    return $result;
}

/**
 * Read a hub-scoped setting from knx_hub_management_settings.
 * Key pattern: knx_hub_{hub_id}_{key}
 *
 * @param int    $hub_id
 * @param string $key
 * @return string
 */
function knx_hn_get_hub_setting($hub_id, $key) {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_hub_management_settings';

    $check = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table)));
    if (!$check) return '';

    $setting_key = 'knx_hub_' . (int) $hub_id . '_' . (string) $key;

    $val = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$table} WHERE setting_key = %s LIMIT 1",
        $setting_key
    ));

    return ($val !== null) ? (string) $val : '';
}

/**
 * Write a hub-scoped setting to knx_hub_management_settings.
 * Upsert pattern.
 *
 * @param int    $hub_id
 * @param string $key
 * @param string $value
 * @return bool
 */
function knx_hn_set_hub_setting($hub_id, $key, $value) {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_hub_management_settings';

    $setting_key = 'knx_hub_' . (int) $hub_id . '_' . (string) $key;

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE setting_key = %s LIMIT 1",
        $setting_key
    ));

    if ($exists) {
        return ($wpdb->update(
            $table,
            ['setting_value' => (string) $value],
            ['setting_key' => $setting_key],
            ['%s'],
            ['%s']
        ) !== false);
    }

    return ($wpdb->insert($table, [
        'setting_key'   => $setting_key,
        'setting_value' => (string) $value,
    ], ['%s', '%s']) !== false);
}

/**
 * Send a hub ntfy notification.
 *
 * @param object $row  Notification row with payload_json.
 * @return true|WP_Error
 */
function knx_hn_ntfy_send($row) {
    if (!isset($row->payload_json)) {
        return new WP_Error('invalid_row', 'Missing payload_json');
    }

    $payload = json_decode($row->payload_json, true);
    if (!is_array($payload)) {
        return new WP_Error('invalid_payload', 'Payload is not valid JSON');
    }

    $title = isset($payload['title']) ? (string) $payload['title'] : '';
    $body  = isset($payload['body'])  ? (string) $payload['body']  : '';
    $url   = isset($payload['url'])   ? (string) $payload['url']   : '';
    $topic = isset($payload['ntfy_topic']) ? (string) $payload['ntfy_topic'] : '';

    if ($title === '' && $body === '') {
        return new WP_Error('empty_payload', 'Empty title/body');
    }

    if (empty($topic)) {
        return new WP_Error('missing_topic', 'Missing ntfy topic');
    }

    $endpoint = get_option('knx_ntfy_endpoint', 'https://ntfy.sh');
    $url_endpoint = rtrim($endpoint, '/') . '/' . rawurlencode($topic);

    $headers = [
        'Title'        => $title !== '' ? $title : 'New Order',
        'Content-Type' => 'text/plain; charset=utf-8',
        'Tags'         => 'bell,package',
    ];

    if (!empty($url)) {
        $headers['Click'] = $url;
    }

    $args = [
        'headers' => $headers,
        'body'    => $body !== '' ? $body : $title,
        'timeout' => 12,
    ];

    $res = wp_remote_post($url_endpoint, $args);
    if (is_wp_error($res)) {
        return $res;
    }

    $code = wp_remote_retrieve_response_code($res);
    if ($code >= 200 && $code < 300) {
        return true;
    }

    return new WP_Error('ntfy_error', 'NTFY responded with ' . $code);
}

/**
 * Send a hub email notification.
 *
 * @param object $row  Notification row with payload_json.
 * @return true|WP_Error
 */
function knx_hn_send_email_notification($row) {
    if (!isset($row->payload_json)) {
        return new WP_Error('invalid_row', 'Missing payload_json');
    }

    $payload = json_decode($row->payload_json, true);
    if (!is_array($payload)) {
        return new WP_Error('invalid_payload', 'Payload is not valid JSON');
    }

    $email_to = isset($payload['email_to']) ? (string) $payload['email_to'] : '';
    if (empty($email_to)) {
        return new WP_Error('missing_email', 'No email recipients');
    }

    // Parse comma-separated list
    $recipients = array_filter(array_map('trim', explode(',', $email_to)));
    $valid_recipients = [];
    foreach ($recipients as $addr) {
        if (is_email($addr)) {
            $valid_recipients[] = $addr;
        }
    }

    if (empty($valid_recipients)) {
        return new WP_Error('no_valid_emails', 'No valid email addresses');
    }

    $template_vars = [
        'hub_name'         => (string) ($payload['hub_name'] ?? ''),
        'order_number'     => (string) ($payload['order_number'] ?? ''),
        'customer_name'    => (string) ($payload['customer_name'] ?? ''),
        'items_summary'    => (string) ($payload['items_summary'] ?? ''),
        'order_total'      => (string) ($payload['order_total'] ?? ''),
        'fulfillment_type' => (string) ($payload['fulfillment_type'] ?? 'delivery'),
        'orders_url'       => (string) ($payload['orders_url'] ?? site_url('/hub-orders')),
    ];

    $rendered = knx_hn_render_email_template('hub_new_order', $template_vars);
    if (empty($rendered) || empty($rendered['subject']) || empty($rendered['html'])) {
        return new WP_Error('template_failed', 'Email template render failed');
    }

    $headers = ['Content-Type: text/html; charset=UTF-8'];

    $all_sent = true;
    foreach ($valid_recipients as $addr) {
        $sent = wp_mail($addr, sanitize_text_field($rendered['subject']), $rendered['html'], $headers);
        if (!$sent) $all_sent = false;
    }

    return $all_sent ? true : new WP_Error('mail_partial_fail', 'Some emails failed to send');
}
