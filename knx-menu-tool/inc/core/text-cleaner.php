<?php
if (!defined('ABSPATH')) exit;

final class KNX_Menu_Tool_Text_Cleaner {

    /**
     * Clean OCR text conservatively, with stronger modal-oriented normalization.
     *
     * @param string $text
     * @param string $profile
     * @return string
     */
    public static function clean($text, $profile = 'default') {
        $text = (string) $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $lines = explode("\n", $text);
        $output = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/\s+/', ' ', $line);

            if ($profile === 'web_item') {
                $line = self::clean_web_item_line($line);
            }

            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $output[] = $line;
        }

        return implode("\n", $output);
    }

    /**
     * @param string $line
     * @return string
     */
    private static function clean_web_item_line($line) {
        $line = (string) $line;

        $line = str_replace(['•', '·', '|'], ' ', $line);
        $line = preg_replace('/\s+/', ' ', $line);

        /**
         * Common OCR money fixes.
         */
        $line = preg_replace('/\bS(?=\d{1,5}(?:\.\d{2})\b)/', '$', $line);
        $line = preg_replace('/\+\s+\$/', '+$', $line);
        $line = preg_replace('/\$\s+(\d)/', '$$$1', $line);

        /**
         * Normalize CTA phrases.
         */
        $line = preg_replace('/\bTap\s+to\s+select\b/i', 'Tap to select', $line);
        $line = preg_replace('/\bTap\s+to\s+add\b/i', 'Tap to add', $line);
        $line = preg_replace('/\bTap\s+to\b/i', 'Tap to', $line);

        /**
         * Normalize common rule phrases.
         */
        $line = preg_replace('/^\s*1\s*Required\s*$/i', '1 Required', $line);
        $line = preg_replace('/^\s*Optional\s*$/i', 'Optional', $line);
        $line = preg_replace('/^\s*Up\s+to\s+(\d+)\s*$/i', 'Up to $1', $line);

        /**
         * Normalize common group names.
         */
        $line = preg_replace('/\bChoose\s+Your\s+Size\b/i', 'Choose Your Size', $line);
        $line = preg_replace('/\bAdd\s+Extra\s+Alfredo\s+Sauce\b/i', 'Add Extra Alfredo Sauce', $line);
        $line = preg_replace('/\bAdd\s+Extra\s+Chicken\b/i', 'Add Extra Chicken', $line);
        $line = preg_replace('/\bAdd\s+Cajun\s+Style\b/i', 'Add Cajun Style', $line);

        $line = preg_replace('/\s+/', ' ', $line);
        $line = trim((string) $line, " \t\n\r\0\x0B-");

        return $line;
    }
}