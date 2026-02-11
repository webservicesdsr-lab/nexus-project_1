(function(){
  'use strict';
  function qs(name){
    try{
      var u = new URL(window.location.href);
      return u.searchParams.get(name);
    }catch(e){
      return null;
    }
  }

  function el(id){return document.getElementById(id);}

  function renderError(msg){
    var box = el('knxOrderStatusBox');
    if(!box) return;
    box.innerHTML = '<div class="knx-order-status-error">'+String(msg)+'</div>';
  }

  function renderOrder(o){
    var box = el('knxOrderStatusBox');
    if(!box) return;
    var html = '';
    html += '<div class="knx-order-meta">';
    html += '<p><strong>Order ID:</strong> ' + (o.order_id || '') + '</p>';
    var statusRaw = (o.status || o.order_status || '').toString();
    var statusKey = statusRaw.toLowerCase().replace(/[^a-z0-9]+/g,'-');
    var pill = '<span class="knx-status-pill knx-status--' + statusKey + '">' + statusRaw + '</span>';
    html += '<p><strong>Status:</strong> ' + pill + '</p>';
    html += '<p><strong>Placed:</strong> ' + (o.created_at || '') + '</p>';
    html += '</div>';

    if(o.cart_snapshot){
      html += '<h3>Items</h3><ul class="knx-order-items">';
      var cs = o.cart_snapshot;
      var items = (cs.items || cs.cart_items || []);
      items.forEach(function(it){
        var qty = it.quantity || it.qty || it.quantity || 1;
        html += '<li><span class="knx-item-name">' + (it.name || it.item_name || 'Item') + '</span>';
        html += '<span class="knx-item-qty">× ' + qty + '</span></li>';
      });
      html += '</ul>';
    }

    if(o.status_history && Array.isArray(o.status_history)){
      html += '<h3>Status History</h3><ol class="knx-order-history">';
      o.status_history.forEach(function(h){
        var s = (h.status||'').toString();
        var k = s.toLowerCase().replace(/[^a-z0-9]+/g,'-');
        html += '<li><strong class="knx-status-pill knx-status--' + k + '">' + s + '</strong> <span class="knx-quiet">— ' + (h.created_at || '') + '</span></li>';
      });
      html += '</ol>';
    }

    box.innerHTML = html;
  }

  function fetchOrder(id){
    if(!id) { renderError('Order id missing'); return; }
    var url = '/wp-json/knx/v1/orders/' + encodeURIComponent(id);
    fetch(url, { method: 'GET', credentials: 'include' })
      .then(function(r){ return r.json().catch(function(){ return null; }); })
      .then(function(raw){
        if(!raw) { renderError('Invalid response'); return; }
        if(raw.success !== true){ renderError(raw.error || raw.message || 'Unable to fetch order'); return; }
        // The API returns order as `order` field
        var o = raw.order || raw.data || raw;
        renderOrder(o);
      })
      .catch(function(){ renderError('Network error'); });
  }

  document.addEventListener('DOMContentLoaded', function(){
    var id = qs('order_id');
    if(!id){
      // Try localStorage pending order
      try{
        var pending = JSON.parse(localStorage.getItem('knx_pending_payment') || 'null');
        if(pending && pending.order_id) id = pending.order_id;
      }catch(e){}
    }
    if(id){ fetchOrder(id); }
    else{ renderError('Order id missing in URL.'); }
  });
})();
