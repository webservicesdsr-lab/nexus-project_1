<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - CART API (Canonical SSOT) — vA (Security First)
 * Endpoint:
 *   POST /wp-json/knx/v1/cart/sync
 * ----------------------------------------------------------
 * Security decisions:
 * - Requires session_token + hub_id (fail-closed)
 * - Ignores client price/name/image (server snapshots from DB)
 * - Computes unit_price from:
 *     hub_items.price + SUM(selected modifier option adjustments)
 * - Rejects cross-hub items (item must belong to hub_id)
 * - No information_schema usage (restricted hosts friendly)
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/cart/sync', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_cart_sync'),
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Handle cart sync request.
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response
 */
function knx_api_cart_sync(WP_REST_Request $req) {
    global $wpdb;

    $table_carts      = $wpdb->prefix . 'knx_carts';
    $table_cart_items = $wpdb->prefix . 'knx_cart_items';
    $table_hubs       = $wpdb->prefix . 'knx_hubs';
    $table_items      = $wpdb->prefix . 'knx_hub_items';
    $table_mods       = $wpdb->prefix . 'knx_item_modifiers';
    $table_opts       = $wpdb->prefix . 'knx_modifier_options';

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid-body'], 400);
    }

    $session_token = isset($body['session_token']) ? sanitize_text_field($body['session_token']) : '';
    $hub_id        = isset($body['hub_id']) ? intval($body['hub_id']) : 0;

    // Back-compat: accept items OR cart key
    $items = [];
    if (isset($body['items']) && is_array($body['items'])) {
        $items = $body['items'];
    } elseif (isset($body['cart']) && is_array($body['cart'])) {
        $items = $body['cart'];
    }

    if ($session_token === '') {
        return new WP_REST_Response(['success' => false, 'error' => 'missing-session-token'], 400);
    }
    if ($hub_id <= 0) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing-hub-id'], 400);
    }

    // Fail-closed: hub must exist
    $hub_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, name, slug, status FROM {$table_hubs} WHERE id = %d LIMIT 1",
            $hub_id
        )
    );
    if (!$hub_row) {
        return new WP_REST_Response(['success' => false, 'error' => 'hub-not-found'], 404);
    }

    $now = current_time('mysql');

    // Hard cap to prevent abuse
    $MAX_ITEMS_PER_CART = 200;
    if (count($items) > $MAX_ITEMS_PER_CART) {
        $items = array_slice($items, 0, $MAX_ITEMS_PER_CART);
    }

    $wpdb->query('START TRANSACTION');

    // Resolve active cart by (session_token, hub_id)
    $cart_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table_carts}
             WHERE session_token = %s AND hub_id = %d AND status = 'active'
             ORDER BY id DESC
             LIMIT 1",
            $session_token,
            $hub_id
        )
    );

    if ($cart_id) {
        // Touch cart
        $wpdb->update(
            $table_carts,
            ['updated_at' => $now],
            ['id' => $cart_id],
            ['%s'],
            ['%d']
        );

        // Clear old items (SSOT replaces all)
        $wpdb->delete($table_cart_items, ['cart_id' => $cart_id], ['%d']);
    } else {
        // Create new cart (customer_id intentionally omitted => NULL)
        $inserted = $wpdb->insert(
            $table_carts,
            [
                'session_token' => $session_token,
                'hub_id'        => $hub_id,
                'subtotal'      => 0.0,
                'status'        => 'active',
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            ['%s', '%d', '%f', '%s', '%s', '%s']
        );

        if (!$inserted) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['success' => false, 'error' => 'cart-insert-failed'], 500);
        }

        $cart_id = (int) $wpdb->insert_id;
    }

    // Insert line items with server-calculated snapshots/prices
    foreach ($items as $raw) {
        $item_id  = isset($raw['item_id']) ? intval($raw['item_id']) : 0;
        $quantity = isset($raw['quantity']) ? intval($raw['quantity']) : 1;

        if ($item_id <= 0) continue;

        $quantity = max(1, $quantity);
        if ($quantity > 500) $quantity = 500;

        // Item must belong to hub_id (blocks cross-hub injection)
        $item_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, hub_id, name, price, image_url, status
                 FROM {$table_items}
                 WHERE id = %d AND hub_id = %d
                 LIMIT 1",
                $item_id,
                $hub_id
            )
        );
        if (!$item_row) continue;
        if (!empty($item_row->status) && $item_row->status !== 'active') continue;

        $base_price = (float) $item_row->price;
        if ($base_price < 0) $base_price = 0.0;

        // Normalize modifiers input (array)
        $mods_in = null;
        if (isset($raw['modifiers']) && is_array($raw['modifiers'])) {
            $mods_in = $raw['modifiers'];
        } elseif (isset($raw['modifiers_json']) && is_string($raw['modifiers_json'])) {
            $decoded = json_decode($raw['modifiers_json'], true);
            if (is_array($decoded)) $mods_in = $decoded;
        }

        // Collect selected option ids grouped by modifier id
        $selected_by_modifier = [];
        $all_option_ids = [];

        if (is_array($mods_in)) {
            foreach ($mods_in as $m) {
                $mid = isset($m['id']) ? intval($m['id']) : 0;
                if ($mid <= 0) continue;

                $opts = isset($m['options']) && is_array($m['options']) ? $m['options'] : [];
                foreach ($opts as $o) {
                    $oid = isset($o['id']) ? intval($o['id']) : 0;
                    if ($oid <= 0) continue;

                    $selected_by_modifier[$mid] = $selected_by_modifier[$mid] ?? [];
                    $selected_by_modifier[$mid][] = $oid;
                    $all_option_ids[] = $oid;
                }
            }
        }

        $all_option_ids = array_values(array_unique(array_filter($all_option_ids)));

        $mods_snapshot = [];
        $mods_total = 0.0;

        if (!empty($all_option_ids)) {
            $placeholders = implode(',', array_fill(0, count($all_option_ids), '%d'));

            // Only allow options that belong to modifiers of this item+hub
            $sql = "
                SELECT
                    mo.id AS option_id,
                    mo.name AS option_name,
                    mo.price_adjustment,
                    mo.modifier_id,
                    im.name AS modifier_name,
                    im.type AS modifier_type,
                    im.required AS modifier_required
                FROM {$table_opts} mo
                INNER JOIN {$table_mods} im ON im.id = mo.modifier_id
                WHERE mo.id IN ({$placeholders})
                  AND im.item_id = %d
                  AND im.hub_id  = %d
            ";

            $args = array_merge($all_option_ids, [$item_id, $hub_id]);
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

            $row_by_opt = [];
            foreach ($rows as $r) {
                $row_by_opt[(int) $r->option_id] = $r;
            }

            foreach ($selected_by_modifier as $mid => $oids) {
                $opts_snap = [];
                $m_name = null;
                $m_type = 'single';
                $m_req  = false;

                foreach ($oids as $oid) {
                    if (empty($row_by_opt[$oid])) continue;
                    $r = $row_by_opt[$oid];

                    // Ensure option belongs to the same modifier selection
                    if ((int) $r->modifier_id !== (int) $mid) continue;

                    $m_name = (string) $r->modifier_name;
                    $m_type = (string) $r->modifier_type;
                    $m_req  = ((int) $r->modifier_required === 1);

                    $adj = (float) $r->price_adjustment;
                    $mods_total += $adj;

                    $opts_snap[] = [
                        'id'               => (int) $r->option_id,
                        'name'             => (string) $r->option_name,
                        'price_adjustment' => (float) $adj,
                    ];
                }

                if ($m_name !== null && !empty($opts_snap)) {
                    $mods_snapshot[] = [
                        'id'       => (int) $mid,
                        'name'     => $m_name,
                        'type'     => $m_type,
                        'required' => $m_req,
                        'options'  => $opts_snap,
                    ];
                }
            }
        }

        $unit_price = $base_price + $mods_total;
        if ($unit_price < 0) $unit_price = 0.0;

        $line_total = $unit_price * $quantity;

        $wpdb->insert(
            $table_cart_items,
            [
                'cart_id'        => $cart_id,
                'item_id'        => $item_id,
                'name_snapshot'  => (string) $item_row->name,
                'image_snapshot' => !empty($item_row->image_url) ? esc_url_raw($item_row->image_url) : null,
                'quantity'       => $quantity,
                'unit_price'     => $unit_price,
                'line_total'     => $line_total,
                'modifiers_json' => !empty($mods_snapshot) ? wp_json_encode($mods_snapshot) : null,
                'created_at'     => $now,
            ],
            ['%d','%d','%s','%s','%d','%f','%f','%s','%s']
        );

        if (!empty($wpdb->last_error)) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['success' => false, 'error' => 'cart-item-insert-failed'], 500);
        }
    }

    $subtotal = (float) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COALESCE(SUM(line_total), 0.0) FROM {$table_cart_items} WHERE cart_id = %d",
            $cart_id
        )
    );
    $item_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COALESCE(SUM(quantity), 0) FROM {$table_cart_items} WHERE cart_id = %d",
            $cart_id
        )
    );

    // Update subtotal
    $wpdb->update(
        $table_carts,
        ['subtotal' => $subtotal, 'updated_at' => $now],
        ['id' => $cart_id],
        ['%f','%s'],
        ['%d']
    );

    $cart_empty = ($item_count === 0);

    if ($cart_empty) {
        $wpdb->update(
            $table_carts,
            ['status' => 'abandoned', 'updated_at' => $now],
            ['id' => $cart_id],
            ['%s','%s'],
            ['%d']
        );
    }

    $cart_status = (string) $wpdb->get_var(
        $wpdb->prepare("SELECT status FROM {$table_carts} WHERE id = %d", $cart_id)
    );

    // Response items (SSOT)
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, item_id, name_snapshot, image_snapshot, quantity, unit_price, line_total, modifiers_json
             FROM {$table_cart_items}
             WHERE cart_id = %d
             ORDER BY id ASC",
            $cart_id
        )
    );

    $formatted_items = [];
    foreach ($rows as $r) {
        $formatted_items[] = [
            'id'         => (int) $r->id,
            'item_id'    => (int) $r->item_id,
            'name'       => (string) $r->name_snapshot,
            'image'      => !empty($r->image_snapshot) ? (string) $r->image_snapshot : null,
            'quantity'   => (int) $r->quantity,
            'unit_price' => (float) $r->unit_price,
            'line_total' => (float) $r->line_total,
            'modifiers'  => !empty($r->modifiers_json) ? json_decode($r->modifiers_json, true) : null,
        ];
    }

    // Availability (soft in cart layer)
    $availability = ['can_order' => true, 'reason' => 'OK', 'message' => '', 'reopen_at' => null, 'source' => 'hub', 'severity' => 'soft'];
    if (function_exists('knx_availability_decision')) {
        $availability = knx_availability_decision($hub_id);
        $availability['severity'] = 'soft';
    }

    $wpdb->query('COMMIT');

    return new WP_REST_Response([
        'success'    => true,
        'cart'       => [
            'id'         => (int) $cart_id,
            'hub_id'     => (int) $hub_id,
            'subtotal'   => (float) $subtotal,
            'item_count' => (int) $item_count,
            'status'     => (string) $cart_status,
        ],
        'cart_empty'   => $cart_empty,
        'hub'          => [
            'id'   => (int) $hub_row->id,
            'name' => (string) $hub_row->name,
            'slug' => (string) $hub_row->slug,
        ],
        'items'        => $formatted_items,
        'availability' => $availability,
    ], 200);
}
