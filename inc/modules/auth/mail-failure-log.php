<?php
if (!defined('ABSPATH')) exit;

/**
 * KNX – Minimal mail failure logger (PRODUCTION SAFE)
 * Logs only when wp_mail() fails.
 * Remove this file once diagnosis is complete.
 */

add_action('wp_mail_failed', function ($wp_error) {

    if (!is_wp_error($wp_error)) {
        error_log('[KNX MAIL] wp_mail_failed fired but no WP_Error object.');
        return;
    }

    $messages = $wp_error->get_error_messages();
    $data     = $wp_error->get_error_data();

    error_log('[KNX MAIL] wp_mail FAILED');
    error_log('[KNX MAIL] Messages: ' . print_r($messages, true));

    if (!empty($data)) {
        error_log('[KNX MAIL] Data: ' . print_r($data, true));
    }

}, 10, 1);
