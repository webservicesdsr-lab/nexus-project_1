<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - CART PAGE SHORTCODE (Production)
 * Shortcode: [knx_cart_page]
 * ----------------------------------------------------------
 * - Reads active cart from DB (knx_carts / knx_cart_items)
 * - Resolves by session_token (cookie knx_cart_token)
 * - Shows UberEats-style summary (no fees / taxes exposed)
 *
 * Canon:
 * - Subtotal SSOT is knx_carts.subtotal (never recompute here)
 * - Checkout access is driven from /cart CTA (guest cannot checkout)
 * ==========================================================
 */

add_shortcode('knx_cart_page', 'knx_render_cart_page');

function knx_render_cart_page() {
    /** @var wpdb $wpdb */
    global $wpdb;

    $table_carts      = $wpdb->prefix . 'knx_carts';
    $table_cart_items = $wpdb->prefix . 'knx_cart_items';
    $table_hubs       = $wpdb->prefix . 'knx_hubs';

    // Resolve session token from KNX helper or cookie
    $session_token = '';
    if (function_exists('knx_get_cart_token')) {
        $session_token = (string) knx_get_cart_token();
    } else {
        if (!empty($_COOKIE['knx_cart_token'])) {
            $session_token = sanitize_text_field(wp_unslash($_COOKIE['knx_cart_token']));
        }
    }

    if ($session_token === '') {
        // No token -> treat as empty cart
        return knx_cart_page_render_html(null, [], 0.0, null, null);
    }

    // Find latest active cart for this session
    $cart_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT c.*, h.name AS hub_name, h.slug AS hub_slug
             FROM {$table_carts} AS c
             LEFT JOIN {$table_hubs} AS h ON h.id = c.hub_id
             WHERE c.session_token = %s
               AND c.status = 'active'
             ORDER BY c.updated_at DESC
             LIMIT 1",
            $session_token
        )
    );

    if (!$cart_row) {
        // No active cart
        return knx_cart_page_render_html(null, [], 0.0, null, null);
    }

    // Fetch items
    $items = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table_cart_items}
             WHERE cart_id = %d
             ORDER BY id ASC",
            (int) $cart_row->id
        )
    );

    // SSOT subtotal from carts table (never recompute)
    $subtotal = (float) ($cart_row->subtotal ?? 0.0);
    if ($subtotal < 0) $subtotal = 0.0;

    // Soft availability decision (UX only)
    $availability = null;
    if (function_exists('knx_availability_decision') && !empty($cart_row->hub_id)) {
        $availability = knx_availability_decision((int) $cart_row->hub_id);
    }

    return knx_cart_page_render_html(
        $cart_row,
        is_array($items) ? $items : [],
        $subtotal,
        $cart_row->hub_name ?? null,
        $availability
    );
}

/**
 * Build final HTML for cart page.
 *
 * @param object|null $cart_row
 * @param array       $items
 * @param float       $subtotal
 * @param string|null $hub_name
 * @param array|null  $availability
 * @return string
 */
function knx_cart_page_render_html($cart_row, $items, $subtotal, $hub_name = null, $availability = null) {

    // Checkout readiness context (needed for CTA regardless of render mode)
    $session      = function_exists('knx_get_session') ? knx_get_session() : null;
    $is_logged_in = !empty($session);
    $can_order    = empty($availability) || !empty($availability['can_order']);

    // Build CTA config for JS
    $profile_page_exists = (bool) get_page_by_path('profile');
    $cta_href   = '#';
    $cta_text   = '';
    $cta_disabled = false;

    if (!$is_logged_in) {
        $cta_text = esc_html__('Login to checkout', 'kingdom-nexus');
        $cta_href = site_url('/login') . '?redirect_to=' . rawurlencode('/cart');
    } else {
        $profile_complete = true;
        $schema_missing   = false;

        if (function_exists('knx_profile_status')) {
            $ps = knx_profile_status((int) $session->user_id);
            $profile_complete = !empty($ps['complete']);
            $schema_missing   = !empty($ps['schema_missing']);
        }

        if ($schema_missing) {
            $cta_text     = esc_html__('Profile system needs setup', 'kingdom-nexus');
            $cta_disabled = true;
        } else if (!$profile_complete) {
            if ($profile_page_exists) {
                $cta_text = esc_html__('Complete profile to checkout', 'kingdom-nexus');
                $cta_href = site_url('/profile');
            } else {
                $cta_text     = esc_html__('Profile required (page missing)', 'kingdom-nexus');
                $cta_disabled = true;
            }
        } else if ($can_order) {
            $cta_text = esc_html__('Proceed to checkout', 'kingdom-nexus');
            $cta_href = site_url('/checkout');
        } else {
            $cta_text     = esc_html__('Restaurant unavailable', 'kingdom-nexus');
            $cta_disabled = true;
        }
    }

    ob_start();
    ?>
    <link rel="stylesheet"
          href="<?php echo esc_url(KNX_URL . 'inc/public/cart/cart-style.css?v=' . KNX_VERSION); ?>">

    <div id="knx-cart-page"
         data-logged="<?php echo $is_logged_in ? '1' : '0'; ?>"
         data-cta-href="<?php echo esc_attr($cta_href); ?>"
         data-cta-text="<?php echo esc_attr($cta_text); ?>"
         data-cta-disabled="<?php echo $cta_disabled ? '1' : '0'; ?>"
         data-addresses-url="<?php echo esc_url(site_url('/my-addresses?return_to=/cart')); ?>"
         data-explore-url="<?php echo esc_url(site_url('/explore-hubs')); ?>">

        <h1 class="knx-cart-page__title">
            <?php echo esc_html__('Your cart', 'kingdom-nexus'); ?>
        </h1>

        <?php if (!empty($hub_name)) : ?>
            <p class="knx-cart-page__hub" style="text-align:center; margin-top:-14px; margin-bottom:22px; font-size:0.9rem; color:#6b7280;">
                <?php
                printf(
                    esc_html__('Ordering from %s', 'kingdom-nexus'),
                    '<strong>' . esc_html($hub_name) . '</strong>'
                );
                ?>
            </p>
        <?php endif; ?>

        <?php
        // Soft availability banner (UX only)
        if (!empty($availability) && empty($availability['can_order'])) :
            $message = !empty($availability['message']) ? $availability['message'] : 'Restaurant unavailable';
        ?>
            <div class="knx-cart-availability-banner" style="background:#fef3c7; border:1px solid #fbbf24; padding:12px 16px; border-radius:8px; margin-bottom:20px; text-align:center; color:#92400e; font-size:0.9rem;">
                <?php echo esc_html($message); ?>
            </div>
        <?php elseif (!empty($availability) && !empty($availability['is_preorder'])) : ?>
            <?php
            $preorder_opens = '';
            if (!empty($availability['opens_at'])) {
                try {
                    $po_dt = new DateTime($availability['opens_at']);
                    $preorder_opens = $po_dt->format('g:i A');
                } catch (Exception $e) {
                    $preorder_opens = '';
                }
            }
            ?>
            <div class="knx-cart-preorder-banner" style="background:#eff6ff; border:1px solid #3b82f6; padding:12px 16px; border-radius:8px; margin-bottom:20px; text-align:center; color:#1e40af; font-size:0.9rem;">
                <strong>📋 Pre-order</strong> — This restaurant hasn't opened yet.
                Your order will be queued for when they open<?php echo $preorder_opens ? ' at <strong>' . esc_html($preorder_opens) . '</strong>' : ' today'; ?>.
            </div>
        <?php endif; ?>

        <!-- JS-driven interactive cart items -->
        <div id="knxCartPageItems" class="knx-cart-items"></div>

        <!-- Empty state (shown/hidden by JS) -->
        <div id="knxCartPageEmpty" class="knx-cart-empty" style="display:none;">
            <i class="fas fa-shopping-basket" aria-hidden="true"></i>
            <p><?php echo esc_html__('Your cart is empty right now.', 'kingdom-nexus'); ?></p>
            <a href="<?php echo esc_url(site_url('/explore-hubs')); ?>"
               class="knx-cart-empty__btn">
                <?php echo esc_html__('Browse restaurants', 'kingdom-nexus'); ?>
            </a>
        </div>

        <!-- Summary (shown/hidden by JS) -->
        <div id="knxCartPageSummary" class="knx-cart-summary" style="display:none;">
            <div class="knx-cart-summary__line">
                <span><?php echo esc_html__('Items subtotal', 'kingdom-nexus'); ?></span>
                <strong id="knxCartPageSubtotal">$0.00</strong>
            </div>

            <a id="knxCartPageCta"
               href="<?php echo esc_url($cta_href); ?>"
               class="knx-cart-summary__checkout"
               <?php echo $cta_disabled ? 'disabled="disabled" style="opacity:0.5; cursor:not-allowed; pointer-events:none;"' : ''; ?>
               title="<?php echo $can_order ? '' : esc_attr__('This restaurant is currently unavailable for orders', 'kingdom-nexus'); ?>">
                <?php echo $cta_text; ?>
            </a>

            <?php if ($is_logged_in) : ?>
                <a href="<?php echo esc_url(site_url('/my-addresses?return_to=/cart')); ?>"
                   class="knx-cart-addresses-link"
                   style="display:block; text-align:center; margin-top:16px; font-size:0.9rem; color:#0b793a; text-decoration:none;">
                    <i class="fas fa-map-marker-alt"></i>
                    <?php echo esc_html__('Manage delivery addresses', 'kingdom-nexus'); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <script src="<?php echo esc_url(KNX_URL . 'inc/public/cart/cart-page.js?v=' . KNX_VERSION); ?>" defer></script>
    <?php

    return ob_get_clean();
}
