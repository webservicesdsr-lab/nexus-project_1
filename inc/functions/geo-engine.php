<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX GEO ENGINE (CANONICAL)
 * ----------------------------------------------------------
 * Pure geographic calculation helpers.
 * NO business logic, NO database queries, NO WordPress deps.
 * 
 * RULE:
 * - This is the ONLY file that defines knx_calculate_distance()
 * - All other files MUST use this, never redefine
 * - Loaded FIRST in bootstrap before any consumers
 * ==========================================================
 */

if (!function_exists('knx_calculate_distance')) {
    /**
     * Calculate distance between two coordinates using Haversine formula.
     * 
     * CANONICAL IMPLEMENTATION
     * - Pure math function
     * - No external dependencies
     * - Thread-safe
     * 
     * @param float  $lat1 Starting latitude
     * @param float  $lng1 Starting longitude
     * @param float  $lat2 Ending latitude
     * @param float  $lng2 Ending longitude
     * @param string $unit 'mile' or 'kilometer'
     * @return float Distance in specified unit
     */
    function knx_calculate_distance($lat1, $lng1, $lat2, $lng2, $unit = 'mile') {
        $lat1 = (float) $lat1;
        $lng1 = (float) $lng1;
        $lat2 = (float) $lat2;
        $lng2 = (float) $lng2;

        $theta = $lng1 - $lng2;

        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2))
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));

        // Clamp to valid range [-1, 1] to handle floating point precision
        if ($dist > 1) $dist = 1;
        if ($dist < -1) $dist = -1;

        $dist = acos($dist);
        $dist = rad2deg($dist);

        $miles = $dist * 60 * 1.1515;

        if ($unit === 'kilometer') {
            return $miles * 1.609344;
        }

        return $miles;
    }
}
