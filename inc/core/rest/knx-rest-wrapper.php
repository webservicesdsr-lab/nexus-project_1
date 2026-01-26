<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX REST — Wrapper (PHASE 1 / SEALED v4.C.0.B.W2)
 * ----------------------------------------------------------
 * This is the CANONICAL wrapper for all REST handlers.
 * 
 * RESPONSIBILITIES:
 * - Catches all exceptions/fatals (Throwable)
 * - Normalizes return types to WP_REST_Response
 * - Prevents technical error leaks to clients
 * 
 * DOES NOT:
 * - Validate response schemas (handler responsibility)
 * - Log errors (add external monitoring if needed)
 * - Validate session structure (permission_callback responsibility)
 * - Enforce NEXUS response format (optional enforcement)
 * 
 * RETURN TYPE HANDLING:
 * - WP_REST_Response → Pass-through (no normalization)
 * - WP_Error → Convert to knx_rest_error()
 * - array|object → Wrap as success response
 * - scalar → Wrap as success response
 * - Exception → Generic "Server error" (500)
 * 
 * IMPORTANT:
 * - No hooks
 * - No endpoints
 * - No output
 * ==========================================================
 */

if (!function_exists('knx_rest_wrap')) {
    /**
     * Wrap a REST handler callback for standardized error handling.
     *
     * Usage in a resource:
     * ```
     * register_rest_route('knx/v1', '/resource', [
     *   'callback' => knx_rest_wrap('my_handler_function')
     * ]);
     * ```
     * 
     * WRAPPER BEHAVIOR:
     * 1. Executes handler with WP_REST_Request
     * 2. Catches all exceptions (Throwable) and returns generic error
     * 3. Normalizes handler return types:
     *    - WP_REST_Response → returned as-is (NO schema enforcement)
     *    - WP_Error → converted to knx_rest_error()
     *    - array|object → wrapped as success response
     *    - scalar → wrapped as success response
     * 
     * FAIL-CLOSED: Exceptions always return 500 with no details.
     * 
     * @param callable|string $handler Callable or function name.
     * @return callable WordPress-compatible REST callback.
     */
    function knx_rest_wrap($handler) {
        return function(WP_REST_Request $request) use ($handler) {

            try {
                if (is_string($handler) && function_exists($handler)) {
                    $out = call_user_func($handler, $request);
                } elseif (is_callable($handler)) {
                    $out = call_user_func($handler, $request);
                } else {
                    return knx_rest_error('Invalid handler', 500);
                }

                // If handler returned WP_REST_Response already, pass through.
                if ($out instanceof WP_REST_Response) {
                    return $out;
                }

                // If handler returned WP_Error, normalize.
                if (is_wp_error($out)) {
                    $error_data = $out->get_error_data();
                    $status = 500; // Default
                    if (is_array($error_data) && isset($error_data['status'])) {
                        $status = (int) $error_data['status'];
                    }
                    return knx_rest_error($out->get_error_message(), $status, [
                        'code' => $out->get_error_code(),
                    ]);
                }

                // If handler returned array/object, wrap as success.
                if (is_array($out) || is_object($out)) {
                    return knx_rest_response(true, 'OK', $out, 200);
                }

                // Fallback scalar
                return knx_rest_response(true, 'OK', ['result' => $out], 200);

            } catch (Throwable $e) {
                // Never leak full error details in production.
                return knx_rest_error('Server error', 500);
            }
        };
    }
}
