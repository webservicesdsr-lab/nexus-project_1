<?php
if (!defined('ABSPATH')) exit;

/**
 * Email verification helpers for KNX.
 */

function knx_email_verifications_table() {
    global $wpdb;
    return $wpdb->prefix . 'knx_email_verifications';
}



function knx_create_email_verification_token($user_id, $ttl_seconds = 86400) {
    global $wpdb;
    $table = knx_email_verifications_table();

    // Generate raw token and hash
    try {
        $raw = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        return false;
    }
    $hash = hash('sha256', $raw);

    // Invalidate any previous active tokens for this user
    $wpdb->update($table, ['used_at' => current_time('mysql')], ['user_id' => $user_id, 'used_at' => null], ['%s'], ['%d','%s']);

    $expires = date('Y-m-d H:i:s', time() + intval($ttl_seconds));

    $inserted = $wpdb->insert($table, [
        'user_id'   => $user_id,
        'token_hash'=> $hash,
        'expires_at'=> $expires,
    ], ['%d','%s','%s']);

    if ($inserted === false) return false;

    return $raw;
}

function knx_get_email_verification_by_token($raw_token) {
    global $wpdb;
    $table = knx_email_verifications_table();
    $hash = hash('sha256', $raw_token);

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1", $hash));
    if (!$row) return false;

    if (!empty($row->used_at)) return false;
    if (strtotime($row->expires_at) < time()) return false;

    return $row;
}

function knx_mark_email_verification_used($id) {
    global $wpdb;
    $table = knx_email_verifications_table();
    return $wpdb->update($table, ['used_at' => current_time('mysql')], ['id' => $id], ['%s'], ['%d']);
}
