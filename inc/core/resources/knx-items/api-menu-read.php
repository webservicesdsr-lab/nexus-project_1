<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Public Menu Read API (Canonical)
 * Route: GET /wp-json/knx/v1/menu
 * Params:
 *   - hub_id (int)  | ó
 *   - hub_slug (string)
 *
 * Returns:
 * {
 *   hub: { id, name, slug },
 *   categories: [{ id, name, sort_order }],
 *   items: [{
 *     id, category_id, name, description, price, image_url, status,
 *     modifiers: [{
 *       id, name, type, required, min_selection, max_selection, sort_order,
 *       options: [{ id, name, price_adjustment, is_default, sort_order }]
 *     }],
 *     addon_groups: [{
 *       id, name, description, sort_order,
 *       addons: [{ id, name, price, sort_order }]
 *     }]
 *   }]
 * }
 *
 * Security:
 *  - Read-only (no auth). Public menu data.
 *  - Safe SQL with $wpdb->prepare.
 *  - No overlay or UI: API only.
 *  - Light rate-limit with transients (optional) and short cache.
 * ==========================================================
 */

add_action('rest_api_init', function () {
  register_rest_route('knx/v1', '/menu', [
    'methods'  => WP_REST_Server::READABLE,
    'callback' => knx_rest_wrap('knx_api_get_menu'),
    'permission_callback' => '__return_true',
    'args' => [
      'hub_id' => [
        'required' => false,
        'type' => 'integer',
        'validate_callback' => function($v){ return is_numeric($v) && (int)$v >= 1; }
      ],
      'hub_slug' => [
        'required' => false,
        'type' => 'string',
        'sanitize_callback' => 'sanitize_title'
      ],
      // cache bust optional: ?cb=1
      'cb' => [
        'required' => false,
        'type' => 'integer'
      ],
    ],
  ]);
});

function knx_api_get_menu( WP_REST_Request $req ) {
  global $wpdb;

  $hub_id   = (int) ($req->get_param('hub_id') ?: 0);
  $hub_slug = trim((string) $req->get_param('hub_slug'));

  if (!$hub_id && !$hub_slug) {
    return new WP_REST_Response([
      'error' => 'missing_hub_param',
      'message' => 'Provide hub_id or hub_slug.'
    ], 400);
  }

  // Tables
  $t_hubs        = $wpdb->prefix . 'knx_hubs';
  $t_cats        = $wpdb->prefix . 'knx_items_categories';
  $t_items       = $wpdb->prefix . 'knx_hub_items';
  $t_mods        = $wpdb->prefix . 'knx_item_modifiers';
  $t_mod_opts    = $wpdb->prefix . 'knx_modifier_options';
  $t_group_map   = $wpdb->prefix . 'knx_item_addon_groups';
  $t_groups      = $wpdb->prefix . 'knx_addon_groups';
  $t_addons      = $wpdb->prefix . 'knx_addons';

  // Resolve hub
  if ($hub_slug && !$hub_id) {
    $hub = $wpdb->get_row(
      $wpdb->prepare("SELECT id, name, slug FROM {$t_hubs} WHERE slug = %s AND status = 'active' LIMIT 1", $hub_slug),
      ARRAY_A
    );
  } else {
    $hub = $wpdb->get_row(
      $wpdb->prepare("SELECT id, name, slug FROM {$t_hubs} WHERE id = %d AND status = 'active' LIMIT 1", $hub_id),
      ARRAY_A
    );
  }

  if (!$hub) {
    return new WP_REST_Response([
      'error' => 'hub_not_found',
      'message' => 'Hub not found or inactive.'
    ], 404);
  }

  $hub_id = (int)$hub['id'];

  // Cache key (60s)
  $cb    = (int) ($req->get_param('cb') ?: 0);
  $ckey  = "knx_menu_hub_{$hub_id}";
  if (!$cb) {
    $cached = get_transient($ckey);
    if ($cached) {
      return new WP_REST_Response($cached, 200);
    }
  }

  // Active categories for hub
  $cats = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT id, name, sort_order
       FROM {$t_cats}
       WHERE hub_id = %d AND status = 'active'
       ORDER BY sort_order ASC, id ASC",
      $hub_id
    ),
    ARRAY_A
  );

  // Active items for hub
  $items = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT id, hub_id, category_id, name, description, price, image_url, status, sort_order
       FROM {$t_items}
       WHERE hub_id = %d AND status = 'active'
       ORDER BY sort_order ASC, id ASC",
      $hub_id
    ),
    ARRAY_A
  );

  // Pre-index items by id to enrich with modifiers/addons
  $byId = [];
  foreach ($items as $r) {
    $r['modifiers']    = []; // filled below
    $r['addon_groups'] = []; // filled below
    $byId[(int)$r['id']] = $r;
  }
  $itemIds = array_keys($byId);

  if (!empty($itemIds)) {
    $in = implode(',', array_map('intval', $itemIds));

    // === Modifiers per item ===
    $mods = $wpdb->get_results(
      "SELECT m.id, m.item_id, m.name, m.type, m.required, m.min_selection, m.max_selection, m.sort_order
       FROM {$t_mods} m
       WHERE (m.item_id IN ($in) OR (m.is_global = 1 AND m.hub_id = {$hub_id}))
       ORDER BY m.sort_order ASC, m.id ASC",
      ARRAY_A
    );

    // Modifier options
    $modIds = array_map('intval', array_column($mods, 'id'));
    $optsByMod = [];
    if (!empty($modIds)) {
      $inMod = implode(',', $modIds);
      $opts = $wpdb->get_results(
        "SELECT id, modifier_id, name, price_adjustment, is_default, sort_order
         FROM {$t_mod_opts}
         WHERE modifier_id IN ($inMod)
         ORDER BY sort_order ASC, id ASC",
        ARRAY_A
      );
      foreach ($opts as $o) {
        $mid = (int)$o['modifier_id'];
        if (!isset($optsByMod[$mid])) $optsByMod[$mid] = [];
        $optsByMod[$mid][] = [
          'id' => (int)$o['id'],
          'name' => $o['name'],
          'price_adjustment' => (float)$o['price_adjustment'],
          'is_default' => (int)$o['is_default'] === 1,
          'sort_order' => (int)$o['sort_order'],
        ];
      }
    }

    // Attach modifiers to each item
    foreach ($mods as $m) {
      $targetItemIds = [];
      if (!empty($m['item_id'])) {
        $targetItemIds[] = (int)$m['item_id'];
      } else {
        // global for hub: apply to all items in hub
        $targetItemIds = $itemIds;
      }
      $modObj = [
        'id'            => (int)$m['id'],
        'name'          => $m['name'],
        'type'          => $m['type'],
        'required'      => (int)$m['required'] === 1,
        'min_selection' => (int)$m['min_selection'],
        'max_selection' => is_null($m['max_selection']) ? null : (int)$m['max_selection'],
        'sort_order'    => (int)$m['sort_order'],
        'options'       => $optsByMod[(int)$m['id']] ?? [],
      ];
      foreach ($targetItemIds as $iid) {
        if (isset($byId[$iid])) {
          $byId[$iid]['modifiers'][] = $modObj;
        }
      }
    }

    // === Addon groups per item ===
    // Mapping item->group
    $maps = $wpdb->get_results(
      "SELECT item_id, group_id
       FROM {$t_group_map}
       WHERE item_id IN ($in)",
      ARRAY_A
    );
    $gids = array_map('intval', array_column($maps, 'group_id'));
    $gids = array_values(array_unique(array_filter($gids)));

    $groupById = [];
    $addonsByGroup = [];

    if (!empty($gids)) {
      $inG = implode(',', $gids);

      // Groups (only from hub for security)
      $groups = $wpdb->get_results(
        "SELECT id, name, description, sort_order
         FROM {$t_groups}
         WHERE id IN ($inG) AND hub_id = {$hub_id}
         ORDER BY sort_order ASC, id ASC",
        ARRAY_A
      );
      foreach ($groups as $g) {
        $groupById[(int)$g['id']] = [
          'id'          => (int)$g['id'],
          'name'        => $g['name'],
          'description' => $g['description'],
          'sort_order'  => (int)$g['sort_order'],
          'addons'      => []
        ];
      }

      // Active addons for group
      if (!empty($groupById)) {
        $inGG = implode(',', array_keys($groupById));
        $adds = $wpdb->get_results(
          "SELECT id, group_id, name, price, sort_order, status
           FROM {$t_addons}
           WHERE group_id IN ($inGG) AND status = 'active'
           ORDER BY sort_order ASC, id ASC",
          ARRAY_A
        );
        foreach ($adds as $a) {
          $gid = (int)$a['group_id'];
          if (!isset($groupById[$gid])) continue;
          $groupById[$gid]['addons'][] = [
            'id'         => (int)$a['id'],
            'name'       => $a['name'],
            'price'      => (float)$a['price'],
            'sort_order' => (int)$a['sort_order'],
          ];
        }
      }

      // Attach groups to item per mapping
      foreach ($maps as $m) {
        $iid = (int)$m['item_id'];
        $gid = (int)$m['group_id'];
        if (isset($byId[$iid]) && isset($groupById[$gid])) {
          $byId[$iid]['addon_groups'][] = $groupById[$gid];
        }
      }
    }
  }

  // === Availability Decision (Soft / UX only) ===
  // Call canonical availability engine for informational purposes.
  // This does NOT block menu rendering.
  $availability = [
    'can_order'  => false,
    'reason'     => 'SYSTEM_UNAVAILABLE',
    'message'    => 'Ordering is temporarily unavailable.',
    'reopen_at'  => null,
    'source'     => 'unknown',
    'severity'   => 'soft'
  ];

  if (function_exists('knx_availability_decision')) {
    try {
      $decision = knx_availability_decision($hub_id);
      if (is_array($decision)) {
        // TASK 03: Use complete availability object from engine
        $availability = [
          'can_order'  => isset($decision['can_order']) ? (bool)$decision['can_order'] : false,
          'reason'     => isset($decision['reason']) ? $decision['reason'] : 'UNKNOWN',
          'message'    => isset($decision['message']) ? $decision['message'] : 'Status unavailable.',
          'reopen_at'  => isset($decision['reopen_at']) ? $decision['reopen_at'] : null,
          'source'     => isset($decision['source']) ? $decision['source'] : 'unknown',
          'severity'   => 'soft' // Always soft in Menu (hard gates in checkout/orders)
        ];
      }
    } catch (Exception $e) {
      // Fail gracefully - menu still loads
      // Availability defaults to unavailable state (already set above)
    }
  }

  // Package response (no fees, no totals; menu only)
  $resp = [
    'hub' => [
      'id'   => (int)$hub['id'],
      'name' => $hub['name'],
      'slug' => $hub['slug'],
    ],
    'categories' => array_map(function($c){
      return [
        'id'         => (int)$c['id'],
        'name'       => $c['name'],
        'sort_order' => (int)$c['sort_order'],
      ];
    }, $cats ?: []),
    'items' => array_values(array_map(function($r){
      return [
        'id'          => (int)$r['id'],
        'category_id' => is_null($r['category_id']) ? null : (int)$r['category_id'],
        'name'        => $r['name'],
        'description' => $r['description'],
        'price'       => (float)$r['price'],
        'image_url'   => $r['image_url'],
        'status'      => $r['status'],
        'sort_order'  => (int)$r['sort_order'],
        'modifiers'   => $r['modifiers'],
        'addon_groups'=> $r['addon_groups'],
      ];
    }, $byId)),
    'availability' => $availability,
  ];

  // Short cache (60s). Use ?cb=1 to force reload.
  set_transient($ckey, $resp, 60);

  // No-cache headers (for proxies/CDN if you have them)
  nocache_headers();

  return new WP_REST_Response($resp, 200);
}
