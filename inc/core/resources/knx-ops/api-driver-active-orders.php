<?php
/**
 * FILE: inc/core/resources/knx-ops/api-driver-active-orders.php
 * Kingdom Nexus — Driver Active Orders (Assigned to current driver)
 *
 * GET /knx/v1/ops/driver-active-orders
 *
 * Canon rules (Driver Flow):
 * - Snapshot v5 ONLY (legacy snapshots are ignored completely)
 * - Fail-closed (backend is the sole authority)
 * - Unified endpoint with two modes:
 *   - List mode: no order_id (TAB 3 driver-live-orders)
 *   - Detail mode: order_id provided (Order Detail /driver-active-orders)
 * - Read-only (no mutations, no cart access, no recalculation)
 * - Response shape is identical in both modes: data.orders[] + data.meta
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('knx_v1_register_driver_active_orders_routes')) {
    add_action('rest_api_init', 'knx_v1_register_driver_active_orders_routes');

    function knx_v1_register_driver_active_orders_routes() {

        register_rest_route('knx/v1', '/ops/driver-active-orders', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => function ($req) {
                // Use canonical wrapper when available.
                if (function_exists('knx_rest_wrap')) {
                    $wrapped = knx_rest_wrap('knx_v1_driver_active_orders');
                    return $wrapped($req);
                }
                return knx_v1_driver_active_orders($req);
            },
            'permission_callback' => 'knx_v1_driver_active_orders_permission',
            'args'                => knx_v1_driver_active_orders_args(),
        ));
    }
}

if (!function_exists('knx_v1_driver_active_orders_args')) {
    function knx_v1_driver_active_orders_args() {
        return array(
            'limit' => array(
                'required' => false,
                'type'     => 'integer',
                'default'  => 50,
            ),
            'offset' => array(
                'required' => false,
                'type'     => 'integer',
                'default'  => 0,
            ),
            'order_id' => array(
                'required' => false,
                'type'     => 'integer',
                'default'  => 0,
            ),
            // Optional override for ops statuses (comma-separated)
            // Default is a safe "active pipeline" set.
            'ops_statuses' => array(
                'required' => false,
                'type'     => 'string',
                'default'  => 'assigned,accepted,preparing,ready,out_for_delivery,picked_up',
            ),
        );
    }
}

if (!function_exists('knx_v1_driver_active_orders_permission')) {
    function knx_v1_driver_active_orders_permission() {
        if (!function_exists('knx_get_driver_context')) {
            return new WP_Error('knx_driver_context_missing', 'Driver context unavailable.', array('status' => 500));
        }

        $ctx = knx_get_driver_context();
        if (!$ctx || !is_object($ctx) || empty($ctx->session) || empty($ctx->session->user_id)) {
            return new WP_Error('knx_forbidden', 'Forbidden.', array('status' => 403));
        }

        // Role gate (fail-closed)
        $role = '';
        if (!empty($ctx->session->role)) $role = (string) $ctx->session->role;

        // Driver flow is driver-only (+ super_admin for debugging/ops).
        if (!in_array($role, array('driver', 'super_admin'), true)) {
            return new WP_Error('knx_forbidden', 'Forbidden.', array('status' => 403));
        }

        return true;
    }
}

if (!function_exists('knx_v1_driver_active_orders')) {
    function knx_v1_driver_active_orders(WP_REST_Request $req) {
        global $wpdb;

        if (!function_exists('knx_get_driver_context')) {
            return new WP_REST_Response(array(
                'success' => false,
                'ok'      => false,
                'data'    => array('reason' => 'driver_context_missing'),
            ), 500);
        }

        $ctx = knx_get_driver_context();
        if (!$ctx || !is_object($ctx) || empty($ctx->session) || empty($ctx->session->user_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'ok'      => false,
                'data'    => array('reason' => 'driver_context_unavailable'),
            ), 403);
        }

        $driver_user_id = (int) $ctx->session->user_id;
        if ($driver_user_id <= 0) {
            return knx_v1_driver_active_orders_ok(array(), array(
                'mode'           => 'list',
                'reason'         => 'driver_user_id_invalid',
                'driver_user_id' => $driver_user_id,
                'server_gmt'     => gmdate('Y-m-d H:i:s'),
            ));
        }

        $limit    = (int) $req->get_param('limit');
        $offset   = (int) $req->get_param('offset');
        $order_id = (int) $req->get_param('order_id');

        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;
        if ($offset < 0) $offset = 0;

        $ops_csv = (string) $req->get_param('ops_statuses');
        $ops_statuses = knx_v1_ops_parse_csv_statuses($ops_csv);
        if (empty($ops_statuses)) {
            $ops_statuses = array('assigned','accepted','preparing','ready','out_for_delivery','picked_up');
        }

        $orders_table  = $wpdb->prefix . 'knx_orders';
        $ops_table     = $wpdb->prefix . 'knx_driver_ops';
        $hubs_table    = $wpdb->prefix . 'knx_hubs';
        $hist_table    = $wpdb->prefix . 'knx_order_status_history';

        // Exclude terminal order statuses (defensive)
        $terminal_order_statuses = array('delivered','cancelled','canceled','refunded','failed');

        // =========================
        // DETAIL MODE (order_id > 0)
        // =========================
        if ($order_id > 0) {
            $ops_ph  = implode(',', array_fill(0, count($ops_statuses), '%s'));
            $term_ph = implode(',', array_fill(0, count($terminal_order_statuses), '%s'));

            $sql = "
                SELECT
                    o.id,
                    o.order_number,
                    o.hub_id,
                    o.city_id,
                    o.fulfillment_type,

                    o.customer_name,
                    o.customer_phone,
                    o.customer_email,

                    o.delivery_address,
                    o.delivery_lat,
                    o.delivery_lng,

                    o.cart_snapshot,

                    h.name      AS hub_name_live,
                    h.address   AS pickup_address_live,
                    h.latitude  AS pickup_lat_live,
                    h.longitude AS pickup_lng_live,

                    o.subtotal,
                    o.tax_amount,
                    o.delivery_fee,
                    o.software_fee,
                    o.tip_amount,
                    o.discount_amount,
                    o.total,

                    o.status,
                    o.payment_method,
                    o.payment_status,

                    o.created_at,
                    o.updated_at,

                    dop.ops_status,
                    dop.assigned_at,
                    dop.updated_at AS ops_updated_at
                FROM {$orders_table} o
                INNER JOIN {$ops_table} dop
                    ON dop.order_id = o.id
                LEFT JOIN {$hubs_table} h
                    ON h.id = o.hub_id
                WHERE o.id = %d
                  AND dop.driver_user_id = %d
                  AND dop.ops_status IN ({$ops_ph})
                  AND o.status NOT IN ({$term_ph})
                LIMIT 1
            ";

            $params = array($order_id, $driver_user_id);
            foreach ($ops_statuses as $s) $params[] = (string) $s;
            foreach ($terminal_order_statuses as $s) $params[] = (string) $s;

            $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
            if (!is_array($row) || empty($row)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'ok'      => false,
                    'data'    => array('reason' => 'order_not_found'),
                ), 404);
            }

            $cart_snapshot_json = isset($row['cart_snapshot']) ? (string) $row['cart_snapshot'] : '';
            $snap = knx_v1_snapshot_v5_decode($cart_snapshot_json);

            // Fail-closed: if snapshot is missing/invalid/not-v5, the order does not exist for driver flow.
            if (!$snap) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'ok'      => false,
                    'data'    => array('reason' => 'order_not_found'),
                ), 404);
            }

            // Items MUST come from snapshot v5 only (SSOT).
            $items = knx_v1_project_items_from_v5($snap);

            // Status history for single order
            $history = array();
            $users_table = $wpdb->users;
            $hist_sql = "
                SELECT
                    h.status,
                    h.changed_by,
                    h.created_at,
                    u.display_name AS changed_by_name
                FROM {$hist_table} h
                LEFT JOIN {$users_table} u
                    ON u.ID = h.changed_by
                WHERE h.order_id = %d
                ORDER BY h.created_at ASC
            ";
            $hist_rows = $wpdb->get_results($wpdb->prepare($hist_sql, array($order_id)), ARRAY_A);
            if (!is_array($hist_rows)) $hist_rows = array();

            foreach ($hist_rows as $h) {
                $changed_by = isset($h['changed_by']) ? (int) $h['changed_by'] : 0;
                $name = !empty($h['changed_by_name']) ? (string) $h['changed_by_name'] : null;

                $history[] = array(
                    'status'          => isset($h['status']) ? (string) $h['status'] : null,
                    'created_at'      => isset($h['created_at']) ? (string) $h['created_at'] : null,
                    'changed_by'      => $changed_by > 0 ? $changed_by : null,
                    'changed_by_name' => $name,
                );
            }

            // Pickup projection: snapshot v5 only (no legacy, no inference).
            $pickup = knx_v1_project_pickup_from_v5($snap);

            // Time slot: passthrough only if present (no inference).
            $time_slot = knx_v1_project_time_slot_from_v5($snap);

            // Totals: snapshot v5 is SSOT; DB is fallback only if snapshot totals missing.
            $totals = knx_v1_project_totals_from_v5($snap);
            $totals_source = $totals['has_snapshot_totals'] ? 'snapshot_v5' : 'db_fallback';

            $delivery_address_text = isset($row['delivery_address']) ? (string) $row['delivery_address'] : '';
            $delivery_lat = isset($row['delivery_lat']) ? $row['delivery_lat'] : null;
            $delivery_lng = isset($row['delivery_lng']) ? $row['delivery_lng'] : null;

            $order_obj = array(
                'id'                   => isset($row['id']) ? (int) $row['id'] : 0,
                'order_number'         => isset($row['order_number']) ? (string) $row['order_number'] : null,

                'hub_id'               => isset($row['hub_id']) ? (int) $row['hub_id'] : null,
                'city_id'              => isset($row['city_id']) ? (int) $row['city_id'] : null,

                'hub_name'             => $pickup['hub_name'],
                'pickup_address_text'  => $pickup['pickup_address_text'],
                'pickup_lat'           => $pickup['pickup_lat'],
                'pickup_lng'           => $pickup['pickup_lng'],
                'address_source'       => $pickup['address_source'],

                'snapshot_version'     => 'v5',

                'fulfillment_type'     => isset($row['fulfillment_type']) ? (string) $row['fulfillment_type'] : null,

                'customer_name'        => isset($row['customer_name']) ? (string) $row['customer_name'] : null,
                'customer_phone'       => isset($row['customer_phone']) ? (string) $row['customer_phone'] : null,
                'customer_email'       => isset($row['customer_email']) ? (string) $row['customer_email'] : null,

                'delivery_address_text'=> $delivery_address_text !== '' ? $delivery_address_text : null,
                'delivery_lat'         => is_numeric($delivery_lat) ? (float) $delivery_lat : null,
                'delivery_lng'         => is_numeric($delivery_lng) ? (float) $delivery_lng : null,

                // Totals (SSOT snapshot v5; DB fallback only)
                'subtotal'             => $totals['has_snapshot_totals'] ? $totals['subtotal'] : (isset($row['subtotal']) ? (float) $row['subtotal'] : 0.0),
                'tax_amount'           => $totals['has_snapshot_totals'] ? $totals['tax_amount'] : (isset($row['tax_amount']) ? (float) $row['tax_amount'] : 0.0),
                'delivery_fee'         => $totals['has_snapshot_totals'] ? $totals['delivery_fee'] : (isset($row['delivery_fee']) ? (float) $row['delivery_fee'] : 0.0),
                'software_fee'         => $totals['has_snapshot_totals'] ? $totals['software_fee'] : (isset($row['software_fee']) ? (float) $row['software_fee'] : 0.0),
                'tip_amount'           => $totals['has_snapshot_totals'] ? $totals['tip_amount'] : (isset($row['tip_amount']) ? (float) $row['tip_amount'] : 0.0),
                'discount_amount'      => $totals['has_snapshot_totals'] ? $totals['discount_amount'] : (isset($row['discount_amount']) ? (float) $row['discount_amount'] : 0.0),
                'total'                => $totals['has_snapshot_totals'] ? $totals['total'] : (isset($row['total']) ? (float) $row['total'] : 0.0),
                'totals_source'        => $totals_source,

                'status'               => isset($row['status']) ? (string) $row['status'] : null,
                'ops_status'           => isset($row['ops_status']) ? (string) $row['ops_status'] : null,

                'payment_method'       => isset($row['payment_method']) ? (string) $row['payment_method'] : null,
                'payment_status'       => isset($row['payment_status']) ? (string) $row['payment_status'] : null,

                'assigned_at'          => isset($row['assigned_at']) ? (string) $row['assigned_at'] : null,
                'created_at'           => isset($row['created_at']) ? (string) $row['created_at'] : null,
                'updated_at'           => isset($row['updated_at']) ? (string) $row['updated_at'] : null,

                'time_slot'            => $time_slot,

                'items'                => $items,
                'status_history'       => $history,
            );

            $meta = array(
                'mode'           => 'detail',
                'order_id'       => $order_id,
                'driver_user_id' => $driver_user_id,
                'server_gmt'     => gmdate('Y-m-d H:i:s'),
            );

            return knx_v1_driver_active_orders_ok(array($order_obj), $meta);
        }

        // =========================
        // LIST MODE (no order_id)
        // =========================
        $ops_ph  = implode(',', array_fill(0, count($ops_statuses), '%s'));
        $term_ph = implode(',', array_fill(0, count($terminal_order_statuses), '%s'));

        $sql = "
            SELECT
                o.id,
                o.order_number,
                o.hub_id,
                o.city_id,
                o.fulfillment_type,

                o.customer_name,
                o.customer_phone,
                o.customer_email,

                o.delivery_address,
                o.delivery_lat,
                o.delivery_lng,

                o.cart_snapshot,

                h.name      AS hub_name_live,
                h.address   AS pickup_address_live,
                h.latitude  AS pickup_lat_live,
                h.longitude AS pickup_lng_live,

                o.subtotal,
                o.tax_amount,
                o.delivery_fee,
                o.software_fee,
                o.tip_amount,
                o.discount_amount,
                o.total,

                o.status,
                o.payment_method,
                o.payment_status,

                o.created_at,
                o.updated_at,

                dop.ops_status,
                dop.assigned_at,
                dop.updated_at AS ops_updated_at
            FROM {$orders_table} o
            INNER JOIN {$ops_table} dop
                ON dop.order_id = o.id
            LEFT JOIN {$hubs_table} h
                ON h.id = o.hub_id
            WHERE dop.driver_user_id = %d
              AND dop.ops_status IN ({$ops_ph})
              AND o.status NOT IN ({$term_ph})
            ORDER BY o.created_at DESC
            LIMIT %d OFFSET %d
        ";

        $params = array($driver_user_id);
        foreach ($ops_statuses as $s) $params[] = (string) $s;
        foreach ($terminal_order_statuses as $s) $params[] = (string) $s;
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($rows)) $rows = array();

        if (empty($rows)) {
            return knx_v1_driver_active_orders_ok(array(), array(
                'mode'           => 'list',
                'limit'          => $limit,
                'offset'         => $offset,
                'ops_statuses'    => array_values($ops_statuses),
                'driver_user_id'  => $driver_user_id,
                'server_gmt'      => gmdate('Y-m-d H:i:s'),
            ));
        }

        // Filter to snapshot v5 ONLY (fail-closed)
        $valid_rows = array();
        $snap_map = array(); // order_id => decoded snapshot
        foreach ($rows as $rr) {
            $oid = isset($rr['id']) ? (int) $rr['id'] : 0;
            if ($oid <= 0) continue;

            $cs = isset($rr['cart_snapshot']) ? (string) $rr['cart_snapshot'] : '';
            $snap = knx_v1_snapshot_v5_decode($cs);
            if (!$snap) continue;

            $snap_map[$oid] = $snap;
            $valid_rows[] = $rr;
        }

        if (empty($valid_rows)) {
            return knx_v1_driver_active_orders_ok(array(), array(
                'mode'           => 'list',
                'limit'          => $limit,
                'offset'         => $offset,
                'ops_statuses'    => array_values($ops_statuses),
                'driver_user_id'  => $driver_user_id,
                'server_gmt'      => gmdate('Y-m-d H:i:s'),
            ));
        }

        // Status history (bulk) is allowed (order state / history), still read-only.
        $order_ids = array();
        foreach ($valid_rows as $r) {
            $oid = isset($r['id']) ? (int) $r['id'] : 0;
            if ($oid > 0) $order_ids[] = $oid;
        }
        $order_ids = array_values(array_unique($order_ids));

        $history_by_order = array();
        if (!empty($order_ids)) {
            $in_ph = implode(',', array_fill(0, count($order_ids), '%d'));
            $users_table = $wpdb->users;

            $hist_sql = "
                SELECT
                    h.order_id,
                    h.status,
                    h.changed_by,
                    h.created_at,
                    u.display_name AS changed_by_name
                FROM {$hist_table} h
                LEFT JOIN {$users_table} u
                    ON u.ID = h.changed_by
                WHERE h.order_id IN ({$in_ph})
                ORDER BY h.created_at ASC
            ";
            $hist_params = array_map('intval', $order_ids);
            $hist_rows = $wpdb->get_results($wpdb->prepare($hist_sql, $hist_params), ARRAY_A);
            if (!is_array($hist_rows)) $hist_rows = array();

            foreach ($hist_rows as $h) {
                $oid = isset($h['order_id']) ? (int) $h['order_id'] : 0;
                if ($oid <= 0) continue;

                if (!isset($history_by_order[$oid])) $history_by_order[$oid] = array();

                $changed_by = isset($h['changed_by']) ? (int) $h['changed_by'] : 0;
                $name = !empty($h['changed_by_name']) ? (string) $h['changed_by_name'] : null;

                $history_by_order[$oid][] = array(
                    'status'          => isset($h['status']) ? (string) $h['status'] : null,
                    'created_at'      => isset($h['created_at']) ? (string) $h['created_at'] : null,
                    'changed_by'      => $changed_by > 0 ? $changed_by : null,
                    'changed_by_name' => $name,
                );
            }
        }

        // Build response orders (flat + explicit)
        $out = array();

        foreach ($valid_rows as $r) {
            $oid = isset($r['id']) ? (int) $r['id'] : 0;
            if ($oid <= 0) continue;

            $snap = isset($snap_map[$oid]) ? $snap_map[$oid] : null;
            if (!is_array($snap)) continue;

            $pickup = knx_v1_project_pickup_from_v5($snap);
            $time_slot = knx_v1_project_time_slot_from_v5($snap);

            // Totals: prefer snapshot v5; DB fallback if missing.
            $totals = knx_v1_project_totals_from_v5($snap);

            $delivery_address_text = isset($r['delivery_address']) ? (string) $r['delivery_address'] : '';
            $delivery_lat = isset($r['delivery_lat']) ? $r['delivery_lat'] : null;
            $delivery_lng = isset($r['delivery_lng']) ? $r['delivery_lng'] : null;

            $out[] = array(
                'id'                   => $oid,
                'order_number'         => isset($r['order_number']) ? (string) $r['order_number'] : null,

                'hub_id'               => isset($r['hub_id']) ? (int) $r['hub_id'] : null,
                'city_id'              => isset($r['city_id']) ? (int) $r['city_id'] : null,

                'hub_name'             => $pickup['hub_name'],
                'pickup_address_text'  => $pickup['pickup_address_text'],
                'pickup_lat'           => $pickup['pickup_lat'],
                'pickup_lng'           => $pickup['pickup_lng'],
                'address_source'       => $pickup['address_source'],

                'snapshot_version'     => 'v5',

                'fulfillment_type'     => isset($r['fulfillment_type']) ? (string) $r['fulfillment_type'] : null,

                'customer_name'        => isset($r['customer_name']) ? (string) $r['customer_name'] : null,
                'customer_phone'       => isset($r['customer_phone']) ? (string) $r['customer_phone'] : null,
                'customer_email'       => isset($r['customer_email']) ? (string) $r['customer_email'] : null,

                'delivery_address_text'=> $delivery_address_text !== '' ? $delivery_address_text : null,
                'delivery_lat'         => is_numeric($delivery_lat) ? (float) $delivery_lat : null,
                'delivery_lng'         => is_numeric($delivery_lng) ? (float) $delivery_lng : null,

                'subtotal'             => $totals['has_snapshot_totals'] ? $totals['subtotal'] : (isset($r['subtotal']) ? (float) $r['subtotal'] : 0.0),
                'tax_amount'           => $totals['has_snapshot_totals'] ? $totals['tax_amount'] : (isset($r['tax_amount']) ? (float) $r['tax_amount'] : 0.0),
                'delivery_fee'         => $totals['has_snapshot_totals'] ? $totals['delivery_fee'] : (isset($r['delivery_fee']) ? (float) $r['delivery_fee'] : 0.0),
                'software_fee'         => $totals['has_snapshot_totals'] ? $totals['software_fee'] : (isset($r['software_fee']) ? (float) $r['software_fee'] : 0.0),
                'tip_amount'           => $totals['has_snapshot_totals'] ? $totals['tip_amount'] : (isset($r['tip_amount']) ? (float) $r['tip_amount'] : 0.0),
                'discount_amount'      => $totals['has_snapshot_totals'] ? $totals['discount_amount'] : (isset($r['discount_amount']) ? (float) $r['discount_amount'] : 0.0),
                'total'                => $totals['has_snapshot_totals'] ? $totals['total'] : (isset($r['total']) ? (float) $r['total'] : 0.0),

                'status'               => isset($r['status']) ? (string) $r['status'] : null,
                'ops_status'           => isset($r['ops_status']) ? (string) $r['ops_status'] : null,

                'payment_method'       => isset($r['payment_method']) ? (string) $r['payment_method'] : null,
                'payment_status'       => isset($r['payment_status']) ? (string) $r['payment_status'] : null,

                'assigned_at'          => isset($r['assigned_at']) ? (string) $r['assigned_at'] : null,
                'created_at'           => isset($r['created_at']) ? (string) $r['created_at'] : null,
                'updated_at'           => isset($r['updated_at']) ? (string) $r['updated_at'] : null,

                'time_slot'            => $time_slot,

                // List mode: do not source items from DB; keep empty array (detail mode provides snapshot items).
                'items'                => array(),

                'status_history'       => isset($history_by_order[$oid]) ? $history_by_order[$oid] : array(),
            );
        }

        $meta = array(
            'mode'           => 'list',
            'limit'          => $limit,
            'offset'         => $offset,
            'ops_statuses'    => array_values($ops_statuses),
            'driver_user_id'  => $driver_user_id,
            'server_gmt'      => gmdate('Y-m-d H:i:s'),
        );

        return knx_v1_driver_active_orders_ok($out, $meta);
    }
}

/* =========================
 * Response helper
 * ========================= */

if (!function_exists('knx_v1_driver_active_orders_ok')) {
    function knx_v1_driver_active_orders_ok($orders, $meta = array()) {
        return new WP_REST_Response(array(
            'success' => true,
            'ok'      => true,
            'data'    => array(
                'orders' => $orders,
                'meta'   => $meta,
            ),
        ), 200);
    }
}

/* =========================
 * CSV helpers
 * ========================= */

if (!function_exists('knx_v1_ops_parse_csv_statuses')) {
    function knx_v1_ops_parse_csv_statuses($csv) {
        $csv = trim((string) $csv);
        if ($csv === '') return array();

        $parts = preg_split('/\s*,\s*/', $csv);
        $out = array();

        foreach ($parts as $p) {
            $p = sanitize_key($p);
            if ($p !== '') $out[] = $p;
        }

        return array_values(array_unique($out));
    }
}

/* =========================
 * Snapshot helpers (v5 ONLY)
 * ========================= */

if (!function_exists('knx_v1_snapshot_v5_decode')) {
    function knx_v1_snapshot_v5_decode($cart_snapshot_json) {
        $cart_snapshot_json = (string) $cart_snapshot_json;
        if ($cart_snapshot_json === '') return null;

        $decoded = json_decode($cart_snapshot_json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) return null;

        $version = isset($decoded['version']) ? (string) $decoded['version'] : '';
        if ($version !== 'v5') return null;

        return $decoded;
    }
}

if (!function_exists('knx_v1_project_pickup_from_v5')) {
    function knx_v1_project_pickup_from_v5($snap) {
        $out = array(
            'hub_name'            => null,
            'pickup_address_text' => null,
            'pickup_lat'          => null,
            'pickup_lng'          => null,
            'address_source'      => null,
        );

        if (!is_array($snap)) return $out;

        if (isset($snap['hub']) && is_array($snap['hub'])) {
            $hub = $snap['hub'];
            $out['hub_name'] = isset($hub['name']) ? (string) $hub['name'] : null;
            $out['pickup_address_text'] = array_key_exists('address', $hub) ? (string) $hub['address'] : null;
            $out['pickup_lat'] = array_key_exists('lat', $hub) && is_numeric($hub['lat']) ? (float) $hub['lat'] : null;
            $out['pickup_lng'] = array_key_exists('lng', $hub) && is_numeric($hub['lng']) ? (float) $hub['lng'] : null;
            $out['address_source'] = 'snapshot_v5';
        }

        return $out;
    }
}

if (!function_exists('knx_v1_project_time_slot_from_v5')) {
    function knx_v1_project_time_slot_from_v5($snap) {
        if (!is_array($snap)) return null;
        return array_key_exists('time_slot', $snap) ? $snap['time_slot'] : null;
    }
}

/* =========================
 * Snapshot v5 projection: items + totals
 * ========================= */

if (!function_exists('knx_v1_project_items_from_v5')) {
    function knx_v1_project_items_from_v5($snap) {
        $out = array();
        if (!is_array($snap)) return $out;

        if (empty($snap['items']) || !is_array($snap['items'])) return $out;

        foreach ($snap['items'] as $it) {
            if (!is_array($it)) continue;

            $item_id = null;
            if (isset($it['item_id'])) $item_id = (int) $it['item_id'];
            elseif (isset($it['id'])) $item_id = (int) $it['id'];

            $name = '';
            if (isset($it['name'])) $name = (string) $it['name'];
            elseif (isset($it['name_snapshot'])) $name = (string) $it['name_snapshot'];
            elseif (isset($it['title'])) $name = (string) $it['title'];

            $image = null;
            if (!empty($it['image'])) $image = (string) $it['image'];
            elseif (!empty($it['image_snapshot'])) $image = (string) $it['image_snapshot'];
            elseif (!empty($it['image_url'])) $image = (string) $it['image_url'];

            $quantity = 0;
            if (isset($it['quantity'])) $quantity = (int) $it['quantity'];
            elseif (isset($it['qty'])) $quantity = (int) $it['qty'];

            $unit_price = 0.0;
            if (isset($it['unit_price']) && is_numeric($it['unit_price'])) $unit_price = (float) $it['unit_price'];
            elseif (isset($it['price']) && is_numeric($it['price'])) $unit_price = (float) $it['price'];

            $line_total = 0.0;
            if (isset($it['line_total']) && is_numeric($it['line_total'])) $line_total = (float) $it['line_total'];
            elseif (isset($it['total']) && is_numeric($it['total'])) $line_total = (float) $it['total'];
            elseif (isset($it['line_total_price']) && is_numeric($it['line_total_price'])) $line_total = (float) $it['line_total_price'];

            $mods = null;
            if (!empty($it['modifiers']) && is_array($it['modifiers'])) {
                $mods = $it['modifiers'];
            } elseif (!empty($it['mods']) && is_array($it['mods'])) {
                $mods = $it['mods'];
            }

            $out[] = array(
                'item_id'        => $item_id,
                'name_snapshot'  => $name,
                'image_snapshot' => $image,
                'quantity'       => $quantity,
                'unit_price'     => $unit_price,
                'line_total'     => $line_total,
                'modifiers'      => $mods,
            );
        }

        return $out;
    }
}

if (!function_exists('knx_v1_project_totals_from_v5')) {
    function knx_v1_project_totals_from_v5($snap) {
        $out = array(
            'has_snapshot_totals' => false,
            'subtotal'            => 0.0,
            'tax_amount'          => 0.0,
            'delivery_fee'        => 0.0,
            'software_fee'        => 0.0,
            'tip_amount'          => 0.0,
            'discount_amount'     => 0.0,
            'total'               => 0.0,
        );

        if (!is_array($snap)) return $out;

        // Prefer a totals container if present (v5 contract may store it here)
        $tot = null;
        if (isset($snap['totals']) && is_array($snap['totals'])) $tot = $snap['totals'];
        elseif (isset($snap['totals_snapshot']) && is_array($snap['totals_snapshot'])) $tot = $snap['totals_snapshot'];

        // If no container, also accept direct top-level keys (still snapshot v5, no inference).
        $src = is_array($tot) ? $tot : $snap;

        $has_any = false;

        // Subtotal
        if (array_key_exists('subtotal', $src) && is_numeric($src['subtotal'])) {
            $out['subtotal'] = (float) $src['subtotal'];
            $has_any = true;
        }

        // Tax
        if (array_key_exists('tax_amount', $src) && is_numeric($src['tax_amount'])) {
            $out['tax_amount'] = (float) $src['tax_amount'];
            $has_any = true;
        } elseif (array_key_exists('tax', $src) && is_numeric($src['tax'])) {
            $out['tax_amount'] = (float) $src['tax'];
            $has_any = true;
        }

        // Delivery fee
        if (array_key_exists('delivery_fee', $src) && is_numeric($src['delivery_fee'])) {
            $out['delivery_fee'] = (float) $src['delivery_fee'];
            $has_any = true;
        }

        // Software fee
        if (array_key_exists('software_fee', $src) && is_numeric($src['software_fee'])) {
            $out['software_fee'] = (float) $src['software_fee'];
            $has_any = true;
        }

        // Tip
        if (array_key_exists('tip_amount', $src) && is_numeric($src['tip_amount'])) {
            $out['tip_amount'] = (float) $src['tip_amount'];
            $has_any = true;
        } elseif (array_key_exists('tip', $src) && is_numeric($src['tip'])) {
            $out['tip_amount'] = (float) $src['tip'];
            $has_any = true;
        }

        // Discount
        if (array_key_exists('discount_amount', $src) && is_numeric($src['discount_amount'])) {
            $out['discount_amount'] = (float) $src['discount_amount'];
            $has_any = true;
        } elseif (array_key_exists('discount', $src) && is_numeric($src['discount'])) {
            $out['discount_amount'] = (float) $src['discount'];
            $has_any = true;
        }

        // Total
        if (array_key_exists('total', $src) && is_numeric($src['total'])) {
            $out['total'] = (float) $src['total'];
            $has_any = true;
        }

        $out['has_snapshot_totals'] = $has_any;

        return $out;
    }
}
