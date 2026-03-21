<?php
/**
 * ==========================================================
 * KNX Auth Emails — Branded Email Templates
 * ==========================================================
 * Provides HTML email templates for KNX auth flows.
 *
 * Used exclusively by auth-handler.php.
 * Transport is handled by knx_queue_mail() → wp_mail() → FluentSMTP.
 *
 * Functions:
 * - knx_get_password_reset_email($reset_link)
 * - knx_get_account_activation_email($activation_link, $user_email)
 *
 * Design:
 * - Same visual branding as driver notification emails
 * - LocalBites brand, dark header, red CTA button
 * - Mobile-responsive table layout
 * - All variables escaped before output
 * - No external dependencies, no wp_enqueue, no wp_footer
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

/**
 * Build branded password reset email.
 *
 * @param string $reset_link  Full reset URL including token
 * @return array ['subject' => string, 'html' => string]
 */
function knx_get_password_reset_email(string $reset_link): array {
    $site_name = esc_html(get_bloginfo('name'));
    $reset_url = esc_url($reset_link);
    $subject   = 'Reset your ' . $site_name . ' password';

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
<p style="margin:0 0 16px 0;font-size:16px;color:#333333;">Password reset requested</p>
<p style="margin:0 0 20px 0;font-size:15px;color:#555555;">Someone requested a password reset for your <strong>' . $site_name . '</strong> account. Click the button below to set a new password.</p>

<!-- CTA Button -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td align="center" style="padding:4px 0 24px 0;">
<a href="' . $reset_url . '" style="display:inline-block;background-color:#e63946;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:12px 32px;border-radius:6px;">Reset Password</a>
</td>
</tr>
</table>

<p style="margin:0 0 12px 0;font-size:13px;color:#999999;">If you didn\'t request this, you can safely ignore this email. Your password won\'t change.</p>
<p style="margin:0;font-size:13px;color:#999999;">This link expires in <strong>60 minutes</strong>.</p>
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

    return ['subject' => $subject, 'html' => $html];
}

/**
 * Build branded account activation email.
 *
 * @param string $activation_link  Full activation URL including token
 * @param string $user_email       User email address (used as display label)
 * @return array ['subject' => string, 'html' => string]
 */
function knx_get_account_activation_email(string $activation_link, string $user_email = ''): array {
    $site_name      = esc_html(get_bloginfo('name'));
    $activation_url = esc_url($activation_link);
    $email_label    = esc_html($user_email);
    $subject        = 'Activate your ' . $site_name . ' account';

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
<p style="margin:0 0 16px 0;font-size:16px;color:#333333;">Welcome to ' . $site_name . '!</p>
<p style="margin:0 0 20px 0;font-size:15px;color:#555555;">Your account has been created. Please activate it by clicking the button below.</p>';

    if ($email_label !== '') {
        $html .= '
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8f9fa;border-radius:6px;margin-bottom:20px;">
<tr>
<td style="padding:14px 20px;">
<p style="margin:0 0 4px 0;font-size:13px;color:#888888;text-transform:uppercase;letter-spacing:0.5px;">Account</p>
<p style="margin:0;font-size:15px;color:#333333;">' . $email_label . '</p>
</td>
</tr>
</table>';
    }

    $html .= '
<!-- CTA Button -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td align="center" style="padding:4px 0 24px 0;">
<a href="' . $activation_url . '" style="display:inline-block;background-color:#e63946;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:12px 32px;border-radius:6px;">Activate Account</a>
</td>
</tr>
</table>

<p style="margin:0 0 12px 0;font-size:13px;color:#999999;">If you didn\'t create this account, you can safely ignore this email.</p>
<p style="margin:0;font-size:13px;color:#999999;">This activation link expires in <strong>24 hours</strong>.</p>
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

    return ['subject' => $subject, 'html' => $html];
}

