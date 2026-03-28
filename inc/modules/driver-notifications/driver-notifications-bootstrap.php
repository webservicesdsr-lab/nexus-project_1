<?php
/**
 * ==========================================================
 * KNX Driver Notifications — Bootstrap
 * ==========================================================
 * Loads all notification module files in correct order.
 *
 * Load order:
 * 1) DB layer (table verification)
 * 2) Template (email rendering)
 * 3) Provider: Email (wp_mail dispatch)
 * 4) Engine (orchestration + dispatch)
 * 5) Hooks (WordPress action bindings)
 * 6) REST (admin/ops endpoints)
 *
 * This file is loaded once from kingdom-nexus.php.
 * No hooks, no output, no side effects beyond require_once.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

$knx_dn_dir = __DIR__ . '/';

require_once $knx_dn_dir . 'driver-notification-db-core.php';
require_once $knx_dn_dir . 'driver-notification-template.php';
require_once $knx_dn_dir . 'driver-notification-provider-email.php';
// Soft-push helper (queue insertion for local polling channel)
require_once $knx_dn_dir . 'driver-notification-push.php';
require_once $knx_dn_dir . 'driver-notification-engine.php';
require_once $knx_dn_dir . 'driver-notification-hooks.php';
require_once $knx_dn_dir . 'driver-notification-rest.php';
