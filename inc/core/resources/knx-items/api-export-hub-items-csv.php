<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Export Hub Items CSV (v1.0 - Studio Format)
 * ----------------------------------------------------------
 * Hook: admin_post_knx_export_hub_items_csv  (logged-in users)
 *
 * Exports hub menu items in KNX Studio 9-column format:
 *   category_name, item_name, item_description, group_name,
 *   group_required, group_type, option_name, option_price,
 *   option_action
 *
 * Params (GET):
 *   hub_id      (required) int
 *   category_id (optional) int — filter to a single category
 *   knx_nonce   (required) string
 *
 * Returns: CSV file download (Content-Disposition: attachment)
 * ==========================================================
 */

add_action('admin_post_knx_export_hub_items_csv', 'knx_api_export_hub_items_csv');

function knx_api_export_hub_items_csv() {
    global $wpdb;

    // Auth check — must be a known KNX role
    if (function_exists('knx_get_session')) {
        $session = knx_get_session();
        $allowed = ['super_admin', 'manager', 'hub_management', 'menu_uploader'];
        if (!$session || !in_array($session->role, $allowed, true)) {
            wp_die('Forbidden', 403);
        }
    }

    // Verify nonce
    $nonce = isset($_GET['knx_nonce']) ? sanitize_text_field(wp_unslash($_GET['knx_nonce'])) : '';
    if (empty($nonce) || !wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        wp_die('Invalid nonce', 403);
    }

    $hub_id = isset($_GET['hub_id']) ? intval($_GET['hub_id']) : 0;
    if (!$hub_id) {
        wp_die('Missing hub_id', 400);
    }

    $category_id_filter = !empty($_GET['category_id']) ? intval($_GET['category_id']) : null;

    $table_items     = knx_table('hub_items');
    $table_cats      = knx_items_categories_table();
    $table_modifiers = knx_table('item_modifiers');
    $table_options   = knx_table('modifier_options');

    // Build items query
    $where = $wpdb->prepare("i.hub_id = %d AND i.status = 'active'", $hub_id);
    if ($category_id_filter) {
        $where .= $wpdb->prepare(" AND i.category_id = %d", $category_id_filter);
    }

    $items = $wpdb->get_results(
        "SELECT i.*, COALESCE(c.name, 'Uncategorized') AS category_name
         FROM $table_items i
         LEFT JOIN $table_cats c ON c.id = i.category_id
         WHERE $where
         ORDER BY c.sort_order ASC, c.name ASC, i.sort_order ASC, i.id ASC"
    );

    if (empty($items)) {
        return new WP_REST_Response(['success' => false, 'error' => 'no_items_found'], 404);
    }

    // Build CSV rows in Studio format
    $csv_header = ['category_name','item_name','item_description','group_name','group_required','group_type','option_name','option_price','option_action'];
    $rows = [];

    foreach ($items as $item) {
        // Get modifier groups for this item (non-global only)
        $modifiers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_modifiers WHERE item_id = %d AND is_global = 0 ORDER BY sort_order ASC, id ASC",
            $item->id
        ));

        if (empty($modifiers)) {
            // Item with no groups → single row, option_price = base price
            $rows[] = [
                $item->category_name,
                $item->name,
                $item->description ?: '',
                '', // group_name
                '', // group_required
                '', // group_type
                '', // option_name
                number_format((float)$item->price, 2, '.', ''),
                '', // option_action
            ];
        } else {
            // Item with groups → one row per option
            $first_row = true;
            foreach ($modifiers as $mod) {
                $options = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $table_options WHERE modifier_id = %d ORDER BY sort_order ASC, id ASC",
                    $mod->id
                ));

                if (empty($options)) {
                    // Group with no options → still output group header row
                    $rows[] = [
                        $item->category_name,
                        $item->name,
                        $first_row ? ($item->description ?: '') : '',
                        $mod->name,
                        (string)$mod->required,
                        $mod->type ?: 'single',
                        '', // option_name
                        $first_row ? number_format((float)$item->price, 2, '.', '') : '0.00',
                        '', // option_action
                    ];
                    $first_row = false;
                } else {
                    foreach ($options as $opt) {
                        $rows[] = [
                            $item->category_name,
                            $item->name,
                            $first_row ? ($item->description ?: '') : '',
                            $mod->name,
                            (string)$mod->required,
                            $mod->type ?: 'single',
                            $opt->name,
                            number_format((float)$opt->price_adjustment, 2, '.', ''),
                            isset($opt->option_action) ? $opt->option_action : 'add',
                        ];
                        $first_row = false;
                    }
                }
            }
        }
    }

    // Generate CSV content
    $output = fopen('php://temp', 'r+');
    fputcsv($output, $csv_header);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    rewind($output);
    $csv_content = stream_get_contents($output);
    fclose($output);

    // Build filename
    $hub_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM " . knx_table('hubs') . " WHERE id = %d", $hub_id));
    $slug = sanitize_title($hub_name ?: 'hub-' . $hub_id);
    $date = date('Y-m-d');

    if ($category_id_filter) {
        $cat_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM $table_cats WHERE id = %d", $category_id_filter));
        $cat_slug = sanitize_title($cat_name ?: 'category');
        $filename = "knx-export-{$slug}-{$cat_slug}-{$date}.csv";
    } else {
        $filename = "knx-export-{$slug}-full-{$date}.csv";
    }

    // Return as download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $csv_content;
    exit;
}
