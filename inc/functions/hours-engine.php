<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Working Hours Engine (v1.0)
 * ============================================
 * Robust hours calculation engine that handles:
 * - Multiple intervals per day
 * - Overnight schedules (close time next day)
 * - Timezone conversions
 * - Temporary closures
 * - Malformed JSON gracefully
 * 
 * Usage:
 * $hub = get_hub_data(); // Your hub object
 * $status = knx_hours_get_status($hub);
 * $hours = knx_hours_format_today($hub);
 * $weekly = knx_hours_format_weekly($hub);
 */

/**
 * Get the current open/closed status of a hub
 * 
 * @param object $hub Hub data with timezone, hours_*, closure_* fields
 * @return array ['is_open' => bool, 'status_text' => string, 'hours_today' => string]
 */
function knx_hours_get_status($hub) {
    // Default response
    $result = [
        'is_open'        => false,
        'status_text'    => 'Closed',
        'hours_today'    => null,
        'next_change'    => null,
        'is_temp_closed' => false,
        'closure_until'  => null,
        'closure_reason' => '',
    ];

    // Check hub status
    if (empty($hub->status) || $hub->status !== 'active') {
        $result['status_text'] = 'Inactive';
        return $result;
    }

    // --- Temporary/Indefinite Closure Logic ---
    $closureUntil  = !empty($hub->closure_until) ? trim($hub->closure_until) : '';
    $closureReason = isset($hub->closure_reason) ? trim((string) $hub->closure_reason) : '';

    $timezone_name = !empty($hub->timezone) ? $hub->timezone : 'America/Chicago';
    try {
        $tz = new DateTimeZone($timezone_name);
        $now = new DateTime('now', $tz);
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
        $now = new DateTime('now', $tz);
    }

    $todayKey = strtolower($now->format('l'));

    $closureUntilDt = $closureUntil ? new DateTime($closureUntil, $tz) : null;

    if ($closureUntilDt && $now <= $closureUntilDt) {
        $result['is_temp_closed'] = true;
        $result['is_open']        = false;
        $result['status_text']    = 'Temporarily Closed';
        $result['closure_reason'] = $closureReason;
        if (!empty($closureReason)) {
            $result['hours_today'] = $closureReason;
        } else {
            $result['hours_today'] = 'Temporarily closed, back soon.';
        }
        $result['next_change']   = $closureUntilDt->format(DATE_ATOM);
        $result['closure_until'] = $closureUntilDt->format(DATE_ATOM);
        return $result;
    } else if ($closureUntil && !$closureUntilDt) {
        // Invalid date format, treat as indefinite closure
        $result['is_temp_closed'] = true;
        $result['is_open']        = false;
        $result['status_text']    = 'Temporarily Closed';
        $result['closure_reason'] = $closureReason;
        $result['hours_today'] = 'Temporarily closed (indefinite).';
        $result['closure_until'] = $closureUntil;
        return $result;
    }

    // --- Weekly Hours Logic (existing) ---
    $current_day = strtolower($now->format('l'));
    $current_time = $now->format('H:i');
    $current_date = $now->format('Y-m-d');

    $intervals = knx_hours_get_intervals($hub, $current_day);

    if (empty($intervals)) {
        $result['hours_today'] = 'Closed';
        return $result;
    }

    $is_open_now = false;
    $formatted_intervals = [];
    $next_opening = null;
    $next_closing = null;

    foreach ($intervals as $interval) {
        if (empty($interval['open']) || empty($interval['close'])) {
            continue;
        }
        $open_time = $interval['open'];
        $close_time = $interval['close'];
        $is_overnight = $close_time < $open_time;
        if ($is_overnight) {
            if ($current_time >= $open_time || $current_time <= $close_time) {
                $is_open_now = true;
                $next_closing = $close_time;
            } else if ($current_time < $open_time && !$next_opening) {
                $next_opening = $open_time;
            }
        } else {
            if ($current_time >= $open_time && $current_time <= $close_time) {
                $is_open_now = true;
                $next_closing = $close_time;
            } else if ($current_time < $open_time && !$next_opening) {
                $next_opening = $open_time;
            }
        }
        $formatted_intervals[] = knx_hours_format_interval($open_time, $close_time, $is_overnight, 'mixed');
    }

    $result['is_open'] = $is_open_now;
    $result['status_text'] = $is_open_now ? 'Open now' : 'Closed';
    $result['hours_today'] = implode(', ', $formatted_intervals);
    // Optionally, set next_change (not strictly required)
    $result['next_change'] = $next_closing ? $next_closing : ($next_opening ? $next_opening : null);

    return $result;
}

/**
 * Get intervals for a specific day, handling malformed JSON gracefully
 * 
 * @param object $hub Hub object
 * @param string $day Day name (monday, tuesday, etc.)
 * @return array Array of intervals or empty array
 */
function knx_hours_get_intervals($hub, $day) {
    $column = 'hours_' . $day;
    
    if (empty($hub->{$column})) {
        return [];
    }
    
    $json_string = $hub->{$column};
    
    // Handle malformed JSON gracefully
    $intervals = json_decode($json_string, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($intervals)) {
        return [];
    }
    
    // Validate each interval
    $valid_intervals = [];
    foreach ($intervals as $interval) {
        if (is_array($interval) && 
            isset($interval['open']) && 
            isset($interval['close']) &&
            knx_hours_validate_time($interval['open']) &&
            knx_hours_validate_time($interval['close'])) {
            
            $valid_intervals[] = $interval;
        }
    }
    
    return $valid_intervals;
}

/**
 * Validate time format (HH:MM)
 * 
 * @param string $time Time string
 * @return bool True if valid
 */
function knx_hours_validate_time($time) {
    return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time);
}

/**
 * Format a single time interval for display
 * 
 * @param string $open_time Opening time (HH:MM)
 * @param string $close_time Closing time (HH:MM)
 * @param bool $is_overnight Whether this spans midnight
 * @param string $format Format type: '12h' (default), '24h', or 'mixed'
 * @return string Formatted interval
 */
function knx_hours_format_interval($open_time, $close_time, $is_overnight = null, $format = '12h') {
    if ($is_overnight === null) {
        $is_overnight = $close_time < $open_time;
    }
    
    try {
        $open_dt = DateTime::createFromFormat('H:i', $open_time);
        $close_dt = DateTime::createFromFormat('H:i', $close_time);
        
        if (!$open_dt || !$close_dt) {
            return $open_time . ' - ' . $close_time;
        }
        
        // Choose format based on parameter
        switch ($format) {
            case '24h':
                $formatted_open = $open_dt->format('H:i');
                $formatted_close = $close_dt->format('H:i');
                break;
            case 'mixed':
                // Clean 12h format (no leading zeros, smart AM/PM)
                $formatted_open = $open_dt->format('g:i A');
                $formatted_close = $close_dt->format('g:i A');
                // Remove AM/PM if both are the same
                if (substr($formatted_open, -2) === substr($formatted_close, -2)) {
                    $formatted_open = $open_dt->format('g:i');
                }
                break;
            default: // 12h
                $formatted_open = $open_dt->format('g:i A');
                $formatted_close = $close_dt->format('g:i A');
                break;
        }
        
        if ($is_overnight) {
            return $formatted_open . ' - ' . $formatted_close . ' <small>+1</small>';
        } else {
            return $formatted_open . ' - ' . $formatted_close;
        }
        
    } catch (Exception $e) {
        return $open_time . ' - ' . $close_time;
    }
}

/**
 * Format today's hours for a hub
 * 
 * @param object $hub Hub object
 * @return string Formatted hours or 'Closed'
 */
function knx_hours_format_today($hub) {
    $status = knx_hours_get_status($hub);
    return $status['hours_today'] ?: 'Closed';
}

/**
 * Get formatted weekly hours for a hub
 * 
 * @param object $hub Hub object
 * @return array Weekly hours data
 */
function knx_hours_format_weekly($hub) {
    $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    $day_names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    
    $weekly = [];
    
    foreach ($days as $index => $day) {
        $intervals = knx_hours_get_intervals($hub, $day);
        
        $day_data = [
            'day' => $day_names[$index],
            'day_short' => substr($day_names[$index], 0, 3),
            'is_open' => !empty($intervals),
            'hours' => 'Closed',
            'intervals' => $intervals
        ];
        
        if (!empty($intervals)) {
            $formatted_intervals = [];
            foreach ($intervals as $interval) {
                $is_overnight = $interval['close'] < $interval['open'];
                $formatted_intervals[] = knx_hours_format_interval(
                    $interval['open'], 
                    $interval['close'], 
                    $is_overnight,
                    'mixed'
                );
            }
            $day_data['hours'] = implode(', ', $formatted_intervals);
        }
        
        $weekly[$day] = $day_data;
    }
    
    return $weekly;
}

/**
 * Add hours data to hub object (for API responses)
 * 
 * @param object $hub Hub object (passed by reference)
 * @return void
 */
function knx_hours_enrich_hub(&$hub) {
    $status = knx_hours_get_status($hub);
    $hub->is_open        = $status['is_open'];
    $hub->status_text    = $status['status_text'];
    $hub->hours_today    = $status['hours_today'];
    $hub->next_change    = $status['next_change'];
    $hub->is_temp_closed = $status['is_temp_closed'];
    $hub->closure_until  = $status['closure_until'];
    $hub->closure_reason = $status['closure_reason'];
    // Optional: Add weekly hours (commented out to keep response light)
    // $hub->weekly_hours = knx_hours_format_weekly($hub);
}

/**
 * Batch enrich multiple hubs with hours data
 * 
 * @param array $hubs Array of hub objects (passed by reference)
 * @return void
 */
function knx_hours_enrich_hubs(&$hubs) {
    if (empty($hubs) || !is_array($hubs)) {
        return;
    }
    
    foreach ($hubs as &$hub) {
        knx_hours_enrich_hub($hub);
    }
}

/**
 * Helper: Get hub data with hours columns for a single hub
 * 
 * @param int $hub_id Hub ID
 * @return object|null Hub object or null if not found
 */
function knx_hours_get_hub($hub_id) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'knx_hubs';
    
    $hub = $wpdb->get_row($wpdb->prepare("
        SELECT id, name, slug, status, timezone,
               closure_start, closure_end,
               hours_monday, hours_tuesday, hours_wednesday, 
               hours_thursday, hours_friday, hours_saturday, hours_sunday
        FROM {$table} 
        WHERE id = %d AND status = 'active'
        LIMIT 1
    ", $hub_id));
    
    return $hub;
}

/**
 * Get formatted status for public display (clean and concise)
 * 
 * @param object $hub Hub data
 * @return array ['status' => string, 'hours' => string, 'is_open' => bool]
 */
function knx_hours_get_public_status($hub) {
    $status = knx_hours_get_status($hub);
    
    $result = [
        'status' => $status['is_open'] ? 'Open now' : 'Closed',
        'hours' => $status['hours_today'] ?: 'Closed today',
        'is_open' => $status['is_open'],
        'status_class' => $status['is_open'] ? 'open' : 'closed'
    ];
    
    // Handle temporary closure
    if (strpos($status['status_text'], 'Temporarily') !== false) {
        $result['status'] = 'Temp. Closed';
        $result['status_class'] = 'temp-closed';
    }
    
    return $result;
}

/**
 * Get today's hours in a clean format for display
 * 
 * @param object $hub Hub data
 * @param string $format Format: 'mixed' (default), '12h', '24h'
 * @return string Formatted hours
 */
function knx_hours_get_today_display($hub, $format = 'mixed') {
    $intervals = knx_hours_get_intervals($hub, strtolower(date('l')));
    
    if (empty($intervals)) {
        return 'Closed today';
    }
    
    $formatted = [];
    foreach ($intervals as $interval) {
        if (!empty($interval['open']) && !empty($interval['close'])) {
            $is_overnight = $interval['close'] < $interval['open'];
            $formatted[] = knx_hours_format_interval(
                $interval['open'], 
                $interval['close'], 
                $is_overnight, 
                $format
            );
        }
    }
    
    return implode(', ', $formatted);
}

/**
 * Helper: Check if any hub is currently open (for quick filtering)
 * 
 * @param array $hub_ids Array of hub IDs to check
 * @return array Array of hub IDs that are currently open
 */
function knx_hours_filter_open_hubs($hub_ids) {
    if (empty($hub_ids)) {
        return [];
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'knx_hubs';
    
    $placeholders = implode(',', array_fill(0, count($hub_ids), '%d'));
    
    $hubs = $wpdb->get_results($wpdb->prepare("
        SELECT id, status, timezone, closure_start, closure_end,
               hours_monday, hours_tuesday, hours_wednesday, 
               hours_thursday, hours_friday, hours_saturday, hours_sunday
        FROM {$table} 
        WHERE id IN ({$placeholders}) AND status = 'active'
    ", ...$hub_ids));
    
    if (empty($hubs)) {
        return [];
    }
    
    $open_hub_ids = [];
    foreach ($hubs as $hub) {
        $status = knx_hours_get_status($hub);
        if ($status['is_open']) {
            $open_hub_ids[] = intval($hub->id);
        }
    }
    
    return $open_hub_ids;
}