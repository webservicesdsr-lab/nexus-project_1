<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Customer Domain Helpers (PHASE 2.BETA+)
 * ----------------------------------------------------------
 * Pure functions for customer profile and snapshot logic.
 * NO side effects, NO redirects, NO UI.
 * ==========================================================
 */

if (!function_exists('knx_db_column_exists')) {
    /**
     * Check if a column exists in a table (safe identifier handling).
     *
     * @param string $table  Full table name (already prefixed).
     * @param string $column Column name.
     * @return bool
     */
    function knx_db_column_exists($table, $column) {
        global $wpdb;

        $table  = (string) $table;
        $column = (string) $column;

        // Defensive: only allow simple identifiers to avoid injection via identifiers.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;

        $sql = "SHOW COLUMNS FROM `{$table}` LIKE %s";
        $found = $wpdb->get_var($wpdb->prepare($sql, $column));
        return !empty($found);
    }
}

/**
 * ==========================================================
 * BLOQUE B1 — Profile Completeness Engine (CANON)
 * ----------------------------------------------------------
 * Determine if a customer profile is complete.
 * SSOT for profile readiness.
 *
 * Required fields (Phase 2.BETA+):
 * - name
 * - phone
 * ==========================================================
 *
 * @param int $user_id Customer ID from knx_users
 * @return array ['complete' => bool, 'missing' => array, 'schema_missing' => bool]
 */
function knx_profile_status($user_id) {
    global $wpdb;

    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return [
            'complete'       => false,
            'missing'        => ['user_id'],
            'schema_missing' => false,
        ];
    }

    $users_table = $wpdb->prefix . 'knx_users';

    // Schema check (your DB dump currently does NOT include these columns)
    $has_name  = knx_db_column_exists($users_table, 'name');
    $has_phone = knx_db_column_exists($users_table, 'phone');

    if (!$has_name || !$has_phone) {
        $missing = [];
        if (!$has_name)  $missing[] = 'schema:name';
        if (!$has_phone) $missing[] = 'schema:phone';

        return [
            'complete'       => false,
            'missing'        => $missing,
            'schema_missing' => true,
        ];
    }

    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT name, phone FROM {$users_table} WHERE id = %d",
        $user_id
    ));

    if (!$user) {
        return [
            'complete'       => false,
            'missing'        => ['user_not_found'],
            'schema_missing' => false,
        ];
    }

    $missing = [];
    if (empty($user->name))  $missing[] = 'name';
    if (empty($user->phone)) $missing[] = 'phone';

    return [
        'complete'       => empty($missing),
        'missing'        => $missing,
        'schema_missing' => false,
    ];
}

/**
 * ==========================================================
 * BLOQUE C1 — Customer Snapshot Contract (PRE-ORDER)
 * ----------------------------------------------------------
 * Generate a snapshot of customer data for order creation.
 * Read-only, no side effects.
 * ==========================================================
 *
 * @param int $user_id Customer ID from knx_users
 * @return array ['customer_id' => int|null, 'name' => string|null, 'phone' => string|null, 'email' => string|null]
 */
function knx_customer_snapshot($user_id) {
    global $wpdb;

    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return [
            'customer_id' => null,
            'name'        => null,
            'phone'       => null,
            'email'       => null,
        ];
    }

    $users_table = $wpdb->prefix . 'knx_users';

    $has_name  = knx_db_column_exists($users_table, 'name');
    $has_phone = knx_db_column_exists($users_table, 'phone');

    // Always fetch email; name/phone only if schema supports it.
    $select = "id, email";
    if ($has_name)  $select .= ", name";
    if ($has_phone) $select .= ", phone";

    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT {$select} FROM {$users_table} WHERE id = %d",
        $user_id
    ));

    if (!$user) {
        return [
            'customer_id' => null,
            'name'        => null,
            'phone'       => null,
            'email'       => null,
        ];
    }

    return [
        'customer_id' => (int) $user->id,
        'name'        => $has_name  ? (string) ($user->name ?? '')  : null,
        'phone'       => $has_phone ? (string) ($user->phone ?? '') : null,
        'email'       => (string) ($user->email ?? ''),
    ];
}
