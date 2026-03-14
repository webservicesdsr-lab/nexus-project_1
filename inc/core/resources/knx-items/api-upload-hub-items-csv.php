<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Upload Hub Items CSV (v2.0 - Dual Format)
 * ----------------------------------------------------------
 * Route: POST /wp-json/knx/v1/upload-hub-items-csv
 * - permission_callback: knx_rest_permission_roles([...])
 * - callback wrapped with knx_rest_wrap()
 *
 * Auto-detects CSV format:
 *
 * FORMAT A — Simple (legacy, 6–7 columns):
 *   name, price, category_id, category_name, description, status, image_url
 *
 * FORMAT B — Studio (9 columns, flat-row per option):
 *   category_name, item_name, item_description, group_name,
 *   group_required, group_type, option_name, option_price, option_action
 *
 * Params:
 *   items_csv     (file)   required
 *   hub_id        (int)    required
 *   conflict_mode (string) 'skip' | 'replace'  (default: 'skip')
 *   knx_nonce     (string) required
 *
 * conflict_mode behavior:
 *   skip    → existing items (by name+hub_id) are left untouched
 *   replace → existing items are updated (price, desc, category, image);
 *             modifier groups are fully replaced (Opción A: delete all + recreate)
 *
 * Returns: summary { processed, inserted, updated, skipped, errors, categories_created }
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

    // ── Verify nonce ──
    $nonce = sanitize_text_field($r->get_param('knx_nonce')) ?: (isset($_POST['knx_nonce']) ? sanitize_text_field($_POST['knx_nonce']) : '');
    if (empty($nonce) || !wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return knx_rest_response(false, 'invalid_nonce', null, 403);
    }

    $hub_id = intval($r->get_param('hub_id')) ?: (isset($_POST['hub_id']) ? intval($_POST['hub_id']) : 0);
    if (!$hub_id) {
        return knx_rest_response(false, 'missing_hub_id', null, 400);
    }

    $conflict_mode = sanitize_text_field($r->get_param('conflict_mode') ?: (isset($_POST['conflict_mode']) ? $_POST['conflict_mode'] : 'skip'));
    if (!in_array($conflict_mode, ['skip', 'replace'])) {
        $conflict_mode = 'skip';
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

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return knx_rest_response(false, 'empty_csv', null, 400);
    }

    // Normalize headers
    $cols = array_map(function($h) { return mb_strtolower(trim($h)); }, $header);

    // ── Auto-detect format ──
    $studio_cols = ['category_name','item_name','item_description','group_name','group_required','group_type','option_name','option_price','option_action'];
    $is_studio = count(array_intersect($cols, $studio_cols)) >= 7; // at least 7 of 9 match

    // Read all rows
    $raw_rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $data = [];
        foreach ($cols as $i => $col) {
            $data[$col] = isset($row[$i]) ? trim($row[$i]) : '';
        }
        $raw_rows[] = $data;
    }
    fclose($handle);

    if (empty($raw_rows)) {
        return knx_rest_response(false, 'empty_csv', null, 400);
    }

    if ($is_studio) {
        return knx_csv_import_studio($wpdb, $hub_id, $raw_rows, $conflict_mode);
    } else {
        return knx_csv_import_simple($wpdb, $hub_id, $raw_rows, $conflict_mode);
    }
}

/* ══════════════════════════════════════════════════════════════
   SIMPLE FORMAT IMPORT (legacy 6–7 columns)
   ══════════════════════════════════════════════════════════════ */
function knx_csv_import_simple($wpdb, $hub_id, $rows, $conflict_mode) {
    $table_items = knx_table('hub_items');
    $table_cats  = knx_items_categories_table();

    $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'categories_created' => 0, 'errors' => []];
    $line_no = 1; // header was line 1

    foreach ($rows as $data) {
        $line_no++;
        $stats['processed']++;

        $name      = isset($data['name']) ? sanitize_text_field($data['name']) : '';
        $price_raw = isset($data['price']) ? $data['price'] : '';

        if ($name === '') {
            $stats['errors'][] = ['line' => $line_no, 'error' => 'missing_name'];
            $stats['skipped']++;
            continue;
        }

        $price = floatval(str_replace(',', '.', $price_raw));
        if ($price <= 0) {
            $stats['errors'][] = ['line' => $line_no, 'error' => 'invalid_price', 'value' => $price_raw];
            $stats['skipped']++;
            continue;
        }

        // ── Resolve category ──
        $category_id = knx_csv_resolve_category($wpdb, $hub_id, $data, $stats, $line_no);
        if ($category_id === false) continue; // error already logged

        $description = isset($data['description']) ? sanitize_textarea_field($data['description']) : '';
        $status      = (isset($data['status']) && in_array($data['status'], ['active','inactive'])) ? $data['status'] : 'active';
        $image_url   = isset($data['image_url']) ? esc_url_raw($data['image_url']) : null;

        // ── Check existing item ──
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table_items WHERE hub_id = %d AND name = %s LIMIT 1",
            $hub_id, $name
        ));

        $now = current_time('mysql');

        if ($existing) {
            if ($conflict_mode === 'replace') {
                $wpdb->update($table_items, [
                    'category_id' => $category_id ?: null,
                    'description' => $description,
                    'price'       => $price,
                    'image_url'   => $image_url,
                    'status'      => $status,
                    'updated_at'  => $now,
                ], ['id' => $existing->id], ['%d','%s','%f','%s','%s','%s'], ['%d']);
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }
            continue;
        }

        // ── Insert new item ──
        $ok = $wpdb->insert($table_items, [
            'hub_id'      => $hub_id,
            'category_id' => $category_id ?: null,
            'name'        => $name,
            'description' => $description,
            'price'       => $price,
            'image_url'   => $image_url,
            'status'      => $status,
            'sort_order'  => time(),
            'created_at'  => $now,
            'updated_at'  => $now,
        ], ['%d','%d','%s','%s','%f','%s','%s','%d','%s','%s']);

        if ($ok === false) {
            $stats['errors'][] = ['line' => $line_no, 'error' => 'insert_failed', 'db_error' => $wpdb->last_error];
            $stats['skipped']++;
        } else {
            $stats['inserted']++;
        }
    }

    return knx_rest_response(true, 'CSV processed (Simple format)', $stats, 200);
}

/* ══════════════════════════════════════════════════════════════
   STUDIO FORMAT IMPORT (9 columns, flat-row per option)
   ══════════════════════════════════════════════════════════════ */
function knx_csv_import_studio($wpdb, $hub_id, $raw_rows, $conflict_mode) {
    $table_items     = knx_table('hub_items');
    $table_cats      = knx_items_categories_table();
    $table_modifiers = knx_table('item_modifiers');
    $table_options   = knx_table('modifier_options');

    $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'categories_created' => 0, 'modifiers_created' => 0, 'options_created' => 0, 'errors' => []];

    // ── Phase 1: Parse flat rows into hierarchical structure ──
    // grouped[category_name][item_name] = { description, base_price, groups: { group_name: { required, type, options: [...] } } }
    $grouped = [];

    foreach ($raw_rows as $idx => $row) {
        $line_no = $idx + 2; // +1 for header, +1 for 1-based
        $stats['processed']++;

        $cat_name  = isset($row['category_name']) ? sanitize_text_field($row['category_name']) : '';
        $item_name = isset($row['item_name']) ? sanitize_text_field($row['item_name']) : '';

        if ($item_name === '') {
            $stats['errors'][] = ['line' => $line_no, 'error' => 'missing_item_name'];
            $stats['skipped']++;
            continue;
        }

        if ($cat_name === '') {
            $cat_name = 'Uncategorized';
        }

        // Init category bucket
        if (!isset($grouped[$cat_name])) {
            $grouped[$cat_name] = [];
        }

        // Init item bucket
        if (!isset($grouped[$cat_name][$item_name])) {
            $grouped[$cat_name][$item_name] = [
                'description' => '',
                'base_price'  => 0,
                'groups'      => [],
            ];
        }

        $item_ref = &$grouped[$cat_name][$item_name];

        // Capture description from first row that has it
        $desc = isset($row['item_description']) ? sanitize_textarea_field($row['item_description']) : '';
        if ($desc !== '' && $item_ref['description'] === '') {
            $item_ref['description'] = $desc;
        }

        $group_name = isset($row['group_name']) ? sanitize_text_field($row['group_name']) : '';
        $opt_price  = isset($row['option_price']) ? floatval(str_replace(',', '.', $row['option_price'])) : 0;

        if ($group_name === '') {
            // Row without group = base item row; option_price is the base price
            if ($opt_price > 0 && $item_ref['base_price'] <= 0) {
                $item_ref['base_price'] = $opt_price;
            }
            continue;
        }

        // Init group bucket
        if (!isset($item_ref['groups'][$group_name])) {
            $required = isset($row['group_required']) ? $row['group_required'] : '0';
            $type     = isset($row['group_type']) ? sanitize_text_field($row['group_type']) : 'single';
            if (!in_array($type, ['single','multiple'])) $type = 'single';

            $item_ref['groups'][$group_name] = [
                'required' => ($required === '1') ? 1 : 0,
                'type'     => $type,
                'options'  => [],
            ];
        }

        // Add option if present
        $opt_name   = isset($row['option_name']) ? sanitize_text_field($row['option_name']) : '';
        $opt_action = isset($row['option_action']) ? sanitize_text_field($row['option_action']) : 'add';
        if (!in_array($opt_action, ['add','remove'])) $opt_action = 'add';

        if ($opt_name !== '') {
            $item_ref['groups'][$group_name]['options'][] = [
                'name'   => $opt_name,
                'price'  => $opt_price,
                'action' => $opt_action,
            ];
        }

        unset($item_ref);
    }

    if (empty($grouped)) {
        return knx_rest_response(false, 'no_valid_items_in_csv', $stats, 400);
    }

    // ── Phase 2: Upsert categories → items → modifiers → options ──
    $cat_cache = []; // name → id

    foreach ($grouped as $cat_name => $items) {
        // ── Resolve/create category ──
        $category_id = knx_csv_resolve_category_by_name($wpdb, $hub_id, $cat_name, $stats);

        foreach ($items as $item_name => $item_data) {
            if ($item_name === '') continue;

            $now = current_time('mysql');
            $base_price = max(0, $item_data['base_price']);

            // ── Check existing item ──
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table_items WHERE hub_id = %d AND name = %s LIMIT 1",
                $hub_id, $item_name
            ));

            $item_id = null;

            if ($existing) {
                $item_id = intval($existing->id);

                if ($conflict_mode === 'replace') {
                    // Update item fields
                    $update_data = [
                        'description' => $item_data['description'],
                        'status'      => 'active',
                        'updated_at'  => $now,
                    ];
                    $update_fmt = ['%s','%s','%s'];

                    if ($category_id) {
                        $update_data['category_id'] = $category_id;
                        $update_fmt[] = '%d';
                    }
                    if ($base_price > 0) {
                        $update_data['price'] = $base_price;
                        $update_fmt[] = '%f';
                    }

                    $wpdb->update($table_items, $update_data, ['id' => $item_id], $update_fmt, ['%d']);

                    // ── Opción A: Delete all existing modifier groups + options, then recreate ──
                    $existing_mods = $wpdb->get_col($wpdb->prepare(
                        "SELECT id FROM $table_modifiers WHERE item_id = %d AND is_global = 0",
                        $item_id
                    ));
                    if (!empty($existing_mods)) {
                        $mod_ids_str = implode(',', array_map('intval', $existing_mods));
                        $wpdb->query("DELETE FROM $table_options WHERE modifier_id IN ($mod_ids_str)");
                        $wpdb->query($wpdb->prepare(
                            "DELETE FROM $table_modifiers WHERE item_id = %d AND is_global = 0",
                            $item_id
                        ));
                    }

                    $stats['updated']++;
                } else {
                    // Skip mode — item exists, don't touch it
                    $stats['skipped']++;
                    continue;
                }
            } else {
                // ── Insert new item ──
                $ok = $wpdb->insert($table_items, [
                    'hub_id'      => $hub_id,
                    'category_id' => $category_id ?: null,
                    'name'        => $item_name,
                    'description' => $item_data['description'],
                    'price'       => $base_price,
                    'image_url'   => null,
                    'status'      => 'active',
                    'sort_order'  => time(),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ], ['%d','%d','%s','%s','%f','%s','%s','%d','%s','%s']);

                if ($ok === false) {
                    $stats['errors'][] = ['item' => $item_name, 'error' => 'insert_failed', 'db_error' => $wpdb->last_error];
                    $stats['skipped']++;
                    continue;
                }

                $item_id = intval($wpdb->insert_id);
                $stats['inserted']++;
            }

            // ── Create modifier groups + options ──
            $group_sort = 0;
            foreach ($item_data['groups'] as $group_name => $group_data) {
                $group_sort++;

                $max_sel = null;
                if ($group_data['type'] === 'single') {
                    $max_sel = 1;
                }

                $ins = [
                    'item_id'       => $item_id,
                    'hub_id'        => $hub_id,
                    'name'          => $group_name,
                    'type'          => $group_data['type'],
                    'required'      => $group_data['required'],
                    'min_selection' => $group_data['required'] ? 1 : 0,
                    'max_selection' => $max_sel,
                    'is_global'     => 0,
                    'sort_order'    => $group_sort,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
                $fmt = ['%d','%d','%s','%s','%d','%d','%d','%d','%d','%s','%s'];

                // Handle NULL max_selection
                if (is_null($max_sel)) {
                    $ins['max_selection'] = null;
                    $fmt[6] = '%s';
                }

                $ok = $wpdb->insert($table_modifiers, $ins, $fmt);
                if ($ok === false) {
                    $stats['errors'][] = ['item' => $item_name, 'group' => $group_name, 'error' => 'modifier_insert_failed', 'db_error' => $wpdb->last_error];
                    continue;
                }

                $modifier_id = intval($wpdb->insert_id);
                $stats['modifiers_created']++;

                // Insert options
                $opt_sort = 0;
                foreach ($group_data['options'] as $opt) {
                    $opt_sort++;

                    $ok2 = $wpdb->insert($table_options, [
                        'modifier_id'      => $modifier_id,
                        'name'             => $opt['name'],
                        'price_adjustment' => $opt['price'],
                        'option_action'    => $opt['action'],
                        'is_default'       => 0,
                        'sort_order'       => $opt_sort,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ], ['%d','%s','%f','%s','%d','%d','%s','%s']);

                    if ($ok2 !== false) {
                        $stats['options_created']++;
                    } else {
                        $stats['errors'][] = ['item' => $item_name, 'group' => $group_name, 'option' => $opt['name'], 'error' => 'option_insert_failed', 'db_error' => $wpdb->last_error];
                    }
                }
            }
        }
    }

    return knx_rest_response(true, 'CSV processed (Studio format)', $stats, 200);
}

/* ══════════════════════════════════════════════════════════════
   SHARED HELPERS
   ══════════════════════════════════════════════════════════════ */

/**
 * Resolve category for Simple format (supports category_id and category_name columns)
 * Returns category_id (int) or 0 on success, false on hard error.
 */
function knx_csv_resolve_category($wpdb, $hub_id, $data, &$stats, $line_no) {
    $table_cats = knx_items_categories_table();
    $category_id = 0;

    if (!empty($data['category_id'])) {
        $maybe = intval($data['category_id']);
        if ($maybe > 0) {
            $cat = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table_cats WHERE id = %d AND hub_id = %d LIMIT 1",
                $maybe, $hub_id
            ));
            if ($cat) {
                return $maybe;
            } else {
                $stats['errors'][] = ['line' => $line_no, 'error' => 'category_not_found_by_id', 'category_id' => $maybe];
                $stats['skipped']++;
                return false;
            }
        }
    }

    if (!empty($data['category_name'])) {
        $cat_name = sanitize_text_field($data['category_name']);
        return knx_csv_resolve_category_by_name($wpdb, $hub_id, $cat_name, $stats);
    }

    return $category_id;
}

/**
 * Resolve category by name: find existing or create new.
 * Returns category_id (int).
 */
function knx_csv_resolve_category_by_name($wpdb, $hub_id, $cat_name, &$stats) {
    $table_cats = knx_items_categories_table();

    $found = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $table_cats WHERE hub_id = %d AND LOWER(name) = LOWER(%s) LIMIT 1",
        $hub_id, $cat_name
    ));

    if ($found) {
        return intval($found->id);
    }

    // Create category automatically
    $max_sort = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(MAX(sort_order),0) FROM $table_cats WHERE hub_id = %d",
        $hub_id
    )));

    $now = current_time('mysql');
    $wpdb->insert($table_cats, [
        'hub_id'     => $hub_id,
        'name'       => $cat_name,
        'sort_order' => $max_sort + 1,
        'status'     => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], ['%d','%s','%d','%s','%s','%s']);

    $stats['categories_created']++;
    return intval($wpdb->insert_id);
}
