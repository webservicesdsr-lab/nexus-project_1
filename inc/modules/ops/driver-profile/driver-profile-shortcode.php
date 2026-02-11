<?php
if (!defined('ABSPATH')) exit;

/**
 * KNX — Driver Profile Shortcode
 * Shortcode: [knx_driver_profile]
 */

add_shortcode('knx_driver_profile', function() {
    if (!function_exists('knx_get_driver_context')) {
        return '<div class="knx-profile">Driver context unavailable.</div>';
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || !is_object($ctx) || empty($ctx->session) || !is_object($ctx->session) || empty($ctx->session->user_id)) {
        return '<div class="knx-profile">Unauthorized.</div>';
    }

    $session = $ctx->session;
    $name = !empty($session->display_name) ? (string)$session->display_name : 'Driver';
    $email = !empty($session->user_email) ? (string)$session->user_email : '';
    $phone = '';
    if (!empty($ctx->profile) && !empty($ctx->profile->phone)) $phone = (string)$ctx->profile->phone;

    $logout_url = wp_logout_url(home_url('/'));

    // Inject styles + script
    $css = __DIR__ . '/driver-profile-style.css';
    if (file_exists($css)) echo '<style>' . file_get_contents($css) . '</style>';
    $js = __DIR__ . '/driver-profile-script.js';
    if (file_exists($js)) echo '<script>' . file_get_contents($js) . '</script>';

    ob_start();
    ?>
    <div class="knx-profile knx-has-bottomnav" role="region" aria-label="Driver profile">
      <div class="knx-profile__card">
        <div class="knx-profile__avatar" aria-hidden="true">
          <?php echo esc_html(substr($name,0,1)); ?>
        </div>
        <div class="knx-profile__meta">
          <div class="knx-profile__name"><?php echo esc_html($name); ?></div>
          <div class="knx-profile__contact"><?php echo esc_html($email); ?><?php if($phone) echo ' • ' . esc_html($phone); ?></div>
        </div>
      </div>

      <div class="knx-profile__actions">
        <a class="knx-profile__btn knx-profile__btn--ghost" href="<?php echo esc_attr($logout_url); ?>">Logout</a>
      </div>

      <?php
        // Render bottom navbar (driver) if available
        if (function_exists('knx_driver_bottom_nav_render')) {
            knx_driver_bottom_nav_render(array('current' => 'profile'));
        }
      ?>
    </div>
    <?php
    return ob_get_clean();
});
