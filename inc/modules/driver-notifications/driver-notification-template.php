<?php
/**
 * ==========================================================
 * KNX Driver Notifications — Email Template
 * ==========================================================
 * Renders branded HTML email for driver notifications.
 *
 * Provides:
 * - knx_dn_render_email_template($event_type, $vars)
 *
 * Templates are internal. They do NOT use FluentSMTP templates.
 * They do NOT override WordPress system emails.
 *
 * Supported event types:
 * - order_available
 *
 * All templates are minimal, branded, mobile-responsive.
 * Variables are escaped before rendering.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

/**
 * Render an email template for a given event type.
 *
 * @param string $event_type  e.g. 'order_available'
 * @param array  $vars        Dynamic variables for the template
 * @return array  ['subject' => string, 'html' => string] or empty array on unknown event
 */
function knx_dn_render_email_template($event_type, $vars = []) {
    $event_type = (string) $event_type;

    switch ($event_type) {
        case 'order_available':
            return knx_dn_template_order_available($vars);
        default:
            return [];
    }
}

/**
 * Template: order_available
 *
 * Expected vars:
 * - driver_name     (string)
 * - order_number    (string)
 * - hub_name        (string)
 * - city_name       (string)
 * - delivery_address (string)
 * - order_total     (string, formatted)
 * - dashboard_url   (string, full URL)
 *
 * @param array $vars
 * @return array ['subject' => string, 'html' => string]
 */
function knx_dn_template_order_available($vars) {
    $driver_name      = esc_html((string) ($vars['driver_name'] ?? 'Driver'));
    $order_number     = esc_html((string) ($vars['order_number'] ?? ''));
    $hub_name         = esc_html((string) ($vars['hub_name'] ?? ''));
    $city_name        = esc_html((string) ($vars['city_name'] ?? ''));
    $delivery_address = esc_html((string) ($vars['delivery_address'] ?? ''));
    $order_total      = esc_html((string) ($vars['order_total'] ?? ''));
    $dashboard_url    = esc_url((string) ($vars['dashboard_url'] ?? site_url('/driver-live-orders')));

    $subject = 'New Order Available' . ($order_number !== '' ? ' - ' . $order_number : '');

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . esc_html($subject) . '</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f7;">
<tr>
<td align="center" style="padding:24px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border-radius:8px;overflow:hidden;">

<!-- Header -->
<tr>
<td style="background-color:#1a1a2e;padding:20px 24px;text-align:center;">
<span style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:0.5px;">LocalBites</span>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding:28px 24px 16px 24px;">
<p style="margin:0 0 16px 0;font-size:16px;color:#333333;">Hi ' . $driver_name . ',</p>
<p style="margin:0 0 20px 0;font-size:15px;color:#555555;">A new delivery order is available in your area.</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8f9fa;border-radius:6px;margin-bottom:20px;">
<tr>
<td style="padding:16px 20px;">';

    if ($order_number !== '') {
        $html .= '<p style="margin:0 0 8px 0;font-size:13px;color:#888888;text-transform:uppercase;letter-spacing:0.5px;">Order</p>
<p style="margin:0 0 14px 0;font-size:16px;color:#1a1a2e;font-weight:600;">' . $order_number . '</p>';
    }

    if ($hub_name !== '') {
        $html .= '<p style="margin:0 0 8px 0;font-size:13px;color:#888888;text-transform:uppercase;letter-spacing:0.5px;">Pickup</p>
<p style="margin:0 0 14px 0;font-size:15px;color:#333333;">' . $hub_name . '</p>';
    }

    if ($delivery_address !== '') {
        $html .= '<p style="margin:0 0 8px 0;font-size:13px;color:#888888;text-transform:uppercase;letter-spacing:0.5px;">Deliver To</p>
<p style="margin:0 0 14px 0;font-size:15px;color:#333333;">' . $delivery_address . '</p>';
    }

    if ($city_name !== '') {
        $html .= '<p style="margin:0 0 8px 0;font-size:13px;color:#888888;text-transform:uppercase;letter-spacing:0.5px;">City</p>
<p style="margin:0 0 14px 0;font-size:15px;color:#333333;">' . $city_name . '</p>';
    }

    if ($order_total !== '') {
        $html .= '<p style="margin:0 0 8px 0;font-size:13px;color:#888888;text-transform:uppercase;letter-spacing:0.5px;">Total</p>
<p style="margin:0 0 0 0;font-size:17px;color:#1a1a2e;font-weight:700;">' . $order_total . '</p>';
    }

    $html .= '</td>
</tr>
</table>

<!-- CTA Button -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td align="center" style="padding:4px 0 24px 0;">
<a href="' . $dashboard_url . '" style="display:inline-block;background-color:#e63946;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:12px 32px;border-radius:6px;">View Available Orders</a>
</td>
</tr>
</table>

<p style="margin:0;font-size:13px;color:#999999;">This order is available to all drivers in ' . ($city_name !== '' ? $city_name : 'your area') . '. First to accept gets it.</p>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="padding:16px 24px 20px 24px;border-top:1px solid #eeeeee;">
<p style="margin:0;font-size:12px;color:#aaaaaa;text-align:center;">LocalBites Delivery &mdash; app.localbites.delivery</p>
</td>
</tr>

</table>
</td>
</tr>
</table>
</body>
</html>';

    return [
        'subject' => $subject,
        'html'    => $html,
    ];
}
