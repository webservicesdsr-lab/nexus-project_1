<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Hubs Shortcode (v4.1)
 * ----------------------------------------------------------
 * ✅ REST-only CRUD (Add + Toggle)
 * ✅ Unified global knxToast() system
 * ✅ Desktop: table (unchanged markup)
 * ✅ Mobile: REAL cards container (same pattern as /customers)
 * ✅ Preserves Search/Pagination + existing endpoints/nonces
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

    $css_url = KNX_URL . 'inc/modules/hubs/hubs-style.css?v=' . rawurlencode(defined('KNX_VERSION') ? KNX_VERSION : '1');
    $js_url  = KNX_URL . 'inc/modules/hubs/hubs-script.js?v=' . rawurlencode(defined('KNX_VERSION') ? KNX_VERSION : '1');

    ob_start(); ?>

    <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">

    <div class="knx-hubs-wrapper knx-admin-page"
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

        <!-- Desktop: Table -->
        <table class="knx-hubs-table" aria-label="Hubs Table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th class="knx-col-center">Edit</th>
                    <th class="knx-col-center">Toggle</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($hubs): foreach ($hubs as $hub): ?>
                    <tr data-id="<?php echo esc_attr($hub->id); ?>"
                        data-name="<?php echo esc_attr(stripslashes($hub->name)); ?>"
                        data-phone="<?php echo esc_attr($hub->phone); ?>"
                        data-status="<?php echo esc_attr($hub->status); ?>">
                        <td>
                            <div class="knx-hub-identity">
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
                        <td class="knx-status-cell">
                            <span class="status-<?php echo esc_attr($hub->status); ?>">
                                <?php echo ucfirst($hub->status); ?>
                            </span>
                        </td>
                        <td class="knx-edit-cell knx-col-center">
                            <a href="<?php echo esc_url(site_url('/edit-hub?id=' . $hub->id)); ?>" class="knx-edit-link" title="Edit Hub">
                                <i class="fas fa-pen"></i>
                            </a>
                        </td>
                        <td class="knx-col-center">
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

        <!-- Mobile: Cards -->
        <div class="knx-hubs-cards" aria-label="Hubs Cards">
            <?php if ($hubs): foreach ($hubs as $hub): ?>
                <div class="knx-hub-card"
                     data-id="<?php echo esc_attr($hub->id); ?>"
                     data-name="<?php echo esc_attr(stripslashes($hub->name)); ?>"
                     data-phone="<?php echo esc_attr($hub->phone); ?>"
                     data-status="<?php echo esc_attr($hub->status); ?>">

                    <div class="knx-hub-card__top">
                        <div class="knx-hub-card__identity">
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

                        <div class="knx-hub-card__status knx-status-cell">
                            <span class="status-<?php echo esc_attr($hub->status); ?>">
                                <?php echo ucfirst($hub->status); ?>
                            </span>
                        </div>
                    </div>

                    <div class="knx-hub-card__meta">
                        <div><strong>Phone:</strong> <?php echo esc_html($hub->phone ?: '—'); ?></div>
                    </div>

                    <div class="knx-hub-card__actions">
                        <a href="<?php echo esc_url(site_url('/edit-hub?id=' . $hub->id)); ?>" class="knx-edit-link" title="Edit Hub">
                            <i class="fas fa-pen"></i> Edit
                        </a>

                        <div class="knx-hub-card__toggle">
                            <label class="knx-switch">
                                <input type="checkbox" class="knx-toggle-hub" <?php checked($hub->status, 'active'); ?>>
                                <span class="knx-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            <?php endforeach; else: ?>
                <div class="knx-empty">No hubs found.</div>
            <?php endif; ?>
        </div>

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
    <div id="knxAddHubModal" class="knx-modal" aria-hidden="true">
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

    <!-- Modal: Confirm Deactivate Hub -->
    <div id="knxConfirmDeactivate" class="knx-modal" aria-hidden="true">
        <div class="knx-modal-content knx-confirm-content" role="dialog" aria-modal="true" aria-labelledby="knxConfirmTitle">
            <h3 id="knxConfirmTitle">Deactivate Hub</h3>
            <p class="knx-confirm-message">Are you sure you want to deactivate this hub? This will make it unavailable to customers.</p>
            <div class="knx-confirm-actions">
                <button id="knxCancelDeactivate" type="button" class="knx-btn-secondary">Cancel</button>
                <button id="knxConfirmDeactivateBtn" type="button" class="knx-btn">Deactivate</button>
            </div>
        </div>
    </div>

    <script src="<?php echo esc_url($js_url); ?>"></script>

    <?php
    return ob_get_clean();
});