<?php
/**
 * ==========================================================
 * KNX Hub Notifications — Bootstrap
 * ==========================================================
 * Loads all hub notification module files in correct order.
 *
 * Load order:
 * 1) DB layer (table verification + insert/update)
 * 2) Template (email rendering)
 * 3) Engine (hub-scoped broadcast on order_confirmed)
 * 4) Hooks (WordPress action bindings)
 * 5) REST (soft-push poll/ack + prefs for hub_management)
 * 6) Boot (global soft-push bootstrap injector for hub sessions)
 *
 * This file is loaded once from kingdom-nexus.php.
 * No hooks, no output, no side effects beyond require_once.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

$knx_hn_dir = __DIR__ . '/';

require_once $knx_hn_dir . 'hub-notification-db-core.php';
require_once $knx_hn_dir . 'hub-notification-template.php';
require_once $knx_hn_dir . 'hub-notification-engine.php';
require_once $knx_hn_dir . 'hub-notification-hooks.php';
require_once $knx_hn_dir . 'hub-notification-rest.php';
require_once $knx_hn_dir . 'hub-notification-boot.php';
