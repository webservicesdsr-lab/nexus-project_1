<?php
if (!defined('ABSPATH')) exit;

add_shortcode('knx_auth', function () {

    $session = knx_get_session();
    if ($session) {
        wp_safe_redirect(site_url('/cart'));
        exit;
    }

    ob_start(); ?>

    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/auth/auth-style.css'); ?>">

    <div class="knx-auth-shell" data-mode="login">

        <!-- Back button -->
        <a href="<?php echo esc_url(site_url('/')); ?>" class="knx-auth-back" aria-label="Back">
            <span class="knx-back-icon">‹</span>
        </a>

        <div class="knx-auth-card">

            <!-- LOGIN -->
            <div class="knx-auth-mode" data-mode="login">
                <h1>Login</h1>
                <p class="knx-auth-sub">
                    Enter your email and password to access your account.
                </p>

                <?php if (isset($_GET['error'])): ?>
                    <div class="knx-auth-error" aria-live="polite">
                        Invalid credentials. Please try again.
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php knx_nonce_field('login'); ?>

                    <div class="knx-hp">
                        <input type="text" name="knx_hp">
                        <input type="hidden" name="knx_hp_ts" value="<?php echo time(); ?>">
                    </div>

                    <label>Email</label>
                    <input type="text" name="knx_login" required autofocus>


                    <label>Password</label>
                    <div class="knx-password-wrap">
                        <input type="password" name="knx_password" required>
                        <button type="button" class="knx-pass-toggle" aria-label="Toggle password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>

                    <div class="knx-auth-row">
                        <label class="knx-checkbox">
                            <input type="checkbox" name="knx_remember">
                            <span>Remember me</span>
                        </label>

                        <button type="button" class="knx-link" data-switch="forgot">
                            Forgot your password?
                        </button>
                    </div>

                    <button class="knx-btn-primary" name="knx_login_btn">
                        Log In
                    </button>
                </form>

                <div class="knx-auth-links">
                    Don’t have an account?
                    <button type="button" data-switch="register">Register Now</button>
                </div>
            </div>

            <!-- REGISTER -->
            <div class="knx-auth-mode" data-mode="register">
                <h1>Create Account</h1>

                <form method="post">
                    <?php knx_nonce_field('register'); ?>

                    <div class="knx-hp">
                        <input type="text" name="knx_hp">
                        <input type="hidden" name="knx_hp_ts" value="<?php echo time(); ?>">
                    </div>

                    <label>Email</label>
                    <input type="email" name="knx_register_email" required>

                    <label>Password</label>
                    <input type="password" name="knx_register_password" required>

                    <label>Confirm Password</label>
                    <input type="password" name="knx_register_password_confirm" required>

                    <button class="knx-btn-primary" name="knx_register_btn">
                        Create Account
                    </button>
                </form>

                <div class="knx-auth-links">
                    Already have an account?
                    <button type="button" data-switch="login">Login</button>
                </div>
            </div>

            <!-- FORGOT -->
            <div class="knx-auth-mode" data-mode="forgot">
                <h1>Password Recovery</h1>

                <form method="post">
                    <?php knx_nonce_field('forgot'); ?>
                    <label>Email</label>
                    <input type="email" name="knx_forgot_email" required>

                    <button class="knx-btn-primary">
                        Send Reset Link
                    </button>
                </form>

                <div class="knx-auth-links">
                    <button type="button" data-switch="login">Back to login</button>
                </div>
            </div>

        </div>
    </div>

    <script>
    (function(){
        const shell = document.querySelector('.knx-auth-shell');

        shell.addEventListener('click', e => {
            if (e.target.dataset.switch) {
                shell.setAttribute('data-mode', e.target.dataset.switch);
            }

            const toggleBtn = e.target.closest('.knx-pass-toggle');
            if (toggleBtn) {
                const input = toggleBtn.previousElementSibling;
                const icon  = toggleBtn.querySelector('i');

                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
        });
    })();
    </script>

    <?php
    return ob_get_clean();
});
