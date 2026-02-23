/**
 * KNX Driver — View Order Script (DB-canon)
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('knxDriverViewOrderApp');
    if (!app) return;

    const orderId = parseInt(app.dataset.orderId, 10) || 0;
    const driverProfileId = parseInt(app.dataset.driverProfileId, 10) || 0;
    const statusChangeUrl = app.dataset.statusChangeUrl || '';
    const releaseUrl = app.dataset.releaseUrl || '';
    const knxNonce = app.dataset.knxNonce || '';
    const backUrl = app.dataset.backUrl || '/driver-active-orders';

    const stateNode = document.getElementById('knxDriverVOState');
    const contentNode = document.getElementById('knxDriverVOContent');

    if (!orderId || orderId <= 0) {
      if (stateNode) stateNode.textContent = 'Invalid order_id';
      return;
    }

    const STATUS_LABELS = {
      confirmed: 'Order Created',
      accepted_by_driver: 'Accepted by Driver',
      accepted_by_hub: 'Accepted by Hub',
      preparing: 'Preparing',
      prepared: 'Prepared',
      picked_up: 'Picked Up',
      completed: 'Completed',
      cancelled: 'Cancelled',
    };

    const TRANSITIONS = {
      confirmed: ['accepted_by_driver', 'cancelled'],
      accepted_by_driver: ['accepted_by_hub', 'cancelled'],
      accepted_by_hub: ['preparing', 'cancelled'],
      preparing: ['prepared', 'cancelled'],
      prepared: ['picked_up', 'cancelled'],
      picked_up: ['completed'],
      completed: [],
      cancelled: [],
    };

    function normalizeStatus(status) {
      const st = String(status || '').trim().toLowerCase();
      if (st === 'placed') return 'confirmed';
      if (st === 'accepted_by_restaurant') return 'accepted_by_hub';
      if (st === 'out_for_delivery') return 'picked_up';
      if (st === 'ready') return 'prepared';
      return st;
    }

    function statusLabel(status) {
      const st = normalizeStatus(status);
      return STATUS_LABELS[st] || st.replace(/_/g, ' ').replace(/\b\w/g, m => m.toUpperCase());
    }

    function statusChipClass(status) {
      const st = normalizeStatus(status);
      if (st === 'confirmed') return 'is-new';
      if (st === 'completed') return 'is-done';
      if (st === 'cancelled') return 'is-cancelled';
      return 'is-progress';
    }

    function getAllowedTransitions(currentStatus) {
      const st = normalizeStatus(currentStatus);
      return TRANSITIONS[st] || [];
    }

    function escapeHtml(str) {
      return String(str).replace(/[&<>"]/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[s]));
    }

    async function fetchOrder() {
      if (stateNode) stateNode.textContent = 'Loading order…';

      try {
        const apiUrl = `${window.location.origin}/wp-json/knx/v2/driver/orders/active?limit=200`;
        const res = await fetch(apiUrl, {
          method: 'GET',
          credentials: 'include',
        });

        const json = await res.json();

        if (!json || !json.success) {
          if (stateNode) stateNode.textContent = 'Failed to load order';
          return;
        }

        const orders = json.data?.orders || json.orders || [];
        const order = orders.find(o => parseInt(o.id, 10) === orderId);

        if (!order) {
          if (stateNode) stateNode.textContent = 'Order not found or not assigned to you';
          if (contentNode) contentNode.innerHTML = '<p style="text-align:center;color:var(--nxs-muted);">This order is not available.</p>';
          return;
        }

        renderOrder(order);

      } catch (err) {
        console.error('[fetchOrder] exception:', err);
        if (stateNode) stateNode.textContent = 'Network error';
      }
    }

    function renderOrder(order) {
      if (stateNode) stateNode.textContent = '';

      const statusClass = statusChipClass(order.status);
      const statusText = statusLabel(order.status);

      const allowedTransitions = getAllowedTransitions(order.status);
      const canChangeStatus = allowedTransitions.length > 0;
      const canRelease = (normalizeStatus(order.status) !== 'completed' && normalizeStatus(order.status) !== 'cancelled');

      const html = `
        <div class="knx-driver-vo__section">
          <h3 class="knx-driver-vo__section-title">Order Information</h3>
          <div class="knx-driver-vo__row">
            <div class="knx-driver-vo__row-label">Order Number</div>
            <div class="knx-driver-vo__row-value">#${escapeHtml(order.order_number || order.id)}</div>
          </div>
          <div class="knx-driver-vo__row">
            <div class="knx-driver-vo__row-label">Status</div>
            <div class="knx-driver-vo__row-value">
              <span class="knx-driver-vo__status-chip ${statusClass}">${escapeHtml(statusText)}</span>
            </div>
          </div>
          <div class="knx-driver-vo__row">
            <div class="knx-driver-vo__row-label">Total</div>
            <div class="knx-driver-vo__row-value">$${parseFloat(order.total || 0).toFixed(2)}</div>
          </div>
          <div class="knx-driver-vo__row">
            <div class="knx-driver-vo__row-label">Created</div>
            <div class="knx-driver-vo__row-value">${escapeHtml(order.created_at || 'N/A')}</div>
          </div>
        </div>

        <div class="knx-driver-vo__section">
          <h3 class="knx-driver-vo__section-title">Customer</h3>
          <div class="knx-driver-vo__row">
            <div class="knx-driver-vo__row-label">Name</div>
            <div class="knx-driver-vo__row-value">${escapeHtml(order.customer_name || 'N/A')}</div>
          </div>
          <div class="knx-driver-vo__row">
            <div class="knx-driver-vo__row-label">Phone</div>
            <div class="knx-driver-vo__row-value">${escapeHtml(order.customer_phone || 'N/A')}</div>
          </div>
        </div>

        <div class="knx-driver-vo__section">
          <h3 class="knx-driver-vo__section-title">Delivery</h3>
          <div class="knx-driver-vo__row">
            <div class="knx-driver-vo__row-label">Address</div>
            <div class="knx-driver-vo__row-value">${escapeHtml(order.delivery_address || 'N/A')}</div>
          </div>
        </div>

        <div class="knx-driver-vo__actions">
          ${canChangeStatus ? `<button type="button" class="knx-driver-vo__btn knx-driver-vo__btn--primary" id="knxDriverVOChangeStatus">Change Status</button>` : ''}
          ${canRelease ? `<button type="button" class="knx-driver-vo__btn knx-driver-vo__btn--danger" id="knxDriverVORelease">Release Order</button>` : ''}
          <button type="button" class="knx-driver-vo__btn" id="knxDriverVOBack">Back to Orders</button>
        </div>
      `;

      if (contentNode) {
        contentNode.innerHTML = html;

        // Attach event listeners
        const changeStatusBtn = document.getElementById('knxDriverVOChangeStatus');
        if (changeStatusBtn) {
          changeStatusBtn.addEventListener('click', () => handleChangeStatus(order));
        }

        const releaseBtn = document.getElementById('knxDriverVORelease');
        if (releaseBtn) {
          releaseBtn.addEventListener('click', () => handleRelease(order));
        }

        const backBtn = document.getElementById('knxDriverVOBack');
        if (backBtn) {
          backBtn.addEventListener('click', () => {
            window.location.href = backUrl;
          });
        }
      }
    }

    function handleChangeStatus(order) {
      const allowed = getAllowedTransitions(order.status);
      if (allowed.length === 0) {
        alert('No status changes available');
        return;
      }

      const options = allowed.map(st => `${st}: ${statusLabel(st)}`).join('\n');
      const choice = prompt(`Current: ${statusLabel(order.status)}\n\nSelect new status:\n${options}\n\nEnter status code:`);
      if (!choice) return;

      const newStatus = choice.trim().toLowerCase();
      if (!allowed.includes(newStatus)) {
        alert('Invalid status');
        return;
      }

      changeStatus(order.id, newStatus);
    }

    async function changeStatus(orderId, newStatus) {
      try {
        const url = `${statusChangeUrl}/${orderId}/ops-status`;
        const res = await fetch(url, {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            knx_nonce: knxNonce,
            status: newStatus,
          }),
        });

        const json = await res.json();

        if (json && json.success) {
          alert(`Status updated to ${statusLabel(newStatus)}`);
          fetchOrder(); // refresh
        } else {
          alert(json?.data?.message || 'Failed to update status');
        }

      } catch (err) {
        console.error('[changeStatus] exception:', err);
        alert('Network error');
      }
    }

    function handleRelease(order) {
      if (!confirm(`Are you sure you want to release order #${order.order_number || order.id}?`)) {
        return;
      }

      releaseOrder(order.id);
    }

    async function releaseOrder(orderId) {
      try {
        const url = `${releaseUrl}/${orderId}/release`;
        const res = await fetch(url, {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            knx_nonce: knxNonce,
          }),
        });

        const json = await res.json();

        if (json && json.success) {
          alert('Order released successfully');
          window.location.href = backUrl;
        } else {
          alert(json?.data?.message || 'Failed to release order');
        }

      } catch (err) {
        console.error('[releaseOrder] exception:', err);
        alert('Network error');
      }
    }

    // Initialize
    fetchOrder();
  });
})();
