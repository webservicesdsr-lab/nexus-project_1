/**
 * ==========================================================
 * Kingdom Nexus — Profile Page Script (PHASE 2.C+)
 * ----------------------------------------------------------
 * - Loads profile via GET /knx/v2/profile/me
 * - Saves profile via POST /knx/v2/profile/update
 * - Shows loading/error/success states
 * ==========================================================
 */

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('knxProfileForm');
    const loading = document.getElementById('knxProfileLoading');
    const message = document.getElementById('knxProfileMessage');
    const saveBtn = document.getElementById('knxProfileSaveBtn');
    
    const nameInput = document.getElementById('knx-name');
    const phoneInput = document.getElementById('knx-phone');
    const emailInput = document.getElementById('knx-email');
    const notesInput = document.getElementById('knx-notes');

    if (!form || !window.KNX_PROFILE) return;

    const config = window.KNX_PROFILE;

    // Load profile on init
    loadProfile();

    // Form submit handler
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        saveProfile();
    });

    /**
     * Load profile from API
     */
    function loadProfile() {
        showLoading(true);
        hideMessage();

        fetch(config.restBase + '/me', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            showLoading(false);

            if (data.success && data.data) {
                const profile = data.data;
                nameInput.value = profile.name || '';
                phoneInput.value = profile.phone || '';
                emailInput.value = profile.email || '';
                notesInput.value = profile.notes || '';

                // Enable form
                enableForm(true);
            } else {
                showMessage('Failed to load profile. Please refresh the page.', 'error');
            }
        })
        .catch(err => {
            showLoading(false);
            showMessage('Network error. Please check your connection.', 'error');
        });
    }

    /**
     * Save profile to API
     */
    function saveProfile() {
        const name = nameInput.value.trim();
        const phone = phoneInput.value.trim();
        const email = emailInput.value.trim();
        const notes = notesInput.value.trim();

        // Validation
        if (!name || !phone) {
            showMessage('Name and phone are required.', 'error');
            return;
        }

        hideMessage();
        enableForm(false);
        showButtonLoading(true);

        const payload = {
            name: name,
            phone: phone,
            email: email,
            notes: notes,
            knx_nonce: config.nonce
        };

        fetch(config.restBase + '/update', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(response => response.json())
        .then(data => {
            showButtonLoading(false);
            enableForm(true);

            if (data.success) {
                showMessage('✓ Profile saved successfully!', 'success');
                
                // Update button text to indicate completion
                const btnText = saveBtn.querySelector('.knx-btn__text');
                if (btnText) {
                    btnText.textContent = 'Profile Saved ✓';
                }
                
                // Optional: disable save button to prevent re-submission
                setTimeout(function() {
                    saveBtn.disabled = false;
                    if (btnText) {
                        btnText.textContent = 'Save Profile';
                    }
                }, 3000);
            } else {
                const errorMsg = data.message || 'Failed to save profile.';
                showMessage(errorMsg, 'error');
            }
        })
        .catch(err => {
            showButtonLoading(false);
            enableForm(true);
            showMessage('Network error. Please try again.', 'error');
        });
    }

    /**
     * UI Helpers
     */
    function showLoading(show) {
        loading.style.display = show ? 'flex' : 'none';
    }

    function showMessage(text, type) {
        message.textContent = text;
        message.className = 'knx-profile__message ' + type;
        message.style.display = 'block';
    }

    function hideMessage() {
        message.style.display = 'none';
    }

    function enableForm(enable) {
        nameInput.disabled = !enable;
        phoneInput.disabled = !enable;
        emailInput.disabled = !enable;
        notesInput.disabled = !enable;
        saveBtn.disabled = !enable;
    }

    function showButtonLoading(show) {
        const text = saveBtn.querySelector('.knx-btn__text');
        const spinner = saveBtn.querySelector('.knx-btn__spinner');
        
        if (text) text.style.display = show ? 'none' : 'inline';
        if (spinner) spinner.style.display = show ? 'inline' : 'none';
    }
});
