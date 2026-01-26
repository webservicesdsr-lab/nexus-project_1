<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Session Cleaner (v2)
 *
 * This file handles the automatic cleanup of expired sessions.
 * It runs every hour using the WordPress Cron API.
 * It ensures the knx_sessions table never stores outdated tokens.
 */

/**
 * Deletes all expired sessions from the database.
 */
function knx_cleanup_sessions() {
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'knx_sessions';

    $deleted = $wpdb->query("
        DELETE FROM $sessions_table
        WHERE expires_at < NOW()
    ");

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Kingdom Nexus cleanup executed. Expired sessions removed: ' . intval($deleted));
    }
}

/**
 * Manual trigger (for maintenance or debugging)
 * Example usage: add_action('init', 'knx_cleanup_sessions');
 */
