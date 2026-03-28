<?php
/**
 * ==========================================================
 * KNX — Driver Notifications Preferences + Browser Soft-Push Routes
 *
 * Endpoints:
 * - GET  /wp-json/knx/v1/driver-soft-push/poll
 * - POST /wp-json/knx/v1/driver-soft-push/ack
 * - GET  /wp-json/knx/v1/driver-soft-push/prefs
 * - POST /wp-json/knx/v1/driver-soft-push/prefs
 * - POST /wp-json/knx/v1/driver-soft-push/test-ntfy
 *
 * Canonical rules:
 * - Browser push, ntfy and email are independent switches
 * - poll only serves browser soft-push rows
 * - ack marks browser soft-push as delivered
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

if (!defined('KNX_SOFT_PUSH_LEASE_SECONDS')) {
    define('KNX_SOFT_PUSH_LEASE_SECONDS', 30);
}

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/driver-soft-push/poll', [
        'methods'             => 'GET',
        'callback'            => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_dn_soft_push_poll')($request);
        },
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);

    register_rest_route('knx/v1', '/driver-soft-push/prefs', [
        'methods' => 'GET',
        'callback' => function (WP_REST_Request $request) {
            if (!function_exists('knx_get_driver_context')) {
                return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
            }

            $ctx = knx_get_driver_context();
            if (!$ctx || empty($ctx->session) || empty($ctx->session->user_id)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
            }

            $uid = (int) $ctx->session->user_id;

            $browser_enabled = get_user_meta($uid, 'knx_browser_push_enabled', true);
            $ntfy_enabled    = get_user_meta($uid, 'knx_ntfy_enabled', true);
            $email_enabled   = get_user_meta($uid, 'knx_email_enabled', true);
            $ntfy_id         = get_user_meta($uid, 'knx_ntfy_id', true) ?: '';

            $browser_enabled = ($browser_enabled === '' || is_null($browser_enabled)) ? '1' : ($browser_enabled ? '1' : '0');
            $ntfy_enabled    = ($ntfy_enabled === '' || is_null($ntfy_enabled)) ? '0' : ($ntfy_enabled ? '1' : '0');
            $email_enabled   = ($email_enabled === '' || is_null($email_enabled)) ? '1' : ($email_enabled ? '1' : '0');

            return new WP_REST_Response([
                'ok'                   => true,
                'browser_push_enabled' => $browser_enabled,
                'ntfy_enabled'         => $ntfy_enabled,
                'email_enabled'        => $email_enabled,
                'ntfy_id'              => $ntfy_id,
            ], 200);
        },
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);

    register_rest_route('knx/v1', '/driver-soft-push/prefs', [
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            if (!function_exists('knx_get_driver_context')) {
                return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
            }

            $ctx = knx_get_driver_context();
            if (!$ctx || empty($ctx->session) || empty($ctx->session->user_id)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
            }

            $uid  = (int) $ctx->session->user_id;
            $body = $request->get_json_params();
            if (!is_array($body)) {
                $body = [];
            }

            if (isset($body['browser_push_enabled'])) {
                update_user_meta($uid, 'knx_browser_push_enabled', $body['browser_push_enabled'] ? '1' : '0');
            }

            if (isset($body['ntfy_enabled'])) {
                update_user_meta($uid, 'knx_ntfy_enabled', $body['ntfy_enabled'] ? '1' : '0');
            }

            if (isset($body['email_enabled'])) {
                update_user_meta($uid, 'knx_email_enabled', $body['email_enabled'] ? '1' : '0');
            }

            if (isset($body['ntfy_id'])) {
                update_user_meta($uid, 'knx_ntfy_id', sanitize_text_field($body['ntfy_id']));
            }

            // Keep canonical drivers table aligned where applicable
            global $wpdb;
            $drivers_table = $wpdb->prefix . 'knx_drivers';
            $driver_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$drivers_table} WHERE user_id = %d LIMIT 1",
                $uid
            ));

            if (!empty($driver_id)) {
                $fields = [];
                $values = [];

                if (isset($body['ntfy_id'])) {
                    $fields[] = 'ntfy_id = %s';
                    $values[] = sanitize_text_field($body['ntfy_id']);
                }

                if (!empty($fields)) {
                    $sql = "UPDATE {$drivers_table} SET " . implode(', ', $fields) . " WHERE id = %d";
                    $values[] = (int)$driver_id;
                    $wpdb->query($wpdb->prepare($sql, $values));
                }
            }

            return new WP_REST_Response(['ok' => true], 200);
        },
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);

    register_rest_route('knx/v1', '/driver-soft-push/ack', [
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            if (!function_exists('knx_get_driver_context')) {
                return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
            }

            $ctx = knx_get_driver_context();
            if (!$ctx || empty($ctx->session) || empty($ctx->session->user_id) || !isset($ctx->driver_id)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
            }

            $driver_id = (int) $ctx->driver_id;
            $body      = $request->get_json_params();
            $nid       = isset($body['notification_id']) ? intval($body['notification_id']) : 0;

            if ($nid <= 0) {
                return new WP_REST_Response(['ok' => false, 'error' => 'invalid_id'], 400);
            }

            global $wpdb;
            $table = knx_dn_table_name();

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, driver_id, channel, status FROM {$table} WHERE id = %d LIMIT 1",
                $nid
            ));

            if (!$row) {
                return new WP_REST_Response(['ok' => false, 'error' => 'not_found'], 404);
            }

            if ((int) $row->driver_id !== $driver_id) {
                return new WP_REST_Response(['ok' => false, 'error' => 'not_owner'], 403);
            }

            if ((string) $row->channel !== 'soft-push') {
                return new WP_REST_Response(['ok' => false, 'error' => 'invalid_channel'], 400);
            }

            if ((string) $row->status === 'delivered') {
                return new WP_REST_Response(['ok' => true, 'already_delivered' => true], 200);
            }

            $updated = $wpdb->update(
                $table,
                [
                    'status'       => 'delivered',
                    'sent_at'      => current_time('mysql'),
                    'available_at' => null,
                ],
                [
                    'id'        => $nid,
                    'driver_id' => $driver_id,
                    'channel'   => 'soft-push',
                ],
                ['%s', '%s', '%s'],
                ['%d', '%d', '%s']
            );

            if ($updated === false) {
                return new WP_REST_Response(['ok' => false, 'error' => 'ack_update_failed'], 500);
            }

            return new WP_REST_Response(['ok' => true], 200);
        },
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);

    register_rest_route('knx/v1', '/driver-soft-push/test-ntfy', [
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            if (!function_exists('knx_get_driver_context')) {
                return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
            }

            $ctx = knx_get_driver_context();
            if (!$ctx || empty($ctx->session) || empty($ctx->session->user_id) || !isset($ctx->driver_id)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
            }

            $uid = (int)$ctx->session->user_id;
            $driver_id = (int)$ctx->driver_id;
            $body = $request->get_json_params();
            if (!is_array($body)) {
                $body = [];
            }

            $ntfy_enabled = get_user_meta($uid, 'knx_ntfy_enabled', true);
            $ntfy_enabled = ($ntfy_enabled === '' || is_null($ntfy_enabled)) ? '0' : ($ntfy_enabled ? '1' : '0');
            if ($ntfy_enabled !== '1') {
                return new WP_REST_Response(['ok' => false, 'error' => 'ntfy_disabled'], 400);
            }

            $ntfy_id = '';
            if (!empty($body['ntfy_id'])) {
                $ntfy_id = sanitize_text_field($body['ntfy_id']);
            } else {
                $ntfy_id = get_user_meta($uid, 'knx_ntfy_id', true);
            }

            if (empty($ntfy_id)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'missing_ntfy_id'], 400);
            }

            if (!function_exists('knx_dn_ntfy_send')) {
                return new WP_REST_Response(['ok' => false, 'error' => 'ntfy_sender_missing'], 500);
            }

            $row = (object) [
                'id' => 0,
                'driver_id' => $driver_id,
                'payload_json' => wp_json_encode([
                    'title' => 'KNX Test Notification',
                    'body'  => 'Your phone notifications are working.',
                    'url'   => site_url('/driver-active-orders'),
                ]),
                'user_ntfy_id' => $ntfy_id,
            ];

            $res = knx_dn_ntfy_send($row);
            if ($res === true) {
                return new WP_REST_Response(['ok' => true], 200);
            }

            if (is_wp_error($res)) {
                return new WP_REST_Response([
                    'ok'    => false,
                    'error' => $res->get_error_message(),
                ], 500);
            }

            return new WP_REST_Response(['ok' => false, 'error' => 'unknown_error'], 500);
        },
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);
});

/**
 * Poll handler — returns one claimable browser soft-push event for the current driver.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function knx_dn_soft_push_poll(WP_REST_Request $request) {
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) {
        return $session;
    }

    if (!function_exists('knx_get_driver_context')) {
        return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || !isset($ctx->driver_id)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    if (!function_exists('knx_dn_table_exists_live') || !knx_dn_table_exists_live()) {
        return new WP_REST_Response(['ok' => false, 'error' => 'notifications_table_missing'], 500);
    }

    global $wpdb;

    $table       = knx_dn_table_name();
    $driver_id   = (int) $ctx->driver_id;
    $uid         = isset($ctx->session->user_id) ? (int) $ctx->session->user_id : 0;
    $now_mysql   = current_time('mysql');
    $lease_until = date('Y-m-d H:i:s', current_time('timestamp') + (int) KNX_SOFT_PUSH_LEASE_SECONDS);

    $browser_enabled = $uid ? get_user_meta($uid, 'knx_browser_push_enabled', true) : '';
    if ($browser_enabled === '' || is_null($browser_enabled)) {
        $browser_enabled = '1';
    }

    if (!in_array($browser_enabled, ['1', 1, true], true)) {
        return new WP_REST_Response(['ok' => true, 'has' => false], 200);
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "
        SELECT id, payload_json, status, available_at
        FROM {$table}
        WHERE driver_id = %d
          AND channel = %s
          AND (
                status = %s
                OR (
                    status = %s
                    AND available_at IS NOT NULL
                    AND available_at <= %s
                )
          )
        ORDER BY created_at ASC, id ASC
        LIMIT 1
        ",
        $driver_id,
        'soft-push',
        'pending',
        'processing',
        $now_mysql
    ));

    if (!$row) {
        return new WP_REST_Response(['ok' => true, 'has' => false], 200);
    }

    $payload = json_decode($row->payload_json, true);

    if (!is_array($payload)) {
        if (function_exists('knx_dn_update_status')) {
            knx_dn_update_status($row->id, 'failed', false);
        } else {
            $wpdb->update(
                $table,
                ['status' => 'failed', 'available_at' => null],
                ['id' => $row->id],
                ['%s', '%s'],
                ['%d']
            );
        }

        return new WP_REST_Response(['ok' => true, 'has' => false], 200);
    }

    if (empty($payload['title']) || empty($payload['body']) || empty($payload['url'])) {
        if (function_exists('knx_dn_update_status')) {
            knx_dn_update_status($row->id, 'failed', false);
        } else {
            $wpdb->update(
                $table,
                ['status' => 'failed', 'available_at' => null],
                ['id' => $row->id],
                ['%s', '%s'],
                ['%d']
            );
        }

        return new WP_REST_Response(['ok' => true, 'has' => false], 200);
    }

    $updated = $wpdb->update(
        $table,
        [
            'status'       => 'processing',
            'available_at' => $lease_until,
        ],
        [
            'id'     => (int) $row->id,
            'status' => (string) $row->status,
        ],
        ['%s', '%s'],
        ['%d', '%s']
    );

    if ($updated === false || $updated < 1) {
        return new WP_REST_Response(['ok' => true, 'has' => false], 200);
    }

    $safe_payload = [
        'title'           => (string) $payload['title'],
        'body'            => (string) $payload['body'],
        'url'             => esc_url_raw((string) $payload['url']),
        'notification_id' => (int) $row->id,
    ];

    if (isset($payload['order_id'])) {
        $safe_payload['order_id'] = (int) $payload['order_id'];
    }

    if (isset($payload['city_id'])) {
        $safe_payload['city_id'] = (int) $payload['city_id'];
    }

    if (isset($payload['event_type'])) {
        $safe_payload['event_type'] = (string) $payload['event_type'];
    }

    return new WP_REST_Response([
        'ok'      => true,
        'has'     => true,
        'payload' => $safe_payload,
    ], 200);
}