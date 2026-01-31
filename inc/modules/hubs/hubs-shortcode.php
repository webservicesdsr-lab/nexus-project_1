<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Hubs Shortcode (v3.9)
 * ----------------------------------------------------------
 * ✅ REST-only CRUD (Add + Toggle)
 * ✅ Unified global knxToast() system
 * ✅ Preserves all Add/Toggle/Search/Pagination logic
 * ✅ Fully backward compatible with v3.8
 * ==========================================================
 */

add_shortcode('knx_hubs', function() {
    global $wpdb;

    /** Validate session and roles */
    $session = knx_get_session();
    if (!$session || !in_array($session->role, ['manager', 'super_admin', 'hub_management', 'menu_uploader'])) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    /** Pagination and search setup */
    $table     = $wpdb->prefix . 'knx_hubs';
    $per_page  = 10;
    $page      = get_query_var('paged') ? intval(get_query_var('paged')) : (isset($_GET['paged']) ? intval($_GET['paged']) : 1);
    $page      = max(1, $page);
    $offset    = ($page - 1) * $per_page;
    $search    = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

    /** Search filter */
    $where = '';
    $params = [];
    if ($search) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where = "WHERE name LIKE %s OR email LIKE %s OR phone LIKE %s";
        $params = [$like, $like, $like];
    }

    /** Fetch hubs */
    $query = "SELECT * FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d";
    $prepared = !empty($params)
        ? $wpdb->prepare($query, ...array_merge($params, [$per_page, $offset]))
        : $wpdb->prepare($query, $per_page, $offset);
    $hubs = $wpdb->get_results($prepared);

    /** Pagination count */
    $total_query = "SELECT COUNT(*) FROM $table $where";
    $total = !empty($params)
        ? $wpdb->get_var($wpdb->prepare($total_query, ...$params))
        : $wpdb->get_var($total_query);
    $pages = ceil(max(1, $total) / $per_page);

    /** Nonces for REST */
    $nonce_add    = wp_create_nonce('knx_add_hub_nonce');
    $nonce_toggle = wp_create_nonce('knx_toggle_hub_nonce');

    ob_start(); ?>

    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/hubs-style.css'); ?>">

    <div class="knx-hubs-wrapper"
        data-api-add="<?php echo esc_url(rest_url('knx/v1/add-hub')); ?>"
        data-api-toggle="<?php echo esc_url(rest_url('knx/v1/toggle-hub')); ?>"
        data-nonce-add="<?php echo esc_attr($nonce_add); ?>"
        data-nonce-toggle="<?php echo esc_attr($nonce_toggle); ?>">

        <div class="knx-hubs-header">
            <h2>Hubs Management</h2>

            <div class="knx-hubs-controls">
                <form method="get" class="knx-search-form">
                    <input type="hidden" name="page_id" value="<?php echo get_the_ID(); ?>">
                    <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="Search hubs...">
                    <button type="submit"><i class="fas fa-search"></i></button>
                </form>
                <button id="knxAddHubBtn" class="knx-add-btn"><i class="fas fa-plus"></i> Add Hub</button>
            </div>
        </div>

        <table class="knx-hubs-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Edit</th>
                    <th>Toggle</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($hubs): foreach ($hubs as $hub): ?>
                    <tr data-id="<?php echo esc_attr($hub->id); ?>">
                        <td>
                            <div class="knx-hub-identity" style="display: flex; align-items: center; gap: 12px;">
                                <?php if (!empty($hub->logo_url)): ?>
                                    <img class="knx-hub-thumb" src="<?php echo esc_url($hub->logo_url); ?>" alt="<?php echo esc_attr(stripslashes($hub->name)); ?>">
                                <?php endif; ?>
                                <div class="knx-hub-name-wrap">
                                    <div class="knx-hub-name"><?php echo esc_html(stripslashes($hub->name)); ?></div>
                                </div>
                                <?php if (($hub->is_featured ?? 0) == 1): ?>
                                    <span class="knx-badge--featured" title="Featured in Locals Love These" aria-hidden="false">
                                        <i class="fas fa-star" aria-hidden="true"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo esc_html($hub->phone); ?></td>
                        <td>
                            <span class="status-<?php echo esc_attr($hub->status); ?>">
                                <?php echo ucfirst($hub->status); ?>
                            </span>
                        </td>
                        <td class="knx-edit-cell">
                            <a href="<?php echo esc_url(site_url('/edit-hub?id=' . $hub->id)); ?>" class="knx-edit-link" title="Edit Hub">
                                <i class="fas fa-pen"></i>
                            </a>
                        </td>
                        <td>
                            <label class="knx-switch">
                                <input type="checkbox" class="knx-toggle-hub" <?php checked($hub->status, 'active'); ?>>
                                <span class="knx-slider"></span>
                            </label>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" style="text-align:center;">No hubs found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($pages > 1): ?>
            <div class="knx-pagination">
                <?php
                $base_url = remove_query_arg('paged');
                if ($search) $base_url = add_query_arg('search', urlencode($search), $base_url);

                if ($page > 1) echo '<a href="' . esc_url(add_query_arg('paged', $page - 1, $base_url)) . '">&laquo; Prev</a>';
                for ($i = 1; $i <= $pages; $i++) {
                    $active = $i == $page ? 'active' : '';
                    echo '<a href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '" class="' . $active . '">' . $i . '</a>';
                }
                if ($page < $pages) echo '<a href="' . esc_url(add_query_arg('paged', $page + 1, $base_url)) . '">Next &raquo;</a>';
                ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal: Add Hub -->
    <div id="knxAddHubModal" class="knx-modal">
        <div class="knx-modal-content">
            <h3>Add Hub</h3>
            <form id="knxAddHubForm">
                <input type="text" name="name" placeholder="Hub Name" required>
                <input type="text" name="phone" placeholder="Phone Number">
                <input type="email" name="email" placeholder="Email Address" required>
                <button type="submit" class="knx-btn">Save</button>
                <button type="button" id="knxCloseModal" class="knx-btn-secondary">Cancel</button>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const wrapper = document.querySelector('.knx-hubs-wrapper');
        const apiAdd = wrapper.dataset.apiAdd;
        const apiToggle = wrapper.dataset.apiToggle;
        const nonceAdd = wrapper.dataset.nonceAdd;
        const nonceToggle = wrapper.dataset.nonceToggle;

        // --- Add Hub Modal ---
        const modal = document.getElementById('knxAddHubModal');
        const openBtn = document.getElementById('knxAddHubBtn');
        const closeBtn = document.getElementById('knxCloseModal');
        const form = document.getElementById('knxAddHubForm');
        const firstInput = form?.querySelector('input[name="name"]');

        const openModal = () => {
            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('knx-modal-open');
            document.body.style.overflow = 'hidden';
            setTimeout(() => firstInput?.focus(), 120);
        };

        const closeModal = () => {
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('knx-modal-open');
            document.body.style.overflow = '';
            openBtn?.focus();
        };

        openBtn.addEventListener('click', openModal);
        closeBtn.addEventListener('click', closeModal);

        // close on ESC
        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') {
                if (modal.classList.contains('active')) closeModal();
            }
        });

        form.addEventListener('submit', async e => {
            e.preventDefault();
            const data = new FormData(form);
            data.append('knx_nonce', nonceAdd);

            try {
                const res = await fetch(apiAdd, { method: 'POST', body: data });
                const out = await res.json();

                if (out.success) {
                    knxToast('Hub added successfully', 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    knxToast(out.error || 'Error adding hub', 'error');
                }
            } catch (err) {
                console.error('Add Hub error', err);
                knxToast('Network error while adding hub', 'error');
            }
        });

        // --- Toggle Switch ---
        document.querySelectorAll('.knx-toggle-hub').forEach(sw => {
            sw.addEventListener('change', async e => {
                const row = e.target.closest('tr');
                const id = row.dataset.id;
                const status = e.target.checked ? 'active' : 'inactive';

                // Confirm deactivation with a nicer UX: if there's a confirm modal use it, otherwise fallback
                if (status === 'inactive') {
                    const confirmModal = document.getElementById('knxConfirmDeactivate');
                    if (confirmModal) {
                        confirmModal.classList.add('active');
                        // wire up confirm buttons if present
                        const confirmBtn = document.getElementById('knxConfirmDeactivateBtn');
                        const cancelBtn = document.getElementById('knxCancelDeactivate');
                        const cleanup = () => { confirmModal.classList.remove('active'); };
                        cancelBtn?.addEventListener('click', () => { e.target.checked = true; cleanup(); });
                        confirmBtn?.addEventListener('click', async () => { cleanup(); await doToggle(); });
                        return; // wait for confirmation
                    }

                    if (!window.confirm('Are you sure you want to deactivate this hub?')) {
                        e.target.checked = true;
                        return;
                    }
                }

                // perform toggle
                async function doToggle() {
                    try {
                        const res = await fetch(apiToggle, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id, status, nonce: nonceToggle })
                        });
                        const out = await res.json();

                        if (out.success) {
                            const label = row.querySelector('.status-active, .status-inactive');
                            if (label) {
                                label.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                                label.className = 'status-' + status;
                            }
                            knxToast('Hub ' + status + ' successfully', 'success');
                        } else {
                            knxToast(out.error || 'Toggle failed', 'error');
                            e.target.checked = !e.target.checked;
                        }
                    } catch (err) {
                        console.error('Toggle error', err);
                        knxToast('Network error toggling hub', 'error');
                        e.target.checked = !e.target.checked;
                    }
                }

                if (status === 'active') await doToggle();
            });
        });
    });
    </script>

    <?php
    return ob_get_clean();
});
