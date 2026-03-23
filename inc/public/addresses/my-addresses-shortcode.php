<?php
if (!defined('ABSPATH')) exit;

/**
 * ════════════════════════════════════════════════════════════════
 * MY ADDRESSES — Shortcode v3.0 (UX Overhaul)
 * ════════════════════════════════════════════════════════════════
 * Shortcode: [knx_my_addresses]
 *
 * v3.0 Changes:
 * - Visual hierarchy: primary address highlighted
 * - Context-aware back button (return_to param)
 * - Simplified modal with address search (Nominatim)
 * - Label chips (Home / Work / Other)
 * - Collapsible apt/suite field
 * - Inline map preview on cards
 * - Mobile-first bottom-sheet modal
 * - Toast notifications (no alerts)
 * ════════════════════════════════════════════════════════════════
 */

add_shortcode('knx_my_addresses', function () {

    // ── Auth ──
    $customer_id = 0;
    if (function_exists('knx_get_session')) {
        $session = knx_get_session();
        if ($session && isset($session->user_id)) {
            $customer_id = (int) $session->user_id;
        }
    }

    if ($customer_id <= 0) {
        return '
        <div style="max-width:480px;margin:4rem auto;text-align:center;padding:3rem 2rem;">
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" style="margin-bottom:1rem;">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
            <h3 style="margin:0 0 .5rem;font-size:1.25rem;color:#1f2937;">Login Required</h3>
            <p style="color:#6b7280;margin:0 0 1.5rem;">Please log in to manage your delivery addresses.</p>
            <a href="/login" style="display:inline-flex;align-items:center;gap:.5rem;padding:.75rem 1.5rem;background:#225638;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Log In</a>
        </div>';
    }

    // ── REST endpoints ──
    $api = [
        'list'    => rest_url('knx/v1/addresses/list'),
        'add'     => rest_url('knx/v1/addresses/add'),
        'update'  => rest_url('knx/v1/addresses/update'),
        'delete'  => rest_url('knx/v1/addresses/delete'),
        'default' => rest_url('knx/v1/addresses/set-default'),
        'select'  => rest_url('knx/v1/addresses/select'),
    ];
    $nonce = wp_create_nonce('knx_nonce');

    // ── Context-aware back link ──
    $return_to  = isset($_GET['return_to']) ? sanitize_text_field($_GET['return_to']) : '';
    $back_url   = $return_to ? site_url($return_to) : '';
    $back_label = '';
    if ($return_to === '/cart')     $back_label = 'Back to Cart';
    elseif ($return_to === '/checkout') $back_label = 'Back to Checkout';
    elseif ($return_to)             $back_label = 'Go Back';

    // ── Assets ──
    $css_url = KNX_URL . 'inc/public/addresses/my-addresses-style.css?v=' . KNX_VERSION;
    $js_url  = KNX_URL . 'inc/public/addresses/my-addresses-script.js?v=' . KNX_VERSION;

    ob_start();
?>
<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin="" />
<link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">

<div class="knx-addr"
     data-customer-id="<?php echo esc_attr($customer_id); ?>"
     data-api-list="<?php echo esc_url($api['list']); ?>"
     data-api-add="<?php echo esc_url($api['add']); ?>"
     data-api-update="<?php echo esc_url($api['update']); ?>"
     data-api-delete="<?php echo esc_url($api['delete']); ?>"
     data-api-default="<?php echo esc_url($api['default']); ?>"
     data-api-select="<?php echo esc_url($api['select']); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>">

    <!-- ═══ HEADER ═══ -->
    <header class="knx-addr__header">
        <div class="knx-addr__header-left">
            <?php if ($back_url): ?>
            <a href="<?php echo esc_url($back_url); ?>" class="knx-addr__back">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                <?php echo esc_html($back_label); ?>
            </a>
            <?php endif; ?>
            <h1 class="knx-addr__title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
                </svg>
                My Addresses
            </h1>
        </div>
        <button type="button" class="knx-addr__add-btn" id="knxAddrAddBtn">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Add Address</span>
        </button>
    </header>

    <!-- ═══ LOADING ═══ -->
    <div class="knx-addr__loading" id="knxAddrLoading">
        <div class="knx-addr__spinner"></div>
        <p>Loading your addresses…</p>
    </div>

    <!-- ═══ CARDS GRID ═══ -->
    <div class="knx-addr__grid" id="knxAddrGrid"></div>

    <!-- ═══ EMPTY STATE ═══ -->
    <div class="knx-addr__empty" id="knxAddrEmpty" style="display:none;">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
        </svg>
        <h3>No Addresses Yet</h3>
        <p>Add your first delivery address to start ordering.</p>
        <button type="button" class="knx-addr__add-btn" onclick="document.getElementById('knxAddrAddBtn').click()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Add Address</span>
        </button>
    </div>
</div>

<?php include __DIR__ . '/addresses-modal-partial.php'; ?>

<!-- Leaflet JS and client config remain (assets and script left in place) -->
<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<script>
// Expose location provider and Google Maps key to the page so client scripts
// can automatically prefer Google Places for autocomplete when configured.
window.KNX_LOCATION_PROVIDER = (function(){
    try {
        var key = <?php echo json_encode(function_exists('knx_get_google_maps_key') ? knx_get_google_maps_key() : get_option('knx_google_maps_key','')); ?>;
        window.KNX_MAPS_CONFIG = window.KNX_MAPS_CONFIG || {};
        window.KNX_MAPS_CONFIG.key = key && key !== '' ? key : null;
        return (key && key !== '') ? 'google' : 'nominatim';
    } catch (e) { return 'nominatim'; }
})();
window.KNX_GEOCODE_API = window.KNX_GEOCODE_API || "<?php echo esc_js(rest_url('knx/v1/geocode-search')); ?>";
</script>
<script src="<?php echo esc_url($js_url); ?>"></script>
<?php
    return ob_get_clean();
});

