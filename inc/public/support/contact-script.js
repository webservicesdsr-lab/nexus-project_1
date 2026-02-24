/**
 * ════════════════════════════════════════════════════════════════
 * KINGDOM NEXUS — Contact & Support Script v1.0
 * ════════════════════════════════════════════════════════════════
 * - FAQ accordion toggle (ARIA-compliant)
 * - No form logic needed (contact = email + phone only)
 * ════════════════════════════════════════════════════════════════
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const faqItems = document.querySelectorAll('.knx-faq-item');
        if (!faqItems.length) return;

        faqItems.forEach(item => {
            const toggle = item.querySelector('.knx-faq-item__toggle');
            const answer = item.querySelector('.knx-faq-item__answer');
            if (!toggle || !answer) return;

            // Generate unique id for ARIA linkage
            const id = 'knx-faq-' + Math.random().toString(36).substr(2, 6);
            answer.id = id;
            toggle.setAttribute('aria-controls', id);

            toggle.addEventListener('click', () => {
                const isOpen = item.classList.contains('knx-faq-item--open');

                // (Optional) Close all others for single-open behavior
                faqItems.forEach(other => {
                    if (other !== item) {
                        other.classList.remove('knx-faq-item--open');
                        const otherToggle = other.querySelector('.knx-faq-item__toggle');
                        const otherAnswer = other.querySelector('.knx-faq-item__answer');
                        if (otherToggle) otherToggle.setAttribute('aria-expanded', 'false');
                        if (otherAnswer) otherAnswer.hidden = true;
                    }
                });

                // Toggle current
                if (isOpen) {
                    item.classList.remove('knx-faq-item--open');
                    toggle.setAttribute('aria-expanded', 'false');
                    answer.hidden = true;
                } else {
                    item.classList.add('knx-faq-item--open');
                    toggle.setAttribute('aria-expanded', 'true');
                    answer.hidden = false;
                }
            });
        });
    }
})();
