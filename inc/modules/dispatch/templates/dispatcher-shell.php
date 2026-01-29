<?php
if (!defined('ABSPATH')) exit;

// Basic responsive dispatcher shell (markup only). JS will populate lists.
?>
<div class="knx-dispatcher-layout">
  <aside class="knx-dispatcher-sidebar" id="knx-dispatcher-sidebar">
    <div class="knx-dp-sidebar-header">
      <h3>Dispatcher</h3>
      <button class="knx-dp-sidebar-toggle" aria-label="Toggle sidebar">☰</button>
    </div>

    <div class="knx-dp-filters">
      <label>Role (prototype):</label>
      <select id="knx-dp-role-select">
        <option value="none">None</option>
        <option value="manager">Manager</option>
        <option value="super_admin">Super Admin</option>
      </select>

      <label>City (prototype):</label>
      <input type="text" id="knx-dp-city" placeholder="City slug or id">

      <label>Search</label>
      <input type="search" id="knx-dp-search" placeholder="Search orders">

      <div class="knx-dp-controls">
        <button id="knx-dp-refresh">Refresh</button>
        <button id="knx-dp-live" role="switch" aria-checked="false">Live ⚡</button>
      </div>
    </div>
  </aside>

  <main class="knx-dispatcher-main">
    <header class="knx-dp-main-header">
      <div class="knx-dp-tabs" role="tablist">
        <button role="tab" aria-selected="true" data-status="new" class="knx-dp-tab">New <span class="knx-badge">0</span></button>
        <button role="tab" aria-selected="false" data-status="in_progress" class="knx-dp-tab">In Progress <span class="knx-badge">0</span></button>
        <button role="tab" aria-selected="false" data-status="completed" class="knx-dp-tab">Completed <span class="knx-badge">0</span></button>
      </div>

      <div class="knx-dp-header-meta">
        <div class="knx-dp-search-meta">Showing: <span id="knx-dp-current-filter">New</span></div>
      </div>
    </header>

    <section class="knx-dp-list" id="knx-dp-list" aria-live="polite">
      <!-- Placeholder rows rendered by dispatcher.js -->
      <div class="knx-dp-placeholder">Loading dispatcher prototype...</div>
    </section>
  </main>
</div>

<noscript>
  <p>Please enable JavaScript to use the dispatcher prototype.</p>
</noscript>
