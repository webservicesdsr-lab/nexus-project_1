<?php
/**
 * Kingdom Nexus - Cities Grid Shortcode (Frontend, Safe)
 * Usage: [knx_cities_grid]
 * - Renderiza ciudades ACTIVAS con conteo de hubs ACTIVOS
 * - Cada tarjeta es un <a> único → /explore-hubs/?location=City%2C%20State
 * - Sin inyección SQL (sin input de usuario)
 */

if (!defined('ABSPATH')) exit;

add_shortcode('knx_cities_grid', 'knx_cities_grid_shortcode');

function knx_cities_grid_shortcode() {
    global $wpdb;

    $cities_table = $wpdb->prefix . 'knx_cities';
    $hubs_table   = $wpdb->prefix . 'knx_hubs';

    // Solo datos “activos”; sin input de usuario → seguro, pero mantenemos prepare por consistencia
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

    ob_start();

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

    $icons = ['fa-city','fa-building','fa-landmark','fa-store','fa-home','fa-map-marker-alt','fa-location-dot','fa-map-pin'];
    $explore_url = home_url('/explore-hubs/'); // /explore-hubs/?location=...

    ?>
    <div class="knx-cities-grid">
        <?php foreach ($cities as $i => $c):
            $icon = $icons[$i % count($icons)];

            $name  = isset($c->name)  ? sanitize_text_field($c->name)  : '';
            $state = isset($c->state) ? sanitize_text_field($c->state) : '';

            // Texto para el usuario y para el query param
            $display_name = trim($name . ( $state ? ', ' . $state : '' ));

            // Construimos URL segura con add_query_arg (WP hace urlencode)
            $city_url = add_query_arg(
                ['location' => $display_name],
                $explore_url
            );

            $hub_count = absint($c->hub_count);
            $hub_text = ($hub_count === 1) ? '1 hub' : ($hub_count . ' hubs');
        ?>
        <a class="knx-city-card" href="<?php echo esc_url($city_url); ?>">
            <div class="knx-city-icon">
                <i class="fas <?php echo esc_attr($icon); ?>"></i>
            </div>
            <div class="knx-city-info">
                <h4 class="knx-city-name"><?php echo esc_html($display_name); ?></h4>
                <div class="knx-city-meta">
                    <span class="knx-hub-count">
                        <i class="fas fa-store"></i> <?php echo esc_html($hub_text); ?>
                    </span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php

    return ob_get_clean();
}
