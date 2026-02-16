<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * DEPRECATED WRAPPER (CANON)
 *
 * Este archivo antes registraba /knx/v1/ops/update-status (legacy).
 * Ahora es un thin wrapper que delega al handler moderno:
 *   inc/core/knx-orders/api-update-order-status.php
 *
 * Regla: NO agregar lógica aquí.
 * ==========================================================
 */

$target = dirname(__DIR__, 2) . '/knx-orders/api-update-order-status.php';
if (is_readable($target)) {
    require_once $target;
}
