<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Drivers — Driver Dashboard Shortcode (SEALED MVP v1.2)
 * Shortcode: [knx_driver_dashboard]
 * ----------------------------------------------------------
 * Rules:
 * - No wp_enqueue_* (NEXUS standard)
 * - No wp_footer dependency
 * - JS reads window.KNX_DRIVER_CONFIG (inline JSON)
 * - Loads JS via <script defer src="...">
 * - Prints PWA meta into <head> only when shortcode is present
 * ==========================================================
 */

add_action('init', function () {
    add_shortcode('knx_driver_dashboard', 'knx_driver_dashboard_shortcode');
});

add_action('wp_head', function () {
    if (!knx_drivers_page_has_dashboard()) return;

    $manifest = function_exists('knx_pwa_driver_manifest_url')
        ? knx_pwa_driver_manifest_url()
        : home_url('/knx-driver-manifest.json');

    $theme = '#0B793A';

    echo '<link rel="manifest" href="' . esc_url($manifest) . '">' . "\n";
    echo '<meta name="theme-color" content="' . esc_attr($theme) . '">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
}, 1);

function knx_drivers_page_has_dashboard() {
    if (!function_exists('is_singular') || !is_singular()) return false;
    global $post;
    if (!$post || !isset($post->post_content)) return false;
    if (!function_exists('has_shortcode')) return false;
    return has_shortcode((string) $post->post_content, 'knx_driver_dashboard');
}

function knx_driver_dashboard_shortcode($atts = []) {

    /**
     * Resolve driver context (canonical). Fail-closed by default.
     * Allow a minimal "no hubs yet" empty state for active driver users.
     */
    $ctx = false;
    $allow_no_hubs = false;

    if (function_exists('knx_get_driver_context')) {
        $ctx = knx_get_driver_context();
    }

    if (!$ctx) {
        $session = function_exists('knx_get_session') ? knx_get_session() : false;
        $driver_row = null;
        $driver_id = 0;

        if ($session && isset($session->role) && $session->role === 'driver') {
            $driver_id = isset($session->user_id) ? intval($session->user_id) : (isset($session->id) ? intval($session->id) : 0);

            if ($driver_id > 0 && function_exists('knx_table')) {
                global $wpdb;
                $tD = knx_table('drivers');

                $driver_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, driver_user_id, status FROM {$tD} WHERE driver_user_id = %d OR id = %d LIMIT 1",
                    $driver_id, $driver_id
                ));

                if ($driver_row && (!isset($driver_row->status) || $driver_row->status === 'active')) {
                    $allow_no_hubs = true;
                }
            }
        }

        if (!$allow_no_hubs) {
            ob_start();
            ?>
            <div id="knx-driver-dashboard" class="knx-driver-dashboard knx-drivers-signed" data-knx="driver-dashboard">
                <div class="knx-card knx-card--soft">
                    <div class="knx-card__body">
                        <div class="knx-title">Access restricted</div>
                        <div class="knx-muted">Your session may have expired or you don’t have driver access.</div>
                    </div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        $ctx = (object) [
            'driver_id' => $driver_id,
            'mode'      => 'driver',
            'driver'    => $driver_row,
            'hubs'      => [],
            'session'   => $session,
        ];
    }

    $vapid = function_exists('knx_push_vapid_public_key') ? knx_push_vapid_public_key() : '';
    $start_url = function_exists('get_permalink') ? get_permalink() : home_url('/driver-dashboard/');

    // Allow admin to view as driver by passing ?as_driver_id=NN (kept for internal testing)
    $as_user = 0;
    if (function_exists('knx_get_session')) {
        $s = knx_get_session();
        $role = isset($s->role) ? (string) $s->role : '';
        $maybe = isset($_GET['as_driver_id']) ? (int) $_GET['as_driver_id'] : 0;
        if ($maybe > 0 && in_array($role, ['super_admin', 'manager'], true)) {
            $as_user = $maybe;
        }
    }

    $config = [
        'myOrders'         => rest_url('knx/v1/driver/my-orders'),
        'updateStatus'     => rest_url('knx/v1/driver/update-status'),
        'releaseEndpoint'  => rest_url('knx/v1/driver/release'),
        'delayEndpoint'    => rest_url('knx/v1/driver/delay'),
        'ordersHistory'    => rest_url('knx/v1/driver/orders/history'),
        'availabilityGet'  => rest_url('knx/v1/driver/availability'),
        'availabilitySet'  => rest_url('knx/v1/driver/availability'),
        'pushSubscribe'    => rest_url('knx/v1/push/subscribe'),
        'pushUnsubscribe'  => rest_url('knx/v1/push/unsubscribe'),
        'pushTest'         => rest_url('knx/v1/push/test'),
        'vapidPublicKey'   => $vapid,
        'swUrl'            => function_exists('knx_pwa_driver_sw_url') ? knx_pwa_driver_sw_url() : home_url('/knx-driver-sw.js'),
        'manifestUrl'      => function_exists('knx_pwa_driver_manifest_url') ? knx_pwa_driver_manifest_url() : home_url('/knx-driver-manifest.json'),
        'startUrl'         => $start_url,
        'asUserId'         => $as_user,
    ];

    $css_url = defined('KNX_URL') ? (KNX_URL . 'inc/modules/drivers/drivers-style.css?ver=' . rawurlencode((string) KNX_VERSION)) : '';
    $js_url  = defined('KNX_URL') ? (KNX_URL . 'inc/modules/drivers/drivers-script.js?ver=' . rawurlencode((string) KNX_VERSION)) : '';

    ob_start();
    ?>
    <div id="knx-driver-dashboard" class="knx-driver-dashboard knx-drivers-signed" data-knx="driver-dashboard">

        <?php if ($css_url) : ?>
            <link id="knx-drivers-style" rel="stylesheet" href="<?php echo esc_url($css_url); ?>" />
        <?php endif; ?>

        <header class="knx-driver-topbar">
            <div id="knx-driver-banner" class="knx-driver-banner" aria-live="polite" style="display:none;"></div>
            <div class="knx-driver-topbar__left">
                <div class="knx-app-title">Driver Ops</div>
                <div class="knx-app-sub">Quick actions · live orders</div>
            </div>

            <div class="knx-driver-topbar__right">
                <div class="knx-availability">
                    <span id="knx-driver-availability-pill" class="knx-pill knx-pill--muted">Loading…</span>
                    <button type="button" id="knx-driver-toggle-availability" class="knx-btn knx-btn--ghost knx-btn--sm">Toggle</button>
                </div>

                <button type="button" class="knx-btn knx-btn--ghost knx-btn--sm" id="knx-driver-open-history">History</button>
                <button type="button" class="knx-btn knx-btn--ghost knx-btn--sm" id="knx-driver-push-test">Test notifications</button>
                <button type="button" class="knx-btn knx-btn--primary knx-btn--sm" id="knx-driver-refresh-btn">Refresh</button>
            </div>
        </header>

        <section class="knx-driver-statusbar" aria-label="Driver status">
            <div id="knx-driver-pill" class="knx-pill knx-pill--soft">Loading…</div>
            <div class="knx-driver-hint">Tip: you can finish assigned orders even if you go Off Duty.</div>
        </section>

        <main id="knx-driver-list" class="knx-driver-list" aria-live="polite">
            <div class="knx-card knx-card--soft">
                <div class="knx-card__body">
                    <?php if (empty($ctx->hubs)) : ?>
                        <div class="knx-title">No hubs assigned yet</div>
                        <div class="knx-muted">Your account is active. A manager will assign hubs soon.</div>
                    <?php else : ?>
                        <div class="knx-title">Loading your orders…</div>
                        <div class="knx-muted">Please wait.</div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <!-- Confirm Modal (replaces window.confirm) -->
        <div id="knx-driver-modal" class="knx-modal" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="knx-modal__backdrop" data-knx-close="1"></div>
            <div class="knx-modal__panel" role="document">
                <div class="knx-modal__head">
                    <div id="knx-modal-title" class="knx-modal__title">Confirm</div>
                    <button class="knx-iconbtn" type="button" data-knx-close="1" aria-label="Close">✕</button>
                </div>
                <div id="knx-modal-body" class="knx-modal__body"></div>
                <div class="knx-modal__actions">
                    <button id="knx-modal-cancel" type="button" class="knx-btn knx-btn--ghost">Cancel</button>
                    <button id="knx-modal-confirm" type="button" class="knx-btn knx-btn--primary">Confirm</button>
                </div>
            </div>
        </div>

        <!-- Delay Modal (quick presets) -->
        <div id="knx-driver-delay" class="knx-modal" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="knx-modal__backdrop" data-knx-delay-close="1"></div>
            <div class="knx-modal__panel" role="document">
                <div class="knx-modal__head">
                    <div class="knx-modal__title">Report a delay</div>
                    <button class="knx-iconbtn" type="button" data-knx-delay-close="1" aria-label="Close">✕</button>
                </div>
                <div class="knx-modal__body">
                    <div class="knx-muted" style="margin-bottom:10px;">Pick a quick update the customer can trust.</div>
                    <div class="knx-chipgrid">
                        <button type="button" class="knx-chip" data-delay-code="10_min_delay">Running ~10 minutes late</button>
                        <button type="button" class="knx-chip" data-delay-code="20_min_delay">Running ~20 minutes late</button>
                        <button type="button" class="knx-chip" data-delay-code="30_min_delay">Running ~30 minutes late</button>
                    </div>
                    <div class="knx-muted" style="margin-top:10px;">(MVP: this saves a delay flag. Customer messaging comes later.)</div>
                </div>
                <div class="knx-modal__actions">
                    <button type="button" class="knx-btn knx-btn--ghost" data-knx-delay-close="1">Close</button>
                </div>
            </div>
        </div>

        <!-- History Modal (IDs only + dummy receipt button) -->
        <div id="knx-driver-history" class="knx-modal" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="knx-modal__backdrop" data-knx-history-close="1"></div>
            <div class="knx-modal__panel" role="document">
                <div class="knx-modal__head">
                    <div class="knx-modal__title">Completed orders</div>
                    <button class="knx-iconbtn" type="button" data-knx-history-close="1" aria-label="Close">✕</button>
                </div>

                <div class="knx-modal__body">
                    <div id="knx-history-list" class="knx-history-list">
                        <div class="knx-muted">Loading…</div>
                    </div>

                    <div class="knx-pager">
                        <button id="knx-history-prev" type="button" class="knx-btn knx-btn--ghost knx-btn--sm">Prev</button>
                        <div id="knx-history-page" class="knx-pill knx-pill--soft">Page 1</div>
                        <button id="knx-history-next" type="button" class="knx-btn knx-btn--ghost knx-btn--sm">Next</button>
                    </div>

                    <div class="knx-muted" style="margin-top:10px;">
                        Security note: only order IDs are shown here.
                    </div>
                </div>

                <div class="knx-modal__actions">
                    <button type="button" class="knx-btn knx-btn--primary" data-knx-history-close="1">Done</button>
                </div>
            </div>
        </div>

        <div id="knx-driver-toast" class="knx-toast" aria-live="polite" aria-atomic="true"></div>

        <script id="knx-drivers-inline-config">
            window.KNX_DRIVER_CONFIG = <?php echo wp_json_encode($config); ?>;
        </script>

        <?php if ($js_url) : ?>
            <script id="knx-drivers-script" defer src="<?php echo esc_url($js_url); ?>"></script>
        <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
}
