<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Profile Page (PHASE 2.C+)
 * Shortcode: [knx_profile]
 * ----------------------------------------------------------
 * - Form to update customer profile (name, phone, email, notes)
 * - Session required (redirect to /login if not logged)
 * - No enqueues (CSS/JS injected directly)
 * ==========================================================
 */

add_shortcode('knx_profile', 'knx_render_profile_page');

function knx_render_profile_page() {
    // Guard: session required
    $session = knx_get_session();
    if (!$session) {
        return '
            <div style="padding:40px;text-align:center;">
                <h2>Login Required</h2>
                <p>You need to be logged in to access your profile.</p>
                <a href="' . esc_url(site_url('/login')) . '" style="display:inline-block;margin-top:16px;padding:12px 24px;background:#10b981;color:white;border-radius:8px;text-decoration:none;font-weight:600;">Login</a>
            </div>
        ';
    }

    // Generate nonce for update
    $nonce = wp_create_nonce('knx_profile_update');

    // Inject settings for JS
    $rest_base = esc_url(rest_url('knx/v2/profile'));
    $cart_url = esc_url(site_url('/cart'));
    $login_url = esc_url(site_url('/login'));

    ob_start();
    ?>

    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/public/profile/profile-style.css?v=' . KNX_VERSION); ?>">

    <div id="knx-profile-page">
        <div class="knx-profile__header">
            <h1>Your Profile</h1>
            <p>Keep your information up to date for a smooth ordering experience.</p>
        </div>

        <div class="knx-profile__card">
            <form id="knxProfileForm" class="knx-profile__form">
                <!-- Required Fields -->
                <div class="knx-form-section">
                    <h3>Required Information</h3>
                    
                    <div class="knx-form-field">
                        <label for="knx-name">
                            Full Name <span class="required">*</span>
                        </label>
                        <input type="text" 
                               id="knx-name" 
                               name="name" 
                               required 
                               placeholder="John Doe"
                               disabled>
                    </div>

                    <div class="knx-form-field">
                        <label for="knx-phone">
                            Phone Number <span class="required">*</span>
                        </label>
                        <input type="tel" 
                               id="knx-phone" 
                               name="phone" 
                               required 
                               placeholder="(555) 123-4567"
                               disabled>
                    </div>
                </div>

                <!-- Optional Fields -->
                <div class="knx-form-section">
                    <h3>Optional Information</h3>
                    
                    <div class="knx-form-field">
                        <label for="knx-email">Email</label>
                        <input type="email" 
                               id="knx-email" 
                               name="email" 
                               placeholder="john@example.com"
                               disabled>
                    </div>

                    <div class="knx-form-field">
                        <label for="knx-notes">Delivery Notes</label>
                        <textarea id="knx-notes" 
                                  name="notes" 
                                  rows="3" 
                                  placeholder="Special delivery instructions..."
                                  disabled></textarea>
                    </div>
                </div>

                <!-- Error/Success Messages -->
                <div id="knxProfileMessage" class="knx-profile__message" style="display:none;"></div>

                <!-- Actions -->
                <div class="knx-profile__actions">
                    <button type="submit" 
                            id="knxProfileSaveBtn" 
                            class="knx-btn knx-btn--primary"
                            disabled>
                        <span class="knx-btn__text">Save Profile</span>
                        <span class="knx-btn__spinner" style="display:none;">Saving...</span>
                    </button>
                    <a href="<?php echo $cart_url; ?>" class="knx-btn knx-btn--secondary">
                        Back to Cart
                    </a>
                </div>

                <!-- Loading State -->
                <div id="knxProfileLoading" class="knx-profile__loading">
                    Loading profile...
                </div>
            </form>
        </div>
    </div>

    <script>
        window.KNX_PROFILE = {
            restBase: <?php echo json_encode($rest_base); ?>,
            nonce: <?php echo json_encode($nonce); ?>,
            urls: {
                cart: <?php echo json_encode($cart_url); ?>,
                login: <?php echo json_encode($login_url); ?>
            }
        };
    </script>
    <script src="<?php echo esc_url(KNX_URL . 'inc/public/profile/profile-script.js?v=' . KNX_VERSION); ?>" defer></script>

    <?php
    return ob_get_clean();
}
