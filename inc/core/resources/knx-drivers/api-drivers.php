<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Drivers API (MVP)
 * Endpoints:
 *   GET    /knx/v1/drivers
 *   GET    /knx/v1/drivers/{id}
 *   POST   /knx/v1/drivers/create
 *   POST   /knx/v1/drivers/{id}/update
 *   POST   /knx/v1/drivers/{id}/toggle
 * ==========================================================
 */

add_action('rest_api_init', function() {
    register_rest_route('knx/v1', '/drivers', [
        'methods' => 'GET',
        'callback' => knx_rest_wrap('knx_api_list_drivers'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
    
    register_rest_route('knx/v1', '/drivers/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => knx_rest_wrap('knx_api_get_driver'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
    
    register_rest_route('knx/v1', '/drivers/create', [
        'methods' => 'POST',
        'callback' => knx_rest_wrap('knx_api_create_driver'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
    
    register_rest_route('knx/v1', '/drivers/(?P<id>\d+)/update', [
        'methods' => 'POST',
        'callback' => knx_rest_wrap('knx_api_update_driver'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
    
    register_rest_route('knx/v1', '/drivers/(?P<id>\d+)/toggle', [
        'methods' => 'POST',
        'callback' => knx_rest_wrap('knx_api_toggle_driver'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

function knx_api_list_drivers(WP_REST_Request $req) {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_drivers';
    
    $drivers = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 100");
    
    return knx_rest_response(true, 'OK', ['drivers' => $drivers], 200);
}

function knx_api_get_driver(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req->get_param('id');
    $table = $wpdb->prefix . 'knx_drivers';
    
    $driver = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d LIMIT 1", $id));
    
    if (!$driver) {
        return knx_rest_error('driver_not_found', 404);
    }
    
    return knx_rest_response(true, 'OK', ['driver' => $driver], 200);
}

function knx_api_create_driver(WP_REST_Request $req) {
    global $wpdb;
    $body = $req->get_json_params();
    $table = $wpdb->prefix . 'knx_drivers';
    
    $full_name = isset($body['full_name']) ? sanitize_text_field($body['full_name']) : '';
    $phone = isset($body['phone']) ? sanitize_text_field($body['phone']) : '';
    $email = isset($body['email']) ? sanitize_email($body['email']) : '';
    $vehicle_info = isset($body['vehicle_info']) ? sanitize_text_field($body['vehicle_info']) : '';
    
    if (empty($full_name) || empty($phone)) {
        return knx_rest_error('name_phone_required', 400);
    }
    
    $inserted = $wpdb->insert($table, [
        'full_name' => $full_name,
        'phone' => $phone,
        'email' => $email,
        'vehicle_info' => $vehicle_info,
        'status' => 'active',
        'created_at' => current_time('mysql')
    ]);
    
    if (!$inserted) {
        return knx_rest_error('create_failed', 500);
    }
    
    return knx_rest_response(true, 'Driver created', ['driver_id' => $wpdb->insert_id], 201);
}

function knx_api_update_driver(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req->get_param('id');
    $body = $req->get_json_params();
    $table = $wpdb->prefix . 'knx_drivers';
    
    $data = [];
    if (isset($body['full_name'])) $data['full_name'] = sanitize_text_field($body['full_name']);
    if (isset($body['phone'])) $data['phone'] = sanitize_text_field($body['phone']);
    if (isset($body['email'])) $data['email'] = sanitize_email($body['email']);
    if (isset($body['vehicle_info'])) $data['vehicle_info'] = sanitize_text_field($body['vehicle_info']);
    
    if (empty($data)) {
        return knx_rest_error('no_fields_to_update', 400);
    }
    
    $data['updated_at'] = current_time('mysql');
    
    $updated = $wpdb->update($table, $data, ['id' => $id]);
    
    if ($updated === false) {
        return knx_rest_error('update_failed', 500);
    }
    
    return knx_rest_response(true, 'Driver updated', null, 200);
}

function knx_api_toggle_driver(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req->get_param('id');
    $table = $wpdb->prefix . 'knx_drivers';
    
    $driver = $wpdb->get_row($wpdb->prepare("SELECT status FROM $table WHERE id = %d LIMIT 1", $id));
    
    if (!$driver) {
        return knx_rest_error('driver_not_found', 404);
    }
    
    $new_status = $driver->status === 'active' ? 'inactive' : 'active';
    
    $updated = $wpdb->update($table, ['status' => $new_status, 'updated_at' => current_time('mysql')], ['id' => $id]);
    
    if ($updated === false) {
        return knx_rest_error('toggle_failed', 500);
    }
    
    return knx_rest_response(true, 'Driver status updated', ['status' => $new_status], 200);
}
