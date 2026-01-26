<?php
/**
 * ████████████████████████████████████████████████████████████████
 * █ STRIPE AUTHORITY — SSOT Bootstrap + Keys + Availability + Init
 * ████████████████████████████████████████████████████████████████
 *
 * Responsibilities (SSOT):
 * - knx_get_stripe_mode()               → 'test' | 'live'
 * - knx_stripe_sdk_boot()               → loads Stripe SDK (composer or standalone init.php)
 * - knx_stripe_is_available()           → SDK boot + class_exists
 * - knx_get_stripe_secret_key()         → mode-aware secret key (FAIL-CLOSED)
 * - knx_get_stripe_publishable_key()    → mode-aware publishable key (FAIL-CLOSED)
 * - knx_get_stripe_webhook_secret()     → mode-aware webhook secret (FAIL-CLOSED)
 * - knx_stripe_init()                   → SDK boot + setApiKey (the ONLY pre-call required)
 * - knx_stripe_authority_log()          → standardized safe logging
 *
 * Security:
 * - Never logs secret keys, webhook secrets, client_secret, or card data.
 * - LIVE must NEVER fallback to TEST keys.
 *
 * @package KingdomNexus
 * @since 2.8.5
 */

if (!defined('ABSPATH')) exit;

/* ==========================================================
 * MODE (SSOT)
 * ========================================================== */
if (!function_exists('knx_get_stripe_mode')) {
    /**
     * Get Stripe mode.
     *
     * SSOT: Reads KNX_STRIPE_MODE constant.
     * Default: 'test' (safe).
     *
     * @return string 'test'|'live'
     */
    function knx_get_stripe_mode() {
        $mode = defined('KNX_STRIPE_MODE') ? strtolower((string) KNX_STRIPE_MODE) : 'test';
        return ($mode === 'live') ? 'live' : 'test';
    }
}

/* ==========================================================
 * LOGGING (SSOT)
 * ========================================================== */
if (!function_exists('knx_stripe_authority_log')) {
    /**
     * Standardized Stripe authority logging.
     *
     * Preference:
     * 1) knx_stripe_log(...) if available (writes to wp-content/knx-logs/stripe.log)
     * 2) error_log(JSON) fallback
     *
     * @param string $level   'error'|'warn'|'info'
     * @param string $code    machine code
     * @param string $message human message
     * @param array  $meta    extra context (sanitized)
     * @return void
     */
    function knx_stripe_authority_log($level, $code, $message = '', $meta = []) {
        $safe_meta = [];
        foreach ((array) $meta as $k => $v) {
            $kl = strtolower((string) $k);
            if (strpos($kl, 'secret') !== false) continue;
            if (strpos($kl, 'key') !== false) continue;
            if (strpos($kl, 'client_secret') !== false) continue;
            $safe_meta[$k] = $v;
        }

        // If dedicated file logger exists, use it.
        if (function_exists('knx_stripe_log')) {
            $lvl = strtoupper((string)$level);
            $lvl = ($lvl === 'WARN') ? 'ERROR' : $lvl; // keep simple buckets
            $msg = trim((string)$message);
            if ($msg === '') $msg = (string)$code;

            knx_stripe_log(
                defined('KNX_STRIPE_LOG_ERROR') ? KNX_STRIPE_LOG_ERROR : 'ERROR',
                "[AUTH] {$msg}",
                array_merge([
                    'code' => (string)$code,
                    'level' => (string)$level,
                    'mode' => function_exists('knx_get_stripe_mode') ? knx_get_stripe_mode() : 'unknown',
                ], (array)$safe_meta)
            );
            return;
        }

        // Fallback to JSON error_log
        $payload = [
            'tag' => '[KNX][STRIPE]',
            'level' => (string) $level,
            'code' => (string) $code,
            'message' => (string) $message,
            'meta' => $safe_meta,
            'mode' => function_exists('knx_get_stripe_mode') ? knx_get_stripe_mode() : 'unknown',
            'timestamp' => function_exists('current_time') ? current_time('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s'),
        ];
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        error_log((string) $json);
    }
}

/* ==========================================================
 * SDK BOOT (SSOT)
 * ========================================================== */
if (!function_exists('knx_stripe_sdk_boot')) {
    /**
     * Boot Stripe SDK by loading composer autoload or standalone init.php.
     * Executed once per request (static cache).
     *
     * Attempts (in order):
     * 1) KNX_PATH/vendor/autoload.php (Composer, future-proof)
     * 2) KNX_PATH/vendor/stripe/stripe-php/init.php (current canonical)
     * 3) dirname(__DIR__,4)/vendor/autoload.php (diagnostic fallback)
     *
     * @return bool
     */
    function knx_stripe_sdk_boot() {
        static $booted = null;
        if ($booted !== null) return (bool)$booted;

        $booted = false;

        $has_stripe_classes = function () {
            return (
                class_exists('\\Stripe\\Stripe') ||
                class_exists('\\Stripe\\PaymentIntent') ||
                class_exists('\\Stripe\\StripeClient') ||
                class_exists('\\Stripe\\Webhook')
            );
        };

        // Attempt 1: Composer autoload
        if (defined('KNX_PATH') && KNX_PATH) {
            $autoload = KNX_PATH . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
            if (file_exists($autoload)) {
                try {
                    require_once $autoload;
                    if ($has_stripe_classes()) {
                        $booted = true;
                        knx_stripe_authority_log('info', 'stripe_sdk_booted', 'Composer autoload used', [
                            'path' => 'vendor/autoload.php',
                        ]);
                        return true;
                    }
                } catch (\Throwable $e) {
                    knx_stripe_authority_log('error', 'stripe_autoload_exception', 'Composer autoload failed', [
                        'msg' => substr($e->getMessage(), 0, 200),
                        'file' => basename($e->getFile()),
                    ]);
                }
            }
        }

        // Attempt 2: Standalone init.php (canonical for your current deployment)
        if (defined('KNX_PATH') && KNX_PATH) {
            $init_php = KNX_PATH . 'vendor' . DIRECTORY_SEPARATOR . 'stripe' . DIRECTORY_SEPARATOR . 'stripe-php' . DIRECTORY_SEPARATOR . 'init.php';
            if (file_exists($init_php)) {
                try {
                    require_once $init_php;
                    if ($has_stripe_classes()) {
                        $booted = true;
                        knx_stripe_authority_log('info', 'stripe_sdk_booted', 'Standalone init.php used', [
                            'path' => 'vendor/stripe/stripe-php/init.php',
                        ]);
                        return true;
                    }
                } catch (\Throwable $e) {
                    knx_stripe_authority_log('error', 'stripe_init_exception', 'Standalone init.php failed', [
                        'msg' => substr($e->getMessage(), 0, 200),
                        'file' => basename($e->getFile()),
                    ]);
                }
            }
        }

        // Attempt 3: Diagnostic fallback autoload
        $fallback = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (file_exists($fallback)) {
            try {
                require_once $fallback;
                if ($has_stripe_classes()) {
                    $booted = true;
                    knx_stripe_authority_log('warn', 'stripe_fallback_used', 'Fallback autoload path used (review deployment)', [
                        'path' => $fallback,
                    ]);
                    return true;
                }
            } catch (\Throwable $e) {
                knx_stripe_authority_log('error', 'stripe_fallback_exception', 'Fallback autoload failed', [
                    'msg' => substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        // Fail-closed
        knx_stripe_authority_log('error', 'stripe_sdk_not_found', 'Stripe SDK not found in any path', [
            'checked' => [
                'composer' => 'KNX_PATH/vendor/autoload.php',
                'standalone' => 'KNX_PATH/vendor/stripe/stripe-php/init.php',
                'fallback' => 'dirname(__DIR__,4)/vendor/autoload.php',
            ],
        ]);

        $booted = false;
        return false;
    }
}

/* ==========================================================
 * AVAILABILITY (SSOT)
 * ========================================================== */
if (!function_exists('knx_stripe_is_available')) {
    /**
     * Check if Stripe SDK is available (booted + classes exist).
     *
     * @return bool
     */
    function knx_stripe_is_available() {
        if (!knx_stripe_sdk_boot()) {
            return false;
        }

        if (
            class_exists('\\Stripe\\Stripe') ||
            class_exists('\\Stripe\\StripeClient') ||
            class_exists('\\Stripe\\PaymentIntent') ||
            class_exists('\\Stripe\\Webhook')
        ) {
            return true;
        }

        knx_stripe_authority_log('error', 'stripe_classes_missing', 'Autoload loaded but Stripe classes not found');
        return false;
    }
}

/* ==========================================================
 * KEYS (SSOT) — FAIL-CLOSED (NO live→test fallback)
 * ========================================================== */
if (!function_exists('knx_get_stripe_secret_key')) {
    /**
     * Get Stripe secret key (mode-aware).
     *
     * LIVE must NOT fallback to TEST.
     *
     * @return string|null
     */
    function knx_get_stripe_secret_key() {
        $mode = function_exists('knx_get_stripe_mode') ? knx_get_stripe_mode() : 'test';

        if ($mode === 'live') {
            if (defined('KNX_STRIPE_LIVE_SECRET_KEY') && !empty(KNX_STRIPE_LIVE_SECRET_KEY)) {
                return (string) KNX_STRIPE_LIVE_SECRET_KEY;
            }
            knx_stripe_authority_log('error', 'stripe_live_secret_missing', 'LIVE secret key not configured');
            return null;
        }

        // test mode
        if (defined('KNX_STRIPE_TEST_SECRET_KEY') && !empty(KNX_STRIPE_TEST_SECRET_KEY)) {
            return (string) KNX_STRIPE_TEST_SECRET_KEY;
        }

        knx_stripe_authority_log('error', 'stripe_test_secret_missing', 'TEST secret key not configured');
        return null;
    }
}

if (!function_exists('knx_get_stripe_publishable_key')) {
    /**
     * Get Stripe publishable key (mode-aware).
     *
     * LIVE must NOT fallback to TEST.
     *
     * @return string|null
     */
    function knx_get_stripe_publishable_key() {
        $mode = function_exists('knx_get_stripe_mode') ? knx_get_stripe_mode() : 'test';

        if ($mode === 'live') {
            if (defined('KNX_STRIPE_LIVE_PUBLISHABLE_KEY') && !empty(KNX_STRIPE_LIVE_PUBLISHABLE_KEY)) {
                return (string) KNX_STRIPE_LIVE_PUBLISHABLE_KEY;
            }
            knx_stripe_authority_log('error', 'stripe_live_publishable_missing', 'LIVE publishable key not configured');
            return null;
        }

        // test mode
        if (defined('KNX_STRIPE_TEST_PUBLISHABLE_KEY') && !empty(KNX_STRIPE_TEST_PUBLISHABLE_KEY)) {
            return (string) KNX_STRIPE_TEST_PUBLISHABLE_KEY;
        }

        knx_stripe_authority_log('error', 'stripe_test_publishable_missing', 'TEST publishable key not configured');
        return null;
    }
}

if (!function_exists('knx_get_stripe_webhook_secret')) {
    /**
     * Get Stripe webhook secret (mode-aware).
     *
     * LIVE must NOT fallback to TEST.
     *
     * @return string|null
     */
    function knx_get_stripe_webhook_secret() {
        $mode = function_exists('knx_get_stripe_mode') ? knx_get_stripe_mode() : 'test';

        if ($mode === 'live') {
            if (defined('KNX_STRIPE_LIVE_WEBHOOK_SECRET') && !empty(KNX_STRIPE_LIVE_WEBHOOK_SECRET)) {
                return (string) KNX_STRIPE_LIVE_WEBHOOK_SECRET;
            }
            knx_stripe_authority_log('error', 'stripe_live_webhook_missing', 'LIVE webhook secret not configured');
            return null;
        }

        // test mode
        if (defined('KNX_STRIPE_TEST_WEBHOOK_SECRET') && !empty(KNX_STRIPE_TEST_WEBHOOK_SECRET)) {
            return (string) KNX_STRIPE_TEST_WEBHOOK_SECRET;
        }

        knx_stripe_authority_log('error', 'stripe_test_webhook_missing', 'TEST webhook secret not configured');
        return null;
    }
}

/* ==========================================================
 * INIT (SSOT) — THE ONLY PRE-CALL REQUIRED
 * ========================================================== */
if (!function_exists('knx_stripe_init')) {
    /**
     * Initialize Stripe SDK with API key (SSOT).
     *
     * This is the ONLY function that should be called before Stripe API calls.
     * Steps:
     * 1) Boot SDK
     * 2) Verify classes exist
     * 3) Resolve secret key (mode-aware, fail-closed)
     * 4) Set API key
     *
     * @return bool
     */
    function knx_stripe_init() {
        static $initialized = null;
        if ($initialized !== null) return (bool)$initialized;

        // Step 1: Boot + availability
        if (!function_exists('knx_stripe_is_available') || !knx_stripe_is_available()) {
            knx_stripe_authority_log('error', 'stripe_init_failed', 'SDK not available');
            $initialized = false;
            return false;
        }

        // Step 2: Key resolve (fail-closed)
        $secret_key = function_exists('knx_get_stripe_secret_key') ? knx_get_stripe_secret_key() : null;
        if (empty($secret_key)) {
            knx_stripe_authority_log('error', 'stripe_init_failed', 'Secret key missing');
            $initialized = false;
            return false;
        }

        // Step 3: Apply key
        try {
            \Stripe\Stripe::setApiKey((string)$secret_key);
            $initialized = true;

            knx_stripe_authority_log('info', 'stripe_initialized', 'Stripe SDK fully initialized', [
                'mode' => function_exists('knx_get_stripe_mode') ? knx_get_stripe_mode() : 'unknown',
            ]);

            return true;
        } catch (\Throwable $e) {
            knx_stripe_authority_log('error', 'stripe_init_exception', 'Failed to set API key', [
                'msg' => substr($e->getMessage(), 0, 200),
            ]);
            $initialized = false;
            return false;
        }
    }
}
