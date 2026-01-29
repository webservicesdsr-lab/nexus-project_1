<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Global Helper Functions (v2)
 *
 * Handles secure session validation, role hierarchy,
 * and access guards for all restricted modules.
 */

/**
 * Get the current active session.
 * Returns a user object if valid, otherwise false.
 */
function knx_get_session() {
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'knx_sessions';
    $users_table    = $wpdb->prefix . 'knx_users';

    if (empty($_COOKIE['knx_session'])) {
        return false;
    }

    $token = sanitize_text_field($_COOKIE['knx_session']);
    $query = $wpdb->prepare("
        SELECT s.*, u.id AS user_id, u.username, u.email, u.role, u.status
        FROM $sessions_table s
        JOIN $users_table u ON s.user_id = u.id
        WHERE s.token = %s
        AND s.expires_at > NOW()
        AND u.status = 'active'
        LIMIT 1
    ", $token);

    $session = $wpdb->get_row($query);
    return $session ? $session : false;
}

/**
 * Require a minimum role hierarchy.
 * Returns the session object or false if unauthorized.
 */
function knx_require_role($role = 'user') {
    $session = knx_get_session();
    if (!$session) {
        return false;
    }

    $hierarchy = [
        'user'           => 1,
        'customer'       => 1,
        'menu_uploader'  => 2,
        'hub_management' => 3,
        'manager'        => 4,
        'super_admin'    => 5
    ];

    $user_role = $session->role;

    if (!isset($hierarchy[$user_role]) || !isset($hierarchy[$role])) {
        return false;
    }

    if ($hierarchy[$user_role] < $hierarchy[$role]) {
        return false;
    }

    return $session;
}

/**
 * Guard a restricted page or shortcode.
 * If unauthorized, redirect safely to the login page.
 */
function knx_guard($required_role = 'user') {
    $session = knx_require_role($required_role);

    if (!$session) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    return $session;
}

/**
 * Secure logout handler.
 * Deletes the current session and clears the cookie.
 */
function knx_logout_user() {
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'knx_sessions';

    if (isset($_COOKIE['knx_session'])) {
        $token = sanitize_text_field($_COOKIE['knx_session']);

        // Delete session from database
        $wpdb->delete($sessions_table, ['token' => $token]);

        // Clear browser cookie securely
        setcookie('knx_session', '', time() - 3600, '/', '', is_ssl(), true);
    }

    // Ensure user is redirected to home or login
    wp_safe_redirect(site_url('/login'));
    exit;
}


/**
 * Return the canonical KNX table name for a logical resource.
 *
 * Usage: knx_table('items_categories') => "$wpdb->prefix . 'knx_items_categories'"
 * This enforces the rule that all KNX tables are named using the WP DB prefix
 * + the "knx_" namespace (e.g. Z7E_knx_items_categories). We intentionally
 * *do not* fallback to legacy or bare table names to avoid collisions with
 * other plugins/tables.
 */
function knx_table($name) {
    global $wpdb;
    $clean = preg_replace('/[^a-z0-9_]/i', '', $name);
    return $wpdb->prefix . 'knx_' . $clean;
}

/**
 * Resolve the items categories table name (canonical).
 */
function knx_items_categories_table() {
    return knx_table('items_categories');
}

/**
 * Generate a clean, SEO-friendly slug from hub name.
 * Removes special characters, accents, and ensures WordPress compatibility.
 * 
 * @param string $name The hub name to slugify
 * @param int $hub_id Optional hub ID for fallback if name is empty
 * @return string Clean slug containing only [a-z0-9-]
 */
function knx_slugify_hub_name($name, $hub_id = 0) {
    // Return fallback if name is empty
    if (empty(trim($name))) {
        return $hub_id ? "hub-{$hub_id}" : 'local-hub';
    }
    
    // Use WordPress sanitize_title for initial cleaning
    $slug = sanitize_title($name);
    
    // Additional cleanup to ensure only [a-z0-9-]
    $slug = strtolower($slug);
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    
    // Collapse multiple hyphens into single ones
    $slug = preg_replace('/-+/', '-', $slug);
    
    // Trim hyphens from start and end
    $slug = trim($slug, '-');
    
    // Final fallback if somehow empty after cleaning
    if (empty($slug)) {
        return $hub_id ? "hub-{$hub_id}" : 'local-hub';
    }
    
    return $slug;
}

/**
 * Migrate existing hubs with empty slugs to generate proper slugs.
 * This function should be called once after deploying the slug functionality.
 * 
 * @return array Results of the migration
 */
function knx_migrate_hub_slugs() {
    global $wpdb;
    
    $table_hubs = $wpdb->prefix . 'knx_hubs';
    $updated = 0;
    $errors = 0;
    
    // Find hubs with empty or null slugs
    $hubs = $wpdb->get_results("
        SELECT id, name
        FROM {$table_hubs}
        WHERE slug IS NULL OR slug = ''
        ORDER BY id ASC
    ");
    
    if (!$hubs) {
        return [
            'updated' => 0,
            'errors' => 0,
            'message' => 'No hubs need slug migration'
        ];
    }
    
    foreach ($hubs as $hub) {
        $new_slug = knx_slugify_hub_name($hub->name, $hub->id);
        
        $result = $wpdb->update(
            $table_hubs,
            ['slug' => $new_slug],
            ['id' => $hub->id],
            ['%s'],
            ['%d']
        );
        
        if ($result !== false) {
            $updated++;
        } else {
            $errors++;
        }
    }
    
    return [
        'updated' => $updated,
        'errors' => $errors,
        'message' => "Migration completed: {$updated} hubs updated, {$errors} errors"
    ];
}

/**
 * ==========================================================
 * CANONICAL CART TOKEN RESOLVER (Phase 1.5 FIX 1)
 * ----------------------------------------------------------
 * Single source of truth for resolving cart tokens across all
 * cart-related endpoints (checkout, create order, etc).
 * 
 * Priority:
 *   1. body.session_token (explicit)
 *   2. cookie knx_cart_token (fallback)
 * 
 * Security: If BOTH exist and mismatch → flag for blocking (403)
 * 
 * @param WP_REST_Request $req The REST request object
 * @return array {
 *   'token' => string|null,    // Resolved token or null
 *   'source' => string,        // 'body', 'cookie', 'none'
 *   'mismatch' => bool         // True if body and cookie exist but differ
 * }
 * ==========================================================
 */
function knx_resolve_cart_token(WP_REST_Request $req) {
    // Extract from body
    $body = $req->get_json_params();
    $body_token = isset($body['session_token']) && is_string($body['session_token'])
        ? sanitize_text_field($body['session_token'])
        : '';
    
    // Extract from cookie
    $cookie_token = isset($_COOKIE['knx_cart_token']) && is_string($_COOKIE['knx_cart_token'])
        ? sanitize_text_field($_COOKIE['knx_cart_token'])
        : '';
    
    // Check for mismatch (security hardening)
    $mismatch = false;
    if ($body_token !== '' && $cookie_token !== '' && $body_token !== $cookie_token) {
        $mismatch = true;
    }
    
    // Determine resolved token and source
    $resolved_token = null;
    $source = 'none';
    
    if ($body_token !== '') {
        $resolved_token = $body_token;
        $source = 'body';
    } elseif ($cookie_token !== '') {
        $resolved_token = $cookie_token;
        $source = 'cookie';
    }
    
    return [
        'token' => $resolved_token,
        'source' => $source,
        'mismatch' => $mismatch,
    ];
}


/**
 * Get the current driver context (fail-closed).
 *
 * Returns an object with keys: `driver_id`, `driver` (db row), `hubs` (array of hub ids),
 * and `session` (session object). Returns `false` when the caller is not an active
 * driver or when the driver has no assigned hubs. This enforces a fail-closed behavior
 * so driver-facing endpoints can rely on a single canonical check.
 *
 * Usage:
 *   $ctx = knx_get_driver_context();
 *   if (!$ctx) { return new WP_Error('unauthorized', 'Driver context required', ['status'=>403]); }
 */
function knx_get_driver_context() {
    // Canonical driver-only resolver.
    // - Accepts only real drivers (session.role === 'driver')
    // - Validates driver profile exists and is active
    // - Allows empty hubs
    // - Returns false for any non-driver or invalid/missing session
    global $wpdb;

    $session = knx_get_session();
    if (!$session) {
        return false;
    }

    $role = isset($session->role) ? (string) $session->role : '';
    if ($role !== 'driver') {
        return false;
    }

    $driver_id = 0;
    if (isset($session->user_id)) {
        $driver_id = intval($session->user_id);
    } elseif (isset($session->id)) {
        $driver_id = intval($session->id);
    }

    if ($driver_id <= 0) {
        return false;
    }

    // Fetch driver row (support driver_user_id or id linkage)
    $table_drivers = knx_table('drivers');
    $driver_key = 'id';
    $cols = $wpdb->get_results("SHOW COLUMNS FROM {$table_drivers}", ARRAY_A);
    if ($cols && is_array($cols)) {
        $col_names = array_map(function($c){ return $c['Field']; }, $cols);
        if (in_array('driver_user_id', $col_names, true)) {
            $driver_key = 'driver_user_id';
        } elseif (in_array('user_id', $col_names, true)) {
            $driver_key = 'user_id';
        }
    }

    $sql = $wpdb->prepare("SELECT * FROM {$table_drivers} WHERE {$driver_key} = %d LIMIT 1", $driver_id);
    $driver_row = $wpdb->get_row($sql);
    if (!$driver_row) {
        return false;
    }
    if (isset($driver_row->status) && (string)$driver_row->status !== 'active') {
        return false;
    }

    // Minimal hubs resolution: map via canonical mapping table if present.
    $hub_ids = [];
    $driver_hubs_table = knx_table('driver_hubs');
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $driver_hubs_table));
    if ($exists) {
        $dh_cols = $wpdb->get_results("SHOW COLUMNS FROM {$driver_hubs_table}", ARRAY_A);
        $dh_col_names = $dh_cols ? array_map(function($c){ return $c['Field']; }, $dh_cols) : [];
        $dh_key = null;
        if (in_array('driver_id', $dh_col_names, true)) {
            $dh_key = 'driver_id';
        } elseif (in_array('driver_user_id', $dh_col_names, true)) {
            $dh_key = 'driver_user_id';
        } elseif (in_array('user_id', $dh_col_names, true)) {
            $dh_key = 'user_id';
        }

        if ($dh_key) {
            $found = $wpdb->get_col($wpdb->prepare("SELECT hub_id FROM {$driver_hubs_table} WHERE {$dh_key} = %d", $driver_id));
            if ($found && is_array($found)) {
                $hub_ids = array_map('intval', $found);
            }
        }
    }

    // Allow empty hubs. No further inference or admin fallbacks here.

    return (object) [
        'driver_id' => $driver_id,
        'mode'      => 'driver',
        'driver'    => $driver_row,
        'hubs'      => array_values(array_map('intval', $hub_ids)),
        'session'   => $session,
    ];
}

/**
 * Acting driver resolver for admin/manager impersonation and tool flows.
 * This function may perform hub inference and fallbacks and is intentionally
 * separate from the canonical driver resolver above.
 *
 * Usage: knx_get_acting_driver_context($as_driver_id)
 *
 * Returns same object shape as knx_get_driver_context() or false on failure.
 */
function knx_get_acting_driver_context($as_driver_id = 0) {
    global $wpdb;

    $session = knx_get_session();
    if (!$session) return false;

    $role = isset($session->role) ? (string) $session->role : '';
    if (!in_array($role, ['manager', 'super_admin'], true)) {
        return false;
    }

    $as_id = intval($as_driver_id);
    if ($as_id <= 0) return false;

    $table_drivers = knx_table('drivers');
    // Support lookup by id or driver_user_id/user_id
    $driver = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_drivers} WHERE id = %d OR driver_user_id = %d OR user_id = %d LIMIT 1", $as_id, $as_id, $as_id));
    if (!$driver) return false;
    if (isset($driver->status) && (string)$driver->status !== 'active') return false;

    // Try to resolve hubs using broader heuristics (mapping table, candidate tables, hub table fallback)
    $hub_ids = [];
    $driver_hubs_table = knx_table('driver_hubs');
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $driver_hubs_table));
    if ($exists) {
        $dh_cols = $wpdb->get_results("SHOW COLUMNS FROM {$driver_hubs_table}", ARRAY_A);
        $dh_col_names = $dh_cols ? array_map(function($c){ return $c['Field']; }, $dh_cols) : [];
        $dh_key = in_array('driver_id', $dh_col_names, true) ? 'driver_id' : (in_array('user_id', $dh_col_names, true) ? 'user_id' : null);
        if ($dh_key) {
            $found = $wpdb->get_col($wpdb->prepare("SELECT hub_id FROM {$driver_hubs_table} WHERE {$dh_key} = %d", $as_id));
            if ($found && is_array($found)) $hub_ids = array_map('intval', $found);
        }
    }

    // Additional heuristics for acting context: try a few common candidate tables
    if (empty($hub_ids)) {
        $candidates = [
            'hub_managers' => ['user_col' => 'manager_id', 'hub_col' => 'hub_id'],
            'hub_admins'   => ['user_col' => 'user_id',    'hub_col' => 'hub_id'],
            'hub_users'    => ['user_col' => 'user_id',    'hub_col' => 'hub_id'],
        ];
        foreach ($candidates as $tbl => $cols) {
            $maybe_table = knx_table($tbl);
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $maybe_table));
            if (!$exists) continue;
            $safe_sql = $wpdb->prepare("SELECT {$cols['hub_col']} FROM {$maybe_table} WHERE {$cols['user_col']} = %d", $as_id);
            $found = $wpdb->get_col($safe_sql);
            if ($found && is_array($found) && count($found) > 0) {
                $hub_ids = array_map('intval', $found);
                break;
            }
        }
    }

    // If still empty, as a last resort attempt to return all hubs for super_admin
    if (empty($hub_ids) && $role === 'super_admin') {
        $hubs_table = knx_table('hubs');
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $hubs_table));
        if ($exists) {
            $found = $wpdb->get_col("SELECT id FROM {$hubs_table}");
            if ($found && is_array($found)) $hub_ids = array_map('intval', $found);
        }
    }

    return (object) [
        'driver_id' => $as_id,
        'mode'      => 'acting_driver',
        'driver'    => $driver,
        'hubs'      => array_values(array_map('intval', $hub_ids)),
        'session'   => $session,
    ];
}

/**
 * Print UI theme CSS variables when `knx_ui_theme` option exists.
 * Injects a small <style> block into head on admin and public pages.
 */
function knx_print_ui_theme_vars() {
    $opt = get_option('knx_ui_theme', null);
    if (empty($opt) || !is_array($opt)) return;

    $font = isset($opt['font']) && $opt['font'] !== '' ? $opt['font'] : '';
    $primary = isset($opt['primary']) && $opt['primary'] !== '' ? $opt['primary'] : '';
    $bg = isset($opt['bg']) && $opt['bg'] !== '' ? $opt['bg'] : '';
    $card = isset($opt['card']) && $opt['card'] !== '' ? $opt['card'] : '';

    $vars = [];
    if ($font !== '') $vars[] = "--nxs-font: {$font};";
    if ($primary !== '') $vars[] = "--nxs-primary: {$primary};";
    if ($bg !== '') $vars[] = "--nxs-bg: {$bg};";
    if ($card !== '') $vars[] = "--nxs-card: {$card};";

    if (empty($vars)) return;

    echo "\n<!-- KNX UI THEME VARIABLES -->\n<style id=\"knx-ui-theme-vars\">:root{" . implode(' ', $vars) . "}</style>\n";
}

add_action('wp_head', 'knx_print_ui_theme_vars', 1);
add_action('admin_head', 'knx_print_ui_theme_vars', 1);


/**
 * Create a server-side session for a user and set the secure cookie.
 * Returns the token string on success or false on failure.
 */
function knx_create_session(int $user_id, bool $remember = false) {
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'knx_sessions';

    $token   = knx_generate_token();
    $expires = $remember ? date('Y-m-d H:i:s', strtotime('+30 days')) : date('Y-m-d H:i:s', strtotime('+1 day'));
    $ip = function_exists('knx_get_client_ip') ? knx_get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $agent = substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);

    $inserted = $wpdb->insert($sessions_table, [
        'user_id'    => $user_id,
        'token'      => $token,
        'ip_address' => $ip,
        'user_agent' => $agent,
        'expires_at' => $expires
    ]);

    if ($inserted === false) return false;

    // Set secure cookie
    setcookie('knx_session', $token, [
        'expires'  => $remember ? time() + (30 * DAY_IN_SECONDS) : time() + DAY_IN_SECONDS,
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    return $token;
}


/**
 * Auto-login helper: creates session, claims guest cart and returns true on success.
 */
function knx_auto_login_user_by_id(int $user_id, bool $remember = false): bool {
    global $wpdb;

    $token = knx_create_session($user_id, $remember);
    if (!$token) return false;

    // Claim guest cart if present
    if (!empty($_COOKIE['knx_cart_token'])) {
        $cart_token = sanitize_text_field(wp_unslash($_COOKIE['knx_cart_token']));
        $carts_table = $wpdb->prefix . 'knx_carts';

        $guest_cart = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$carts_table}
             WHERE session_token = %s
             AND status = 'active'
             AND customer_id IS NULL
             ORDER BY updated_at DESC
             LIMIT 1",
            $cart_token
        ));

        if ($guest_cart) {
            $wpdb->update(
                $carts_table,
                ['customer_id' => $user_id],
                ['id' => $guest_cart->id],
                ['%d'],
                ['%d']
            );
        }
    }

    return true;
}

