/* =========================================================
   KNX — OPERATIONAL CLEAN (Orders + Webhooks + Payments + OPS)
   Prefix: y05_
   Goal: leave system with ZERO orders so you only test "today".
   ========================================================= */

START TRANSACTION;

SET FOREIGN_KEY_CHECKS = 0;

/* ---- Webhooks + Payments (providers) ---- */
TRUNCATE TABLE `y05_knx_webhook_events`;
TRUNCATE TABLE `y05_knx_payments`;

/* ---- Order core (children first) ---- */
TRUNCATE TABLE `y05_knx_order_status_history`;
TRUNCATE TABLE `y05_knx_order_items`;

/* ---- OPS/Driver operational mapping ---- */
TRUNCATE TABLE `y05_knx_driver_ops`;

/* ---- Orders (parent) ---- */
TRUNCATE TABLE `y05_knx_orders`;

/* ---- Discounts / coupons tied to orders ---- */
TRUNCATE TABLE `y05_knx_coupon_redemptions`;

/* ---- Gift cards ledger tied to orders ---- */
TRUNCATE TABLE `y05_knx_gift_card_transactions`;

/* ---- Carts (if you want ZERO carts in system) ---- */
TRUNCATE TABLE `y05_knx_cart_items`;
TRUNCATE TABLE `y05_knx_carts`;

/* =========================================================
   OPTIONAL CLEANUPS (enable only if you want them)
   ========================================================= */

/* 1) Force all drivers "off" (recommended for clean test runs)
TRUNCATE TABLE `y05_knx_driver_availability`;
-- OR if you prefer to keep rows:
-- UPDATE `y05_knx_driver_availability` SET `status`='off', `updated_at`=NOW();
*/

/* 2) Clear push subscriptions (only if push tests are confusing)
TRUNCATE TABLE `y05_knx_push_subscriptions`;
*/

/* 3) Clear sessions (forces everyone to login again; useful to avoid stale auth)
TRUNCATE TABLE `y05_knx_sessions`;
*/

SET FOREIGN_KEY_CHECKS = 1;

COMMIT;
