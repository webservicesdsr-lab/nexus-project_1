<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX REST — Guard Helpers (PHASE 1)
 * ----------------------------------------------------------
 * - No hooks
 * - No endpoints
 * - No output
 * - Provides permission_callback helpers
 * ==========================================================
 */

if (!function_exists('knx_rest_get_session')) {
    /**
     * Returns current session or false.
     *
     * SSOT: Delegates entirely to knx_get_session() (inc/functions/helpers.php).
     * Does NOT implement session logic — only wraps canonical function.
     *
     * @return object|false
     */
    function knx_rest_get_session() {
        if (function_exists('knx_get_session')) {
            return knx_get_session();
        }
        return false;
    }
}

    if (!function_exists('knx_rest_permission_driver_context')) {
        /**
         * Permission callback that delegates to knx_get_driver_context().
         * Returns true when a valid driver context exists (canonical or acting).
         * This keeps route-level checks minimal and delegates authority to the
         * canonical context resolver.
         *
         * @return callable
         */
        function knx_rest_permission_driver_context() {
            return function () {
                // Require session first
                $session = knx_rest_get_session();
                if (!$session) {
                    return new WP_Error('knx_unauthorized', 'Unauthorized', ['status' => 401]);
                }

                // Delegate to the canonical driver-only resolver
                if (!function_exists('knx_get_driver_context')) {
                    return new WP_Error('knx_forbidden', 'Forbidden', ['status' => 403]);
                }

                $ctx = knx_get_driver_context();
                if (!$ctx) {
                    return new WP_Error('knx_forbidden', 'Forbidden', ['status' => 403]);
                }

                return true;
            };
        }
    }

if (!function_exists('knx_rest_require_session')) {
    /**
     * Require a valid session or return error response.
     *
     * @return object|WP_REST_Response
     */
    function knx_rest_require_session() {
        $session = knx_rest_get_session();
        if (!$session) {
            return knx_rest_error('Unauthorized', 401);
        }
        return $session;
    }
}

if (!function_exists('knx_rest_require_role')) {
    /**
     * Require role to be in allowed list.
     *
     * @param object $session
     * @param array  $allowed_roles
     * @return true|WP_REST_Response
     */
    function knx_rest_require_role($session, array $allowed_roles) {
        $role = isset($session->role) ? (string) $session->role : '';
        if (!$role || !in_array($role, $allowed_roles, true)) {
            return knx_rest_error('Forbidden', 403);
        }
        return true;
    }
}

if (!function_exists('knx_rest_verify_nonce')) {
    /**
     * Verify nonce and return true or error response.
     *
     * @param string $nonce
     * @param string $action
     * @return true|WP_REST_Response
     */
    function knx_rest_verify_nonce($nonce, $action) {
        $nonce = is_string($nonce) ? trim($nonce) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, $action)) {
            return knx_rest_error('Invalid nonce', 403);
        }
        return true;
    }
}

/**
 * ==========================================================
 * Permission callbacks (route-level security)
 * ==========================================================
 */

if (!function_exists('knx_rest_permission_session')) {
    /**
     * Require session at route-level.
     *
     * @return callable
     */
    function knx_rest_permission_session() {
        return function () {
            $session = knx_rest_get_session();
            if (!$session) {
                return new WP_Error('knx_unauthorized', 'Unauthorized', ['status' => 401]);
            }
            return true;
        };
    }
}

if (!function_exists('knx_rest_permission_roles')) {
    /**
     * Require session + role(s) at route-level.
     *
     * @param array $allowed_roles
     * @return callable
     */
    function knx_rest_permission_roles(array $allowed_roles) {
        return function () use ($allowed_roles) {
            $session = knx_rest_get_session();
            if (!$session) {
                return new WP_Error('knx_unauthorized', 'Unauthorized', ['status' => 401]);
            }

            $role = isset($session->role) ? (string) $session->role : '';
            if (!$role || !in_array($role, $allowed_roles, true)) {
                return new WP_Error('knx_forbidden', 'Forbidden', ['status' => 403]);
            }

            return true;
        };
    }
}

if (!function_exists('knx_rest_permission_driver_or_roles')) {
    /**
     * Require session + either admin roles OR a linked active driver profile.
     *
     * Usage:
     * - 'permission_callback' => knx_rest_permission_driver_or_roles()
     *
     * @param array $admin_roles
     * @return callable
     */
    function knx_rest_permission_driver_or_roles(array $admin_roles = ['super_admin', 'manager']) {
        return function () use ($admin_roles) {
            $session = knx_rest_get_session();
            if (!$session) {
                return new WP_Error('knx_unauthorized', 'Unauthorized', ['status' => 401]);
            }

            $role = isset($session->role) ? (string) $session->role : '';
            $user_id = isset($session->user_id) ? (int) $session->user_id : 0;

            // Admin override
            if ($role && in_array($role, $admin_roles, true)) {
                return true;
            }

            // Driver role must have active linked driver profile
            if ($role === 'driver') {
                if ($user_id <= 0) {
                    return new WP_Error('knx_forbidden', 'Forbidden', ['status' => 403]);
                }

                global $wpdb;

                $drivers_table = $wpdb->prefix . 'knx_drivers';
                if (function_exists('knx_table')) {
                    $maybe = knx_table('drivers');
                    if (is_string($maybe) && $maybe !== '') {
                        $drivers_table = $maybe;
                    }
                }

                $driver = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, user_id, status FROM {$drivers_table} WHERE user_id = %d LIMIT 1",
                    $user_id
                ));

                if (!$driver) {
                    return new WP_Error('knx_forbidden', 'Forbidden', ['status' => 403]);
                }

                if (!isset($driver->status) || (string) $driver->status !== 'active') {
                    return new WP_Error('knx_forbidden', 'Forbidden', ['status' => 403]);
                }

                return true;
            }

            return new WP_Error('knx_forbidden', 'Forbidden', ['status' => 403]);
        };
    }
}
