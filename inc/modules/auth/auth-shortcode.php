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

    <a href="<?php echo esc_url(site_url('/')); ?>" class="knx-auth-back">← Back</a>

    <div class="knx-auth-card">

        <!-- LOGIN -->
        <section class="knx-auth-mode" data-mode="login">
            <h1>Sign in</h1>

            <form method="post">
                <?php knx_nonce_field('login'); ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="knx-auth-error">Invalid credentials. Please try again.</div>
                <?php endif; ?>

                <div class="knx-hp">
                    <input type="text" name="knx_hp">
                    <input type="hidden" name="knx_hp_ts" value="<?php echo time(); ?>">
                </div>

                <input type="text" name="knx_login" placeholder="Email" required>
                <input type="password" name="knx_password" placeholder="Password" required>

                <label class="knx-remember">
                    <input type="checkbox" name="knx_remember"> Remember me
                </label>

                <button class="knx-btn-primary" name="knx_login_btn">Sign in</button>
            </form>

            <div class="knx-auth-links">
                <button type="button" data-switch="forgot">Forgot password?</button>
                <span>New here? <button type="button" data-switch="register">Create account</button></span>
            </div>
        </section>

        <!-- REGISTER -->
        <section class="knx-auth-mode" data-mode="register">
            <h1>Create account</h1>

            <form method="post">
                <?php knx_nonce_field('register'); ?>

                <div class="knx-hp">
                    <input type="text" name="knx_hp">
                    <input type="hidden" name="knx_hp_ts" value="<?php echo time(); ?>">
                </div>

                <input type="email" name="knx_register_email" placeholder="Email" required>
                <input type="password" name="knx_register_password" placeholder="Password" required>
                <input type="password" name="knx_register_password_confirm" placeholder="Confirm password" required>

                <button class="knx-btn-primary" name="knx_register_btn">Create account</button>
            </form>

            <div class="knx-auth-links">
                <span>Already have an account? <button type="button" data-switch="login">Sign in</button></span>
            </div>
        </section>

        <!-- FORGOT PASSWORD (UI ONLY for now) -->
        <section class="knx-auth-mode" data-mode="forgot">
            <h1>Reset password</h1>

            <form method="post">
                <?php knx_nonce_field('forgot'); ?>

                <input type="email" name="knx_reset_email" placeholder="Email" required>

                <button class="knx-btn-primary" disabled>
                    Send recovery email
                </button>
            </form>

            <div class="knx-auth-links">
                <button type="button" data-switch="login">Back to sign in</button>
            </div>
        </section>

    </div>
</div>

<script>
(function(){
    const shell = document.querySelector('.knx-auth-shell');
    document.querySelectorAll('[data-switch]').forEach(btn => {
        btn.addEventListener('click', () => {
            shell.setAttribute('data-mode', btn.dataset.switch);
        });
    });

    // Force login on errors
    if (new URLSearchParams(window.location.search).has('error')) {
        shell.setAttribute('data-mode', 'login');
    }
})();
</script>

<?php
return ob_get_clean();
});
