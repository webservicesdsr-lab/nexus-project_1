<?php
/**
 * ==========================================================
 * KNX System Emails — Bootstrap
 * ==========================================================
 * Loads all system email module files in correct order.
 *
 * Load order:
 * 1) Provider (reuses wp_mail transport)
 * 2) Template (HTML email rendering)
 * 3) Engine (orchestration + WordPress override)
 * 4) Hooks (WordPress filter bindings)
 *
 * This file is loaded once from kingdom-nexus.php.
 * No hooks, no output, no side effects beyond require_once.
 *
 * Architecture:
 * - Overrides ONLY KNX-triggered user flows
 * - Does NOT interfere with wp-admin emails
 * - Maintains FluentSMTP transport compatibility
 * - Uses same visual branding as driver notifications
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

$knx_se_dir = __DIR__ . '/';

require_once $knx_se_dir . 'system-email-provider.php';
require_once $knx_se_dir . 'system-email-template.php';
require_once $knx_se_dir . 'system-email-engine.php';
require_once $knx_se_dir . 'system-email-hooks.php';