<?php
if (!defined('ABSPATH')) exit;

/**
 * Shortcode: [knx_dispatcher]
 * Renders the dispatcher UI shell (frontend-only prototype).
 * This shortcode intentionally does NOT depend on any backend API yet.
 */
function knx_render_dispatcher_shortcode($atts = []) {

    // Attributes and defaults
    $atts = shortcode_atts([
        'poll_interval' => '10000',
        'page_size'     => '20',
        'role'          => 'none', // prototype only
        'city'          => '',
    ], $atts, 'knx_dispatcher');

    ob_start();

    // Inline include of CSS/JS — follow existing project pattern (no wp_enqueue)
    echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'public/css/dispatcher.css') . '">';

    // Root container with data attributes for future wiring
    echo '<div id="knx-dispatcher" class="knx-dispatcher-shell"'
        . ' data-poll-interval="' . esc_attr($atts['poll_interval']) . '"'
        . ' data-page-size="' . esc_attr($atts['page_size']) . '"'
        . ' data-role="' . esc_attr($atts['role']) . '"'
        . ' data-city="' . esc_attr($atts['city']) . '">';

    // Include template markup
    $template = __DIR__ . '/templates/dispatcher-shell.php';
    if (file_exists($template)) {
        require $template;
    } else {
        echo '<p>Dispatcher template missing.</p>';
    }

    echo '</div>';

    // Load mock adapter and UI script (no-dependency)
    echo '<script src="' . esc_url(KNX_URL . 'public/js/mocks/knx_dispatcher_mock.js') . '"></script>';
    echo '<script src="' . esc_url(KNX_URL . 'public/js/dispatcher.js') . '"></script>';

    return ob_get_clean();
}

add_shortcode('knx_dispatcher', 'knx_render_dispatcher_shortcode');
