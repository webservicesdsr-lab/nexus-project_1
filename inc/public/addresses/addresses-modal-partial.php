<?php
if (!defined('ABSPATH')) exit;

// Prevent rendering this shared modal partial more than once in the same request/response
if (!defined('KNX_ADDR_MODAL_RENDERED')):
    define('KNX_ADDR_MODAL_RENDERED', true);
?>

<!-- ═══════════════════════════════════════════════════════════════
     SHARED ADDRESS MODAL v4.0 — Inspired by Food Truck modal
     ─────────────────────────────────────────────────────────────
     Layout:  Search → Map → Delivery Details (below the fold)
     Design:  Autocomplete + map = source of truth for address/coords.
              Structured fields (line1, city, state, etc.) are hidden,
              auto-populated by reverse geocode. User only fills:
              - Label (chips)
              - Apt / Suite / Unit #
              - Delivery instructions (references, house color, etc.)
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

            <!-- ─── 1. AUTOCOMPLETE SEARCH ─── -->
            <div class="knx-addr-form__group">
                <label for="knxAddrSearch">Search address</label>
                <div class="knx-addr-form__search-wrap">
                    <svg class="knx-addr-form__search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <input type="text" id="knxAddrSearch" placeholder="Type an address, e.g. 123 Main St…" autocomplete="off">
                </div>
                <ul class="knx-addr-form__suggestions" id="knxAddrSuggestions"></ul>
            </div>

            <!-- ─── 2. MAP + GEOLOCATION ─── -->
            <div class="knx-addr-form__group">
                <label>Pin Location <span class="knx-addr-form__req">*</span></label>
                <div class="knx-addr-form__map-bar">
                    <button type="button" class="knx-addr-form__map-btn" id="knxAddrGeoBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
                        Use my location
                    </button>
                </div>
                <div id="knxAddrMap" class="knx-addr-form__map"></div>
                <p class="knx-addr-form__hint" id="knxAddrMapHint">Click the map or drag the pin to set exact location</p>
            </div>

            <!-- ─── 3. DELIVERY DETAILS ─── -->
            <fieldset class="knx-addr-form__fieldset">
                <legend class="knx-addr-form__legend">Delivery Details</legend>

                <!-- Label (chips + input) -->
                <div class="knx-addr-form__group">
                    <label for="knxAddrLabel">Label <span class="knx-addr-form__req">*</span></label>
                    <div class="knx-addr-form__chips" id="knxAddrLabelChips">
                        <button type="button" class="knx-addr-form__chip" data-label="Home">🏠 Home</button>
                        <button type="button" class="knx-addr-form__chip" data-label="Work">💼 Work</button>
                        <button type="button" class="knx-addr-form__chip" data-label="Other">📍 Other</button>
                    </div>
                    <input type="text" id="knxAddrLabel" placeholder="Home, Work, Mom's…" required maxlength="100">
                </div>

                <!-- Apt / Suite / Unit -->
                <div class="knx-addr-form__group">
                    <label for="knxAddrLine2">Apt, Suite, Unit # <span class="knx-addr-form__req">*</span></label>
                    <input type="text" id="knxAddrLine2" placeholder="e.g. Apt 4B, Suite 200, House #12" required maxlength="255">
                </div>

                <!-- Delivery Instructions / References -->
                <div class="knx-addr-form__group">
                    <label for="knxAddrInstructions">Delivery Instructions <span class="knx-addr-form__req">*</span></label>
                    <textarea id="knxAddrInstructions" placeholder="e.g. White house with green door, between 5th and 6th Ave, ring bell twice, gate code 1234…" required maxlength="500" rows="3"></textarea>
                </div>
            </fieldset>

            <!-- Hidden structured fields (auto-populated by geocode) -->
            <input type="hidden" id="knxAddrLine1" value="">
            <input type="hidden" id="knxAddrCity" value="">
            <input type="hidden" id="knxAddrState" value="">
            <input type="hidden" id="knxAddrZip" value="">
            <input type="hidden" id="knxAddrCountry" value="USA">
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
