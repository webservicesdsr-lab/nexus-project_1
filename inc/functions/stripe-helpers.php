<?php
/**
 * Kingdom Nexus — Stripe Helpers (LEGACY WRAPPERS ONLY)
 *
 * IMPORTANT:
 * - This file must NEVER become the SSOT again.
 * - All real Stripe authority lives in:
 *   inc/core/resources/knx-payments/stripe-authority.php
 *
 * Goals:
 * - Backward compatibility for older code calling legacy function names.
 * - Safe delegation to SSOT functions when available.
 * - If SSOT is missing (misload), attempt to load it (best effort).
 * - Fail-closed: never fatal, never silently fallback live -> test.
 *
 * @package KingdomNexus
 * @since 2.8.5
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('knx_stripe_helpers_maybe_require_authority')) {
    /**
     * Best-effort: load Stripe SSOT authority if it's not already loaded.
     * Never fatals.
     *
     * @return bool True if authority functions are present after this call.
     */
    function knx_stripe_helpers_maybe_require_authority() {
        // Already loaded?
        if (function_exists('knx_stripe_init') && function_exists('knx_stripe_is_available')) {
            return true;
        }

        if (!defined('KNX_PATH') || !KNX_PATH) {
            return false;
        }

        $authority_path = KNX_PATH . 'inc' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'knx-payments' . DIRECTORY_SEPARATOR . 'stripe-authority.php';

        if (file_exists($authority_path)) {
            require_once $authority_path;
        }

        return (function_exists('knx_stripe_init') && function_exists('knx_stripe_is_available'));
    }
}

if (!function_exists('knx_get_stripe_sdk_init_path')) {
    /**
     * Legacy helper: return canonical standalone Stripe SDK init path (if used).
     *
     * @return string
     */
    function knx_get_stripe_sdk_init_path() {
        if (!defined('KNX_PATH') || !KNX_PATH) return '';
        return KNX_PATH . 'vendor' . DIRECTORY_SEPARATOR . 'stripe' . DIRECTORY_SEPARATOR . 'stripe-php' . DIRECTORY_SEPARATOR . 'init.php';
    }
}

if (!function_exists('knx_is_stripe_sdk_available')) {
    /**
     * Legacy wrapper: check if Stripe SDK is available.
     * Delegates to SSOT when possible.
     *
     * @return bool
     */
    function knx_is_stripe_sdk_available() {
        knx_stripe_helpers_maybe_require_authority();

        if (function_exists('knx_stripe_is_available')) {
            return (bool) knx_stripe_is_available();
        }

        // Fallback (non-preferred): direct init.php load
        $init_path = knx_get_stripe_sdk_init_path();
        if ($init_path && file_exists($init_path)) {
            try {
                require_once $init_path;
                return (class_exists('\\Stripe\\Stripe') || class_exists('\\Stripe\\StripeClient') || class_exists('\\Stripe\\PaymentIntent'));
            } catch (\Throwable $e) {
                error_log('[KNX][STRIPE][LEGACY] sdk_available_exception msg=' . substr($e->getMessage(), 0, 200));
                return false;
            }
        }

        return false;
    }
}

if (!function_exists('knx_load_stripe_sdk')) {
    /**
     * Legacy wrapper: historically used to "load" the SDK.
     * SSOT equivalent: knx_stripe_is_available().
     *
     * @return bool
     */
    function knx_load_stripe_sdk() {
        return knx_is_stripe_sdk_available();
    }
}

/**
 * IMPORTANT:
 * Do NOT define SSOT key getters unless SSOT is missing.
 * If SSOT is present, it must own these function names.
 */

if (!function_exists('knx_get_stripe_secret_key')) {
    /**
     * Legacy fallback ONLY if SSOT is missing.
     * Fail-closed: LIVE must NOT fallback to TEST.
     *
     * @return string|null
     */
    function knx_get_stripe_secret_key() {
        knx_stripe_helpers_maybe_require_authority();

        // If SSOT became available after require, delegate.
        if (function_exists('knx_get_stripe_secret_key')) {
            // NOTE: This line will never run because we're inside the same function.
            // Kept intentionally as a reminder: SSOT should own this name via load order.
        }

        $mode = defined('KNX_STRIPE_MODE') ? strtolower((string) KNX_STRIPE_MODE) : 'test';
        $mode = ($mode === 'live') ? 'live' : 'test';

        if ($mode === 'live') {
            if (defined('KNX_STRIPE_LIVE_SECRET_KEY') && !empty(KNX_STRIPE_LIVE_SECRET_KEY)) {
                return (string) KNX_STRIPE_LIVE_SECRET_KEY;
            }
            error_log('[KNX][STRIPE][LEGACY] live_secret_missing');
            return null;
        }

        if (defined('KNX_STRIPE_TEST_SECRET_KEY') && !empty(KNX_STRIPE_TEST_SECRET_KEY)) {
            return (string) KNX_STRIPE_TEST_SECRET_KEY;
        }

        error_log('[KNX][STRIPE][LEGACY] test_secret_missing');
        return null;
    }
}

if (!function_exists('knx_get_stripe_publishable_key')) {
    /**
     * Legacy fallback ONLY if SSOT is missing.
     * Fail-closed: LIVE must NOT fallback to TEST.
     *
     * @return string|null
     */
    function knx_get_stripe_publishable_key() {
        knx_stripe_helpers_maybe_require_authority();

        $mode = defined('KNX_STRIPE_MODE') ? strtolower((string) KNX_STRIPE_MODE) : 'test';
        $mode = ($mode === 'live') ? 'live' : 'test';

        if ($mode === 'live') {
            if (defined('KNX_STRIPE_LIVE_PUBLISHABLE_KEY') && !empty(KNX_STRIPE_LIVE_PUBLISHABLE_KEY)) {
                return (string) KNX_STRIPE_LIVE_PUBLISHABLE_KEY;
            }
            error_log('[KNX][STRIPE][LEGACY] live_publishable_missing');
            return null;
        }

        if (defined('KNX_STRIPE_TEST_PUBLISHABLE_KEY') && !empty(KNX_STRIPE_TEST_PUBLISHABLE_KEY)) {
            return (string) KNX_STRIPE_TEST_PUBLISHABLE_KEY;
        }

        error_log('[KNX][STRIPE][LEGACY] test_publishable_missing');
        return null;
    }
}

if (!function_exists('knx_get_stripe_webhook_secret')) {
    /**
     * Legacy fallback ONLY if SSOT is missing.
     * Fail-closed: LIVE must NOT fallback to TEST.
     *
     * @return string|null
     */
    function knx_get_stripe_webhook_secret() {
        knx_stripe_helpers_maybe_require_authority();

        $mode = defined('KNX_STRIPE_MODE') ? strtolower((string) KNX_STRIPE_MODE) : 'test';
        $mode = ($mode === 'live') ? 'live' : 'test';

        if ($mode === 'live') {
            if (defined('KNX_STRIPE_LIVE_WEBHOOK_SECRET') && !empty(KNX_STRIPE_LIVE_WEBHOOK_SECRET)) {
                return (string) KNX_STRIPE_LIVE_WEBHOOK_SECRET;
            }
            error_log('[KNX][STRIPE][LEGACY] live_webhook_missing');
            return null;
        }

        if (defined('KNX_STRIPE_TEST_WEBHOOK_SECRET') && !empty(KNX_STRIPE_TEST_WEBHOOK_SECRET)) {
            return (string) KNX_STRIPE_TEST_WEBHOOK_SECRET;
        }

        error_log('[KNX][STRIPE][LEGACY] test_webhook_missing');
        return null;
    }
}

if (!function_exists('knx_init_stripe')) {
    /**
     * Legacy wrapper: initialize Stripe.
     * SSOT equivalent: knx_stripe_init()
     *
     * @return bool
     */
    function knx_init_stripe() {
        knx_stripe_helpers_maybe_require_authority();

        // Delegate to SSOT if present
        if (function_exists('knx_stripe_init')) {
            return (bool) knx_stripe_init();
        }

        // Fallback (non-preferred): direct init.php + setApiKey
        if (!knx_load_stripe_sdk()) {
            error_log('[KNX][STRIPE][LEGACY] init_failed sdk_unavailable');
            return false;
        }

        $secret_key = knx_get_stripe_secret_key();
        if (empty($secret_key)) {
            error_log('[KNX][STRIPE][LEGACY] init_failed secret_missing');
            return false;
        }

        try {
            \Stripe\Stripe::setApiKey((string) $secret_key);
            error_log('[KNX][STRIPE][LEGACY] initialized_fallback');
            return true;
        } catch (\Throwable $e) {
            error_log('[KNX][STRIPE][LEGACY] init_exception msg=' . substr($e->getMessage(), 0, 200));
            return false;
        }
    }
}
