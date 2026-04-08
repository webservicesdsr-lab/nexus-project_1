/**
 * ==========================================================
 * KNX Hub Management — Orders JS Controller
 * ----------------------------------------------------------
 * Loads hub orders, renders cards, handles "Ready for Pickup"
 * signal (FAKE — does NOT change the real order status).
 * ==========================================================
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        var wrap = document.querySelector('.knx-hub-orders');
        if (!wrap) return;

        var hubId     = wrap.dataset.hubId;
        var hubName   = wrap.dataset.hubName || '';
        var nonce     = wrap.dataset.nonce;
        var wpNonce   = wrap.dataset.wpNonce;
        var apiOrders = wrap.dataset.apiOrders;
        var apiSignal = wrap.dataset.apiSignal;

        var listEl    = document.getElementById('hoOrdersList');
        var countEl   = document.getElementById('hoOrderCount');
        var refreshBtn = document.getElementById('hoRefreshBtn');
        var tabsEl    = document.getElementById('hoTabs');
        var autoRefreshCheckbox = document.getElementById('hoAutoRefresh');
        var autoRefreshLabel = document.getElementById('hoAutoRefreshLabel');
        var countdownEl = document.getElementById('hoCountdown');

        var state = {
            orders: [],
            filter: 'active',
            loading: false,
            autoRefresh: false,
            countdown: 30,
            intervalId: null,
            countdownId: null,
        };

        var STATUS_LABELS = {
            confirmed:           'New Order',
            accepted_by_driver:  'Driver Accepted',
            accepted_by_hub:     'Accepted',
            preparing:           'Preparing',
            prepared:            'Prepared',
            picked_up:           'Picked Up',
            completed:           'Completed',
            cancelled:           'Cancelled',
        };

        // ── Load Orders ───────────────────────────────────
        function loadOrders() {
            state.loading = true;
            render();

            var statusParam = '';
            if (state.filter === 'completed') {
                statusParam = '&status=completed,cancelled';
            }

            var url = apiOrders + '?hub_id=' + hubId + statusParam;

            fetch(url, {
                method: 'GET',
                headers: { 'X-WP-Nonce': wpNonce },
                credentials: 'same-origin',
            })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                state.loading = false;
                if (json.success && json.data && json.data.orders) {
                    state.orders = json.data.orders;
                } else {
                    state.orders = [];
                }
                render();
            })
            .catch(function() {
                state.loading = false;
                state.orders = [];
                render();
                toast('error', 'Failed to load orders');
            });
        }

        // ── Filter ────────────────────────────────────────
        function filterOrders() {
            var f = state.filter;
            if (f === 'active') {
                return state.orders.filter(function(o) {
                    return o.status !== 'completed' && o.status !== 'cancelled';
                });
            }
            if (f === 'new') {
                return state.orders.filter(function(o) {
                    return o.status === 'confirmed';
                });
            }
            if (f === 'in-progress') {
                return state.orders.filter(function(o) {
                    return ['accepted_by_driver', 'accepted_by_hub', 'preparing'].indexOf(o.status) !== -1;
                });
            }
            if (f === 'ready') {
                return state.orders.filter(function(o) {
                    return o.status === 'prepared' || o.status === 'picked_up';
                });
            }
            if (f === 'completed') {
                return state.orders.filter(function(o) {
                    return o.status === 'completed' || o.status === 'cancelled';
                });
            }
            return state.orders;
        }

        // ── Render ────────────────────────────────────────
        function render() {
            if (state.loading) {
                listEl.innerHTML = '<div class="knx-ho-empty"><i class="fas fa-spinner fa-spin"></i> Loading orders...</div>';
                return;
            }

            var filtered = filterOrders();
            countEl.textContent = filtered.length + ' order' + (filtered.length !== 1 ? 's' : '');

            if (!filtered.length) {
                listEl.innerHTML = '<div class="knx-ho-empty"><i class="fas fa-inbox"></i> No orders in this view.</div>';
                return;
            }

            var viewOrderBase = window.location.origin + '/hub-view-order';

            var html = filtered.map(function(o) {
                var cardClass = getCardClass(o.status);
                var statusClass = 'st-' + o.status;
                var label = STATUS_LABELS[o.status] || o.status.replace(/_/g, ' ');
                var total = money(o.total);
                var ago = relTime(o.created_at);
                var isActive = ['confirmed', 'accepted_by_driver', 'accepted_by_hub', 'preparing', 'prepared'].indexOf(o.status) !== -1;
                var viewUrl = viewOrderBase + '?order_id=' + o.order_id;

                // Fulfillment badge
                var fulfillmentHtml = '';
                if (o.fulfillment_type === 'pickup') {
                    fulfillmentHtml = '<span class="knx-ho-fulfillment is-pickup"><i class="fas fa-shopping-bag"></i> Pickup</span>';
                } else {
                    fulfillmentHtml = '<span class="knx-ho-fulfillment is-delivery"><i class="fas fa-motorcycle"></i> Delivery</span>';
                }

                // Driver name for delivery
                var driverHtml = '';
                if (o.fulfillment_type === 'delivery' && o.driver_name) {
                    driverHtml = '<div class="knx-ho-card__driver"><i class="fas fa-user-shield"></i> ' + esc(o.driver_name) + '</div>';
                } else if (o.fulfillment_type === 'delivery' && !o.driver_name) {
                    driverHtml = '<div class="knx-ho-card__driver knx-ho-card__driver--pending"><i class="fas fa-clock"></i> Awaiting driver</div>';
                }

                // Items preview (first 3 items as chips)
                var itemsHtml = '';
                if (o.items && o.items.length) {
                    var chips = o.items.slice(0, 3).map(function(it) {
                        return '<span>' + esc(it.quantity + 'x ' + it.name) + '</span>';
                    }).join('');
                    if (o.items.length > 3) {
                        chips += '<span class="knx-ho-card__items-more">+' + (o.items.length - 3) + ' more</span>';
                    }
                    itemsHtml = '<div class="knx-ho-card__items">' + chips + '</div>';
                }

                var notesHtml = '';
                if (o.notes) {
                    notesHtml = '<div class="knx-ho-card__notes"><strong>Notes:</strong> ' + esc(o.notes) + '</div>';
                }

                // Ready button
                var actionsHtml = '';
                if (isActive) {
                    if (o.hub_signaled_ready) {
                        actionsHtml = '<div class="knx-ho-card__actions">' +
                            '<span class="knx-ho-signaled"><i class="fas fa-check-circle"></i> Ready signal sent</span>' +
                            '</div>';
                    } else {
                        actionsHtml = '<div class="knx-ho-card__actions">' +
                            '<button type="button" class="knx-ho-ready-btn" data-order-id="' + o.order_id + '">' +
                            '<i class="fas fa-bell"></i> Ready for Pickup' +
                            '</button>' +
                            '</div>';
                    }
                }

                return '<a href="' + esc(viewUrl) + '" class="knx-ho-card-link">' +
                    '<div class="knx-ho-card ' + cardClass + '" data-order-id="' + o.order_id + '">' +
                        '<div class="knx-ho-card__top">' +
                            '<div>' +
                                '<div class="knx-ho-card__id">#' + esc(String(o.order_id)) + ' ' + esc(o.customer_name || 'Customer') + '</div>' +
                                '<div class="knx-ho-card__time">' + esc(ago) + '</div>' +
                            '</div>' +
                            '<div class="knx-ho-card__right">' +
                                '<span class="knx-ho-status ' + statusClass + '">' + esc(label) + '</span>' +
                                fulfillmentHtml +
                                '<div class="knx-ho-card__total">' + esc(total) + '</div>' +
                            '</div>' +
                        '</div>' +
                        driverHtml +
                        itemsHtml +
                        notesHtml +
                        actionsHtml +
                    '</div>' +
                '</a>';
            }).join('');

            listEl.innerHTML = html;

            // Attach signal handlers (prevent link navigation on button click)
            listEl.querySelectorAll('.knx-ho-ready-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    sendSignal(btn);
                });
            });
        }

        // ── Send Signal ───────────────────────────────────
        function sendSignal(btn) {
            var orderId = btn.dataset.orderId;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

            fetch(apiSignal, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpNonce,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    hub_id: hubId,
                    knx_nonce: nonce,
                    order_id: parseInt(orderId, 10),
                }),
            })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success) {
                    btn.className = 'knx-ho-ready-btn sent';
                    btn.innerHTML = '<i class="fas fa-check-circle"></i> Signal Sent';
                    btn.disabled = true;

                    // Update local state
                    state.orders.forEach(function(o) {
                        if (o.order_id === parseInt(orderId, 10)) {
                            o.hub_signaled_ready = true;
                        }
                    });

                    toast('success', 'Ready signal sent to driver');
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-bell"></i> Ready for Pickup';
                    toast('error', json.message || json.error || 'Failed to send signal');
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-bell"></i> Ready for Pickup';
                toast('error', 'Network error');
            });
        }

        // ── Tabs ──────────────────────────────────────────
        tabsEl.querySelectorAll('.knx-ho-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                tabsEl.querySelectorAll('.knx-ho-tab').forEach(function(t) { t.classList.remove('active'); });
                tab.classList.add('active');
                var newFilter = tab.dataset.filter;

                // If switching to/from completed, reload from API with different status param
                if ((newFilter === 'completed') !== (state.filter === 'completed')) {
                    state.filter = newFilter;
                    loadOrders();
                } else {
                    state.filter = newFilter;
                    render();
                }
            });
        });

        refreshBtn.addEventListener('click', function() {
            loadOrders();
        });

        // ── Auto-refresh toggle ───────────────────────────
        autoRefreshCheckbox.addEventListener('change', function() {
            state.autoRefresh = autoRefreshCheckbox.checked;
            if (state.autoRefresh) {
                autoRefreshLabel.classList.add('active');
                startAutoRefresh();
            } else {
                autoRefreshLabel.classList.remove('active');
                stopAutoRefresh();
            }
        });

        function startAutoRefresh() {
            stopAutoRefresh(); // Clear any existing intervals
            state.countdown = 30;
            updateCountdown();

            state.intervalId = setInterval(function() {
                loadOrders();
                state.countdown = 30;
            }, 30000); // 30 seconds

            state.countdownId = setInterval(function() {
                state.countdown--;
                if (state.countdown < 0) state.countdown = 30;
                updateCountdown();
            }, 1000);
        }

        function stopAutoRefresh() {
            if (state.intervalId) {
                clearInterval(state.intervalId);
                state.intervalId = null;
            }
            if (state.countdownId) {
                clearInterval(state.countdownId);
                state.countdownId = null;
            }
            countdownEl.textContent = '';
        }

        function updateCountdown() {
            if (state.autoRefresh) {
                countdownEl.textContent = 'Next refresh: ' + state.countdown + 's';
            } else {
                countdownEl.textContent = '';
            }
        }

        // ── Helpers ───────────────────────────────────────
        function getCardClass(status) {
            if (status === 'confirmed') return 'is-new';
            if (status === 'prepared' || status === 'picked_up') return 'is-ready';
            if (status === 'completed' || status === 'cancelled') return 'is-done';
            return 'is-progress';
        }

        function money(val) {
            var n = parseFloat(val);
            if (!isFinite(n)) return '$—';
            return '$' + n.toFixed(2);
        }

        function relTime(mysql) {
            if (!mysql) return '';
            var parts = mysql.split(' ');
            if (parts.length !== 2) return mysql;
            var d = parts[0].split('-').map(Number);
            var t = parts[1].split(':').map(Number);
            var dt = new Date(Date.UTC(d[0], d[1] - 1, d[2], t[0] || 0, t[1] || 0, t[2] || 0));
            var diff = Date.now() - dt.getTime();
            if (diff < 0) diff = 0;
            var sec = Math.floor(diff / 1000);
            if (sec < 60) return sec + 's ago';
            var min = Math.floor(sec / 60);
            if (min < 60) return min + 'm ago';
            var hr = Math.floor(min / 60);
            if (hr < 24) return hr + 'h ago';
            return Math.floor(hr / 24) + 'd ago';
        }

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        }

        function toast(type, msg) {
            if (typeof window.knxToast === 'function') {
                window.knxToast(msg, type);
            } else if (typeof window.KnxToast !== 'undefined' && typeof window.KnxToast.show === 'function') {
                window.KnxToast.show(msg, type);
            }
        }

        // ── Init ──────────────────────────────────────────
        loadOrders();
    }
})();
