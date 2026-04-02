/**
 * ==========================================================
 * KNX Hub Managers — Admin JS Controller
 * ----------------------------------------------------------
 * Handles: List managers, create user, assign/unassign hubs,
 *          delete users (super_admin only)
 * ==========================================================
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const wrap = document.querySelector('.knx-managers-wrap');
        if (!wrap) return;

        const state = {
            nonce: wrap.dataset.nonce,
            wpNonce: wrap.dataset.wpNonce,
            apiList: wrap.dataset.apiList,
            apiCreate: wrap.dataset.apiCreate,
            apiAssign: wrap.dataset.apiAssign,
            apiUnassign: wrap.dataset.apiUnassign,
            apiDelete: wrap.dataset.apiDelete,
            isSuperAdmin: wrap.dataset.isSuperAdmin === '1',
            managers: [],
        };

        // Elements
        const container = document.getElementById('managersTableContainer');
        const btnCreateManager = document.getElementById('btnCreateManager');
        const modalCreate = document.getElementById('modalCreateManager');
        const modalAssign = document.getElementById('modalAssignHub');
        const modalDelete = document.getElementById('modalConfirmDelete');
        const formCreate = document.getElementById('formCreateManager');
        const formAssign = document.getElementById('formAssignHub');

        // Load managers on init
        loadManagers();

        // Event: Open create modal
        btnCreateManager.addEventListener('click', () => {
            formCreate.reset();
            modalCreate.classList.add('active');
        });

        // Event: Cancel create
        document.getElementById('btnCancelCreate').addEventListener('click', () => {
            modalCreate.classList.remove('active');
        });

        // Event: Cancel assign
        document.getElementById('btnCancelAssign').addEventListener('click', () => {
            modalAssign.classList.remove('active');
        });

        // Event: Cancel delete
        document.getElementById('btnCancelDelete').addEventListener('click', () => {
            modalDelete.classList.remove('active');
        });

        // Event: Submit create form
        formCreate.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitCreate');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';

            const data = {
                knx_nonce: state.nonce,
                name: document.getElementById('newManagerName').value.trim(),
                username: document.getElementById('newManagerUsername').value.trim(),
                email: document.getElementById('newManagerEmail').value.trim(),
                password: document.getElementById('newManagerPassword').value,
                hub_id: document.getElementById('newManagerHub').value || 0,
            };

            try {
                const res = await postJSON(state.apiCreate, data, state.wpNonce);
                if (res.success) {
                    toast('success', 'Hub manager created successfully');
                    modalCreate.classList.remove('active');
                    formCreate.reset();
                    loadManagers();
                } else {
                    toast('error', res.message || res.error || 'Failed to create user');
                }
            } catch (err) {
                toast('error', 'Network error');
                console.error(err);
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Create Manager';
        });

        // Event: Submit assign form
        formAssign.addEventListener('submit', async (e) => {
            e.preventDefault();
            const userId = document.getElementById('assignUserId').value;
            const hubId = document.getElementById('assignHubSelect').value;

            if (!hubId) {
                toast('error', 'Please select a hub');
                return;
            }

            try {
                const res = await postJSON(state.apiAssign, {
                    knx_nonce: state.nonce,
                    user_id: userId,
                    hub_id: hubId,
                }, state.wpNonce);

                if (res.success) {
                    toast('success', 'Hub assigned successfully');
                    modalAssign.classList.remove('active');
                    loadManagers();
                } else {
                    toast('error', res.message || res.error || 'Failed to assign hub');
                }
            } catch (err) {
                toast('error', 'Network error');
            }
        });

        // Event: Confirm delete
        document.getElementById('btnConfirmDelete').addEventListener('click', async () => {
            const userId = document.getElementById('deleteUserId').value;
            const btn = document.getElementById('btnConfirmDelete');
            btn.disabled = true;

            try {
                const res = await fetch(state.apiDelete, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': state.wpNonce,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        knx_nonce: state.nonce,
                        user_id: userId,
                    }),
                });
                const json = await res.json();

                if (json.success) {
                    toast('success', 'User deleted');
                    modalDelete.classList.remove('active');
                    loadManagers();
                } else {
                    toast('error', json.message || json.error || 'Delete failed');
                }
            } catch (err) {
                toast('error', 'Network error');
            }

            btn.disabled = false;
        });

        // Close modals on backdrop click
        [modalCreate, modalAssign, modalDelete].forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });

        // Function: Load managers
        async function loadManagers() {
            container.innerHTML = `
                <div class="knx-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading managers...</p>
                </div>
            `;

            try {
                const res = await fetch(state.apiList, {
                    headers: { 'X-WP-Nonce': state.wpNonce },
                    credentials: 'same-origin',
                });
                const json = await res.json();

                if (json.success && json.data) {
                    state.managers = json.data.managers || [];
                    renderTable(state.managers);
                } else {
                    container.innerHTML = `<div class="knx-empty"><i class="fas fa-exclamation-circle"></i><p>Failed to load managers</p></div>`;
                }
            } catch (err) {
                container.innerHTML = `<div class="knx-empty"><i class="fas fa-exclamation-circle"></i><p>Network error</p></div>`;
                console.error(err);
            }
        }

        // Function: Render table
        function renderTable(managers) {
            if (!managers.length) {
                container.innerHTML = `
                    <div class="knx-empty">
                        <i class="fas fa-user-tie"></i>
                        <p>No hub managers yet. Create one to get started.</p>
                    </div>
                `;
                return;
            }

            let html = `
                <table class="knx-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Username</th>
                            <th>Assigned Hubs</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            managers.forEach(m => {
                const statusClass = m.status === 'active' ? 'knx-badge-green' : 'knx-badge-gray';
                const hubPills = m.hub_ids.length
                    ? m.hub_ids.map((hubId, i) => {
                        const hubName = m.hub_names.split(', ')[i] || `Hub #${hubId}`;
                        return `
                            <span class="knx-hub-pill">
                                ${escHtml(hubName)}
                                <i class="fas fa-times remove-hub" data-user-id="${m.user_id}" data-hub-id="${hubId}" title="Remove"></i>
                            </span>
                        `;
                    }).join('')
                    : '<span class="knx-badge knx-badge-gray">No hubs</span>';

                html += `
                    <tr>
                        <td>
                            <div class="knx-user-info">
                                <span class="knx-user-name">${escHtml(m.name)}</span>
                                <span class="knx-user-email">${escHtml(m.email)}</span>
                            </div>
                        </td>
                        <td><code>${escHtml(m.username)}</code></td>
                        <td>
                            <div class="knx-hubs-pills">${hubPills}</div>
                        </td>
                        <td><span class="knx-badge ${statusClass}">${escHtml(m.status)}</span></td>
                        <td>
                            <div class="knx-actions">
                                <button class="knx-btn knx-btn-secondary knx-btn-sm btn-assign-hub" 
                                        data-user-id="${m.user_id}" 
                                        data-user-name="${escAttr(m.name)}">
                                    <i class="fas fa-link"></i> Assign Hub
                                </button>
                                ${state.isSuperAdmin ? `
                                <button class="knx-btn knx-btn-danger knx-btn-sm btn-delete-user" 
                                        data-user-id="${m.user_id}" 
                                        data-user-name="${escAttr(m.name)}">
                                    <i class="fas fa-trash"></i>
                                </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            });

            html += '</tbody></table>';
            container.innerHTML = html;

            // Attach events to buttons
            container.querySelectorAll('.btn-assign-hub').forEach(btn => {
                btn.addEventListener('click', () => {
                    const userId = btn.dataset.userId;
                    const userName = btn.dataset.userName;
                    document.getElementById('assignUserId').value = userId;
                    document.getElementById('assignUserInfo').textContent = `Assigning hub to: ${userName}`;
                    document.getElementById('assignHubSelect').value = '';
                    modalAssign.classList.add('active');
                });
            });

            container.querySelectorAll('.btn-delete-user').forEach(btn => {
                btn.addEventListener('click', () => {
                    const userId = btn.dataset.userId;
                    const userName = btn.dataset.userName;
                    document.getElementById('deleteUserId').value = userId;
                    document.getElementById('deleteUserInfo').innerHTML = 
                        `Are you sure you want to delete <strong>${escHtml(userName)}</strong>?<br>All hub assignments will be removed.`;
                    modalDelete.classList.add('active');
                });
            });

            // Attach events to remove hub pills
            container.querySelectorAll('.remove-hub').forEach(icon => {
                icon.addEventListener('click', async () => {
                    const userId = icon.dataset.userId;
                    const hubId = icon.dataset.hubId;

                    if (!confirm('Remove this hub assignment?')) return;

                    try {
                        const res = await postJSON(state.apiUnassign, {
                            knx_nonce: state.nonce,
                            user_id: userId,
                            hub_id: hubId,
                        }, state.wpNonce);

                        if (res.success) {
                            toast('success', 'Hub removed');
                            loadManagers();
                        } else {
                            toast('error', res.message || 'Failed to remove hub');
                        }
                    } catch (err) {
                        toast('error', 'Network error');
                    }
                });
            });
        }
    }

    // Helpers
    async function postJSON(url, data, wpNonce) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpNonce,
            },
            credentials: 'same-origin',
            body: JSON.stringify(data),
        });
        return res.json();
    }

    function toast(type, msg) {
        if (typeof window.knxToast === 'function') {
            window.knxToast(msg, type);
        } else if (typeof window.KnxToast !== 'undefined' && typeof window.KnxToast.show === 'function') {
            window.KnxToast.show(msg, type);
        } else {
            alert(msg);
        }
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function escAttr(s) {
        return (s || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
})();
