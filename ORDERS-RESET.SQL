SET FOREIGN_KEY_CHECKS = 0;

/* Webhooks + Payments */
DELETE FROM `y05_knx_webhook_events`;
DELETE FROM `y05_knx_payments`;

/* Discounts / gift cards tied to orders */
DELETE FROM `y05_knx_coupon_redemptions`;
DELETE FROM `y05_knx_gift_card_transactions`;

/* Order children */
DELETE FROM `y05_knx_order_status_history`;
DELETE FROM `y05_knx_order_items`;

/* OPS mapping */
DELETE FROM `y05_knx_driver_ops`;

/* Driver notifications (canonical queue) */
DELETE FROM `y05_knx_driver_notifications`;

/* Order messages (Driver <-> Customer chat) */
DELETE FROM `y05_knx_order_messages`;

/* Orders (parent) */
-- Use DELETE rather than TRUNCATE for the parent table to avoid
-- "cannot truncate referenced table" errors in tools (phpMyAdmin
-- may run statements in separate sessions where FOREIGN_KEY_CHECKS
-- is not preserved). DELETE honors FK cascades (ON DELETE CASCADE).
DELETE FROM `y05_knx_orders`;

/* Carts */
DELETE FROM `y05_knx_cart_items`;
DELETE FROM `y05_knx_carts`;

SET FOREIGN_KEY_CHECKS = 1;

/* Reset AUTO_INCREMENT counters so new orders start from 1 */
ALTER TABLE `y05_knx_orders` AUTO_INCREMENT = 1;
ALTER TABLE `y05_knx_order_items` AUTO_INCREMENT = 1;
ALTER TABLE `y05_knx_order_status_history` AUTO_INCREMENT = 1;
ALTER TABLE `y05_knx_order_messages` AUTO_INCREMENT = 1;
ALTER TABLE `y05_knx_driver_notifications` AUTO_INCREMENT = 1;
ALTER TABLE `y05_knx_driver_ops` AUTO_INCREMENT = 1;
