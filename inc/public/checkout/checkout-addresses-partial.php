<?php
if (!defined('ABSPATH')) exit;

// Minimal, checkout-scoped addresses CRUD partial.
// Reuses the same REST endpoints and nonce as the full addresses page.

// Ensure customer context
$customer_id = 0;
if (function_exists('knx_get_session')) {
    $session = knx_get_session();
    if ($session && isset($session->user_id)) {
        $customer_id = (int) $session->user_id;
    }
}

// REST endpoints (same as my-addresses)
$api = [
    'list'    => rest_url('knx/v1/addresses/list'),
    'add'     => rest_url('knx/v1/addresses/add'),
    'update'  => rest_url('knx/v1/addresses/update'),
    'delete'  => rest_url('knx/v1/addresses/delete'),
    'default' => rest_url('knx/v1/addresses/set-default'),
    'select'  => rest_url('knx/v1/addresses/select'),
];
$nonce = wp_create_nonce('knx_nonce');

?>

<div class="knx-co-card knx-co-card--address knx-co-card--Address--checkout" id="knxCheckoutAddressesWrapper">
    <div class="knx-co-card__head">
        <div class="knx-co-card__headleft">
            <span class="knx-co-iconpin" aria-hidden="true">📍</span>
            <h2>Delivery Address</h2>
        </div>

        <div class="knx-co-card__headright">
            <div style="display:flex;align-items:center;gap:8px;">
                <button type="button" id="knxCheckoutAddrToggleBtn" class="knx-co-btn knx-co-btn--ghost" aria-expanded="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M6 9l6 6 6-6"/>
                    </svg>
                </button>

                <button type="button" id="knxCheckoutAddrAddBtn" class="knx-co-btn knx-co-btn--small" style="display:none;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span style="margin-left:6px;">Add</span>
                </button>
            </div>

            <a href="<?php echo esc_url(site_url('/my-addresses')); ?>" class="knx-co-link knx-co-link--small" style="margin-left:8px;">
                Manage
            </a>
        </div>
    </div>

    <div class="knx-co-card__body">
        <div id="knxCheckoutAddr" class="knx-co-addresses"
             data-customer-id="<?php echo esc_attr($customer_id); ?>"
             data-api-list="<?php echo esc_attr($api['list']); ?>"
             data-api-add="<?php echo esc_attr($api['add']); ?>"
             data-api-update="<?php echo esc_attr($api['update']); ?>"
             data-api-delete="<?php echo esc_attr($api['delete']); ?>"
             data-api-default="<?php echo esc_attr($api['default']); ?>"
             data-api-select="<?php echo esc_attr($api['select']); ?>"
             data-nonce="<?php echo esc_attr($nonce); ?>">

            <div id="knxCheckoutAddrLoading" class="knx-addr__loading">
                Loading addresses…
            </div>

            <div id="knxCheckoutAddrList" class="knx-addr__list" aria-live="polite"></div>

            <div id="knxCheckoutAddrEmpty" class="knx-addr__empty" style="display:none;margin-top:12px;">
                <p style="margin:0 0 8px 0;color:#6b7280;">No delivery addresses yet.</p>
                <div>
                    <a href="#" class="knx-co-btn knx-co-btn--primary" id="knxCheckoutAddrEmptyAdd">
                        Add Address
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<?php
// ─────────────────────────────────────────────
// STYLES (CANONICAL)
// ─────────────────────────────────────────────
?>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      crossorigin="" />

<link rel="stylesheet"
      href="<?php echo esc_url(KNX_URL . 'inc/public/addresses/my-addresses-style.css?v=' . KNX_VERSION); ?>">

<?php
// ─────────────────────────────────────────────
// MODAL (SHARED)
// ─────────────────────────────────────────────
?>

<?php include __DIR__ . '/../addresses/addresses-modal-partial.php'; ?>

<?php
// ─────────────────────────────────────────────
// FORCE MODAL INTO BODY (CRITICAL FIX)
// ─────────────────────────────────────────────
?>

<script>
(function(){
    function mount(){
        try{
            var modal = document.getElementById('knxAddrModal');
            if(modal && modal.parentElement !== document.body){
                document.body.appendChild(modal);
            }

            var toast = document.getElementById('knxAddrToast') || document.getElementById('knxCheckoutAddrToast');
            if(toast && toast.parentElement !== document.body){
                document.body.appendChild(toast);
            }
            // Deduplicate any other modal instances that might have been rendered elsewhere.
            try {
                var all = Array.from(document.querySelectorAll('#knxAddrModal'));
                if (all.length > 1) {
                    // Keep the one that's already a child of body (the canonical moved one), remove the rest
                    var keep = all.find(function(n){ return n.parentElement === document.body; }) || all[0];
                    all.forEach(function(n){ if(n !== keep){
                        console.warn('knx: removing duplicate #knxAddrModal from', n.parentElement && n.parentElement.tagName, n);
                        try { n.parentElement && n.parentElement.removeChild(n); } catch(e) { try { n.remove(); } catch(_){} }
                    }});
                }

                var toasts = Array.from(document.querySelectorAll('#knxAddrToast, #knxCheckoutAddrToast'));
                if (toasts.length > 1) {
                    var kept = toasts.find(function(t){ return t.parentElement === document.body; }) || toasts[0];
                    toasts.forEach(function(t){ if (t !== kept) {
                        console.warn('knx: removing duplicate toast', t);
                        try { t.parentElement && t.parentElement.removeChild(t); } catch(e) { try { t.remove(); } catch(_){} }
                    }});
                }
            } catch (e) { console.error('knx dedupe error', e); }
        }catch(e){
            console.error('knx mount error', e);
        }
    }

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }

    // Observer for safety (late renders)
    try{
        var obs = new MutationObserver(function(muts){
            muts.forEach(function(m){
                m.addedNodes && m.addedNodes.forEach(function(n){
                    if(!n || !n.querySelector) return;
                    if(n.querySelector('#knxAddrModal') || n.id === 'knxAddrModal'){
                        mount();
                    }
                });
            });
        });

        obs.observe(document.body, { childList: true, subtree: true });

        setTimeout(function(){ obs.disconnect(); }, 8000);

    }catch(e){
        console.error('knx observer error', e);
    }

})();
</script>

<?php
// ─────────────────────────────────────────────
// SCRIPTS
// ─────────────────────────────────────────────
?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        crossorigin=""></script>

<script>
window.KNX_LOCATION_PROVIDER = (function(){
    try {
        var key = <?php echo json_encode(function_exists('knx_get_google_maps_key') ? knx_get_google_maps_key() : get_option('knx_google_maps_key','')); ?>;
        window.KNX_MAPS_CONFIG = window.KNX_MAPS_CONFIG || {};
        window.KNX_MAPS_CONFIG.key = key && key !== '' ? key : null;
        return (key && key !== '') ? 'google' : 'nominatim';
    } catch (e) {
        return 'nominatim';
    }
})();
</script>

<script src="<?php echo esc_url(KNX_URL . 'inc/public/addresses/my-addresses-script.js?v=' . KNX_VERSION); ?>"></script>

<!-- Checkout scoped toast -->
<div id="knxCheckoutAddrToast" class="knx-addr-toast" style="position:relative;top:8px;"></div>

<?php
return;