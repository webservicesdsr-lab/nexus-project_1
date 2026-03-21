<?php
/**
 * ==========================================================
 * KNX Driver Notifications — Email Provider
 * ==========================================================
 * Provides:
 * - knx_dn_send_email($to_email, $subject, $html_body)
 *
 * Uses wp_mail exclusively.
 * FluentSMTP intercepts wp_mail at transport level.
 * This provider does NOT configure SMTP, headers beyond Content-Type,
 * or interact with FluentSMTP API.
 *
 * Does NOT override WordPress password reset or verification emails.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

/**
 * Send an email via wp_mail.
 *
 * @param string $to_email   Recipient email address
 * @param string $subject    Email subject
 * @param string $html_body  HTML email body
 * @return bool  true on success, false on failure
 */
function knx_dn_send_email($to_email, $subject, $html_body) {
    $to_email = trim((string) $to_email);
    $subject  = trim((string) $subject);

    if ($to_email === '' || !is_email($to_email)) {
        return false;
    }

    if ($subject === '' || $html_body === '') {
        return false;
    }

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
    ];

    try {
        $result = wp_mail($to_email, $subject, $html_body, $headers);
        return (bool) $result;
    } catch (\Throwable $e) {
        return false;
    }
}
