<?php
if (!defined('ABSPATH')) exit;

// Prevent rendering this shared modal partial more than once in the same request/response
if (!defined('KNX_ADDR_MODAL_RENDERED')):
    define('KNX_ADDR_MODAL_RENDERED', true);
?>

<!-- Shared Addresses Modal Partial -->
<!-- This partial contains the full my-addresses modal (IDs prefix knxAddr*)
     It is intended to be included by both the full My Addresses page and
     the checkout partial. It intentionally contains only the modal + toast
     markup; assets and page-level JS should be included by the caller. -->

<!-- ═══════════════════════════════════════════════════════════════
     MODAL (bottom-sheet on mobile, centered on desktop)
     ═══════════════════════════════════════════════════════════════ -->
<div class="knx-addr-modal" id="knxAddrModal" aria-hidden="true">
    <div class="knx-addr-modal__backdrop" id="knxAddrModalBG"></div>
    <div class="knx-addr-modal__sheet" role="dialog" aria-modal="true" aria-labelledby="knxAddrModalTitle">
        <header class="knx-addr-modal__header">
            <h2 id="knxAddrModalTitle">Add Address</h2>
            <button type="button" class="knx-addr-modal__close" id="knxAddrModalClose" aria-label="Close">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </header>

        <form id="knxAddrForm" class="knx-addr-modal__body" autocomplete="off">
            <input type="hidden" id="knxAddrId" value="">

            <!-- Search -->
            <div class="knx-addr-form__group">
                <label for="knxAddrSearch">Search address</label>
                <div class="knx-addr-form__search-wrap">
                    <svg class="knx-addr-form__search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <input type="text" id="knxAddrSearch" placeholder="Type an address, e.g. 123 Main St…" autocomplete="off">
                </div>
                <ul class="knx-addr-form__suggestions" id="knxAddrSuggestions"></ul>
            </div>

            <!-- Label -->
            <div class="knx-addr-form__group">
                <label for="knxAddrLabel">Label <span class="knx-addr-form__req">*</span></label>
                <div class="knx-addr-form__chips" id="knxAddrLabelChips">
                    <button type="button" class="knx-addr-form__chip" data-label="Home">🏠 Home</button>
                    <button type="button" class="knx-addr-form__chip" data-label="Work">💼 Work</button>
                    <button type="button" class="knx-addr-form__chip" data-label="Other">📍 Other</button>
                </div>
                <input type="text" id="knxAddrLabel" placeholder="Home, Work, Mom's…" required maxlength="100">
            </div>

            <!-- Street -->
            <div class="knx-addr-form__group">
                <label for="knxAddrLine1">Street Address <span class="knx-addr-form__req">*</span></label>
                <input type="text" id="knxAddrLine1" placeholder="123 Main St" required maxlength="255">
            </div>

            <!-- Apt toggle -->
            <div class="knx-addr-form__group">
                <button type="button" class="knx-addr-form__apt-toggle" id="knxAddrAptToggle">+ Add apt, suite, unit</button>
                <div class="knx-addr-form__apt-wrap" id="knxAddrAptWrap" style="display:none;">
                    <input type="text" id="knxAddrLine2" placeholder="Apt 4B, Suite 200…" maxlength="255">
                </div>
            </div>

            <!-- City + State -->
            <div class="knx-addr-form__row">
                <div class="knx-addr-form__group">
                    <label for="knxAddrCity">City <span class="knx-addr-form__req">*</span></label>
                    <input type="text" id="knxAddrCity" placeholder="Chicago" required maxlength="100">
                </div>
                <div class="knx-addr-form__group">
                    <label for="knxAddrState">State</label>
                    <input type="text" id="knxAddrState" placeholder="IL" maxlength="50">
                </div>
            </div>

            <!-- Zip + Country -->
            <div class="knx-addr-form__row">
                <div class="knx-addr-form__group">
                    <label for="knxAddrZip">Zip Code</label>
                    <input type="text" id="knxAddrZip" placeholder="60601" maxlength="20">
                </div>
                <div class="knx-addr-form__group">
                    <label for="knxAddrCountry">Country</label>
                    <input type="text" id="knxAddrCountry" value="USA" maxlength="100">
                </div>
            </div>

            <!-- Map -->
            <div class="knx-addr-form__group">
                <label>Pin Location <span class="knx-addr-form__req">*</span></label>
                <div class="knx-addr-form__map-bar">
                    <button type="button" class="knx-addr-form__map-btn" id="knxAddrGeoBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
                        Use my location
                    </button>
                    <button type="button" class="knx-addr-form__map-btn" id="knxAddrSearchMapBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        Find on map
                    </button>
                </div>
                <div id="knxAddrMap" class="knx-addr-form__map"></div>
                <p class="knx-addr-form__hint" id="knxAddrMapHint">Click the map or drag the pin to set exact location</p>
            </div>

            <input type="hidden" id="knxAddrLat" value="">
            <input type="hidden" id="knxAddrLng" value="">

            <!-- Actions -->
            <div class="knx-addr-modal__actions">
                <button type="button" class="knx-addr-modal__cancel" id="knxAddrCancelBtn">Cancel</button>
                <button type="submit" class="knx-addr-modal__save" id="knxAddrSaveBtn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Save Address
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Toast container -->
<div class="knx-addr-toast" id="knxAddrToast" aria-live="polite"></div>

<?php
// End of partial
endif; // KNX_ADDR_MODAL_RENDERED
?>
