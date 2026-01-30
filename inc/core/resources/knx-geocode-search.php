<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Nominatim Geocode Search
 * Endpoint: GET /knx/v1/geocode-search?q={query}
 * Returns: { success: true, items: [ { display, lat, lng, provider, housenumber?, street?, city?, state?, postcode? } ] }
 * Uses server-side Nominatim call + transient caching (TTL 60s)
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/geocode-search', [
        'methods'             => 'GET',
        'callback'            => 'knx_geocode_search',
        'permission_callback' => '__return_true',
        'args' => [
            'q' => [
                'required'          => true,
                'type'              => 'string',
                'description'       => 'Search query for geocoding',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);
});

function knx_geocode_search($request) {
    $query = (string) $request->get_param('q');
    $query = trim($query);

    if (mb_strlen($query) < 2) {
        return new WP_REST_Response([ 'success' => true, 'items' => [] ], 200);
    }

    $cache_key = 'knx_geo_' . md5($query);
    $cached = get_transient($cache_key);
    if ($cached !== false && is_array($cached)) {
        return new WP_REST_Response([ 'success' => true, 'items' => $cached ], 200);
    }

    $nominatim = 'https://nominatim.openstreetmap.org/search';
    $args = [
        'timeout' => 3,
        'headers' => [
            'Accept' => 'application/json',
            // Nominatim requires a descriptive User-Agent
            'User-Agent' => sprintf('%s - %s', get_bloginfo('name'), get_site_url()),
        ],
    ];

    $q = add_query_arg([
        'format' => 'jsonv2',
        'addressdetails' => 1,
        'limit' => 10,
        'countrycodes' => 'us',
        'q' => $query,
    ], $nominatim);

    $items = [];

    try {
        $resp = wp_remote_get($q, $args);
        if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
            $body = wp_remote_retrieve_body($resp);
            $json = json_decode($body, true);
            if (is_array($json)) {
                foreach ($json as $r) {
                    if (!is_array($r)) continue;
                    $lat = isset($r['lat']) ? floatval($r['lat']) : null;
                    $lon = isset($r['lon']) ? floatval($r['lon']) : null;
                    if ($lat === null || $lon === null) continue;

                    $display = isset($r['display_name']) ? trim((string) $r['display_name']) : '';
                    // Clean common noise
                    $display = preg_replace('/,\s*United States(,|$)/i', '', $display);
                    $display = preg_replace('/\s+,\s+/', ', ', $display);
                    $display = trim($display, ", ");

                    $address = isset($r['address']) && is_array($r['address']) ? $r['address'] : [];
                    $housenumber = isset($address['house_number']) ? trim((string) $address['house_number']) : (isset($address['housenumber']) ? trim((string)$address['housenumber']) : '');
                    $road = isset($address['road']) ? trim((string) $address['road']) : (isset($address['street']) ? trim((string)$address['street']) : '');
                    $city = isset($address['city']) ? trim((string) $address['city']) : (isset($address['town']) ? trim((string)$address['town']) : (isset($address['village']) ? trim((string)$address['village']) : ''));
                    $state = isset($address['state']) ? trim((string) $address['state']) : '';
                    $postcode = isset($address['postcode']) ? trim((string) $address['postcode']) : '';
                    $country_code = isset($address['country_code']) ? strtoupper(trim((string)$address['country_code'])) : '';

                    $items[] = [
                        'display' => $display,
                        'lat' => $lat,
                        'lng' => $lon,
                        'provider' => 'nominatim',
                        'housenumber' => $housenumber,
                        'street' => $road,
                        'city' => $city,
                        'state' => $state,
                        'postcode' => $postcode,
                        'country_code' => $country_code,
                    ];
                }
            }
        }
    } catch (Exception $e) {
        // swallow network exceptions — fail closed (return empty results)
    }

    // Cache lightweight normalized items for short TTL
    if (!empty($items)) {
        set_transient($cache_key, $items, 60);
    }

    return new WP_REST_Response([ 'success' => true, 'items' => $items ], 200);
}
