<?php
/**
 * ████████████████████████████████████████████████████████████████
 * █ KNX-A1.1 — PAYMENT STATE AUTHORITY & DB
 * ████████████████████████████████████████████████████████████████
 * 
 * PURPOSE:
 * - Single Source of Truth for Payment State Machine
 * - Guards to prevent payment logic errors
 * - Database authority for knx_payments table
 * 
 * RULES:
 * - One active payment per order
 * - Payment only for orders with status = 'placed'
 * - Fail-closed on any validation error
 * - No payment modification after success
 * 
 * STATES:
 * - intent_created: Payment intent created, awaiting capture
 * - authorized: Payment authorized but not captured (future)
 * - paid: Payment successfully captured
 * - failed: Payment attempt failed
 * - cancelled: Payment cancelled by user or system
 * 
 * DEPENDENCIES:
 * - knx_orders table (from KNX-A0.9)
 * - knx_payments table (created by this module)
 * 
 * @package KingdomNexus
 * @since A1.1
 */

if (!defined('ABSPATH')) exit;

/**
 * KNX-A1.1.1 — Payments Safe Authority Logger (CANON)
 * 
 * WHY:
 * - Must NEVER fatal (used by payment intent + webhook + polling)
 * - Must be available even if Stripe / helpers are missing
 * - Must not leak secrets (client_secret, keys, full payloads)
 * 
 * USAGE:
 * knx_payments_safe_authority_log('info', 'intent_created', '...', ['order_id'=>1]);
 */
if (!function_exists('knx_payments_safe_authority_log')) {
    function knx_payments_safe_authority_log($level, $code, $message, $context = []) {
        try {
            $lvl = strtolower(trim((string)$level));
            if ($lvl === '') $lvl = 'info';

            // Only log if KNX_DEBUG is enabled OR severity is error-ish
            $should_log = (defined('KNX_DEBUG') && KNX_DEBUG);
            if (in_array($lvl, ['error', 'critical', 'fatal', 'security'], true)) {
                $should_log = true;
            }
            if (!$should_log) return;

            $c = is_array($context) ? $context : [];

            // Hard strip potential secrets / noisy fields
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

                // Avoid dumping arrays/objects
                $safe[$k] = '[omitted]';
            }

            // Keep log lines short
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
            // Absolute fail-safe: never throw from logger
            try {
                error_log('[KNX-PAYMENTS][AUTH][LOGGER_FAIL] ' . substr($e->getMessage(), 0, 120));
            } catch (\Throwable $ignored) {}
        }
    }
}

/**
 * Get payment record by order ID
 * 
 * Returns the FIRST non-failed, non-cancelled payment for an order.
 * If multiple exist (shouldn't happen), returns the most recent.
 * 
 * @param int $order_id Order ID
 * @return object|null Payment record or null
 */
if (!function_exists('knx_get_payment_by_order_id')) {
    function knx_get_payment_by_order_id($order_id) {
        global $wpdb;
        
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knx_payments 
             WHERE order_id = %d 
             AND status NOT IN ('failed', 'cancelled')
             ORDER BY created_at DESC 
             LIMIT 1",
            $order_id
        ));
        
        return $payment ?: null;
    }
}

    /**
     * Attempt to reconcile deferred Stripe webhook events for a given intent.
     * Idempotent and safe to call multiple times.
     *
     * @param string $intent_id
     * @return bool
     */
    if (!function_exists('knx_reconcile_deferred_webhook_for_intent')) {
        function knx_reconcile_deferred_webhook_for_intent($intent_id) {
            global $wpdb;

            $events_table = $wpdb->prefix . 'knx_webhook_events';
            $orders_table = $wpdb->prefix . 'knx_orders';

            // Find deferred/unprocessed events for this intent (processed_at IS NULL)
            $events = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$events_table} WHERE intent_id = %s AND processed_at IS NULL ORDER BY created_at ASC",
                $intent_id
            ));

            if (!$events) {
                return false;
            }

            // Payment must now exist
            $payment = knx_get_payment_by_provider_intent('stripe', $intent_id);
            if (!$payment) {
                return false;
            }

            foreach ($events as $event) {
                $wpdb->query('START TRANSACTION');

                try {
                    // Lock event row
                    $locked = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$events_table} WHERE id = %d FOR UPDATE",
                        (int) $event->id
                    ));

                    if (!$locked || !empty($locked->processed_at)) {
                        $wpdb->query('COMMIT');
                        continue;
                    }

                    // Update payment -> paid (idempotent guard in knx_update_payment_status)
                    if (function_exists('knx_update_payment_status')) {
                        @knx_update_payment_status((int) $payment->id, 'paid');
                    }

                    // Promote order if present and in allowed pre-confirmation state
                    $order = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$orders_table} WHERE id = %d FOR UPDATE",
                        (int) $payment->order_id
                    ));

                    if ($order) {
                        $allowed_pre = ['placed', 'pending'];
                        if (in_array((string) $order->status, $allowed_pre, true)) {
                            $order_updated = $wpdb->update(
                                $orders_table,
                                [
                                    'status'                 => 'confirmed',
                                    'payment_status'         => 'paid',
                                    'payment_method'         => 'stripe',
                                    'payment_transaction_id' => $intent_id,
                                    'updated_at'             => current_time('mysql'),
                                ],
                                ['id' => (int) $order->id],
                                ['%s','%s','%s','%s','%s'],
                                ['%d']
                            );

                            if ($order_updated !== false) {
                                $wpdb->insert(
                                    $wpdb->prefix . 'knx_order_status_history',
                                    [
                                        'order_id'   => (int) $order->id,
                                        'status'     => 'confirmed',
                                        'created_at' => current_time('mysql'),
                                    ],
                                    ['%d','%s','%s']
                                );
                            }
                        }
                    }

                    // Mark webhook event as processed
                    $wpdb->update(
                        $events_table,
                        [
                            'processed_at' => current_time('mysql'),
                            'order_id'     => (int) $payment->order_id,
                        ],
                        ['id' => (int) $event->id],
                        ['%s','%d'],
                        ['%d']
                    );

                    $wpdb->query('COMMIT');

                    error_log('[KNX][RECONCILE] Deferred webhook reconciled | intent=' . $intent_id);

                } catch (\Throwable $e) {
                    $wpdb->query('ROLLBACK');
                    error_log('[KNX][RECONCILE][ERROR] ' . substr($e->getMessage(), 0, 200));
                }
            }

            return true;
        }
    }

/**
 * Check if order can have a new payment created
 * 
 * GUARDS:
 * - Order must exist
 * - Order status must be 'placed'
 * - No existing active payment (not failed/cancelled)
 * - Order must have valid snapshot
 * - Snapshot must be locked
 * 
 * @param int $order_id Order ID
 * @return array ['ok' => bool, 'error' => string|null]
 */
if (!function_exists('knx_can_create_payment_for_order')) {
    function knx_can_create_payment_for_order($order_id) {
        global $wpdb;
        
        // Guard A: Order exists
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, totals_snapshot FROM {$wpdb->prefix}knx_orders WHERE id = %d",
            $order_id
        ));
        
        if (!$order) {
            return ['ok' => false, 'error' => 'Order not found'];
        }
        
        // Guard B: Order status = placed
        if ($order->status !== 'placed') {
            return ['ok' => false, 'error' => 'Order status must be placed (current: ' . $order->status . ')'];
        }
        
        // Guard C: Snapshot exists and is locked
        if (empty($order->totals_snapshot)) {
            return ['ok' => false, 'error' => 'Order has no totals snapshot'];
        }
        
        $snapshot = json_decode($order->totals_snapshot, true);
        if (!$snapshot || !isset($snapshot['is_snapshot_locked'])) {
            return ['ok' => false, 'error' => 'Invalid totals snapshot'];
        }
        
        if ($snapshot['is_snapshot_locked'] !== true) {
            return ['ok' => false, 'error' => 'Snapshot is not locked'];
        }
        
        // Guard D: No active payment exists
        $existing_payment = knx_get_payment_by_order_id($order_id);
        if ($existing_payment) {
            return [
                'ok' => false, 
                'error' => 'Order already has active payment (id: ' . $existing_payment->id . ', status: ' . $existing_payment->status . ')'
            ];
        }
        
        // Guard E: Total > 0
        $total = $snapshot['total'] ?? 0;
        if ($total <= 0) {
            return ['ok' => false, 'error' => 'Order total must be greater than 0'];
        }
        
        return ['ok' => true, 'error' => null];
    }
}

/**
 * Create payment record
 * 
 * CRITICAL: Does NOT validate - caller must use knx_can_create_payment_for_order() first
 * 
 * @param array $data Payment data
 *   - order_id (required)
 *   - provider (required) e.g. 'stripe', 'paypal'
 *   - provider_intent_id (required)
 *   - amount (required) in cents
 *   - currency (required) e.g. 'usd'
 *   - status (required) e.g. 'intent_created'
 * @return int|false Payment ID or false on failure
 */
if (!function_exists('knx_create_payment_record')) {
    function knx_create_payment_record($data) {
        global $wpdb;
        
        // TASK 5.4-P0-002: Validate amount is integer (cents)
        if (!isset($data['amount']) || !is_numeric($data['amount'])) {
            error_log('[KNX-A1.1] CRITICAL: Payment amount must be numeric (cents)');
            return false;
        }
        
        $amount_cents = (int)$data['amount'];
        if ($amount_cents <= 0) {
            error_log('[KNX-A1.1] CRITICAL: Payment amount must be > 0 (got: ' . $amount_cents . ')');
            return false;
        }
        
        // RACE CONDITION GUARD: Re-check for active payment before insert
        $wpdb->query('START TRANSACTION');
        
        try {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, status FROM {$wpdb->prefix}knx_payments 
                 WHERE order_id = %d AND status NOT IN ('failed', 'cancelled') 
                 FOR UPDATE",
                $data['order_id']
            ));
            
            if ($existing) {
                $wpdb->query('ROLLBACK');
                error_log('[KNX-A1.1] RACE: Active payment exists: order_id=' . $data['order_id'] . ' payment_id=' . $existing->id);
                return false;
            }
            
            $result = $wpdb->insert(
                $wpdb->prefix . 'knx_payments',
                [
                    'order_id' => $data['order_id'],
                    'provider' => $data['provider'],
                    'provider_intent_id' => $data['provider_intent_id'],
                    'amount' => $amount_cents,
                    'currency' => strtolower($data['currency']),
                    'status' => $data['status'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );
            
            if (!$result) {
                throw new Exception('Insert failed');
            }
            
            $payment_id = $wpdb->insert_id;
            $wpdb->query('COMMIT');
            
            return $payment_id;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('[KNX-A1.1] Payment insert failed: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Update payment status
 * 
 * GUARDS:
 * - Payment must exist
 * - Cannot update if already in final state (paid, cancelled)
 * 
 * @param int $payment_id Payment ID
 * @param string $new_status New status
 * @return bool Success
 */
if (!function_exists('knx_update_payment_status')) {
    function knx_update_payment_status($payment_id, $new_status) {
        global $wpdb;
        
        // Get current payment
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}knx_payments WHERE id = %d",
            $payment_id
        ));
        
        if (!$payment) {
            error_log("[KNX-A1.1] Payment not found: payment_id=$payment_id");
            return false;
        }
        
        // Guard: Cannot update final states (except failed → paid for retry scenarios)
        $final_states = ['paid', 'cancelled'];
        if (in_array($payment->status, $final_states, true)) {
            error_log("[KNX-A1.1] Cannot update payment in final state: payment_id=$payment_id current_status={$payment->status}");
            return false;
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'knx_payments',
            [
                'status' => $new_status,
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

/**
 * Get payment by provider intent ID
 * 
 * Used for webhook validation
 * 
 * @param string $provider Provider name
 * @param string $provider_intent_id Provider's intent ID
 * @return object|null Payment record or null
 */
if (!function_exists('knx_get_payment_by_provider_intent')) {
    function knx_get_payment_by_provider_intent($provider, $provider_intent_id) {
        global $wpdb;
        
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knx_payments 
             WHERE provider = %s 
             AND provider_intent_id = %s 
             LIMIT 1",
            $provider,
            $provider_intent_id
        ));
        
        return $payment ?: null;
    }
}
