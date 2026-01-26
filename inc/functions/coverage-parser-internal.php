<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Coverage Parser Helpers (Internal Only)
 * ==========================================================
 * PURPOSE: Internal polygon parsing utilities for coverage-engine.php
 * 
 * DOES NOT contain canonical coverage logic.
 * DOES NOT claim SSOT authority.
 * MUST ONLY be called from coverage-engine.php.
 * 
 * Salvaged from legacy delivery_zone_helper.php:
 * - MultiPolygon support
 * - Legacy polygon format compatibility
 * - GeoJSON RFC 7946 compliance
 * 
 * Functions Declared:
 *   - knx_coverage_parse_polygon_internal() ← Parser only
 *   - knx_coverage_geojson_to_points_internal() ← GeoJSON converter
 *   - knx_coverage_legacy_to_points_internal() ← Legacy converter
 *   - knx_within_area_internal() ← Ray-casting algorithm
 * ==========================================================
 */

/**
 * Ray casting algorithm: check if point is inside polygon.
 * 
 * Internal implementation for coverage-engine.php only.
 * DO NOT call directly from REST endpoints.
 *
 * @param object $point  Object with ->lat and ->lng
 * @param array  $polygon Array of objects with ->lat and ->lng
 * @param int    $n Number of points
 * @return bool
 */
function knx_within_area_internal($point, $polygon, $n) {
    if (!is_array($polygon) || $n < 3) return false;

    // Close polygon if not already closed
    if ($polygon[0] != $polygon[$n - 1]) {
        $polygon[$n] = $polygon[0];
        $n++;
    }

    $oddNodes = false;
    $x = (float) $point->lng;
    $y = (float) $point->lat;

    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;

        $yi = (float) $polygon[$i]->lat;
        $yj = (float) $polygon[$j]->lat;

        if ((($yi < $y) && ($yj >= $y)) || (($yj < $y) && ($yi >= $y))) {
            $xi = (float) $polygon[$i]->lng;
            $xj = (float) $polygon[$j]->lng;

            $cross = $xi + (($y - $yi) / (($yj - $yi) ?: 1e-12)) * ($xj - $xi);
            if ($cross < $x) {
                $oddNodes = !$oddNodes;
            }
        }
    }

    return $oddNodes;
}

/**
 * Internal parser last-error setter/getter
 */
function knx_coverage_set_last_error_internal($msg) {
    static $err;
    $err = $msg;
}

function knx_coverage_get_last_error_internal() {
    static $err;
    return $err;
}

/**
 * Parse polygon from GeoJSON or legacy formats.
 * 
 * Internal parser for coverage-engine.php only.
 * Salvaged from legacy delivery_zone_helper.php.
 * 
 * SUPPORTS:
 * - GeoJSON Polygon: { type: "Polygon", coordinates: [[[lng,lat], ...]] }
 * - GeoJSON MultiPolygon: { type: "MultiPolygon", coordinates: [[[[lng,lat], ...]]] }
 * - Legacy object array: [{ lat, lng }, ...]
 * - Legacy coordinate array: [[lat, lng], ...]
 * 
 * NOTE: GeoJSON uses [lng, lat] order per RFC 7946.
 * NOTE: MultiPolygon uses first polygon only.
 * 
 * @param mixed $decoded Decoded JSON data or object
 * @return array|null Normalized point array [{lat, lng}, ...] or null
 */
function knx_coverage_parse_polygon_internal($decoded) {
    knx_coverage_set_last_error_internal(null);

    if (!is_object($decoded) && !is_array($decoded)) {
        $last_error = 'INVALID_STRUCTURE';
        return null;
    }

    // GeoJSON Polygon or MultiPolygon
    if (is_object($decoded) && isset($decoded->type) && isset($decoded->coordinates)) {
        if ($decoded->type === 'Polygon') {
            if (is_array($decoded->coordinates) && count($decoded->coordinates) > 0) {
                $ring = $decoded->coordinates[0]; // outer ring
                return knx_coverage_geojson_to_points_internal($ring);
            }
        } elseif ($decoded->type === 'MultiPolygon') {
            if (is_array($decoded->coordinates) && count($decoded->coordinates) > 0) {
                $first_polygon = $decoded->coordinates[0];
                if (is_array($first_polygon) && count($first_polygon) > 0) {
                    $ring = $first_polygon[0]; // first polygon outer ring
                    return knx_coverage_geojson_to_points_internal($ring);
                }
            }
        }
        knx_coverage_set_last_error_internal('INVALID_GEOJSON');
        return null;
    }

    // Legacy array formats
    if (is_array($decoded)) {
        $points = knx_coverage_legacy_to_points_internal($decoded);
        return $points;
    }

    knx_coverage_set_last_error_internal('UNSUPPORTED_FORMAT');
    return null;
}


/**
 * Convert GeoJSON ring to normalized points.
 * 
 * Internal converter for coverage-engine.php only.
 * 
 * GeoJSON coordinate order: [longitude, latitude]
 * Internal format: [{lat, lng}, ...]
 * 
 * @param array $ring Array of [lng, lat] pairs
 * @return array|null Point array or null
 */
function knx_coverage_geojson_to_points_internal($ring) {
    if (!is_array($ring) || count($ring) < 3) {
        knx_coverage_set_last_error_internal('< 3 points');
        return null;
    }

    $points = [];

    foreach ($ring as $coord) {
        if (!is_array($coord) || count($coord) < 2) {
            knx_coverage_set_last_error_internal('MALFORMED_COORD');
            return null;
        }

        $lng = $coord[0];
        $lat = $coord[1];

        if (!is_numeric($lng) || !is_numeric($lat)) {
            knx_coverage_set_last_error_internal('NON_NUMERIC_COORD');
            return null;
        }

        $lat_f = (float) $lat;
        $lng_f = (float) $lng;

        if ($lat_f < -90 || $lat_f > 90) {
            knx_coverage_set_last_error_internal('OUT_OF_RANGE_LAT');
            return null;
        }

        if ($lng_f < -180 || $lng_f > 180) {
            knx_coverage_set_last_error_internal('OUT_OF_RANGE_LNG');
            return null;
        }

        $points[] = (object) [
            'lat' => $lat_f,
            'lng' => $lng_f,
        ];
    }

    if (count($points) >= 3) {
        return $points;
    }

    knx_coverage_set_last_error_internal('< 3 points');
    return null;
}

/**
 * Convert legacy array formats to normalized points.
 * 
 * Internal converter for coverage-engine.php only.
 * 
 * SUPPORTS:
 * - [{ lat, lng }, ...] (object format)
 * - [[lat, lng], ...] (array format)
 * 
 * @param array $decoded Legacy array
 * @return array|null Point array or null
 */
function knx_coverage_legacy_to_points_internal($decoded) {
    if (!is_array($decoded) || count($decoded) < 3) {
        knx_coverage_set_last_error_internal('< 3 points');
        return null;
    }

    $points = [];

    foreach ($decoded as $p) {
        // Object form: { lat, lng }
        if (is_object($p) && isset($p->lat) && isset($p->lng)) {
            if (!is_numeric($p->lat) || !is_numeric($p->lng)) {
                knx_coverage_set_last_error_internal('NON_NUMERIC_COORD');
                return null;
            }

            $lat_f = (float) $p->lat;
            $lng_f = (float) $p->lng;

            if ($lat_f < -90 || $lat_f > 90) {
                knx_coverage_set_last_error_internal('OUT_OF_RANGE_LAT');
                return null;
            }

            if ($lng_f < -180 || $lng_f > 180) {
                knx_coverage_set_last_error_internal('OUT_OF_RANGE_LNG');
                return null;
            }

            $points[] = (object) [
                'lat' => $lat_f,
                'lng' => $lng_f,
            ];
            continue;
        }

        // Array form: [lat, lng]
        if (is_array($p) && count($p) >= 2) {
            if (!is_numeric($p[0]) || !is_numeric($p[1])) {
                knx_coverage_set_last_error_internal('NON_NUMERIC_COORD');
                return null;
            }

            $lat_f = (float) $p[0];
            $lng_f = (float) $p[1];

            if ($lat_f < -90 || $lat_f > 90) {
                knx_coverage_set_last_error_internal('OUT_OF_RANGE_LAT');
                return null;
            }

            if ($lng_f < -180 || $lng_f > 180) {
                knx_coverage_set_last_error_internal('OUT_OF_RANGE_LNG');
                return null;
            }

            $points[] = (object) [
                'lat' => $lat_f,
                'lng' => $lng_f,
            ];
            continue;
        }

        knx_coverage_set_last_error_internal('MALFORMED_POINT');
        return null; // fail-closed on malformed point
    }

    if (count($points) >= 3) {
        return $points;
    }

    knx_coverage_set_last_error_internal('< 3 points');
    return null;
}
