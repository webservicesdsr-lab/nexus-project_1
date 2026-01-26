/**
 * Kingdom Nexus — Explore Hubs (Minimal Premium + Casino Loader)
 * FINAL BUILD — Feb 2025
 */

document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const root = document.querySelector('#olc-explore-hubs');
    if (!root) return;

    /* ============================================================
       STATE
    ============================================================ */
    const state = {
        categoryId: null,
        categoryName: null,
        query: '',
        vendors: [],
        spotlights: [],
    };

    /* ============================================================
       DOM REFS
    ============================================================ */
    const $ = {
        search: root.querySelector('#hub-search'),
        searchSticky: root.querySelector('.search-sticky'),
        spotBox: root.querySelector('#spotlights-container'),
        spotSection: root.querySelector('.spot-wrap'),
        surpSection: root.querySelector('.surp-wrap'),
        grid: root.querySelector('#vendors-grid'),
        surpOverlay: root.querySelector('#surprise-overlay'),
        surpWinner: root.querySelector('#surprise-winner'),
        surpRotatorText: root.querySelector('#surprise-rotator-text'),
        surpTrigger: root.querySelector('#surprise-trigger'),

        availModal: document.getElementById('knx-availability-modal'),
    };

    /* ============================================================
       INIT
    ============================================================ */
    function init() {
        bindEvents();
        loadVendors();
        renderSpotlights();
        window.openSurpriseModal = openSurpriseModal;
    }

    /* ============================================================
       LOAD VENDORS
    ============================================================ */
    async function loadVendors() {
        try {
            const res = await fetch('/wp-json/knx/v1/explore-hubs', { credentials: 'same-origin' });
            const data = await res.json();
            state.vendors = (data && data.hubs) || data || [];
            renderVendors();
        } catch (err) {
            console.error('Failed to load vendors:', err);
            $.grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:40px;color:#ef4444;">
                <i class="fas fa-exclamation-triangle" style="font-size:48px;opacity:.5;"></i>
                <h3 style="margin:10px 0 6px;">Unable to load vendors</h3>
                <p>Please try again later.</p>
            </div>`;
        }
    }

    /* ============================================================
       EVENTS
    ============================================================ */
    function bindEvents() {
        /* SEARCH */
        if ($.search) {
            let t;
            $.search.addEventListener('input', function (e) {
                clearTimeout(t);
                t = setTimeout(() => {
                    state.query = e.target.value.trim().toLowerCase();
                    renderVendors();
                }, 200);
            });
        }

        /* CATEGORY CHIPS */
        root.addEventListener('click', function (e) {
            const chip = e.target.closest('.knx-mood-chip');
            if (!chip) return;

            const id = chip.dataset.categoryId;
            const name = chip.dataset.categoryName;

            if (state.categoryId === id) {
                state.categoryId = null;
                state.categoryName = null;
                chip.classList.remove('active');
            } else {
                root.querySelectorAll('.knx-mood-chip').forEach(c => c.classList.remove('active'));
                state.categoryId = id;
                state.categoryName = name;
                chip.classList.add('active');
            }
            renderVendors();
        });

        /* SCROLL COMPACT SEARCH */
        if ($.searchSticky) {
            const onScroll = () => {
                if (window.scrollY > 12) $.searchSticky.classList.add('compact');
                else $.searchSticky.classList.remove('compact');
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();
        }

        /* SURPRISE TRIGGER */
        if ($.surpTrigger) {
            $.surpTrigger.addEventListener('click', openSurpriseModal);
            $.surpTrigger.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openSurpriseModal();
                }
            });
        }

        /* CLOSE SURPRISE OVERLAY */
        root.querySelectorAll('[data-surp-close]').forEach(el =>
            el.addEventListener('click', closeSurpriseModal)
        );

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeSurpriseModal();
                closeAvailabilityModal();
            }
        });

        /* AVAILABILITY MODAL CLOSE */
        if ($.availModal) {
            const backdrop = $.availModal.querySelector('.knx-avail-backdrop');
            const closeBtn = $.availModal.querySelector('.knx-avail-close');
            const xBtn = $.availModal.querySelector('.knx-avail-x');

            if (backdrop) backdrop.addEventListener('click', closeAvailabilityModal);
            if (xBtn) xBtn.addEventListener('click', closeAvailabilityModal);

            if (closeBtn) closeBtn.addEventListener('click', function () {
                closeAvailabilityModal();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    }

    /* ============================================================
       FILTERS
    ============================================================ */
    function matchesQuery(v) {
        const q = state.query;
        if (!q) return true;
        const txt = (
            (v.name || '') + ' ' + (v.category_name || '') + ' ' + (v.tagline || '') + ' ' + (v.address || '')
        ).toLowerCase();
        return txt.includes(q);
    }

    function matchesCategory(v) {
        if (!state.categoryId) return true;
        return String(v.category_id || v.category) === String(state.categoryId);
    }

    function updateSectionsVisibility() {
        const filtering = !!state.query || !!state.categoryId;
        if ($.spotSection) $.spotSection.style.display = filtering ? 'none' : '';
        if ($.surpSection) $.surpSection.style.display = filtering ? 'none' : '';
    }

    function canOrder(v) {
        return !(v && v.availability && v.availability.can_order === false);
    }

    /* ============================================================
       RENDER VENDORS
    ============================================================ */
    function renderVendors() {
        updateSectionsVisibility();

        const filtered = state.vendors.filter(v =>
            matchesQuery(v) && matchesCategory(v)
        );

        if (!filtered.length && (state.query || state.categoryId)) {
            $.grid.innerHTML = `
                <div style="grid-column:1/-1;text-align:center;padding:40px;color:#999;">
                    <i class="fas fa-search" style="font-size:48px;opacity:.35;"></i>
                    <h3>No vendors found</h3>
                    <p>Try adjusting your search.</p>
                </div>`;
            return;
        }

        $.grid.innerHTML = '';
        filtered.forEach(v => $.grid.appendChild(hubCard(v)));
    }

    /* ============================================================
       SPOTLIGHTS
    ============================================================ */
    async function renderSpotlights() {
        try {
            const res = await fetch('/wp-json/knx/v1/explore-hubs?featured=1', { credentials: 'same-origin' });
            const data = await res.json();
            state.spotlights = (data && data.hubs) || [];
        } catch {
            state.spotlights = [];
        }

        $.spotBox.innerHTML = '';

        if (!state.spotlights.length) {
            $.spotBox.innerHTML = `<p style="padding:20px;text-align:center;color:#999;">No featured hubs yet</p>`;
            return;
        }

        state.spotlights.forEach(h => {
            const el = hubCard(h, { spotlight: true });
            el.classList.add('spot-card');
            $.spotBox.appendChild(el);
        });
    }

    /* ============================================================
       HUB CARD
    ============================================================ */
    function hubCard(v, opts = {}) {
        const spotlight = !!opts.spotlight;
        const slug = v.slug || (v.name || '').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');

        const image =
            v.image || v.hero_img || v.logo_url ||
            'https://via.placeholder.com/700x450?text=' + encodeURIComponent(v.name || 'Restaurant');

        // TASK 04: Use canonical availability from backend (no custom logic)
        const availability = v.availability || {};
        const reason = availability.reason || '';
        const orderable = canOrder(v);

        let statusText = 'Closed';
        let statusClass = 'closed';

        // TASK 04: Only use availability.can_order + availability.reason (no v.is_open)
        if (orderable) {
            statusText = 'Open';
            statusClass = 'open';
        } else if (reason === 'HUB_CLOSING_SOON') {
            statusText = 'Closing Soon';
            statusClass = 'temp-closed';
        } else if (reason === 'HUB_TEMP_CLOSED' || reason === 'HUB_CLOSED_INDEFINITELY') {
            statusText = 'Temp Closed';
            statusClass = 'temp-closed';
        } else if (reason === 'HUB_OUTSIDE_HOURS') {
            statusText = 'Closed';
            statusClass = 'closed';
        } else {
            // Other reasons (city inactive, hub inactive, etc.)
            statusText = 'Unavailable';
            statusClass = 'closed';
        }

        const el = document.createElement('article');
        el.className = 'hub-card';
        el.innerHTML = `
            <div class="hub-img">
                <img src="${escapeAttr(image)}" alt="${escapeAttr(v.name || '')}">
                ${spotlight
                    ? `<div class="spot-heart"><i class="fas fa-heart"></i></div>`
                    : `<span class="hub-status-pill ${statusClass}">${escapeHtml(statusText)}</span>`
                }
            </div>
            <div class="hub-bottom">
                <div class="hub-main-name"><p class="hub-name">${escapeHtml(v.name || '')}</p></div>
                <div class="hub-main-hours">
                    <p class="hub-hours">${escapeHtml(v.hours_today || 'Hours unavailable')}</p>
                </div>
            </div>
        `;

        /* Dynamic name sizing (subtle) */
        const nameEl = el.querySelector('.hub-name');
        const nameTxt = (v.name || '').trim();
        if (nameEl && nameTxt.length >= 22) {
            nameEl.classList.add('is-long');
        }

        el.addEventListener('click', function () {
            if (!canOrder(v)) {
                showAvailabilityModal(v);
                return;
            }
            window.location.href = '/' + slug;
        });

        return el;
    }

    /* ============================================================
       RANDOMIZER (CASINO LOADER)
    ============================================================ */
    function openSurpriseModal() {
        if (!$.surpOverlay) return;

        const msgs = [
            'Rolling the dice...',
            'Picking something delicious...',
            'Let’s see what destiny tastes like...',
            'Good things take time...'
        ];

        if ($.surpRotatorText) {
            const msg = msgs[Math.floor(Math.random() * msgs.length)];
            $.surpRotatorText.textContent = msg;
        }

        $.surpOverlay.classList.remove('hidden');
        $.surpOverlay.setAttribute('aria-hidden', 'false');

        startCasinoLoading();
    }

    function closeSurpriseModal() {
        if (!$.surpOverlay) return;
        $.surpOverlay.classList.add('hidden');
        $.surpOverlay.setAttribute('aria-hidden', 'true');
    }

    function startCasinoLoading() {
        const winnerArea = $.surpWinner;
        if (!winnerArea) return;

        const box = winnerArea.querySelector('.aspect-16x9');
        const body = winnerArea.querySelector('.winner-body');

        box.innerHTML = `
            <div class="casino-loader">
                <span class="slot slot-1">🍔</span>
                <span class="slot slot-2">🍕</span>
                <span class="slot slot-3">🌮</span>
            </div>
        `;

        body.innerHTML = `
            <div style="margin-top:22px;font-size:18px;color:#555;">Finding a tasty winner...</div>
        `;

        const emojis = ['🍔','🍕','🌮','🥗','🍜','🍣','🌯','🍩','🍤','🥙'];
        const s1 = box.querySelector('.slot-1');
        const s2 = box.querySelector('.slot-2');
        const s3 = box.querySelector('.slot-3');

        let interval1, interval2, interval3;

        interval1 = setInterval(() => s1.textContent = emojis[Math.floor(Math.random()*emojis.length)], 90);
        interval2 = setInterval(() => s2.textContent = emojis[Math.floor(Math.random()*emojis.length)], 75);
        interval3 = setInterval(() => s3.textContent = emojis[Math.floor(Math.random()*emojis.length)], 60);

        setTimeout(() => {
            clearInterval(interval1);
            clearInterval(interval2);
            clearInterval(interval3);
            showRandomWinner();
        }, 1500);
    }

    function showRandomWinner() {
        let pool = state.vendors.filter(v => canOrder(v));
        if (!pool.length) pool = state.spotlights.filter(v => canOrder(v));
        if (!pool.length) return showNoWinner();

        const pick = pool[Math.floor(Math.random()*pool.length)];
        const img = pick.image || pick.hero_img || pick.logo_url;
        const slug = pick.slug;

        const box = $.surpWinner.querySelector('.aspect-16x9');
        const body = $.surpWinner.querySelector('.winner-body');

        box.innerHTML = `<img src="${escapeAttr(img)}" style="width:100%;height:100%;object-fit:cover;" alt="">`;

        body.innerHTML = `
            <span class="win-medal">🎉</span>
            <h3>${escapeHtml(pick.name || '')}</h3>
            <p>${escapeHtml(pick.tagline || 'Your lucky pick!')}</p>

            <div class="win-actions">
                <a class="btn btn-amber" href="/${escapeAttr(slug)}">
                    <i class="fas fa-utensils"></i> Open Menu
                </a>

                <button class="btn btn-amber" id="try-random-again">Try Again</button>
            </div>
        `;

        body.querySelector('#try-random-again').addEventListener('click', () => {
            closeSurpriseModal();
            setTimeout(openSurpriseModal, 150);
        });
    }

    function showNoWinner() {
        const box = $.surpWinner.querySelector('.aspect-16x9');
        const body = $.surpWinner.querySelector('.winner-body');

        box.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:48px;">😴</div>`;
        body.innerHTML = `
            <h3>No available hubs</h3>
            <p>Try again later!</p>
            <div class="win-actions">
                <button class="btn btn-amber" data-surp-close>Close</button>
            </div>
        `;

        body.querySelector('[data-surp-close]').addEventListener('click', closeSurpriseModal);
    }

    /* ============================================================
       AVAILABILITY MODAL (Explore Hubs)
    ============================================================ */
    let countdownTimer = null;

    function showAvailabilityModal(hub) {
        if (!$.availModal) return;

        const iconEl = document.getElementById('knxAvailIcon');
        const titleEl = document.getElementById('knxAvailTitle');
        const msgEl = document.getElementById('knxAvailMessage');
        const cdEl = document.getElementById('knxAvailCountdown');

        const availability = hub.availability || {};
        const reason = availability.reason || 'UNKNOWN';
        const reopenAt = availability.reopen_at || null;
        const closureReason = (hub.closure_reason || '').trim();

        stopCountdown();
        if (cdEl) {
            cdEl.style.display = 'none';
            cdEl.textContent = '';
        }

        let icon = '⏸️';
        let title = (hub.name || 'This restaurant') + ' is unavailable';
        let message = 'Please check back later or explore other local spots.';

        if (reason === 'HUB_TEMP_CLOSED') {
            icon = '⏰';
            title = (hub.name || 'This restaurant') + ' is temporarily closed';
            message = closureReason ? closureReason : 'We’ll be back soon.';
            if (reopenAt && cdEl) {
                cdEl.style.display = 'block';
                startCountdown(reopenAt, cdEl);
            }
        } else if (reason === 'HUB_CLOSED_INDEFINITELY') {
            icon = '🔒';
            title = (hub.name || 'This restaurant') + ' is temporarily closed';
            message = closureReason ? closureReason : 'Please check back later.';
        } else if (reason === 'HUB_OUTSIDE_HOURS') {
            icon = '🌙';
            title = (hub.name || 'This restaurant') + ' is closed right now';
            message = 'We’re currently outside operating hours. Please check back later.';
        } else if (reason === 'HUB_CLOSING_SOON') {
            icon = '🕗';
            title = (hub.name || 'This restaurant') + ' is closing soon';
            message = 'Orders are paused near closing time. Please try another restaurant for now.';
        } else if (reason === 'HUB_NO_HOURS_SET') {
            icon = 'ℹ️';
            title = (hub.name || 'This restaurant') + ' is unavailable today';
            message = 'Hours aren’t available right now. Please check back later.';
        } else if (reason === 'CITY_NOT_OPERATIONAL') {
            icon = '⛔';
            title = 'Ordering is paused in this city';
            message = 'Orders are temporarily paused in your area. Please check back later.';
        } else if (reason === 'CITY_INACTIVE') {
            icon = '📍';
            title = 'This area is currently unavailable';
            message = 'We’re not accepting orders in this location right now.';
        } else if (reason === 'HUB_INACTIVE') {
            icon = '🚫';
            title = (hub.name || 'This restaurant') + ' is unavailable';
            message = 'This restaurant is currently unavailable.';
        }

        if (iconEl) iconEl.textContent = icon;
        if (titleEl) titleEl.textContent = title;
        if (msgEl) msgEl.textContent = message;

        $.availModal.classList.remove('hidden');
        $.availModal.setAttribute('aria-hidden', 'false');
    }

    function closeAvailabilityModal() {
        stopCountdown();
        if (!$.availModal) return;
        $.availModal.classList.add('hidden');
        $.availModal.setAttribute('aria-hidden', 'true');
    }

    function startCountdown(reopenAtIso, el) {
        if (!el) return;

        const target = new Date(reopenAtIso).getTime();
        if (!isFinite(target)) return;

        const tick = () => {
            const now = Date.now();
            const d = target - now;

            if (d <= 0) {
                el.textContent = 'Reopening soon…';
                stopCountdown();
                return;
            }

            const totalSeconds = Math.floor(d / 1000);
            const h = Math.floor(totalSeconds / 3600);
            const m = Math.floor((totalSeconds % 3600) / 60);
            const s = totalSeconds % 60;

            el.textContent = `Reopens in ${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        };

        tick();
        countdownTimer = setInterval(tick, 1000);
    }

    function stopCountdown() {
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }

    /* ============================================================
       HELPERS (XSS-safe)
    ============================================================ */
    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/`/g, '&#096;');
    }

    /* ============================================================
       INIT
    ============================================================ */
    init();
});
