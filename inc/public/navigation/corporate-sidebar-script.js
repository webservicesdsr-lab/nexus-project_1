/**
 * KINGDOM NEXUS — CORPORATE SIDEBAR SCRIPT
 * PURPOSE:
 * - Mobile expand/collapse toggle
 * - Persist state in sessionStorage
 * 
 * [KNX-NAV-CORPORATE]
 */

(function() {
    'use strict';

    const sidebar = document.getElementById('knxCorporateSidebar');
    const expandBtn = document.getElementById('knxExpandMobile');

    if (!sidebar || !expandBtn) return;

    // Restore state from sessionStorage
    const isExpanded = sessionStorage.getItem('knx_corporate_sidebar_expanded') === 'true';
    if (isExpanded) {
        sidebar.classList.add('expanded');
        document.body.classList.add('knx-sidebar-expanded');
    }

    // Toggle on button click
    expandBtn.addEventListener('click', function() {
        sidebar.classList.toggle('expanded');
        const expanded = sidebar.classList.contains('expanded');
        document.body.classList.toggle('knx-sidebar-expanded', expanded);
        sessionStorage.setItem('knx_corporate_sidebar_expanded', expanded);
    });

    console.log('[KNX-CORPORATE] Sidebar initialized');
})();
