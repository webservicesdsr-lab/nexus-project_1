<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Hub Ownership Helpers (v1.0 - Canonical)
 * ----------------------------------------------------------
 * SSOT for hub ↔ user ownership resolution.
 * All hub-management scoped pages and endpoints MUST use
 * these helpers to derive allowed hub IDs.
 *
 * Canonical table: y05_knx_hub_managers
 * ==========================================================
 */

/**
 * Get hub IDs managed by a given user.
 *
 * @param int $user_id The knx_users.id
 * @return int[] Array of hub IDs (may be empty)
 */
if (!function_exists('knx_get_managed_hub_ids')) {
    function knx_get_managed_hub_ids(int $user_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'knx_hub_managers';
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT hub_id FROM {$table} WHERE user_id = %d",
            $user_id
        ));
        return array_map('intval', $rows ?: []);
    }
}

/**
 * Check if a user owns a specific hub.
 *
 * @param int $user_id
 * @param int $hub_id
 * @return bool
 */
if (!function_exists('knx_user_owns_hub')) {
    function knx_user_owns_hub(int $user_id, int $hub_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'knx_hub_managers';
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND hub_id = %d",
            $user_id, $hub_id
        ));
        return $count > 0;
    }
}

/**
 * Validate hub-management session + ownership. Fail-closed.
 * Returns [session, hub_id] on success or false on failure.
 *
 * @param int|null $hub_id If null, reads from $_GET['hub_id'] or $_GET['id']
 * @return array{0: object, 1: int}|false
 */
if (!function_exists('knx_hub_management_guard')) {
    function knx_hub_management_guard(?int $hub_id = null) {
        $session = knx_get_session();
        if (!$session) return false;

        $role = isset($session->role) ? (string) $session->role : '';
        // Hub family roles — inline regex per spec
        if (!preg_match('/\bhub(?:[_\-\s]?(?:management|owner|staff|manager))\b/i', $role)) {
            return false;
        }

        $user_id = isset($session->user_id) ? (int) $session->user_id : 0;
        if ($user_id <= 0) return false;

        if ($hub_id === null) {
            $hub_id = isset($_GET['hub_id']) ? intval($_GET['hub_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
        }
        if ($hub_id <= 0) return false;

        if (!knx_user_owns_hub($user_id, $hub_id)) return false;

        return [$session, $hub_id];
    }
}

/**
 * REST-flavored ownership check. Returns WP_REST_Response error or [session, hub_id].
 *
 * @param WP_REST_Request $request
 * @return array{0: object, 1: int}|WP_REST_Response
 */
if (!function_exists('knx_rest_hub_management_guard')) {
    function knx_rest_hub_management_guard(WP_REST_Request $request) {
        $session = knx_rest_require_session();
        if ($session instanceof WP_REST_Response) return $session;

        $role = isset($session->role) ? (string) $session->role : '';

        // Super admin and manager bypass ownership (they see everything)
        if (in_array($role, ['super_admin', 'manager'], true)) {
            $hub_id = intval($request->get_param('hub_id') ?: $request->get_param('id') ?: 0);
            if ($hub_id <= 0) return knx_rest_error('Missing hub_id', 400);
            return [$session, $hub_id];
        }

        // Hub management role — require ownership
        if (!preg_match('/\bhub(?:[_\-\s]?(?:management|owner|staff|manager))\b/i', $role)) {
            return knx_rest_error('Forbidden', 403);
        }

        $user_id = isset($session->user_id) ? (int) $session->user_id : 0;
        if ($user_id <= 0) return knx_rest_error('Invalid session', 401);

        $hub_id = intval($request->get_param('hub_id') ?: $request->get_param('id') ?: 0);
        if ($hub_id <= 0) return knx_rest_error('Missing hub_id', 400);

        if (!knx_user_owns_hub($user_id, $hub_id)) {
            return knx_rest_error('Forbidden', 403);
        }

        return [$session, $hub_id];
    }
}
