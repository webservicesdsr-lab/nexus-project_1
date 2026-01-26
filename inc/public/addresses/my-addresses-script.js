/**
 * ==========================================================
 * MY ADDRESSES — Client Script (v2.0 REST-driven)
 * ==========================================================
 */

(function() {
    'use strict';
    
    const wrapper = document.querySelector('.knx-addresses-wrapper');
    if (!wrapper) return;
    
    // Config from data attributes
    const config = {
        customerId: wrapper.dataset.customerId,
        apiList: wrapper.dataset.apiList,
        apiAdd: wrapper.dataset.apiAdd,
        apiUpdate: wrapper.dataset.apiUpdate,
        apiDelete: wrapper.dataset.apiDelete,
        apiDefault: wrapper.dataset.apiDefault,
        apiSelect: wrapper.dataset.apiSelect,
        nonce: wrapper.dataset.nonce
    };
    
    // DOM elements
    const grid = document.getElementById('knxAddressesGrid');
    const emptyState = document.getElementById('knxAddressesEmpty');
    const addBtn = document.getElementById('knxAddressesAddBtn');
    const modal = document.getElementById('knxAddressModal');
    const modalBackdrop = document.getElementById('knxAddressModalBackdrop');
    const modalClose = document.getElementById('knxAddressModalClose');
    const modalTitle = document.getElementById('knxAddressModalTitle');
    const form = document.getElementById('knxAddressForm');
    const cancelBtn = document.getElementById('knxAddressCancelBtn');
    
    // Form fields
    const fields = {
        id: document.getElementById('knxAddressId'),
        label: document.getElementById('knxAddressLabel'),
        line1: document.getElementById('knxAddressLine1'),
        line2: document.getElementById('knxAddressLine2'),
        city: document.getElementById('knxAddressCity'),
        state: document.getElementById('knxAddressState'),
        zip: document.getElementById('knxAddressZip'),
        country: document.getElementById('knxAddressCountry'),
        lat: document.getElementById('knxAddressLat'),
        lng: document.getElementById('knxAddressLng')
    };
    
    let map = null;
    let marker = null;
    let addresses = [];
    let editingId = null;
    
    // ============================================================
    // INIT
    // ============================================================
    
    function init() {
        attachEvents();
        loadAddresses();
    }
    
    function attachEvents() {
        addBtn?.addEventListener('click', () => openModal());
        modalClose?.addEventListener('click', closeModal);
        modalBackdrop?.addEventListener('click', closeModal);
        cancelBtn?.addEventListener('click', closeModal);
        form?.addEventListener('submit', handleSubmit);
        
        document.getElementById('knxBtnUseLocation')?.addEventListener('click', useMyLocation);
        document.getElementById('knxBtnSearchAddress')?.addEventListener('click', searchAddress);
    }
    
    // ============================================================
    // REST API CALLS
    // ============================================================
    
    async function loadAddresses() {
        try {
            const res = await fetch(config.apiList, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    knx_nonce: config.nonce
                })
            });
            
            const data = await res.json();
            
            if (!data.success) {
                throw new Error(data.message || 'Failed to load addresses');
            }
            
            addresses = data.data?.addresses || [];
            renderAddresses();
            
        } catch (err) {
            console.error('[Addresses] Load error:', err);
            grid.innerHTML = `<div class="knx-error">Failed to load addresses. Please refresh.</div>`;
        }
    }
    
    async function saveAddress(data) {
        const url = editingId ? config.apiUpdate : config.apiAdd;
        const payload = editingId ? { ...data, address_id: editingId, knx_nonce: config.nonce } : { ...data, knx_nonce: config.nonce };
        
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            
            const result = await res.json();
            
            if (!result.success) {
                throw new Error(result.message || 'Failed to save address');
            }
            
            showToast(editingId ? 'Address updated' : 'Address added', 'success');
            closeModal();
            loadAddresses();
            
        } catch (err) {
            console.error('[Addresses] Save error:', err);
            showToast(err.message, 'error');
        }
    }
    
    async function deleteAddress(id) {
        if (!confirm('Delete this address?')) return;
        
        try {
            const res = await fetch(config.apiDelete, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ address_id: id, knx_nonce: config.nonce })
            });
            
            const result = await res.json();
            
            if (!result.success) {
                throw new Error(result.message || 'Failed to delete address');
            }
            
            showToast('Address deleted', 'success');
            loadAddresses();
            
        } catch (err) {
            console.error('[Addresses] Delete error:', err);
            showToast(err.message, 'error');
        }
    }
    
    async function setDefault(id) {
        try {
            const res = await fetch(config.apiDefault, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ address_id: id, knx_nonce: config.nonce })
            });
            
            const result = await res.json();
            
            if (!result.success) {
                throw new Error(result.message || 'Failed to set default');
            }
            
            showToast('Default address updated', 'success');
            loadAddresses();
            
        } catch (err) {
            console.error('[Addresses] Set default error:', err);
            showToast(err.message, 'error');
        }
    }
    
    async function selectAddress(id) {
        try {
            const res = await fetch(config.apiSelect, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ address_id: id, knx_nonce: config.nonce })
            });
            
            const result = await res.json();
            
            if (!result.success) {
                throw new Error(result.message || 'Failed to select address');
            }
            
            showToast('Address selected', 'success');

            // Reload current page to reflect SSOT change (do not auto-redirect)
            setTimeout(() => {
                window.location.reload();
            }, 400);
            
        } catch (err) {
            console.error('[Addresses] Select error:', err);
            showToast(err.message, 'error');
        }
    }
    
    // ============================================================
    // RENDER
    // ============================================================
    
    function renderAddresses() {
        if (!addresses || addresses.length === 0) {
            grid.style.display = 'none';
            emptyState.style.display = 'flex';
            return;
        }
        
        grid.style.display = 'grid';
        emptyState.style.display = 'none';
        
        grid.innerHTML = addresses.map(addr => `
            <div class="knx-address-card ${addr.is_default ? 'is-default' : ''}">
                <div class="knx-address-header">
                    <h4>${escapeHtml(addr.label || 'Address')}</h4>
                    ${addr.is_default ? '<span class="knx-badge">Default</span>' : ''}
                </div>
                <div class="knx-address-body">
                    <p><strong>${escapeHtml(addr.line1)}</strong></p>
                    ${addr.line2 ? `<p>${escapeHtml(addr.line2)}</p>` : ''}
                    <p>${escapeHtml(addr.city)}${addr.state ? ', ' + escapeHtml(addr.state) : ''} ${escapeHtml(addr.postal_code || '')}</p>
                    ${addr.country ? `<p>${escapeHtml(addr.country)}</p>` : ''}
                </div>
                <div class="knx-address-actions">
                    <button type="button" class="knx-btn knx-btn-sm" onclick="window.knxEditAddress(${addr.id})">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    ${!addr.is_default ? `
                        <button type="button" class="knx-btn knx-btn-sm" onclick="window.knxSetDefault(${addr.id})" title="Set as default">
                            <i class="fas fa-star"></i>
                        </button>
                    ` : ''}
                    <button type="button" class="knx-btn knx-btn-sm knx-btn-danger" onclick="window.knxDeleteAddress(${addr.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }
    
    // ============================================================
    // MODAL
    // ============================================================
    
    function openModal(id = null) {
        editingId = id;
        
        if (id) {
            modalTitle.textContent = 'Edit Address';
            const addr = addresses.find(a => a.id === id);
            if (addr) populateForm(addr);
        } else {
            modalTitle.textContent = 'Add Address';
            form.reset();
            fields.country.value = 'USA';
            fields.lat.value = '';
            fields.lng.value = '';
        }
        
        modal.style.display = 'flex';
        initMap();
    }
    
    function closeModal() {
        modal.style.display = 'none';
        form.reset();
        editingId = null;
        
        if (map) {
            map.remove();
            map = null;
            marker = null;
        }
    }
    
    function populateForm(addr) {
        fields.label.value = addr.label || '';
        fields.line1.value = addr.line1 || '';
        fields.line2.value = addr.line2 || '';
        fields.city.value = addr.city || '';
        fields.state.value = addr.state || '';
        fields.zip.value = addr.postal_code || '';
        fields.country.value = addr.country || 'USA';
        fields.lat.value = addr.latitude || '';
        fields.lng.value = addr.longitude || '';
    }
    
    function handleSubmit(e) {
        e.preventDefault();
        
        const lat = parseFloat(fields.lat.value);
        const lng = parseFloat(fields.lng.value);
        
        if (!lat || !lng) {
            showToast('Please set location on map', 'error');
            return;
        }
        
        const data = {
            label: fields.label.value.trim(),
            line1: fields.line1.value.trim(),
            line2: fields.line2.value.trim(),
            city: fields.city.value.trim(),
            state: fields.state.value.trim(),
            postal_code: fields.zip.value.trim(),
            country: fields.country.value.trim(),
            latitude: lat,
            longitude: lng
        };
        
        saveAddress(data);
    }
    
    // ============================================================
    // MAP (Leaflet)
    // ============================================================
    
    function initMap() {
        setTimeout(() => {
            const mapEl = document.getElementById('knxAddressMap');
            if (!mapEl || map) return;
            
            const lat = parseFloat(fields.lat.value) || 41.8781;
            const lng = parseFloat(fields.lng.value) || -87.6298;
            
            map = L.map('knxAddressMap').setView([lat, lng], 13);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap'
            }).addTo(map);
            
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            
            marker.on('dragend', () => {
                const pos = marker.getLatLng();
                fields.lat.value = pos.lat.toFixed(6);
                fields.lng.value = pos.lng.toFixed(6);
            });
            
            map.on('click', (e) => {
                marker.setLatLng(e.latlng);
                fields.lat.value = e.latlng.lat.toFixed(6);
                fields.lng.value = e.latlng.lng.toFixed(6);
            });
        }, 100);
    }
    
    function useMyLocation() {
        if (!navigator.geolocation) {
            showToast('Geolocation not supported', 'error');
            return;
        }
        
        navigator.geolocation.getCurrentPosition(
            pos => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                fields.lat.value = lat.toFixed(6);
                fields.lng.value = lng.toFixed(6);
                
                if (map && marker) {
                    map.setView([lat, lng], 15);
                    marker.setLatLng([lat, lng]);
                }
                
                showToast('Location detected', 'success');
            },
            err => {
                console.error('[Geolocation] Error:', err);
                showToast('Failed to get location', 'error');
            }
        );
    }
    
    function searchAddress() {
        const query = `${fields.line1.value} ${fields.city.value} ${fields.state.value}`.trim();
        
        if (!query) {
            showToast('Enter address first', 'error');
            return;
        }
        
        // Nominatim geocoding
        fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(data => {
                if (data && data.length > 0) {
                    const lat = parseFloat(data[0].lat);
                    const lng = parseFloat(data[0].lon);
                    
                    fields.lat.value = lat.toFixed(6);
                    fields.lng.value = lng.toFixed(6);
                    
                    if (map && marker) {
                        map.setView([lat, lng], 15);
                        marker.setLatLng([lat, lng]);
                    }
                    
                    showToast('Address found', 'success');
                } else {
                    showToast('Address not found', 'error');
                }
            })
            .catch(err => {
                console.error('[Geocoding] Error:', err);
                showToast('Search failed', 'error');
            });
    }
    
    // ============================================================
    // HELPERS
    // ============================================================
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function showToast(message, type = 'info') {
        if (typeof window.knxToast === 'function') {
            window.knxToast(message, type);
        } else {
            alert(message);
        }
    }
    
    // ============================================================
    // GLOBAL EXPORTS (for onclick handlers)
    // ============================================================
    
    window.knxEditAddress = openModal;
    window.knxDeleteAddress = deleteAddress;
    window.knxSetDefault = setDefault;
    window.knxSelectAddress = selectAddress;
    
    // Start
    init();
    
})();
