<?php
if (!defined('ABSPATH')) exit;

final class KNX_Menu_Tool_Structure_Engine {

    /**
     * Parse a single ordering-modal OCR text into canonical item shape.
     *
     * @param string $text
     * @return array
     */
    public static function parse_web_item_text($text) {
        $lines = self::normalize_lines($text);

        $warnings = [];
        $evidence = [];

        $item = [
            'title' => '',
            'description' => '',
            'base_price' => 0,
            'globals' => [
                'special_instructions_allowed' => false,
            ],
            'groups' => [],
        ];

        $confidence = [
            'title' => 0.0,
            'base_price' => 0.0,
            'description' => 0.0,
            'groups' => 0.0,
        ];

        if (empty($lines)) {
            $warnings[] = 'No OCR lines found.';
            return compact('item', 'confidence', 'warnings', 'evidence');
        }

        $zones = self::split_modal_zones($lines);

        $header = self::parse_header_zone($zones['header_lines'], $zones['group_lines']);
        $groups = self::parse_group_zone($zones['group_lines'], $warnings);

        $item['title'] = $header['title'];
        $item['description'] = $header['description'];
        $item['base_price'] = $header['base_price'];
        $item['globals']['special_instructions_allowed'] = $zones['special_instructions_detected'];
        $item['groups'] = $groups;

        if ($item['title'] !== '') {
            $confidence['title'] = 0.97;
            $evidence['title'] = [$item['title']];
        } else {
            $warnings[] = 'Title not confidently detected.';
        }

        if ($header['base_price_found']) {
            $confidence['base_price'] = 0.95;
            $evidence['base_price'] = [$header['base_price_source']];
        } else {
            $confidence['base_price'] = 0.20;
            $warnings[] = 'Base price not confidently detected.';
        }

        if ($item['description'] !== '') {
            $confidence['description'] = 0.88;
            $evidence['description'] = [$item['description']];
        }

        if (!empty($item['groups'])) {
            $confidence['groups'] = 0.94;
            $evidence['groups'] = array_map(static function ($group) {
                return $group['name'];
            }, $item['groups']);
        } else {
            $warnings[] = 'No modifier groups detected.';
        }

        return compact('item', 'confidence', 'warnings', 'evidence');
    }

    /**
     * @param string $text
     * @return array
     */
    private static function normalize_lines($text) {
        $raw_lines = preg_split('/\r\n|\r|\n/', (string) $text);
        $lines = [];

        foreach ($raw_lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/\s+/', ' ', $line);
            $line = self::fix_common_ocr_money_tokens($line);
            $line = self::normalize_cta_copy($line);
            $line = self::normalize_rule_copy($line);
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            if (self::looks_like_hard_noise_line($line)) {
                continue;
            }

            $lines[] = $line;
        }

        return array_values($lines);
    }

    /**
     * Split the OCR into header zone and group zone.
     *
     * @param array $lines
     * @return array
     */
    private static function split_modal_zones(array $lines) {
        $header_lines = [];
        $group_lines = [];
        $special_instructions_detected = false;

        $in_groups = false;

        foreach ($lines as $index => $line) {
            if (self::looks_like_special_instructions($line)) {
                $special_instructions_detected = true;
                $in_groups = true;
                continue;
            }

            if (!$in_groups && self::looks_like_group_header($line, $index, $lines)) {
                $in_groups = true;
            }

            if ($in_groups) {
                $group_lines[] = $line;
            } else {
                $header_lines[] = $line;
            }
        }

        return [
            'header_lines' => array_values($header_lines),
            'group_lines' => array_values($group_lines),
            'special_instructions_detected' => $special_instructions_detected,
        ];
    }

    /**
     * Parse the modal header:
     * title, description, base price.
     *
     * @param array $headerLines
     * @param array $groupLines
     * @return array
     */
    private static function parse_header_zone(array $headerLines, array $groupLines) {
        $title = '';
        $description = '';
        $base_price = 0.00;
        $base_price_found = false;
        $base_price_source = '';

        if (empty($headerLines)) {
            return compact('title', 'description', 'base_price', 'base_price_found', 'base_price_source');
        }

        /**
         * Strong base price detection:
         * first clean money line in header wins.
         */
        foreach ($headerLines as $line) {
            if (self::looks_like_clean_base_price_line($line)) {
                $money = self::extract_money($line);
                if ($money !== null) {
                    $base_price = $money;
                    $base_price_found = true;
                    $base_price_source = $line;
                    break;
                }
            }
        }

        /**
         * Fallback: try to recover a clean money token from the compact group text,
         * but only if it appears before obvious CTA-heavy sections.
         */
        if (!$base_price_found) {
            $compact = trim(implode(' ', $headerLines));
            if (preg_match('/\$\s*(\d{1,5}(?:\.\d{2})?)\b/', $compact, $m)) {
                $base_price = (float) $m[1];
                $base_price_found = true;
                $base_price_source = '$' . $m[1];
            }
        }

        /**
         * Preferred title:
         * nearest strong non-description line before the price.
         */
        $priceIndex = null;
        if ($base_price_found) {
            foreach ($headerLines as $idx => $line) {
                if ($line === $base_price_source || self::looks_like_clean_base_price_line($line)) {
                    $priceIndex = $idx;
                    break;
                }
            }
        }

        if ($priceIndex !== null) {
            for ($i = $priceIndex - 1; $i >= 0; $i--) {
                $line = trim((string) $headerLines[$i]);

                if ($line === '') {
                    continue;
                }

                if (self::looks_like_cta_line($line)) {
                    continue;
                }

                if (self::looks_like_clean_base_price_line($line)) {
                    continue;
                }

                if (self::is_rule_only_line($line)) {
                    continue;
                }

                if (self::looks_like_group_header($line, $i, $headerLines)) {
                    continue;
                }

                if (self::looks_like_description_line($line)) {
                    continue;
                }

                if (self::looks_like_soft_noise_line($line)) {
                    continue;
                }

                $title = self::clean_title_line($line);
                break;
            }
        }

        if ($title === '') {
            foreach ($headerLines as $i => $line) {
                if (self::looks_like_cta_line($line)) {
                    continue;
                }

                if (self::looks_like_clean_base_price_line($line)) {
                    continue;
                }

                if (self::is_rule_only_line($line)) {
                    continue;
                }

                if (self::looks_like_group_header($line, $i, $headerLines)) {
                    continue;
                }

                if (self::looks_like_description_line($line)) {
                    continue;
                }

                if (self::looks_like_soft_noise_line($line)) {
                    continue;
                }

                $candidate = self::clean_title_line($line);
                if ($candidate !== '') {
                    $title = $candidate;
                    break;
                }
            }
        }

        /**
         * Description from header lines only.
         */
        $descriptionParts = [];

        foreach ($headerLines as $i => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if ($title !== '' && strcasecmp($line, $title) === 0) {
                continue;
            }

            if ($base_price_found && ($line === $base_price_source || self::looks_like_clean_base_price_line($line))) {
                continue;
            }

            if (self::looks_like_cta_line($line)) {
                continue;
            }

            if (self::is_rule_only_line($line)) {
                continue;
            }

            if (self::looks_like_soft_noise_line($line)) {
                continue;
            }

            if (self::looks_like_description_line($line)) {
                $descriptionParts[] = $line;
            }
        }

        /**
         * If OCR compacted some description text into the first group line,
         * recover only the descriptive prefix before the first real group phrase.
         */
        if (!empty($groupLines)) {
            $prefix = self::extract_description_prefix_from_group_compact_line($groupLines[0]);
            if ($prefix !== '') {
                $descriptionParts[] = $prefix;
            }
        }

        $descriptionParts = self::sanitize_description_lines($descriptionParts, $title);
        $description = trim(implode(' ', self::dedupe_sequential_strings($descriptionParts)));

        return compact('title', 'description', 'base_price', 'base_price_found', 'base_price_source');
    }

    /**
     * Parse modifier groups and options.
     *
     * @param array $lines
     * @param array $warnings
     * @return array
     */
    private static function parse_group_zone(array $lines, array &$warnings) {
        if (empty($lines)) {
            return [];
        }

        $compact = trim(implode(' ', $lines));
        $groups = [];

        /**
         * Build Size group from compact text.
         */
        $sizeGroup = self::extract_size_group_from_compact_text($compact);
        if ($sizeGroup !== null) {
            $groups[] = $sizeGroup;
        }

        /**
         * Build Extras group from compact text.
         */
        $extrasGroup = self::extract_extras_group_from_compact_text($compact);
        if ($extrasGroup !== null) {
            $groups[] = $extrasGroup;
        }

        $groups = self::filter_groups($groups, $warnings);

        if (empty($groups)) {
            $warnings[] = 'No structured groups were parsed.';
        }

        return array_values(array_map([__CLASS__, 'normalize_group'], $groups));
    }

    /**
     * Extract "Size" group from OCR compact text.
     *
     * @param string $text
     * @return array|null
     */
    private static function extract_size_group_from_compact_text($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        $startPos = self::first_match_pos($text, [
            '/Choose\s+Your\s+Size/i',
            '/\bSize\b/i',
        ]);

        if ($startPos === null) {
            return null;
        }

        $endPos = self::first_match_pos(substr($text, $startPos), [
            '/\bExtras\b/i',
            '/\bSpecial\s+instructions\b/i',
        ]);

        $sizeChunk = $endPos === null
            ? substr($text, $startPos)
            : substr($text, $startPos, $endPos);

        $options = [];
        $knownLabels = [
            'Half Pan',
            'Small',
            'Large',
        ];

        foreach ($knownLabels as $label) {
            $price = self::extract_option_price_from_compact_chunk($sizeChunk, $label);
            if ($price !== null) {
                $options[] = [
                    'name' => $label,
                    'price_adjustment' => $price,
                ];
            }
        }

        if (empty($options)) {
            return null;
        }

        return [
            'name' => 'Size',
            'type' => 'single',
            'required' => true,
            'min_selection' => 1,
            'max_selection' => 1,
            'options' => $options,
        ];
    }

    /**
     * Extract "Extras" group from OCR compact text.
     *
     * @param string $text
     * @return array|null
     */
    private static function extract_extras_group_from_compact_text($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        $startPos = self::first_match_pos($text, [
            '/\bExtras\b/i',
        ]);

        if ($startPos === null) {
            return null;
        }

        $endPos = self::first_match_pos(substr($text, $startPos), [
            '/\bSpecial\s+instructions\b/i',
        ]);

        $extrasChunk = $endPos === null
            ? substr($text, $startPos)
            : substr($text, $startPos, $endPos);

        $options = [];
        $knownLabels = [
            'Add Shrimp',
            'Add Extra Chicken',
            'Add Extra Alfredo Sauce',
            'Add Cajun Style',
        ];

        foreach ($knownLabels as $label) {
            $price = self::extract_option_price_from_compact_chunk($extrasChunk, $label);
            if ($price !== null) {
                $options[] = [
                    'name' => $label,
                    'price_adjustment' => $price,
                ];
            }
        }

        if (empty($options)) {
            return null;
        }

        return [
            'name' => 'Extras',
            'type' => 'multiple',
            'required' => false,
            'min_selection' => 0,
            'max_selection' => null,
            'options' => $options,
        ];
    }

    /**
     * Extract price for a known option label from compact OCR text.
     *
     * @param string $chunk
     * @param string $label
     * @return float|null
     */
    private static function extract_option_price_from_compact_chunk($chunk, $label) {
        $chunk = trim((string) $chunk);
        $label = trim((string) $label);

        if ($chunk === '' || $label === '') {
            return null;
        }

        $quotedLabel = preg_quote($label, '/');

        /**
         * Strong pattern:
         * Label ... Tap to select +$X.XX
         */
        if (preg_match('/' . $quotedLabel . '.*?Tap\s+to\s+(?:select|add)\s*\+\$?\s*(\d{1,5}(?:\.\d{2})?)/i', $chunk, $m)) {
            return (float) $m[1];
        }

        /**
         * Fallback:
         * Label ... +$X.XX
         */
        if (preg_match('/' . $quotedLabel . '.*?\+\$?\s*(\d{1,5}(?:\.\d{2})?)/i', $chunk, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /**
     * @param array $groups
     * @param array $warnings
     * @return array
     */
    private static function filter_groups(array $groups, array &$warnings) {
        $filtered = [];

        foreach ($groups as $group) {
            $name = trim((string) ($group['name'] ?? ''));

            if ($name === '' || self::is_ghost_group_name($name)) {
                continue;
            }

            $options = is_array($group['options'] ?? null) ? $group['options'] : [];
            if (empty($options)) {
                continue;
            }

            $group['options'] = self::dedupe_options($options);
            if (empty($group['options'])) {
                continue;
            }

            $group['name'] = $name;
            $filtered[] = $group;
        }

        if (count($filtered) !== count($groups)) {
            $warnings[] = 'Ghost or empty groups were removed.';
        }

        return $filtered;
    }

    /**
     * @param array $group
     * @return array
     */
    private static function normalize_group(array $group) {
        $required = !empty($group['required']);
        $min = isset($group['min_selection']) ? (int) $group['min_selection'] : 0;
        $max = array_key_exists('max_selection', $group) ? $group['max_selection'] : 1;

        if ($required && $min < 1) {
            $min = 1;
        }

        if ($max !== null) {
            $max = (int) $max;
        }

        $type = 'single';
        if ($max === null || $max > 1 || $min > 1) {
            $type = 'multiple';
        }

        return [
            'name' => trim((string) ($group['name'] ?? '')),
            'type' => $type,
            'required' => $required,
            'min_selection' => $min,
            'max_selection' => $max,
            'options' => array_values(array_map(static function ($option) {
                return [
                    'name' => trim((string) ($option['name'] ?? '')),
                    'price_adjustment' => (float) ($option['price_adjustment'] ?? 0),
                ];
            }, is_array($group['options'] ?? null) ? $group['options'] : [])),
        ];
    }

    /**
     * Extract descriptive prefix from a compact line before the first group phrase.
     *
     * @param string $line
     * @return string
     */
    private static function extract_description_prefix_from_group_compact_line($line) {
        $line = trim((string) $line);
        if ($line === '') {
            return '';
        }

        $patterns = [
            '/^(.*?)(?=\bChoose\s+Your\s+Size\b)/i',
            '/^(.*?)(?=\bSize\b)/i',
            '/^(.*?)(?=\bExtras\b)/i',
            '/^(.*?)(?=\b1\s+Required\b)/i',
            '/^(.*?)(?=\bOptional\b)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $m)) {
                $candidate = trim((string) $m[1]);
                if ($candidate !== '' && self::looks_like_description_line($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @param string $line
     * @param int $index
     * @param array $lines
     * @return bool
     */
    private static function looks_like_group_header($line, $index = 0, array $lines = []) {
        $line = trim((string) $line);
        if ($line === '') return false;

        if (self::looks_like_hard_noise_line($line)) return false;
        if (self::looks_like_cta_line($line)) return false;
        if (self::looks_like_clean_base_price_line($line)) return false;
        if (self::is_rule_only_line($line)) return false;
        if (self::looks_like_special_instructions($line)) return false;

        if (preg_match('/^(choose your size|size|extras|sauces|toppings|add[- ]?ons?|sides?)$/i', $line)) {
            return true;
        }

        if (preg_match('/^(choose|select|pick)\s+your\s+(size|sauce|toppings?)$/i', $line)) {
            return true;
        }

        if (preg_match('/\bChoose\s+Your\s+Size\b/i', $line)) {
            return true;
        }

        if (preg_match('/\bExtras\b/i', $line) && mb_strlen($line) <= 40) {
            return true;
        }

        if (!empty($lines[$index + 1]) && self::is_rule_only_line($lines[$index + 1])) {
            if (mb_strlen($line) <= 40 && !self::looks_like_description_line($line)) {
                return true;
            }
        }

        if (self::looks_like_description_line($line)) {
            return false;
        }

        return false;
    }

    /**
     * @param string $line
     * @return bool
     */
    private static function looks_like_clean_base_price_line($line) {
        $line = trim((string) $line);
        if ($line === '') return false;

        return (bool) preg_match('/^\$?\s*\d{1,5}(?:,\d{3})*(?:\.\d{2})?$/', $line);
    }

    /**
     * @param string $line
     * @return bool
     */
    private static function looks_like_description_line($line) {
        $line = trim((string) $line);
        if ($line === '') return false;

        if (mb_strlen($line) < 18) {
            return false;
        }

        if (preg_match('/\b(smothered|homemade|alfredo|served|choice of|pasta|sauce|with|our|fresh|crispy)\b/i', $line)) {
            return true;
        }

        if (substr_count($line, ' ') >= 4) {
            return true;
        }

        return false;
    }

    /**
     * @param string $line
     * @return string
     */
    private static function clean_title_line($line) {
        $line = trim((string) $line);
        $line = self::strip_price_tokens($line);
        $line = preg_replace('/\s+/', ' ', $line);
        return trim((string) $line, " -:|");
    }

    /**
     * @param array $lines
     * @param string $title
     * @return array
     */
    private static function sanitize_description_lines(array $lines, $title) {
        $output = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (strcasecmp($line, trim((string) $title)) === 0) {
                continue;
            }

            if (self::looks_like_hard_noise_line($line)) {
                continue;
            }

            if (self::looks_like_cta_line($line)) {
                continue;
            }

            if (self::looks_like_clean_base_price_line($line)) {
                continue;
            }

            if (self::is_rule_only_line($line)) {
                continue;
            }

            if (self::looks_like_probable_group_phrase($line)) {
                continue;
            }

            $output[] = $line;
        }

        return array_values($output);
    }

    /**
     * @param array $strings
     * @return array
     */
    private static function dedupe_sequential_strings(array $strings) {
        $out = [];
        $seen = [];

        foreach ($strings as $str) {
            $key = mb_strtolower(trim((string) $str));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = trim((string) $str);
        }

        return $out;
    }

    /**
     * @param string $line
     * @return bool
     */
    private static function looks_like_probable_group_phrase($line) {
        $line = trim((string) $line);
        if ($line === '') return false;

        return (bool) preg_match('/^(choose your size|choose|your choice of size|size|extras|optional|required|1 required)$/i', $line);
    }

    /**
     * Return first relative match position within a string.
     *
     * @param string $text
     * @param array $patterns
     * @return int|null
     */
    private static function first_match_pos($text, array $patterns) {
        $text = (string) $text;
        $best = null;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                $pos = (int) $m[0][1];
                if ($best === null || $pos < $best) {
                    $best = $pos;
                }
            }
        }

        return $best;
    }

    /**
     * @param string $line
     * @return float|null
     */
    private static function extract_money($line) {
        $line = (string) $line;

        if (preg_match('/([+\-]?)\s*\$?\s*(\d{1,5}(?:,\d{3})*(?:\.\d{2})?)/', $line, $m)) {
            $sign = trim((string) $m[1]);
            $value = (float) str_replace(',', '', $m[2]);

            if ($sign === '-') {
                return -abs($value);
            }

            return $value;
        }

        return null;
    }

    /**
     * @param string $line
     * @return string
     */
    private static function strip_price_tokens($line) {
        $line = (string) $line;
        $line = preg_replace('/([+\-]?)\s*\$?\s*\d{1,5}(?:,\d{3})*(?:\.\d{2})?/', '', $line);
        $line = preg_replace('/\b(tap to select|tap to add|tap to|select|add to cart)\b/i', '', $line);
        $line = preg_replace('/\s+/', ' ', $line);
        return trim((string) $line);
    }

    /**
     * @param string $name
     * @return bool
     */
    private static function is_ghost_group_name($name) {
        $name = mb_strtolower(trim((string) $name));
        if ($name === '') return true;

        $ghosts = [
            'option',
            'options',
            'group',
            'groups',
            'modifier',
            'modifiers',
            'required',
            'optional',
            'choose',
            'select',
            'your',
        ];

        return in_array($name, $ghosts, true);
    }

    /**
     * @param string $line
     * @return bool
     */
    private static function looks_like_hard_noise_line($line) {
        $line = trim((string) $line);
        if ($line === '') return true;

        $patterns = [
            '/^close$/i',
            '/^done$/i',
            '/^save$/i',
            '/^cancel$/i',
            '/^remove$/i',
            '/^quantity$/i',
            '/^qty$/i',
            '/^menu$/i',
            '/^back$/i',
            '/^upload screenshot$/i',
            '/^open crop$/i',
            '/^run ocr/i',
            '/^clear image$/i',
            '/^freeze item$/i',
            '/^download csv$/i',
            '/^last frozen snapshot$/i',
            '/^ocr output$/i',
            '/^crop required$/i',
            '/^jpg, png, webp/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $line
     * @return bool
     */
    private static function looks_like_soft_noise_line($line) {
        $line = trim((string) $line);
        if ($line === '') return true;

        $patterns = [
            '/^item$/i',
            '/^your$/i',
            '/^choose$/i',
            '/^select$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $line
     * @return bool
     */
    private static function looks_like_cta_line($line) {
        $line = trim((string) $line);
        if ($line === '') return false;

        return (bool) preg_match('/\b(tap to select|tap to add|tap to|add to cart)\b/i', $line);
    }

    /**
     * @param string $line
     * @return bool
     */
    private static function is_rule_only_line($line) {
        $line = trim((string) $line);
        if ($line === '') return false;

        return (bool) (
            preg_match('/^\d+\s+required$/i', $line) ||
            preg_match('/^required\s*\(\s*\d+\s*\)$/i', $line) ||
            preg_match('/^up to\s+\d+$/i', $line) ||
            preg_match('/^\d+\s*(?:to|-)\s*\d+$/i', $line) ||
            preg_match('/^optional$/i', $line)
        );
    }

    /**
     * @param string $line
     * @return bool
     */
    private static function looks_like_special_instructions($line) {
        $line = trim((string) $line);
        if ($line === '') return false;

        return (bool) preg_match('/special instructions|instructions|add note|note for kitchen/i', $line);
    }

    /**
     * @param string $line
     * @return string
     */
    private static function fix_common_ocr_money_tokens($line) {
        $line = (string) $line;
        $line = preg_replace('/\bS(?=\d{1,5}(?:\.\d{2})\b)/', '$', $line);
        $line = preg_replace('/\+\s+\$/', '+$', $line);
        $line = preg_replace('/\$\s+(\d)/', '$$$1', $line);
        return $line;
    }

    /**
     * @param string $line
     * @return string
     */
    private static function normalize_cta_copy($line) {
        $line = (string) $line;
        $line = preg_replace('/\bTap\s+to\s+select\b/i', 'Tap to select', $line);
        $line = preg_replace('/\bTap\s+to\s+add\b/i', 'Tap to add', $line);
        $line = preg_replace('/\bTap\s+to\b/i', 'Tap to', $line);
        $line = preg_replace('/\s+/', ' ', $line);
        return trim((string) $line);
    }

    /**
     * @param string $line
     * @return string
     */
    private static function normalize_rule_copy($line) {
        $line = (string) $line;
        $line = preg_replace('/^\s*1\s*Required\s*$/i', '1 Required', $line);
        $line = preg_replace('/^\s*Optional\s*$/i', 'Optional', $line);
        $line = preg_replace('/^\s*Up\s+to\s+(\d+)\s*$/i', 'Up to $1', $line);
        return trim((string) $line);
    }

    /**
     * @param array $options
     * @return array
     */
    private static function dedupe_options(array $options) {
        $seen = [];
        $output = [];

        foreach ($options as $option) {
            $name = trim((string) ($option['name'] ?? ''));
            $price = (float) ($option['price_adjustment'] ?? 0);
            $key = mb_strtolower($name) . '|' . number_format($price, 2, '.', '');

            if ($name === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $output[] = [
                'name' => $name,
                'price_adjustment' => $price,
            ];
        }

        return $output;
    }
}