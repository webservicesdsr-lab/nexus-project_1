<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Delete Hub (v3.0 - Canonical)
 * ----------------------------------------------------------
 * Hard delete functionality for hubs with cascading cleanup.
 * ✅ Session validation
 * ✅ Secure nonce validation
 * ✅ Transaction safety with rollback
 * ✅ Cascading cleanup of all related data
 * Route: POST /wp-json/knx/v1/delete-hub
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/delete-hub', [
        'methods' => 'POST',
        'callback' => knx_rest_wrap('knx_api_delete_hub_v3'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager', 'hub_management']),
    ]);
});

function knx_api_delete_hub_v3(WP_REST_Request $request) {
    global $wpdb;
    
    $data = $request->get_json_params();
    $hub_id = intval($data['hub_id'] ?? 0);
    $nonce = sanitize_text_field($data['knx_nonce'] ?? '');
    
    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }
    
    if (!$hub_id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_hub_id'], 400);
    }
    
    $hubs_table = $wpdb->prefix . 'knx_hubs';
    $hub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$hubs_table} WHERE id = %d", $hub_id));
    if (!$hub) {
        return new WP_REST_Response(['success' => false, 'error' => 'hub_not_found'], 404);
    }
    
    $wpdb->query('START TRANSACTION');
    
    try {
        $hub_tables = [
            'knx_hub_items' => 'hub_id',
            'knx_item_categories' => 'hub_id',
            'knx_orders' => 'hub_id',
            'knx_order_items' => 'hub_id',
            'knx_item_addons' => 'hub_id',
            'knx_item_modifiers' => 'hub_id'
        ];
        
        foreach ($hub_tables as $table => $hub_column) {
            $full_table = $wpdb->prefix . $table;
            
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table;
            if (!$table_exists) {
                error_log("Table does not exist: $full_table");
                continue;
            }
            
            $columns = $wpdb->get_col("DESCRIBE $full_table");
            if (!in_array($hub_column, $columns)) {
                error_log("Column $hub_column does not exist in table $full_table");
                continue;
            }
            
            $result = $wpdb->delete($full_table, [$hub_column => $hub_id], ['%d']);
            
            if ($result === false) {
                error_log("Failed to delete from $table: " . $wpdb->last_error);
                throw new Exception("Failed to delete from table: $table - " . $wpdb->last_error);
            }
            
            error_log("Successfully deleted from $table: $result rows");
        }
        
        $delivery_rates_table = $wpdb->prefix . 'delivery_rates';
        if ($wpdb->get_var("SHOW TABLES LIKE '$delivery_rates_table'") === $delivery_rates_table) {
            if (!empty($hub->city_id)) {
                $delivery_result = $wpdb->delete($delivery_rates_table, ['city_id' => $hub->city_id], ['%d']);
                if ($delivery_result !== false) {
                    error_log("Deleted delivery rates for city_id {$hub->city_id}: $delivery_result rows");
                }
            }
        }
        
        $result = $wpdb->delete($hubs_table, ['id' => $hub_id], ['%d']);
        
        if ($result === false) {
            throw new Exception("Failed to delete hub: " . $wpdb->last_error);
        }
        
        error_log("Successfully deleted hub $hub_id");
        
        if (!empty($hub->logo_url)) {
            $upload_dir = wp_upload_dir();
            $logo_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $hub->logo_url);
            if (file_exists($logo_path)) {
                wp_delete_file($logo_path);
            }
        }
        
        $wpdb->query('COMMIT');
        
        return new WP_REST_Response([
            'success' => true,
            'message' => '✅ Hub deleted successfully'
        ], 200);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log('Hub deletion failed: ' . $e->getMessage());
        
        return new WP_REST_Response([
            'success' => false,
            'message' => '❌ Failed to delete hub: ' . $e->getMessage()
        ], 500);
    }
}
