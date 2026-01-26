<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - SEO-Friendly Menu URLs (Stable Build)
 * ---------------------------------------------------------
 * FEATURES:
 * ✔ /menu/{slug}   → Render menu via WordPress Page + shortcode
 * ✔ /{slug}        → Automatically redirect to /menu/{slug}
 * ✔ Does NOT override real WP Pages or system routes
 * ✔ Fully compatible with your knx_get_hub_by_slug()
 * ✔ Safe with any theme and plug-and-play
 */

/**
 * ---------------------------------------------------------
 * REGISTER REWRITE RULES
 * ---------------------------------------------------------
 */
add_action('init', function () {

    // Route 1: /menu/{slug}/  (WordPress page + shortcode handles this)
    add_rewrite_rule(
        '^menu/([a-z0-9-]+)/?$',
        'index.php?pagename=menu&hub_slug=$matches[1]',
        'top'
    );

    // Route 2: /{slug}/  (We attach hub_slug, redirect later)
    add_rewrite_rule(
        '^([a-z0-9-]+)/?$',
        'index.php?pagename=$matches[1]&hub_slug=$matches[1]',
        'top'
    );

    // Register query var
    add_filter('query_vars', function ($vars) {
        $vars[] = 'hub_slug';
        return $vars;
    });
});

/**
 * ---------------------------------------------------------
 * ROOT SLUG HANDLING (REDIRECT /slug → /menu/slug)
 * ---------------------------------------------------------
 * This block ensures clean plug-and-play behavior.
 * It ONLY redirects real hub URLs, and ignores WP system paths.
 */
add_action('template_redirect', function () {

    // WP Admin? Exit immediately.
    if (is_admin()) return;

    global $wp;
    $path = isset($wp->request) ? trim($wp->request, '/') : '';

    // Skip empty paths or multi-segment URLs (/a/b/c)
    if (!$path || strpos($path, '/') !== false) return;

    // System slugs reserved by WP or your plugin
    $reserved = [
        'menu','login','register','dashboard','checkout','cart',
        'explore-hubs','cities','hubs','settings',
        'wp-admin','wp-login','admin'
    ];
    if (in_array($path, $reserved, true)) return;

    // Is this path a hub slug?
    $hub = knx_get_hub_by_slug($path);
    if (!$hub) return; // Not a hub → normal WP handling

    // Redirect clean slug → /menu/slug
    wp_redirect(home_url('/menu/' . $path . '/'), 301);
    exit;
});

/**
 * ---------------------------------------------------------
 * MENU PAGE MODE (/menu/{slug})
 * ---------------------------------------------------------
 * This is where the shortcode takes over. We do NOT render
 * the template here because WordPress is already routing to
 * the actual /menu page (which contains [knx_menu]).
 *
 * Only ensure hub_slug stays available for the shortcode.
 */
add_action('wp', function () {

    // WordPress page name
    $pagename = get_query_var('pagename');
    if ($pagename !== 'menu') return;

    $hub_slug = get_query_var('hub_slug');
    if (empty($hub_slug)) return;

    // Pass slug safely to the shortcode
    set_query_var('hub_slug', $hub_slug);
});


/**
 * ---------------------------------------------------------
 * CORE HELPER
 * ---------------------------------------------------------
 * Finds a hub by slug using your rules.
 */
function knx_get_hub_by_slug($raw_slug) {
    global $wpdb;

    $table_hubs   = $wpdb->prefix . 'knx_hubs';
    $table_cats   = $wpdb->prefix . 'knx_hub_categories';
    $table_cities = $wpdb->prefix . 'knx_cities';

    static $cache = [];

    // Normalize slug
    $slug = strtolower(trim($raw_slug));
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    if (isset($cache[$slug])) return $cache[$slug];

    // 1) Try match against slug column
    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT h.*, 
                cat.name AS category_name,
                ci.name  AS city_name
         FROM {$table_hubs} h
         LEFT JOIN {$table_cats}   cat ON h.category_id = cat.id
         LEFT JOIN {$table_cities} ci  ON h.city_id     = ci.id
         WHERE h.status = 'active'
           AND h.slug   = %s
         LIMIT 1",
        $slug
    ));

    // 2) Fallback: match hub name if slug empty
    if (!$hub) {
        $hub = $wpdb->get_row($wpdb->prepare(
            "SELECT h.*,
                    cat.name AS category_name,
                    ci.name  AS city_name
             FROM {$table_hubs} h
             LEFT JOIN {$table_cats}   cat ON h.category_id = cat.id
             LEFT JOIN {$table_cities} ci  ON h.city_id     = ci.id
             WHERE h.status = 'active'
               AND (h.slug IS NULL OR h.slug = '')
               AND REPLACE(LOWER(h.name), ' ', '-') = %s
             LIMIT 1",
            $slug
        ));
    }

    return $cache[$slug] = $hub;
}
