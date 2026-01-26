<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KINGDOM NEXUS — TAX ENGINE (Hub SSOT)
 * ==========================================================
 *
 * Authority:
 * - SSOT: {$wpdb->prefix}knx_hubs.tax_rate
 *
 * Notes:
 * - This function reads from DB (not pure).
 * - Fail-closed: invalid hub or missing rate => 0.00
 * - Output is always normalized and complete.
 * ==========================================================
 */

if (!function_exists('knx_resolve_tax')) {
    /**
     * Resolve tax for a given taxable base using hub SSOT tax_rate.
     *
     * IMPORTANT:
     * - Caller defines the taxable base.
     * - Per your rule: Service Fee must NOT affect tax base.
     *
     * @param float $tax_base Taxable base (already computed by caller)
     * @param int   $hub_id   Hub ID (required)
     * @return array {
     *   applied: bool,
     *   amount: float,
     *   rate: float,
     *   source: string,
     *   hub_id: int
     * }
     */
    function knx_resolve_tax($tax_base, $hub_id) {
        global $wpdb;

        $tax_base = (float) $tax_base;
        $hub_id   = (int) $hub_id;

        if ($tax_base <= 0 || $hub_id <= 0) {
            return [
                'applied' => false,
                'amount'  => 0.00,
                'rate'    => 0.00,
                'source'  => 'hub_setting',
                'hub_id'  => $hub_id,
            ];
        }

        $table_hubs = $wpdb->prefix . 'knx_hubs';

        $tax_rate = $wpdb->get_var($wpdb->prepare(
            "SELECT tax_rate
             FROM {$table_hubs}
             WHERE id = %d AND status = 'active'
             LIMIT 1",
            $hub_id
        ));

        if ($tax_rate === null) {
            return [
                'applied' => false,
                'amount'  => 0.00,
                'rate'    => 0.00,
                'source'  => 'hub_setting',
                'hub_id'  => $hub_id,
            ];
        }

        $tax_rate = (float) $tax_rate;
        if ($tax_rate < 0) $tax_rate = 0.00;

        $amount = round(($tax_base * $tax_rate) / 100, 2);
        if ($amount < 0) $amount = 0.00;

        return [
            'applied' => ($amount > 0),
            'amount'  => $amount,
            'rate'    => $tax_rate,
            'source'  => 'hub_setting',
            'hub_id'  => $hub_id,
        ];
    }
}
