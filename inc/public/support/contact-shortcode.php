<?php
if (!defined('ABSPATH')) exit;

/**
 * ════════════════════════════════════════════════════════════════
 * KINGDOM NEXUS — Contact & Support Page v1.0
 * Shortcode: [knx_contact]
 * ════════════════════════════════════════════════════════════════
 *
 * PURPOSE:
 * - Public FAQ accordion (expandable)
 * - Contact information: email + phone
 * - Accessible, mobile-first, Nexus Shell tokens
 *
 * SCOPE:
 * - Public page: /contact
 * - No session required (guests can view)
 *
 * [KNX-SUPPORT-1.0]
 */

add_shortcode('knx_contact', 'knx_render_contact_page');

function knx_render_contact_page() {
    ob_start();
    ?>

    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/public/support/contact-style.css?v=' . KNX_VERSION); ?>">

    <div class="knx-contact" id="knxContactPage">

        <!-- ═══ FAQ ACCORDION ═══ -->
        <section class="knx-contact__faq" aria-label="Frequently Asked Questions">

            <div class="knx-faq-item">
                <button class="knx-faq-item__toggle" type="button" aria-expanded="false">
                    <span class="knx-faq-item__question">What is Local Bites' Mission?</span>
                    <svg class="knx-faq-item__chevron" width="20" height="20" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="knx-faq-item__answer" role="region" hidden>
                    <p>Our mission is to deliver local flavors, support small businesses, and make a donation with every delivery.</p>
                </div>
            </div>

            <div class="knx-faq-item">
                <button class="knx-faq-item__toggle" type="button" aria-expanded="false">
                    <span class="knx-faq-item__question">About Us</span>
                    <svg class="knx-faq-item__chevron" width="20" height="20" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="knx-faq-item__answer" role="region" hidden>
                    <p>Local Bites connects communities with their favorite local restaurants while giving back through donations. We've donated tens of thousands of pounds of food to local food banks and are committed to making a positive impact.</p>
                </div>
            </div>

            <div class="knx-faq-item">
                <button class="knx-faq-item__toggle" type="button" aria-expanded="false">
                    <span class="knx-faq-item__question">How Do I Get a Refund?</span>
                    <svg class="knx-faq-item__chevron" width="20" height="20" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="knx-faq-item__answer" role="region" hidden>
                    <p>Send us a message on our Facebook page for quickest support or send an email to <a href="mailto:localbitesdelivery@gmail.com">localbitesdelivery@gmail.com</a> — you can also reach out to your driver.</p>
                </div>
            </div>

            <div class="knx-faq-item">
                <button class="knx-faq-item__toggle" type="button" aria-expanded="false">
                    <span class="knx-faq-item__question">How Do I Sign Up as a Vendor?</span>
                    <svg class="knx-faq-item__chevron" width="20" height="20" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="knx-faq-item__answer" role="region" hidden>
                    <p>If you're a restaurant or food vendor interested in joining Local Bites Delivery, we'd love to hear from you! Feel free to reach out via email, phone, or social media — whichever works best for you.</p>
                </div>
            </div>

            <div class="knx-faq-item">
                <button class="knx-faq-item__toggle" type="button" aria-expanded="false">
                    <span class="knx-faq-item__question">How Do I Sign Up as a Driver?</span>
                    <svg class="knx-faq-item__chevron" width="20" height="20" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="knx-faq-item__answer" role="region" hidden>
                    <p>Interested in driving for Local Bites? We're always looking for reliable drivers to join our team! Get in touch via email, phone, or social media — we're happy to answer any questions you might have.</p>
                </div>
            </div>

        </section>

        <!-- ═══ CONTACT INFO ═══ -->
        <section class="knx-contact__info" aria-label="Contact Information">
            <h2 class="knx-contact__title">Have More Questions?</h2>
            <p class="knx-contact__subtitle">Reach out to us directly — we're happy to help.</p>

            <div class="knx-contact__channels">

                <!-- Email -->
                <a href="mailto:localbitesdelivery@gmail.com" class="knx-contact__channel">
                    <div class="knx-contact__channel-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="4" width="20" height="16" rx="2"/>
                            <path d="M22 4l-10 8L2 4"/>
                        </svg>
                    </div>
                    <div class="knx-contact__channel-body">
                        <span class="knx-contact__channel-label">Email</span>
                        <span class="knx-contact__channel-value">localbitesdelivery@gmail.com</span>
                    </div>
                    <svg class="knx-contact__channel-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </a>

                <!-- Phone -->
                <a href="tel:+14695452328" class="knx-contact__channel">
                    <div class="knx-contact__channel-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07
                                     19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18
                                     2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81
                                     a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27
                                     a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7
                                     A2 2 0 0 1 22 16.92z"/>
                        </svg>
                    </div>
                    <div class="knx-contact__channel-body">
                        <span class="knx-contact__channel-label">Phone</span>
                        <span class="knx-contact__channel-value">+1 (469) 545-2328</span>
                    </div>
                    <svg class="knx-contact__channel-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </a>

            </div>
        </section>

    </div>

    <script src="<?php echo esc_url(KNX_URL . 'inc/public/support/contact-script.js?v=' . KNX_VERSION); ?>" defer></script>

    <?php
    return ob_get_clean();
}
