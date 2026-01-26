<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Payments Service (HARD DISABLED)
 * ----------------------------------------------------------
 * This module is intentionally disabled.
 *
 * Canonical totals engine:
 * - inc/functions/totals-engine.php (knx_totals_quote)
 *
 * Reason:
 * - Legacy totals logic conflicts with SSOT totals architecture
 * - Prevent runtime ambiguity and duplicate business rules
 *
 * Status:
 * - No executable code in this file
 * - Safe to keep in repo without parse risks
 * ==========================================================
 */

return;
