<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Explore Hubs API (Canonical)
 * ----------------------------------------------------------
 * - GET /knx/v1/explore-hubs
 * - GET /knx/v1/explore-hubs-spotlights
 * - GET /knx/v1/explore-hubs-cuisines
 * ==========================================================
 */

add_action('rest_api_init', function() {

    // Main explore hubs endpoint
    register_rest_route('knx/v1', '/explore-hubs', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_explore_hubs'),
        'permission_callback' => '__return_true',
    ]);

    // Featured hubs
    register_rest_route('knx/v1', '/explore-hubs-spotlights', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_explore_hubs_spotlights'),
        'permission_callback' => '__return_true',
    ]);

    // Cuisine options
    register_rest_route('knx/v1', '/explore-hubs-cuisines', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_explore_hubs_cuisines'),
        'permission_callback' => '__return_true',
    ]);
});


/**
 * ==========================================================
 * 1. Explore Hubs (Main Public API)
 * ==========================================================
 */
function knx_api_explore_hubs(WP_REST_Request $r) {
    global $wpdb;

    $table_hubs   = $wpdb->prefix . 'knx_hubs';
    $table_cities = $wpdb->prefix . 'knx_cities';

    // -----------------------------
    // Input filters
    // -----------------------------
    $query       = sanitize_text_field($r->get_param('q') ?? '');
    $mood        = sanitize_text_field($r->get_param('mood') ?? '');
    $open_only   = intval($r->get_param('open_only') ?? 0);
    $distance    = floatval($r->get_param('distance') ?? 0);
    $rating      = floatval($r->get_param('rating') ?? 0);
    $cuisine     = sanitize_text_field($r->get_param('cuisine') ?? '');
    $featured    = intval($r->get_param('featured') ?? 0);

    $where = ["h.status = 'active'"];

    if ($featured) {
        $where[] = "h.is_featured = 1";
    }

    if (!empty($query)) {
        $like = '%' . $wpdb->esc_like($query) . '%';
        $where[] = $wpdb->prepare(
            "(h.name LIKE %s OR h.tagline LIKE %s OR c.name LIKE %s)",
            $like, $like, $like
        );
    }

    // Mood → cuisine translator
    if (!empty($mood)) {
        $mood_map = [
            'pizza'   => 'pizza',
            'tacos'   => 'mexican',
            'noodles' => 'asian',
            'healthy' => 'healthy',
            'dessert' => 'dessert',
            'coffee'  => 'coffee',
            'spicy'   => 'spicy',
            'trucks'  => 'food truck'
        ];
        $search_term = $mood_map[$mood] ?? $mood;
        $where[] = $wpdb->prepare(
            "LOWER(h.cuisines) LIKE %s",
            '%' . $wpdb->esc_like(strtolower($search_term)) . '%'
        );
    }

    if ($open_only) {
        // Placeholder — hours engine will filter open/closed later
        $where[] = "h.status = 'active'";
    }

    if ($rating > 0) {
        $where[] = $wpdb->prepare("h.rating >= %f", $rating);
    }

    if (!empty($cuisine)) {
        $where[] = $wpdb->prepare(
            "LOWER(h.cuisines) LIKE %s",
            '%' . $wpdb->esc_like(strtolower($cuisine)) . '%'
        );
    }

    $where_sql = implode(' AND ', $where);

    // -----------------------------
    // SELECT (Corrected & Complete)
    // -----------------------------
    $sql = "
        SELECT
            h.id,
            h.slug,
            h.name,
            h.tagline,
            h.logo_url,
            h.hero_img,
            h.type,
            h.rating,
            h.cuisines,
            h.delivery_radius,
            h.delivery_available,
            h.pickup_available,
            h.latitude,
            h.longitude,
            h.is_featured,
            h.status,
            h.timezone,
            h.closure_start,
            h.closure_until,
            h.closure_reason,
            h.hours_monday,
            h.hours_tuesday,
            h.hours_wednesday,
            h.hours_thursday,
            h.hours_friday,
            h.hours_saturday,
            h.hours_sunday,
            h.category_id,
            c.name AS city
        FROM {$table_hubs} h
        LEFT JOIN {$table_cities} c ON h.city_id = c.id
        WHERE {$where_sql}
        ORDER BY h.rating DESC, h.name ASC
    ";

    $sql .= $featured ? " LIMIT 10" : " LIMIT 50";

    $results = $wpdb->get_results($sql);

    if (!$results) {
        return new WP_REST_Response(['success' => true, 'hubs' => []], 200);
    }

    // Hours Engine Enrichment
    if (function_exists('knx_hours_enrich_hubs')) {
        knx_hours_enrich_hubs($results);
    }

    if ($open_only) {
        $results = array_values(array_filter($results, function($h) {
            return !empty($h->is_open);
        }));
    }

    // -----------------------------
    // FORMAT OUTPUT
    // -----------------------------
    $items = array_map(function($hub) use ($wpdb) {

        $is_open        = !empty($hub->is_open);
        $status_text    = $hub->status_text ?? ($is_open ? 'Open now' : 'Closed');
        $hours_today    = $hub->hours_today ?? 'Hours not available';
        $next_change    = $hub->next_change ?? null;
        $is_temp_closed = !empty($hub->is_temp_closed);
        $closure_until  = $hub->closure_until ?? null;
        $closure_reason = $hub->closure_reason ?? '';

        // Cuisines JSON parsing
        $cuisines = [];
        if (!empty($hub->cuisines)) {
            $parsed = json_decode($hub->cuisines, true);
            $cuisines = is_array($parsed) ? $parsed : [$hub->cuisines];
        }

        // Hero image fallback
        $image = !empty($hub->hero_img) ? $hub->hero_img : $hub->logo_url;

        // Slug generation
        $slug = !empty($hub->slug)
            ? $hub->slug
            : knx_slugify_hub_name($hub->name, $hub->id);

        // Category extraction
        $category_id   = $hub->category_id ? (int) $hub->category_id : null;
        $category_name = null;
        if ($category_id) {
            $category_name = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT name FROM {$wpdb->prefix}knx_hub_categories WHERE id = %d",
                    $category_id
                )
            );
        }

        // === Availability Decision (Soft / UX only) ===
        // Call canonical availability engine for informational purposes.
        // This does NOT block hub visibility in Explore.
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
                $decision = knx_availability_decision((int) $hub->id);
                if (is_array($decision)) {
                    // TASK 03: Use complete availability object from engine
                    $availability = [
                        'can_order'  => isset($decision['can_order']) ? (bool)$decision['can_order'] : false,
                        'reason'     => isset($decision['reason']) ? $decision['reason'] : 'UNKNOWN',
                        'message'    => isset($decision['message']) ? $decision['message'] : 'Status unavailable.',
                        'reopen_at'  => isset($decision['reopen_at']) ? $decision['reopen_at'] : null,
                        'source'     => isset($decision['source']) ? $decision['source'] : 'unknown',
                        'severity'   => 'soft' // Always soft in Explore (hard gates in checkout/orders)
                    ];
                }
            } catch (Exception $e) {
                // Fail gracefully - hub still shows in Explore
                // Availability defaults to unavailable state (already set above)
            }
        }

        return [
            'id'                 => intval($hub->id),
            'slug'               => $slug,
            'name'               => $hub->name,
            'tagline'            => $hub->tagline ?? '',
            'image'              => $image,
            'logo_url'           => $hub->logo_url,
            'is_open'            => $is_open,
            'status_text'        => $status_text,
            'hours_today'        => $hours_today,
            'hours_label'        => $hours_today,
            'type'               => $hub->type ?? 'Restaurant',
            'rating'             => floatval($hub->rating ?? 4.5),
            'distance'           => '1.2 mi', // placeholder
            'cuisines'           => $cuisines,
            'delivery_available' => boolval($hub->delivery_available ?? 1),
            'pickup_available'   => boolval($hub->pickup_available ?? 1),
            'city'               => $hub->city ?? 'Unknown',
            'is_featured'        => intval($hub->is_featured ?? 0),
            'public_url'         => home_url('/' . $slug),

            // Extended fields
            'next_change'        => $next_change,
            'is_temp_closed'     => $is_temp_closed,
            'closure_until'      => $closure_until,
            'closure_reason'     => $closure_reason,

            // Categories
            'category_id'        => $category_id,
            'category_name'      => $category_name,

            // Availability (Soft / UX)
            'availability'       => $availability,
        ];

    }, $results);


    return new WP_REST_Response([
        'success' => true,
        'hubs'    => $items
    ], 200);
}



/**
 * ==========================================================
 * 2. Featured Spotlights
 * ==========================================================
 */
function knx_api_explore_hubs_spotlights(WP_REST_Request $r) {
    global $wpdb;

    $table_hubs = $wpdb->prefix . 'knx_hubs';

    $sql = "
        SELECT 
            id,
            name,
            hero_img,
            logo_url,
            rating,
            cuisines,
            type
        FROM {$table_hubs}
        WHERE status = 'active'
          AND rating >= 4.5
          AND hero_img IS NOT NULL AND hero_img != ''
        ORDER BY rating DESC
        LIMIT 6
    ";

    $results = $wpdb->get_results($sql);

    if (!$results) {
        return new WP_REST_Response(['success' => true, 'items' => []], 200);
    }

    $items = array_map(function($hub) {
        $emoji = '🍽️';
        $lower = strtolower($hub->cuisines);

        if (strpos($lower, 'mexican') !== false) $emoji = '🌮';
        elseif (strpos($lower, 'pizza') !== false) $emoji = '🍕';
        elseif (strpos($lower, 'asian') !== false) $emoji = '🍜';
        elseif (strpos($lower, 'healthy') !== false) $emoji = '🥗';
        elseif (strpos($lower, 'dessert') !== false) $emoji = '🍰';
        elseif (strpos($lower, 'burger') !== false) $emoji = '🍔';

        return [
            'id'     => intval($hub->id),
            'name'   => $hub->name,
            'image'  => $hub->hero_img ?: $hub->logo_url,
            'tags'   => [],
            'rating' => floatval($hub->rating ?? 4.5),
            'emoji'  => $emoji,
        ];
    }, $results);

    return new WP_REST_Response(['success' => true, 'items' => $items], 200);
}



/**
 * ==========================================================
 * 3. Cuisine List (Static)
 * ==========================================================
 */
function knx_api_explore_hubs_cuisines(WP_REST_Request $r) {

    $items = [
        ['id' => 1,  'slug' => 'mexican',  'name' => 'Mexican'],
        ['id' => 2,  'slug' => 'italian',  'name' => 'Italian'],
        ['id' => 3,  'slug' => 'asian',    'name' => 'Asian'],
        ['id' => 4,  'slug' => 'american', 'name' => 'American'],
        ['id' => 5,  'slug' => 'healthy',  'name' => 'Healthy'],
        ['id' => 6,  'slug' => 'dessert',  'name' => 'Dessert'],
        ['id' => 7,  'slug' => 'coffee',   'name' => 'Coffee & Tea'],
        ['id' => 8,  'slug' => 'seafood',  'name' => 'Seafood'],
        ['id' => 9,  'slug' => 'bbq',      'name' => 'BBQ'],
        ['id' => 10, 'slug' => 'pizza',    'name' => 'Pizza'],
    ];

    return new WP_REST_Response(['success' => true, 'items' => $items], 200);
}
