/**
 * ==========================================================
 * Kingdom Nexus - Checkout Payment Flow (A2.1) — SEALED
 * Stripe Elements + Server Polling (SSOT)
 * ==========================================================
 *
 * FLOW:
 * 1) Click "Place Order" → Create Order (snapshot-locked, server)
 * 2) Create PaymentIntent (server)
 * 3) Confirm card payment via Stripe.js (KNX_STRIPE_RUNTIME)
 * 4) Poll payment status endpoint until webhook confirms (server authority)
 *
 * FAIL-CLOSED:
 * - No action if paymentsReady is false
 * - No duplicate clicks while processing
 * - Polling timeout after ~2 minutes
 * - Reload resume via localStorage (non-sensitive only)
 *
 * SSOT:
 * - Webhook is ONLY authority for payment confirmation
 * - Frontend never marks orders paid
 * - Server quote is authoritative for totals
 *
 * HARDENING:
 * - WP double-injection guard (prevents duplicate listeners/pollers)
 * - DOM binding guard (does not bail if button not yet in DOM)
 * - Correct parsing of status payload (supports top-level + nested { success, message, data })
 * - Finalization guard (hard-stop polling/resume after paid)
 * - Safe redirect (same-origin only)
 * ==========================================================
 */

(function () {
  'use strict';

  // ----------------------------
  // Global double-injection guard (WordPress echo-assets safety)
  // ----------------------------
  if (typeof window !== 'undefined') {
    if (window.__KNX_A2_1_LOADED) return;
    window.__KNX_A2_1_LOADED = true;
  }

  var cfg = window.KNX_CHECKOUT_CONFIG;
  if (!cfg) return;

  var runtime = window.KNX_STRIPE_RUNTIME;
  if (!runtime) return;

  // ----------------------------
  // State
  // ----------------------------
  var inFlight = false;
  var pollingTimer = null;
  var pollingAttempts = 0;
  var consecutiveNetworkFailures = 0;

  var POLLING_MAX_ATTEMPTS = 33; // 15 @ 2s + 18 @ 5s ≈ 120s total
  var POLLING_PHASE_A_INTERVAL = 2000; // 2s
  var POLLING_PHASE_B_INTERVAL = 5000; // 5s
  var POLLING_PHASE_A_COUNT = 15; // 30s
  var MAX_CONSECUTIVE_FAILURES = 6;

  var STORAGE_KEY = 'knx_pending_payment';
  var SPLASH_REDIRECT_DELAY = 2500; // 2.5s

  // Finalization guard (hard stop)
  var paymentFinalized = false;
  if (typeof window !== 'undefined' && window.__KNX_PAYMENT_FINALIZED) {
    paymentFinalized = true;
  }

  // ----------------------------
  // DOM refs (bound later; do NOT bail early)
  // ----------------------------
  var btnId = (cfg.ui && cfg.ui.placeOrderBtnId) ? cfg.ui.placeOrderBtnId : 'knxCoPlaceOrderBtn';
  var statusBoxId = (cfg.ui && cfg.ui.statusBoxId) ? cfg.ui.statusBoxId : 'knxCheckoutStatus';

  var btn = null;
  var statusBox = null;

  // ----------------------------
  // Headers helper (TOP-LEVEL)
  // Used by polling + status checks
  // ----------------------------
  function buildHeaders(isJson) {
    var h = {};
    if (isJson) h['Content-Type'] = 'application/json';
    if (cfg && cfg.wpRestNonce) h['X-WP-Nonce'] = cfg.wpRestNonce;
    return h;
  }

  // ----------------------------
  // Safe redirect (same-origin only)
  // ----------------------------
  function safeRedirectUrl(u) {
    if (!u || typeof u !== 'string') return '';
    u = u.trim();
    if (!u) return '';
    try {
      var parsed = new URL(u, window.location.origin);
      if (parsed.origin !== window.location.origin) return '';
      return parsed.href;
    } catch (e) {
      return '';
    }
  }

  // ----------------------------
  // Splash UI
  // ----------------------------
  function showSuccessSplash(redirectUrl) {
    var safeUrl =
      safeRedirectUrl(redirectUrl) ||
      safeRedirectUrl(cfg.successRedirectUrl) ||
      safeRedirectUrl(cfg.homeUrl) ||
      '/';

    var splash = document.getElementById('knxSuccessSplash');
    if (!splash) {
      // Even if splash is missing, still redirect (best effort)
      setTimeout(function () {
        window.location.href = safeUrl;
      }, 50);
      return;
    }

    // Best-effort: blur active element to reduce Stripe Elements a11y warnings
    try {
      if (document.activeElement && typeof document.activeElement.blur === 'function') {
        document.activeElement.blur();
      }
    } catch (e) {}

    splash.classList.add('active');

    setTimeout(function () {
      window.location.href = safeUrl;
    }, SPLASH_REDIRECT_DELAY);
  }

  // Expose globally for manual testing
  if (typeof window !== 'undefined') {
    window.knxShowSuccessSplash = function () {
      showSuccessSplash();
    };
  }

  // ----------------------------
  // Response Normalization
  // Ensures consistent shape: { success, message, data }
  // ----------------------------
  function normalizeResponse(raw) {
    if (!raw || typeof raw !== 'object') {
      return { success: false, message: 'Invalid response', data: null };
    }

    // Already canonical shape
    if (typeof raw.success === 'boolean') {
      return {
        success: raw.success,
        message: raw.message || (raw.success ? 'OK' : 'Unknown error'),
        data: (typeof raw.data !== 'undefined') ? raw.data : raw
      };
    }

    // Legacy/unknown shape
    var hasError = raw.error || raw.reason || raw.code;
    return {
      success: !hasError,
      message: raw.message || raw.error || (hasError ? 'Request failed' : 'OK'),
      data: raw
    };
  }

  // ----------------------------
  // Extract status payload (supports top-level + nested data)
  // ----------------------------
  function extractStatusInfo(raw) {
    if (!raw || typeof raw !== 'object') return null;
    if (raw.__auth_error) return { __auth_error: true };

    var canon = normalizeResponse(raw);
    var payload = canon.data;

    if (!payload || typeof payload !== 'object') payload = raw;

    // Prefer explicit status fields (top-level first, then nested)
    var st =
      raw.status ||
      raw.payment_status ||
      raw.order_status ||
      payload.status ||
      payload.payment_status ||
      payload.order_status ||
      payload.state ||
      '';

    st = String(st || '').toLowerCase();

    var msg =
      canon.message ||
      raw.message ||
      payload.message ||
      '';

    var redirectUrl =
      raw.redirect_url ||
      payload.redirect_url ||
      payload.redirectUrl ||
      '';

    return {
      canon: canon,
      payload: payload,
      status: st,
      message: msg,
      redirect_url: redirectUrl
    };
  }

  // ----------------------------
  // UI helpers
  // ----------------------------
  function setStatus(type, msg) {
    if (!statusBox) return;
    statusBox.textContent = String(msg || '');
    statusBox.style.display = msg ? 'block' : 'none';

    var colors = {
      info: '#3b82f6',
      success: '#10b981',
      error: '#ef4444',
      warn: '#f59e0b'
    };
    statusBox.style.color = colors[type] || '#6b7280';
  }

  function disableBtn(text) {
    if (!btn) return;
    btn.disabled = true;
    btn.style.opacity = '0.6';
    btn.style.cursor = 'not-allowed';
    var textSpan = btn.querySelector('.knx-place-order-text');
    if (textSpan) textSpan.textContent = String(text || 'Processing…');
  }

  function enableBtn(text) {
    if (!btn) return;
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.cursor = 'pointer';
    var textSpan = btn.querySelector('.knx-place-order-text');
    if (textSpan) textSpan.textContent = String(text || 'Place Order');
  }

  // ----------------------------
  // localStorage helpers (non-sensitive only)
  // ----------------------------
  function savePending(intent_id, order_id) {
    try {
      var obj = { ts: Date.now(), intent_id: intent_id || '', order_id: order_id || '' };
      localStorage.setItem(STORAGE_KEY, JSON.stringify(obj));
    } catch (e) {}
  }

  function getPending() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      var obj = JSON.parse(raw);
      var age = Date.now() - (obj.ts || 0);
      if (age > 600000) {
        // older than 10 min → discard
        clearPending();
        return null;
      }
      return obj;
    } catch (e) {
      return null;
    }
  }

  function clearPending() {
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch (e) {}
  }

  // ----------------------------
  // Finalization (single authority in this script)
  // ----------------------------
  function stopPollingTimer() {
    if (pollingTimer) {
      clearTimeout(pollingTimer);
      pollingTimer = null;
    }
  }

  function finalizePaid(order_id, redirectUrl) {
    if (paymentFinalized) return;

    paymentFinalized = true;
    if (typeof window !== 'undefined') window.__KNX_PAYMENT_FINALIZED = true;

    stopPollingTimer();
    clearPending();

    setStatus('success', order_id ? ('Payment confirmed! Order ID: ' + order_id) : 'Payment confirmed — order placed!');
    disableBtn('Confirmed');

    showSuccessSplash(redirectUrl);
  }

  function finalizeFailed(msg) {
    if (paymentFinalized) return;

    stopPollingTimer();
    clearPending();

    setStatus('error', msg || 'Payment failed or canceled.');
    enableBtn('Retry');
    inFlight = false;
  }

  function finalizeAuthExpired() {
    stopPollingTimer();
    setStatus('error', 'Session expired — please login again and refresh to continue.');
    disableBtn('Login required');
    // FAIL-CLOSED: keep pending so a reload after login can resume
  }

  function finalizeTimeout() {
    stopPollingTimer();
    setStatus('warn', 'Payment still processing — do not resubmit. Check your order status or contact support.');
    disableBtn('Processing…');
    // FAIL-CLOSED: keep pending + keep inFlight true (prevents duplicates)
  }

  // ----------------------------
  // A) Fetch Quote Snapshot (required for create-order)
  // ----------------------------
  async function fetchQuoteSnapshot() {
    var quoteUrl = cfg.quoteUrl;
    if (!quoteUrl) {
      setStatus('error', 'Quote endpoint missing.');
      return null;
    }

    var tipEl = document.getElementById('knx_tip_amount');
    var couponEl = document.getElementById('knx_coupon_code');

    var payload = {
      tip_amount: tipEl ? parseFloat(tipEl.value || 0) : 0,
      coupon_code: couponEl ? String(couponEl.value || '').trim() : ''
    };

    // Fulfillment: use root selected address if available
    try {
      var rootEl = document.getElementById((cfg.ui && cfg.ui.rootId) ? cfg.ui.rootId : 'knx-checkout');
      var selectedAddressId = rootEl ? parseInt(rootEl.getAttribute('data-selected-address-id') || '0', 10) : 0;
      var fulfillment = (selectedAddressId && selectedAddressId > 0) ? 'delivery' : 'pickup';
      payload.fulfillment_type = fulfillment;
      if (fulfillment === 'delivery' && selectedAddressId > 0) {
        payload.address_id = selectedAddressId;
      }
    } catch (e) {}

    var res, raw, data;
    try {
      res = await fetch(quoteUrl, {
        method: 'POST',
        credentials: 'include',
        headers: buildHeaders(true),
        body: JSON.stringify(payload)
      });
      raw = await res.json();
      data = normalizeResponse(raw);
    } catch (err) {
      setStatus('error', 'Network error fetching quote.');
      return null;
    }

    if (!data.success) {
      setStatus('error', data.message || 'Unable to fetch quote.');
      return null;
    }

    var snapshot = (data.data && data.data.snapshot) ? data.data.snapshot : (raw && raw.snapshot ? raw.snapshot : null);

    // Deep-clone + seal into window (SSOT)
    try {
      window.KNX_LAST_QUOTE = raw || data || null;

      var snapshotClone = null;
      if (snapshot && typeof snapshot === 'object') {
        if (typeof structuredClone === 'function') snapshotClone = structuredClone(snapshot);
        else {
          try { snapshotClone = JSON.parse(JSON.stringify(snapshot)); }
          catch (e) { snapshotClone = snapshot; }
        }
      }

      window.KNX_LAST_SNAPSHOT = snapshotClone || null;
      snapshot = snapshotClone || snapshot;
    } catch (e) {}

    if (!snapshot) {
      setStatus('error', 'Quote snapshot missing. Please refresh and try again.');
      return null;
    }

    return snapshot;
  }

  // ----------------------------
  // B) Create Order (server-authoritative snapshot)
  // ----------------------------
  async function createOrder() {
    var url = cfg.createOrderUrl;
    if (!url) {
      setStatus('error', 'Create order endpoint missing.');
      return null;
    }

    setStatus('info', 'Preparing order...');
    var freshSnapshot = await fetchQuoteSnapshot();
    if (!freshSnapshot) return null;

    var tipEl = document.getElementById('knx_tip_amount');
    var couponEl = document.getElementById('knx_coupon_code');
    var commentEl = document.getElementById('knxCoComment');
    var deliveryTimeEl = document.getElementById('knxDeliveryTime');

    var sealed = window.KNX_LAST_SNAPSHOT;
    if (!sealed || typeof sealed !== 'object') {
      console.error('[KNX][CREATE_ORDER] Missing KNX_LAST_SNAPSHOT');
      setStatus('error', 'Order snapshot missing. Please re-quote.');
      return null;
    }

    // Optional mismatch warning (best effort)
    try {
      if (!window.__KNX_SNAPSHOT_MISMATCH_WARNED) {
        var s1 = null, s2 = null;
        try { s1 = JSON.stringify(freshSnapshot); } catch (e) { s1 = String(freshSnapshot); }
        try { s2 = JSON.stringify(sealed); } catch (e) { s2 = String(sealed); }
        if (s1 !== s2) {
          console.warn('[KNX][SNAPSHOT_MISMATCH] Fetched snapshot differs from sealed KNX_LAST_SNAPSHOT. Using sealed snapshot for create-order.');
          window.__KNX_SNAPSHOT_MISMATCH_WARNED = true;
        }
      }
    } catch (e) {}

    var payload = {
      fulfillment_type: sealed.fulfillment_type || 'delivery',
      comment: commentEl ? String(commentEl.value || '').trim() : '',
      coupon_code: couponEl ? String(couponEl.value || '').trim() : '',
      tip_amount: tipEl ? parseFloat(tipEl.value || 0) : 0,
      delivery_time: deliveryTimeEl ? String(deliveryTimeEl.value || '').trim() : 'asap',
      snapshot: sealed
    };

    // Client-side validation gate (best-effort, uses sealed snapshot)
    try {
      if (payload.fulfillment_type === 'delivery') {
        var dv = sealed.delivery || null;
        var dv46 = dv && dv.delivery_snapshot_v46 ? dv.delivery_snapshot_v46 : null;
        var addr = dv46 && dv46.address ? dv46.address : null;
        var feeObj = dv46 && dv46.delivery_fee ? dv46.delivery_fee : null;

        var valid = true;
        if (!dv || !dv46 || !addr) valid = false;

        if (addr) {
          var lat = Number(addr.lat || addr.latitude || 0);
          var lng = Number(addr.lng || addr.longitude || 0);
          if (!isFinite(lat) || !isFinite(lng) || lat === 0 || lng === 0) valid = false;
        }

        if (!feeObj || typeof feeObj.amount === 'undefined') valid = false;

        if (!valid) {
          setStatus('error', 'Delivery snapshot incomplete. Re-run quote or select a valid address.');
          return null;
        }

        if (typeof sealed.delivery_fee !== 'undefined' && dv46 && dv46.delivery_fee) {
          var topFee = Number(sealed.delivery_fee || 0);
          var sealedFee = Number(dv46.delivery_fee.amount || 0);
          if (Math.abs(topFee - sealedFee) > 0.01) {
            setStatus('error', 'Delivery fee mismatch. Please re-run quote.');
            return null;
          }
        }
      }
    } catch (e) {
      setStatus('error', 'Snapshot validation failed.');
      return null;
    }

    // Expose payload for DevTools verification
    try { window.KNX_CREATE_PAYLOAD = payload; } catch (e) {}

    var res, raw, data;
    try {
      res = await fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: buildHeaders(true),
        body: JSON.stringify(payload)
      });
      raw = await res.json();
      data = normalizeResponse(raw);
    } catch (err) {
      setStatus('error', 'Network error creating order.');
      return null;
    }

    var orderId = raw.order_id || (data.data && data.data.order_id);
    if (!data.success || !orderId) {
      setStatus('error', data.message || 'Unable to create order.');
      return null;
    }

    if (raw.already_exists) {
      console.log('[KNX-409] Reusing existing order:', {
        order_id: orderId,
        order_number: raw.order_number,
        reason: raw.reason
      });
    }

    return { order_id: orderId };
  }

  // ----------------------------
  // C) Create PaymentIntent for the created order
  // ----------------------------
  async function createIntent() {
    var intentUrl = cfg.createIntentUrl;
    if (!intentUrl) {
      setStatus('error', 'Create intent endpoint missing.');
      return null;
    }

    var order = await createOrder();
    if (!order || !order.order_id) return null;

    var res, raw, data;
    try {
      res = await fetch(intentUrl, {
        method: 'POST',
        credentials: 'include',
        headers: buildHeaders(true),
        body: JSON.stringify({ order_id: order.order_id })
      });
      raw = await res.json();
      data = normalizeResponse(raw);
    } catch (err) {
      setStatus('error', 'Network error creating payment intent.');
      return null;
    }

    if (!data.success) {
      setStatus('error', data.message || 'Unable to create payment intent.');
      return null;
    }

    // Backend anti-duplicate: order already confirmed/paid
    if (raw.already_paid || (data.data && data.data.already_paid)) {
      var rUrl = raw.redirect_url || (data.data && data.data.redirect_url) || '';
      finalizePaid(order.order_id, rUrl);
      return null;
    }

    var client_secret = raw.client_secret || (data.data && data.data.client_secret) || '';
    var intent_id = raw.payment_intent_id || raw.intent_id || raw.provider_intent_id || '';

    if (!client_secret) {
      setStatus('error', 'Payment intent response missing client_secret.');
      return null;
    }

    return { client_secret: client_secret, intent_id: intent_id, order_id: order.order_id };
  }

  // ----------------------------
  // D) Confirm card payment
  // ----------------------------
  async function confirmPayment(client_secret) {
    var r = runtime.init();
    if (!r || !r.ok) {
      setStatus('error', 'Stripe runtime init failed: ' + (r ? r.error : 'unknown'));
      return null;
    }

    var mounted = runtime.ensureMounted();
    if (!mounted) {
      setStatus('error', 'Card element not mounted.');
      return null;
    }

    var card = runtime.getCard();
    if (!card) {
      setStatus('error', 'Card element unavailable.');
      return null;
    }

    var stripe = runtime.stripe;
    if (!stripe) {
      setStatus('error', 'Stripe instance missing.');
      return null;
    }

    runtime.clearError();

    var result;
    try {
      result = await stripe.confirmCardPayment(client_secret, {
        payment_method: { card: card }
      });
    } catch (err) {
      setStatus('error', 'Payment confirmation error: ' + String(err));
      return null;
    }

    if (result.error) {
      var errMsg = result.error.message || 'Payment failed.';
      setStatus('error', errMsg);
      runtime.setError(errMsg);
      return null;
    }

    if (!result.paymentIntent) {
      setStatus('error', 'Payment response invalid.');
      return null;
    }

    return result.paymentIntent;
  }

  // ----------------------------
  // Helper: parse JSON even on non-2xx (so we can read knx_rest_error)
  // ----------------------------
  function parseJsonBestEffort(res) {
    if (!res) return Promise.resolve(null);
    return res.json().catch(function () { return null; });
  }

  function looksLikeNoRoute(obj) {
    if (!obj || typeof obj !== 'object') return false;
    var code = String(obj.code || '').toLowerCase();
    var msg = String(obj.message || '').toLowerCase();
    if (code === 'rest_no_route') return true;
    if (msg.indexOf('no route') !== -1) return true;
    return false;
  }

  // ----------------------------
  // E) Poll payment status (POST primary, GET fallback)
  // ----------------------------
  function startPolling(intent_id, order_id) {
    if (paymentFinalized) return;
    if (typeof window !== 'undefined' && window.__KNX_PAYMENT_FINALIZED) return;

    pollingAttempts = 0;
    consecutiveNetworkFailures = 0;

    setStatus('info', 'Waiting for payment confirmation…');

    function intervalForAttempt(n) {
      return (n <= POLLING_PHASE_A_COUNT) ? POLLING_PHASE_A_INTERVAL : POLLING_PHASE_B_INTERVAL;
    }

    function scheduleNext() {
      if (paymentFinalized) return;
      if (pollingAttempts >= POLLING_MAX_ATTEMPTS) {
        finalizeTimeout();
        return;
      }
      var delay = intervalForAttempt(pollingAttempts + 1);
      pollingTimer = setTimeout(tick, delay);
    }

    function tryGetMethod(url) {
      var params = [];
      if (intent_id) params.push('payment_intent_id=' + encodeURIComponent(intent_id));
      if (order_id) params.push('order_id=' + encodeURIComponent(order_id));
      var qs = params.length ? ('?' + params.join('&')) : '';

      return fetch(url + qs, {
        method: 'GET',
        credentials: 'include',
        headers: buildHeaders(false)
      })
        .then(function (res) {
          if (res.status === 401 || res.status === 403) return { __auth_error: true };

          return parseJsonBestEffort(res).then(function (obj) {
            // If GET is not allowed or route missing, return null (soft failure)
            if (res.status === 405) return null;
            if (res.status === 404 && looksLikeNoRoute(obj)) return null;
            return obj;
          });
        })
        .catch(function () {
          return null;
        });
    }

    function pollOnce() {
      var url = cfg.paymentStatusUrl;
      if (!url) {
        stopPollingTimer();
        setStatus('error', 'Payment status endpoint missing.');
        clearPending();
        enableBtn('Retry');
        inFlight = false;
        return Promise.resolve(false);
      }

      var payload = {};
      if (intent_id) payload.payment_intent_id = intent_id;
      if (order_id) payload.order_id = order_id;

      return fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: buildHeaders(true),
        body: JSON.stringify(payload)
      })
        .then(function (res) {
          if (res.status === 401 || res.status === 403) return { __auth_error: true };

          // If method not allowed -> try GET
          if (res.status === 405) return tryGetMethod(url);

          // For 404: could be "no route" OR "order not found"
          if (res.status === 404) {
            return parseJsonBestEffort(res).then(function (obj) {
              if (looksLikeNoRoute(obj)) return tryGetMethod(url);
              return obj; // likely { success:false, message:"Order not found" }
            });
          }

          // Any other status: try to parse JSON anyway (even if non-2xx)
          return parseJsonBestEffort(res);
        })
        .then(function (raw) {
          if (paymentFinalized) return false;

          if (raw && raw.__auth_error) {
            finalizeAuthExpired();
            return false;
          }

          if (!raw || typeof raw !== 'object') {
            consecutiveNetworkFailures++;
            if (consecutiveNetworkFailures >= MAX_CONSECUTIVE_FAILURES) {
              setStatus('warn', 'Connection issues — retrying…');
            }
            return true;
          }

          consecutiveNetworkFailures = 0;

          var info = extractStatusInfo(raw);
          if (!info) return true;

          if (info.__auth_error) {
            finalizeAuthExpired();
            return false;
          }

          // If API explicitly returns success:false, decide if stop or continue
          if (info.canon && info.canon.success === false) {
            if (info.message && String(info.message).match(/not found/i)) {
              finalizeFailed('Payment record not found. Contact support if charged.');
              return false;
            }
            // Otherwise keep polling (fail-closed)
            return true;
          }

          var st = info.status;

          // Success states
          if (st === 'succeeded' || st === 'paid' || st === 'confirmed') {
            finalizePaid(order_id, info.redirect_url || '');
            return false;
          }

          // Failure states
          if (st === 'failed' || st === 'canceled' || st === 'cancelled') {
            finalizeFailed(info.message || 'Payment failed or canceled.');
            return false;
          }

          // No record states
          if (st === 'no_record' || st === 'not_found') {
            finalizeFailed('Payment record not found. Contact support if charged.');
            return false;
          }

          // Pending/processing/unknown -> keep polling
          return true;
        })
        .catch(function () {
          consecutiveNetworkFailures++;
          if (consecutiveNetworkFailures >= MAX_CONSECUTIVE_FAILURES) {
            setStatus('warn', 'Connection issues — retrying…');
          }
          return true;
        });
    }

    function tick() {
      stopPollingTimer();
      if (paymentFinalized) return;

      if (pollingAttempts >= POLLING_MAX_ATTEMPTS) {
        finalizeTimeout();
        return;
      }

      pollingAttempts++;

      pollOnce().then(function (shouldContinue) {
        if (!shouldContinue) return;

        if (pollingAttempts >= POLLING_MAX_ATTEMPTS) {
          finalizeTimeout();
          return;
        }

        scheduleNext();
      });
    }

    tick(); // first poll immediately
  }

  // ----------------------------
  // F) Main flow
  // ----------------------------
  async function handlePlaceOrder() {
    if (paymentFinalized) return;
    if (inFlight) return;

    if (!cfg.paymentsReady) {
      setStatus('error', 'Payment system not configured. Please try again later.');
      return;
    }

    inFlight = true;
    disableBtn('Processing…');
    runtime.clearError();

    setStatus('info', 'Creating payment intent…');
    var intentData = await createIntent();
    if (!intentData) {
      if (!paymentFinalized) {
        enableBtn('Place Order');
        inFlight = false;
      }
      return;
    }

    var client_secret = intentData.client_secret;
    var intent_id = intentData.intent_id;
    var order_id = intentData.order_id;

    savePending(intent_id, order_id);

    setStatus('info', 'Confirming payment…');
    var paymentIntent = await confirmPayment(client_secret);
    if (!paymentIntent) {
      clearPending();
      enableBtn('Retry');
      inFlight = false;
      return;
    }

    startPolling(intent_id, order_id);
  }

  // ----------------------------
  // G) Reload resume + Re-entry guard
  // ----------------------------
  function fetchStatusBestEffort(order_id, intent_id) {
    if (!cfg.paymentStatusUrl) return Promise.resolve(null);

    var url = cfg.paymentStatusUrl;

    // Try GET first
    var qs = [];
    if (order_id) qs.push('order_id=' + encodeURIComponent(order_id));
    if (intent_id) qs.push('payment_intent_id=' + encodeURIComponent(intent_id));
    var getUrl = url + (qs.length ? ('?' + qs.join('&')) : '');

    return fetch(getUrl, {
      method: 'GET',
      credentials: 'include',
      headers: buildHeaders(false)
    })
      .then(function (res) {
        if (res.status === 401 || res.status === 403) return { __auth_error: true };

        // If GET not allowed, try POST
        if (res.status === 405 || (res.status === 404 && looksLikeNoRoute)) {
          var payload = {};
          if (order_id) payload.order_id = order_id;
          if (intent_id) payload.payment_intent_id = intent_id;

          return fetch(url, {
            method: 'POST',
            credentials: 'include',
            headers: buildHeaders(true),
            body: JSON.stringify(payload)
          })
            .then(function (r2) {
              if (r2.status === 401 || r2.status === 403) return { __auth_error: true };
              return parseJsonBestEffort(r2);
            })
            .catch(function () { return null; });
        }

        return parseJsonBestEffort(res);
      })
      .catch(function () { return null; });
  }

  function resumeIfPending() {
    if (paymentFinalized) return;

    var pending = getPending();
    if (!pending || !pending.intent_id) return;

    setStatus('info', 'Resuming payment confirmation…');
    disableBtn('Resuming…');
    inFlight = true;

    fetchStatusBestEffort(pending.order_id, pending.intent_id).then(function (raw) {
      if (paymentFinalized) return;

      if (raw && raw.__auth_error) {
        finalizeAuthExpired();
        return;
      }

      if (raw && typeof raw === 'object') {
        var info = extractStatusInfo(raw);
        if (info && (info.status === 'succeeded' || info.status === 'paid' || info.status === 'confirmed')) {
          finalizePaid(pending.order_id, info.redirect_url || '');
          return;
        }
      }

      startPolling(pending.intent_id, pending.order_id);
    });
  }

  // ----------------------------
  // DOM bind (do not bail if button missing yet)
  // ----------------------------
  function bindDomIfReady() {
    if (typeof window !== 'undefined' && window.__KNX_A2_1_BOUND) return;

    btn = document.getElementById(btnId);
    statusBox = document.getElementById(statusBoxId);

    if (!btn) return;

    btn.addEventListener('click', handlePlaceOrder);
    if (typeof window !== 'undefined') window.__KNX_A2_1_BOUND = true;
  }

  function init() {
    bindDomIfReady();
    resumeIfPending();

    // If button wasn't present at init time, retry shortly (WP content timing)
    if (!btn) {
      setTimeout(function () {
        bindDomIfReady();
      }, 50);
      setTimeout(function () {
        bindDomIfReady();
      }, 250);
    }
  }

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    init();
  } else {
    document.addEventListener('DOMContentLoaded', init);
  }
})();
