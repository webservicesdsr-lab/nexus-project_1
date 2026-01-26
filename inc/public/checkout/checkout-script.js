/**
 * Kingdom Nexus — Checkout Script (UI v2)
 * - SSOT for quote + tip + promo UI
 * - Mobile: sticky bottom bar mirrors secure totals
 * - Tip: chips (No tip, presets) + Custom mode with apply/clear
 * - Summary: collapsible on mobile
 * - Defensive DOM checks (no globals)
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('knx-checkout');
    if (!root) return;

    var quoteUrl = root.getAttribute('data-quote-url');
    if (!quoteUrl) return;

    // Core elements
    var summaryBody = document.getElementById('knx-summary-body');
    var tipHidden = document.getElementById('knx_tip_amount');
    var couponHidden = document.getElementById('knx_coupon_code');

    // Place Order button (main)
    var placeOrderBtn = document.getElementById('knxCoPlaceOrderBtn');
    var placeOrderTotal = document.getElementById('knxPlaceOrderTotal');

    // Sticky bar (mobile)
    var stickyBar = document.querySelector('.knx-co-stickybar');
    var stickyTotal = document.getElementById('knxStickyTotal');
    var stickySubline = document.getElementById('knxStickySubline');
    var stickyContinueBtn = document.getElementById('knxStickyContinueBtn');

    // Tip UI
    var tipPill = document.getElementById('knxTipPill');
    var tipChips = root.querySelectorAll('.knx-tip-chip');
    var tipCustomWrap = root.querySelector('[data-tip-custom]');
    var tipCustomInput = document.getElementById('knx_tip_custom');
    var tipCustomApply = document.getElementById('knx_tip_custom_apply');
    var tipCustomClear = document.getElementById('knx_tip_custom_clear');

    // Promo UI
    var couponInput = document.getElementById('knx_coupon_input');
    var couponApplyBtn = document.getElementById('knx_coupon_apply_btn');
    var couponStatus = document.getElementById('knx_coupon_status');

    // Summary collapse UI
    var summaryCard = root.querySelector('.knx-co-card--summary');
    var summaryToggle = root.querySelector('.knx-co-collapse-toggle');

    // Disclaimer toggle
    // Use data-attribute selectors to match shortcode markup (fail-closed if not found)
    var feeToggle = root.querySelector('[data-co-toggle="fees"]');
    var feePanel = root.querySelector('[data-co-panel="fees"]');

    function money(n) {
      var val = Number(n || 0);
      try {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(val);
      } catch (e) {
        return '$' + val.toFixed(2);
      }
    }

    function setText(el, txt) {
      if (!el) return;
      el.textContent = String(txt || '');
    }

    function clearSummary(msg) {
      if (!summaryBody) return;
      summaryBody.innerHTML = '';
      var row = document.createElement('div');
      row.className = 'knx-summary-row';
      row.innerHTML = '<span>' + String(msg || '—') + '</span><span></span>';
      summaryBody.appendChild(row);
    }

    function renderRow(label, value, cls) {
      var div = document.createElement('div');
      div.className = 'knx-summary-row' + (cls ? ' ' + cls : '');
      var a = document.createElement('span');
      a.textContent = String(label);
      var b = document.createElement('span');
      b.textContent = String(value);
      div.appendChild(a);
      div.appendChild(b);
      return div;
    }

    function normalizeTax(breakdown) {
      var taxAmount = 0;
      var taxRate = 0;

      if (breakdown && typeof breakdown.tax === 'object' && breakdown.tax) {
        taxAmount = Number(breakdown.tax.amount || 0);
        taxRate = Number(breakdown.tax.rate || 0);
      } else if (typeof breakdown.tax === 'number') {
        taxAmount = Number(breakdown.tax || 0);
      }

      return { amount: taxAmount, rate: taxRate };
    }

    function setActiveChip(mode, amount) {
      if (!tipChips || !tipChips.length) return;

      tipChips.forEach(function (b) {
        b.classList.remove('is-active');
      });

      if (mode === 'custom') {
        tipChips.forEach(function (b) {
          if (b.getAttribute('data-tip-mode') === 'custom') b.classList.add('is-active');
        });
        return;
      }

      // mode: none or preset
      var target = null;
      tipChips.forEach(function (b) {
        var m = b.getAttribute('data-tip-mode');
        var t = b.getAttribute('data-tip');
        if (mode === 'none' && m === 'none') target = b;
        if (mode === 'preset' && t !== null && Number(t) === Number(amount)) target = b;
      });
      if (target) target.classList.add('is-active');
    }

    function setTipPill(amount, isCustom) {
      if (!tipPill) return;
      var a = Number(amount || 0);
      if (!isFinite(a) || a <= 0) {
        tipPill.textContent = 'No tip';
      } else {
        tipPill.textContent = (isCustom ? 'Custom: ' : 'Tip: ') + money(a);
      }
    }

    function showCouponStatus(type, text) {
      if (!couponStatus) return;
      couponStatus.className = 'knx-coupon-status ' + (type || '');
      couponStatus.textContent = String(text || '');
      couponStatus.style.display = text ? 'block' : 'none';
    }

    function updateTotalsMirrors(totalValue, tipValue) {
      var t = money(totalValue || 0);
      if (placeOrderTotal) placeOrderTotal.textContent = t;
      if (stickyTotal) stickyTotal.textContent = t;

      var tipNum = Number(tipValue || 0);
      if (stickySubline) {
        if (isFinite(tipNum) && tipNum > 0) {
          stickySubline.textContent = 'Includes tip ' + money(tipNum);
        } else {
          stickySubline.textContent = 'Calculated securely';
        }
      }
    }

    async function fetchQuote() {
      if (!summaryBody) return;

      summaryBody.innerHTML = '';
      summaryBody.appendChild(renderRow('Loading…', '', ''));

      var tip = tipHidden ? Number(tipHidden.value || 0) : 0;
      var code = couponHidden ? String(couponHidden.value || '') : '';

      var payload = {};
      if (isFinite(tip) && tip > 0) payload.tip_amount = tip;

      if (code) payload.coupon_code = code;

      // Fulfillment: prefer explicit selected address id rendered in root dataset
      var rootEl = document.getElementById('knx-checkout');
      var selectedAddressId = 0;
      if (rootEl) selectedAddressId = parseInt(rootEl.getAttribute('data-selected-address-id') || '0', 10) || 0;
      var fulfillment = (selectedAddressId && selectedAddressId > 0) ? 'delivery' : 'pickup';
      payload.fulfillment_type = fulfillment;
      if (fulfillment === 'delivery' && selectedAddressId > 0) {
        payload.address_id = selectedAddressId;
      }

      var res, data;
      try {
        res = await fetch(quoteUrl, {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        data = await res.json();
      } catch (e) {
        clearSummary('Unable to load totals.');
        updateTotalsMirrors(0, tip);
        return;
      }

      if (!data) {
        clearSummary('Unable to load totals.');
        updateTotalsMirrors(0, tip);
        return;
      }

      // Expose last quote for DevTools verification (Option A)
      try {
        window.KNX_LAST_QUOTE = data;
        // NOTE: Do NOT set `KNX_LAST_SNAPSHOT` here. `checkout-payment-flow.js` is
        // the single source-of-truth for the snapshot used to create orders.
        // Leaving the snapshot global here created a race/mutation vector where
        // multiple scripts could overwrite the authority object and drop
        // delivery details. Payment flow will expose the snapshot when needed.
      } catch (e) {
        // ignore
      }

      // Some endpoints return {can_checkout:false,...} as 200-safe gate
      if (data.can_checkout === false) {
        clearSummary(data.message || 'Cannot proceed to checkout.');
        updateTotalsMirrors(0, tip);
        return;
      }

      if (!data.success || !data.totals || !data.totals.breakdown) {
        clearSummary((data && data.message) ? data.message : 'Unable to load totals.');
        updateTotalsMirrors(0, tip);
        return;
      }

      var b = data.totals.breakdown || {};
      var fees = Array.isArray(b.fees) ? b.fees : [];
      var discounts = Array.isArray(b.discounts) ? b.discounts : [];

      var tax = normalizeTax(b);

      summaryBody.innerHTML = '';

      summaryBody.appendChild(renderRow('Subtotal', money(b.subtotal), ''));

      // Discounts (show 0 or list)
      if (discounts.length) {
        discounts.forEach(function (d) {
          var codeTxt = d && d.code ? ' (' + d.code + ')' : '';
          var amt = Number((d && d.amount) ? d.amount : 0);
          summaryBody.appendChild(renderRow('Discount' + codeTxt, '-' + money(amt), ''));
        });
      } else {
        summaryBody.appendChild(renderRow('Discount', money(0), ''));
      }

      // Fees
      if (fees.length) {
        fees.forEach(function (f) {
          var label = (f && f.label) ? String(f.label) : 'Fee';
          var amt = Number((f && f.amount) ? f.amount : 0);
          summaryBody.appendChild(renderRow(label, money(amt), ''));
        });
      } else {
        summaryBody.appendChild(renderRow('Service Fee', money(0), ''));
      }

      summaryBody.appendChild(renderRow('Tip', money(b.tip || 0), ''));

      var taxLabel = (tax.rate > 0) ? ('Tax (' + tax.rate.toFixed(2) + '%)') : 'Tax';
      summaryBody.appendChild(renderRow(taxLabel, money(tax.amount || 0), ''));

      summaryBody.appendChild(renderRow('Total', money(b.total), 'total'));

      // Mirrors
      updateTotalsMirrors(b.total, (b.tip || 0));

      // Coupon UI feedback (best-effort)
      var currentCode = couponHidden ? String(couponHidden.value || '') : '';
      if (currentCode) {
        var matched = discounts.some(function (d) {
          return d && d.code && String(d.code).toUpperCase() === currentCode.toUpperCase();
        });

        if (matched) showCouponStatus('success', 'Promo applied: ' + currentCode.toUpperCase());
        else showCouponStatus('error', 'Promo not applied (validated in totals).');
      } else {
        showCouponStatus('', '');
      }
    }

    // ----------------------------
    // Tip: chips + custom mode
    // ----------------------------
    if (tipChips && tipChips.length) {
      tipChips.forEach(function (btn) {
        btn.addEventListener('click', function () {
          var mode = btn.getAttribute('data-tip-mode');
          var tipValAttr = btn.getAttribute('data-tip');

          // Custom mode
          if (mode === 'custom') {
            setActiveChip('custom');
            if (tipCustomWrap) tipCustomWrap.hidden = false;
            if (tipCustomInput) tipCustomInput.focus();
            setTipPill(Number(tipHidden ? tipHidden.value : 0), true);
            return;
          }

          // Preset / No tip
          var v = Number(tipValAttr || 0);
          if (!isFinite(v) || v < 0) v = 0;

          if (tipHidden) tipHidden.value = v.toFixed(2);

          // Hide custom UI when selecting preset/none
          if (tipCustomWrap) tipCustomWrap.hidden = true;
          if (tipCustomInput) tipCustomInput.value = '';

          if (mode === 'none' || v === 0) {
            setActiveChip('none');
            setTipPill(0, false);
          } else {
            setActiveChip('preset', v);
            setTipPill(v, false);
          }

          fetchQuote();
        });
      });
    }

    if (tipCustomApply) {
      tipCustomApply.addEventListener('click', function () {
        var v = tipCustomInput ? Number(tipCustomInput.value || 0) : 0;
        if (!isFinite(v) || v < 0) v = 0;

        if (tipHidden) tipHidden.value = v.toFixed(2);

        setActiveChip('custom');
        setTipPill(v, true);

        fetchQuote();
      });
    }

    if (tipCustomClear) {
      tipCustomClear.addEventListener('click', function () {
        if (tipHidden) tipHidden.value = '0.00';
        if (tipCustomInput) tipCustomInput.value = '';
        if (tipCustomWrap) tipCustomWrap.hidden = true;

        setActiveChip('none');
        setTipPill(0, false);

        fetchQuote();
      });
    }

    // ----------------------------
    // Promo apply
    // ----------------------------
    if (couponApplyBtn && couponInput && couponHidden) {
      couponApplyBtn.addEventListener('click', function () {
        var code = String(couponInput.value || '').trim().toUpperCase();

        if (!code) {
          couponHidden.value = '';
          showCouponStatus('error', 'Please enter a promo code.');
          fetchQuote();
          return;
        }

        couponHidden.value = code;
        showCouponStatus('', 'Checking promo…');
        fetchQuote();
      });
    }

    // ----------------------------
    // Sticky continue triggers main button
    // ----------------------------
    if (stickyContinueBtn) {
      stickyContinueBtn.addEventListener('click', function () {
        var mainBtn = document.getElementById('knxCoPlaceOrderBtn');
        if (mainBtn) mainBtn.click();
      });
    }

    // ----------------------------
    // Summary collapsible on mobile
    // ----------------------------
    function setCollapsed(collapsed) {
      if (!summaryCard || !summaryToggle) return;
      if (collapsed) {
        summaryCard.classList.add('is-collapsed');
        summaryToggle.setAttribute('aria-expanded', 'false');
      } else {
        summaryCard.classList.remove('is-collapsed');
        summaryToggle.setAttribute('aria-expanded', 'true');
      }
    }

    if (summaryToggle && summaryCard) {
      summaryToggle.addEventListener('click', function () {
        var isCollapsed = summaryCard.classList.contains('is-collapsed');
        setCollapsed(!isCollapsed);
      });

      // Default collapse on small screens
      if (window.matchMedia && window.matchMedia('(max-width: 640px)').matches) {
        setCollapsed(true);
      }
    }

    // ----------------------------
    // Fees panel toggle
    // ----------------------------
    if (feeToggle && feePanel) {
      feeToggle.addEventListener('click', function () {
        var expanded = feeToggle.getAttribute('aria-expanded') === 'true';
        feeToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');

        // The panel is rendered with `hidden` in the shortcode; toggle it explicitly.
        if (!expanded) {
          feePanel.hidden = false;
          feePanel.classList.add('show');
        } else {
          feePanel.classList.remove('show');
          feePanel.hidden = true;
        }
      });
    }

    // Initial state
    setTipPill(0, false);
    fetchQuote();

    // ----------------------------
    // Universal Collapse Toggle
    // ----------------------------
    var collapseToggles = root.querySelectorAll('.knx-collapse-toggle');
    if (collapseToggles && collapseToggles.length) {
      collapseToggles.forEach(function (toggle) {
        toggle.addEventListener('click', function () {
          var card = toggle.closest('.knx-is-collapsible');
          if (!card) return;

          var isOpen = card.classList.contains('is-open');
          if (isOpen) {
            card.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
          } else {
            card.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
          }
        });
      });
    }

    // ----------------------------
    // Dynamic Sticky Bar (hide when Place Order button is visible)
    // ----------------------------
    if (placeOrderBtn && stickyBar && 'IntersectionObserver' in window) {
      var observer = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              // Place Order button is visible, hide sticky bar
              stickyBar.classList.add('knx-hidden');
            } else {
              // Place Order button is not visible, show sticky bar
              stickyBar.classList.remove('knx-hidden');
            }
          });
        },
        {
          root: null,
          threshold: 0.1,
        }
      );

      observer.observe(placeOrderBtn);
    }
  });
})();

/* ==========================================================
 * KNX Stripe Runtime (A2 Step 2) - Card Element wiring (SSOT)
 * - Exposes window.KNX_STRIPE_RUNTIME for checkout-payment-flow.js
 * - Fail-closed: will not init or mount if config/key missing
 * ==========================================================
*/
(function () {
  'use strict';

  // Do not overwrite if already present
  if (window.KNX_STRIPE_RUNTIME) return;

  var runtime = {
    stripe: null,
    elements: null,
    card: null,
    mounted: false,

    init: function () {
      try {
        var cfg = window.KNX_CHECKOUT_CONFIG;
        if (!cfg) return { ok: false, error: 'Missing KNX_CHECKOUT_CONFIG' };

        if (!cfg.paymentsReady) return { ok: false, error: 'Payments not ready' };

        var key = String(cfg.publishableKey || '').trim();
        if (!key) return { ok: false, error: 'Publishable key missing' };

        if (typeof window.Stripe !== 'function') return { ok: false, error: 'Stripe.js not loaded' };

        // Initialize only once
        if (!runtime.stripe) {
          runtime.stripe = window.Stripe(key);
        }

        if (!runtime.elements && runtime.stripe && typeof runtime.stripe.elements === 'function') {
          runtime.elements = runtime.stripe.elements();
        }

        return { ok: true };
      } catch (err) {
        try { if (typeof error_log === 'function') error_log(String(err)); } catch (e) {}
        return { ok: false, error: String(err) };
      }
    },

    ensureMounted: function () {
      try {
        if (runtime.mounted) return true;

        var cfg = window.KNX_CHECKOUT_CONFIG || {};
        var mountId = (cfg.ui && cfg.ui.stripeMountId) ? cfg.ui.stripeMountId : 'knx-stripe-card-element';
        var errorsId = (cfg.ui && cfg.ui.stripeErrorsId) ? cfg.ui.stripeErrorsId : 'knx-stripe-card-errors';

        if (!runtime.elements) return false;

        var mountEl = document.getElementById(mountId);
        if (!mountEl) return false;

        // Create card element
        runtime.card = runtime.elements.create('card', { hidePostalCode: true });
        runtime.card.mount('#' + mountId);

        // card change events
        runtime.card.on('change', function (e) {
          if (e && e.error && e.error.message) {
            runtime.setError(e.error.message);
          } else {
            runtime.clearError();
          }
        });

        runtime.mounted = true;
        return true;
      } catch (err) {
        runtime.mounted = false;
        try { console.error && console.error(err); } catch (e) {}
        return false;
      }
    },

    getCard: function () {
      return runtime.card || null;
    },

    setError: function (msg) {
      try {
        var cfg = window.KNX_CHECKOUT_CONFIG || {};
        var errorsId = (cfg.ui && cfg.ui.stripeErrorsId) ? cfg.ui.stripeErrorsId : 'knx-stripe-card-errors';
        var el = document.getElementById(errorsId);
        if (!el) return false;
        el.textContent = String(msg || '');
        el.style.display = msg ? 'block' : 'none';
        return true;
      } catch (e) {
        return false;
      }
    },

    clearError: function () {
      return runtime.setError('');
    }
  };

  // Expose runtime
  window.KNX_STRIPE_RUNTIME = runtime;

  // Auto-init + mount if paymentsReady
  document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.KNX_CHECKOUT_CONFIG;
    if (!cfg) return;
    if (cfg.paymentsReady) {
      var r = runtime.init();
      if (r && r.ok) {
        // attempt mount; ignoring result is fine (ensureMounted returns boolean)
        runtime.ensureMounted();
      }
    }
  });

})();