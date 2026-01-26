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
    ob_start();
    ?>
    <link rel="stylesheet"
          href="<?php echo esc_url(KNX_URL . 'inc/public/cart/cart-style.css?v=' . KNX_VERSION); ?>">

    <div id="knx-cart-page">
        <h1 class="knx-cart-page__title">
            <?php echo esc_html__('Your cart', 'kingdom-nexus'); ?>
        </h1>

        <?php if (!empty($hub_name)) : ?>
            <p style="text-align:center; margin-top:-14px; margin-bottom:22px; font-size:0.9rem; color:#6b7280;">
                <?php
                printf(
                    /* translators: %s is hub name */
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
        <?php endif; ?>

        <?php if (empty($items)) : ?>
            <div class="knx-cart-empty">
                <i class="fas fa-shopping-basket" aria-hidden="true"></i>
                <p><?php echo esc_html__('Your cart is empty right now.', 'kingdom-nexus'); ?></p>
                <a href="<?php echo esc_url(site_url('/explore-hubs')); ?>"
                   class="knx-cart-empty__btn">
                    <?php echo esc_html__('Browse restaurants', 'kingdom-nexus'); ?>
                </a>
            </div>
        <?php else : ?>

            <div class="knx-cart-items">
                <?php foreach ($items as $item) : ?>
                    <?php
                    $name     = (string) ($item->name_snapshot ?? '');
                    $img      = (string) ($item->image_snapshot ?? '');
                    $qty      = (int) ($item->quantity ?? 1);
                    $line     = (float) ($item->line_total ?? 0.0);
                    $mods_raw = $item->modifiers_json ?? null;

                    if ($qty < 1) $qty = 1;

                    // Display fallback only for the line (subtotal is SSOT from carts table)
                    if ($line < 0) {
                        $unit = (float) ($item->unit_price ?? 0.0);
                        $line = $unit * $qty;
                    }

                    $mods_text = '';
                    if (!empty($mods_raw)) {
                        $decoded = json_decode($mods_raw, true);
                        if (is_array($decoded) && !empty($decoded)) {
                            $parts = [];
                            foreach ($decoded as $mod) {
                                if (empty($mod['name']) || empty($mod['options']) || !is_array($mod['options'])) continue;

                                $opt_names = [];
                                foreach ($mod['options'] as $opt) {
                                    if (!empty($opt['name'])) $opt_names[] = $opt['name'];
                                }

                                if ($opt_names) {
                                    $parts[] = $mod['name'] . ': ' . implode(', ', $opt_names);
                                }
                            }
                            if ($parts) $mods_text = implode(' • ', $parts);
                        }
                    }
                    ?>
                    <div class="knx-cart-item">
                        <?php if (!empty($img)) : ?>
                            <div class="knx-cart-item__img">
                                <img src="<?php echo esc_url($img); ?>"
                                     alt="<?php echo esc_attr($name); ?>">
                            </div>
                        <?php endif; ?>

                        <div class="knx-cart-item__body">
                            <div class="knx-cart-item__title">
                                <?php echo esc_html($name); ?>
                            </div>

                            <?php if ($mods_text) : ?>
                                <div class="knx-cart-item__mods">
                                    <?php echo esc_html($mods_text); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="knx-cart-item__meta">
                            <div class="knx-cart-item__price">
                                <?php echo esc_html('$' . number_format_i18n($line, 2)); ?>
                            </div>
                            <div class="knx-cart-item__qty">
                                <?php
                                printf(
                                    /* translators: %d is quantity */
                                    esc_html__('%d×', 'kingdom-nexus'),
                                    $qty
                                );
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="knx-cart-summary">
                <div class="knx-cart-summary__line">
                    <span><?php echo esc_html__('Items subtotal', 'kingdom-nexus'); ?></span>
                    <strong><?php echo esc_html('$' . number_format_i18n((float) $subtotal, 2)); ?></strong>
                </div>

                <?php
                // Checkout readiness (SOFT): session + profile + availability
                $session      = function_exists('knx_get_session') ? knx_get_session() : null;
                $is_logged_in = !empty($session);
                $can_order    = empty($availability) || !empty($availability['can_order']);

                $profile_page_exists = (bool) get_page_by_path('profile');

                $button_text   = '';
                $button_href   = '#';
                $disabled_attr = '';

                if (!$is_logged_in) {
                    $button_text = esc_html__('Login to checkout', 'kingdom-nexus');
                    $button_href = site_url('/login');
                } else {
                    $profile_complete = true;
                    $schema_missing   = false;

                    if (function_exists('knx_profile_status')) {
                        $ps = knx_profile_status((int) $session->user_id);
                        $profile_complete = !empty($ps['complete']);
                        $schema_missing   = !empty($ps['schema_missing']);
                    }

                    if ($schema_missing) {
                        $button_text   = esc_html__('Profile system needs setup', 'kingdom-nexus');
                        $button_href   = '#';
                        $disabled_attr = 'disabled="disabled" style="opacity:0.5; cursor:not-allowed; pointer-events:none;"';
                    } else if (!$profile_complete) {
                        if ($profile_page_exists) {
                            $button_text = esc_html__('Complete profile to checkout', 'kingdom-nexus');
                            $button_href = site_url('/profile');
                        } else {
                            $button_text   = esc_html__('Profile required (page missing)', 'kingdom-nexus');
                            $button_href   = '#';
                            $disabled_attr = 'disabled="disabled" style="opacity:0.5; cursor:not-allowed; pointer-events:none;"';
                        }
                    } else if ($can_order) {
                        $button_text = esc_html__('Proceed to checkout', 'kingdom-nexus');
                        $button_href = site_url('/checkout');
                    } else {
                        $button_text   = esc_html__('Restaurant unavailable', 'kingdom-nexus');
                        $button_href   = '#';
                        $disabled_attr = 'disabled="disabled" style="opacity:0.5; cursor:not-allowed; pointer-events:none;"';
                    }
                }
                ?>
                <a href="<?php echo esc_url($button_href); ?>"
                   class="knx-cart-summary__checkout"
                   <?php echo $disabled_attr; ?>
                   title="<?php echo $can_order ? '' : esc_attr__('This restaurant is currently unavailable for orders', 'kingdom-nexus'); ?>">
                    <?php echo $button_text; ?>
                </a>

                <?php
                // v1.8 Addresses CTA (simple link, no logic in cart)
                if ($is_logged_in) :
                ?>
                    <a href="<?php echo esc_url(site_url('/my-addresses?return_to=/cart')); ?>"
                       class="knx-cart-addresses-link"
                       style="display:block; text-align:center; margin-top:16px; font-size:0.9rem; color:#0b793a; text-decoration:none;">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo esc_html__('Manage delivery addresses', 'kingdom-nexus'); ?>
                    </a>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>
    <?php

    return ob_get_clean();
}
