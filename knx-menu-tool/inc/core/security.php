<?php
if (!defined('ABSPATH')) exit;

final class KNX_Menu_Tool_Security {

    /**
     * KNX roles are NOT WordPress roles.
     *
     * @return string[]
     */
    public static function allowed_roles() {
        return ['super_admin', 'manager', 'menu_uploader'];
    }

    /**
     * @param mixed $session
     * @return string|null
     */
    public static function get_role_from_session($session) {
        if (is_object($session)) {
            return $session->role ?? $session->user_role ?? null;
        }

        if (is_array($session)) {
            return $session['role'] ?? $session['user_role'] ?? null;
        }

        return null;
    }

    /**
     * @param mixed $session
     * @return int
     */
    public static function get_user_id_from_session($session) {
        if (is_object($session)) {
            $user_id = (int) ($session->user_id ?? 0);
            if ($user_id > 0) return $user_id;
        }

        if (is_array($session)) {
            $user_id = (int) ($session['user_id'] ?? 0);
            if ($user_id > 0) return $user_id;
        }

        return 0;
    }

    /**
     * @return string
     */
    public static function get_tool_url() {
        return home_url('/knx-menu-tool');
    }

    /**
     * Redirects to KNX login, not WordPress admin login.
     *
     * @return string
     */
    public static function get_knx_login_redirect_url() {
        return add_query_arg(
            ['redirect' => rawurlencode(self::get_tool_url())],
            KNX_MENU_TOOL_LOGIN_URL
        );
    }

    /**
     * Page access matrix:
     * - knx missing => 403 + standalone page
     * - session missing => redirect KNX login
     * - invalid role => redirect KNX login
     * - valid => allow
     *
     * @return array
     */
    public static function check_page_access() {
        if (!function_exists('knx_get_session')) {
            return [
                'ok' => false,
                'mode' => 'nexus_required',
            ];
        }

        $session = knx_get_session();
        if (!$session) {
            return [
                'ok' => false,
                'mode' => 'redirect_login',
                'login_url' => self::get_knx_login_redirect_url(),
            ];
        }

        $role = self::get_role_from_session($session);
        $user_id = self::get_user_id_from_session($session);

        if (!in_array($role, self::allowed_roles(), true) || $user_id <= 0) {
            return [
                'ok' => false,
                'mode' => 'redirect_login',
                'login_url' => self::get_knx_login_redirect_url(),
            ];
        }

        return [
            'ok' => true,
            'mode' => 'allow',
            'role' => $role,
            'user_id' => $user_id,
        ];
    }

    /**
     * AJAX access matrix:
     * - invalid nonce => 403
     * - knx missing => 403
     * - no session => 401
     * - invalid role => 403
     * - valid => allow
     *
     * @param string $nonce_action
     * @return array
     */
    public static function check_ajax_access($nonce_action) {
        if (!check_ajax_referer($nonce_action, 'nonce', false)) {
            return [
                'ok' => false,
                'status' => 403,
                'message' => 'Invalid nonce.',
            ];
        }

        if (!function_exists('knx_get_session')) {
            return [
                'ok' => false,
                'status' => 403,
                'message' => 'Kingdom Nexus required.',
            ];
        }

        $session = knx_get_session();
        if (!$session) {
            return [
                'ok' => false,
                'status' => 401,
                'message' => 'Authentication required.',
            ];
        }

        $role = self::get_role_from_session($session);
        $user_id = self::get_user_id_from_session($session);

        if (!in_array($role, self::allowed_roles(), true) || $user_id <= 0) {
            return [
                'ok' => false,
                'status' => 403,
                'message' => 'Access denied.',
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'role' => $role,
            'user_id' => $user_id,
        ];
    }
}