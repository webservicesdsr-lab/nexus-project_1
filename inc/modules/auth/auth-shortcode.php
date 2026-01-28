<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Auth Shortcode (v2)
 *
 * Shortcode: [knx_auth]
 * Provides a secure and modern login form.
 * Automatically redirects logged-in users to home.
 */

add_shortcode('knx_auth', function () {
    // Redirect if user already has a valid session
    $session = knx_get_session();
    if ($session) {
        wp_safe_redirect(site_url('/cart'));
        exit;
    }

    ob_start(); ?>

    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/auth/auth-style.css'); ?>">

    <div class="knx-auth-container">
        <a href="<?php echo esc_url(site_url('/')); ?>" class="knx-back-home">Back to Home</a>

        <div class="knx-auth-card knx-auth-card--tabs" role="region" aria-label="Authentication">
            <div class="knx-tabs" role="tablist" aria-label="Auth Tabs">
                <button type="button" class="knx-tab knx-tab--active" data-target="knx-tab-login" role="tab" aria-selected="true">Sign In</button>
                <button type="button" class="knx-tab" data-target="knx-tab-register" role="tab" aria-selected="false">Create Account</button>
            </div>

            <div class="knx-tab-contents">
                <div id="knx-tab-login" class="knx-tab-pane knx-tab-pane--active" role="tabpanel">
                    <h2>Sign In</h2>

                    <form method="post">
                        <?php knx_nonce_field('login'); ?>

                        <?php if (isset($_GET['error']) && in_array($_GET['error'], ['auth','invalid','locked'], true)): ?>
                            <div class="knx-error">Something went wrong with your login. Please try again.</div>
                        <?php endif; ?>

                        <div style="display:none;">
                            <label>Leave this empty<input type="text" name="knx_hp" value=""></label>
                            <input type="hidden" name="knx_hp_ts" value="<?php echo time(); ?>">
                        </div>

                        <div class="knx-input-group">
                            <input type="text" name="knx_login" placeholder="Username or Email" required>
                        </div>

                        <div class="knx-input-group">
                            <input type="password" name="knx_password" placeholder="Password" required>
                        </div>

                        <div class="knx-auth-options">
                            <label><input type="checkbox" name="knx_remember"> Remember me</label>
                        </div>

                        <button type="submit" name="knx_login_btn" class="knx-btn">Sign In</button>
                    </form>
                </div>

                <div id="knx-tab-register" class="knx-tab-pane" role="tabpanel" aria-hidden="true">
                    <h2>Create Account</h2>

                    <form method="post">
                        <?php knx_nonce_field('register'); ?>

                        <div style="display:none;">
                            <label>Leave this empty<input type="text" name="knx_hp" value=""></label>
                            <input type="hidden" name="knx_hp_ts" value="<?php echo time(); ?>">
                        </div>

                        <div class="knx-input-group">
                            <input type="email" name="knx_register_email" placeholder="Email" required>
                        </div>

                        <div class="knx-input-group">
                            <input type="password" name="knx_register_password" placeholder="Password" required>
                        </div>

                        <div class="knx-input-group">
                            <input type="password" name="knx_register_password_confirm" placeholder="Confirm Password" required>
                        </div>

                        <button type="submit" name="knx_register_btn" class="knx-btn">Create Account</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var tabs = document.querySelectorAll('.knx-tab');
        var panes = document.querySelectorAll('.knx-tab-pane');

        function activate(targetId){
            tabs.forEach(function(t){
                var on = t.getAttribute('data-target') === targetId;
                t.classList.toggle('knx-tab--active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panes.forEach(function(p){
                var on = p.id === targetId;
                p.classList.toggle('knx-tab-pane--active', on);
                p.setAttribute('aria-hidden', on ? 'false' : 'true');
            });
        }

        tabs.forEach(function(btn){
            btn.addEventListener('click', function(){
                activate(btn.getAttribute('data-target'));
            });
            btn.addEventListener('touchend', function(){
                activate(btn.getAttribute('data-target'));
            });
        });

        // If URL has error param, ensure Sign In tab is active
        try{
            var params = new URLSearchParams(window.location.search);
            if (params.has('error')) {
                activate('knx-tab-login');
            }
        }catch(e){ /* ignore */ }
    })();
    </script>

    <?php
    return ob_get_clean();
});
