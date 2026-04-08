<?php
/**
 * ==========================================================
 * KNX Hub Notifications — Browser Soft-Push Boot
 * ==========================================================
 * Injects the soft-push polling client on ALL pages when a
 * hub_management user is logged in.
 *
 * Mirrors knx_render_driver_soft_push_bootstrap() in kingdom-nexus.php
 * but for hub_management role.
 *
 * Config is exposed as KNX_HUB_OPS_CONFIG and the client JS
 * handles adaptive polling + browser Notification API.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

/**
 * Check if the current session is a hub_management session.
 */
function knx_is_hub_management_session_active() {
    if (!function_exists('knx_get_session')) return false;

    $session = knx_get_session();
    if (!$session || !is_object($session)) return false;

    $role = (string) ($session->role ?? '');
    return (bool) preg_match('/\bhub(?:[_\-\s]?(?:management|owner|staff|manager))\b/i', $role);
}

/**
 * Render the hub soft-push bootstrap script.
 * Injected via wp_head at priority 3 (after driver push at 2).
 */
function knx_render_hub_soft_push_bootstrap() {
    if (is_admin()) return;
    if (!knx_is_hub_management_session_active()) return;

    $session = knx_get_session();
    if (!$session || empty($session->user_id)) return;

    $user_id = (int) $session->user_id;

    // Resolve hub_id
    $hub_id = 0;
    if (function_exists('knx_get_managed_hub_ids')) {
        $ids = knx_get_managed_hub_ids($user_id);
        if (!empty($ids)) $hub_id = $ids[0];
    }

    if ($hub_id <= 0) return;

    $config = [
        'softPushPoll' => rest_url('knx/v1/hub-soft-push/poll'),
        'ackUrl'       => rest_url('knx/v1/hub-soft-push/ack'),
        'ordersUrl'    => site_url('/hub-orders'),
        'hubId'        => $hub_id,
        'pollVisibleMs' => 10000,
        'pollHiddenMs'  => 30000,
        'debug'         => (defined('WP_DEBUG') && WP_DEBUG),
    ];
    ?>
    <script>
    (function () {
        'use strict';

        if (window.__KNX_HUB_SOFT_PUSH_BOOTED__) return;
        window.__KNX_HUB_SOFT_PUSH_BOOTED__ = true;

        var cfg = <?php echo wp_json_encode($config); ?>;
        var POLL_URL   = cfg.softPushPoll;
        var ACK_URL    = cfg.ackUrl;
        var ORDERS_URL = cfg.ordersUrl;
        var VISIBLE_MS = cfg.pollVisibleMs || 10000;
        var HIDDEN_MS  = cfg.pollHiddenMs  || 30000;
        var DEBUG      = !!cfg.debug;

        if (!POLL_URL) return;

        var timer = null;
        var polling = true;
        var seen = {};

        function log() {
            if (!DEBUG) return;
            try { console.log.apply(console, ['KNX hub-push:'].concat(Array.prototype.slice.call(arguments))); } catch(e){}
        }

        function scheduleNext() {
            clearTimeout(timer);
            if (!polling) return;
            var ms = document.visibilityState === 'visible' ? VISIBLE_MS : HIDDEN_MS;
            timer = setTimeout(runPoll, ms);
        }

        function showNotification(payload) {
            var nid = payload.notification_id || payload.order_id || 0;
            if (seen[nid]) return;
            seen[nid] = true;

            log('notification:', payload.title);

            // Try browser Notification API
            if ('Notification' in window && Notification.permission === 'granted') {
                try {
                    var n = new Notification(payload.title || 'New Order', {
                        body: payload.body || '',
                        icon: '/wp-content/uploads/localbites-icon.png',
                        tag: 'hub-order-' + nid,
                    });
                    n.onclick = function () {
                        window.focus();
                        window.location.href = payload.url || ORDERS_URL;
                        n.close();
                    };
                } catch (e) {
                    log('Notification API failed', e);
                }
            }

            // Also show a toast if knxToast is available
            if (typeof knxToast === 'function') {
                knxToast(payload.title + ': ' + (payload.body || ''), 'info');
            }

            // Play sound
            try {
                var audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVggoGBgXR3goyYo6WZgWVRVG+CiZCQjYZ/eHd7g42XnpmQg3hrX2JwhJGanpqSiIB3cXF1foeRmJmTi4R+dnBxeIKLlJeUjoeDfnl2dn2Gj5SXlI6HhH97eXh9gIqRlZSPi4aAfHl4e4GJkZWUj4qGgX14eHuBiJGVlI+KhIF9eXh7gYiRlZSPioWBfXl4e4GJkZWUkIqFgX15eHuAiJGVlI+KhYF9eXh7gYiRlZSPioSBfXl4e4GJkZWUkIqFgX15eHuAiZGVlI+KhIF9eXh7gYiRlZSPioSBfXl5');
                audio.volume = 0.3;
                audio.play().catch(function(){});
            } catch(e) {}

            // Ack
            if (ACK_URL && payload.notification_id) {
                fetch(ACK_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ notification_id: payload.notification_id }),
                }).catch(function(){});
            }
        }

        function runPoll() {
            if (!polling) return;

            fetch(POLL_URL, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (res) {
                    if (!res || !res.ok) {
                        if (res && (res.status === 401 || res.status === 403)) {
                            polling = false;
                            log('unauthorized — stopped');
                        }
                        scheduleNext();
                        return null;
                    }
                    return res.json();
                })
                .then(function (json) {
                    if (!json) return;

                    if (json.has && json.payload) {
                        showNotification(json.payload);
                        // Poll again immediately to check for more
                        setTimeout(runPoll, 500);
                        return;
                    }

                    scheduleNext();
                })
                .catch(function (e) {
                    log('poll error', e);
                    scheduleNext();
                });
        }

        // Request notification permission
        if ('Notification' in window && Notification.permission === 'default') {
            try { Notification.requestPermission(); } catch(e){}
        }

        // Visibility-aware polling
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                runPoll();
            } else {
                scheduleNext();
            }
        });

        // Start
        runPoll();
    })();
    </script>
    <?php
}

add_action('wp_head', 'knx_render_hub_soft_push_bootstrap', 3);
