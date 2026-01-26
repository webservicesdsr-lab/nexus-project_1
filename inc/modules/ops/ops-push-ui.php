<?php
/**
 * ==========================================================
 * Kingdom Nexus — OPS Push UI (Inline, No Enqueue, No wp_footer)
 * ==========================================================
 *
 * Renders a small widget/button for OPS roles (super_admin/manager)
 * that toggles Push Notifications:
 * - Subscribe -> POST /knx/v2/ops/push/subscribe
 * - Unsubscribe -> POST /knx/v2/ops/push/unsubscribe
 *
 * Requires:
 * - OPS Service Worker router working (Jalón 2): /knx-ops-sw.js
 *
 * FAIL-CLOSED:
 * - If not HTTPS, not supported, not OPS role, or no SW -> widget hides.
 *
 * @package KingdomNexus
 * @since 2.8.6
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('knx_ops_push_ui_is_ops_role')) {
    /**
     * Determine if current visitor is OPS (super_admin or manager).
     * Prefers KNX session; falls back to WP user roles best-effort.
     *
     * @return bool
     */
    function knx_ops_push_ui_is_ops_role() {
        if (function_exists('knx_get_session')) {
            $session = knx_get_session();
            if ($session && isset($session->role)) {
                $role = (string) $session->role;
                return in_array($role, ['super_admin', 'manager'], true);
            }
        }

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

if (!function_exists('knx_ops_push_ui_should_render')) {
    /**
     * Render only on OPS pages (best-effort heuristic).
     * You can adjust slugs anytime.
     *
     * @return bool
     */
    function knx_ops_push_ui_should_render() {
        if (is_admin()) return false;
        if (!knx_ops_push_ui_is_ops_role()) return false;

        // Push requires HTTPS (except localhost). Fail-closed.
        $is_https = function_exists('is_ssl') ? is_ssl() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        if (!$is_https) return false;

        global $post;
        $slug = is_object($post) ? (string) $post->post_name : '';

        $default_slugs = [
            'ops-orders',
            'ops',
            'ops-dashboard',
            'ops-settings',
        ];

        /**
         * Allow overriding the OPS slugs list.
         *
         * @param array $slugs
         */
        $slugs = function_exists('apply_filters') ? apply_filters('knx_ops_push_ui_slugs', $default_slugs) : $default_slugs;

        // If we can't detect slug reliably, allow rendering (still fail-closed in JS)
        if ($slug === '') return true;

        return in_array($slug, (array)$slugs, true);
    }
}

if (!function_exists('knx_ops_push_ui_nonce')) {
    /**
     * Create a nonce for REST calls (best-effort).
     *
     * @return string
     */
    function knx_ops_push_ui_nonce() {
        if (function_exists('knx_create_nonce')) {
            // If you have a canonical nonce helper, prefer it.
            return (string) knx_create_nonce();
        }
        if (function_exists('wp_create_nonce')) {
            return (string) wp_create_nonce('wp_rest');
        }
        return '';
    }
}

if (!function_exists('knx_ops_push_ui_render')) {
    /**
     * Print widget + inline JS (from ops-push-ui.js).
     *
     * @return void
     */
    function knx_ops_push_ui_render() {
        if (!knx_ops_push_ui_should_render()) return;

        $subscribe_url   = function_exists('rest_url') ? rest_url('knx/v2/ops/push/subscribe') : '/wp-json/knx/v2/ops/push/subscribe';
        $unsubscribe_url = function_exists('rest_url') ? rest_url('knx/v2/ops/push/unsubscribe') : '/wp-json/knx/v2/ops/push/unsubscribe';
        $settings_url    = function_exists('rest_url') ? rest_url('knx/v2/ops/get') : '/wp-json/knx/v2/ops/get';

        $nonce = knx_ops_push_ui_nonce();

        // Load JS source (no enqueue)
        $js_path = defined('KNX_PATH') ? KNX_PATH . 'inc/modules/ops/ops-push-ui.js' : '';
        $js_src  = ($js_path && file_exists($js_path)) ? (string) @file_get_contents($js_path) : '';

        // Minimal CSS
        echo "\n" . '<style id="knx-ops-push-ui-css">
#knxOpsPushWidget{position:fixed;right:14px;bottom:14px;z-index:99999;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
#knxOpsPushWidget .knx-card{background:#0b0f14;color:#fff;border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:10px 12px;box-shadow:0 10px 30px rgba(0,0,0,.35);min-width:220px}
#knxOpsPushWidget .knx-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
#knxOpsPushWidget .knx-title{font-size:12px;opacity:.85;margin:0 0 6px 0}
#knxOpsPushBtn{appearance:none;border:0;border-radius:12px;padding:8px 10px;font-weight:600;cursor:pointer;background:#22c55e;color:#08110a}
#knxOpsPushBtn[data-state="off"]{background:#f59e0b;color:#1b1202}
#knxOpsPushBtn[data-state="busy"]{background:#94a3b8;color:#0b0f14;cursor:wait}
#knxOpsPushState{font-size:12px;opacity:.85;white-space:nowrap}
#knxOpsPushWidget[hidden]{display:none!important}
</style>' . "\n";

        // Widget HTML (data-* -> JS)
        echo "\n" . '<div id="knxOpsPushWidget" hidden
  data-subscribe-url="' . esc_attr($subscribe_url) . '"
  data-unsubscribe-url="' . esc_attr($unsubscribe_url) . '"
  data-settings-url="' . esc_attr($settings_url) . '"
  data-nonce="' . esc_attr($nonce) . '"
  data-audience="ops_orders"
>
  <div class="knx-card">
    <div class="knx-title">OPS Push</div>
    <div class="knx-row">
      <button id="knxOpsPushBtn" type="button" data-state="busy">Loading…</button>
      <span id="knxOpsPushState">…</span>
    </div>
  </div>
</div>' . "\n";

        // Inline JS (fail-closed if missing)
        echo "\n" . '<script id="knx-ops-push-ui-js">(function(){' . "\n";
        if ($js_src !== '') {
            echo $js_src . "\n";
        } else {
            echo "try{var w=document.getElementById('knxOpsPushWidget'); if(w) w.hidden=true;}catch(e){}\n";
        }
        echo "\n" . '})();</script>' . "\n";
    }
}

// Use wp_body_open (project rule: avoid wp_footer)
add_action('wp_body_open', 'knx_ops_push_ui_render', 30);
