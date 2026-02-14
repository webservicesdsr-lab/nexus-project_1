<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX-A1.2 — STRIPE WEBHOOK HANDLER (PAYMENT CONFIRMATION AUTHORITY)
 *
 * Endpoint: POST /wp-json/knx/v1/payments/webhook
 *
 * Goals:
 * - Verify Stripe signature (fail-closed).
 * - Idempotent processing (dedup by event_id).
 * - Atomic state transitions (payment + order + history) in ONE transaction.
 *
 * Canon (DB-aligned):
 * - orders.status enum:
 *   pending_payment, placed, confirmed, preparing, ready, out_for_delivery, completed, cancelled
 * - orders.payment_status enum:
 *   pending, paid, failed, refunded
 * - knx_payments.status (varchar but canonical):
 *   intent_created, authorized, paid, failed, cancelled
 *
 * Critical behavior:
 * - If payment record does NOT exist yet OR order_id is missing -> return 503 and DO NOT dedup.
 *   (Stripe must retry; we must not consume the event.)
 * - Dedup insert happens AFTER locks + validation gates.
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/payments/webhook', [
        'methods'             => 'POST',
        'callback'            => 'knx_api_payment_webhook',
        'permission_callback' => '__return_true',
    ]);
});

if (!function_exists('knx_payments_ensure_webhook_events_table')) {
    function knx_payments_ensure_webhook_events_table() {
        global $wpdb;

        static $ready = null;
        if ($ready !== null) return (bool) $ready;

        $table = $wpdb->prefix . 'knx_webhook_events';

        // Use SHOW TABLES LIKE to avoid information_schema permissions issues.
        $like = $wpdb->esc_like($table);
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like));
        if ($exists === $table) {
            $ready = true;
            return true;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `provider` varchar(50) NOT NULL DEFAULT 'stripe',
            `event_id` varchar(255) NOT NULL,
            `event_type` varchar(100) NOT NULL,
            `intent_id` varchar(255) DEFAULT NULL,
            `order_id` bigint(20) unsigned DEFAULT NULL,
            `processed_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_event_id` (`event_id`),
            KEY `idx_intent_id` (`intent_id`),
            KEY `idx_order_id` (`order_id`),
            KEY `idx_processed_at` (`processed_at`)
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

if (!function_exists('knx_api_payment_webhook')) {
    function knx_api_payment_webhook(WP_REST_Request $request) {
        global $wpdb;

        $payload = (string) $request->get_body();
        $sig_header = $request->get_header('stripe-signature') ?: $request->get_header('Stripe-Signature');

        if (empty($sig_header)) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_signature_missing', 'Missing signature header');
            }
            return function_exists('knx_rest_error')
                ? knx_rest_error('Missing signature', 400)
                : new WP_REST_Response(['success' => false, 'message' => 'Missing signature'], 400);
        }

        // Stripe bootstrap
        $webhook_secret = function_exists('knx_get_stripe_webhook_secret') ? knx_get_stripe_webhook_secret() : null;
        if (empty($webhook_secret)) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_secret_missing', 'Webhook secret not configured');
            }
            return function_exists('knx_rest_error')
                ? knx_rest_error('Webhook not configured', 500)
                : new WP_REST_Response(['success' => false, 'message' => 'Webhook not configured'], 500);
        }

        if (!function_exists('knx_stripe_init') || !knx_stripe_init()) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_stripe_unavailable', 'Stripe not initialized');
            }
            return function_exists('knx_rest_error')
                ? knx_rest_error('Payment system unavailable', 500)
                : new WP_REST_Response(['success' => false, 'message' => 'Payment system unavailable'], 500);
        }

        // Construct Stripe event (signature verified)
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
        } catch (\UnexpectedValueException $e) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('warn', 'webhook_invalid_payload', 'Invalid webhook payload', [
                    'msg' => substr($e->getMessage(), 0, 160),
                ]);
            }
            return function_exists('knx_rest_error')
                ? knx_rest_error('Invalid payload', 400)
                : new WP_REST_Response(['success' => false, 'message' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('warn', 'webhook_signature_invalid', 'Invalid signature', [
                    'msg' => substr($e->getMessage(), 0, 160),
                ]);
            }
            return function_exists('knx_rest_error')
                ? knx_rest_error('Invalid signature', 400)
                : new WP_REST_Response(['success' => false, 'message' => 'Invalid signature'], 400);
        } catch (\Throwable $e) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_event_parse_failed', 'Failed constructing webhook event', [
                    'msg' => substr($e->getMessage(), 0, 160),
                ]);
            }
            return function_exists('knx_rest_error')
                ? knx_rest_error('Webhook error', 500)
                : new WP_REST_Response(['success' => false, 'message' => 'Webhook error'], 500);
        }

        // Allowed event types only
        $allowed = ['payment_intent.succeeded', 'payment_intent.payment_failed'];
        if (!in_array((string) $event->type, $allowed, true)) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('info', 'webhook_ignored', 'Ignored event', [
                    'event_id'   => (string) $event->id,
                    'event_type' => (string) $event->type,
                ]);
            }
            return function_exists('knx_rest_response')
                ? knx_rest_response(true, 'Event ignored', null, 200)
                : new WP_REST_Response(['success' => true, 'message' => 'Event ignored'], 200);
        }

        $intent = $event->data->object ?? null;
        $intent_id = isset($intent->id) ? (string) $intent->id : '';
        $amount_received = isset($intent->amount_received) ? (int) $intent->amount_received : null;
        $currency = isset($intent->currency) ? strtolower((string) $intent->currency) : null;

        if (empty($intent_id)) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('warn', 'webhook_missing_intent', 'Missing intent id', [
                    'event_id' => (string) $event->id,
                ]);
            }
            // Non-actionable; ack to stop retries.
            return function_exists('knx_rest_response')
                ? knx_rest_response(true, 'Missing intent ID', null, 200)
                : new WP_REST_Response(['success' => true, 'message' => 'Missing intent ID'], 200);
        }

        // Payment mapping must exist BEFORE any dedup consumption.
        if (!function_exists('knx_get_payment_by_provider_intent')) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_payment_helpers_missing', 'Payment helper missing');
            }
            return function_exists('knx_rest_error')
                ? knx_rest_error('System unavailable', 500)
                : new WP_REST_Response(['success' => false, 'message' => 'System unavailable'], 500);
        }

        $payment = knx_get_payment_by_provider_intent('stripe', $intent_id);

        // Retryable: event arrived before create-intent persisted mapping.
        if (!$payment) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('info', 'webhook_payment_unmapped', 'Payment not found for intent (retryable)', [
                    'event_id'   => (string) $event->id,
                    'intent_id'  => $intent_id,
                    'event_type' => (string) $event->type,
                ]);
            }
            return function_exists('knx_rest_error')
                ? knx_rest_error('Payment not mapped yet', 503)
                : new WP_REST_Response(['success' => false, 'message' => 'Payment not mapped yet'], 503);
        }

        if (empty($payment->order_id)) {
            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('info', 'webhook_payment_unlinked', 'Payment exists but not linked to order (retryable)', [
                    'payment_id' => isset($payment->id) ? (int) $payment->id : null,
                    'intent_id'  => $intent_id,
                    'event_id'   => (string) $event->id,
                ]);
            }
            return function_exists('knx_rest_error')
                ? knx_rest_error('Payment not linked to order yet', 503)
                : new WP_REST_Response(['success' => false, 'message' => 'Payment not linked to order yet'], 503);
        }

        // Ensure dedup infra exists.
        if (!knx_payments_ensure_webhook_events_table()) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('DB not ready', 500)
                : new WP_REST_Response(['success' => false, 'message' => 'DB not ready'], 500);
        }

        $events_table = $wpdb->prefix . 'knx_webhook_events';
        $payments_table = $wpdb->prefix . 'knx_payments';
        $orders_table = $wpdb->prefix . 'knx_orders';
        $history_table = $wpdb->prefix . 'knx_order_status_history';

        // Begin transaction + lock rows.
        $wpdb->query('START TRANSACTION');

        try {
            $payment_locked = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$payments_table} WHERE id = %d FOR UPDATE",
                (int) $payment->id
            ));

            if (!$payment_locked) {
                throw new Exception('PAYMENT_LOCK_FAILED');
            }

            $order = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$orders_table} WHERE id = %d FOR UPDATE",
                (int) $payment_locked->order_id
            ));

            // Retryable: order row missing temporarily (should not happen, but do not consume).
            if (!$order) {
                $wpdb->query('ROLLBACK');
                if (function_exists('knx_stripe_authority_log')) {
                    knx_stripe_authority_log('error', 'webhook_order_missing', 'Order not found for payment (retryable)', [
                        'payment_id' => (int) $payment_locked->id,
                        'order_id'   => (int) $payment_locked->order_id,
                        'event_id'   => (string) $event->id,
                    ]);
                }
                return function_exists('knx_rest_error')
                    ? knx_rest_error('Order not found', 503)
                    : new WP_REST_Response(['success' => false, 'message' => 'Order not found'], 503);
            }

            // Non-retryable validations (once rows are locked).
            // Currency check (if DB currency present)
            if (!empty($payment_locked->currency) && !empty($currency)) {
                $db_currency = strtolower((string) $payment_locked->currency);
                if ($db_currency !== (string) $currency) {
                    $wpdb->query('ROLLBACK');
                    if (function_exists('knx_stripe_authority_log')) {
                        knx_stripe_authority_log('warn', 'webhook_currency_mismatch', 'Currency mismatch', [
                            'payment_id'      => (int) $payment_locked->id,
                            'order_id'        => (int) $order->id,
                            'db_currency'     => $db_currency,
                            'stripe_currency' => (string) $currency,
                            'event_id'        => (string) $event->id,
                        ]);
                    }
                    return function_exists('knx_rest_error')
                        ? knx_rest_error('Currency mismatch', 409)
                        : new WP_REST_Response(['success' => false, 'message' => 'Currency mismatch'], 409);
                }
            }

            // Amount check:
            // - DB knx_payments.amount is cents
            // - Stripe amount_received is cents
            $db_amount_cents = isset($payment_locked->amount) ? (int) $payment_locked->amount : 0;

            if ($event->type === 'payment_intent.succeeded') {
                if (empty($amount_received) || (int) $amount_received <= 0) {
                    $wpdb->query('ROLLBACK');
                    return function_exists('knx_rest_error')
                        ? knx_rest_error('Invalid amount', 409)
                        : new WP_REST_Response(['success' => false, 'message' => 'Invalid amount'], 409);
                }

                if ((int) $amount_received !== $db_amount_cents) {
                    $wpdb->query('ROLLBACK');
                    if (function_exists('knx_stripe_authority_log')) {
                        knx_stripe_authority_log('warn', 'webhook_amount_mismatch', 'Amount mismatch', [
                            'payment_id'     => (int) $payment_locked->id,
                            'order_id'       => (int) $order->id,
                            'db_amount'      => $db_amount_cents,
                            'received_cents' => (int) $amount_received,
                            'event_id'       => (string) $event->id,
                        ]);
                    }
                    return function_exists('knx_rest_error')
                        ? knx_rest_error('Amount mismatch', 409)
                        : new WP_REST_Response(['success' => false, 'message' => 'Amount mismatch'], 409);
                }
            }

            // If already final, ack (idempotent). No need to dedup-insert.
            $payment_status_now = strtolower((string) ($payment_locked->status ?? ''));
            $order_payment_status_now = strtolower((string) ($order->payment_status ?? ''));

            $is_paid_already = ($payment_status_now === 'paid') || ($order_payment_status_now === 'paid') || ((string) $order->status === 'confirmed');
            if ($is_paid_already && $event->type === 'payment_intent.succeeded') {
                $wpdb->query('ROLLBACK');
                return function_exists('knx_rest_response')
                    ? knx_rest_response(true, 'Already processed', null, 200)
                    : new WP_REST_Response(['success' => true, 'message' => 'Already processed'], 200);
            }

            // Dedup insert AFTER locks + non-retryable validations.
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
                // Duplicate event -> ack idempotently.
                $last_error = (string) ($wpdb->last_error ?? '');
                if (stripos($last_error, 'Duplicate') !== false || stripos($last_error, 'duplicate') !== false) {
                    $wpdb->query('ROLLBACK');
                    return function_exists('knx_rest_response')
                        ? knx_rest_response(true, 'Already processed', null, 200)
                        : new WP_REST_Response(['success' => true, 'message' => 'Already processed'], 200);
                }

                $wpdb->query('ROLLBACK');
                return function_exists('knx_rest_error')
                    ? knx_rest_error('DB error', 500)
                    : new WP_REST_Response(['success' => false, 'message' => 'DB error'], 500);
            }

            $event_row_id = (int) $wpdb->insert_id;

            // Enforce order is in a pre-confirm state for success promotions.
            // NOTE: Because orders are created as pending_payment, "placed" is allowed too for future evolution.
            if ($event->type === 'payment_intent.succeeded') {
                $allowed_pre = ['pending_payment', 'placed'];
                if (!in_array((string) $order->status, $allowed_pre, true)) {
                    // Non-retryable for state machine; ack but do not promote.
                    $wpdb->update(
                        $events_table,
                        ['processed_at' => current_time('mysql'), 'order_id' => (int) $order->id],
                        ['id' => $event_row_id],
                        ['%s','%d'],
                        ['%d']
                    );
                    $wpdb->query('COMMIT');
                    return function_exists('knx_rest_response')
                        ? knx_rest_response(true, 'Order state not eligible', null, 200)
                        : new WP_REST_Response(['success' => true, 'message' => 'Order state not eligible'], 200);
                }
            }

            // Apply transitions
            if ($event->type === 'payment_intent.succeeded') {
                // Payment -> paid
                if (function_exists('knx_update_payment_status')) {
                    $ok = knx_update_payment_status((int) $payment_locked->id, 'paid');
                    if (!$ok) throw new Exception('PAYMENT_STATUS_UPDATE_FAILED');
                } else {
                    // Fallback: direct update
                    $u = $wpdb->update($payments_table, ['status' => 'paid', 'updated_at' => current_time('mysql')], ['id' => (int) $payment_locked->id], ['%s','%s'], ['%d']);
                    if ($u === false) throw new Exception('PAYMENT_STATUS_UPDATE_FAILED');
                }

                // Order -> confirmed + payment_status paid
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
                if ($order_updated === false) throw new Exception('ORDER_UPDATE_FAILED');

                // History
                $history_ok = $wpdb->insert(
                    $history_table,
                    [
                        'order_id'   => (int) $order->id,
                        'status'     => 'confirmed',
                        'created_at' => current_time('mysql'),
                    ],
                    ['%d','%s','%s']
                );
                if (!$history_ok) throw new Exception('HISTORY_INSERT_FAILED');

            } else {
                // payment_failed -> payment failed, order stays pending_payment but payment_status = failed
                if (function_exists('knx_update_payment_status')) {
                    $ok = knx_update_payment_status((int) $payment_locked->id, 'failed');
                    if (!$ok) throw new Exception('PAYMENT_STATUS_UPDATE_FAILED');
                } else {
                    $u = $wpdb->update($payments_table, ['status' => 'failed', 'updated_at' => current_time('mysql')], ['id' => (int) $payment_locked->id], ['%s','%s'], ['%d']);
                    if ($u === false) throw new Exception('PAYMENT_STATUS_UPDATE_FAILED');
                }

                $order_updated = $wpdb->update(
                    $orders_table,
                    [
                        'status'                 => 'pending_payment',
                        'payment_status'         => 'failed',
                        'payment_method'         => 'stripe',
                        'payment_transaction_id' => $intent_id,
                        'updated_at'             => current_time('mysql'),
                    ],
                    ['id' => (int) $order->id],
                    ['%s','%s','%s','%s','%s'],
                    ['%d']
                );
                if ($order_updated === false) throw new Exception('ORDER_UPDATE_FAILED');

                $history_ok = $wpdb->insert(
                    $history_table,
                    [
                        'order_id'   => (int) $order->id,
                        'status'     => 'pending_payment',
                        'created_at' => current_time('mysql'),
                    ],
                    ['%d','%s','%s']
                );
                if (!$history_ok) throw new Exception('HISTORY_INSERT_FAILED');
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
                knx_stripe_authority_log('info', 'webhook_processed', 'Webhook processed', [
                    'event_id'   => (string) $event->id,
                    'event_type' => (string) $event->type,
                    'intent_id'  => $intent_id,
                    'order_id'   => (int) $order->id,
                    'payment_id' => (int) $payment_locked->id,
                ]);
            }

            return function_exists('knx_rest_response')
                ? knx_rest_response(true, 'Webhook processed', null, 200)
                : new WP_REST_Response(['success' => true, 'message' => 'Webhook processed'], 200);

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');

            if (function_exists('knx_stripe_authority_log')) {
                knx_stripe_authority_log('error', 'webhook_processing_failed', 'Transaction failed during webhook processing', [
                    'event_id'  => isset($event->id) ? (string) $event->id : null,
                    'intent_id' => $intent_id,
                    'msg'       => substr($e->getMessage(), 0, 220),
                ]);
            }

            return function_exists('knx_rest_error')
                ? knx_rest_error('Processing failed', 500)
                : new WP_REST_Response(['success' => false, 'message' => 'Processing failed'], 500);
        }
    }
}
