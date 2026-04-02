<?php
/**
 * KNX NTFY Sender
 * Responsible for sending a single notification row to ntfy.
 * This file is provider-specific and does not modify queue mechanics.
 */
if (!defined('ABSPATH')) exit;

/**
 * Send a notification row to ntfy.
 * Expects a DB row with `id`, `driver_id`, and `payload_json`.
 * Returns true on success or WP_Error on failure.
 *
 * @param object $row
 * @return bool|WP_Error
 */
function knx_dn_ntfy_send($row) {
    if (!isset($row->driver_id) || !isset($row->payload_json)) {
        return new WP_Error('invalid_row', 'Invalid notification row');
    }

    $payload = json_decode($row->payload_json, true);
    if (!is_array($payload)) {
        return new WP_Error('invalid_payload', 'Payload is not valid JSON');
    }

    $title = isset($payload['title']) ? (string)$payload['title'] : '';
    $body  = isset($payload['body'])  ? (string)$payload['body']  : '';
    $url   = isset($payload['url'])   ? (string)$payload['url']   : '';

    if ($title === '' && $body === '') {
        return new WP_Error('empty_payload', 'Empty title/body');
    }

    $endpoint = get_option('knx_ntfy_endpoint', 'https://ntfy.sh');
    $topic_prefix = get_option('knx_ntfy_topic_prefix', 'knx-driver');

    $topic = '';
    if (!empty($row->user_ntfy_id)) {
        $topic = (string)$row->user_ntfy_id;
    } else {
        global $wpdb;
        $drivers_table = $wpdb->prefix . 'knx_drivers';

        // Defensive: detect whether the user link column is user_id or driver_user_id
        $user_col = 'user_id';
        $cols_raw = $wpdb->get_results("SHOW COLUMNS FROM {$drivers_table}", ARRAY_A);
        if (is_array($cols_raw)) {
            $col_names = array_map(function ($c) { return $c['Field']; }, $cols_raw);
            if (in_array('driver_user_id', $col_names, true)) {
                $user_col = 'driver_user_id';
            }
        }

        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT {$user_col} FROM {$drivers_table} WHERE id = %d LIMIT 1",
            (int)$row->driver_id
        ));

        if (!empty($user_id)) {
            $user_topic = get_user_meta((int)$user_id, 'knx_ntfy_id', true);
            if (!empty($user_topic)) {
                $topic = $user_topic;
            }
        }

        if (empty($topic)) {
            $topic = $topic_prefix . '-' . (int)$row->driver_id;
        }
    }

    if (empty($topic)) {
        return new WP_Error('missing_topic', 'Missing ntfy topic');
    }

    $url_endpoint = rtrim($endpoint, '/') . '/' . rawurlencode($topic);

    $headers = [
        'Title'        => $title !== '' ? $title : 'KNX Notification',
        'Content-Type' => 'text/plain; charset=utf-8',
        'Tags'         => 'bell',
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

    $body_resp = wp_remote_retrieve_body($res);
    return new WP_Error(
        'ntfy_error',
        'NTFY responded with ' . $code,
        ['body' => $body_resp]
    );
}