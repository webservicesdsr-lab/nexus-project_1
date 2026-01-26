<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Checkout Shortcode (Production)
 * Shortcode: [knx_checkout]
 * ==========================================================
 *
 * Notes:
 * - UI v2: Mobile-first + sticky bottom bar
 * - Tip UI: chips (No tip, presets, Custom)
 * - Removes inline duplicate JS: checkout-script.js is SSOT for quote/tip/promo UI
 */

add_shortcode('knx_checkout', 'knx_render_checkout_page');

function knx_render_checkout_page() {
    global $wpdb;

    // Fail-closed if session engine is missing
    if (!function_exists('knx_get_session')) {
        return '<div style="padding:40px;text-align:center;">
            <h2>System unavailable</h2>
            <p>Please try again later.</p>
        </div>';
    }

    // Login guard
    $session = knx_get_session();
    $is_logged_in = !empty($session);

    if (!$is_logged_in) {
        return '<div style="padding:40px;text-align:center;">
            <h2>Please login to continue</h2>
            <p>You need to be logged in to access checkout.</p>
            <a href="' . esc_url(site_url('/login')) . '" style="display:inline-block;margin-top:16px;padding:12px 24px;background:#10b981;color:white;border-radius:8px;text-decoration:none;">Login</a>
        </div>';
    }

    $table_carts      = $wpdb->prefix . 'knx_carts';
    $table_cart_items = $wpdb->prefix . 'knx_cart_items';
    $table_hubs       = $wpdb->prefix . 'knx_hubs';

    // Resolve session_token from cookie (same used by JS cart system)
    $session_token = '';
    if (!empty($_COOKIE['knx_cart_token'])) {
        $session_token = sanitize_text_field(wp_unslash($_COOKIE['knx_cart_token']));
    }

    $cart     = null;
    $items    = [];
    $hub      = null;
    $subtotal = 0.00;

    if ($session_token !== '') {
        // Latest active cart for this session_token
        $cart = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_carts}
                 WHERE session_token = %s
                   AND status = 'active'
                 ORDER BY updated_at DESC
                 LIMIT 1",
                $session_token
            )
        );

        if ($cart) {
            // Cart items
            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_cart_items}
                     WHERE cart_id = %d
                     ORDER BY id ASC",
                    $cart->id
                )
            );

            if ($items) {
                foreach ($items as $line) {
                    $line_total = isset($line->line_total) ? (float) $line->line_total : 0.00;
                    $subtotal  += $line_total;
                }
                $subtotal = max(0.00, round($subtotal, 2));
            }

            // Hub basic info
            if (!empty($cart->hub_id)) {
                $hub = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id, name, address, phone, logo_url
                         FROM {$table_hubs}
                         WHERE id = %d",
                        $cart->hub_id
                    )
                );
            }
        }
    }

    // Guard: empty cart
    if (empty($items)) {
        return '<div style="padding:40px;text-align:center;">
            <h2>Your cart is empty</h2>
            <p>Add some items before checking out.</p>
            <a href="' . esc_url(site_url('/explore-hubs')) . '" style="display:inline-block;margin-top:16px;padding:12px 24px;background:#10b981;color:white;border-radius:8px;text-decoration:none;">Explore Restaurants</a>
        </div>';
    }

    // Availability check (soft gate)
    $availability = null;
    if (function_exists('knx_availability_decision') && !empty($cart->hub_id)) {
        $availability = knx_availability_decision((int) $cart->hub_id);
        $can_order = !empty($availability['can_order']);

        if (!$can_order) {
            $message = !empty($availability['message']) ? $availability['message'] : 'Restaurant unavailable';
            return '<div style="padding:40px;text-align:center;">
                <h2>Restaurant Unavailable</h2>
                <p>' . esc_html($message) . '</p>
                <a href="' . esc_url(site_url('/cart')) . '" style="display:inline-block;margin-top:16px;padding:12px 24px;background:#10b981;color:white;border-radius:8px;text-decoration:none;">Return to Cart</a>
            </div>';
        }
    }

    // Current WP user (for header display only)
    $current_user = wp_get_current_user();
    $customer_name = '';
    if ($current_user && $current_user->exists()) {
        $customer_name = $current_user->display_name ?: $current_user->user_login;
    }

    // Soft profile completeness banner (create-order is still the hard gate)
    $profile_incomplete_banner = '';
    if (function_exists('knx_profile_status') && $is_logged_in) {
        $profile_status = knx_profile_status($session->user_id);
        if (!empty($profile_status) && empty($profile_status['complete'])) {
            $missing = isset($profile_status['missing']) && is_array($profile_status['missing'])
                ? implode(', ', $profile_status['missing'])
                : '';

            $profile_incomplete_banner = '
                <div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:16px;margin:20px 0;border-radius:10px;">
                    <h3 style="margin:0 0 8px 0;color:#92400e;">Profile Incomplete</h3>
                    <p style="margin:0 0 12px 0;color:#78350f;">
                        Your profile is missing required information: <strong>' . esc_html($missing) . '</strong>.
                        Please complete your profile before placing an order.
                    </p>
                    <a href="' . esc_url(site_url('/profile')) . '"
                       style="display:inline-block;padding:10px 16px;background:#f59e0b;color:white;text-decoration:none;border-radius:10px;font-weight:700;">
                        Complete Profile
                    </a>
                </div>
            ';
        }
    }

    // Quote endpoint (SSOT for UI quote box + sticky bar)
    $quote_url = esc_url_raw(rest_url('knx/v1/orders/quote-totals'));

    // ----------------------------------------------------------
    // Phase 4.2: Resolve selected address BEFORE rendering root dataset
    // ----------------------------------------------------------
    $customer_id = isset($session->user_id) ? (int) $session->user_id : 0;
    $selected_address = null;
    $selected_address_id = 0;

    if ($customer_id > 0 && function_exists('knx_session_get_selected_address_id') && function_exists('knx_get_address_by_id')) {
        $selected_address_id = (int) knx_session_get_selected_address_id();
        if ($selected_address_id > 0) {
            $selected_address = knx_get_address_by_id($selected_address_id, $customer_id);
        }
    }

    // Payments endpoints (A2)
    $create_intent_url   = esc_url_raw(rest_url('knx/v1/payments/create-intent'));
    $payment_status_url  = esc_url_raw(rest_url('knx/v1/payments/status'));
    $create_order_url    = esc_url_raw(rest_url('knx/v1/orders/create'));

    // WP REST nonce (optional: used by frontend as a generic hardening header)
    // NOTE: Backend can still rely on KNX session auth. This nonce is additive only.
    $wp_rest_nonce = function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '';

    // Stripe publishable key (fail-closed)
    // Copilot/Repo may not include wp-config values; runtime will.
    $stripe_publishable_key = '';

    // Prefer helper if present
    if (function_exists('knx_get_stripe_publishable_key')) {
        $stripe_publishable_key = (string) knx_get_stripe_publishable_key();
    }

    // Fallback to wp-config constants (TEST/LIVE)
    if (empty($stripe_publishable_key)) {
        $mode = defined('KNX_STRIPE_MODE') ? (string) KNX_STRIPE_MODE : 'test';
        $mode = strtolower($mode) === 'live' ? 'live' : 'test';

        if ($mode === 'live' && defined('KNX_STRIPE_LIVE_PUBLISHABLE_KEY') && !empty(KNX_STRIPE_LIVE_PUBLISHABLE_KEY)) {
            $stripe_publishable_key = (string) KNX_STRIPE_LIVE_PUBLISHABLE_KEY;
        }

        if ($mode === 'test' && defined('KNX_STRIPE_TEST_PUBLISHABLE_KEY') && !empty(KNX_STRIPE_TEST_PUBLISHABLE_KEY)) {
            $stripe_publishable_key = (string) KNX_STRIPE_TEST_PUBLISHABLE_KEY;
        }
    }

    $stripe_publishable_key = trim($stripe_publishable_key);
    $payments_ready = !empty($stripe_publishable_key);

    ob_start();
    ?>

<link rel="stylesheet"
      href="<?php echo esc_url(KNX_URL . 'inc/public/checkout/checkout-style.css?v=' . KNX_VERSION); ?>">
<link rel="stylesheet" 
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" 
      integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" 
      crossorigin="anonymous" 
      referrerpolicy="no-referrer">

<!-- Stripe.js SDK -->
<script src="https://js.stripe.com/v3/"></script>

<!-- KNX Checkout Configuration (A2 SSOT) -->
<script>
    (function () {
        var cfg = {
            // Runtime config
            publishableKey: <?php echo wp_json_encode($stripe_publishable_key); ?>,
            paymentsReady: <?php echo $payments_ready ? 'true' : 'false'; ?>,

            // Endpoints
            createIntentUrl: <?php echo wp_json_encode($create_intent_url); ?>,
            paymentStatusUrl: <?php echo wp_json_encode($payment_status_url); ?>,
            createOrderUrl: <?php echo wp_json_encode($create_order_url); ?>,
            ordersUrl: <?php echo wp_json_encode(esc_url_raw(site_url('/orders'))); ?>,
            quoteUrl: <?php echo wp_json_encode($quote_url); ?>,

            // Optional hardening
            wpRestNonce: <?php echo wp_json_encode($wp_rest_nonce); ?>,

            // Correlation (UX only — NO secrets stored)
            cartId: <?php echo wp_json_encode($cart ? (int) $cart->id : 0); ?>,
            hubId: <?php echo wp_json_encode($cart ? (int) $cart->hub_id : 0); ?>,

            // Stable UI IDs (A2 contract)
            ui: {
                rootId: "knx-checkout",
                placeOrderBtnId: "knxCoPlaceOrderBtn",
                stripeMountId: "knx-stripe-card-element",
                stripeErrorsId: "knx-stripe-card-errors",
                statusBoxId: "knxCheckoutStatus"
            }
        };

        window.KNX_CHECKOUT_CONFIG = cfg;

        // Back-compat: existing code may read KNX_STRIPE_CONFIG
        window.KNX_STRIPE_CONFIG = {
            publishableKey: cfg.publishableKey,
            createIntentEndpoint: "/wp-json/knx/v1/payments/create-intent"
        };
    })();
</script>

<script src="<?php echo esc_url(KNX_URL . 'inc/public/checkout/checkout-script.js?v=' . KNX_VERSION); ?>" defer></script>
<script src="<?php echo esc_url(KNX_URL . 'inc/public/checkout/checkout-payment-flow.js?v=' . KNX_VERSION); ?>" defer></script>

<div id="knx-checkout"
    data-cart-id="<?php echo esc_attr($cart ? (int) $cart->id : 0); ?>"
    data-hub-id="<?php echo esc_attr($cart ? (int) $cart->hub_id : 0); ?>"
    data-selected-address-id="<?php echo esc_attr(isset($selected_address_id) ? (int) $selected_address_id : 0); ?>"
    data-quote-url="<?php echo esc_attr($quote_url); ?>"
    data-create-intent-url="<?php echo esc_attr($create_intent_url); ?>"
    data-payment-status-url="<?php echo esc_attr($payment_status_url); ?>"
    data-payments-ready="<?php echo esc_attr($payments_ready ? '1' : '0'); ?>">

    <?php echo $profile_incomplete_banner; ?>

    <!-- RESTAURANT INFO HEADER (New modern design) -->
    <?php if ($hub): ?>
        <header class="knx-co-hero">
            <!-- Back button (mobile only) -->
            <a href="<?php echo esc_url(site_url('/cart')); ?>" class="knx-co-hero__back"></a>

            <div class="knx-co-hero__content">
                <div class="knx-co-hero__badge">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                    <span>Delivering from</span>
                </div>
                
                <h1 class="knx-co-hero__title"><?php echo esc_html($hub->name); ?></h1>
                
                <?php if (!empty($hub->address)): ?>
                    <div class="knx-co-hero__location">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                        </svg>
                        <span><?php echo esc_html($hub->address); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </header>
    <?php endif; ?>

    <div class="knx-co-layout">
        <!-- MAIN COLUMN (Left on desktop) -->
        <section class="knx-co-main">
            <!-- ORDER DETAILS CARD (always visible) -->
            <div class="knx-co-card knx-co-card--summary">
                <div class="knx-co-card__head">
                    <div class="knx-co-card__headleft">
                        <h2>Order details</h2>
                        <span class="knx-co-pill">
                            <?php echo count($items); ?> item<?php echo count($items) === 1 ? '' : 's'; ?>
                        </span>
                    </div>
                </div>

                <div class="knx-co-itemswrap" id="knxOrderItems">
                    <div class="knx-co-items">
                        <?php foreach ($items as $line): ?>
                            <?php
                            $name       = isset($line->name_snapshot) ? $line->name_snapshot : '';
                            $image      = isset($line->image_snapshot) ? $line->image_snapshot : '';
                            $qty        = isset($line->quantity) ? (int) $line->quantity : 1;
                            $unit_price = isset($line->unit_price) ? (float) $line->unit_price : 0.00;
                            $line_total = isset($line->line_total) ? (float) $line->line_total : ($unit_price * $qty);

                            $mods_text = '';
                            if (!empty($line->modifiers_json)) {
                                $mods = json_decode($line->modifiers_json, true);
                                if (is_array($mods) && !empty($mods)) {
                                    $parts = [];
                                    foreach ($mods as $m) {
                                        if (empty($m['options']) || !is_array($m['options'])) continue;
                                        $opt_names = array_map(
                                            static function ($o) {
                                                return isset($o['name']) ? $o['name'] : '';
                                            },
                                            $m['options']
                                        );
                                        $label = (isset($m['name']) ? $m['name'] . ': ' : '') . implode(', ', $opt_names);
                                        $parts[] = $label;
                                    }
                                    $mods_text = implode(' • ', $parts);
                                }
                            }
                            ?>
                            <article class="knx-co-item">
                                <?php if (!empty($image)): ?>
                                    <div class="knx-co-item__img">
                                        <img src="<?php echo esc_url($image); ?>"
                                             alt="<?php echo esc_attr($name); ?>">
                                    </div>
                                <?php endif; ?>

                                <div class="knx-co-item__body">
                                    <div class="knx-co-item__row">
                                        <h3 class="knx-co-item__name">
                                            <?php echo esc_html($qty . '× ' . $name); ?>
                                        </h3>
                                        <div class="knx-co-item__price">
                                            $<?php echo number_format($line_total, 2); ?>
                                        </div>
                                    </div>

                                    <?php if ($mods_text): ?>
                                        <div class="knx-co-item__mods">
                                            <?php echo esc_html($mods_text); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="knx-co-items-subtotal">
                        <span>Items subtotal</span>
                        <strong>$<?php echo number_format($subtotal, 2); ?></strong>
                    </div>
                </div>
            </div>

            <!-- DELIVERY ADDRESS CARD (Phase 4.2 Address Book Integration) -->
            <?php
            // Variables already resolved at top of function (A2.9.1)
            if ($selected_address && function_exists('knx_addresses_format_one_line')) {
                $address_line = knx_addresses_format_one_line($selected_address);
                $address_label = isset($selected_address->label) ? $selected_address->label : 'Delivery Address';
                ?>
                <div class="knx-co-card knx-co-card--address">
                    <div class="knx-co-card__head">
                        <div class="knx-co-card__headleft">
                            <span class="knx-co-iconpin" aria-hidden="true">📍</span>
                            <h2><?php echo esc_html($address_label); ?></h2>
                        </div>
                        <a href="<?php echo esc_url(site_url('/my-addresses')); ?>" class="knx-co-link">Change</a>
                    </div>

                    <div class="knx-co-card__body">
                        <div class="knx-co-softsuccess">
                            <div class="knx-co-softsuccess__title">Delivering to</div>
                            <div class="knx-co-softsuccess__line"><?php echo esc_html($address_line); ?></div>
                        </div>
                    </div>
                </div>
                <?php
            } else {
                ?>
                <div class="knx-co-card knx-co-card--address">
                    <div class="knx-co-card__head">
                        <div class="knx-co-card__headleft">
                            <span class="knx-co-iconpin knx-co-iconpin--warn" aria-hidden="true">📍</span>
                            <h2>Delivery Address</h2>
                        </div>
                    </div>
                    <div class="knx-co-card__body">
                        <div class="knx-co-softwarn">
                            <div class="knx-co-softwarn__title">No delivery address selected</div>
                            <div class="knx-co-softwarn__text">Please add or select a delivery address to continue.</div>
                            <a href="<?php echo esc_url(site_url('/my-addresses')); ?>" class="knx-co-btn knx-co-btn--primary">
                                Add Address
                            </a>
                        </div>
                    </div>
                </div>
                <?php
            }
            ?>

            <!-- DELIVERY TIME -->
            <div class="knx-co-card knx-co-card--delivery-time">
                <div class="knx-co-card__head">
                    <h2>Delivery time</h2>
                </div>
                <div class="knx-co-card__body">
                    <select class="knx-co-select" id="knxDeliveryTime">
                        <option value="asap">As soon as possible</option>
                        <!-- Future slots injected here -->
                    </select>
                    <p class="knx-co-helptext">
                        Delivery times are estimates and may vary based on demand.
                    </p>
                </div>
            </div>

            <!-- PAYMENT METHOD (Stripe Card Element) -->
            <div class="knx-co-card knx-co-card--payment">
                <div class="knx-co-card__head">
                    <h2>Payment method</h2>
                    <span class="knx-co-pill knx-co-pill--muted">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                            <line x1="1" y1="10" x2="23" y2="10"></line>
                        </svg>
                        Card
                    </span>
                </div>
                <div class="knx-co-card__body">
                    <!-- Stripe Card Element will be mounted here -->
                    <div id="knx-stripe-card-element" style="padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff;"></div>
                    
                    <!-- Card errors will be displayed here -->
                    <div id="knx-stripe-card-errors" role="alert" style="color: #ef4444; font-size: 14px; margin-top: 8px; display: none;"></div>
                    
                    <p class="knx-co-helptext">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        </svg>
                        Your payment information is secure and encrypted.
                    </p>
                </div>
            </div>

            <!-- TIP CARD (UI v2) -->
            <div class="knx-co-card knx-co-card--tip">
                <div class="knx-co-card__head">
                    <h2>Add a tip</h2>
                    <span class="knx-co-pill knx-co-pill--muted" id="knxTipPill">No tip</span>
                </div>

                <div class="knx-co-card__body">
                    <div class="knx-tip-selector" role="group" aria-label="Tip amount">
                        <button type="button" class="knx-tip-chip is-active" data-tip="0" data-tip-mode="none">No tip</button>
                        <button type="button" class="knx-tip-chip" data-tip="2">$2</button>
                        <button type="button" class="knx-tip-chip" data-tip="3">$3</button>
                        <button type="button" class="knx-tip-chip" data-tip="5">$5</button>
                        <button type="button" class="knx-tip-chip" data-tip="10">$10</button>
                        <button type="button" class="knx-tip-chip" data-tip-mode="custom">Custom</button>
                    </div>

                    <div class="knx-tip-customwrap" data-tip-custom hidden>
                        <div class="knx-tip-customrow">
                            <div class="knx-tip-customfield">
                                <label class="knx-tip-label" for="knx_tip_custom">Custom tip</label>
                                <input type="number"
                                       id="knx_tip_custom"
                                       class="knx-tip-input"
                                       placeholder="0.00"
                                       min="0"
                                       step="0.01"
                                       inputmode="decimal">
                            </div>

                            <button type="button" id="knx_tip_custom_apply" class="knx-tip-apply">Apply</button>
                            <button type="button" id="knx_tip_custom_clear" class="knx-tip-clear">Clear</button>
                        </div>

                        <div class="knx-tip-hint" id="knxTipHint">Tip will be reflected in the total.</div>
                    </div>
                </div>
            </div>

            <!-- COUPON CARD -->
            <div class="knx-co-card knx-co-card--coupon">
                <div class="knx-co-card__head">
                    <h2>Promo code</h2>
                </div>
                <div class="knx-co-card__body">
                    <div class="knx-coupon-form">
                        <input type="text"
                               id="knx_coupon_input"
                               class="knx-coupon-input"
                               placeholder="Enter promo code">
                        <button type="button" class="knx-coupon-apply" id="knx_coupon_apply_btn">Apply</button>
                    </div>
                    <div id="knx_coupon_status" class="knx-coupon-status"></div>
                </div>
            </div>

            <!-- COMMENT BLOCK -->
            <div class="knx-co-card knx-co-card--comment">
                <div class="knx-co-card__head">
                    <h2>Comment for the driver / kitchen</h2>
                </div>
                <div class="knx-co-card__body">
                    <label for="knxCoComment" class="knx-co-comment-label">
                        Optional note (for example: gate code, extra crispy, ring the bell).
                    </label>
                    <textarea id="knxCoComment"
                              class="knx-co-comment-textarea"
                              rows="3"
                              placeholder="Type your comment here..."></textarea>
                </div>
            </div>

        </section>

        <!-- SIDEBAR (Right on desktop - shows Order Summary + Place Order) -->
        <aside class="knx-co-sidebar">
            <div class="knx-co-sidebar-sticky">
                <!-- TOTAL BREAKDOWN (in sidebar on desktop) -->
                <div class="knx-co-card knx-co-card--totals">
                    <div class="knx-co-card__head">
                        <h2>Checkout Summary</h2>
                    </div>
                    <div class="knx-co-card__body">
                        <div id="knx-summary-body"></div>

                        <div class="knx-co-taxes-info">
                            <button type="button"
                                    class="knx-co-taxes-info-toggle"
                                    data-co-toggle="fees"
                                    aria-expanded="false"
                                    aria-controls="knxFeesPanel">
                                <span class="knx-co-taxes-info-icon">ℹ️</span>
                                <span>How are taxes and fees calculated?</span>
                            </button>

                            <div class="knx-co-taxes-info-content" data-co-panel="fees" id="knxFeesPanel" hidden>
                                <p>
                                    Taxes, delivery and service fees are calculated securely using backend rules,
                                    your delivery area and current promos.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PLACE ORDER BUTTON (in sidebar on desktop) -->
                <div class="knx-co-card knx-co-card--place-order">
                    <div class="knx-co-card__body">
                        <!-- Hidden fields for tip and coupon (used by checkout-script.js) -->
                        <input type="hidden" id="knx_tip_amount" name="tip_amount" value="0.00">
                        <input type="hidden" id="knx_coupon_code" name="coupon_code" value="">
                        
                        <button type="button"
                                class="knx-co-btn knx-co-btn--primary knx-co-btn--full knx-co-btn--place"
                                id="knxCoPlaceOrderBtn"
                                data-co-cart-id="<?php echo esc_attr($cart ? (int) $cart->id : 0); ?>"
                                data-co-subtotal="<?php echo esc_attr(number_format($subtotal, 2, '.', '')); ?>">
                            <span class="knx-place-order-text">Place Order</span>
                            <span class="knx-place-order-total" id="knxPlaceOrderTotal">$<?php echo number_format($subtotal, 2); ?></span>
                        </button>

                        <div id="knxCheckoutStatus"
                             style="margin-top:10px;font-size:14px;line-height:1.35;display:none;"
                             aria-live="polite"></div>

                        <?php if (!$payments_ready): ?>
                            <div style="margin-top:10px;padding:10px 12px;border-radius:10px;background:#fef3c7;border:1px solid #f59e0b;color:#92400e;font-size:14px;">
                                Payment system not configured. Please try again later.
                            </div>
                            <script>
                                // Fail-closed: disable actions if publishable key missing
                                document.addEventListener("DOMContentLoaded", function () {
                                    var btn = document.getElementById("knxCoPlaceOrderBtn");
                                    if (btn) {
                                        btn.disabled = true;
                                        btn.style.opacity = "0.6";
                                        btn.style.cursor = "not-allowed";
                                    }
                                });
                            </script>
                        <?php endif; ?>

                        <p class="knx-co-fineprint">
                            Final total is calculated securely before payment.
                        </p>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <!-- Sticky Bottom Bar (mobile only) -->
    <div class="knx-co-stickybar" role="region" aria-label="Checkout actions">
        <div class="knx-co-stickybar__meta">
            <div class="knx-co-stickybar__label">Estimated total</div>
            <div class="knx-co-stickybar__value" id="knxStickyTotal">Calculating…</div>
            <div class="knx-co-stickybar__sub" id="knxStickySubline">Calculated securely</div>
        </div>
        <button type="button" class="knx-co-stickybar__cta" id="knxStickyContinueBtn">
            Continue
        </button>
    </div>

    <!-- Success Splash Overlay (A2.9) -->
    <div id="knxSuccessSplash" role="dialog" aria-modal="true" aria-labelledby="knxSplashTitle">
        <div class="knx-splash-card">
            <div class="knx-splash-checkmark" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <h2 class="knx-splash-title" id="knxSplashTitle">Order confirmed</h2>
            <p class="knx-splash-body">Thanks for your order. We're getting everything ready.</p>
            <p class="knx-splash-subtext">Redirecting you home…</p>
            <div class="knx-splash-actions">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="knx-splash-btn knx-splash-btn-primary">
                    Explore more restaurants
                </a>
                <a href="<?php echo esc_url(home_url('/order-status')); ?>" class="knx-splash-btn knx-splash-btn-secondary">
                    View my current order status
                </a>
            </div>
        </div>
    </div>

</div>

<?php
    return ob_get_clean();
}
