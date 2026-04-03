<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - GET ORDER API (Read-Only)
 * Endpoint:
 *   GET /wp-json/knx/v1/orders/{order_id}
 * ----------------------------------------------------------
 * Base checkpoint: PLUGIN_NEXUS_SEALED_v5
 * Philosophy: Immutable snapshots, read-only access
 * ----------------------------------------------------------
 * Returns single Order with full snapshot and status history.
 * Access control: session + ownership validation.
 * ==========================================================
 */

/**
 * Register REST route for fetching single order.
 */
add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/orders/(?P<order_id>\d+)', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_get_order'),
        'permission_callback' => knx_rest_permission_session(),
        'args' => [
            'order_id' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                }
            ],
        ],
    ]);
});

/**
 * Fetch single Order with access control.
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response
 */
function knx_api_get_order(WP_REST_Request $req) {
    global $wpdb;

    $table_orders = $wpdb->prefix . 'knx_orders';
    $table_order_history = $wpdb->prefix . 'knx_order_status_history';

    $order_id = intval($req['order_id']);

    // Fetch order
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_orders} WHERE id = %d LIMIT 1",
        $order_id
    ));

    if (!$order) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'order-not-found'
        ], 404);
    }

    // ACCESS CONTROL: Validate ownership
    $session = knx_get_session();
    
    if (!$session) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'session-invalid'
        ], 401);
    }

    $role = isset($session->role) ? $session->role : '';

    // ====================================================
    // ACCESS CONTROL LOGIC (NEXUS Philosophy)
    // ====================================================
    // - Super Admin: Unrestricted (system owner)
    // - Manager: City-scoped via hub relationships
    // - Customer: Own orders only (customer_id match)
    // - Guest: Own orders only (session_token match)
    //
    // CRITICAL SECURITY:
    // - Customers/Guests get 404 (not 403) when order exists but is not theirs
    //   This prevents order ID enumeration attacks
    // - Managers get 403 when order exists but outside their city scope
    //   This is acceptable for internal roles
    // ====================================================

    // Super admin: unrestricted access
    if ($role === 'super_admin') {
        // Full access - no restrictions
    }
    // Hub management: scoped to their assigned hubs only
    elseif (preg_match('/\bhub(?:[_\-\s]?(?:management|owner|staff|manager))\b/i', $role)) {
        $managed_ids = function_exists('knx_get_managed_hub_ids')
            ? knx_get_managed_hub_ids((int) $session->user_id)
            : [];
        if (!in_array((int) $order->hub_id, $managed_ids, true)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'order-not-found'
            ], 404);
        }
    }
    // Manager: city-scoped access via hub → city relationship
    elseif ($role === 'manager') {
        // Managers can only access orders from hubs in cities they manage
        // TODO: Full city-scoping requires knx_users.managed_cities or similar
        // For now, we enforce hub-level validation as best-effort restriction
        
        $table_hubs = $wpdb->prefix . 'knx_hubs';
        
        // Verify the hub exists and get its city_id
        $hub = $wpdb->get_row($wpdb->prepare(
            "SELECT city_id FROM {$table_hubs} WHERE id = %d LIMIT 1",
            $order->hub_id
        ));
        
        if (!$hub) {
            // Hub doesn't exist - manager cannot access
            // Return 403 (not 404) because this is an internal role
            return new WP_REST_Response([
                'success' => false,
                'error' => 'access-denied'
            ], 403);
        }
        
        // TEMPORARY: Allow all managers until city-scoping is fully implemented
        // Future: Check if $hub->city_id is in manager's managed_cities array
        // For now: Allow (assumes manager manages all cities)
    }
    // Customer: only own orders
    elseif ($role === 'customer') {
        // Check if customer owns this order
        if ($order->customer_id && (int)$order->customer_id === (int)$session->user_id) {
            // Allow - customer owns order
        } else {
            // CRITICAL: Return 404 (not 403) to prevent order existence leak
            // Customers must not be able to detect if an order_id exists
            return new WP_REST_Response([
                'success' => false,
                'error' => 'order-not-found'
            ], 404);
        }
    }
    // Guest or other roles: check session token
    else {
        if ($order->session_token === $session->token) {
            // Allow - session token matches
        } else {
            // CRITICAL: Return 404 (not 403) to prevent order existence leak
            // Guests must not be able to detect if an order_id exists
            return new WP_REST_Response([
                'success' => false,
                'error' => 'order-not-found'
            ], 404);
        }
    }

    // ===================================================
    // VISIBILITY GATE: Hide pending_payment from customers/guests.
    // Orders in pending_payment are internal (awaiting Stripe webhook).
    // The customer must never see them in their history or status page.
    // Return 404 (not 403) to prevent order existence leak.
    // ===================================================
    if ((string) $order->status === 'pending_payment') {
        if ($role === 'customer' || ($role !== 'super_admin' && $role !== 'manager')) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'order-not-found',
            ], 404);
        }
    }

    // Fetch status history
    $status_history = $wpdb->get_results($wpdb->prepare(
        "SELECT status, changed_by, created_at 
         FROM {$table_order_history} 
         WHERE order_id = %d 
         ORDER BY created_at ASC",
        $order_id
    ));

    // Decode cart_snapshot
    $cart_snapshot = null;
    if ($order->cart_snapshot) {
        $cart_snapshot = json_decode($order->cart_snapshot, true);
    }

    // Resolve hub info from cart_snapshot (frozen at order time) or live fallback
    $hub_name    = null;
    $hub_address = null;
    $hub_phone   = null;
    $hub_logo    = null;

    if ($cart_snapshot && isset($cart_snapshot['hub'])) {
        $h = $cart_snapshot['hub'];
        $hub_name    = isset($h['name'])     ? (string)$h['name']     : null;
        $hub_address = isset($h['address'])  ? (string)$h['address']  : null;
        $hub_phone   = isset($h['phone'])    ? (string)$h['phone']    : null;
        $hub_logo    = isset($h['logo_url']) ? (string)$h['logo_url'] : null;
    }

    // Live fallback if snapshot hub data is missing
    if (!$hub_name) {
        $table_hubs = $wpdb->prefix . 'knx_hubs';
        $hub_row = $wpdb->get_row($wpdb->prepare(
            "SELECT name, address, phone, logo_url FROM {$table_hubs} WHERE id = %d LIMIT 1",
            (int)$order->hub_id
        ));
        if ($hub_row) {
            $hub_name    = $hub_name    ?: (isset($hub_row->name)     ? (string)$hub_row->name     : null);
            $hub_address = $hub_address ?: (isset($hub_row->address)  ? (string)$hub_row->address  : null);
            $hub_phone   = $hub_phone   ?: (isset($hub_row->phone)    ? (string)$hub_row->phone    : null);
            $hub_logo    = $hub_logo    ?: (isset($hub_row->logo_url) ? (string)$hub_row->logo_url : null);
        }
    }

    // Resolve items from knx_order_items (authoritative) with cart_snapshot fallback
    $table_order_items = $wpdb->prefix . 'knx_order_items';
    $db_items = $wpdb->get_results($wpdb->prepare(
        "SELECT name_snapshot, image_snapshot, quantity, unit_price, line_total, modifiers_json
         FROM {$table_order_items}
         WHERE order_id = %d
         ORDER BY id ASC",
        $order_id
    ));

    $items = [];
    if (!empty($db_items)) {
        foreach ($db_items as $it) {
            $mods = null;
            if (!empty($it->modifiers_json)) {
                $decoded = json_decode($it->modifiers_json, true);
                if (json_last_error() === JSON_ERROR_NONE) $mods = $decoded;
            }
            $items[] = [
                'name'       => (string)($it->name_snapshot ?? ''),
                'image'      => !empty($it->image_snapshot) ? (string)$it->image_snapshot : null,
                'quantity'   => (int)($it->quantity ?? 0),
                'unit_price' => (float)($it->unit_price ?? 0),
                'line_total' => (float)($it->line_total ?? 0),
                'modifiers'  => $mods,
            ];
        }
    } elseif ($cart_snapshot && isset($cart_snapshot['items']) && is_array($cart_snapshot['items'])) {
        foreach ($cart_snapshot['items'] as $it) {
            $items[] = [
                'name'       => (string)($it['name_snapshot'] ?? $it['name'] ?? ''),
                'image'      => !empty($it['image_snapshot']) ? (string)$it['image_snapshot'] : null,
                'quantity'   => (int)($it['quantity'] ?? 0),
                'unit_price' => (float)($it['unit_price'] ?? 0),
                'line_total' => (float)($it['line_total'] ?? 0),
                'modifiers'  => isset($it['modifiers']) ? $it['modifiers'] : null,
            ];
        }
    }

    // Fulfillment
    $fulfillment_type = isset($order->fulfillment_type) ? (string)$order->fulfillment_type : 'delivery';

    // Canonical status timeline (ordered progression)
    $canonical_statuses = [
        'order_created',
        'confirmed',
        'accepted_by_driver',
        'accepted_by_hub',
        'preparing',
        'prepared',
        'picked_up',
        'completed',
    ];

    // If pickup, remove driver/delivery-specific statuses
    // Note: backend double-jumps prepared → picked_up automatically for pickup orders.
    // We map 'picked_up' → 'ready_for_pickup' in the timeline for customer UX.
    if ($fulfillment_type === 'pickup') {
        $canonical_statuses = [
            'order_created',
            'confirmed',
            'accepted_by_hub',
            'preparing',
            'ready_for_pickup',  // Maps to backend 'picked_up' (double-jump from 'prepared')
            'completed',
        ];
    }

    // Build status history lookup
    $history_by_status = [];
    foreach ($status_history as $h) {
        $st = (string)$h->status;
        if (!isset($history_by_status[$st])) {
            $history_by_status[$st] = $h;
        }
    }

    // Map order_created from orders.created_at
    $history_by_status['order_created'] = (object)[
        'status'     => 'order_created',
        'changed_by' => null,
        'created_at' => $order->created_at,
    ];

    // Current status position
    $current_status = (string)$order->status;
    
    // For pickup orders: backend sends 'picked_up' (double-jump result), but we show 'ready_for_pickup' to customer
    $timeline_status = $current_status;
    if ($fulfillment_type === 'pickup' && $current_status === 'picked_up') {
        $timeline_status = 'ready_for_pickup';
    }
    
    $current_index  = array_search($timeline_status, $canonical_statuses);

    // Handle confirmed = order_created alias
    if ($timeline_status === 'confirmed' && $current_index === false) {
        $current_index = array_search('confirmed', $canonical_statuses);
    }

    $timeline = [];
    foreach ($canonical_statuses as $i => $st) {
        // Skip pending_payment from timeline
        if ($st === 'pending_payment') continue;

        // For pickup orders: 'ready_for_pickup' maps to backend 'picked_up' status/history
        $db_status = $st;
        if ($fulfillment_type === 'pickup' && $st === 'ready_for_pickup') {
            $db_status = 'picked_up';
        }

        $has_record = isset($history_by_status[$db_status]);
        $is_current = ($st === $timeline_status);
        $is_done    = false;

        if ($current_index !== false) {
            $is_done = ($i <= $current_index);
        } elseif ($has_record) {
            $is_done = true;
        }

        // For cancelled orders
        if ($current_status === 'cancelled') {
            $is_current = ($st === 'cancelled');
            $is_done    = $has_record;
        }

        $labels = [
            'order_created'      => 'Order Created',
            'confirmed'          => 'Confirmed',
            'accepted_by_driver' => 'Driver Assigned',
            'accepted_by_hub'    => 'Restaurant Accepted',
            'preparing'          => 'Preparing',
            'prepared'           => 'Ready',
            'picked_up'          => 'On the Way',
            'ready_for_pickup'   => 'Ready for Pickup',
            'completed'          => 'Completed',
            'cancelled'          => 'Cancelled',
        ];

        $timeline[] = [
            'status'     => $st,
            'label'      => isset($labels[$st]) ? $labels[$st] : ucwords(str_replace('_', ' ', $st)),
            'is_done'    => $is_done,
            'is_current' => $is_current,
            'hidden'     => ($st === 'confirmed'),
            'created_at' => $has_record ? $history_by_status[$db_status]->created_at : null,
        ];
    }

    // Add cancelled to timeline if applicable
    if ($current_status === 'cancelled' && isset($history_by_status['cancelled'])) {
        $timeline[] = [
            'status'     => 'cancelled',
            'label'      => 'Cancelled',
            'is_done'    => true,
            'is_current' => true,
            'created_at' => $history_by_status['cancelled']->created_at,
        ];
    }

    // ── Resolve driver info (if delivery + driver assigned) ──
    $driver_name  = null;
    $driver_phone = null;
    if (!empty($order->driver_id)) {
        $table_drivers = $wpdb->prefix . 'knx_drivers';
        $driver_row = $wpdb->get_row($wpdb->prepare(
            "SELECT full_name, phone FROM {$table_drivers} WHERE id = %d LIMIT 1",
            (int) $order->driver_id
        ));
        if ($driver_row) {
            $driver_name  = $driver_row->full_name ? (string) $driver_row->full_name : null;
            $driver_phone = $driver_row->phone     ? (string) $driver_row->phone     : null;
        }
    }

    // Build response
    return new WP_REST_Response([
        'success' => true,
        'order' => [
            'order_id'         => (int)$order->id,
            'order_number'     => isset($order->order_number) ? (string)$order->order_number : null,
            'hub_id'           => (int)$order->hub_id,
            'status'           => $current_status,
            'fulfillment_type' => $fulfillment_type,
            'created_at'       => $order->created_at,
            'created_at_iso'   => $order->created_at ? date('c', strtotime($order->created_at)) : null,

            'customer' => [
                'name'  => isset($order->customer_name)  ? (string) $order->customer_name  : null,
                'phone' => isset($order->customer_phone) ? (string) $order->customer_phone : null,
            ],

            'restaurant' => [
                'name'     => $hub_name,
                'address'  => $hub_address,
                'phone'    => $hub_phone,
                'logo_url' => $hub_logo,
            ],

            'delivery' => [
                'address' => isset($order->delivery_address) ? (string)$order->delivery_address : null,
                'lat'     => isset($order->delivery_lat) ? (float)$order->delivery_lat : null,
                'lng'     => isset($order->delivery_lng) ? (float)$order->delivery_lng : null,
            ],

            'driver' => [
                'id'    => !empty($order->driver_id) ? (int) $order->driver_id : null,
                'name'  => $driver_name,
                'phone' => $driver_phone,
            ],

            'payment' => [
                'method' => isset($order->payment_method) ? (string)$order->payment_method : null,
                'status' => isset($order->payment_status) ? (string)$order->payment_status : null,
            ],

            'totals' => [
                'subtotal'        => (float)($order->subtotal ?? 0),
                'tax_amount'      => (float)($order->tax_amount ?? 0),
                'delivery_fee'    => (float)($order->delivery_fee ?? 0),
                'software_fee'    => (float)($order->software_fee ?? 0),
                'tip_amount'      => (float)($order->tip_amount ?? 0),
                'discount_amount' => (float)($order->discount_amount ?? 0),
                'total'           => (float)($order->total ?? 0),
            ],

            'notes' => isset($order->notes) ? (string) $order->notes : null,

            'items' => $items,

            'status_history' => $timeline,
        ]
    ], 200);
}
