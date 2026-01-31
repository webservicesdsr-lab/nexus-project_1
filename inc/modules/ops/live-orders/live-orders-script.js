/* Live Orders — Scaffold JS
 * - Polls /knx/v1/ops/live-orders with selected city_ids
 * - Simple UI: selector, state, list
 */
(function(){
    if (!document) return;

    document.addEventListener('DOMContentLoaded', function(){
        const app = document.getElementById('knxLiveOrdersApp');
        if (!app) return;

        const apiUrl = app.dataset.apiUrl;
        const citiesUrl = app.dataset.citiesUrl;
        const role = app.dataset.sessionRole || '';
        const managedCities = JSON.parse(app.dataset.managedCities || '[]');

        const stateEl = document.getElementById('knxLiveOrdersState');
        const listEl = document.getElementById('knxLiveOrdersList');
        const selectorContainer = document.getElementById('knxCitySelectorContainer');

        let selectedCities = [];
        let pollTimer = null;
        const POLL_INTERVAL = 12000; // 12s

        function setState(msg) {
            stateEl.textContent = msg;
        }

        function renderCard(o) {
            const div = document.createElement('div');
            div.className = 'knx-live-order-card';

            div.innerHTML = `
                <div class="knx-live-order-main">
                    <h3 class="knx-live-order-title">${escapeHtml(o.restaurant_name)}</h3>
                    <div class="knx-live-order-meta">${escapeHtml(o.hub_name)} &middot; ${escapeHtml(o.customer_name)}</div>
                </div>
                <div class="knx-live-order-stats">
                    <div class="knx-live-order-created">${escapeHtml(o.created_human)}</div>
                    <div class="knx-live-order-amount">$${numberFormat(o.total_amount)}</div>
                    <div class="knx-live-order-tip">Tip: $${numberFormat(o.tip_amount)}</div>
                    <div class="knx-live-order-status">${escapeHtml(o.status)}</div>
                    <div class="knx-live-order-assigned">${o.assigned_driver ? 'Assigned' : 'Unassigned'}</div>
                    <div class="knx-live-order-actions">
                        <button class="knx-btn knx-view-location">View Location</button>
                        <button class="knx-btn knx-view-order">View Order</button>
                    </div>
                </div>
            `;

            return div;
        }

        function numberFormat(n){
            return (Number(n) || 0).toFixed(2);
        }

        function escapeHtml(s){
            if (s === null || s === undefined) return '';
            return String(s).replace(/[&<>"']/g, function(c){
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
        }

        async function fetchLive() {
            if (!selectedCities || selectedCities.length === 0) {
                setState('Select a city to view live orders');
                listEl.innerHTML = '';
                stopPolling();
                return;
            }

            setState('Loading...');

            try {
                const params = new URLSearchParams();
                selectedCities.forEach(c => params.append('city_ids[]', c));
                const url = apiUrl + '?' + params.toString();
                const res = await fetch(url, { credentials: 'same-origin' });
                const j = await res.json();
                if (!j || !j.success) {
                    setState('Error loading live orders');
                    return;
                }

                const data = j.data || [];
                if (data.length === 0) {
                    setState('No live orders');
                    listEl.innerHTML = '';
                    return;
                }

                setState('');
                listEl.innerHTML = '';
                data.forEach(o => listEl.appendChild(renderCard(o)));
            } catch (err) {
                console.error('Live orders fetch error', err);
                setState('Network error');
            }
        }

        function startPolling(){
            stopPolling();
            pollTimer = setInterval(fetchLive, POLL_INTERVAL);
            fetchLive();
        }

        function stopPolling(){
            if (pollTimer) clearInterval(pollTimer);
            pollTimer = null;
        }

        // Build selector UI
        async function initSelector(){
            // Manager: if only one managed city, hide selector and use that city
            if (role === 'manager') {
                if (managedCities.length === 1) {
                    selectedCities = [managedCities[0]];
                    startPolling();
                    return;
                }
                // multiple: render limited selector
                const sel = document.createElement('select');
                sel.multiple = true;
                sel.className = 'knx-live-orders-select';
                managedCities.forEach(c => {
                    const opt = document.createElement('option'); opt.value = c; opt.text = 'City ' + c; sel.appendChild(opt);
                });
                sel.addEventListener('change', () => {
                    selectedCities = Array.from(sel.selectedOptions).map(o=>o.value);
                    if (selectedCities.length) startPolling(); else stopPolling();
                });
                selectorContainer.appendChild(sel);
                return;
            }

            // Super admin: fetch cities list and render multi-select + "All cities" checkbox
            try {
                const res = await fetch(citiesUrl, { credentials: 'same-origin' });
                const j = await res.json();
                const cities = (j && j.data && j.data.cities) ? j.data.cities : [];

                const allCheckbox = document.createElement('label');
                allCheckbox.innerHTML = '<input type="checkbox" id="knxLiveOrdersAll"> All Cities';
                selectorContainer.appendChild(allCheckbox);
                const sel = document.createElement('select'); sel.multiple = true; sel.className='knx-live-orders-select';
                cities.forEach(c => {
                    const opt = document.createElement('option'); opt.value = c.id; opt.text = c.name; sel.appendChild(opt);
                });
                selectorContainer.appendChild(sel);

                document.getElementById('knxLiveOrdersAll').addEventListener('change', function(e){
                    if (this.checked) {
                        // select all
                        selectedCities = cities.map(c=>c.id);
                        Array.from(sel.options).forEach(o=>o.selected=true);
                        startPolling();
                    } else {
                        selectedCities = [];
                        Array.from(sel.options).forEach(o=>o.selected=false);
                        stopPolling();
                    }
                });

                sel.addEventListener('change', function(){
                    selectedCities = Array.from(sel.selectedOptions).map(o=>o.value);
                    if (selectedCities.length) startPolling(); else stopPolling();
                });

            } catch (err){
                console.error('cities fetch failed', err);
                // fallback: empty
                selectorContainer.textContent = '';
            }
        }

        // Initialize
        initSelector();

    });

})();
