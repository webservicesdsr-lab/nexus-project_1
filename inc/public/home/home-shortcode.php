<?php
/**
 * Kingdom Nexus - Home shortcode (landing page)
 * - Renderiza buscador + ciudades (server-side)
 * - Autocomplete por Nominatim en JS
 * - Cards redirigen a /explore-hubs/?location=City%2C%20State
 */
if (!defined('ABSPATH')) exit;

function knx_home_shortcode() {
    // Enqueue estilos/scripts de Home
    wp_enqueue_style('knx-home', KNX_URL . 'inc/public/home/home.css', [], KNX_VERSION);
    wp_enqueue_script('knx-home', KNX_URL . 'inc/public/home/home.js', [], KNX_VERSION, true);

    // (Opcional) Módulos de localización si los usas en otras partes
    wp_enqueue_style('knx-location-modal', KNX_URL . 'inc/modules/home/knx-location-modal.css', [], KNX_VERSION);
    wp_enqueue_script('knx-location-detector', KNX_URL . 'inc/modules/home/knx-location-detector.js', [], KNX_VERSION, true);

    // Pasamos la URL base de explore y (si quieres usarlo) endpoint de búsqueda
    wp_localize_script('knx-home', 'knxHome', [
        'exploreUrl' => home_url('/explore-hubs/'),
        // 'restUrl' => rest_url('knx/v1/location-search'), // si lo usas después
    ]);

    // (Opcional) saludo por sesión
    $first_name = '';
    if (isset($_SESSION['knx_user_id'])) {
        global $wpdb;
        $uid = intval($_SESSION['knx_user_id']);
        $row = $wpdb->get_row($wpdb->prepare("SELECT first_name FROM {$wpdb->prefix}knx_users WHERE id = %d", $uid));
        if ($row && !empty($row->first_name)) {
            $first_name = esc_html($row->first_name);
        }
    }

    ob_start();
?>
<div class="knx-hero">
    <h2>
        <?php if ($first_name): ?>
            Welcome back, <?php echo $first_name; ?>! 🎉<br>
        <?php endif; ?>
        Every order you place with us makes a meaningful contribution to supporting the Center of Hope Food Pantry in Kankakee.
    </h2>
    <p>Enter your address to explore hubs near you</p>

    <div class="knx-searchbox">
        <div class="knx-search-wrapper">
            <input 
                type="text" 
                id="knx-address-input"
                placeholder="Enter your city or address"
                autocomplete="off"
            />
            <div id="knx-autocomplete-dropdown" class="knx-autocomplete-dropdown"></div>
        </div>

        <button type="button" id="knx-search-btn">Find Restaurants</button>

        <button type="button" id="knx-detect-location" class="knx-detect-btn">
            📍Detect My Location
        </button>
    </div>

    <div id="knx-geolocation-status" class="knx-status-message" aria-live="polite"></div>

    <div class="knx-cities-section">
        <h3 class="knx-cities-title">Cities</h3>
        <?php echo do_shortcode('[knx_cities_grid]'); ?>
    </div>
</div>
<?php
    return ob_get_clean();
}
add_shortcode('knx_home', 'knx_home_shortcode');
