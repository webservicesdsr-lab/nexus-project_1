<?php
// File: inc/shortcodes/cities-grid-shortcode.php

/**
 * Kingdom Nexus - Cities Grid Shortcode (Frontend, Safe) — v4.1 (SSOT DB Theme)
 * Usage: [knx_cities_grid]
 *
 * - Renders ACTIVE cities with count of ACTIVE hubs
 * - Each card is a single <a> → /explore-hubs/?location=City%2C%20State
 * - Applies GLOBAL City Grid Theme (SSOT) from DB singleton: {prefix}knx_city_branding (id=1)
 * - Uses SSOT CSS: inc/public/branding/knx-city-grid.css
 * - No wp_enqueue. Assets injected via echo.
 */

if (!defined('ABSPATH')) exit;

add_shortcode('knx_cities_grid', 'knx_cities_grid_shortcode');

/**
 * Get global city grid theme from DB (singleton).
 */
function knx_city_grid_theme_get_global() {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_city_branding';

    $defaults = [
        'gradient' => ['from' => '#FF7A00', 'to' => '#FFB100', 'angle' => 180],
        'card' => ['radius' => 18, 'minHeight' => 240, 'paddingY' => 35, 'paddingX' => 20, 'shadow' => true],
        'title' => [
            'fontFamily' => 'system',
            'fontWeight' => 800,
            'fontSize' => 20,
            'lineHeight' => 1.00,
            'letterSpacing' => 1.00,
            'fill' => '#FFFFFF',
            'strokeColor' => '#083B58',
            'strokeWidth' => 0
        ],
        'cta' => [
            'text' => 'Tap to EXPLORE HUBS',
            'twoLines' => false,
            'bg' => '#083B58',
            'textColor' => '#FFFFFF',
            'radius' => 999,
            'borderDotted' => false,
            'borderColor' => '#FFFFFF',
            'borderWidth' => 2,
            'paddingY' => 14,
            'paddingX' => 26,
            'fontSize' => 18,
            'fontWeight' => 800
        ],
    ];

    // Fail-closed: if table missing or query fails, return defaults.
    $row = $wpdb->get_row("SELECT * FROM {$table} WHERE id = 1 LIMIT 1", ARRAY_A);
    if (!is_array($row)) return $defaults;

    // Helpers
    $hex = function($v, $fallback){
        $s = is_string($v) ? strtoupper(trim($v)) : '';
        if ($s === '') return $fallback;
        if ($s[0] !== '#') $s = '#' . ltrim($s, '#');
        $s = substr($s, 0, 7);
        return preg_match('/^#[0-9A-F]{6}$/', $s) ? $s : $fallback;
    };
    $int = function($v, $min, $max, $fallback){
        $n = is_numeric($v) ? (int)$v : (int)$fallback;
        return max($min, min($max, $n));
    };
    $float = function($v, $min, $max, $fallback){
        $n = is_numeric($v) ? (float)$v : (float)$fallback;
        return max($min, min($max, $n));
    };
    $bool = function($v, $fallback){
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) === 1;
        if (is_string($v)) {
            $x = strtolower(trim($v));
            if (in_array($x, ['1','true','yes','on'], true)) return true;
            if (in_array($x, ['0','false','no','off'], true)) return false;
        }
        return (bool)$fallback;
    };

    $theme = $defaults;

    $theme['gradient']['from']  = $hex($row['gradient_from'] ?? null, $defaults['gradient']['from']);
    $theme['gradient']['to']    = $hex($row['gradient_to'] ?? null, $defaults['gradient']['to']);
    $theme['gradient']['angle'] = $int($row['gradient_angle'] ?? null, 0, 360, $defaults['gradient']['angle']);

    $theme['title']['fontSize']      = $int($row['title_font_size'] ?? null, 20, 76, $defaults['title']['fontSize']);
    $theme['title']['fill']          = $hex($row['title_fill_color'] ?? null, $defaults['title']['fill']);
    $theme['title']['strokeColor']   = $hex($row['title_stroke_color'] ?? null, $defaults['title']['strokeColor']);
    $theme['title']['strokeWidth']   = $int($row['title_stroke_width'] ?? null, 0, 14, $defaults['title']['strokeWidth']);
    $theme['title']['fontWeight']    = $int($row['title_font_weight'] ?? null, 400, 950, $defaults['title']['fontWeight']);
    $theme['title']['lineHeight']    = $float($row['title_line_height'] ?? null, 0.8, 1.6, $defaults['title']['lineHeight']);
    $theme['title']['letterSpacing'] = $float($row['title_letter_spacing'] ?? null, -10.0, 10.0, $defaults['title']['letterSpacing']);

    $theme['cta']['bg']          = $hex($row['cta_bg'] ?? null, $defaults['cta']['bg']);
    $theme['cta']['textColor']   = $hex($row['cta_text_color'] ?? null, $defaults['cta']['textColor']);
    $theme['cta']['radius']      = $int($row['cta_radius'] ?? null, 0, 999, $defaults['cta']['radius']);
    $theme['cta']['borderColor'] = $hex($row['cta_border_color'] ?? null, $defaults['cta']['borderColor']);
    $theme['cta']['borderWidth'] = $int($row['cta_border_width'] ?? null, 0, 16, $defaults['cta']['borderWidth']);
    $theme['cta']['borderDotted']= $bool($row['cta_border_dotted'] ?? null, $defaults['cta']['borderDotted']);
    $theme['cta']['twoLines']    = $bool($row['cta_two_lines'] ?? null, $defaults['cta']['twoLines']);

    $theme['card']['radius']    = $int($row['card_radius'] ?? null, 0, 64, $defaults['card']['radius']);
    $theme['card']['paddingY']  = $int($row['card_padding_y'] ?? null, 0, 90, $defaults['card']['paddingY']);
    $theme['card']['paddingX']  = $int($row['card_padding_x'] ?? null, 0, 90, $defaults['card']['paddingX']);
    $theme['card']['minHeight'] = $int($row['card_min_height'] ?? null, 120, 900, $defaults['card']['minHeight']);
    $theme['card']['shadow']    = $bool($row['card_shadow'] ?? null, $defaults['card']['shadow']);

    return $theme;
}

function knx_cities_grid_shortcode() {
    global $wpdb;

    $cities_table = $wpdb->prefix . 'knx_cities';
    $hubs_table   = $wpdb->prefix . 'knx_hubs';

    $sql = $wpdb->prepare(
        "
        SELECT 
            c.id,
            c.name,
            c.state,
            COUNT(h.id) AS hub_count
        FROM {$cities_table} c
        LEFT JOIN {$hubs_table} h 
            ON h.city_id = c.id AND h.status = %s
        WHERE c.status = %s
        GROUP BY c.id, c.name, c.state
        ORDER BY hub_count DESC, c.name ASC
        LIMIT 8
        ",
        'active', 'active'
    );

    $cities = $wpdb->get_results($sql);

    // ---- Load global theme (SSOT DB singleton) ----
    $theme = knx_city_grid_theme_get_global();

    // Include SSOT CSS (echo, not enqueue)
    $ver = defined('KNX_VERSION') ? KNX_VERSION : time();
    $theme_css = defined('KNX_URL') ? (KNX_URL . 'inc/public/branding/knx-city-grid.css?v=' . rawurlencode($ver)) : '';

    ob_start();

    if ($theme_css) {
        echo '<link rel="stylesheet" href="' . esc_url($theme_css) . '">';
    }

    if (empty($cities)) {
        ?>
        <div class="knx-cities-empty">
            <i class="fas fa-map-marked-alt"></i>
            <h3>Oops! Nothing found here!</h3>
            <p>We'll be back soon with more locations. Stay tuned! 🚀</p>
        </div>
        <?php
        return ob_get_clean();
    }

    $explore_url = home_url('/explore-hubs/');

    // Apply global theme vars on a single wrapper (SSOT)
    $wrap_style = implode('; ', [
        '--knx-city-grad-from: ' . esc_attr($theme['gradient']['from']),
        '--knx-city-grad-to: ' . esc_attr($theme['gradient']['to']),
        '--knx-city-grad-angle: ' . esc_attr((int)$theme['gradient']['angle']) . 'deg',

        '--knx-city-card-radius: ' . esc_attr((int)$theme['card']['radius']) . 'px',
        '--knx-city-card-min-height: ' . esc_attr((int)$theme['card']['minHeight']) . 'px',
        '--knx-city-card-padding-y: ' . esc_attr((int)$theme['card']['paddingY']) . 'px',
        '--knx-city-card-padding-x: ' . esc_attr((int)$theme['card']['paddingX']) . 'px',
        '--knx-city-card-shadow: ' . (!empty($theme['card']['shadow']) ? '1' : '0'),

        '--knx-city-title-font-size: ' . esc_attr((int)$theme['title']['fontSize']) . 'px',
        '--knx-city-title-font-weight: ' . esc_attr((int)$theme['title']['fontWeight']),
        '--knx-city-title-line-height: ' . esc_attr((float)$theme['title']['lineHeight']),
        '--knx-city-title-letter-spacing: ' . esc_attr((float)$theme['title']['letterSpacing']) . 'px',
        '--knx-city-title-fill: ' . esc_attr($theme['title']['fill']),
        '--knx-city-title-stroke-color: ' . esc_attr($theme['title']['strokeColor']),
        '--knx-city-title-stroke-width: ' . esc_attr((int)$theme['title']['strokeWidth']) . 'px',

        '--knx-city-cta-bg: ' . esc_attr($theme['cta']['bg']),
        '--knx-city-cta-text: ' . esc_attr($theme['cta']['textColor']),
        '--knx-city-cta-radius: ' . esc_attr((int)$theme['cta']['radius']) . 'px',
        '--knx-city-cta-border-color: ' . esc_attr($theme['cta']['borderColor']),
        '--knx-city-cta-border-width: ' . esc_attr((int)$theme['cta']['borderWidth']) . 'px',
        '--knx-city-cta-padding-y: ' . esc_attr((int)$theme['cta']['paddingY']) . 'px',
        '--knx-city-cta-padding-x: ' . esc_attr((int)$theme['cta']['paddingX']) . 'px',
        '--knx-city-cta-font-size: ' . esc_attr((int)$theme['cta']['fontSize']) . 'px',
        '--knx-city-cta-font-weight: ' . esc_attr((int)$theme['cta']['fontWeight']),
        '--knx-city-cta-dotted: ' . (!empty($theme['cta']['borderDotted']) ? '1' : '0'),
        '--knx-city-cta-two-lines: ' . (!empty($theme['cta']['twoLines']) ? '1' : '0'),
    ]);

    $ctaTwoLines = !empty($theme['cta']['twoLines']);
    $ctaDotted   = !empty($theme['cta']['borderDotted']);
    $ctaTextStr  = is_string(($theme['cta']['text'] ?? null)) ? sanitize_text_field($theme['cta']['text']) : 'Tap to EXPLORE HUBS';
    ?>
    <div class="knx-city-grid-wrap" style="<?php echo esc_attr($wrap_style); ?>">
        <div class="knx-cities-grid">
            <?php foreach ($cities as $c):
                $name  = isset($c->name)  ? sanitize_text_field($c->name)  : '';
                $state = isset($c->state) ? sanitize_text_field($c->state) : '';
                $display_name = trim($name . ($state ? ', ' . $state : ''));

                $city_url = add_query_arg(['location' => $display_name], $explore_url);
                ?>
                <a class="knx-city-banner-card" href="<?php echo esc_url($city_url); ?>">
                    <div class="knx-city-banner-inner">
                        <div class="knx-city-banner-title"><?php echo esc_html($display_name); ?></div>

                        <?php if ($ctaTwoLines): ?>
                            <div class="knx-city-banner-cta" data-two-lines="1" data-dotted="<?php echo $ctaDotted ? '1' : '0'; ?>">
                                <span class="knx-city-cta-line">Tap to</span>
                                <span class="knx-city-cta-line">EXPLORE HUBS</span>
                            </div>
                        <?php else: ?>
                            <div class="knx-city-banner-cta" data-two-lines="0" data-dotted="<?php echo $ctaDotted ? '1' : '0'; ?>">
                                <?php echo esc_html($ctaTextStr); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php

    return ob_get_clean();
}