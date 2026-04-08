<?php
/**
 * ==========================================================
 * KNX Hub Notifications — Email Template
 * ==========================================================
 * Renders branded HTML email for hub order notifications.
 *
 * Supported event types:
 * - hub_new_order
 *
 * All templates are minimal, branded, mobile-responsive.
 * Variables are escaped before rendering.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

function knx_hn_render_email_template($event_type, $vars = []) {
    $event_type = (string) $event_type;

    switch ($event_type) {
        case 'hub_new_order':
            return knx_hn_template_hub_new_order($vars);
        default:
            return [];
    }
}

function knx_hn_template_hub_new_order($vars) {
    $hub_name         = esc_html((string) ($vars['hub_name'] ?? ''));
    $order_number     = esc_html((string) ($vars['order_number'] ?? ''));
    $customer_name    = esc_html((string) ($vars['customer_name'] ?? 'Customer'));
    $items_summary    = esc_html((string) ($vars['items_summary'] ?? ''));
    $order_total      = esc_html((string) ($vars['order_total'] ?? ''));
    $fulfillment_type = esc_html((string) ($vars['fulfillment_type'] ?? 'delivery'));
    $orders_url       = esc_url((string) ($vars['orders_url'] ?? site_url('/hub-orders')));

    $subject = 'New Order ' . $order_number . ' — ' . $hub_name;

    $fulfillment_label = $fulfillment_type === 'pickup' ? '🏪 Pickup' : '🚗 Delivery';

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . esc_html($subject) . '</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f7;">
<tr>
<td align="center" style="padding:24px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border-radius:8px;overflow:hidden;">

<!-- Header -->
<tr>
<td style="background-color:#0b793a;padding:20px 24px;text-align:center;">
<span style="color:#ffffff;font-size:18px;font-weight:700;">LocalBites</span>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding:28px 24px 16px 24px;">
<p style="margin:0 0 16px;font-size:16px;color:#1a1a2e;font-weight:600;">New order for ' . $hub_name . '</p>
<p style="margin:0 0 20px;font-size:14px;color:#374151;line-height:1.5;">A new order has been placed and is ready for preparation.</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border-radius:8px;padding:16px;margin-bottom:20px;">
<tr><td style="padding:16px;">';

    if ($order_number !== '') {
        $html .= '<p style="margin:0 0 8px;font-size:13px;color:#6b7280;">Order</p>
<p style="margin:0 0 16px;font-size:18px;font-weight:700;color:#0b1220;">' . $order_number . '</p>';
    }

    if ($customer_name !== '' && $customer_name !== 'Customer') {
        $html .= '<p style="margin:0 0 4px;font-size:13px;color:#6b7280;">Customer</p>
<p style="margin:0 0 12px;font-size:14px;color:#0b1220;">' . $customer_name . '</p>';
    }

    $html .= '<p style="margin:0 0 4px;font-size:13px;color:#6b7280;">Type</p>
<p style="margin:0 0 12px;font-size:14px;color:#0b1220;">' . $fulfillment_label . '</p>';

    if ($items_summary !== '') {
        $html .= '<p style="margin:0 0 4px;font-size:13px;color:#6b7280;">Items</p>
<p style="margin:0 0 12px;font-size:14px;color:#0b1220;">' . $items_summary . '</p>';
    }

    if ($order_total !== '') {
        $html .= '<p style="margin:0 0 4px;font-size:13px;color:#6b7280;">Total</p>
<p style="margin:0;font-size:16px;font-weight:700;color:#0b793a;">' . $order_total . '</p>';
    }

    $html .= '</td></tr></table>

<!-- CTA -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td align="center" style="padding:4px 0 20px;">
<a href="' . $orders_url . '" style="display:inline-block;padding:14px 32px;background-color:#0b793a;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:700;font-size:14px;">
View Orders
</a>
</td>
</tr>
</table>

<p style="margin:0;font-size:13px;color:#9ca3af;line-height:1.5;text-align:center;">Please start preparing this order as soon as possible.</p>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="padding:16px 24px;text-align:center;border-top:1px solid #f3f4f6;">
<p style="margin:0;font-size:12px;color:#9ca3af;">LocalBites — app.localbites.delivery</p>
</td>
</tr>

</table></td></tr></table>
</body></html>';

    return ['subject' => $subject, 'html' => $html];
}
