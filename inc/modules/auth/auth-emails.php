<?php
if (!defined('ABSPATH')) exit;

/**
 * Build password reset email content.
 * Returns array: ['subject' => string, 'html' => string]
 * Minimal, template-only helper — transport is handled elsewhere.
 */
function knx_get_password_reset_email(string $reset_link): array {
    $site_name = get_bloginfo('name');
    $subject = sprintf('%s — Password reset instructions', $site_name);

    $esc_link = esc_url($reset_link);
    $site = esc_html($site_name);

    $html = '';
    $html .= '<!doctype html><html><head><meta charset="utf-8"></head><body>';
    $html .= '<p>' . $site . ',</p>';
    $html .= '<p>You (or someone using this email) requested a password reset. Click the link below to set a new password. The link will expire in 60 minutes.</p>';
    $html .= '<p><a href="' . $esc_link . '">Reset your password</a></p>';
    $html .= '<p>If you did not request a password reset, you can safely ignore this message.</p>';
    $html .= '<p>— ' . $site . '</p>';
    $html .= '</body></html>';

    return ['subject' => $subject, 'html' => $html];
}
