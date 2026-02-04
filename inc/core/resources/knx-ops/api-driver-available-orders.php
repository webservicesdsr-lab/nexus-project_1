<?php
/**
 * FILE: inc/core/resources/knx-ops/api-driver-available-orders.php
 * Kingdom Nexus — Driver Available Orders (Snapshot-Sealed)
 *
 * Architecture:
 * - Pickup address/coordinates sourced from immutable cart_snapshot (v5)
 * - Fallback to live hub JOIN for legacy orders (pre-snapshot)
 * - Snapshot versioning enforced (v5 = namespaced hub object)
 * - Backward compatible with flat snapshot structure
 *
 * Technical Debt:
 * - JSON decode per-row for snapshot parsing (acceptable for now)
 * - TODO: Move to projection layer for high-volume feeds (200+ orders)
 * - Consider pre-parsing snapshots during order insert or via scheduled job
 *
 * Version History:
 * - v5: Namespaced hub object in snapshot (hub.id, hub.city_id, hub.name, hub.address, hub.lat, hub.lng)
 * - legacy: Flat snapshot structure (hub_name, hub_address, hub_latitude, hub_longitude)
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('knx_v1_register_driver_available_orders_routes')) {
    add_action('rest_api_init', 'knx_v1_register_driver_available_orders_routes');

    function knx_v1_register_driver_available_orders_routes() {
        // Canonical route (what your UI likely calls)
        register_rest_route('knx/v1', '/ops/driver-available-orders', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'knx_v1_driver_available_orders',
            'permission_callback' => 'knx_v1_driver_available_orders_permission',
            'args'                => knx_v1_driver_available_orders_args(),
        ));

        // Alias route (handy for future /drivers/* UIs)
        register_rest_route('knx/v1', '/drivers/available-orders', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'knx_v1_driver_available_orders',
            'permission_callback' => 'knx_v1_driver_available_orders_permission',
            'args'                => knx_v1_driver_available_orders_args(),
        ));
    }
}

if (!function_exists('knx_v1_driver_available_orders_args')) {
    function knx_v1_driver_available_orders_args() {
        return array(
            'range' => array(
                'required' => false,
                'type'     => 'string',
                'default'  => 'recent', // recent shows orders even if none were created "today"
            ),
            'days' => array(
                'required' => false,
                'type'     => 'integer',
                'default'  => 7,
            ),
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
            'statuses' => array(
                'required' => false,
                'type'     => 'string', // comma-separated
                'default'  => 'placed,confirmed,preparing,ready,out_for_delivery',
            ),
            'after' => array(
                'required' => false,
                'type'     => 'string', // MySQL datetime or ISO-ish; best effort parse
            ),
        );
    }
}

if (!function_exists('knx_v1_driver_available_orders_permission')) {
    function knx_v1_driver_available_orders_permission() {
        if (!function_exists('knx_get_session')) {
            return new WP_Error('knx_session_missing', 'Session unavailable.', array('status' => 500));
        }

        $session = knx_get_session();
        $role = ($session && isset($session->role)) ? (string)$session->role : '';

        // Allow drivers + managers + super_admins (canonical role is super_admin)
        if (!in_array($role, array('driver', 'manager', 'super_admin'), true)) {
            return new WP_Error('knx_forbidden', 'Forbidden.', array('status' => 403));
        }

        return true;
    }
}

if (!function_exists('knx_v1_driver_available_orders')) {
    function knx_v1_driver_available_orders(WP_REST_Request $req) {
        global $wpdb;

        // ---------- Driver context (source of truth) ----------
        if (!function_exists('knx_get_driver_context')) {
            return knx_v1_driver_available_orders_ok(array(), array(
                'reason' => 'driver_context_function_missing',
            ));
        }

        $ctx = knx_get_driver_context();
        if (!$ctx || !is_object($ctx)) {
            return knx_v1_driver_available_orders_ok(array(), array(
                'reason' => 'driver_context_unavailable',
            ));
        }

        // In your helpers.php, ctx->driver_id is session user id.
        $driver_user_id = isset($ctx->driver_id) ? intval($ctx->driver_id) : 0;

        // Canonical driver profile PK: ctx->driver->id (knx_drivers.id)
        $driver_profile_id = 0;
        if (isset($ctx->driver) && is_object($ctx->driver) && isset($ctx->driver->id)) {
            $driver_profile_id = intval($ctx->driver->id);
        }

        // ---------- Resolve scope (fail-closed) ----------
        $allowed_hub_ids  = knx_v1_driver_available_orders_extract_hubs($ctx);

        // If hubs not present in ctx, optionally load from table
        if (empty($allowed_hub_ids)) {
            $allowed_hub_ids = knx_v1_driver_available_orders_load_driver_hubs($wpdb, $driver_profile_id, $driver_user_id);
        }

        $allowed_city_ids = knx_v1_driver_available_orders_load_driver_cities($wpdb, $driver_profile_id, $driver_user_id);

        if (empty($allowed_hub_ids) && empty($allowed_city_ids)) {
            return knx_v1_driver_available_orders_ok(array(), array(
                'reason'             => 'scope_empty_fail_closed',
                'driver_user_id'     => $driver_user_id,
                'driver_profile_id'  => $driver_profile_id,
                'allowed_city_ids'   => array(),
                'allowed_hub_ids'    => array(),
            ));
        }

        // ---------- Range support ----------
        $range = sanitize_key((string)$req->get_param('range'));
        if (!$range) $range = 'recent';

        $days = intval($req->get_param('days'));
        if ($days < 1) $days = 1;
        if ($days > 60) $days = 60;

        $limit = intval($req->get_param('limit'));
        if ($limit < 1) $limit = 1;
        if ($limit > 200) $limit = 200;

        $offset = intval($req->get_param('offset'));
        if ($offset < 0) $offset = 0;

        $statuses_csv = (string) $req->get_param('statuses');
        $statuses = knx_v1_driver_available_orders_parse_statuses($statuses_csv);
        if (empty($statuses)) {
            $statuses = array('placed', 'confirmed', 'preparing', 'ready', 'out_for_delivery');
        }

        $after = $req->get_param('after');
        $after_mysql = null;
        $no_after_filter = false;

        // Explicit after parameter overrides range computation
        if (is_string($after) && $after !== '') {
            $parsed = knx_v1_driver_available_orders_parse_after($after);
            if ($parsed) {
                $after_mysql = $parsed;
            }
        }

        // Compute after timestamp based on range if not explicitly provided
        if ($after_mysql === null) {
            if ($range === 'all') {
                $no_after_filter = true;
            } else {
                $after_mysql = knx_v1_driver_available_orders_compute_after_mysql($range, $days);
            }
        }

        // ---------- Build WHERE clause ----------
        $where = array();
        $params = array();

        // Status filter
        if (!empty($statuses)) {
            $status_ph = implode(',', array_fill(0, count($statuses), '%s'));
            $where[] = "o.status IN ($status_ph)";
            foreach ($statuses as $s) {
                $params[] = (string) $s;
            }
        }

        // Time filter
        if (!$no_after_filter && $after_mysql !== null) {
            $where[] = "o.created_at >= %s";
            $params[] = $after_mysql;
        }

        // Scope filter (hubs OR cities)
        $scope_parts = array();

        if (!empty($allowed_hub_ids)) {
            $hub_ph = implode(',', array_fill(0, count($allowed_hub_ids), '%d'));
            $scope_parts[] = "o.hub_id IN ($hub_ph)";
            foreach ($allowed_hub_ids as $hid) $params[] = intval($hid);
        }

        if (!empty($allowed_city_ids)) {
            $city_ph = implode(',', array_fill(0, count($allowed_city_ids), '%d'));
            $scope_parts[] = "o.city_id IN ($city_ph)";
            foreach ($allowed_city_ids as $cid) $params[] = intval($cid);
        }

        if (empty($scope_parts)) {
            return knx_v1_driver_available_orders_ok(array(), array(
                'reason'            => 'scope_empty_after_resolution',
                'driver_user_id'    => $driver_user_id,
                'driver_profile_id' => $driver_profile_id,
            ));
        }

        $where[] = '(' . implode(' OR ', $scope_parts) . ')';

        // Table definitions
        $orders_table = $wpdb->prefix . 'knx_orders';
        $driver_ops_table = $wpdb->prefix . 'knx_driver_ops';
        $hubs_table = $wpdb->prefix . 'knx_hubs';

        // ---------- SQL ----------
        $sql =
            "SELECT
                o.id,
                o.order_number,
                o.hub_id,
                o.city_id,
                o.fulfillment_type,
                o.customer_name,
                o.customer_phone,
                o.customer_email,
                o.delivery_address,
                o.delivery_address AS delivery_address_text,
                o.cart_snapshot,
                h.name AS hub_name_live,
                h.address AS pickup_address_live,
                h.latitude AS pickup_lat_live,
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
                COALESCE(dop.ops_status, 'unassigned') AS ops_status,
                dop.assigned_at,
                dop.driver_user_id,
                dop.assigned_by,
                dop.updated_at AS ops_updated_at
            FROM {$orders_table} o
            LEFT JOIN {$driver_ops_table} dop
                ON dop.order_id = o.id
            LEFT JOIN {$hubs_table} h
                ON h.id = o.hub_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY o.created_at DESC
            LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $prepared = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        if (!is_array($rows)) $rows = array();

        // ---------- Post-process: Prefer snapshot (v5+), fallback to live ----------
        // NOTE: JSON decode per-row is acceptable for now (technical debt for large feeds)
        foreach ($rows as &$row) {
            $snapshot = null;
            $snapshot_version = null;

            if (!empty($row['cart_snapshot'])) {
                $decoded = json_decode($row['cart_snapshot'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $snapshot = $decoded;
                    $snapshot_version = isset($decoded['version']) ? $decoded['version'] : 'legacy';
                }
            }

            // Resolve pickup fields based on snapshot version
            if ($snapshot_version === 'v5' && isset($snapshot['hub']) && is_array($snapshot['hub'])) {
                // v5 snapshot: namespaced hub object
                $hub = $snapshot['hub'];
                $row['hub_name'] = isset($hub['name']) ? $hub['name'] : null;
                $row['pickup_address_text'] = isset($hub['address']) ? $hub['address'] : null;
                $row['pickup_lat'] = isset($hub['lat']) ? $hub['lat'] : null;
                $row['pickup_lng'] = isset($hub['lng']) ? $hub['lng'] : null;
                $row['address_source'] = 'snapshot';
            } elseif ($snapshot && isset($snapshot['hub_name'])) {
                // Legacy flat snapshot structure (backward compatible)
                $row['hub_name'] = $snapshot['hub_name'];
                $row['pickup_address_text'] = isset($snapshot['hub_address']) ? $snapshot['hub_address'] : null;
                $row['pickup_lat'] = isset($snapshot['hub_latitude']) ? $snapshot['hub_latitude'] : null;
                $row['pickup_lng'] = isset($snapshot['hub_longitude']) ? $snapshot['hub_longitude'] : null;
                $row['address_source'] = 'snapshot_legacy';
            } else {
                // Fallback to live hub data (pre-snapshot orders or missing data)
                $row['hub_name'] = isset($row['hub_name_live']) ? $row['hub_name_live'] : null;
                $row['pickup_address_text'] = isset($row['pickup_address_live']) ? $row['pickup_address_live'] : null;
                $row['pickup_lat'] = isset($row['pickup_lat_live']) ? $row['pickup_lat_live'] : null;
                $row['pickup_lng'] = isset($row['pickup_lng_live']) ? $row['pickup_lng_live'] : null;
                $row['address_source'] = 'live';
            }

            // Clean up temporary fields
            unset($row['cart_snapshot']);
            unset($row['hub_name_live']);
            unset($row['pickup_address_live']);
            unset($row['pickup_lat_live']);
            unset($row['pickup_lng_live']);
        }
        unset($row); // Break reference


        // ---------- Meta (helps debug without breaking fail-closed) ----------
        $meta = array(
            'range'             => $range,
            'days'              => $days,
            'after_mysql'        => $no_after_filter ? null : $after_mysql,
            'no_after_filter'   => $no_after_filter,
            'limit'             => $limit,
            'offset'            => $offset,
            'statuses'          => $statuses,
            'allowed_city_ids'  => array_values(array_map('intval', $allowed_city_ids)),
            'allowed_hub_ids'   => array_values(array_map('intval', $allowed_hub_ids)),
            'driver_user_id'    => $driver_user_id,
            'driver_profile_id' => $driver_profile_id,
            'server_gmt'        => gmdate('Y-m-d H:i:s'),
        );

        return knx_v1_driver_available_orders_ok($rows, $meta);
    }
}

/* =========================
 * Helpers
 * ========================= */

if (!function_exists('knx_v1_driver_available_orders_ok')) {
    function knx_v1_driver_available_orders_ok($orders, $meta = array()) {
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

if (!function_exists('knx_v1_driver_available_orders_extract_hubs')) {
    function knx_v1_driver_available_orders_extract_hubs($ctx) {
        $out = array();

        // Many implementations store hubs as IDs or objects.
        if (isset($ctx->hubs) && is_array($ctx->hubs)) {
            foreach ($ctx->hubs as $h) {
                if (is_numeric($h)) {
                    $out[] = intval($h);
                    continue;
                }
                if (is_object($h)) {
                    if (isset($h->hub_id)) $out[] = intval($h->hub_id);
                    elseif (isset($h->id)) $out[] = intval($h->id);
                }
                if (is_array($h)) {
                    if (isset($h['hub_id'])) $out[] = intval($h['hub_id']);
                    elseif (isset($h['id'])) $out[] = intval($h['id']);
                }
            }
        }

        $out = array_values(array_unique(array_filter($out, function($v) { return $v > 0; })));
        return $out;
    }
}

if (!function_exists('knx_v1_driver_available_orders_load_driver_cities')) {
    function knx_v1_driver_available_orders_load_driver_cities($wpdb, $driver_profile_id, $driver_user_id) {
        $table = $wpdb->prefix . 'knx_driver_cities';
        $ids = array();

        // Primary: profile PK (knx_drivers.id)
        if ($driver_profile_id > 0) {
            $sql = $wpdb->prepare("SELECT city_id FROM {$table} WHERE driver_id = %d", $driver_profile_id);
            $rows = $wpdb->get_col($sql);
            if (is_array($rows)) {
                foreach ($rows as $r) $ids[] = intval($r);
            }
        }

        // Fallback: in case some installs stored WP user_id in driver_id historically
        if (empty($ids) && $driver_user_id > 0 && $driver_user_id !== $driver_profile_id) {
            $sql = $wpdb->prepare("SELECT city_id FROM {$table} WHERE driver_id = %d", $driver_user_id);
            $rows = $wpdb->get_col($sql);
            if (is_array($rows)) {
                foreach ($rows as $r) $ids[] = intval($r);
            }
        }

        $ids = array_values(array_unique(array_filter($ids, function($v) { return $v > 0; })));
        return $ids;
    }
}

if (!function_exists('knx_v1_driver_available_orders_load_driver_hubs')) {
    function knx_v1_driver_available_orders_load_driver_hubs($wpdb, $driver_profile_id, $driver_user_id) {
        $table = $wpdb->prefix . 'knx_driver_hubs';
        $ids = array();

        // Primary: profile PK (knx_drivers.id)
        if ($driver_profile_id > 0) {
            $sql = $wpdb->prepare("SELECT hub_id FROM {$table} WHERE driver_id = %d", $driver_profile_id);
            $rows = $wpdb->get_col($sql);
            if (is_array($rows)) {
                foreach ($rows as $r) $ids[] = intval($r);
            }
        }

        // Fallback: in case some installs stored WP user_id
        if (empty($ids) && $driver_user_id > 0 && $driver_user_id !== $driver_profile_id) {
            $sql = $wpdb->prepare("SELECT hub_id FROM {$table} WHERE driver_id = %d", $driver_user_id);
            $rows = $wpdb->get_col($sql);
            if (is_array($rows)) {
                foreach ($rows as $r) $ids[] = intval($r);
            }
        }

        $ids = array_values(array_unique(array_filter($ids, function($v) { return $v > 0; })));
        return $ids;
    }
}

if (!function_exists('knx_v1_driver_available_orders_parse_statuses')) {
    function knx_v1_driver_available_orders_parse_statuses($csv) {
        $csv = trim((string)$csv);
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

if (!function_exists('knx_v1_driver_available_orders_parse_after')) {
    function knx_v1_driver_available_orders_parse_after($after) {
        $after = trim((string)$after);
        if ($after === '') return null;

        // Accept "YYYY-mm-dd HH:ii:ss" or ISO-ish; normalize to "Y-m-d H:i:s" in GMT best-effort.
        try {
            // If it looks like MySQL already, keep it.
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $after)) {
                return $after;
            }

            $dt = new DateTime($after, new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('knx_v1_driver_available_orders_compute_after_mysql')) {
    function knx_v1_driver_available_orders_compute_after_mysql($range, $days) {
        // "today" means start of day in site timezone, converted to UTC for DB comparisons.
        if ($range === 'today') {
            return knx_v1_driver_available_orders_start_of_today_utc_mysql();
        }

        // Default "recent": last N days from now (UTC).
        $seconds = max(1, intval($days)) * 86400;
        $ts = time() - $seconds;
        return gmdate('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('knx_v1_driver_available_orders_start_of_today_utc_mysql')) {
    function knx_v1_driver_available_orders_start_of_today_utc_mysql() {
        // Use WP timezone if available; convert to UTC.
        try {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $now = new DateTime('now', $tz);
            $now->setTime(0, 0, 0);
            $now->setTimezone(new DateTimeZone('UTC'));
            return $now->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            // Fallback: UTC midnight
            return gmdate('Y-m-d 00:00:00');
        }
    }
}
