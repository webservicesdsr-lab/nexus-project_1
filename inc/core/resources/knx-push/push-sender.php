<?php
/**
 * KNX Push Sender (provider coordinator)
 * Responsible for processing a single provider row (ntfy) using provider-specific senders.
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/ntfy-sender.php';

/**
 * Process a single ntfy notification row.
 * @param object $row DB row with id, driver_id, payload_json
 * @return array ['ok' => bool, 'error' => string|null]
 */
function knx_dn_push_process_ntfy_row($row) {
    if (!isset($row->id)) return ['ok' => false, 'error' => 'missing_id'];

    $res = knx_dn_ntfy_send($row);
    if ($res === true) {
        return ['ok' => true, 'error' => null];
    }

    if (is_wp_error($res)) {
        return ['ok' => false, 'error' => $res->get_error_message()];
    }

    return ['ok' => false, 'error' => 'unknown_error'];
}
