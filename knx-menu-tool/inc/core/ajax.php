<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Menu Tool — AJAX Handlers
 * ==========================================================
 *
 * Notes:
 * - No custom DB writes here.
 * - IndexedDB remains the source of local draft truth.
 * - Structure parsing returns the canonical contract expected by the UI:
 *   {
 *     item: {...},
 *     confidence: {...},
 *     warnings: [...],
 *     evidence: {...},
 *     cleaned_text: "..."
 *   }
 * - CSV export accepts either:
 *   1) a single snapshot object
 *   2) an array of snapshot objects
 * ==========================================================
 */

final class KNX_Menu_Tool_Ajax {

    /**
     * Central AJAX gate.
     *
     * @return array
     */
    private static function gate_or_die() {
        if (!class_exists('KNX_Menu_Tool_Security')) {
            wp_send_json_error(['message' => 'Security layer unavailable.'], 500);
        }

        $access = KNX_Menu_Tool_Security::check_ajax_access('knx_menu_tool_nonce');

        if (empty($access['ok'])) {
            $status = isset($access['status']) ? (int) $access['status'] : 403;
            $message = isset($access['message']) ? (string) $access['message'] : 'Access denied.';
            wp_send_json_error(['message' => $message], $status);
        }

        return $access;
    }

    /**
     * Parse OCR text into canonical item structure.
     *
     * POST:
     * - nonce
     * - ocr_text
     *
     * @return void
     */
    public static function handle_structure() {
        self::gate_or_die();

        $ocr_text = isset($_POST['ocr_text']) ? (string) wp_unslash($_POST['ocr_text']) : '';
        if (trim($ocr_text) === '') {
            wp_send_json_error(['message' => 'Missing ocr_text.'], 400);
        }

        if (!class_exists('KNX_Menu_Tool_Text_Cleaner')) {
            wp_send_json_error(['message' => 'Text cleaner unavailable.'], 500);
        }

        if (!class_exists('KNX_Menu_Tool_Structure_Engine')) {
            wp_send_json_error(['message' => 'Structure engine unavailable.'], 500);
        }

        $cleaned_text = KNX_Menu_Tool_Text_Cleaner::clean($ocr_text, 'web_item');
        $result = KNX_Menu_Tool_Structure_Engine::parse_web_item_text($cleaned_text);

        if (!is_array($result) || !isset($result['item']) || !is_array($result['item'])) {
            wp_send_json_error(['message' => 'Parser returned an invalid payload.'], 500);
        }

        $result['cleaned_text'] = $cleaned_text;

        wp_send_json_success($result);
    }

    /**
     * Tool is intentionally agnostic. Return an empty preset library.
     *
     * @return void
     */
    public static function handle_get_modifiers() {
        self::gate_or_die();

        wp_send_json_success([
            'presets' => [],
        ]);
    }

    /**
     * Stream CSV from one or many frozen snapshots.
     *
     * Request:
     * - nonce
     * - snapshot_json
     *
     * Accepted payloads:
     * - { title, description, base_price, globals, groups }
     * - [ {snapshot1}, {snapshot2}, ... ]
     *
     * @return void
     */
    public static function handle_download_csv() {
        self::gate_or_die();

        $snapshot_json = isset($_REQUEST['snapshot_json']) ? (string) wp_unslash($_REQUEST['snapshot_json']) : '';
        if (trim($snapshot_json) === '') {
            wp_send_json_error(['message' => 'Missing snapshot_json.'], 400);
        }

        $decoded = json_decode($snapshot_json, true);
        if (!is_array($decoded)) {
            wp_send_json_error(['message' => 'Invalid snapshot_json (must decode into array or object).'], 400);
        }

        $snapshots = self::normalize_snapshot_payload($decoded);
        if (empty($snapshots)) {
            wp_send_json_error(['message' => 'No valid snapshots found for export.'], 400);
        }

        self::stream_snapshot_collection_csv($snapshots);
        exit;
    }

    /**
     * Normalize incoming payload to a list of canonical item snapshots.
     *
     * @param mixed $decoded
     * @return array
     */
    private static function normalize_snapshot_payload($decoded) {
        if (!is_array($decoded)) {
            return [];
        }

        /**
         * Single snapshot object:
         * [
         *   'title' => '',
         *   'description' => '',
         *   ...
         * ]
         */
        if (self::is_single_snapshot_shape($decoded)) {
            return [self::normalize_single_snapshot($decoded)];
        }

        /**
         * Multi snapshot array:
         * [
         *   [...],
         *   [...],
         * ]
         */
        $output = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!self::is_single_snapshot_shape($entry)) {
                continue;
            }

            $output[] = self::normalize_single_snapshot($entry);
        }

        return $output;
    }

    /**
     * Check whether the array looks like a single canonical snapshot object.
     *
     * @param array $data
     * @return bool
     */
    private static function is_single_snapshot_shape(array $data) {
        return array_key_exists('title', $data)
            || array_key_exists('description', $data)
            || array_key_exists('base_price', $data)
            || array_key_exists('groups', $data);
    }

    /**
     * Normalize a single canonical snapshot.
     *
     * @param array $snapshot
     * @return array
     */
    private static function normalize_single_snapshot(array $snapshot) {
        $groups = [];

        if (!empty($snapshot['groups']) && is_array($snapshot['groups'])) {
            foreach ($snapshot['groups'] as $group) {
                if (!is_array($group)) {
                    continue;
                }

                $options = [];
                if (!empty($group['options']) && is_array($group['options'])) {
                    foreach ($group['options'] as $option) {
                        if (!is_array($option)) {
                            continue;
                        }

                        $options[] = [
                            'name' => trim((string) ($option['name'] ?? '')),
                            'price_adjustment' => (float) ($option['price_adjustment'] ?? 0),
                        ];
                    }
                }

                $max_selection = array_key_exists('max_selection', $group)
                    ? $group['max_selection']
                    : 1;

                if ($max_selection !== null) {
                    $max_selection = (int) $max_selection;
                }

                $groups[] = [
                    'name' => trim((string) ($group['name'] ?? '')),
                    'type' => trim((string) ($group['type'] ?? 'single')),
                    'required' => !empty($group['required']),
                    'min_selection' => (int) ($group['min_selection'] ?? 0),
                    'max_selection' => $max_selection,
                    'options' => $options,
                ];
            }
        }

        return [
            'title' => trim((string) ($snapshot['title'] ?? '')),
            'description' => trim((string) ($snapshot['description'] ?? '')),
            'base_price' => (float) ($snapshot['base_price'] ?? 0),
            'globals' => is_array($snapshot['globals'] ?? null) ? $snapshot['globals'] : [],
            'groups' => $groups,
        ];
    }

    /**
     * Stream a row-type CSV for a collection of snapshots.
     *
     * Format:
     * row_type,item_key,title,description,base_price,globals_json,group_key,group_name,required,min_selection,max_selection,option_name,price_adjustment,sort_order
     *
     * @param array $snapshots
     * @return void
     */
    private static function stream_snapshot_collection_csv(array $snapshots) {
        $filename = 'knx_menu_tool_' . gmdate('Y-m-d_His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        if (!$out) {
            status_header(500);
            echo 'Could not open output stream.';
            exit;
        }

        fputcsv($out, [
            'row_type',
            'item_key',
            'title',
            'description',
            'base_price',
            'globals_json',
            'group_key',
            'group_name',
            'required',
            'min_selection',
            'max_selection',
            'option_name',
            'price_adjustment',
            'sort_order',
        ]);

        $item_sort = 1;

        foreach ($snapshots as $snapshot) {
            $title = (string) ($snapshot['title'] ?? '');
            $description = (string) ($snapshot['description'] ?? '');
            $base_price = number_format((float) ($snapshot['base_price'] ?? 0), 2, '.', '');
            $globals = is_array($snapshot['globals'] ?? null) ? $snapshot['globals'] : [];
            $groups = is_array($snapshot['groups'] ?? null) ? $snapshot['groups'] : [];

            $item_key = 'it_' . substr(sha1($item_sort . '|' . $title . '|' . $base_price), 0, 12);

            /**
             * Item row
             */
            fputcsv($out, [
                'item',
                $item_key,
                $title,
                $description,
                $base_price,
                wp_json_encode($globals),
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                $item_sort,
            ]);

            /**
             * Group rows and option rows
             */
            $group_sort = 1;

            foreach ($groups as $group) {
                $group_name = (string) ($group['name'] ?? '');
                $required = !empty($group['required']) ? 1 : 0;
                $min = (int) ($group['min_selection'] ?? 0);
                $max = array_key_exists('max_selection', $group) ? $group['max_selection'] : '';
                $group_key = 'g_' . substr(sha1($item_key . '|' . $group_name . '|' . $group_sort), 0, 12);

                fputcsv($out, [
                    'group',
                    $item_key,
                    '',
                    '',
                    '',
                    '',
                    $group_key,
                    $group_name,
                    $required,
                    $min,
                    $max === null ? '' : $max,
                    '',
                    '',
                    $group_sort,
                ]);

                $options = is_array($group['options'] ?? null) ? $group['options'] : [];
                $option_sort = 1;

                foreach ($options as $option) {
                    $option_name = (string) ($option['name'] ?? '');
                    $price_adjustment = number_format((float) ($option['price_adjustment'] ?? 0), 2, '.', '');

                    fputcsv($out, [
                        'option',
                        $item_key,
                        '',
                        '',
                        '',
                        '',
                        $group_key,
                        '',
                        '',
                        '',
                        '',
                        $option_name,
                        $price_adjustment,
                        $option_sort,
                    ]);

                    $option_sort++;
                }

                $group_sort++;
            }

            $item_sort++;
        }

        fclose($out);
        exit;
    }
}

/**
 * Legacy wrappers in case some hooks still point to function names
 * instead of the class static methods.
 */
if (!function_exists('knx_mt_ajax_structure')) {
    function knx_mt_ajax_structure() {
        KNX_Menu_Tool_Ajax::handle_structure();
    }
}

if (!function_exists('knx_mt_ajax_get_modifiers')) {
    function knx_mt_ajax_get_modifiers() {
        KNX_Menu_Tool_Ajax::handle_get_modifiers();
    }
}

if (!function_exists('knx_mt_ajax_download_csv')) {
    function knx_mt_ajax_download_csv() {
        KNX_Menu_Tool_Ajax::handle_download_csv();
    }
}