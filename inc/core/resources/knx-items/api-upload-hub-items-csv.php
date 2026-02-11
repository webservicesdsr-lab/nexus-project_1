<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Upload Hub Items CSV (v1.0 - Canonical)
 * ----------------------------------------------------------
 * Route: POST /wp-json/knx/v1/upload-hub-items-csv
 * - permission_callback: knx_rest_permission_roles([...])
 * - callback wrapped with knx_rest_wrap()
 *
 * Expected CSV headers (case-insensitive):
 *  - name (required)
 *  - price (required)
 *  - category_id OR category_name
 *  - description
 *  - status (available|inactive)
 *  - image_url (optional)
 *
 * Behavior:
 *  - resolves categories by id (preferred) or name (creates if missing)
 *  - skips duplicates by exact name within the hub
 *  - returns summary of processed/inserted/skipped/errors
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/upload-hub-items-csv', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_api_upload_hub_items_csv'),
        'permission_callback' => knx_rest_permission_roles(['super_admin','manager','hub_management','menu_uploader']),
    ]);
});

function knx_api_upload_hub_items_csv(WP_REST_Request $r) {
    global $wpdb;

    // Verify nonce
    $nonce = sanitize_text_field($r->get_param('knx_nonce')) ?: (isset($_POST['knx_nonce']) ? sanitize_text_field($_POST['knx_nonce']) : '');
    if (empty($nonce) || !wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return knx_rest_response(false, 'invalid_nonce', null, 403);
    }

    $hub_id = intval($r->get_param('hub_id')) ?: (isset($_POST['hub_id']) ? intval($_POST['hub_id']) : 0);
    if (!$hub_id) {
        return knx_rest_response(false, 'missing_hub_id', null, 400);
    }

    if (empty($_FILES['items_csv'])) {
        return knx_rest_response(false, 'missing_file', null, 400);
    }

    $file = $_FILES['items_csv'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return knx_rest_response(false, 'upload_error', ['code' => $file['error']], 400);
    }

    // Size limit: 10MB
    if ($file['size'] > 10 * 1024 * 1024) {
        return knx_rest_response(false, 'file_too_large', null, 400);
    }

    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        return knx_rest_response(false, 'cannot_open_file', null, 500);
    }

    $table_items = knx_table('hub_items');
    $table_cats  = knx_items_categories_table();

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return knx_rest_response(false, 'empty_csv', null, 400);
    }

    // Normalize headers
    $cols = array_map(function($h) { return mb_strtolower(trim($h)); }, $header);

    $line_no = 1; // header
    $processed = 0;
    $inserted = 0;
    $skipped = 0;
    $errors = [];

    while (($row = fgetcsv($handle)) !== false) {
        $line_no++;
        $processed++;

        // Map row to associative array
        $data = [];
        foreach ($cols as $i => $col) {
            $data[$col] = isset($row[$i]) ? trim($row[$i]) : '';
        }

        // Required: name and price
        $name = isset($data['name']) ? sanitize_text_field($data['name']) : '';
        $price_raw = isset($data['price']) ? $data['price'] : '';

        if ($name === '') {
            $errors[] = ['line' => $line_no, 'error' => 'missing_name'];
            $skipped++;
            continue;
        }

        $price = floatval(str_replace([','], ['.'], $price_raw));
        if ($price <= 0) {
            $errors[] = ['line' => $line_no, 'error' => 'invalid_price', 'value' => $price_raw];
            $skipped++;
            continue;
        }

        // Check duplicate by exact name within hub
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_items WHERE hub_id = %d AND name = %s", $hub_id, $name));
        if ($exists && $exists > 0) {
            $skipped++;
            $errors[] = ['line' => $line_no, 'error' => 'duplicate_name', 'name' => $name];
            continue;
        }

        // Resolve category
        $category_id = 0;
        if (!empty($data['category_id'])) {
            $maybe = intval($data['category_id']);
            if ($maybe > 0) {
                $cat = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table_cats WHERE id = %d AND hub_id = %d LIMIT 1", $maybe, $hub_id));
                if ($cat) {
                    $category_id = $maybe;
                } else {
                    $errors[] = ['line' => $line_no, 'error' => 'category_not_found_by_id', 'category_id' => $maybe];
                    $skipped++;
                    continue;
                }
            }
        }

        if ($category_id === 0 && !empty($data['category_name'])) {
            $cat_name = sanitize_text_field($data['category_name']);
            $found = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table_cats WHERE hub_id = %d AND LOWER(name) = LOWER(%s) LIMIT 1", $hub_id, $cat_name));
            if ($found) {
                $category_id = intval($found->id);
            } else {
                // Create category automatically
                $max_sort = intval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sort_order),0) FROM $table_cats WHERE hub_id = %d", $hub_id)));
                $now = current_time('mysql');
                $wpdb->insert($table_cats, [
                    'hub_id' => $hub_id,
                    'name' => $cat_name,
                    'sort_order' => $max_sort + 1,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now
                ], ['%d','%s','%d','%s','%s','%s']);
                $category_id = intval($wpdb->insert_id);
            }
        }

        $description = isset($data['description']) ? sanitize_textarea_field($data['description']) : '';
        $status = isset($data['status']) && in_array($data['status'], ['available','inactive']) ? $data['status'] : 'available';
        $image_url = isset($data['image_url']) ? esc_url_raw($data['image_url']) : null;

        // Insert item
        $now = current_time('mysql');
        $inserted_ok = $wpdb->insert($table_items, [
            'hub_id' => $hub_id,
            'category_id' => $category_id,
            'name' => $name,
            'description' => $description,
            'price' => $price,
            'image_url' => esc_url_raw($image_url),
            'status' => $status,
            'sort_order' => time(),
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%d','%s','%s','%f','%s','%s','%d','%s','%s']);

        if ($inserted_ok === false) {
            $errors[] = ['line' => $line_no, 'error' => 'insert_failed', 'db_error' => $wpdb->last_error];
            $skipped++;
            continue;
        }

        $inserted++;
    }

    fclose($handle);

    return knx_rest_response(true, 'CSV processed', [
        'processed' => $processed,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'errors' => $errors
    ], 200);
}
