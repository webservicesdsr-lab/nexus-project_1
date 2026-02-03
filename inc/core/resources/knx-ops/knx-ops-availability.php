<?php
/**
 * Canonical availability engine for OPS and DRIVER
 * Provides knx_ops_get_available_orders(array $args)
 *
 * This enforces a single source of truth for available orders.
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('knx_ops_get_available_orders')) {
    function knx_ops_get_available_orders($args = array()) {
        global $wpdb;

        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'days' => 7,
            'statuses' => array('placed','confirmed','preparing','ready','out_for_delivery'),
            'no_after_filter' => false,
            'after_mysql' => '',
            'allowed_city_ids' => array(),
            'allowed_hub_ids' => array(),
            // canonical base rules (can be relaxed by callers)
            'require_payment_valid' => true,
            'require_fulfillment_delivery' => true,
            // driver-specific
            'require_driver_null' => false,
            'require_ops_unassigned' => false,
            // relaxed mode for OPS
            'relaxed' => false,
        );

        $opts = array_merge($defaults, (array)$args);

        $limit = (int)$opts['limit'];
        $offset = (int)$opts['offset'];
        $days = max(1, min(60, (int)$opts['days']));

        $statuses = is_array($opts['statuses']) ? $opts['statuses'] : array();
        if (empty($statuses)) $statuses = $defaults['statuses'];

        $no_after = !empty($opts['no_after_filter']);
        $after_mysql = '';
        if (!empty($opts['after_mysql'])) $after_mysql = $opts['after_mysql'];
        if (!$no_after && empty($after_mysql)) {
            $after_mysql = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        }

        $orders_table = $wpdb->prefix . 'knx_orders';
        $ops_table = $wpdb->prefix . 'knx_driver_ops';

        $where = array();
        $params = array();

        // Fulfillment
        if (!empty($opts['require_fulfillment_delivery']) && !$opts['relaxed']) {
            $where[] = "o.fulfillment_type = %s";
            $params[] = 'delivery';
        }

        // Statuses
        $status_place = implode(',', array_fill(0, count($statuses), '%s'));
        $where[] = "o.status IN ($status_place)";
        foreach ($statuses as $s) $params[] = (string)$s;

        // Payment validity (canonical) unless relaxed
        if (!empty($opts['require_payment_valid']) && !$opts['relaxed']) {
            // payment_status = paid OR payment_method = cash
            $where[] = "(o.payment_status = %s OR LOWER(o.payment_method) = %s)";
            $params[] = 'paid';
            $params[] = 'cash';
        }

        // Date filter
        if (!$no_after && $after_mysql) {
            $where[] = "o.created_at >= %s";
            $params[] = $after_mysql;
        }

        // Driver / ops filters (applied for driver endpoint)
        if (!empty($opts['require_driver_null'])) {
            $where[] = "(o.driver_id IS NULL OR o.driver_id = 0)";
        }
        if (!empty($opts['require_ops_unassigned'])) {
            $where[] = "(dop.driver_user_id IS NULL OR dop.driver_user_id = 0)";
            $where[] = "(dop.ops_status IS NULL OR dop.ops_status = %s)";
            $params[] = 'unassigned';
        }

        // Scope: hubs OR cities
        $scope_parts = array();
        if (!empty($opts['allowed_hub_ids']) && is_array($opts['allowed_hub_ids'])) {
            $hubs = array_values(array_map('intval', $opts['allowed_hub_ids']));
            if (!empty($hubs)) {
                $ph = implode(',', array_fill(0, count($hubs), '%d'));
                $scope_parts[] = "o.hub_id IN ($ph)";
                foreach ($hubs as $v) $params[] = $v;
            }
        }
        if (!empty($opts['allowed_city_ids']) && is_array($opts['allowed_city_ids'])) {
            $cits = array_values(array_map('intval', $opts['allowed_city_ids']));
            if (!empty($cits)) {
                $ph = implode(',', array_fill(0, count($cits), '%d'));
                $scope_parts[] = "o.city_id IN ($ph)";
                foreach ($cits as $v) $params[] = $v;
            }
        }

        if (!empty($scope_parts)) {
            $where[] = '(' . implode(' OR ', $scope_parts) . ')';
        }

        // Base where SQL
        $where_sql = implode(' AND ', $where);

        $sql = "SELECT
            o.id,
            o.order_number,
            o.hub_id,
            o.city_id,
            o.fulfillment_type,
            o.customer_name,
            o.customer_phone,
            o.customer_email,
            o.delivery_address,
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
        LEFT JOIN {$ops_table} dop ON dop.order_id = o.id
        WHERE {$where_sql}
        ORDER BY o.created_at DESC
        LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $prepared = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        if (!is_array($rows)) $rows = array();

        $meta = array(
            'range' => 'recent',
            'days' => $days,
            'after_mysql' => $no_after ? null : $after_mysql,
            'no_after_filter' => (bool)$no_after,
            'limit' => $limit,
            'offset' => $offset,
            'statuses' => $statuses,
            'allowed_city_ids' => !empty($opts['allowed_city_ids']) ? array_values(array_map('intval', (array)$opts['allowed_city_ids'])) : array(),
            'allowed_hub_ids'  => !empty($opts['allowed_hub_ids']) ? array_values(array_map('intval', (array)$opts['allowed_hub_ids'])) : array(),
            'require_payment_valid' => (bool)$opts['require_payment_valid'],
            'require_driver_null' => (bool)$opts['require_driver_null'],
            'require_ops_unassigned' => (bool)$opts['require_ops_unassigned'],
            'server_gmt' => gmdate('Y-m-d H:i:s'),
        );

        return array('orders' => $rows, 'meta' => $meta, 'sql' => isset($prepared) ? $prepared : '');
    }
}
