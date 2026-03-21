<?php
/**
 * ==========================================================
 * KNX System Emails — Engine (Orchestration)
 * ==========================================================
 * Provides controlled replacement of WordPress system emails.
 *
 * Functions:
 * - knx_se_send_activation_email($user_id, $activation_key)
 * - knx_se_send_password_reset_email($user_data, $reset_key)
 * - knx_se_is_knx_context() — determines if email should be overridden
 *
 * Architecture:
 * - Only overrides emails for KNX user flows
 * - Does NOT interfere with wp-admin generated emails
 * - Uses same template rendering as driver notifications
 * - Maintains compatibility with FluentSMTP transport
 * - Fail-closed design
 *
 * Context Detection:
 * - KNX context: REST API calls, frontend forms, KNX-specific flows
 * - WordPress context: wp-admin, core WP flows
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

/**
 * Send user activation email (KNX-controlled).
 *
 * @param int    $user_id        User ID
 * @param string $activation_key Activation key
 * @return bool  true on success, false on failure
 */
function knx_se_send_activation_email($user_id, $activation_key) {
    $user_id = (int) $user_id;
    if ($user_id <= 0 || empty($activation_key)) {
        return false;
    }

    $user = get_user_by('id', $user_id);
    if (!$user) {
        return false;
    }

    $activation_url = add_query_arg([
        'action' => 'knx_activate',
        'key'    => $activation_key,
        'login'  => rawurlencode($user->user_login),
    ], site_url('/'));

    $template_vars = [
        'user_name'      => $user->display_name ?: $user->user_login,
        'user_email'     => $user->user_email,
        'activation_url' => $activation_url,
        'site_name'      => get_bloginfo('name'),
    ];

    $email = knx_se_render_email_template('user_activation', $template_vars);

    if (empty($email) || empty($email['subject']) || empty($email['html'])) {
        return false;
    }

    return knx_se_send_email($user->user_email, $email['subject'], $email['html']);
}

/**
 * Send password reset email (KNX-controlled).
 *
 * @param WP_User|object $user_data User object
 * @param string         $reset_key Password reset key
 * @return bool  true on success, false on failure
 */
function knx_se_send_password_reset_email($user_data, $reset_key) {
    if (!$user_data || empty($reset_key)) {
        return false;
    }

    // Handle both WP_User objects and stdClass objects
    $user_login = isset($user_data->user_login) ? $user_data->user_login : '';
    $user_email = isset($user_data->user_email) ? $user_data->user_email : '';
    $display_name = isset($user_data->display_name) ? $user_data->display_name : '';

    if (empty($user_login) || empty($user_email)) {
        return false;
    }

    $reset_url = add_query_arg([
        'action' => 'rp',
        'key'    => $reset_key,
        'login'  => rawurlencode($user_login),
    ], wp_login_url());

    $template_vars = [
        'user_name'   => $display_name ?: $user_login,
        'user_email'  => $user_email,
        'reset_url'   => $reset_url,
        'site_name'   => get_bloginfo('name'),
        'expires_in'  => '24 hours',
    ];

    $email = knx_se_render_email_template('password_reset', $template_vars);

    if (empty($email) || empty($email['subject']) || empty($email['html'])) {
        return false;
    }

    return knx_se_send_email($user_email, $email['subject'], $email['html']);
}

/**
 * Determine if we're in a KNX context where emails should be overridden.
 *
 * KNX context includes:
 * - REST API requests
 * - Frontend requests (not wp-admin)
 * - Requests with KNX-specific markers
 *
 * WordPress context includes:
 * - wp-admin requests
 * - Core WordPress flows without KNX involvement
 *
 * @return bool true if KNX should handle the email
 */
function knx_se_is_knx_context() {
    // Always override in REST API context
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }

    // Always override if WP_CLI (usually imports/migrations)
    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }

    // Never override in wp-admin
    if (is_admin()) {
        return false;
    }

    // Check for KNX-specific markers
    if (isset($_REQUEST['knx_flow']) || isset($_REQUEST['knx_context'])) {
        return true;
    }

    // Check if request came through KNX REST endpoints
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($request_uri, '/wp-json/knx/') !== false) {
        return true;
    }

    // Default to KNX context for frontend requests
    return !is_admin();
}

/**
 * Get contextual user data for password reset.
 * Handles both WP_User objects and database result objects.
 *
 * @param mixed $user_data User data from various WordPress contexts
 * @return object|false Normalized user object or false on failure
 */
function knx_se_normalize_user_data($user_data) {
    if (!$user_data) {
        return false;
    }

    // Already a properly formatted object
    if (is_object($user_data) && isset($user_data->user_login, $user_data->user_email)) {
        return $user_data;
    }

    // WP_User object
    if ($user_data instanceof WP_User) {
        return (object) [
            'user_login'   => $user_data->user_login,
            'user_email'   => $user_data->user_email,
            'display_name' => $user_data->display_name,
        ];
    }

    // Array (convert to object)
    if (is_array($user_data)) {
        return (object) $user_data;
    }

    return false;
}