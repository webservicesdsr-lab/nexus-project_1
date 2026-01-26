<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX PWA Helpers (Driver MVP) — SEALED
 * ----------------------------------------------------------
 * - Provides URLs for manifest + service worker
 * - Provides VAPID key generation (stored in wp_options)
 * - FAIL-CLOSED: if OpenSSL missing, returns empty keys (no fatals)
 * - Does NOT send push (sending wired later)
 * ==========================================================
 */

if (!function_exists('knx_pwa_driver_manifest_url')) {
    function knx_pwa_driver_manifest_url() {
        return home_url('/knx-driver-manifest.json');
    }
}

if (!function_exists('knx_pwa_driver_sw_url')) {
    function knx_pwa_driver_sw_url() {
        return home_url('/knx-driver-sw.js');
    }
}

if (!function_exists('knx_b64url_encode')) {
    function knx_b64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('knx_push_set_option_noautoload')) {
    /**
     * Store WP option with autoload disabled (best-effort).
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    function knx_push_set_option_noautoload($key, $value) {
        // add_option supports explicit autoload param reliably
        if (get_option($key, null) === null) {
            // option doesn't exist
            add_option($key, $value, '', 'no');
            return;
        }
        // update_option supports autoload in modern WP; older WP ignores safely
        update_option($key, $value, 'no');
    }
}

if (!function_exists('knx_push_ensure_vapid_keys')) {
    /**
     * Ensure VAPID keys exist in wp_options.
     *
     * Stores:
     * - knx_push_vapid_public   (base64url, uncompressed EC point)
     * - knx_push_vapid_private  (PEM)
     *
     * FAIL-CLOSED: if OpenSSL not available, returns empty.
     *
     * @return array{public:string, private_pem:string}
     */
    function knx_push_ensure_vapid_keys() {
        $pub = get_option('knx_push_vapid_public');
        $priv_pem = get_option('knx_push_vapid_private');

        if (is_string($pub) && $pub !== '' && is_string($priv_pem) && $priv_pem !== '') {
            return ['public' => $pub, 'private_pem' => $priv_pem];
        }

        // OpenSSL hard guard (avoid fatal errors)
        if (
            !extension_loaded('openssl') ||
            !function_exists('openssl_pkey_new') ||
            !function_exists('openssl_pkey_get_details') ||
            !function_exists('openssl_pkey_export') ||
            !defined('OPENSSL_KEYTYPE_EC')
        ) {
            return ['public' => '', 'private_pem' => ''];
        }

        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ];

        $res = openssl_pkey_new($config);
        if (!$res) {
            return ['public' => '', 'private_pem' => ''];
        }

        $priv_out = '';
        $export_ok = openssl_pkey_export($res, $priv_out);
        if (!$export_ok || !is_string($priv_out) || $priv_out === '') {
            return ['public' => '', 'private_pem' => ''];
        }

        $details = openssl_pkey_get_details($res);
        $pub_raw = '';

        /**
         * Attempt to build uncompressed public key: 0x04 || X || Y
         * Some PHP builds expose $details['ec']['x'] and ['y'] as binary strings.
         */
        if (is_array($details) && isset($details['ec']) && is_array($details['ec'])) {
            $ec = $details['ec'];

            if (isset($ec['x'], $ec['y']) && is_string($ec['x']) && is_string($ec['y'])) {
                $pub_raw = "\x04" . $ec['x'] . $ec['y'];
            } elseif (isset($ec['public_key']) && is_string($ec['public_key']) && $ec['public_key'] !== '') {
                // Best-effort fallback (not guaranteed across environments)
                $pub_raw = $ec['public_key'];
            }
        }

        $pub_b64 = $pub_raw ? knx_b64url_encode($pub_raw) : '';

        // Store with autoload disabled
        knx_push_set_option_noautoload('knx_push_vapid_public', $pub_b64);
        knx_push_set_option_noautoload('knx_push_vapid_private', $priv_out);

        return ['public' => $pub_b64, 'private_pem' => $priv_out];
    }
}

if (!function_exists('knx_push_vapid_public_key')) {
    function knx_push_vapid_public_key() {
        $keys = knx_push_ensure_vapid_keys();
        return isset($keys['public']) ? (string) $keys['public'] : '';
    }
}
