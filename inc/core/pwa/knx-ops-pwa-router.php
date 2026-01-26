<?php
/**
 * ==========================================================
 * Kingdom Nexus — OPS PWA Router (Service Worker Delivery)
 * ==========================================================
 *
 * WHY:
 * - The SW must be served from a URL that can control the desired scope.
 * - Plugin file URLs (wp-content/plugins/...) are usually not suitable for broad scope.
 *
 * WHAT:
 * - Serve /knx-ops-sw.js from this plugin file: inc/core/pwa/knx-ops-sw.js
 * - Send correct headers (JS + no-cache + Service-Worker-Allowed: /)
 * - Register SW via inline script (NO wp_footer, NO wp_enqueue), gated by OPS roles.
 *
 * FAIL-CLOSED:
 * - If file missing -> returns 404 safely.
 * - If role not OPS -> no registration script output.
 *
 * @package KingdomNexus
 * @since 2.8.6
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('knx_ops_pwa_sw_public_path')) {
    /**
     * Public path used by the browser to register the SW.
     * Keep it stable.
     *
     * @return string
     */
    function knx_ops_pwa_sw_public_path() {
        return '/knx-ops-sw.js';
    }
}

if (!function_exists('knx_ops_pwa_sw_file_path')) {
    /**
     * Physical file path for the SW source (inside plugin).
     *
     * @return string
     */
    function knx_ops_pwa_sw_file_path() {
        if (!defined('KNX_PATH') || !KNX_PATH) return '';
        return KNX_PATH . 'inc/core/pwa/knx-ops-sw.js';
    }
}

if (!function_exists('knx_ops_pwa_is_ops_role')) {
    /**
     * Decide if current visitor is OPS (super_admin or manager).
     * Uses KNX session if available; falls back to WP roles.
     *
     * @return bool
     */
    function knx_ops_pwa_is_ops_role() {
        // Prefer KNX session (authoritative for your stack)
        if (function_exists('knx_get_session')) {
            $session = knx_get_session();
            if ($session && isset($session->role)) {
                $role = (string) $session->role;
                return in_array($role, ['super_admin', 'manager'], true);
            }
        }

        // Fallback to WP user roles (best-effort)
        if (function_exists('wp_get_current_user')) {
            $u = wp_get_current_user();
            if ($u && !empty($u->roles) && is_array($u->roles)) {
                foreach ($u->roles as $r) {
                    if (in_array((string)$r, ['administrator', 'editor', 'manager', 'super_admin'], true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}

if (!function_exists('knx_ops_pwa_serve_sw_if_requested')) {
    /**
     * Serve the Service Worker JS when /knx-ops-sw.js is requested.
     *
     * @return void
     */
    function knx_ops_pwa_serve_sw_if_requested() {
        // Only handle front-end requests
        if (is_admin()) return;

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = '/' . ltrim($path, '/');

        if ($path !== knx_ops_pwa_sw_public_path()) return;

        $file = knx_ops_pwa_sw_file_path();
        if (!$file || !file_exists($file)) {
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not Found';
            exit;
        }

        // SW must be JS
        status_header(200);
        header('Content-Type: application/javascript; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        // Do not cache SW aggressively during development
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Allow SW scope to be site-root (needed if you want it to control /ops pages)
        header('Service-Worker-Allowed: /');

        // Output file
        $js = @file_get_contents($file);
        if ($js === false) {
            status_header(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Failed to read Service Worker';
            exit;
        }

        echo $js;
        exit;
    }
}
add_action('template_redirect', 'knx_ops_pwa_serve_sw_if_requested', 0);

if (!function_exists('knx_ops_pwa_print_sw_register_script')) {
    /**
     * Print inline SW registration script (NO wp_footer).
     * Gated by OPS roles only.
     *
     * @return void
     */
    function knx_ops_pwa_print_sw_register_script() {
        if (is_admin()) return;
        if (!knx_ops_pwa_is_ops_role()) return;

        // Push/SW requires HTTPS (except localhost). Fail-closed.
        $is_https = function_exists('is_ssl') ? is_ssl() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        if (!$is_https) return;

        $sw_url = home_url(knx_ops_pwa_sw_public_path());
        $ver = defined('KNX_VERSION') ? (string) KNX_VERSION : '0';

        echo "\n" . '<script>(function(){' . "\n";
        echo "  try {\n";
        echo "    if (!('serviceWorker' in navigator)) return;\n";
        echo "    var swUrl = " . json_encode($sw_url) . " + '?v=' + " . json_encode($ver) . ";\n";
        echo "    navigator.serviceWorker.register(swUrl, { scope: '/' })\n";
        echo "      .then(function(reg){\n";
        echo "        window.KNX_OPS_SW = { registered:true, scope:(reg && reg.scope) ? reg.scope : '/' };\n";
        echo "      })\n";
        echo "      .catch(function(err){\n";
        echo "        window.KNX_OPS_SW = { registered:false, error:String(err && err.message ? err.message : err) };\n";
        echo "      });\n";
        echo "  } catch(e) {}\n";
        echo '})();</script>' . "\n";
    }
}

/**
 * We use wp_body_open to avoid wp_footer (project rule).
 * This guarantees the registration runs early on every frontend page for OPS roles.
 */
add_action('wp_body_open', 'knx_ops_pwa_print_sw_register_script', 5);
