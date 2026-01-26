<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus — EXPLORE HUBS (Minimal Premium Edition)
 * Shortcode: [olc_explore_hubs]
 * Fully compiled + randomizer modal corrected
 */

add_shortcode('olc_explore_hubs', function ($atts) {
    ob_start();
    ?>

<div id="olc-explore-hubs" class="olc-root" role="region">

    <main>

        <!-- ====================== STICKY SEARCH ====================== -->
        <div class="search-sticky">
            <div class="search-row">
                <div class="search-pill">
                    <input id="hub-search" class="search-input" type="text" placeholder="Search tacos, pizza, local spots…">
                    <span class="search-ico search-ico-right"><i class="fas fa-search"></i></span>
                </div>
            </div>
        </div>

        <!-- ====================== CATEGORY CHIPS ====================== -->
        <section class="mood-wrap">
            <div class="mood-inner">
                <div class="mood-scroll scrollbar-hide snap-x">

                    <?php
                    global $wpdb;
                    $cat_table = $wpdb->prefix . 'knx_hub_categories';
                    $categories = $wpdb->get_results("SELECT id, name FROM {$cat_table} WHERE status='active' ORDER BY sort_order ASC");

                    if ($categories) {
                        foreach ($categories as $cat) {
                            echo '<button type="button" class="knx-mood-chip" data-category-id="'.esc_attr($cat->id).'" data-category-name="'.esc_attr($cat->name).'">
                                    <b>'.esc_html($cat->name).'</b>
                                  </button>';
                        }
                    }
                    ?>

                </div>
            </div>
        </section>

        <!-- ====================== SPOTLIGHTS ====================== -->
        <section class="spot-wrap">
            <div class="spot-inner">
                <div class="spot-head">
                    <h2>Locals Love These ❤️</h2>
                    <p class="spot-sub">Local favorites curated by your neighbors.</p>
                </div>
                <div id="spotlights-container" class="spot-scroll scrollbar-hide snap-x"></div>
            </div>
        </section>

        <!-- ====================== SURPRISE ME ====================== -->
        <section class="surp-wrap">
            <div class="surp-inner">
                <div id="surprise-trigger" class="surp-card" role="button" tabindex="0">
                    <div class="surp-ico-big">
                        <i class="fas fa-shuffle"></i>
                    </div>
                    <h3 class="surp-title">Feeling indecisive?</h3>
                    <div class="surp-rotator">
                        <p id="surprise-rotator-text" class="surp-rotator-text">
                            Tap once and we’ll spin a local pick for you.
                        </p>
                    </div>
                    <div class="surp-cta">
                        <span class="btn btn-amber">
                            <i class="fas fa-dice"></i> Surprise Me
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <!-- ====================== VENDORS GRID ====================== -->
        <section class="vend-wrap">
            <div class="vend-inner">
                <h2>Awesome Food Near Me</h2>
                <p class="vend-sub">Tap any card to open full menu.</p>
                <div id="vendors-grid" class="vend-grid"></div>
            </div>
        </section>

    </main>

    <!-- ====================== AVAILABILITY MODAL (NEXUS) ====================== -->
    <div id="knx-availability-modal" class="knx-avail hidden" aria-hidden="true">
        <div class="knx-avail-backdrop" aria-hidden="true"></div>

        <div class="knx-avail-card" role="dialog" aria-modal="true">

            <!-- X (same style concept as randomizer) -->
            <button type="button" class="knx-modal-x knx-avail-x" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>

            <div class="knx-avail-icon" id="knxAvailIcon">⏰</div>

            <h3 class="knx-avail-title" id="knxAvailTitle">This restaurant is unavailable</h3>

            <p class="knx-avail-message" id="knxAvailMessage">
                Please check back later or explore other local spots.
            </p>

            <div class="knx-avail-countdown" id="knxAvailCountdown" style="display:none;"></div>

            <div class="knx-avail-actions">
                <button type="button" class="btn btn-amber knx-avail-close">
                    Explore other restaurants
                </button>
            </div>
        </div>
    </div>

    <!-- ====================== SURPRISE ME MODAL (FINAL) ====================== -->
    <div id="surprise-overlay" class="surp-overlay hidden" aria-hidden="true">

        <div class="surp-overlay-backdrop" data-surp-close></div>

        <div class="surp-modal">
            <button type="button" class="surp-close knx-modal-x" data-surp-close aria-label="Close">
                <i class="fas fa-times"></i>
            </button>

            <section id="surprise-winner" class="surp-winner">
                <div class="aspect-16x9"></div>
                <div class="winner-body"></div>
            </section>
        </div>

    </div>

    <!-- ====================== FOOTER ====================== -->
    <footer class="knx-footer">
        <div class="ft-inner">
            <p class="ft-line">Support local, one bite at a time.</p>
            <p class="ft-sub">A portion of every order supports the community.</p>
        </div>
    </footer>

</div>

<!-- ========== DIRECT INCLUDES (NO ENQUEUE) ========== -->
<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/public/explore-hubs/explore-hubs.css?v=' . KNX_VERSION); ?>">
<script src="<?php echo esc_url(KNX_URL . 'inc/public/explore-hubs/explore-hubs.js?v=' . KNX_VERSION); ?>"></script>

<?php
    return ob_get_clean();
});
