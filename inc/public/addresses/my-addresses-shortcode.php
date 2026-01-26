<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * MY ADDRESSES — Shortcode (v2.0 Clean MVP)
 * ----------------------------------------------------------
 * Shortcode: [knx_my_addresses]
 * 
 * Strategy:
 * - Minimal PHP, maximum JS
 * - REST-driven CRUD (6 endpoints already exist)
 * - Leaflet map integration
 * - Mobile-first cards layout
 * - Proven pattern from customers/fees
 * ==========================================================
 */

add_shortcode('knx_my_addresses', function () {
    
    // Auth check
    $customer_id = 0;
    
    if (function_exists('knx_get_session')) {
        $session = knx_get_session();
        if ($session && isset($session->user_id)) {
            $customer_id = (int) $session->user_id;
        }
    }
    
    
    // Guest: redirect to login
    if ($customer_id <= 0) {
        return '
            <div class="knx-addresses-guest">
                <div class="knx-empty-state">
                    <i class="fas fa-lock" style="font-size:48px;color:#ccc;"></i>
                    <h3>Login Required</h3>
                    <p>Please log in to manage your addresses.</p>
                    <a href="/login" class="knx-btn knx-btn-primary">
                        <i class="fas fa-sign-in-alt"></i> Log In
                    </a>
                </div>
            </div>
        ';
    }
    
    // REST endpoints
    $api_list       = rest_url('knx/v1/addresses/list');
    $api_add        = rest_url('knx/v1/addresses/add');
    $api_update     = rest_url('knx/v1/addresses/update');
    $api_delete     = rest_url('knx/v1/addresses/delete');
    $api_default    = rest_url('knx/v1/addresses/set-default');
    $api_select     = rest_url('knx/v1/addresses/select');
    
    // Canon nonce used across Nexus UIs
    $nonce = wp_create_nonce('knx_nonce');
    
    // Assets
    $css_url = KNX_URL . 'inc/public/addresses/my-addresses-style.css?v=' . KNX_VERSION;
    $js_url  = KNX_URL . 'inc/public/addresses/my-addresses-script.js?v=' . KNX_VERSION;
    
    ob_start();
?>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" 
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" 
      crossorigin="" />

<!-- Plugin CSS -->
<link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">

<div class="knx-addresses-wrapper"
     data-customer-id="<?php echo esc_attr($customer_id); ?>"
     data-api-list="<?php echo esc_url($api_list); ?>"
     data-api-add="<?php echo esc_url($api_add); ?>"
     data-api-update="<?php echo esc_url($api_update); ?>"
     data-api-delete="<?php echo esc_url($api_delete); ?>"
     data-api-default="<?php echo esc_url($api_default); ?>"
     data-api-select="<?php echo esc_url($api_select); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>">
    
    <!-- Header -->
    <div class="knx-addresses-header">
        <h2>
            <i class="fas fa-map-marker-alt"></i>
            My Addresses
        </h2>
        <div class="knx-addresses-controls">
            <a href="/cart" class="knx-btn knx-btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Cart
            </a>
            <button type="button" id="knxAddressesAddBtn" class="knx-btn knx-btn-primary">
                <i class="fas fa-plus"></i> Add Address
            </button>
        </div>
    </div>
    
    <!-- Address Cards -->
    <div class="knx-addresses-grid" id="knxAddressesGrid">
        <div class="knx-loading">
            <div class="knx-spinner"></div>
            <p>Loading addresses...</p>
        </div>
    </div>
    
    <!-- Empty State (hidden initially, shown by JS if needed) -->
    <div class="knx-empty-state" id="knxAddressesEmpty" style="display:none;">
        <i class="fas fa-map-marked-alt" style="font-size:48px;color:#ccc;"></i>
        <h3>No Addresses Yet</h3>
        <p>Add your first delivery address to get started.</p>
        <button type="button" class="knx-btn knx-btn-primary" onclick="document.getElementById('knxAddressesAddBtn').click()">
            <i class="fas fa-plus"></i> Add Address
        </button>
    </div>
    
</div>

<!-- Editor Modal -->
<div class="knx-modal" id="knxAddressModal" style="display:none;">
    <div class="knx-modal-backdrop" id="knxAddressModalBackdrop"></div>
    <div class="knx-modal-content">
        <div class="knx-modal-header">
            <h3 id="knxAddressModalTitle">Add Address</h3>
            <button type="button" class="knx-modal-close" id="knxAddressModalClose">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="knxAddressForm" class="knx-modal-body">
            <input type="hidden" id="knxAddressId" value="">
            
            <div class="knx-form-group">
                <label for="knxAddressLabel">Label *</label>
                <input type="text" id="knxAddressLabel" placeholder="Home, Work, etc." required maxlength="100">
            </div>
            
            <div class="knx-form-group">
                <label for="knxAddressLine1">Street Address *</label>
                <input type="text" id="knxAddressLine1" placeholder="123 Main St" required maxlength="255">
            </div>
            
            <div class="knx-form-group">
                <label for="knxAddressLine2">Apt, Suite, Unit</label>
                <input type="text" id="knxAddressLine2" placeholder="Apt 4B" maxlength="255">
            </div>
            
            <div class="knx-form-row">
                <div class="knx-form-group">
                    <label for="knxAddressCity">City *</label>
                    <input type="text" id="knxAddressCity" placeholder="Chicago" required maxlength="100">
                </div>
                <div class="knx-form-group">
                    <label for="knxAddressState">State</label>
                    <input type="text" id="knxAddressState" placeholder="IL" maxlength="50">
                </div>
            </div>
            
            <div class="knx-form-row">
                <div class="knx-form-group">
                    <label for="knxAddressZip">Zip Code</label>
                    <input type="text" id="knxAddressZip" placeholder="60601" maxlength="20">
                </div>
                <div class="knx-form-group">
                    <label for="knxAddressCountry">Country</label>
                    <input type="text" id="knxAddressCountry" value="USA" maxlength="100">
                </div>
            </div>
            
            <!-- Map Section -->
            <div class="knx-form-group">
                <label>Pin Location on Map *</label>
                <div class="knx-map-controls">
                    <button type="button" class="knx-btn knx-btn-sm" id="knxBtnUseLocation">
                        <i class="fas fa-crosshairs"></i> Use My Location
                    </button>
                    <button type="button" class="knx-btn knx-btn-sm" id="knxBtnSearchAddress">
                        <i class="fas fa-search"></i> Search Address
                    </button>
                </div>
                <div id="knxAddressMap" class="knx-map"></div>
                <small class="knx-hint" id="knxMapHint">Click or drag the pin to set exact location</small>
            </div>
            
            <!-- Hidden coordinates -->
            <input type="hidden" id="knxAddressLat" value="">
            <input type="hidden" id="knxAddressLng" value="">
            
            <div class="knx-modal-actions">
                <button type="button" class="knx-btn knx-btn-secondary" id="knxAddressCancelBtn">
                    Cancel
                </button>
                <button type="submit" class="knx-btn knx-btn-primary" id="knxAddressSaveBtn">
                    <i class="fas fa-save"></i> Save Address
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>

<!-- Plugin JS -->
<script src="<?php echo esc_url($js_url); ?>"></script>

<?php
    return ob_get_clean();
});

