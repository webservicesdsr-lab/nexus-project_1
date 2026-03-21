<?php
/**
 * ==========================================================
 * KNX System Emails — Hooks (intentionally empty)
 * ==========================================================
 * WHY NO HOOKS ARE REGISTERED HERE:
 *
 * KNX uses a fully custom authentication system backed by its
 * own database tables (knx_users, knx_password_resets,
 * knx_email_verifications). WordPress core auth functions
 * such as wp_new_user_notification() and retrieve_password()
 * are NEVER called anywhere in the KNX codebase.
 *
 * Therefore, WordPress filters like:
 * - wp_new_user_notification_email
 * - retrieve_password_notification_email
 * would never fire and registering them here would be dead code.
 *
 * THE REAL EMAIL FLOWS ARE:
 *
 * Account activation:
 *   auth-handler.php (REGISTER) → knx_create_email_verification_token()
 *   → knx_get_account_activation_email() [auth-emails.php]
 *   → knx_queue_mail() → shutdown → wp_mail() → FluentSMTP
 *
 * Password reset:
 *   auth-handler.php (FORGOT) → knx_create_password_reset()
 *   → knx_get_password_reset_email() [auth-emails.php]
 *   → knx_queue_mail() → shutdown → wp_mail() → FluentSMTP
 *
 * To customise these emails, edit:
 *   inc/modules/auth/auth-emails.php
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

// No hooks to register — see header comment above.

/**
 * Override new user notification email in KNX context.
 * 
 * This filter is called by wp_new_user_notification() before sending
 * the user activation email.
 */
add_filter('wp_new_user_notification_email', 'knx_se_override_user_activation_email', 10, 3);

/**
 * Override password reset notification email in KNX context.
 *
 * This filter is called by retrieve_password() before sending
 * the password reset email.
 */
add_filter('retrieve_password_notification_email', 'knx_se_override_password_reset_email', 10, 4);

/**
 * Filter callback: Override user activation email.
 *
 * @param array   $wp_new_user_notification_email Original email array
 * @param WP_User $user                           User object
 * @param string  $blogname                       Site name
 * @return array  Modified email array or original if not KNX context
 */
function knx_se_override_user_activation_email($wp_new_user_notification_email, $user, $blogname) {
    // Only override in KNX context
    if (!knx_se_is_knx_context()) {
        return $wp_new_user_notification_email;
    }

    // Get activation key (WordPress generates this)
    $activation_key = get_user_meta($user->ID, 'activation_key', true);
    if (empty($activation_key)) {
        // Fallback: generate activation key if missing
        $activation_key = wp_generate_password(20, false);
        update_user_meta($user->ID, 'activation_key', $activation_key);
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
        'site_name'      => $blogname,
    ];

    $email = knx_se_render_email_template('user_activation', $template_vars);

    if (empty($email) || empty($email['subject']) || empty($email['html'])) {
        // Fallback to WordPress default on template failure
        return $wp_new_user_notification_email;
    }

    // Return modified email array
    return [
        'to'      => $user->user_email,
        'subject' => $email['subject'],
        'message' => $email['html'],
        'headers' => ['Content-Type: text/html; charset=UTF-8'],
    ];
}

/**
 * Filter callback: Override password reset email.
 *
 * @param array  $defaults    Original email array
 * @param string $key         Password reset key
 * @param string $user_login  User login
 * @param object $user_data   User data object
 * @return array Modified email array or original if not KNX context
 */
function knx_se_override_password_reset_email($defaults, $key, $user_login, $user_data) {
    // Only override in KNX context
    if (!knx_se_is_knx_context()) {
        return $defaults;
    }

    $normalized_user = knx_se_normalize_user_data($user_data);
    if (!$normalized_user) {
        return $defaults;
    }

    $reset_url = add_query_arg([
        'action' => 'rp',
        'key'    => $key,
        'login'  => rawurlencode($user_login),
    ], wp_login_url());

    $template_vars = [
        'user_name'   => $normalized_user->display_name ?? $user_login,
        'user_email'  => $normalized_user->user_email,
        'reset_url'   => $reset_url,
        'site_name'   => get_bloginfo('name'),
        'expires_in'  => '24 hours',
    ];

    $email = knx_se_render_email_template('password_reset', $template_vars);

    if (empty($email) || empty($email['subject']) || empty($email['html'])) {
        // Fallback to WordPress default on template failure
        return $defaults;
    }

    // Return modified email array
    return [
        'to'      => $normalized_user->user_email,
        'subject' => $email['subject'],
        'message' => $email['html'],
        'headers' => ['Content-Type: text/html; charset=UTF-8'],
    ];
}

/**
 * Optional: Handle activation URL processing.
 * 
 * This processes the ?action=knx_activate URLs generated by our templates.
 * Add this to your main KNX routing if needed.
 */
add_action('init', 'knx_se_handle_activation_url');

function knx_se_handle_activation_url() {
    if (!isset($_GET['action']) || $_GET['action'] !== 'knx_activate') {
        return;
    }

    $activation_key = sanitize_text_field($_GET['key'] ?? '');
    $user_login = sanitize_text_field($_GET['login'] ?? '');

    if (empty($activation_key) || empty($user_login)) {
        wp_die('Invalid activation link.');
    }

    $user = get_user_by('login', $user_login);
    if (!$user) {
        wp_die('Invalid user.');
    }

    $stored_key = get_user_meta($user->ID, 'activation_key', true);
    if ($stored_key !== $activation_key) {
        wp_die('Invalid or expired activation key.');
    }

    // Activate user (remove activation key, set as active)
    delete_user_meta($user->ID, 'activation_key');
    
    // If using a custom user status system, update it here
    // update_user_meta($user->ID, 'account_status', 'active');

    // Redirect to login or dashboard
    wp_redirect(wp_login_url() . '?activated=1');
    exit;
}