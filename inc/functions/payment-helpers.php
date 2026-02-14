<?php
/**
 * ████████████████████████████████████████████████████████████████
 * █ KNX-A1.1 — PAYMENT STATE AUTHORITY & DB
 * ████████████████████████████████████████████████████████████████
 *
 * NOTE:
 * - checkout_attempt_key is NOT NULL UNIQUE in DB.
 * - This file is SSOT for payments guards + inserts.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('knx_payments_safe_authority_log')) {
    function knx_payments_safe_authority_log($level, $code, $message, $context = []) {
        try {
            $lvl = strtolower(trim((string)$level));
            if ($lvl === '') $lvl = 'info';

            $should_log = (defined('KNX_DEBUG') && KNX_DEBUG);
            if (in_array($lvl, ['error', 'critical', 'fatal', 'security'], true)) {
                $should_log = true;
            }
            if (!$should_log) return;

            $c = is_array($context) ? $context : [];

            $blacklist = [
                'client_secret', 'secret', 'api_key', 'key',
                'card', 'payment_method', 'stripe', 'raw', 'payload',
                'headers', 'cookies', 'session', 'token',
            ];

            $safe = [];
            foreach ($c as $k => $v) {
                $kk = strtolower((string)$k);

                $blocked = false;
                foreach ($blacklist as $b) {
                    if (strpos($kk, $b) !== false) { $blocked = true; break; }
                }
                if ($blocked) continue;

                if (is_scalar($v) || $v === null) {
                    $safe[$k] = $v;
                    continue;
                }

                $safe[$k] = '[omitted]';
            }

            $msg = substr((string)$message, 0, 220);
            $cod = substr((string)$code, 0, 80);

            $suffix = '';
            if (!empty($safe)) {
                $json = wp_json_encode($safe);
                if (is_string($json)) {
                    $suffix = ' ctx=' . substr($json, 0, 400);
                }
            }

            error_log(sprintf('[KNX-PAYMENTS][AUTH][%s][%s] %s%s', strtoupper($lvl), $cod, $msg, $suffix));
        } catch (\Throwable $e) {
            try { error_log('[KNX-PAYMENTS][AUTH][LOGGER_FAIL] ' . substr($e->getMessage(), 0, 120)); } catch (\Throwable $ignored) {}
        }
    }
}

if (!function_exists('knx_get_payment_by_order_id')) {
    function knx_get_payment_by_order_id($order_id) {
        global $wpdb;

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knx_payments
             WHERE order_id = %d
               AND status NOT IN ('failed', 'cancelled')
             ORDER BY created_at DESC
             LIMIT 1",
            (int)$order_id
        ));

        return $payment ?: null;
    }
}

/**
 * Check if order can have a new payment created
 *
 * GUARDS:
 * - Order must exist
 * - Order status must be eligible for payment creation:
 *     pending_payment OR placed
 * - Snapshot exists and is locked
 * - No existing active payment (not failed/cancelled)
 * - Total > 0
 *
 * @param int $order_id
 * @return array ['ok' => bool, 'error' => string|null]
 */
if (!function_exists('knx_can_create_payment_for_order')) {
    function knx_can_create_payment_for_order($order_id) {
        global $wpdb;

        $order_id = (int)$order_id;
        if ($order_id <= 0) {
            return ['ok' => false, 'error' => 'Invalid order id'];
        }

        // Guard A: Order exists
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, payment_status, totals_snapshot
             FROM {$wpdb->prefix}knx_orders
             WHERE id = %d
             LIMIT 1",
            $order_id
        ));

        if (!$order) {
            return ['ok' => false, 'error' => 'Order not found'];
        }

        // Guard B: Order payment_status must NOT be paid (hard stop)
        $ps = strtolower((string)($order->payment_status ?? ''));
        if ($ps === 'paid') {
            return ['ok' => false, 'error' => 'Order already paid'];
        }

        // Guard C: Order status eligible for payment creation (align with create-intent endpoint)
        $eligible_order_states = ['pending_payment', 'placed'];
        $os = (string)($order->status ?? '');

        if (!in_array($os, $eligible_order_states, true)) {
            return ['ok' => false, 'error' => 'Order not eligible for payment (status: ' . $os . ')'];
        }

        // Guard D: Snapshot exists and is locked
        if (empty($order->totals_snapshot)) {
            return ['ok' => false, 'error' => 'Order has no totals snapshot'];
        }

        $snapshot = json_decode((string)$order->totals_snapshot, true);
        if (!is_array($snapshot) || !array_key_exists('is_snapshot_locked', $snapshot)) {
            return ['ok' => false, 'error' => 'Invalid totals snapshot'];
        }

        if ($snapshot['is_snapshot_locked'] !== true) {
            return ['ok' => false, 'error' => 'Snapshot is not locked'];
        }

        // Guard E: No active payment exists
        $existing_payment = knx_get_payment_by_order_id($order_id);
        if ($existing_payment) {
            return [
                'ok' => false,
                'error' => 'Order already has active payment (id: ' . $existing_payment->id . ', status: ' . $existing_payment->status . ')'
            ];
        }

        // Guard F: Total > 0
        $total = isset($snapshot['total']) ? (float)$snapshot['total'] : 0.0;
        if ($total <= 0) {
            return ['ok' => false, 'error' => 'Order total must be greater than 0'];
        }

        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('knx_create_payment_record')) {
    function knx_create_payment_record($data) {
        global $wpdb;

        $order_id = isset($data['order_id']) ? (int)$data['order_id'] : 0;
        $provider = isset($data['provider']) ? (string)$data['provider'] : '';
        $intent_id = isset($data['provider_intent_id']) ? (string)$data['provider_intent_id'] : '';
        $attempt_key = isset($data['checkout_attempt_key']) ? (string)$data['checkout_attempt_key'] : '';
        $currency = isset($data['currency']) ? strtolower((string)$data['currency']) : 'usd';
        $status = isset($data['status']) ? (string)$data['status'] : '';

        if ($order_id <= 0) {
            error_log('[KNX-A1.1] CRITICAL: Missing/invalid order_id for payment insert');
            return false;
        }
        if ($provider === '' || $intent_id === '' || $status === '') {
            error_log('[KNX-A1.1] CRITICAL: Missing provider/provider_intent_id/status for payment insert');
            return false;
        }
        if ($attempt_key === '') {
            error_log('[KNX-A1.1] CRITICAL: Missing checkout_attempt_key for payment insert');
            return false;
        }
        if ($currency === '') $currency = 'usd';

        if (!isset($data['amount']) || !is_numeric($data['amount'])) {
            error_log('[KNX-A1.1] CRITICAL: Payment amount must be numeric (cents)');
            return false;
        }

        $amount_cents = (int)$data['amount'];
        if ($amount_cents <= 0) {
            error_log('[KNX-A1.1] CRITICAL: Payment amount must be > 0 (got: ' . $amount_cents . ')');
            return false;
        }

        $payments_table = $wpdb->prefix . 'knx_payments';

        $wpdb->query('START TRANSACTION');

        try {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, status
                 FROM {$payments_table}
                 WHERE order_id = %d
                   AND status NOT IN ('failed', 'cancelled')
                 ORDER BY created_at DESC
                 LIMIT 1
                 FOR UPDATE",
                $order_id
            ));

            if ($existing) {
                $wpdb->query('ROLLBACK');
                error_log('[KNX-A1.1] RACE: Active payment exists: order_id=' . $order_id . ' payment_id=' . $existing->id);
                return false;
            }

            $attempt_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$payments_table}
                 WHERE checkout_attempt_key = %s
                 LIMIT 1",
                $attempt_key
            ));

            if ((int)$attempt_exists > 0) {
                $wpdb->query('ROLLBACK');
                error_log('[KNX-A1.1] CRITICAL: Duplicate checkout_attempt_key generated: ' . $attempt_key);
                return false;
            }

            $now = current_time('mysql');

            $result = $wpdb->insert(
                $payments_table,
                [
                    'order_id'             => $order_id,
                    'provider'             => $provider,
                    'provider_intent_id'   => $intent_id,
                    'checkout_attempt_key' => $attempt_key,
                    'amount'               => $amount_cents,
                    'currency'             => $currency,
                    'status'               => $status,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ],
                ['%d','%s','%s','%s','%d','%s','%s','%s','%s']
            );

            if ($result === false) {
                throw new Exception('Insert failed');
            }

            $payment_id = (int)$wpdb->insert_id;
            $wpdb->query('COMMIT');

            return $payment_id;

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('[KNX-A1.1] Payment insert failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('knx_update_payment_status')) {
    function knx_update_payment_status($payment_id, $new_status) {
        global $wpdb;

        $payment_id = (int)$payment_id;

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}knx_payments WHERE id = %d",
            $payment_id
        ));

        if (!$payment) {
            error_log("[KNX-A1.1] Payment not found: payment_id=$payment_id");
            return false;
        }

        $final_states = ['paid', 'cancelled'];
        if (in_array($payment->status, $final_states, true)) {
            error_log("[KNX-A1.1] Cannot update payment in final state: payment_id=$payment_id current_status={$payment->status}");
            return false;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'knx_payments',
            [
                'status' => (string)$new_status,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $payment_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($result !== false) {
            error_log("[KNX-A1.1] Payment status updated: payment_id=$payment_id {$payment->status}→$new_status");
        }

        return $result !== false;
    }
}

if (!function_exists('knx_get_payment_by_provider_intent')) {
    function knx_get_payment_by_provider_intent($provider, $provider_intent_id) {
        global $wpdb;

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knx_payments
             WHERE provider = %s
               AND provider_intent_id = %s
             LIMIT 1",
            (string)$provider,
            (string)$provider_intent_id
        ));

        return $payment ?: null;
    }
}
