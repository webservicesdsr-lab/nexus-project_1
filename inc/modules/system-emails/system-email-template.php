<?php
/**
 * ==========================================================
 * KNX System Emails — HTML Templates
 * ==========================================================
 * Renders branded HTML email for system notifications.
 *
 * Provides:
 * - knx_se_render_email_template($email_type, $vars)
 *
 * Templates are internal. They do NOT use FluentSMTP templates.
 * They do NOT override WordPress system emails outside KNX context.
 *
 * Supported email types:
 * - user_activation
 * - password_reset
 *
 * All templates use LocalBites branding, mobile-responsive.
 * Variables are escaped before rendering.
 * Same visual style as driver notification templates.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

/**
 * Render an email template for a given system email type.
 *
 * @param string $email_type  'user_activation' or 'password_reset'
 * @param array  $vars        Dynamic variables for the template
 * @return array  ['subject' => string, 'html' => string] or empty array on unknown type
 */
function knx_se_render_email_template($email_type, $vars = []) {
    $email_type = (string) $email_type;

    switch ($email_type) {
        case 'user_activation':
            return knx_se_template_user_activation($vars);
        case 'password_reset':
            return knx_se_template_password_reset($vars);
        default:
            return [];
    }
}

/**
 * Template: user_activation
 *
 * Expected vars:
 * - user_name       (string) Display name or username
 * - user_email      (string) User email address
 * - activation_url  (string) Full activation URL
 * - site_name       (string) Site name
 *
 * @param array $vars
 * @return array ['subject' => string, 'html' => string]
 */
function knx_se_template_user_activation($vars) {
    $user_name      = esc_html((string) ($vars['user_name'] ?? 'User'));
    $user_email     = esc_html((string) ($vars['user_email'] ?? ''));
    $activation_url = esc_url((string) ($vars['activation_url'] ?? ''));
    $site_name      = esc_html((string) ($vars['site_name'] ?? get_bloginfo('name')));

    $subject = 'Activate your ' . $site_name . ' account';

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
<p style="margin:0 0 16px 0;font-size:16px;color:#333333;">Hi ' . $user_name . ',</p>
<p style="margin:0 0 20px 0;font-size:15px;color:#555555;">Welcome to ' . $site_name . '! Please activate your account to get started.</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8f9fa;border-radius:6px;margin-bottom:20px;">
<tr>
<td style="padding:16px 20px;">
<p style="margin:0 0 8px 0;font-size:13px;color:#888888;text-transform:uppercase;letter-spacing:0.5px;">Account</p>
<p style="margin:0 0 0 0;font-size:15px;color:#333333;">' . $user_email . '</p>
</td>
</tr>
</table>

<!-- CTA Button -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td align="center" style="padding:4px 0 24px 0;">
<a href="' . $activation_url . '" style="display:inline-block;background-color:#e63946;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:12px 32px;border-radius:6px;">Activate Account</a>
</td>
</tr>
</table>

<p style="margin:0 0 12px 0;font-size:13px;color:#999999;">If you didn\'t create this account, you can safely ignore this email.</p>
<p style="margin:0;font-size:13px;color:#999999;">This activation link will expire in 24 hours for security.</p>
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

/**
 * Template: password_reset
 *
 * Expected vars:
 * - user_name    (string) Display name or username
 * - user_email   (string) User email address
 * - reset_url    (string) Full password reset URL
 * - site_name    (string) Site name
 * - expires_in   (string) Expiration time (e.g., "1 hour")
 *
 * @param array $vars
 * @return array ['subject' => string, 'html' => string]
 */
function knx_se_template_password_reset($vars) {
    $user_name   = esc_html((string) ($vars['user_name'] ?? 'User'));
    $user_email  = esc_html((string) ($vars['user_email'] ?? ''));
    $reset_url   = esc_url((string) ($vars['reset_url'] ?? ''));
    $site_name   = esc_html((string) ($vars['site_name'] ?? get_bloginfo('name')));
    $expires_in  = esc_html((string) ($vars['expires_in'] ?? '1 hour'));

    $subject = 'Reset your ' . $site_name . ' password';

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
<p style="margin:0 0 16px 0;font-size:16px;color:#333333;">Hi ' . $user_name . ',</p>
<p style="margin:0 0 20px 0;font-size:15px;color:#555555;">Someone requested a password reset for your ' . $site_name . ' account.</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8f9fa;border-radius:6px;margin-bottom:20px;">
<tr>
<td style="padding:16px 20px;">
<p style="margin:0 0 8px 0;font-size:13px;color:#888888;text-transform:uppercase;letter-spacing:0.5px;">Account</p>
<p style="margin:0 0 0 0;font-size:15px;color:#333333;">' . $user_email . '</p>
</td>
</tr>
</table>

<!-- CTA Button -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td align="center" style="padding:4px 0 24px 0;">
<a href="' . $reset_url . '" style="display:inline-block;background-color:#e63946;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:12px 32px;border-radius:6px;">Reset Password</a>
</td>
</tr>
</table>

<p style="margin:0 0 12px 0;font-size:13px;color:#999999;">If you didn\'t request this reset, you can safely ignore this email. Your password won\'t change.</p>
<p style="margin:0;font-size:13px;color:#999999;">This reset link will expire in ' . $expires_in . ' for security.</p>
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