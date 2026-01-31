<?php
/**
 * ████████████████████████████████████████████████████████████████
 * █ KNX-A1.2 — STRIPE WEBHOOK HANDLER (PAYMENT CONFIRMATION AUTHORITY)
 * ████████████████████████████████████████████████████████████████
 *
 * ENDPOINT: POST /wp-json/knx/v1/payments/webhook
 *
 * PURPOSE:
 * - Receive Stripe webhook events securely
 * - Verify webhook signature (mandatory)
 * - Confirm payment success (backend authority)
 * - Update payment + order state atomically
 * - Fully idempotent, fail-closed, SSOT-compliant
 *
 * EVENTS HANDLED:
 * - payment_intent.succeeded
 * - payment_intent.payment_failed
 *
 * IMPORTANT (RETRY-SAFE):
 * - If the webhook arrives BEFORE the payment record exists in DB,
 *   the event is DEFERRED and acknowledged (200). The deferred event
 *   is persisted to `knx_webhook_events` and will be reconciled once
 *   the payment record becomes available.
 * - We must NOT mark the event as processed until reconciliation completes.
 *
 * DEPENDENCIES (expected to exist in your codebase):
 * - knx_get_stripe_webhook_secret()
 * - knx_stripe_is_available()
 * - knx_get_payment_by_provider_intent($provider, $intent_id)
 * - knx_update_payment_status($payment_id, $new_status)
 * - knx_rest_response($ok, $message, $data, $http_status)
 * - knx_rest_error($message, $http_status)
 * - knx_stripe_authority_log($level, $code, $message, $meta)
 *
 * @package KingdomNexus
 * @since A1.2
 */

if (!defined('ABSPATH')) exit;

/**
 * Register Payment Webhook Endpoint
 */
add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/payments/webhook', [
        'methods'             => 'POST',
        'callback'            => 'knx_api_payment_webhook',
        'permission_callback' => '__return_true', // Stripe doesn't use WP auth/session
    ]);
});

/**
 * Ensure webhook events table exists (lazy, idempotent, prefix-safe).
 *
 * @return bool
 */
if (!function_exists('knx_payments_ensure_webhook_events_table')) {
    function knx_payments_ensure_webhook_events_table() {
        global $wpdb;

        static $ready = null;
        if ($ready !== null) return $ready;

        $table = $wpdb->prefix . 'knx_webhook_events';

        // Check via information_schema for accuracy.
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = %s
                 LIMIT 1",
                $table
            )
        );

        if ($exists) {
            $ready = true;
            return true;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `provider` varchar(50) NOT NULL DEFAULT 'stripe',
            `event_id` varchar(255) NOT NULL COMMENT 'Stripe event ID (evt_xxx)',
            `event_type` varchar(100) NOT NULL COMMENT 'payment_intent.succeeded, etc',
            `intent_id` varchar(255) DEFAULT NULL COMMENT 'provider_intent_id (pi_xxx)',
            `order_id` bigint(20) unsigned DEFAULT NULL,
            `processed_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `event_id` (`event_id`),
            KEY `intent_id` (`intent_id`),
            KEY `order_id` (`order_id`),
            KEY `processed_at` (`processed_at`)
        ) ENGINE=InnoDB {$charset_collate};";

        $ok = $wpdb->query($sql);

        if ($ok === false) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_events_table_create_failed', 'Failed creating webhook events table', [
                    'table'    => $table,
                    'db_error' => (string) $wpdb->last_error,
                ]);
            }
            $ready = false;
            return false;
        }

        $ready = true;
        return true;
    }
}

/**
 * API: Stripe Webhook Handler
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
if (!function_exists('knx_api_payment_webhook')) {
    function knx_api_payment_webhook($request) {
        global $wpdb;

        // ───────────────────────────────────────────────────────────
        // STEP 1: Raw payload + signature header (fail-closed)
        // ───────────────────────────────────────────────────────────
        $payload = (string) $request->get_body();

        $sig_header = $request->get_header('stripe-signature');
        if (empty($sig_header)) {
            $sig_header = $request->get_header('Stripe-Signature');
        }

        if (empty($sig_header)) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_signature_missing', 'Webhook rejected - missing signature header');
            }
            return knx_rest_error('Missing signature', 400);
        }

        // ───────────────────────────────────────────────────────────
        // STEP 2: SSOT webhook secret + SDK availability
        // ───────────────────────────────────────────────────────────
        $webhook_secret = function_exists('knx_get_stripe_webhook_secret') ? knx_get_stripe_webhook_secret() : null;

        if (empty($webhook_secret)) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_secret_missing', 'Webhook secret not configured');
            }
            return knx_rest_error('Webhook not configured', 500);
        }

        if (!function_exists('knx_stripe_init') || !knx_stripe_init()) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_stripe_unavailable', 'Stripe not initialized for webhook processing');
            }
            return knx_rest_error('Payment system unavailable', 500);
        }

        // ───────────────────────────────────────────────────────────
        // STEP 3: Verify signature + parse event (fail-closed)
        // ───────────────────────────────────────────────────────────
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
        } catch (\UnexpectedValueException $e) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('warn', 'webhook_invalid_payload', 'Invalid webhook payload (JSON parse)', [
                    'msg' => substr($e->getMessage(), 0, 160),
                ]);
            }
            return knx_rest_error('Invalid payload', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('warn', 'webhook_signature_invalid', 'Webhook signature verification failed', [
                    'msg' => substr($e->getMessage(), 0, 160),
                ]);
            }
            return knx_rest_error('Invalid signature', 400);
        } catch (\Throwable $e) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_event_parse_failed', 'Unexpected error constructing webhook event', [
                    'msg' => substr($e->getMessage(), 0, 160),
                ]);
            }
            return knx_rest_error('Webhook error', 500);
        }

        // ───────────────────────────────────────────────────────────
        // STEP 4: Allow only specific event types
        // ───────────────────────────────────────────────────────────
        $allowed_events = [
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
        ];

        if (!in_array($event->type, $allowed_events, true)) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('info', 'webhook_event_ignored', 'Ignored event type', [
                    'event_id'   => $event->id,
                    'event_type' => $event->type,
                ]);
            }
            return knx_rest_response(true, 'Event ignored', null, 200);
        }

        // ───────────────────────────────────────────────────────────
        // STEP 5: Extract intent data early (before any dedup insert)
        // ───────────────────────────────────────────────────────────
        $intent = $event->data->object ?? null;

        $intent_id = isset($intent->id) ? (string) $intent->id : '';
        $amount_received = isset($intent->amount_received) ? (int) $intent->amount_received : null; // cents
        $currency = isset($intent->currency) ? strtolower((string) $intent->currency) : null;

        if (empty($intent_id)) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('warn', 'webhook_missing_intent_id', 'PaymentIntent ID missing from event', [
                    'event_id'   => $event->id,
                    'event_type' => $event->type,
                ]);
            }
            return knx_rest_response(true, 'Missing intent ID', null, 200);
        }

        // ───────────────────────────────────────────────────────────
        // STEP 6: Locate payment BEFORE dedup insert (RETRY-SAFE)
        // If payment doesn't exist yet => return 503 to force Stripe retry.
        // ───────────────────────────────────────────────────────────
        if (!function_exists('knx_get_payment_by_provider_intent')) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_payment_helpers_missing', 'Payment helpers not loaded');
            }
            return knx_rest_error('System unavailable', 500);
        }

        $payment = knx_get_payment_by_provider_intent('stripe', $intent_id);

        if (!$payment) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('info', 'webhook_payment_unmapped', 'Payment not found yet for intent (deferring event)', [
                    'event_id'   => $event->id,
                    'event_type' => $event->type,
                    'intent_id'  => $intent_id,
                ]);
            }

            // Ensure events table exists before attempting to persist deferred event
            if (!knx_payments_ensure_webhook_events_table()) {
                if (function_exists('knx_stripe_authority_log')) {
                    knx_stripe_authority_log('error', 'webhook_events_table_missing', 'Webhook events table missing while deferring event', [
                        'intent_id' => $intent_id,
                        'event_id'  => $event->id,
                    ]);
                }
                return knx_rest_error('DB not ready', 500);
            }

            $events_table = $wpdb->prefix . 'knx_webhook_events';

            $insert_ok = $wpdb->insert(
                $events_table,
                [
                    'provider'     => 'stripe',
                    'event_id'     => (string) $event->id,
                    'event_type'   => (string) $event->type,
                    'intent_id'    => (string) $intent_id,
                    'order_id'     => null,
                    'processed_at' => null,
                    'created_at'   => current_time('mysql'),
                ],
                ['%s','%s','%s','%s','%d','%s','%s']
            );

            if ($insert_ok === false) {
                // If duplicate (rare), treat as deferred success
                $last_error = (string) ($wpdb->last_error ?? '');
                if (stripos($last_error, 'duplicate') !== false) {
                    if (function_exists('knx_stripe_authority_log')) {
                        knx_stripe_authority_log('info', 'webhook_event_deferred_duplicate', 'Deferred webhook event already recorded', [
                            'event_id' => $event->id,
                            'intent_id' => $intent_id,
                        ]);
                    }
                } else {
                    if (function_exists('knx_stripe_authority_log')) {
                        knx_stripe_authority_log('error', 'webhook_event_defer_failed', 'Failed to persist deferred webhook event', [
                            'event_id' => $event->id,
                            'intent_id' => $intent_id,
                            'db_error' => $last_error,
                        ]);
                    }
                    return knx_rest_error('DB error', 500);
                }
            }

            error_log('[KNX][WEBHOOK] Payment not mapped yet → deferred | intent=' . $intent_id);

            // Acknowledge webhook so Stripe will not retry indefinitely
            return knx_rest_response(true, 'Event deferred', null, 200);
        }

        // ───────────────────────────────────────────────────────────
        // STEP 7: Ensure dedup table exists (prefix-safe)
        // ───────────────────────────────────────────────────────────
        if (!knx_payments_ensure_webhook_events_table()) {
            return knx_rest_error('DB not ready', 500);
        }

        $events_table = $wpdb->prefix . 'knx_webhook_events';

        // ───────────────────────────────────────────────────────────
        // STEP 8: Transaction + event dedup insert-first (idempotent)
        // ───────────────────────────────────────────────────────────
        $wpdb->query('START TRANSACTION');

        $insert_ok = $wpdb->insert(
            $events_table,
            [
                'provider'     => 'stripe',
                'event_id'     => (string) $event->id,
                'event_type'   => (string) $event->type,
                'intent_id'    => (string) $intent_id,
                'order_id'     => null,
                'processed_at' => null,
                'created_at'   => current_time('mysql'),
            ],
            ['%s','%s','%s','%s','%d','%s','%s']
        );

        if ($insert_ok === false) {
            $last_error = (string) ($wpdb->last_error ?? '');
            $wpdb->query('ROLLBACK');

            if (stripos($last_error, 'duplicate') !== false) {
                if (function_exists('knx_stripe_authority_log')) {
                    knx_stripe_authority_log('info', 'webhook_event_duplicate', 'Duplicate webhook event ignored', [
                        'event_id'   => $event->id,
                        'event_type' => $event->type,
                        'intent_id'  => $intent_id,
                    ]);
                }
                return knx_rest_response(true, 'Already processed', null, 200);
            }

            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_event_insert_failed', 'Failed to insert webhook event', [
                    'event_id'  => $event->id,
                    'db_error'  => $last_error,
                ]);
            }

            return knx_rest_error('DB error', 500);
        }

        $event_row_id = (int) $wpdb->insert_id;

        // ───────────────────────────────────────────────────────────
        // STEP 9: Lock payment + order rows (FOR UPDATE)
        // ───────────────────────────────────────────────────────────
        $payment_locked = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knx_payments WHERE id = %d FOR UPDATE",
            (int) $payment->id
        ));

        if (!$payment_locked) {
            $wpdb->query('ROLLBACK');

            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_payment_lock_failed', 'Failed to lock payment row', [
                    'payment_id' => (int) $payment->id,
                    'event_id'   => $event->id,
                ]);
            }

            return knx_rest_error('DB error', 500);
        }

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knx_orders WHERE id = %d FOR UPDATE",
            (int) $payment_locked->order_id
        ));

        if (!$order) {
            // Mark event processed and commit (no further side effects possible)
            $wpdb->update(
                $events_table,
                ['processed_at' => current_time('mysql'), 'order_id' => null],
                ['id' => $event_row_id],
                ['%s','%d'],
                ['%d']
            );
            $wpdb->query('COMMIT');

            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_order_not_found', 'Order not found for payment', [
                    'payment_id' => (int) $payment_locked->id,
                    'order_id'   => (int) $payment_locked->order_id,
                    'event_id'   => $event->id,
                ]);
            }

            return knx_rest_response(true, 'Order not found', null, 200);
        }

        // ───────────────────────────────────────────────────────────
        // STEP 10: Currency guard (fail-closed)
        // ───────────────────────────────────────────────────────────
        if (!empty($payment_locked->currency) && !empty($currency)) {
            $db_currency = strtolower((string) $payment_locked->currency);

            if ($db_currency !== $currency) {
                if (function_exists('knx_update_payment_status')) {
                    knx_update_payment_status((int) $payment_locked->id, 'failed');
                }

                $wpdb->update(
                    $events_table,
                    ['processed_at' => current_time('mysql'), 'order_id' => (int) $order->id],
                    ['id' => $event_row_id],
                    ['%s','%d'],
                    ['%d']
                );
                $wpdb->query('COMMIT');

                if (function_exists('knx_stripe_authority_log')) {
                    knx_stripe_authority_log('warn', 'webhook_currency_mismatch', 'Currency mismatch detected', [
                        'payment_id'       => (int) $payment_locked->id,
                        'order_id'         => (int) $order->id,
                        'db_currency'      => $db_currency,
                        'stripe_currency'  => $currency,
                        'event_id'         => $event->id,
                    ]);
                }

                return knx_rest_response(true, 'Currency mismatch', null, 200);
            }
        }

        // ───────────────────────────────────────────────────────────
        // STEP 11: Idempotency guard by payment final states
        // Treat only paid/cancelled as final at webhook layer.
        // ───────────────────────────────────────────────────────────
        $final_states = ['paid', 'cancelled'];

        if (in_array((string) $payment_locked->status, $final_states, true)) {
            $wpdb->update(
                $events_table,
                ['processed_at' => current_time('mysql'), 'order_id' => (int) $order->id],
                ['id' => $event_row_id],
                ['%s','%d'],
                ['%d']
            );
            $wpdb->query('COMMIT');

            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('info', 'webhook_payment_already_final', 'Payment already in final state - idempotent', [
                    'payment_id'     => (int) $payment_locked->id,
                    'order_id'       => (int) $order->id,
                    'payment_status' => (string) $payment_locked->status,
                    'event_id'       => $event->id,
                    'event_type'     => $event->type,
                ]);
            }

            return knx_rest_response(true, 'Already processed', null, 200);
        }

        // ───────────────────────────────────────────────────────────
        // STEP 12: Handle event types (ATOMIC)
        // ───────────────────────────────────────────────────────────
        try {
            if ($event->type === 'payment_intent.succeeded') {
                // Amount validation (Stripe gives cents)
                if (empty($amount_received) || $amount_received <= 0) {
                    if (function_exists('knx_update_payment_status')) {
                        knx_update_payment_status((int) $payment_locked->id, 'failed');
                    }

                    $wpdb->update(
                        $events_table,
                        ['processed_at' => current_time('mysql'), 'order_id' => (int) $order->id],
                        ['id' => $event_row_id],
                        ['%s','%d'],
                        ['%d']
                    );
                    $wpdb->query('COMMIT');

                    if (function_exists('knx_stripe_authority_log')) {
                        knx_stripe_authority_log('warn', 'webhook_invalid_amount', 'Invalid amount_received for succeeded event', [
                            'payment_id'       => (int) $payment_locked->id,
                            'order_id'         => (int) $order->id,
                            'amount_received'  => $amount_received,
                            'event_id'         => $event->id,
                        ]);
                    }

                    return knx_rest_response(true, 'Invalid amount', null, 200);
                }

                $expected_amount_cents = (int) $payment_locked->amount;

                if ($expected_amount_cents <= 0) {
                    if (function_exists('knx_update_payment_status')) {
                        knx_update_payment_status((int) $payment_locked->id, 'failed');
                    }

                    $wpdb->update(
                        $events_table,
                        ['processed_at' => current_time('mysql'), 'order_id' => (int) $order->id],
                        ['id' => $event_row_id],
                        ['%s','%d'],
                        ['%d']
                    );
                    $wpdb->query('COMMIT');

                    if (function_exists('knx_stripe_authority_log')) {
                        knx_stripe_authority_log('error', 'webhook_invalid_db_amount', 'Invalid expected DB amount', [
                            'payment_id'             => (int) $payment_locked->id,
                            'order_id'               => (int) $order->id,
                            'expected_amount_cents'  => $expected_amount_cents,
                            'event_id'               => $event->id,
                        ]);
                    }

                    return knx_rest_response(true, 'Invalid payment record', null, 200);
                }

                if ((int) $amount_received !== $expected_amount_cents) {
                    if (function_exists('knx_update_payment_status')) {
                        knx_update_payment_status((int) $payment_locked->id, 'failed');
                    }

                    $wpdb->update(
                        $events_table,
                        ['processed_at' => current_time('mysql'), 'order_id' => (int) $order->id],
                        ['id' => $event_row_id],
                        ['%s','%d'],
                        ['%d']
                    );
                    $wpdb->query('COMMIT');

                    if (function_exists('knx_stripe_authority_log')) {
                        knx_stripe_authority_log('warn', 'webhook_amount_mismatch', 'Amount mismatch detected', [
                            'payment_id'      => (int) $payment_locked->id,
                            'order_id'        => (int) $order->id,
                            'expected_cents'  => $expected_amount_cents,
                            'received_cents'  => (int) $amount_received,
                            'event_id'        => $event->id,
                        ]);
                    }

                    return knx_rest_response(true, 'Amount mismatch', null, 200);
                }

                // Enforce order pre-confirmation state
                $allowed_pre = ['placed', 'pending'];

                if (!in_array((string) $order->status, $allowed_pre, true)) {
                    $wpdb->update(
                        $events_table,
                        ['processed_at' => current_time('mysql'), 'order_id' => (int) $order->id],
                        ['id' => $event_row_id],
                        ['%s','%d'],
                        ['%d']
                    );
                    $wpdb->query('COMMIT');

                    if (function_exists('knx_stripe_authority_log')) {
                        knx_stripe_authority_log('info', 'webhook_invalid_order_state', 'Order not in expected pre-confirmation state', [
                            'order_id'      => (int) $order->id,
                            'order_status'  => (string) $order->status,
                            'event_id'      => $event->id,
                        ]);
                    }

                    return knx_rest_response(true, 'Invalid order state', null, 200);
                }

                // Update payment -> paid
                if (function_exists('knx_update_payment_status')) {
                    $ok = knx_update_payment_status((int) $payment_locked->id, 'paid');
                    if (!$ok) {
                        throw new Exception('Failed to update payment status to paid');
                    }
                } else {
                    throw new Exception('knx_update_payment_status not available');
                }

                // Update order -> confirmed
                $order_updated = $wpdb->update(
                    $wpdb->prefix . 'knx_orders',
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

                if ($order_updated === false) {
                    throw new Exception('Failed to update order to confirmed');
                }

                // Insert order history
                $history_ok = $wpdb->insert(
                    $wpdb->prefix . 'knx_order_status_history',
                    [
                        'order_id'    => (int) $order->id,
                        'status'      => 'confirmed',
                        'created_at'  => current_time('mysql'),
                    ],
                    ['%d','%s','%s']
                );

                if (!$history_ok) {
                    throw new Exception('Failed to insert order status history (confirmed)');
                }

                // Mark event processed
                $wpdb->update(
                    $events_table,
                    ['processed_at' => current_time('mysql'), 'order_id' => (int) $order->id],
                    ['id' => $event_row_id],
                    ['%s','%d'],
                    ['%d']
                );

                $wpdb->query('COMMIT');

                if (function_exists('knx_stripe_authority_log')) {
                    knx_stripe_authority_log('info', 'webhook_payment_succeeded', 'Order confirmed via webhook', [
                        'order_id'   => (int) $order->id,
                        'payment_id' => (int) $payment_locked->id,
                        'event_id'   => $event->id,
                        'intent_id'  => $intent_id,
                    ]);
                }

                return knx_rest_response(true, 'Webhook processed', null, 200);
            }

            if ($event->type === 'payment_intent.payment_failed') {
                // Mark payment failed + order payment_failed
                if (function_exists('knx_update_payment_status')) {
                    $ok = knx_update_payment_status((int) $payment_locked->id, 'failed');
                    if (!$ok) {
                        throw new Exception('Failed to update payment status to failed');
                    }
                } else {
                    throw new Exception('knx_update_payment_status not available');
                }

                $order_updated = $wpdb->update(
                    $wpdb->prefix . 'knx_orders',
                    [
                        'status'                 => 'payment_failed',
                        'payment_status'         => 'failed',
                        'payment_method'         => 'stripe',
                        'payment_transaction_id' => $intent_id,
                        'updated_at'             => current_time('mysql'),
                    ],
                    ['id' => (int) $order->id],
                    ['%s','%s','%s','%s','%s'],
                    ['%d']
                );

                if ($order_updated === false) {
                    throw new Exception('Failed to update order to payment_failed');
                }

                $history_ok = $wpdb->insert(
                    $wpdb->prefix . 'knx_order_status_history',
                    [
                        'order_id'   => (int) $order->id,
                        'status'     => 'payment_failed',
                        'created_at' => current_time('mysql'),
                    ],
                    ['%d','%s','%s']
                );

                if (!$history_ok) {
                    throw new Exception('Failed to insert order status history (payment_failed)');
                }

                $wpdb->update(
                    $events_table,
                    ['processed_at' => current_time('mysql'), 'order_id' => (int) $order->id],
                    ['id' => $event_row_id],
                    ['%s','%d'],
                    ['%d']
                );

                $wpdb->query('COMMIT');

                if (function_exists('knx_stripe_authority_log')) {
                    knx_stripe_authority_log('info', 'webhook_payment_failed', 'Payment failed processed via webhook', [
                        'order_id'   => (int) $order->id,
                        'payment_id' => (int) $payment_locked->id,
                        'event_id'   => $event->id,
                        'intent_id'  => $intent_id,
                    ]);
                }

                return knx_rest_response(true, 'Webhook processed', null, 200);
            }

            // Should not reach here due to allowed events guard
            $wpdb->update(
                $events_table,
                ['processed_at' => current_time('mysql'), 'order_id' => (int) $order->id],
                ['id' => $event_row_id],
                ['%s','%d'],
                ['%d']
            );
            $wpdb->query('COMMIT');

            return knx_rest_response(true, 'Event ignored', null, 200);

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');

            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_db_update_failed', 'Transaction failed during webhook processing', [
                    'event_id'   => $event->id,
                    'event_type' => $event->type,
                    'intent_id'  => $intent_id,
                    'msg'        => substr($e->getMessage(), 0, 200),
                ]);
            }

            return knx_rest_error('Processing failed', 500);
        }
    }
}
