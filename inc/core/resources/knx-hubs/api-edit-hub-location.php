<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Update Hub Location (v3.0 - Canonical)
 * ----------------------------------------------------------
 * Routes:
 *   - POST /wp-json/knx/v1/update-hub-location
 *   - GET /wp-json/knx/v1/get-hub-polygon/{id}
 * Based on old Laravel app polygon logic
 * ==========================================================
 */

add_action('rest_api_init', function () {
  register_rest_route('knx/v1', '/update-hub-location', [
    'methods'  => 'POST',
    'callback' => knx_rest_wrap('knx_api_update_hub_location'),
    'permission_callback' => knx_rest_permission_roles(['super_admin','manager','hub_management','menu_uploader','vendor_owner']),
  ]);
  
  register_rest_route('knx/v1', '/get-hub-polygon/(?P<id>\d+)', [
    'methods'  => 'GET',
    'callback' => knx_rest_wrap('knx_api_get_hub_polygon'),
    'permission_callback' => '__return_true',
  ]);
});

function knx_api_update_hub_location(WP_REST_Request $r) {
  global $wpdb;

  $nonce = (string) $r->get_param('knx_nonce');
  if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
    return new WP_REST_Response(['success'=>false,'error'=>'invalid_nonce'], 403);
  }

  $hub_id = intval($r->get_param('hub_id'));
  $address_raw = $r->get_param('address');
  $address = is_string($address_raw) ? trim(wp_kses_post($address_raw)) : '';
  $lat_raw = $r->get_param('lat');
  $lng_raw = $r->get_param('lng');
  $radius_raw = $r->get_param('delivery_radius');
  $zone_type = sanitize_text_field($r->get_param('delivery_zone_type') ?: 'radius');
  $polygon_points = $r->get_param('polygon_points');

  $lat = is_numeric($lat_raw) ? (float)$lat_raw : null;
  $lng = is_numeric($lng_raw) ? (float)$lng_raw : null;
  $radius = is_numeric($radius_raw) ? (float)$radius_raw : 0.0;

  if ($hub_id <= 0 || $address === '' || $lat === null || $lng === null) {
    return new WP_REST_Response(['success'=>false,'error'=>'missing_fields'], 400);
  }

  if (!in_array($zone_type, ['radius', 'polygon'])) {
    $zone_type = 'radius';
  }

  $table = $wpdb->prefix . 'knx_hubs';
  $zones_table = $wpdb->prefix . 'knx_delivery_zones';

  $data = [
    'address'              => $address,
    'latitude'             => $lat,
    'longitude'            => $lng,
    'delivery_radius'      => $radius,
    'delivery_zone_type'   => $zone_type,
    'updated_at'           => current_time('mysql'),
  ];
  $formats = ['%s','%f','%f','%f','%s','%s'];

  $updated = $wpdb->update($table, $data, ['id' => $hub_id], $formats, ['%d']);

  if ($updated === false) {
    return new WP_REST_Response([
      'success'=>false,
      'error'=>'db_update_failed',
      'detail'=>$wpdb->last_error
    ], 500);
  }

  if ($zone_type === 'polygon' && is_array($polygon_points) && count($polygon_points) >= 3) {
    $wpdb->delete($zones_table, ['hub_id' => $hub_id], ['%d']);
    
    $polygon_json = json_encode($polygon_points);
    
    $wpdb->insert($zones_table, [
      'hub_id'         => $hub_id,
      'zone_name'      => 'Main Delivery Area',
      'polygon_points' => $polygon_json,
      'fill_color'     => '#0b793a',
      'fill_opacity'   => 0.35,
      'stroke_color'   => '#0b793a',
      'stroke_weight'  => 2,
      'is_active'      => 1,
      'created_at'     => current_time('mysql'),
    ], ['%d','%s','%s','%s','%f','%s','%d','%d','%s']);
  }

  return new WP_REST_Response([
    'success' => true,
    'hub_id'  => $hub_id,
    'address' => $address,
    'lat'     => $lat,
    'lng'     => $lng,
    'delivery_zone_type' => $zone_type,
    'delivery_radius' => $radius
  ], 200);
}

function knx_api_get_hub_polygon($request) {
  global $wpdb;
  
  $hub_id = intval($request['id']);
  
  if (!$hub_id) {
    return new WP_REST_Response(['status'=>false,'errMsg'=>'Hub ID required'], 400);
  }
  
  $table_hubs = $wpdb->prefix . 'knx_hubs';
  $hub = $wpdb->get_row($wpdb->prepare(
    "SELECT latitude, longitude, delivery_zone_type, delivery_radius FROM $table_hubs WHERE id = %d",
    $hub_id
  ));
  
  if (!$hub) {
    return new WP_REST_Response(['status'=>false,'errMsg'=>'Hub not found'], 404);
  }
  
  $response = [
    'status' => true,
    'data' => [
      'lat' => floatval($hub->latitude),
      'lng' => floatval($hub->longitude),
      'delivery_zone_type' => $hub->delivery_zone_type,
      'area' => null
    ]
  ];
  
  if ($hub->delivery_zone_type === 'polygon') {
    $table_zones = $wpdb->prefix . 'knx_delivery_zones';
    $polygon = $wpdb->get_row($wpdb->prepare(
      "SELECT polygon_points FROM $table_zones WHERE hub_id = %d AND is_active = 1 LIMIT 1",
      $hub_id
    ));
    
    if ($polygon && $polygon->polygon_points) {
      $response['data']['area'] = json_decode($polygon->polygon_points, true);
    }
  } else {
    $response['data']['radius'] = floatval($hub->delivery_radius);
  }
  
  return new WP_REST_Response($response, 200);
}
