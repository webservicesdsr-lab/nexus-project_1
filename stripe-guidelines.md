

Starting the comprehensive Stripe webhook audit — gathering all relevant files in parallel. 

Searched for text `stripe` (`**/inc/**/*.php`), 80 results

Searched for text `webhook` (`**/inc/**/*.php`), 50 results

Searched for regex `pending_payment|confirmed` (`**/inc/core/knx-orders/**`), 30 results

Summarized conversation historyGood, I've identified the key files. Now let me read all the critical files in parallel: Optimizing tool selection...

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/core/resources/knx-payments/api-payment-webhook.php#1-1), lines 1 to 500

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/core/resources/knx-payments/stripe-authority.php)

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/functions/stripe-helpers.php)

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/core/knx-orders/api-create-order-mvp.php#870-870), lines 870 to 960

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/core/knx-orders/api-update-order-status.php#1-1), lines 1 to 400

Now let me read the rest of the webhook handler and search for additional related files: 

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/core/resources/knx-payments/api-payment-webhook.php#500-500), lines 500 to 573

Searched for regex `payment_intent|create_payment_intent|PaymentIntent::create` (`**/inc/**/*.php`), 26 results

Searched for text `knx_order_confirmed`, 4 results

Searched for regex `checkout.session|charge.refund|charge.succeeded|payment_method`, 20 results

Now let me check for the payment status endpoint (fallback polling), the payment intent creation, and any hardcoded webhook URLs: 

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/core/resources/knx-payments/api-payment-status.php)

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/core/resources/knx-payments/api-create-payment-intent.php)

Searched for regex `payments/webhook|webhook.*url|webhook.*endpoint`, 2 results

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/modules/driver-notifications/driver-notification-hooks.php)

Now let me check for any remaining pieces — the payments module directory listing, any wp-config references, and the checkout frontend polling logic: 

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/core/resources/knx-payments)

Searched for regex `KNX_STRIPE_MODE|KNX_STRIPE_LIVE|KNX_STRIPE_TEST`, 30 results

Searched for regex `payments/status|pollPayment|finalizePaid|payment.*poll`, 13 results

Let me check the checkout frontend polling logic to see if there's a fallback confirmation path: 

Searched for regex `poll|status|webhook|confirm|pending` (`**/inc/public/checkout/checkout-payment-flow.js`), 30 results

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/public/checkout/checkout-payment-flow.js#1-1), lines 1 to 100

Searched for regex `knx_get_payment_by_provider_intent|knx_create_payment_record|knx_update_payment_status`, 14 results

Let me read the key polling section and the remaining relevant parts of checkout-payment-flow.js: 

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/public/checkout/checkout-payment-flow.js#800-800), lines 800 to 920

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/public/checkout/checkout-payment-flow.js#600-600), lines 600 to 700

Searched for regex `refund|charge\.refunded|dispute` (`**/inc/**/*.php`), 3 results

Now let me check the finalizeTimeout function to see how it handles webhook timeout: 

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/public/checkout/checkout-payment-flow.js#920-920), lines 920 to 1050

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/public/checkout/checkout-payment-flow.js#1050-1050), lines 1050 to 1141

I now have all the evidence needed. Let me also quickly confirm there are no hardcoded webhook URL references elsewhere: 

Searched for regex `payments/webhook|webhook-v2|whsec_`, 3 results

Searched for text `wp-config`, 3 results

Let me check the checkout shortcode for how it wires up Stripe config: 

Read [](file:///Users/mac/repositories/nexus-v5/nexus-project_1/inc/public/checkout/checkout-shortcode.php#195-195), lines 195 to 250

I now have comprehensive evidence from every relevant file. Here's the complete audit:

---

## KNX Stripe Webhook Audit — Decision Memo

### 1. Files Inspected

- api-payment-webhook.php (573 lines) — **webhook handler**
- stripe-authority.php (400 lines) — **SSOT keys/init**
- api-create-payment-intent.php (300 lines) — **PI creation**
- api-payment-status.php (200 lines) — **polling endpoint**
- api-create-order-mvp.php (969 lines) — **order creation**
- api-update-order-status.php (444 lines) — **status transition SSOT**
- stripe-helpers.php (250 lines) — **legacy wrappers**
- stripe-logger.php (177 lines) — **logging**
- payment-helpers.php — **payment record CRUD**
- checkout-payment-flow.js (1141 lines) — **frontend polling**
- checkout-shortcode.php — **checkout config wiring**
- driver-notification-hooks.php — **post-confirm hook consumer**

---

### 2. Stripe Events Found in Code

| Event | Where Used | Purpose | Required? |
|---|---|---|---|
| `payment_intent.succeeded` | api-payment-webhook.php line 167, 295, 338, 376, 393, 497 | Promotes order `pending_payment` → `confirmed`, sets `payment_status = paid`, cleans up cart items, fires `knx_order_confirmed` hook (driver broadcast) | **REQUIRED** — this is the only path that confirms orders |
| `payment_intent.payment_failed` | api-payment-webhook.php line 167, 445 | Sets `payment_status = failed` on order and payment record, keeps order in `pending_payment` status, writes status history | **REQUIRED** — needed so frontend polling detects failure and enables retry |

No other Stripe events are referenced anywhere in the codebase. Specifically:
- `checkout.session.completed` — **not found in repo**
- `charge.refunded` — **not found in repo** (only the string `refunded` appears as a payment_status enum value and a terminal order status in driver ops)
- `charge.succeeded` — **not found in repo**
- `charge.dispute.*` — **not found in repo**

---

### 3. Webhook Route(s)

| Route | File | Line |
|---|---|---|
| `POST /wp-json/knx/v1/payments/webhook` | api-payment-webhook.php | Line 43 |

**Hardcoded references to this path:**
- Only 2 occurrences, both in api-payment-webhook.php itself (line 8 comment, line 43 `register_rest_route`)
- The webhook URL is **not** hardcoded anywhere else in the codebase — not in JS, not in other PHP files
- **Changing the path is SAFE from the repo perspective.** The only external dependency is the Stripe Dashboard webhook configuration, which must be updated to match any new path.

---

### 4. Required Live Configuration

| Constant | Where Used | Purpose | Could Explain Delivery Failures? |
|---|---|---|---|
| `KNX_STRIPE_MODE` | stripe-authority.php line 42, stripe-helpers.php line 125/155/185, stripe-logger.php line 176 | Determines `'test'` vs `'live'` — defaults to `'test'` if missing | **YES** — if missing, system defaults to test mode. Webhook secret lookup would search for `KNX_STRIPE_TEST_WEBHOOK_SECRET` instead of live, causing signature verification failure → 400 response → Stripe marks delivery failed |
| `KNX_STRIPE_LIVE_SECRET_KEY` | stripe-authority.php line 262 | Stripe API authentication for SDK init | **YES** — if missing, `knx_stripe_init()` fails → webhook returns 500 `Payment system unavailable` |
| `KNX_STRIPE_LIVE_WEBHOOK_SECRET` | stripe-authority.php line 314, stripe-helpers.php line 189 | Signature verification via `\Stripe\Webhook::constructEvent()` | **YES** — if missing, webhook returns 500 `Webhook not configured`. If wrong value, signature check fails → 400 `Invalid signature` |
| `KNX_STRIPE_LIVE_PUBLISHABLE_KEY` | stripe-authority.php line 284, checkout-shortcode.php line 222 | Frontend Stripe.js init | No — not webhook-related, but without it `paymentsReady = false` and checkout is dead |
| `KNX_STRIPE_TEST_SECRET_KEY` | stripe-authority.php line 273 | Test mode API key | No — live mode relevant only |
| `KNX_STRIPE_TEST_WEBHOOK_SECRET` | stripe-authority.php line 322 | Test mode webhook secret | No — unless `KNX_STRIPE_MODE` is missing (then it defaults to test and looks for this) |

**Most likely root cause of Stripe delivery failures:**

If `KNX_STRIPE_MODE` was not set to `'live'` in `wp-config.php`, the webhook handler would:
1. Default to `'test'` mode
2. Look for `KNX_STRIPE_TEST_WEBHOOK_SECRET`
3. Either find no secret (→ 500) or find a test secret that doesn't match the live signature (→ 400)
4. Every live webhook delivery would fail

Alternatively, if `KNX_STRIPE_LIVE_WEBHOOK_SECRET` was missing/wrong while mode was correctly `'live'`, the handler returns 500 or 400 on every attempt.

---

### 5. Payment Confirmation Authority

The exact backend authority path:

1. **Stripe sends** `payment_intent.succeeded` to `POST /wp-json/knx/v1/payments/webhook`
2. **Signature verified** via `\Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret)` — api-payment-webhook.php line 146
3. **Payment record looked up** via `knx_get_payment_by_provider_intent('stripe', $intent_id)` — returns 503 if not found (forces Stripe retry)
4. **Both payment and order rows locked** with `SELECT ... FOR UPDATE` inside a transaction
5. **Currency + amount validated** against DB records (mismatches → 409 ROLLBACK)
6. **Idempotency dedup** via `INSERT INTO knx_webhook_events` (unique on `event_id`)
7. **Payment record updated** to `status = 'paid'` via `knx_update_payment_status()`
8. **Order payment fields updated** → `payment_status = 'paid'`, `payment_method = 'stripe'`, `payment_transaction_id = $intent_id`
9. **Order status promoted** to `'confirmed'` via `knx_orders_apply_status_change_db_canon()` (the SSOT function in api-update-order-status.php)
   - This function enforces the transition matrix: `'pending_payment' => ['confirmed']` (line 234)
   - It requires `payment_status = 'paid'` gate (line 278-282) — which was just set in step 8
10. **Cart items cleaned up** (non-fatal, wrapped in try/catch)
11. **COMMIT** — all changes atomic
12. **Post-commit hook** `do_action('knx_order_confirmed', $order_id)` fires driver notification broadcast

**There is NO fallback backend confirmation path.** The api-payment-status.php endpoint is **read-only** — it queries current DB state for frontend polling but never promotes status. The frontend explicitly states: *"Webhook is ONLY authority for payment confirmation"* (checkout-payment-flow.js line 20). Frontend never marks orders paid.

**Response timing:** The webhook handler returns 200 **after** the full transaction completes (signature verify → DB locks → payment update → order promotion → cart cleanup → COMMIT). This includes `SELECT ... FOR UPDATE` locks and multiple DB writes. Heavy logic runs **before** the 200 response. This is a potential concern for Stripe's timeout expectations (Stripe expects 2xx within ~5-20s).

---

### 6. Minimum Recommended Event Set for a New Webhook

| Event | Reason |
|---|---|
| `payment_intent.succeeded` | **Required.** Only path to promote orders from `pending_payment` → `confirmed` and set `payment_status = 'paid'` |
| `payment_intent.payment_failed` | **Required.** Sets `payment_status = 'failed'` so frontend polling detects failure and enables card retry |

**That's it. 2 events.** No other events are consumed by the codebase. Subscribing to anything else (`charge.*`, `checkout.session.*`, etc.) would only generate ignored 200 responses and unnecessary traffic.

---

### 7. Recommendation

**C) Create a new route/path AND a new Stripe webhook endpoint in the Stripe Dashboard.**

**Reasoning (repo evidence only):**

1. The old Stripe Dashboard endpoint is disabled. Re-enabling it (Option A) means reusing the same `whsec_*` signing secret. If the original failure was caused by a misconfigured `KNX_STRIPE_LIVE_WEBHOOK_SECRET` in `wp-config.php`, re-enabling blindly could repeat the same failures.

2. Option B (same handler, new endpoint) gains nothing — the handler code at api-payment-webhook.php is solid (signature verify, idempotency, atomic transactions, fail-closed gates). The handler is not the problem.

3. Creating a **new** Stripe Dashboard endpoint at a new URL (e.g., `/wp-json/knx/v1/payments/webhook-v2`) generates a **fresh `whsec_*` signing secret**. You then set `KNX_STRIPE_LIVE_WEBHOOK_SECRET` in `wp-config.php` to that new value with certainty. This eliminates any ambiguity about secret mismatch. The route path only appears in one `register_rest_route` call (line 43) and one comment — trivial to update. No other file references it.

4. Before creating the new endpoint: **verify** that `KNX_STRIPE_MODE = 'live'` and `KNX_STRIPE_LIVE_SECRET_KEY` are correctly set in `wp-config.php`. These are prerequisites — without them, the webhook handler will fail at lines 129-133 (`knx_stripe_init()` fails) regardless of the webhook secret.

5. Subscribe to **only** `payment_intent.succeeded` and `payment_intent.payment_failed`. Nothing else.

**One concern to address regardless of option chosen:** The handler performs heavy logic (multiple DB queries + FOR UPDATE locks + multi-table writes) before returning 200. If the DB is slow, Stripe could time out. Consider whether to acknowledge (200) early and process async — but that's a code change discussion for a future step, not this audit.