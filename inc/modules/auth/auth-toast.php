<?php
if (!defined('ABSPATH')) exit;

/**
 * KNX Auth Toast — simple one-shot toast system for auth flows.
 * Stores a single toast per session (if session token exists) or per IP (fallback).
 */
class KNX_Auth_Toast {
    // Push a toast: type (success|info|warning|error), code (canonical), optional message override
    public static function push($type, $code, $message = '', $force_ip = false) {
        $payload = [
            'type' => $type,
            'code' => $code,
            'message' => $message ?: self::message_for($code, $type),
        ];

        $key = self::storage_key($force_ip);
        // Short-lived transient (one-shot). TTL 30 seconds.
        set_transient($key, $payload, 30);
    }

    // Consume the current toast (one-shot). Returns payload or false.
    public static function consume() {
        $key = self::storage_key();
        $payload = get_transient($key);
        if ($payload) {
            delete_transient($key);
            return $payload;
        }
        return false;
    }

    // Generate storage key based on session token if present, otherwise IP.
    private static function storage_key($force_ip = false) {
        // Prefer session-based storage
        if (!$force_ip && function_exists('knx_get_session')) {
            $session = knx_get_session();
            if ($session && isset($session->token) && $session->token) {
                return 'knx_auth_toast_s_' . md5($session->token);
            }
        }

        // Fallback to IP
        $ip = '0.0.0.0';
        if (function_exists('knx_get_client_ip')) {
            $ip = knx_get_client_ip();
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return 'knx_auth_toast_ip_' . md5($ip);
    }

    // Default canonical messages (i18n-ready keys can be used later)
    private static function message_for($code, $type) {
        $map = [
            'verify_sent'   => 'Account created successfully. Your account is not active yet. Please check your email to activate your account.',
            'inactive'      => 'Account not active. Your account exists but hasn\'t been activated yet. Please check your email for the activation link.',
            'auth_failed'   => 'Invalid credentials. Please try again.',
            'locked'        => 'Too many attempts. Please try again later.',
            'reset_sent'    => 'If an account exists, a reset link has been sent.',
            'reset_success' => 'Your password has been updated successfully.',
            'verify_success'=> 'Your account has been activated.',
            'verify_invalid'=> 'Invalid activation token.',
            'verify_expired'=> 'Activation token has expired.',
            'verify_send_failed' => 'We could not send a verification email. Please contact support.',
            'logout'        => 'You have been logged out.',
        ];

        return isset($map[$code]) ? $map[$code] : '';
    }
}
