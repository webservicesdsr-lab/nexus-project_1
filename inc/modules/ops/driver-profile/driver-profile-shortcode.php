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
    $username = !empty($session->username) ? (string)$session->username : '';
    $phone = '';
    if (!empty($ctx->profile) && !empty($ctx->profile->phone)) {
        $phone = (string)$ctx->profile->phone;
    }

    $logout_url = wp_logout_url(home_url('/'));

    $knx_nonce = wp_create_nonce('knx_nonce');
    $wp_rest_nonce = wp_create_nonce('wp_rest');
    $change_password_url = esc_url(rest_url('knx/v2/profile/change-password'));

    $css = __DIR__ . '/driver-profile-style.css';
    if (file_exists($css)) {
        echo '<style>' . file_get_contents($css) . '</style>';
    }

    ob_start();
    ?>
    <div class="knx-profile knx-has-bottomnav" role="region" aria-label="Driver profile">
      <div class="knx-profile__card">
        <div class="knx-profile__avatar" aria-hidden="true">
          <?php echo esc_html(substr($name,0,1)); ?>
        </div>
        <div class="knx-profile__meta">
          <div class="knx-profile__name"><?php echo esc_html($name); ?></div>
          <div class="knx-profile__contact"><?php echo esc_html($email); ?><?php if ($phone) echo ' • ' . esc_html($phone); ?></div>
        </div>
      </div>

      <div class="knx-profile__section">
        <h3 class="knx-profile__section-title">Change Password</h3>
        <form id="knxChangePasswordForm" class="knx-profile__form">
          <div class="knx-form-field">
            <label for="current_password">Current Password <span class="required">*</span></label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
          </div>
          <div class="knx-form-field">
            <label for="new_password">New Password <span class="required">*</span></label>
            <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
            <small class="knx-form-hint">At least 8 characters</small>
          </div>
          <div class="knx-form-field">
            <label for="confirm_password">Confirm New Password <span class="required">*</span></label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
          </div>
          <div id="knxPasswordMessage" class="knx-profile__message" style="display:none;"></div>
          <button type="submit" class="knx-profile__btn knx-profile__btn--primary" id="knxChangePasswordBtn">
            Change Password
          </button>
        </form>
      </div>

      <div class="knx-profile__section">
        <h3 class="knx-profile__section-title">Change Username</h3>
        <form id="knxChangeUsernameForm" class="knx-profile__form">
          <div class="knx-form-field">
            <label for="new_username">New Username <span class="required">*</span></label>
            <input type="text" id="new_username" name="new_username" required minlength="3" value="<?php echo esc_attr($username); ?>" autocomplete="username">
            <small class="knx-form-hint">Choose a unique username (letters, numbers, hyphen/underscore).</small>
          </div>
          <div class="knx-form-field">
            <label for="username_current_password">Current Password <span class="required">*</span></label>
            <input type="password" id="username_current_password" name="username_current_password" required autocomplete="current-password">
          </div>
          <div id="knxUsernameMessage" class="knx-profile__message" style="display:none;"></div>
          <button type="submit" class="knx-profile__btn knx-profile__btn--primary" id="knxChangeUsernameBtn">Change Username</button>
        </form>
      </div>

      <div class="knx-profile__section">
        <h3 class="knx-profile__section-title">Notifications</h3>

        <div class="knx-profile__switch-list">
          <label class="knx-profile__switch-row" for="knx_browser_push_enabled">
            <div class="knx-profile__switch-copy">
              <div class="knx-profile__switch-title">Browser notifications</div>
              <div class="knx-profile__switch-desc">Alerts in this browser when available.</div>
            </div>
            <span class="knx-switch">
              <input id="knx_browser_push_enabled" type="checkbox">
              <span class="knx-slider"></span>
            </span>
          </label>

          <label class="knx-profile__switch-row" for="knx_ntfy_enabled">
            <div class="knx-profile__switch-copy">
              <div class="knx-profile__switch-title">Phone notifications</div>
              <div class="knx-profile__switch-desc">Send alerts to the ntfy app on your phone.</div>
            </div>
            <span class="knx-switch">
              <input id="knx_ntfy_enabled" type="checkbox">
              <span class="knx-slider"></span>
            </span>
          </label>

          <label class="knx-profile__switch-row" for="knx_email_enabled">
            <div class="knx-profile__switch-copy">
              <div class="knx-profile__switch-title">Email notifications</div>
              <div class="knx-profile__switch-desc">Also send alerts to your email.</div>
            </div>
            <span class="knx-switch">
              <input id="knx_email_enabled" type="checkbox">
              <span class="knx-slider"></span>
            </span>
          </label>
        </div>

        <div class="knx-form-field" id="knxNtfyFieldWrap">
          <label for="knx_ntfy_id">ntfy topic</label>
          <input type="text" id="knx_ntfy_id" placeholder="e.g. localbites-delivery-driver26" style="width:100%;">
          <small class="knx-form-hint">Example: localbites-delivery-driver26</small>
        </div>

        <div class="knx-profile__notif-actions">
          <button id="knxSaveNotifPrefs" class="knx-profile__btn knx-profile__btn--primary" type="button">Save Notification Settings</button>
          <button id="knxTestNtfyBtn" class="knx-profile__btn knx-profile__btn--ghost" type="button">Send Test Phone Notification</button>
          <span id="knxSaveNotifMsg" class="knx-profile__inline-message" style="display:none;"></span>
        </div>
      </div>

      <div class="knx-profile__actions">
        <a class="knx-profile__btn knx-profile__btn--ghost" href="<?php echo esc_attr($logout_url); ?>">Logout</a>
      </div>

      <script>
        window.knxDriverProfile = {
          changePasswordUrl: <?php echo wp_json_encode($change_password_url); ?>,
          knxNonce: <?php echo wp_json_encode($knx_nonce); ?>,
          wpRestNonce: <?php echo wp_json_encode($wp_rest_nonce); ?>,
          changeUsernameUrl: <?php echo wp_json_encode(rest_url('knx/v2/profile/change-username')); ?>,
          notificationPrefsUrl: <?php echo wp_json_encode(rest_url('knx/v1/driver-soft-push/prefs')); ?>,
          notificationTestUrl: <?php echo wp_json_encode(rest_url('knx/v1/driver-soft-push/test-ntfy')); ?>
        };
      </script>

      <script>
      <?php
        $js = __DIR__ . '/driver-profile-script.js';
        if (file_exists($js)) {
            echo file_get_contents($js);
        }
      ?>
      </script>

      <?php
        if (function_exists('knx_driver_bottom_nav_render')) {
            knx_driver_bottom_nav_render(array('current' => 'profile'));
        }
      ?>
    </div>
    <?php
    return ob_get_clean();
});