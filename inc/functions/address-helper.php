<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX ADDRESSES — HELPERS (SSOT + DB-SAFE)
 * ----------------------------------------------------------
 * Goals:
 * - No REST registration
 * - No UI output
 * - Fail-closed if table/columns are unknown
 * - Column-detection to avoid schema assumptions (db-install frozen)
 * ==========================================================
 */

if (!function_exists('knx_addresses_table')) {
    function knx_addresses_table() {
        global $wpdb;
        return $wpdb->prefix . 'knx_addresses';
    }
}

/**
 * ==========================================================
 * KNX Addresses — Active Address Getter
 * ==========================================================
 * Returns address ONLY if:
 * - belongs to customer
 * - status = active
 * - deleted_at IS NULL
 * ==========================================================
 */
function knx_get_address_by_id_active($address_id, $customer_id) {
    $row = knx_get_address_by_id($address_id, $customer_id);
    if (!$row) return null;

    if (!empty($row->deleted_at)) return null;
    if (isset($row->status) && $row->status !== 'active') return null;

    return $row;
}

if (!function_exists('knx_addresses_now_mysql')) {
    function knx_addresses_now_mysql() {
        return function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('knx_addresses_table_exists')) {
    function knx_addresses_table_exists() {
        global $wpdb;
        static $exists = null;

        if ($exists !== null) return $exists;

        $table  = knx_addresses_table();
        $result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        $exists = ($result === $table);

        return $exists;
    }
}

if (!function_exists('knx_addresses_columns')) {
    function knx_addresses_columns() {
        global $wpdb;
        static $cols = null;

        if ($cols !== null) return $cols;

        $cols = [];
        if (!knx_addresses_table_exists()) return $cols;

        $table = knx_addresses_table();
        $rows  = $wpdb->get_results("SHOW COLUMNS FROM {$table}");

        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!empty($r->Field)) $cols[] = (string) $r->Field;
            }
        }

        return $cols;
    }
}

if (!function_exists('knx_addresses_has_col')) {
    function knx_addresses_has_col($col) {
        $col = (string) $col;
        $cols = knx_addresses_columns();
        return in_array($col, $cols, true);
    }
}

if (!function_exists('knx_addresses_trim')) {
    function knx_addresses_trim($value, $maxLen = 255) {
        $v = trim((string) $value);
        if ($v === '') return null;
        if ($maxLen > 0) $v = mb_substr($v, 0, $maxLen);
        return $v;
    }
}

if (!function_exists('knx_addresses_normalize_coords')) {
    /**
     * Normalize and validate coordinates.
     * Returns [lat|null, lng|null].
     */
    function knx_addresses_normalize_coords($lat, $lng) {
        if ($lat === '' || $lat === false) $lat = null;
        if ($lng === '' || $lng === false) $lng = null;

        if ($lat === null || $lng === null) return [null, null];
        if (!is_numeric($lat) || !is_numeric($lng)) return [null, null];

        $lat = (float) $lat;
        $lng = (float) $lng;

        // Reject zeroed coords (common "unset")
        if ($lat == 0.0 && $lng == 0.0) return [null, null];

        // Range validation
        if ($lat < -90 || $lat > 90) return [null, null];
        if ($lng < -180 || $lng > 180) return [null, null];

        // Keep precision consistent with typical decimal(10,7)
        $lat = round($lat, 7);
        $lng = round($lng, 7);

        return [$lat, $lng];
    }
}

if (!function_exists('knx_addresses_format_one_line')) {
    /**
     * Format an address row into a single text line (for snapshots).
     */
    function knx_addresses_format_one_line($addr) {
        if (!$addr) return '';

        $parts = [];

        $line1 = isset($addr->line1) ? trim((string) $addr->line1) : '';
        $line2 = isset($addr->line2) ? trim((string) $addr->line2) : '';
        $city  = isset($addr->city) ? trim((string) $addr->city) : '';
        $state = isset($addr->state) ? trim((string) $addr->state) : '';
        $zip   = isset($addr->postal_code) ? trim((string) $addr->postal_code) : '';
        $ctry  = isset($addr->country) ? trim((string) $addr->country) : 'USA';

        if ($line1 !== '') $parts[] = $line1;
        if ($line2 !== '') $parts[] = $line2;

        $cityStateZip = trim(
            $city
            . ($state !== '' ? ', ' . $state : '')
            . ($zip !== '' ? ' ' . $zip : '')
        );
        if ($cityStateZip !== '') $parts[] = $cityStateZip;

        if ($ctry !== '') $parts[] = $ctry;

        return implode(' • ', $parts);
    }
}

/**
 * ==========================================================
 * Active Row Filter (DB-safe, schema flexible)
 * ==========================================================
 */
if (!function_exists('knx_addresses_active_where_sql')) {
    /**
     * Returns an SQL fragment like: "AND is_deleted = 0 AND deleted_at IS NULL ..."
     * If no known active/deleted markers exist, FAIL-CLOSED and return "AND 1=0".
     */
    function knx_addresses_active_where_sql() {
        $clauses = [];

        $has_any_marker = false;

        if (knx_addresses_has_col('is_deleted')) {
            $clauses[] = "is_deleted = 0";
            $has_any_marker = true;
        }
        if (knx_addresses_has_col('deleted_at')) {
            $clauses[] = "deleted_at IS NULL";
            $has_any_marker = true;
        }
        if (knx_addresses_has_col('status')) {
            $clauses[] = "status = 'active'";
            $has_any_marker = true;
        }

        if (!$has_any_marker) {
            // Fail-closed: we refuse to guess "active" semantics without markers.
            return "AND 1=0";
        }

        return "AND " . implode(" AND ", $clauses);
    }
}

/**
 * ==========================================================
 * DB Reads
 * ==========================================================
 */
if (!function_exists('knx_addresses_list_for_customer')) {
    function knx_addresses_list_for_customer($customer_id) {
        global $wpdb;

        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) return [];
        if (!knx_addresses_table_exists()) return [];

        $table = knx_addresses_table();

        // Only return active (non-deleted) rows for UI/checkout
        $active_sql = knx_addresses_active_where_sql();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE customer_id = %d
             {$active_sql}
             ORDER BY " . (knx_addresses_has_col('is_default') ? "is_default DESC," : "") . " id DESC",
            $customer_id
        ));

        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('knx_get_address_by_id')) {
    function knx_get_address_by_id($address_id, $customer_id) {
        global $wpdb;

        $address_id  = (int) $address_id;
        $customer_id = (int) $customer_id;

        if ($address_id <= 0 || $customer_id <= 0) return null;
        if (!knx_addresses_table_exists()) return null;

        $table = knx_addresses_table();

        // DB = SSOT (ownership only, no active filters)
        return $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE id = %d
             AND customer_id = %d
             LIMIT 1",
            $address_id,
            $customer_id
        ));
    }
}if (!function_exists('knx_get_default_address')) {
    function knx_get_default_address($customer_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'knx_addresses';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE customer_id = %d
               AND is_default = 1
               AND status = 'active'
               AND deleted_at IS NULL
             LIMIT 1",
            $customer_id
        ));
    }
}if (!function_exists('knx_clear_default_address')) {
    function knx_clear_default_address($customer_id) {
        global $wpdb;

        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) return false;
        if (!knx_addresses_table_exists()) return false;

        // If schema has no is_default, treat as no-op success.
        if (!knx_addresses_has_col('is_default')) return true;

        $table = knx_addresses_table();
        $res = $wpdb->update(
            $table,
            ['is_default' => 0],
            ['customer_id' => $customer_id],
            ['%d'],
            ['%d']
        );

        return ($res !== false);
    }
}

if (!function_exists('knx_set_default_address')) {
    function knx_set_default_address($address_id, $customer_id) {
        global $wpdb;

        $address_id  = (int) $address_id;
        $customer_id = (int) $customer_id;

        if ($address_id <= 0 || $customer_id <= 0) return false;
        if (!knx_addresses_table_exists()) return false;
        if (!knx_addresses_has_col('is_default')) return false;

        $addr = knx_get_address_by_id($address_id, $customer_id);
        if (!$addr) return false;

        $table = knx_addresses_table();

        // Clear previous defaults first.
        if (!knx_clear_default_address($customer_id)) return false;

        $updated = $wpdb->update(
            $table,
            ['is_default' => 1],
            ['id' => $address_id, 'customer_id' => $customer_id],
            ['%d'],
            ['%d', '%d']
        );

        return ($updated !== false);
    }
}

/**
 * ==========================================================
 * Cookie helpers (used for selected address SSOT)
 * ==========================================================
 */
if (!function_exists('knx_cookie_set')) {
    function knx_cookie_set($name, $value, $ttl_seconds, $path = '/', $httponly = true, $samesite = 'Strict') {
        $secure = is_ssl();

        if (headers_sent()) {
            // Mirror runtime only
            $_COOKIE[$name] = (string) $value;
            return;
        }

        $expires = time() + (int) $ttl_seconds;

        // PHP 7.3+ supports options array with SameSite.
        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, (string) $value, [
                'expires'  => $expires,
                'path'     => $path,
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ]);
        } else {
            // Best-effort legacy.
            setcookie($name, (string) $value, $expires, $path, COOKIE_DOMAIN ?: '', $secure, $httponly);
        }

        $_COOKIE[$name] = (string) $value;
    }
}

if (!function_exists('knx_cookie_clear')) {
    function knx_cookie_clear($name, $path = '/', $httponly = true, $samesite = 'Strict') {
        $secure = is_ssl();

        if (!headers_sent()) {
            if (PHP_VERSION_ID >= 70300) {
                setcookie($name, '', [
                    'expires'  => time() - 3600,
                    'path'     => $path,
                    'domain'   => COOKIE_DOMAIN ?: '',
                    'secure'   => $secure,
                    'httponly' => $httponly,
                    'samesite' => $samesite,
                ]);
            } else {
                setcookie($name, '', time() - 3600, $path, COOKIE_DOMAIN ?: '', $secure, $httponly);
            }
        }

        unset($_COOKIE[$name]);
    }
}

/**
 * ==========================================================
 * SESSION SSOT — Selected Address (DB-backed)
 * ==========================================================
 * Migrated from Cookie/Session to DB for production security.
 * Uses knx_addresses.is_default as single source of truth.
 * Cookie serves as performance cache only.
 */
if (!function_exists('knx_session_get_selected_address_id')) {
    function knx_session_get_selected_address_id() {
        global $wpdb;
        
        // Get customer_id from current session
        $customer_id = 0;
        if (function_exists('knx_addresses_get_customer_id')) {
            $customer_id = knx_addresses_get_customer_id();
        }
        
        if ($customer_id <= 0) return 0;
        
        // Read from DB (SSOT)
        $table = $wpdb->prefix . 'knx_addresses';
        $address = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE customer_id = %d
                   AND is_default = 1
                   AND status = 'active'
                   AND deleted_at IS NULL
                 LIMIT 1",
                $customer_id
            )
        );
        
        if ($address && isset($address->id)) {
            $address_id = (int) $address->id;
            
            // Sync cookie cache for performance
            if (!isset($_COOKIE['knx_selected_address_id']) || (int) $_COOKIE['knx_selected_address_id'] !== $address_id) {
                $ttl = defined('DAY_IN_SECONDS') ? (30 * DAY_IN_SECONDS) : (30 * 24 * 60 * 60);
                knx_cookie_set('knx_selected_address_id', (string) $address_id, $ttl);
            }
            
            return $address_id;
        }
        
        return 0;
    }
}

if (!function_exists('knx_session_set_selected_address_id')) {
    /**
     * SSOT: Set selected address ID by updating is_default in DB
     * Clears previous default, sets new one atomically.
     */
    function knx_session_set_selected_address_id($address_id) {
        global $wpdb;
        
        $address_id = (int) $address_id;
        if ($address_id <= 0) return false;
        
        // Get customer_id from current session
        $customer_id = 0;
        if (function_exists('knx_addresses_get_customer_id')) {
            $customer_id = knx_addresses_get_customer_id();
        }
        
        if ($customer_id <= 0) return false;
        
        $table = $wpdb->prefix . 'knx_addresses';
        
        // Transaction: Clear old default + Set new default atomically
        $wpdb->query('START TRANSACTION');
        
        try {
            // Step 1: Clear all is_default for this customer
            $wpdb->update(
                $table,
                ['is_default' => 0],
                ['customer_id' => $customer_id],
                ['%d'],
                ['%d']
            );
            
            // Step 2: Set new default (with ownership check)
            $updated = $wpdb->update(
                $table,
                ['is_default' => 1],
                [
                    'id' => $address_id,
                    'customer_id' => $customer_id,
                    'status' => 'active'
                ],
                ['%d'],
                ['%d', '%d', '%s']
            );
            
            if ($updated === false) {
                throw new Exception("DB update failed: " . $wpdb->last_error);
            }
            
            if ($updated === 0) {
                throw new Exception("Address not found or not owned by customer");
            }
            
            $wpdb->query('COMMIT');
            
            // Sync cookie cache
            $ttl = defined('DAY_IN_SECONDS') ? (30 * DAY_IN_SECONDS) : (30 * 24 * 60 * 60);
            knx_cookie_set('knx_selected_address_id', (string) $address_id, $ttl);
            
            return true;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }
}

if (!function_exists('knx_session_clear_selected_address_id')) {
    function knx_session_clear_selected_address_id() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['knx_selected_address_id']);
        }

        knx_cookie_clear('knx_selected_address_id');
    }
}

/**
 * ==========================================================
 * SSOT Orchestrator
 * Priority: session/cookie → DB default → 0
 * ==========================================================
 */
if (!function_exists('knx_addresses_get_selected_id_for_customer')) {
    function knx_addresses_get_selected_id_for_customer($customer_id) {
        // 1) Session / Cookie SSOT
        $selected_id = knx_session_get_selected_address_id();

        if ($selected_id > 0) {
            $addr = knx_get_address_by_id_active($selected_id, $customer_id);
            if ($addr) {
                return (int) $selected_id;
            }

            // Invalid or deleted → clear selection
            knx_session_clear_selected_address_id();
        }

        // 2) Fallback to DEFAULT (active only)
        $default = knx_get_default_address($customer_id);
        if ($default && !empty($default->id)) {
            return (int) $default->id;
        }

        // 3) No address selected
        return 0;
    }
}

/**
 * ==========================================================
 * PHASE 4.3: CART HUB RETRIEVAL (FOR COVERAGE GATE)
 * ==========================================================
 * Retrieve hub_id from active cart for coverage enforcement.
 * Fail-open: returns 0 if no cart or no hub (allows selection).
 * Used only for coverage gate in address selection.
 * ==========================================================
 */
if (!function_exists('knx_addresses_get_cart_hub_id')) {
    function knx_addresses_get_cart_hub_id($customer_id) {
        global $wpdb;

        // Resolve cart token from cookie
        $session_token = '';
        if (function_exists('knx_get_cart_token')) {
            $session_token = (string) knx_get_cart_token();
        } elseif (!empty($_COOKIE['knx_cart_token'])) {
            $session_token = sanitize_text_field(wp_unslash($_COOKIE['knx_cart_token']));
        }

        // Fail-open: no token → no enforcement
        if ($session_token === '') {
            return 0;
        }

        $table_carts = $wpdb->prefix . 'knx_carts';

        // Query latest active cart for this session
        $cart = $wpdb->get_row($wpdb->prepare(
            "SELECT hub_id
             FROM {$table_carts}
             WHERE session_token = %s
               AND status = 'active'
             ORDER BY updated_at DESC
             LIMIT 1",
            $session_token
        ));

        // Fail-open: no cart or no hub → no enforcement
        if (!$cart || !isset($cart->hub_id)) {
            return 0;
        }

        return (int) $cart->hub_id;
    }
}

/**
 * ==========================================================
 * KNX-A4.1: ADDRESS CRUD (SSOT)
 * ==========================================================
 * Create, update, delete addresses with guards and ownership checks.
 * Fail-closed architecture.
 * ==========================================================
 */

/**
 * Create new address for customer
 * 
 * @param int $customer_id Customer ID (ownership)
 * @param array $data Address data
 *   - label (required)
 *   - line1 (required) - maps to address_line_1
 *   - line2 (optional) - maps to address_line_2
 *   - city (required)
 *   - state (optional)
 *   - postal_code (optional)
 *   - country (optional)
 *   - latitude (required for delivery)
 *   - longitude (required for delivery)
 *   - is_default (optional, bool)
 * @return array ['ok' => bool, 'address_id' => int|null, 'error' => string|null]
 */
if (!function_exists('knx_create_address')) {
    function knx_create_address($customer_id, $data) {
        global $wpdb;

        // Guard A: Table exists
        if (!knx_addresses_table_exists()) {
            return ['ok' => false, 'address_id' => null, 'error' => 'Addresses table not found'];
        }

        // Guard B: Customer ID valid
        if (empty($customer_id) || !is_numeric($customer_id)) {
            return ['ok' => false, 'address_id' => null, 'error' => 'Invalid customer ID'];
        }

        $customer_id = (int) $customer_id;

        // Guard C: Required fields (using DB column names)
        $required = ['label', 'line1', 'city'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['ok' => false, 'address_id' => null, 'error' => "Missing required field: $field"];
            }
        }

        // Guard D: Coordinates required (A4.1 rule: no partial addresses)
        if (!isset($data['latitude']) || !isset($data['longitude'])) {
            return ['ok' => false, 'address_id' => null, 'error' => 'Coordinates required (latitude/longitude)'];
        }

        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];

        if ($lat === 0.0 && $lng === 0.0) {
            return ['ok' => false, 'address_id' => null, 'error' => 'Invalid coordinates'];
        }

        // Prepare data (using actual DB column names)
        $insert_data = [
            'customer_id' => $customer_id,
            'label' => knx_addresses_trim($data['label']),
            'line1' => knx_addresses_trim($data['line1']),
            'line2' => isset($data['line2']) ? knx_addresses_trim($data['line2']) : '',
            'city' => knx_addresses_trim($data['city']),
            'state' => isset($data['state']) ? knx_addresses_trim($data['state']) : '',
            'postal_code' => isset($data['postal_code']) ? knx_addresses_trim($data['postal_code']) : '',
            'country' => isset($data['country']) ? knx_addresses_trim($data['country']) : '',
            'latitude' => $lat,
            'longitude' => $lng,
            'is_default' => !empty($data['is_default']) ? 1 : 0,
            'created_at' => knx_addresses_now_mysql(),
            'updated_at' => knx_addresses_now_mysql()
        ];

        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%s', '%s'];

        // If setting as default, clear other defaults first
        if ($insert_data['is_default'] === 1) {
            knx_clear_default_address($customer_id);
        }

        $table = knx_addresses_table();
        $result = $wpdb->insert($table, $insert_data, $formats);

        if ($result === false) {
            error_log("[KNX-A4.1] Failed to create address: customer_id=$customer_id");
            return ['ok' => false, 'address_id' => null, 'error' => 'Database insert failed'];
        }

        $address_id = $wpdb->insert_id;
        error_log("[KNX-A4.1] Address created: address_id=$address_id customer_id=$customer_id");

        return ['ok' => true, 'address_id' => $address_id, 'error' => null];
    }
}

/**
 * Update existing address
 * 
 * @param int $address_id Address ID
 * @param int $customer_id Customer ID (ownership check)
 * @param array $data Address data (same as create)
 * @return array ['ok' => bool, 'error' => string|null]
 */
if (!function_exists('knx_update_address')) {
    function knx_update_address($address_id, $customer_id, $data) {
        global $wpdb;

        // Guard A: Table exists
        if (!knx_addresses_table_exists()) {
            return ['ok' => false, 'error' => 'Addresses table not found'];
        }

        // Guard B: Valid IDs
        if (empty($address_id) || !is_numeric($address_id)) {
            return ['ok' => false, 'error' => 'Invalid address ID'];
        }

        if (empty($customer_id) || !is_numeric($customer_id)) {
            return ['ok' => false, 'error' => 'Invalid customer ID'];
        }

        $address_id = (int) $address_id;
        $customer_id = (int) $customer_id;

        // Guard C: Ownership check
        $existing = knx_get_address_by_id($address_id, $customer_id);
        if (!$existing) {
            error_log("[KNX-A4.1] Update blocked: address_id=$address_id customer_id=$customer_id (not found or not owned)");
            return ['ok' => false, 'error' => 'Address not found or access denied'];
        }

        // Prepare update data (only provided fields) - using actual DB column names
        $update_data = ['updated_at' => knx_addresses_now_mysql()];
        $formats = ['%s'];

        if (isset($data['label'])) {
            $update_data['label'] = knx_addresses_trim($data['label']);
            $formats[] = '%s';
        }

        if (isset($data['line1'])) {
            $update_data['line1'] = knx_addresses_trim($data['line1']);
            $formats[] = '%s';
        }

        if (isset($data['line2'])) {
            $update_data['line2'] = knx_addresses_trim($data['line2']);
            $formats[] = '%s';
        }

        if (isset($data['city'])) {
            $update_data['city'] = knx_addresses_trim($data['city']);
            $formats[] = '%s';
        }

        if (isset($data['state'])) {
            $update_data['state'] = knx_addresses_trim($data['state']);
            $formats[] = '%s';
        }

        if (isset($data['postal_code'])) {
            $update_data['postal_code'] = knx_addresses_trim($data['postal_code']);
            $formats[] = '%s';
        }

        if (isset($data['country'])) {
            $update_data['country'] = knx_addresses_trim($data['country']);
            $formats[] = '%s';
        }

        if (isset($data['latitude'])) {
            $update_data['latitude'] = (float) $data['latitude'];
            $formats[] = '%f';
        }

        if (isset($data['longitude'])) {
            $update_data['longitude'] = (float) $data['longitude'];
            $formats[] = '%f';
        }

        if (isset($data['is_default'])) {
            $new_default = !empty($data['is_default']) ? 1 : 0;
            
            // If setting as default, clear other defaults first
            if ($new_default === 1) {
                knx_clear_default_address($customer_id);
            }
            
            $update_data['is_default'] = $new_default;
            $formats[] = '%d';
        }

        $table = knx_addresses_table();
        $result = $wpdb->update(
            $table,
            $update_data,
            ['id' => $address_id, 'customer_id' => $customer_id],
            $formats,
            ['%d', '%d']
        );

        if ($result === false) {
            error_log("[KNX-A4.1] Failed to update address: address_id=$address_id customer_id=$customer_id");
            return ['ok' => false, 'error' => 'Database update failed'];
        }

        error_log("[KNX-A4.1] Address updated: address_id=$address_id customer_id=$customer_id");

        return ['ok' => true, 'error' => null];
    }
}

/**
 * Delete address
 * 
 * @param int $address_id Address ID
 * @param int $customer_id Customer ID (ownership check)
 * @return array ['ok' => bool, 'error' => string|null]
 */
if (!function_exists('knx_delete_address')) {
    function knx_delete_address($address_id, $customer_id) {
        global $wpdb;

        // Guard A: Table exists
        if (!knx_addresses_table_exists()) {
            return ['ok' => false, 'error' => 'Addresses table not found'];
        }

        // Guard B: Valid IDs
        if (empty($address_id) || !is_numeric($address_id)) {
            return ['ok' => false, 'error' => 'Invalid address ID'];
        }

        if (empty($customer_id) || !is_numeric($customer_id)) {
            return ['ok' => false, 'error' => 'Invalid customer ID'];
        }

        $address_id = (int) $address_id;
        $customer_id = (int) $customer_id;

        // Guard C: Ownership check
        $existing = knx_get_address_by_id($address_id, $customer_id);
        if (!$existing) {
            error_log("[KNX-A4.1] Delete blocked: address_id=$address_id customer_id=$customer_id (not found or not owned)");
            return ['ok' => false, 'error' => 'Address not found or access denied'];
        }

        $table = knx_addresses_table();
        $now   = knx_addresses_now_mysql();

        $res = $wpdb->update(
            $table,
            [
                'deleted_at' => $now,
                'status'     => 'deleted',
                'is_default' => 0,
            ],
            [
                'id' => $address_id,
                'customer_id' => $customer_id,
            ],
            ['%s', '%s', '%d'],
            ['%d', '%d']
        );

        if ($res === false) {
            error_log("[KNX-A4.1] Failed to soft-delete address: address_id=$address_id customer_id=$customer_id");
            return ['ok' => false, 'error' => 'Database soft-delete failed'];
        }

        // Clear SSOT if needed
        $selected_id = knx_addresses_get_selected_id_for_customer($customer_id);
        if ((int)$selected_id === $address_id) {
            knx_session_clear_selected_address_id();
        }

        error_log("[KNX-A4.1] Address soft-deleted: address_id=$address_id customer_id=$customer_id");

        return ['ok' => true, 'error' => null];
    }
}
