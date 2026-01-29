// Dispatcher UI skeleton script (no backend wiring). Vanilla JS.
(function(){
  function qs(sel, ctx){ return (ctx||document).querySelector(sel); }
  function qsa(sel, ctx){ return (ctx||document).querySelectorAll(sel); }

  document.addEventListener('DOMContentLoaded', function(){
    const root = qs('#knx-dispatcher');
    if (!root) return;

    const sidebar = qs('#knx-dispatcher-sidebar');
    const toggle = qs('.knx-dp-sidebar-toggle');
    const roleSelect = qs('#knx-dp-role-select');
    const cityInput = qs('#knx-dp-city');
    const searchInput = qs('#knx-dp-search');
    const refreshBtn = qs('#knx-dp-refresh');
    const liveBtn = qs('#knx-dp-live');
    const tabs = qsa('.knx-dp-tab');
    const list = qs('#knx-dp-list');
    const filterLabel = qs('#knx-dp-current-filter');

    let activeStatus = 'new';
    let live = false;
    let pollInterval = parseInt(root.dataset.pollInterval || 10000, 10);
    let pollTimer = null;

    function renderRow(o){
      const el = document.createElement('div');
      el.className = 'knx-dp-row';
      el.innerHTML = '<div><strong>' + o.id + '</strong><div class="meta">' + o.summary + '</div></div>' +
                     '<div class="meta">' + new Date(o.created).toLocaleTimeString() + '</div>';
      return el;
    }

    function setBadges(counts){
      tabs.forEach(t => {
        const status = t.dataset.status;
        const badge = t.querySelector('.knx-badge');
        badge.textContent = counts[status] || 0;
      });
    }

    function showPlaceholder(msg){
      list.innerHTML = '<div class="knx-dp-placeholder">' + msg + '</div>';
    }

    function fetchAndRender(){
      showPlaceholder('Loading...');
      const opts = { status: activeStatus, city: cityInput.value.trim(), search: searchInput.value.trim() };
      if (window.knxDispatcherMock && typeof window.knxDispatcherMock.fetchOrders === 'function'){
        window.knxDispatcherMock.fetchOrders(opts).then(resp => {
          if (!resp || !resp.success){ showPlaceholder('Error fetching mock data'); return; }
          const orders = resp.data.orders || [];
          list.innerHTML = '';
          if (!orders.length) { showPlaceholder('No orders in this view'); }
          orders.forEach(o => list.appendChild(renderRow(o)));

          // compute counts for tabs (simple: reuse sample fetch without status filter)
          Promise.all(['new','in_progress','completed'].map(s => window.knxDispatcherMock.fetchOrders({status:s, city: cityInput.value.trim()}))).then(results=>{
            const counts = {};
            ['new','in_progress','completed'].forEach((s,i)=> counts[s] = results[i].data.total || 0);
            setBadges(counts);
          });
        }).catch(()=> showPlaceholder('Error (mock)'));
      } else {
        showPlaceholder('Mock adapter not loaded');
      }
    }

    // Tabs
    tabs.forEach(t => t.addEventListener('click', function(){
      tabs.forEach(x=> x.setAttribute('aria-selected','false'));
      this.setAttribute('aria-selected','true');
      activeStatus = this.dataset.status;
      filterLabel.textContent = this.textContent.trim();
      fetchAndRender();
    }));

    // Controls
    toggle && toggle.addEventListener('click', function(){ sidebar.classList.toggle('open'); });
    refreshBtn && refreshBtn.addEventListener('click', function(){ fetchAndRender(); });
    liveBtn && liveBtn.addEventListener('click', function(){
      live = !live; this.setAttribute('aria-checked', String(live));
      this.style.boxShadow = live ? '0 0 0 4px rgba(34,197,94,0.12)' : 'none';
      if (live){ pollTimer = setInterval(fetchAndRender, pollInterval); } else { clearInterval(pollTimer); pollTimer = null; }
    });

    roleSelect && roleSelect.addEventListener('change', function(){ root.dataset.role = this.value; });
    cityInput && cityInput.addEventListener('change', function(){ root.dataset.city = this.value; fetchAndRender(); });
    searchInput && searchInput.addEventListener('input', debounce(()=> fetchAndRender(), 250));

    function debounce(fn, wait){ let t; return function(){ clearTimeout(t); t = setTimeout(fn, wait); }; }

    // initial render
    fetchAndRender();
  });
})();
