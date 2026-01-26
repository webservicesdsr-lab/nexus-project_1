<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Cart Core Helpers + Cleanup (Production)
 * ----------------------------------------------------------
 * Provides:
 * - knx_cart_tables_exist() helper
 * - Hourly cleanup of old carts:
 *     1) Mark as "abandoned" after 12h of inactivity
 *     2) Delete abandoned carts older than 7 days
 * ----------------------------------------------------------
 * Tables:
 *   {$wpdb->prefix}knx_carts
 *   {$wpdb->prefix}knx_cart_items
 * ==========================================================
 */

/**
 * Check if cart tables exist in the current database.
 *
 * @param string $table_carts
 * @param string $table_cart_items
 * @return bool
 */
function knx_cart_tables_exist($table_carts, $table_cart_items) {
    global $wpdb;

    $cart_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_carts
        )
    );

    $items_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_cart_items
        )
    );

    return !empty($cart_exists) && !empty($items_exists);
}

/**
 * Register cron event for cart cleanup (hourly).
 * This keeps plugin main file as a simple loader.
 */
add_action('init', 'knx_cart_register_cleanup_cron');

function knx_cart_register_cleanup_cron() {
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
        return;
    }

    if (!wp_next_scheduled('knx_cleanup_carts_event')) {
        wp_schedule_event(time(), 'hourly', 'knx_cleanup_carts_event');
    }
}

/**
 * Hook for the scheduled event.
 */
add_action('knx_cleanup_carts_event', 'knx_cleanup_abandoned_carts');

/**
 * Cleanup logic
 */
function knx_cleanup_abandoned_carts() {
    global $wpdb;

    $table_carts = $wpdb->prefix . 'knx_carts';

    // Safety: if table does not exist, skip
    $cart_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_carts
        )
    );

    if (empty($cart_exists)) {
        return;
    }

    // 1) Mark as ABANDONED after 12 hours of inactivity
    $hours_inactive = 12;

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table_carts}
             SET status = 'abandoned'
             WHERE status = 'active'
               AND updated_at < DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $hours_inactive
        )
    );

    // 2) Delete ABANDONED carts older than 7 days
    $days_to_keep_abandoned = 7;

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_carts}
             WHERE status = 'abandoned'
               AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_to_keep_abandoned
        )
    );
}
