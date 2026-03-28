<?php
/**
 * ==========================================================
 * KNX Driver Notifications — Email Provider
 * ==========================================================
 * Sends a single email notification row.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

/**
 * Process one email notification row.
 *
 * @param object $row
 * @return bool|WP_Error
 */
function knx_dn_send_email_notification($row) {
    if (!isset($row->payload_json)) {
        return new WP_Error('invalid_row', 'Missing payload_json');
    }

    $payload = json_decode($row->payload_json, true);
    if (!is_array($payload)) {
        return new WP_Error('invalid_payload', 'Payload is not valid JSON');
    }

    $email = isset($payload['email']) ? sanitize_email($payload['email']) : '';
    if (empty($email) || !is_email($email)) {
        return new WP_Error('invalid_email', 'Missing or invalid email address');
    }

    // Build template variables expected by the renderer. Use payload keys but
    // provide sensible defaults. The engine enqueues driver-facing fields.
    $template_vars = [
        'driver_name'      => isset($payload['driver_name']) ? (string)$payload['driver_name'] : '',
        'order_number'     => isset($payload['order_number']) ? (string)$payload['order_number'] : '',
        'hub_name'         => isset($payload['hub_name']) ? (string)$payload['hub_name'] : '',
        'city_name'        => isset($payload['city_name']) ? (string)$payload['city_name'] : '',
        'delivery_address' => isset($payload['delivery_address']) ? (string)$payload['delivery_address'] : '',
        'order_total'      => isset($payload['order_total']) ? (string)$payload['order_total'] : '',
        // Template expects 'dashboard_url' — map from 'url' if present.
        'dashboard_url'    => isset($payload['dashboard_url']) ? (string)$payload['dashboard_url'] : (isset($payload['url']) ? (string)$payload['url'] : site_url('/driver-ops')),
    ];

    // Render branded HTML template. Fallback to legacy plain text if renderer
    // not available or returns empty.
    if (!function_exists('knx_dn_render_email_template')) {
        // Legacy fallback: plain text message
        $subject = isset($payload['title']) ? sanitize_text_field($payload['title']) : 'KNX Notification';
        $body    = isset($payload['body']) ? wp_kses_post($payload['body']) : '';
        $url     = isset($payload['url']) ? esc_url_raw($payload['url']) : '';

        $message = $body;
        if (!empty($url)) {
            $message .= "\n\nOpen: " . $url;
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $sent = wp_mail($email, $subject, $message, $headers);
        if ($sent) return true;
        return new WP_Error('mail_failed', 'wp_mail failed');
    }

    $rendered = knx_dn_render_email_template(isset($payload['event_type']) ? (string)$payload['event_type'] : 'order_available', $template_vars);
    if (empty($rendered) || empty($rendered['subject']) || empty($rendered['html'])) {
        return new WP_Error('template_failed', 'Email template render failed');
    }

    $subject = sanitize_text_field($rendered['subject']);
    $html    = $rendered['html'];

    // Send HTML email — many transports (FluentSMTP, etc.) expect Content-Type header.
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    $sent = wp_mail($email, $subject, $html, $headers);
    if ($sent) {
        return true;
    }

    return new WP_Error('mail_failed', 'wp_mail failed');
}