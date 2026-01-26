<?php
if (!defined('ABSPATH')) exit;

/**
 * ════════════════════════════════════════════════════════════════
 * KINGDOM NEXUS — SIDEBAR (Private Navigation)
 * ════════════════════════════════════════════════════════════════
 * 
 * PURPOSE:
 * - Admin/ops/driver navigation
 * - Role-filtered links (fail-closed)
 * - Active state calculation
 * - NO wp_footer, NO enqueue
 * 
 * CONTEXT:
 * - Rendered via wp_body_open hook
 * - Uses navigation-engine.php for authority
 * - Assets via echo (controlled)
 * 
 * @package KingdomNexus
 * @since Phase 3.6
 */

add_action('wp_body_open', 'knx_render_sidebar');

if (!function_exists('knx_render_sidebar')) {
    function knx_render_sidebar() {
        // Get navigation context
        $context = knx_get_navigation_context();
        $layout = knx_get_navigation_layout($context);
        
        // Only render sidebar if layout says so
        if (!$layout['render_sidebar']) {
            return;
        }
        
        // Get sidebar items based on area
        $area = $layout['sidebar_area'] ?? 'admin';
        $nav_items = knx_get_nav_items($area);
        
        // Filter items by role
        $allowed_items = [];
        foreach ($nav_items as $item) {
            if (knx_can_render_nav_item($item, $context)) {
                $allowed_items[] = $item;
            }
        }
        
        // If no items allowed, don't render sidebar
        if (empty($allowed_items)) {
            return;
        }
        
        // Load assets (echo, not enqueue)
        echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/public/navigation/navigation-style.css?v=' . KNX_VERSION) . '">';
        ?>

        <!-- SIDEBAR -->
        <aside class="knx-sidebar" id="knxSidebar" role="navigation" aria-label="Main navigation">
            <!-- Header -->
            <div class="knx-sidebar__header">
                <button id="knxSidebarToggle" class="knx-sidebar__toggle" aria-label="Toggle Sidebar" aria-expanded="true">
                    <i class="fas fa-angles-right"></i>
                </button>
                <a href="<?php echo esc_url(site_url('/dashboard')); ?>" class="knx-sidebar__logo" title="Dashboard">
                    <i class="fas fa-home"></i>
                </a>
            </div>

            <!-- Scrollable Menu -->
            <div class="knx-sidebar__scroll">
                <ul class="knx-sidebar__menu">
                    <?php foreach ($allowed_items as $item): 
                        $is_active = knx_is_nav_item_active($item, $context['current_slug']);
                        $active_class = $is_active ? 'knx-sidebar__menu-item--active' : '';
                        $route = esc_url(site_url($item['route']));
                        $icon = esc_attr($item['icon']);
                        $label = esc_html($item['label']);
                    ?>
                    <li class="knx-sidebar__menu-item <?php echo $active_class; ?>">
                        <a href="<?php echo $route; ?>" class="knx-sidebar__menu-link">
                            <i class="fas fa-<?php echo $icon; ?>"></i>
                            <span><?php echo $label; ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <!-- Footer (Logout) -->
            <div class="knx-sidebar__footer">
                <form method="post" class="knx-sidebar__logout">
                    <?php wp_nonce_field('knx_logout_action', 'knx_logout_nonce'); ?>
                    <button type="submit" name="knx_logout" aria-label="Logout" class="knx-sidebar__logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </button>
                </form>
            </div>
        </aside>

        <!-- Sidebar Toggle Script (Minimal, Inline) -->
        <script>
        (function() {
            const sidebar = document.getElementById('knxSidebar');
            const toggle = document.getElementById('knxSidebarToggle');
            
            if (!sidebar || !toggle) return;
            
            toggle.addEventListener('click', function() {
                sidebar.classList.toggle('knx-sidebar--collapsed');
                const isExpanded = !sidebar.classList.contains('knx-sidebar--collapsed');
                toggle.setAttribute('aria-expanded', isExpanded);
            });
        })();
        </script>

        <?php
    }
}
