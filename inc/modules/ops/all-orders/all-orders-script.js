/**
 * ════════════════════════════════════════════════════════════════
 * KNX OPS — All Orders History Script v1.0
 * ════════════════════════════════════════════════════════════════
 *
 * Behaviors:
 * - City picker (same UX as live-orders): checkboxes persisted in localStorage
 * - super_admin: picks any cities; manager: restricted to assigned cities
 * - Fetches paginated order history from /knx/v1/ops/all-orders
 * - Status filter + search (debounced)
 * - Nexus-style pagination (prev / numbered pages / ellipsis / next)
 * - Click row → /view-order/?order_id=X
 */

(function () {
    'use strict';

    // ── Status label map ──────────────────────────────────────────
    const STATUS_LABELS = {
        confirmed:          'Confirmed',
        accepted_by_driver: 'Accepted',
        accepted_by_hub:    'At Hub',
        preparing:          'Preparing',
        prepared:           'Prepared',
        picked_up:          'Picked Up',
        completed:          'Delivered',
        cancelled:          'Cancelled',
    };

    const LS_CITY_KEY = 'knx_ao_selected_cities';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const app = document.getElementById('knxAllOrdersApp');
        if (!app) return;

        const apiUrl      = app.dataset.apiUrl      || '';
        const citiesUrl   = app.dataset.citiesUrl   || '';
        const viewUrl     = app.dataset.viewOrderUrl || '/view-order';
        const restNonce   = app.dataset.restNonce   || '';
        const role        = app.dataset.role         || '';

        const managedCities = (() => {
            try { return JSON.parse(app.dataset.managedCities || '[]'); } catch (e) { return []; }
        })();

        // ── DOM refs ───────────────────────────────────────────────
        const citiesBtn   = document.getElementById('knxAOCitiesBtn');
        const citiesPill  = document.getElementById('knxAOCitiesPill');
        const statusSel   = document.getElementById('knxAOStatusFilter');
        const searchInput = document.getElementById('knxAOSearch');
        const stateEl     = document.getElementById('knxAOState');
        const tableWrap   = document.getElementById('knxAOTableWrap');
        const tableBody   = document.getElementById('knxAOTableBody');
        const pagination  = document.getElementById('knxAOPagination');
        const prevBtn     = document.getElementById('knxAOPrev');
        const nextBtn     = document.getElementById('knxAONext');
        const pageNumbers = document.getElementById('knxAOPageNumbers');

        const modal       = document.getElementById('knxAOModal');
        const cityListEl  = document.getElementById('knxAOCityList');
        const applyBtn    = document.getElementById('knxAOApplyCities');
        const selectAllBtn= document.getElementById('knxAOSelectAll');
        const clearAllBtn = document.getElementById('knxAOClearAll');

        // ── State ──────────────────────────────────────────────────
        let allCities      = [];    // [{id, name}] fetched from API
        let selectedCities = [];    // committed city IDs (from localStorage)
        let pendingCities  = [];    // in-modal draft
        let currentPage    = 1;
        let totalPages     = 1;
        let perPage        = 25;
        let loading        = false;
        let searchTimer    = null;

        // ── Boot ────────────────────────────────────────────────────
        loadCitiesFromStorage();
        fetchCities().then(() => {
            // super_admin: auto-select all cities on first load (no saved selection)
            if (role === 'super_admin' && selectedCities.length === 0 && allCities.length > 0) {
                selectedCities = allCities.map(c => c.id);
                saveCitiesToStorage();
            }
            if (selectedCities.length > 0) {
                updateCitiesPill();
                loadOrders();
            }
        });

        // ── Events ─────────────────────────────────────────────────
        citiesBtn.addEventListener('click', openModal);

        applyBtn.addEventListener('click', () => {
            selectedCities = [...pendingCities];
            saveCitiesToStorage();
            updateCitiesPill();
            closeModal();
            currentPage = 1;
            loadOrders();
        });

        selectAllBtn.addEventListener('click', () => {
            const checkboxes = cityListEl.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => { cb.checked = true; });
            pendingCities = allCities.map(c => c.id);
        });

        clearAllBtn.addEventListener('click', () => {
            const checkboxes = cityListEl.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => { cb.checked = false; });
            pendingCities = [];
        });

        // Close modal
        modal.addEventListener('click', e => {
            if (e.target.closest('[data-close]')) closeModal();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeModal();
        });

        // Status filter
        statusSel.addEventListener('change', () => { currentPage = 1; loadOrders(); });

        // Search (debounced 400ms)
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { currentPage = 1; loadOrders(); }, 400);
        });

        // Pagination
        prevBtn.addEventListener('click', () => {
            if (currentPage > 1) { currentPage--; loadOrders(); }
        });
        nextBtn.addEventListener('click', () => {
            if (currentPage < totalPages) { currentPage++; loadOrders(); }
        });

        // ── Cities ─────────────────────────────────────────────────
        function loadCitiesFromStorage() {
            try {
                const raw = localStorage.getItem(LS_CITY_KEY);
                if (raw) selectedCities = JSON.parse(raw).map(Number).filter(Boolean);
            } catch (e) { selectedCities = []; }
        }

        function saveCitiesToStorage() {
            try { localStorage.setItem(LS_CITY_KEY, JSON.stringify(selectedCities)); } catch (e) {}
        }

        async function fetchCities() {
            try {
                const res = await fetch(citiesUrl, {
                    headers: { 'X-WP-Nonce': restNonce },
                    credentials: 'same-origin',
                });
                const json = await res.json();

                // Normalize response shape: {data:{cities:[]}} or [{id,name}] etc.
                let list = [];
                if (Array.isArray(json)) list = json;
                else if (Array.isArray(json.data)) list = json.data;
                else if (Array.isArray(json.data?.cities)) list = json.data.cities;
                else if (Array.isArray(json.cities)) list = json.cities;

                // For managers, restrict to managed cities
                if (role === 'manager' && managedCities.length > 0) {
                    list = list.filter(c => managedCities.includes(Number(c.id)));
                }

                allCities = list.map(c => ({ id: Number(c.id), name: String(c.name || '') }));

                // Filter persisted selected to only valid ones
                const validIds = allCities.map(c => c.id);
                selectedCities = selectedCities.filter(id => validIds.includes(id));

            } catch (e) {
                allCities = [];
                setState('Could not load cities. Please refresh the page.');
            }
        }

        function renderCityList() {
            if (!allCities.length) {
                cityListEl.innerHTML = '<div class="knx-ao-skel">No cities available.</div>';
                return;
            }

            cityListEl.innerHTML = '';
            pendingCities = [...selectedCities];

            allCities.forEach(city => {
                const item = document.createElement('label');
                item.className = 'knx-ao-modal__city-item';

                const cb = document.createElement('input');
                cb.type    = 'checkbox';
                cb.value   = city.id;
                cb.checked = selectedCities.includes(city.id);

                cb.addEventListener('change', () => {
                    if (cb.checked) {
                        if (!pendingCities.includes(city.id)) pendingCities.push(city.id);
                    } else {
                        pendingCities = pendingCities.filter(id => id !== city.id);
                    }
                });

                const nameEl = document.createElement('span');
                nameEl.className = 'knx-ao-modal__city-name';
                nameEl.textContent = city.name;

                item.appendChild(cb);
                item.appendChild(nameEl);
                cityListEl.appendChild(item);
            });
        }

        function updateCitiesPill() {
            if (!selectedCities.length) {
                citiesPill.textContent = 'No cities selected';
                return;
            }
            if (selectedCities.length === allCities.length && allCities.length > 0) {
                citiesPill.textContent = 'All cities';
                return;
            }
            const names = selectedCities
                .map(id => { const c = allCities.find(x => x.id === id); return c ? c.name : String(id); })
                .join(', ');
            citiesPill.textContent = names;
        }

        function openModal() {
            renderCityList();
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeModal() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        }

        // ── Fetch orders ───────────────────────────────────────────
        async function loadOrders() {
            if (!selectedCities.length) {
                setState('Select a city to view orders.');
                hide(tableWrap);
                hide(pagination);
                return;
            }

            if (loading) return;
            loading = true;

            setState('');
            hide(tableWrap);
            hide(pagination);
            renderSkeletons(8);
            show(tableWrap);

            const status = statusSel.value;
            const search = searchInput.value.trim();

            const params = new URLSearchParams();
            // super_admin with all cities → use city_id=all shortcut
            const isAllCities = role === 'super_admin' &&
                allCities.length > 0 &&
                selectedCities.length === allCities.length;
            if (isAllCities) {
                params.set('city_id', 'all');
            } else {
                selectedCities.forEach(id => params.append('city_ids[]', id));
            }
            params.set('page', currentPage);
            params.set('per_page', perPage);
            if (status) params.set('status', status);
            if (search)  params.set('search', search);

            try {
                const res = await fetch(apiUrl + '?' + params.toString(), {
                    headers: { 'X-WP-Nonce': restNonce },
                    credentials: 'same-origin',
                });

                const json = await res.json();

                if (!res.ok || !json.success) {
                    const msg = json.message || json.error || 'Error loading orders.';
                    tableBody.innerHTML = '';
                    renderEmptyRow(msg);
                    loading = false;
                    return;
                }

                const orders = json.data?.orders || json.orders || [];
                const pag    = json.data?.pagination || json.pagination || {};
                totalPages   = pag.total_pages || 1;
                currentPage  = pag.page || currentPage;

                renderRows(orders, viewUrl);
                renderPagination(currentPage, totalPages);

                if (orders.length === 0) {
                    renderEmptyRow('No orders found for the selected filters.');
                    hide(pagination);
                } else {
                    show(pagination);
                }

            } catch (e) {
                tableBody.innerHTML = '';
                renderEmptyRow('Network error. Please try again.');
            }

            loading = false;
        }

        // ── Render helpers ─────────────────────────────────────────
        function renderRows(orders, viewUrl) {
            tableBody.innerHTML = '';

            orders.forEach(order => {
                const tr = document.createElement('tr');
                tr.className = 'knx-ao__row';
                tr.setAttribute('role', 'button');
                tr.setAttribute('tabindex', '0');
                tr.setAttribute('aria-label', 'View order ' + order.display_id);

                tr.addEventListener('click', () => {
                    window.location.href = viewUrl + '?order_id=' + encodeURIComponent(order.order_id);
                });
                tr.addEventListener('keydown', e => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        window.location.href = viewUrl + '?order_id=' + encodeURIComponent(order.order_id);
                    }
                });

                // ID cell
                const tdId = document.createElement('td');
                tdId.className = 'knx-ao__td';
                const idPill = document.createElement('span');
                idPill.className = 'knx-ao__id-pill';
                idPill.textContent = order.display_id || ('#' + order.order_id);
                tdId.appendChild(idPill);

                // Restaurant cell
                const tdRest = document.createElement('td');
                tdRest.className = 'knx-ao__td';
                const restWrap = document.createElement('div');
                restWrap.className = 'knx-ao__restaurant';

                if (order.hub_logo) {
                    const img = document.createElement('img');
                    img.src = order.hub_logo;
                    img.alt = order.hub_name;
                    img.className = 'knx-ao__hub-logo';
                    img.loading = 'lazy';
                    img.onerror = function () {
                        const ph = document.createElement('div');
                        ph.className = 'knx-ao__hub-logo-placeholder';
                        ph.textContent = '🍽';
                        this.parentNode.replaceChild(ph, this);
                    };
                    restWrap.appendChild(img);
                } else {
                    const ph = document.createElement('div');
                    ph.className = 'knx-ao__hub-logo-placeholder';
                    ph.textContent = '🍽';
                    restWrap.appendChild(ph);
                }

                const nameEl = document.createElement('span');
                nameEl.className = 'knx-ao__hub-name';
                nameEl.textContent = order.hub_name || '—';
                restWrap.appendChild(nameEl);
                tdRest.appendChild(restWrap);

                // Status cell
                const tdStatus = document.createElement('td');
                tdStatus.className = 'knx-ao__td knx-ao__td--status';

                const badge = document.createElement('span');
                const statusKey = (order.status || '').toLowerCase();
                const label = STATUS_LABELS[statusKey] || order.status || '—';
                badge.className = 'knx-ao__status-badge knx-ao__status-badge--' + (statusKey || 'default');
                badge.textContent = label;
                tdStatus.appendChild(badge);

                tr.appendChild(tdId);
                tr.appendChild(tdRest);
                tr.appendChild(tdStatus);
                tableBody.appendChild(tr);
            });
        }

        function renderSkeletons(n) {
            tableBody.innerHTML = '';
            for (let i = 0; i < n; i++) {
                const tr = document.createElement('tr');
                tr.className = 'knx-ao__skel-row';

                [60, 200, 90].forEach(w => {
                    const td = document.createElement('td');
                    td.className = 'knx-ao__td';
                    const bar = document.createElement('div');
                    bar.className = 'knx-ao__skel-bar';
                    bar.style.width = w + 'px';
                    td.appendChild(bar);
                    tr.appendChild(td);
                });

                tableBody.appendChild(tr);
            }
        }

        function renderEmptyRow(msg) {
            const tr = document.createElement('tr');
            tr.className = 'knx-ao__empty-row';
            const td = document.createElement('td');
            td.setAttribute('colspan', '3');
            td.textContent = msg;
            tr.appendChild(td);
            tableBody.appendChild(tr);
        }

        // ── Pagination ────────────────────────────────────────────
        function renderPagination(page, total) {
            pageNumbers.innerHTML = '';

            prevBtn.disabled = page <= 1;
            nextBtn.disabled = page >= total;

            const pages = paginationRange(page, total);

            pages.forEach(p => {
                if (p === '…') {
                    const el = document.createElement('span');
                    el.className = 'knx-ao__pg-ellipsis';
                    el.textContent = '…';
                    pageNumbers.appendChild(el);
                } else {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'knx-ao__pg-num' + (p === page ? ' is-active' : '');
                    btn.textContent = p;
                    btn.setAttribute('aria-label', 'Page ' + p);
                    if (p === page) btn.setAttribute('aria-current', 'page');

                    btn.addEventListener('click', () => {
                        if (p !== currentPage) {
                            currentPage = p;
                            loadOrders();
                        }
                    });
                    pageNumbers.appendChild(btn);
                }
            });
        }

        /**
         * Returns array of page numbers and '…' ellipsis markers.
         * Always shows: first, last, current ±1, and ellipsis in between.
         */
        function paginationRange(current, total) {
            if (total <= 7) {
                return Array.from({ length: total }, (_, i) => i + 1);
            }

            const delta = 1;
            const range = [];
            const rangeWithDots = [];
            let l;

            range.push(1);
            for (let i = current - delta; i <= current + delta; i++) {
                if (i > 1 && i < total) range.push(i);
            }
            range.push(total);

            range.forEach(i => {
                if (l) {
                    if (i - l === 2) rangeWithDots.push(l + 1);
                    else if (i - l > 2) rangeWithDots.push('…');
                }
                rangeWithDots.push(i);
                l = i;
            });

            return rangeWithDots;
        }

        // ── Utility ───────────────────────────────────────────────
        function setState(msg) {
            stateEl.textContent = msg;
            stateEl.style.display = msg ? '' : 'none';
        }

        function show(el) { el && (el.hidden = false); }
        function hide(el) { el && (el.hidden = true); }
    }
})();
