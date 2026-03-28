<?php
/**
 * ==========================================================
 * KNX Driver Notifications — Browser Soft-Push Helpers
 * ==========================================================
 * This helper validates and enqueues browser soft-push rows only.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

/**
 * Clean and validate payload for browser soft-push.
 *
 * @param array $payload
 * @return array|false
 */
function knx_dn_clean_payload_for_soft_push($payload) {
    if (!is_array($payload)) {
        return false;
    }

    $title = isset($payload['title']) ? sanitize_text_field($payload['title']) : '';
    $body  = isset($payload['body']) ? sanitize_text_field($payload['body']) : '';
    $url   = isset($payload['url']) ? esc_url_raw($payload['url']) : '';

    if ($title === '' || $body === '' || $url === '') {
        return false;
    }

    if (!wp_http_validate_url($url)) {
        return false;
    }

    $clean = [
        'title' => $title,
        'body'  => $body,
        'url'   => $url,
    ];

    if (isset($payload['order_id'])) {
        $clean['order_id'] = (int)$payload['order_id'];
    }
    if (isset($payload['hub_id'])) {
        $clean['hub_id'] = (int)$payload['hub_id'];
    }
    if (isset($payload['city_id'])) {
        $clean['city_id'] = (int)$payload['city_id'];
    }
    if (isset($payload['event_type'])) {
        $clean['event_type'] = sanitize_text_field($payload['event_type']);
    }

    return $clean;
}

/**
 * Enqueue one browser soft-push notification row.
 *
 * @param int   $driver_id
 * @param int   $city_id
 * @param array $payload
 * @return int|false
 */
function knx_dn_enqueue_soft_push_notification($driver_id, $city_id, $payload) {
    $driver_id = (int)$driver_id;
    $city_id   = (int)$city_id;

    if ($driver_id <= 0 || $city_id <= 0) {
        return false;
    }

    $clean_payload = knx_dn_clean_payload_for_soft_push($payload);
    if ($clean_payload === false) {
        return false;
    }

    $order_id   = isset($clean_payload['order_id']) ? (int)$clean_payload['order_id'] : 0;
    $event_type = !empty($clean_payload['event_type']) ? (string)$clean_payload['event_type'] : 'order_available';

    return knx_dn_insert_notification([
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