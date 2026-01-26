<?php
if (!defined('ABSPATH')) exit;

function knx_item_global_modifiers_table() { return knx_table('item_global_modifiers'); }

add_action('rest_api_init', function () {

    register_rest_route('knx/v1', '/get-item-modifiers', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_get_item_modifiers'),
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('knx/v1', '/save-modifier', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_save_modifier'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/delete-modifier', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_delete_modifier'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/reorder-modifier', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_reorder_modifier'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/save-modifier-option', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_save_modifier_option'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/delete-modifier-option', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_delete_modifier_option'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/reorder-modifier-option', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_reorder_modifier_option'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/get-global-modifiers', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_get_global_modifiers'),
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('knx/v1', '/clone-global-modifier', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_clone_global_modifier'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

/* ========== 1) GET item modifiers ========== */
function knx_api_get_item_modifiers(WP_REST_Request $r) {
    global $wpdb;
    $table_modifiers = knx_table('item_modifiers');
    $table_options   = knx_table('modifier_options');

    $item_id = intval($r->get_param('item_id'));
    if (!$item_id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_item_id'], 400);
    }

    $mods = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_modifiers WHERE item_id = %d ORDER BY sort_order ASC, id ASC", $item_id
    ));

    foreach ($mods as &$m) {
        $m->options = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_options WHERE modifier_id = %d ORDER BY sort_order ASC, id ASC", $m->id
        ));
    }

    return new WP_REST_Response(['success' => true, 'modifiers' => $mods], 200);
}

/* ========== 2) SAVE modifier ========== */
function knx_api_save_modifier(WP_REST_Request $r) {
    global $wpdb;

    $table = knx_table('item_modifiers');

    $id            = intval($r->get_param('id'));
    $item_id       = $r->get_param('item_id') !== null ? intval($r->get_param('item_id')) : null;
    $hub_id        = intval($r->get_param('hub_id'));
    $name          = sanitize_text_field($r->get_param('name'));
    $type          = sanitize_text_field($r->get_param('type'));
    $required      = intval($r->get_param('required'));
    $min_selection = intval($r->get_param('min_selection'));
    $max_selection = ($r->get_param('max_selection') !== null && $r->get_param('max_selection') !== '') ? intval($r->get_param('max_selection')) : null;
    $is_global     = intval($r->get_param('is_global'));
    $nonce         = sanitize_text_field($r->get_param('knx_nonce'));

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }
    if (!$hub_id || !$name) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_fields'], 400);
    }

    // UPDATE
    if ($id > 0) {
        $upd = [
            'item_id'       => ($is_global === 1 ? null : $item_id),
            'hub_id'        => $hub_id,
            'name'          => $name,
            'type'          => $type,
            'required'      => $required,
            'min_selection' => $min_selection,
            'is_global'     => $is_global,
            'updated_at'    => current_time('mysql')
        ];
        $fmt = ['%d','%d','%s','%s','%d','%d','%d','%s'];
        if (is_null($upd['item_id'])) { $upd['item_id'] = null; $fmt[0] = '%s'; }
        if (is_null($max_selection)) { $upd['max_selection'] = null; $fmt[] = '%s'; } else { $upd['max_selection'] = $max_selection; $fmt[] = '%d'; }

        $ok = $wpdb->update($table, $upd, ['id' => $id], $fmt, ['%d']);
        if ($ok === false) {
            return new WP_REST_Response(['success' => false, 'error' => 'db_update_failed', 'detail' => $wpdb->last_error], 500);
        }
        return new WP_REST_Response(['success' => true, 'message' => 'Modifier updated', 'id' => $id], 200);
    }

    // CREATE
    $max_sort = ($is_global === 1)
        ? $wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM $table WHERE hub_id = %d AND is_global = 1", $hub_id))
        : $wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM $table WHERE item_id = %d", $item_id));
    $sort_order = intval($max_sort) + 1;

    $ins = [
        'item_id'       => ($is_global === 1 ? null : $item_id),
        'hub_id'        => $hub_id,
        'name'          => $name,
        'type'          => $type,
        'required'      => $required,
        'min_selection' => $min_selection,
        'max_selection' => $max_selection,
        'is_global'     => $is_global,
        'sort_order'    => $sort_order,
        'created_at'    => current_time('mysql'),
        'updated_at'    => current_time('mysql'),
    ];
    $fmt = ['%d','%d','%s','%s','%d','%d','%d','%d','%d','%s','%s'];
    if (is_null($ins['item_id'])) { $ins['item_id'] = null; $fmt[0] = '%s'; }
    if (is_null($ins['max_selection'])) { $ins['max_selection'] = null; $fmt[6] = '%s'; }

    $ok = $wpdb->insert($table, $ins, $fmt);
    if ($ok === false) {
        return new WP_REST_Response(['success' => false, 'error' => 'db_insert_failed', 'detail' => $wpdb->last_error], 500);
    }
    return new WP_REST_Response(['success' => true, 'message' => 'Modifier created', 'id' => $wpdb->insert_id], 200);
}

/* ========== 3) DELETE modifier ========== */
function knx_api_delete_modifier(WP_REST_Request $r) {
    global $wpdb;

    $table_mod = knx_table('item_modifiers');
    $table_opt = knx_table('modifier_options');
    $rel_table = knx_item_global_modifiers_table();

    $id    = intval($r->get_param('id'));
    $nonce = sanitize_text_field($r->get_param('knx_nonce'));

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }
    if (!$id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_id'], 400);
    }

    // fetch before delete
    $mod = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_mod WHERE id = %d", $id));
    if (!$mod) {
        return new WP_REST_Response(['success' => false, 'error' => 'not_found'], 404);
    }

    // delete options
    $wpdb->delete($table_opt, ['modifier_id' => $id], ['%d']);

    // clean possible stale relation (matching by name to a global with same hub)
    if (!empty($mod->item_id)) {
        $wpdb->query($wpdb->prepare(
            "DELETE r FROM $rel_table r
             JOIN $table_mod g ON g.id = r.global_modifier_id
             WHERE r.item_id = %d AND g.hub_id = %d AND g.is_global = 1 AND g.name = %s",
            $mod->item_id, $mod->hub_id, $mod->name
        ));
    }

    $deleted = $wpdb->delete($table_mod, ['id' => $id], ['%d']);
    if ($deleted === false) {
        return new WP_REST_Response(['success' => false, 'error' => 'delete_failed'], 500);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Modifier deleted'], 200);
}

/* ========== 4) REORDER modifier ========== */
function knx_api_reorder_modifier(WP_REST_Request $r) {
    global $wpdb;

    $table = knx_table('item_modifiers');
    $id = intval($r->get_param('id'));
    $direction = sanitize_text_field($r->get_param('direction'));
    $nonce = sanitize_text_field($r->get_param('knx_nonce'));

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    if (!$current) {
        return new WP_REST_Response(['success' => false, 'error' => 'not_found'], 404);
    }

    $operator = ($direction === 'up') ? '<' : '>';
    $order    = ($direction === 'up') ? 'DESC' : 'ASC';

    $sibling = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE item_id = %d AND sort_order $operator %d ORDER BY sort_order $order LIMIT 1",
        $current->item_id, $current->sort_order
    ));
    if (!$sibling) {
        return new WP_REST_Response(['success' => false, 'error' => 'no_sibling'], 400);
    }

    $wpdb->update($table, ['sort_order' => $sibling->sort_order], ['id' => $current->id], ['%d'], ['%d']);
    $wpdb->update($table, ['sort_order' => $current->sort_order], ['id' => $sibling->id], ['%d'], ['%d']);

    return new WP_REST_Response(['success' => true, 'message' => 'Modifier reordered'], 200);
}

/* ========== 5) SAVE modifier option ========== */
function knx_api_save_modifier_option(WP_REST_Request $r) {
    global $wpdb;

    $table = knx_table('modifier_options');

    $id               = intval($r->get_param('id'));
    $modifier_id      = intval($r->get_param('modifier_id'));
    $name             = sanitize_text_field($r->get_param('name'));
    $price_adjustment = floatval($r->get_param('price_adjustment'));
    $is_default       = intval($r->get_param('is_default'));
    $sort_order_param = $r->get_param('sort_order');
    $sort_order       = ($sort_order_param !== null && $sort_order_param !== '') ? intval($sort_order_param) : null;
    $nonce            = sanitize_text_field($r->get_param('knx_nonce'));

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }
    if (!$modifier_id || !$name) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_fields'], 400);
    }

    if ($is_default) {
        $wpdb->update($table, ['is_default' => 0], ['modifier_id' => $modifier_id], ['%d'], ['%d']);
    }

    if ($id > 0) {
        $upd = [
            'modifier_id'      => $modifier_id,
            'name'             => $name,
            'price_adjustment' => $price_adjustment,
            'is_default'       => $is_default,
            'updated_at'       => current_time('mysql'),
        ];
        $fmt = ['%d','%s','%f','%d','%s'];
        if (!is_null($sort_order)) { $upd['sort_order'] = $sort_order; $fmt[] = '%d'; }

        $ok = $wpdb->update($table, $upd, ['id' => $id], $fmt, ['%d']);
        if ($ok === false) {
            return new WP_REST_Response(['success' => false, 'error' => 'db_update_failed', 'detail' => $wpdb->last_error], 500);
        }
        return new WP_REST_Response(['success' => true, 'message' => 'Option updated', 'id' => $id], 200);
    }

    $max_sort = $wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM $table WHERE modifier_id = %d", $modifier_id));
    $next_sort = is_null($sort_order) ? (intval($max_sort) + 1) : $sort_order;

    $ok = $wpdb->insert($table, [
        'modifier_id'      => $modifier_id,
        'name'             => $name,
        'price_adjustment' => $price_adjustment,
        'is_default'       => $is_default,
        'sort_order'       => $next_sort,
        'created_at'       => current_time('mysql'),
        'updated_at'       => current_time('mysql'),
    ], ['%d','%s','%f','%d','%d','%s','%s']);
    if ($ok === false) {
        return new WP_REST_Response(['success' => false, 'error' => 'db_insert_failed', 'detail' => $wpdb->last_error], 500);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Option created', 'id' => $wpdb->insert_id], 200);
}

/* ========== 6) DELETE option ========== */
function knx_api_delete_modifier_option(WP_REST_Request $r) {
    global $wpdb;

    $table = knx_table('modifier_options');
    $id    = intval($r->get_param('id'));
    $nonce = sanitize_text_field($r->get_param('knx_nonce'));

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }
    if (!$id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_id'], 400);
    }

    $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);
    if ($deleted === false) {
        return new WP_REST_Response(['success' => false, 'error' => 'delete_failed'], 500);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Option deleted'], 200);
}

/* ========== 7) REORDER option ========== */
function knx_api_reorder_modifier_option(WP_REST_Request $r) {
    global $wpdb;

    $table = knx_table('modifier_options');
    $id = intval($r->get_param('id'));
    $direction = sanitize_text_field($r->get_param('direction'));
    $nonce = sanitize_text_field($r->get_param('knx_nonce'));

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    if (!$current) {
        return new WP_REST_Response(['success' => false, 'error' => 'not_found'], 404);
    }

    $operator = ($direction === 'up') ? '<' : '>';
    $order    = ($direction === 'up') ? 'DESC' : 'ASC';

    $sibling = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE modifier_id = %d AND sort_order $operator %d ORDER BY sort_order $order LIMIT 1",
        $current->modifier_id, $current->sort_order
    ));
    if (!$sibling) {
        return new WP_REST_Response(['success' => false, 'error' => 'no_sibling'], 400);
    }

    $wpdb->update($table, ['sort_order' => $sibling->sort_order], ['id' => $current->id], ['%d'], ['%d']);
    $wpdb->update($table, ['sort_order' => $current->sort_order], ['id' => $sibling->id], ['%d'], ['%d']);

    return new WP_REST_Response(['success' => true, 'message' => 'Option reordered'], 200);
}

/* ========== 8) GET global modifiers ========== */
function knx_api_get_global_modifiers(WP_REST_Request $r) {
    global $wpdb;

    $table_modifiers = knx_table('item_modifiers');
    $table_options   = knx_table('modifier_options');
    $rel_table       = knx_item_global_modifiers_table();

    $hub_id = intval($r->get_param('hub_id'));
    if (!$hub_id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_hub_id'], 400);
    }

    $mods = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_modifiers WHERE hub_id = %d AND is_global = 1 ORDER BY sort_order ASC, id ASC", $hub_id
    ));

    foreach ($mods as &$m) {
        $m->options = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_options WHERE modifier_id = %d ORDER BY sort_order ASC, id ASC", $m->id
        ));
        $m->usage_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT item_id) FROM $rel_table WHERE global_modifier_id = %d", $m->id
        )));
    }

    return new WP_REST_Response(['success' => true, 'modifiers' => $mods], 200);
}

/* ========== 9) CLONE global → item ========== */
function knx_api_clone_global_modifier(WP_REST_Request $r) {
    global $wpdb;

    $table_modifiers = knx_table('item_modifiers');
    $table_options   = knx_table('modifier_options');
    $rel_table       = knx_item_global_modifiers_table();

    $global_modifier_id = intval($r->get_param('global_modifier_id'));
    $item_id            = intval($r->get_param('item_id'));
    $nonce              = sanitize_text_field($r->get_param('knx_nonce'));

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }
    if (!$global_modifier_id || !$item_id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_fields'], 400);
    }

    // Get global row
    $global_modifier = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_modifiers WHERE id = %d AND is_global = 1", $global_modifier_id
    ));
    if (!$global_modifier) {
        return new WP_REST_Response(['success' => false, 'error' => 'global_modifier_not_found'], 404);
    }

    // If relation exists but NO matching item modifier (same name), treat as stale -> delete relation and continue
    $rel_count = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $rel_table WHERE item_id = %d AND global_modifier_id = %d", $item_id, $global_modifier_id
    )));
    if ($rel_count > 0) {
        $has_clone = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_modifiers WHERE item_id = %d AND name = %s", $item_id, $global_modifier->name
        )));
        if ($has_clone) {
            return new WP_REST_Response(['success' => false, 'error' => 'already_cloned'], 409);
        }
        // stale relation cleanup
        $wpdb->delete($rel_table, ['item_id' => $item_id, 'global_modifier_id' => $global_modifier_id], ['%d','%d']);
    }

    // Create item-level copy
    $max_sort = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(sort_order) FROM $table_modifiers WHERE item_id = %d", $item_id
    ));

    $ok = $wpdb->insert($table_modifiers, [
        'item_id'       => $item_id,
        'hub_id'        => $global_modifier->hub_id,
        'name'          => $global_modifier->name,
        'type'          => $global_modifier->type,
        'required'      => $global_modifier->required,
        'min_selection' => $global_modifier->min_selection,
        'max_selection' => $global_modifier->max_selection,
        'is_global'     => 0,
        'sort_order'    => intval($max_sort) + 1,
        'created_at'    => current_time('mysql'),
        'updated_at'    => current_time('mysql'),
    ], ['%d','%d','%s','%s','%d','%d','%d','%d','%d','%s','%s']);
    if ($ok === false) {
        return new WP_REST_Response(['success' => false, 'error' => 'db_insert_failed', 'detail' => $wpdb->last_error], 500);
    }

    $new_modifier_id = $wpdb->insert_id;

    // Copy options preserving order
    $global_options = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_options WHERE modifier_id = %d ORDER BY sort_order ASC", $global_modifier_id
    ));
    foreach ($global_options as $o) {
        $ok2 = $wpdb->insert($table_options, [
            'modifier_id'      => $new_modifier_id,
            'name'             => $o->name,
            'price_adjustment' => $o->price_adjustment,
            'is_default'       => $o->is_default,
            'sort_order'       => $o->sort_order,
            'created_at'       => current_time('mysql'),
            'updated_at'       => current_time('mysql'),
        ], ['%d','%s','%f','%d','%d','%s','%s']);
        if ($ok2 === false) {
            return new WP_REST_Response(['success' => false, 'error' => 'db_insert_failed', 'detail' => $wpdb->last_error], 500);
        }
    }

    // Relation
    $okRel = $wpdb->insert($rel_table, [
        'item_id'            => $item_id,
        'global_modifier_id' => $global_modifier_id,
        'created_at'         => current_time('mysql'),
    ], ['%d','%d','%s']);
    if ($okRel === false) {
        return new WP_REST_Response(['success' => false, 'error' => 'relation_insert_failed', 'detail' => $wpdb->last_error], 500);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Modifier cloned successfully', 'new_modifier_id' => $new_modifier_id], 200);
}
