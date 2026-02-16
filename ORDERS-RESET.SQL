SET FOREIGN_KEY_CHECKS = 0;

/* Webhooks + Payments */
TRUNCATE TABLE `y05_knx_webhook_events`;
TRUNCATE TABLE `y05_knx_payments`;

/* Discounts / gift cards tied to orders */
TRUNCATE TABLE `y05_knx_coupon_redemptions`;
TRUNCATE TABLE `y05_knx_gift_card_transactions`;

/* Order children */
TRUNCATE TABLE `y05_knx_order_status_history`;
TRUNCATE TABLE `y05_knx_order_items`;

/* OPS mapping */
TRUNCATE TABLE `y05_knx_driver_ops`;

/* Orders (parent) */
TRUNCATE TABLE `y05_knx_orders`;

/* Carts */
TRUNCATE TABLE `y05_knx_cart_items`;
TRUNCATE TABLE `y05_knx_carts`;

SET FOREIGN_KEY_CHECKS = 1;
