<?php
/**
 * Kingdom Nexus - Stripe Logger (wp-content)
 * Writes to: WP_CONTENT_DIR . '/knx-logs/stripe.log'
 *
 * SECURITY:
 * - Never log secret keys, webhook secret, client_secret, card data, or PII.
 *
 * @package KingdomNexus
 * @since 2.8.5
 */

if (!defined('ABSPATH')) exit;

if (!defined('KNX_STRIPE_LOG_INIT'))     define('KNX_STRIPE_LOG_INIT', 'INIT');
if (!defined('KNX_STRIPE_LOG_INTENT'))   define('KNX_STRIPE_LOG_INTENT', 'INTENT');
if (!defined('KNX_STRIPE_LOG_CONFIRM'))  define('KNX_STRIPE_LOG_CONFIRM', 'CONFIRM');
if (!defined('KNX_STRIPE_LOG_WEBHOOK'))  define('KNX_STRIPE_LOG_WEBHOOK', 'WEBHOOK');
if (!defined('KNX_STRIPE_LOG_ERROR'))    define('KNX_STRIPE_LOG_ERROR', 'ERROR');
if (!defined('KNX_STRIPE_LOG_SECURITY')) define('KNX_STRIPE_LOG_SECURITY', 'SECURITY');

/**
 * Additional optional buckets (non-breaking):
 */
if (!defined('KNX_STRIPE_LOG_INFO')) define('KNX_STRIPE_LOG_INFO', 'INFO');
if (!defined('KNX_STRIPE_LOG_WARN')) define('KNX_STRIPE_LOG_WARN', 'WARN');
if (!defined('KNX_STRIPE_LOG_AUTH')) define('KNX_STRIPE_LOG_AUTH', 'AUTH');

if (!function_exists('knx_stripe_log_dir_path')) {
    function knx_stripe_log_dir_path() {
        $wp_content = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : rtrim(ABSPATH, '/') . '/wp-content';
        return rtrim($wp_content, '/') . '/knx-logs';
    }
}

if (!function_exists('knx_stripe_log_file_path')) {
    function knx_stripe_log_file_path() {
        return knx_stripe_log_dir_path() . '/stripe.log';
    }
}

if (!function_exists('knx_stripe_log_ensure_storage')) {
    function knx_stripe_log_ensure_storage() {
        $dir = knx_stripe_log_dir_path();
        $log = knx_stripe_log_file_path();

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Best-effort deny web access
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $rules = "# KNX Logs - Deny all web access\n<Files \"*\">\n  Require all denied\n</Files>\n\nOptions -Indexes\n";
            @file_put_contents($htaccess, $rules, LOCK_EX);
        }

        $indexphp = $dir . '/index.php';
        if (!file_exists($indexphp)) {
            @file_put_contents($indexphp, "<?php\nhttp_response_code(403);\nexit;\n", LOCK_EX);
        }

        if (!file_exists($log)) {
            $header = "# Kingdom Nexus - Stripe Log\n"
                . "# Format: [timestamp_utc] [KNX][STRIPE][LEVEL] message | key=value\n"
                . "# Security: NO secrets, NO webhook secret, NO client_secret, NO card data, NO PII\n";
            @file_put_contents($log, $header, LOCK_EX);
        }

        return (file_exists($log) && is_writable($log));
    }
}

if (!function_exists('knx_stripe_redact_value_if_sensitive')) {
    function knx_stripe_redact_value_if_sensitive($value) {
        $v = (string)$value;

        $patterns = [
            '/\bsk_(test|live)_[A-Za-z0-9]+\b/',
            '/\bpk_(test|live)_[A-Za-z0-9]+\b/',
            '/\bwhsec_[A-Za-z0-9]+\b/',
            '/\bpi_secret_[A-Za-z0-9]+\b/i',
            '/\bclient_secret\b/i',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $v)) return '[REDACTED]';
        }

        return $v;
    }
}

if (!function_exists('knx_stripe_log')) {
    /**
     * Write a line to stripe.log (fail-closed).
     *
     * @param string $level
     * @param string $message
     * @param array  $context
     * @return bool
     */
    function knx_stripe_log($level, $message, $context = []) {
        $valid = [
            KNX_STRIPE_LOG_INIT,
            KNX_STRIPE_LOG_INTENT,
            KNX_STRIPE_LOG_CONFIRM,
            KNX_STRIPE_LOG_WEBHOOK,
            KNX_STRIPE_LOG_ERROR,
            KNX_STRIPE_LOG_SECURITY,
            KNX_STRIPE_LOG_INFO,
            KNX_STRIPE_LOG_WARN,
            KNX_STRIPE_LOG_AUTH,
        ];

        if (!in_array($level, $valid, true)) return false;
        if (!knx_stripe_log_ensure_storage()) return false;

        // Block sensitive keys + PII-ish keys
        $forbidden_keys = [
            'secret', 'secret_key', 'stripe_secret',
            'webhook', 'webhook_secret',
            'client_secret',
            'card', 'card_number', 'cvc', 'cvv', 'exp',
            'password', 'token',
            'email', 'phone', 'address', 'name',
        ];

        $message = str_replace(["\r", "\n", "\t"], ' ', (string)$message);
        $message = substr($message, 0, 240);

        $parts = [];
        if (is_array($context)) {
            foreach ($context as $k => $v) {
                $kl = strtolower((string)$k);

                $blocked = false;
                foreach ($forbidden_keys as $bad) {
                    if (strpos($kl, $bad) !== false) { $blocked = true; break; }
                }
                if ($blocked) continue;

                $safe_key = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$k);
                if ($safe_key === '') continue;

                if (is_array($v) || is_object($v)) {
                    $v = function_exists('wp_json_encode') ? wp_json_encode($v) : json_encode($v);
                }

                $safe_val = knx_stripe_redact_value_if_sensitive($v);
                $safe_val = str_replace(["\r", "\n", "\t"], ' ', (string)$safe_val);
                $safe_val = substr($safe_val, 0, 600);

                $parts[] = "{$safe_key}={$safe_val}";
            }
        }

        $timestamp = gmdate('Y-m-d H:i:s');
        $line = "[{$timestamp}] [KNX][STRIPE][{$level}] {$message}";
        if (!empty($parts)) $line .= " | " . implode(' ', $parts);
        $line .= "\n";

        $log = knx_stripe_log_file_path();
        $written = @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);

        return ($written !== false);
    }
}

if (!function_exists('knx_stripe_log_init')) {
    function knx_stripe_log_init() {
        static $initialized = false;
        if ($initialized) return;
        $initialized = true;

        $mode = defined('KNX_STRIPE_MODE') ? KNX_STRIPE_MODE : 'test';
        knx_stripe_log(KNX_STRIPE_LOG_INIT, 'Stripe logger initialized', [
            'mode' => $mode,
            'php'  => PHP_VERSION,
        ]);
    }
}
