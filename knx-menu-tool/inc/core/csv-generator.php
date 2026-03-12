<?php
if (!defined('ABSPATH')) exit;

final class KNX_Menu_Tool_CSV_Generator {

    /**
     * Streams a row-type CSV from one or many canonical item snapshot payloads.
     *
     * Accepted input:
     * [
     *   [
     *     'title' => '',
     *     'description' => '',
     *     'base_price' => 0,
     *     'globals' => [],
     *     'groups' => [
     *       [
     *         'name' => '',
     *         'required' => true,
     *         'min_selection' => 1,
     *         'max_selection' => 2,
     *         'options' => [
     *           ['name' => '', 'price_adjustment' => 0]
     *         ]
     *       ]
     *     ]
     *   ]
     * ]
     *
     * @param array $snapshots
     * @return void
     */
    public static function stream_rowtype_csv(array $snapshots) {
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
            $base_price = self::normalize_money($snapshot['base_price'] ?? 0);
            $globals = is_array($snapshot['globals'] ?? null) ? $snapshot['globals'] : [];
            $groups = is_array($snapshot['groups'] ?? null) ? $snapshot['groups'] : [];

            $item_key = 'it_' . substr(sha1($title . '|' . $base_price . '|' . $item_sort), 0, 10);

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
             * Group and option rows
             */
            $group_sort = 1;
            foreach ($groups as $group) {
                $group_name = (string) ($group['name'] ?? '');
                $required = !empty($group['required']) ? 1 : 0;
                $min = (int) ($group['min_selection'] ?? 0);
                $max = array_key_exists('max_selection', $group) ? $group['max_selection'] : '';
                $group_key = 'g_' . substr(sha1($item_key . '|' . $group_name . '|' . $group_sort), 0, 10);

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
                    $price_adjustment = self::normalize_money($option['price_adjustment'] ?? 0);

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

    /**
     * @param mixed $value
     * @return string
     */
    private static function normalize_money($value) {
        return number_format((float) $value, 2, '.', '');
    }
}