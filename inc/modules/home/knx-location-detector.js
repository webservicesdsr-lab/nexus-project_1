/**
 * Kingdom Nexus - Location Detector & Coverage Checker
 * Handles geolocation, coverage validation, and auto-redirect
 * Version: 1.0
 */

(function() {
    'use strict';

    const STORAGE_KEY = 'knx_user_location';
    const STORAGE_DURATION = 3600000; // 1 hour in ms
    const API_ENDPOINT = '/wp-json/knx/v1/check-coverage';

    class LocationDetector {
        constructor() {
            this.modal = null;
            this.detectBtn = document.getElementById('knx-detect-location');
            this.manualCards = document.querySelectorAll('.knx-hub-card');
            
            if (this.detectBtn) {
                this.init();
            }
        }

        init() {
            // Check if we have cached location
            this.checkCachedLocation();
            
            // Bind detect button
            this.detectBtn.addEventListener('click', () => this.detectLocation());
            
            // Bind manual cards
            this.manualCards.forEach(card => {
                card.addEventListener('click', (e) => {
                    const hubSlug = card.dataset.hubSlug;
                    if (hubSlug) {
                        this.redirectToHub(hubSlug);
                    }
                });
            });
        }

        checkCachedLocation() {
            const cached = sessionStorage.getItem(STORAGE_KEY);
            if (!cached) return;

            try {
                const data = JSON.parse(cached);
                const now = Date.now();
                
                if (now - data.timestamp < STORAGE_DURATION && data.hub) {
                    console.log('✅ Using cached location:', data.hub.name);
                    // Optional: auto-redirect immediately
                    // this.redirectToHub(data.hub.slug);
                }
            } catch (e) {
                sessionStorage.removeItem(STORAGE_KEY);
            }
        }

        async detectLocation() {
            if (!navigator.geolocation) {
                this.showModal('error', 'Geolocation is not supported by your browser.');
                return;
            }

            this.showModal('loading', 'Detecting your location...');

            navigator.geolocation.getCurrentPosition(
                (position) => this.onLocationSuccess(position),
                (error) => this.onLocationError(error),
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }

        async onLocationSuccess(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            console.log('📍 Location detected:', { lat, lng });

            try {
                const response = await fetch(API_ENDPOINT, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ lat, lng })
                });

                const data = await response.json();
                console.log('📥 Coverage check result:', data);

                if (data.found && data.hub) {
                    // Save to session
                    sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                        timestamp: Date.now(),
                        lat,
                        lng,
                        hub: data.hub
                    }));

                    // Show success and redirect
                    this.showModal('success', `Found: ${data.hub.name}!`, data.hub);
                    
                    setTimeout(() => {
                        this.redirectToHub(data.hub.slug);
                    }, 1500);
                } else if (data.nearby_hubs && data.nearby_hubs.length > 0) {
                    // No coverage but found nearby hubs
                    this.showModal('nearby', 'We\'re not in your area yet', data.nearby_hubs);
                } else {
                    // No hubs at all
                    this.showModal('not-found', 'Oops! We don\'t deliver to your area yet.');
                }
            } catch (error) {
                console.error('❌ Coverage check failed:', error);
                this.showModal('error', 'Failed to check coverage. Please try again.');
            }
        }

        onLocationError(error) {
            console.error('❌ Geolocation error:', error);

            let message = 'Unable to detect your location.';
            
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    message = 'Location permission denied. Please select a hub manually below.';
                    this.highlightManualCards();
                    break;
                case error.POSITION_UNAVAILABLE:
                    message = 'Location information unavailable.';
                    break;
                case error.TIMEOUT:
                    message = 'Location request timed out.';
                    break;
            }

            this.showModal('error', message);
        }

        showModal(type, message, data = null) {
            this.closeModal(); // Close existing modal

            this.modal = document.createElement('div');
            this.modal.className = `knx-location-modal knx-modal-${type}`;
            
            let content = '';

            switch(type) {
                case 'loading':
                    content = `
                        <div class="knx-modal-spinner"></div>
                        <p>${message}</p>
                    `;
                    break;

                case 'success':
                    content = `
                        <div class="knx-modal-icon">✅</div>
                        <h3>${message}</h3>
                        <p>Redirecting you now...</p>
                        ${data ? `<p class="knx-modal-distance">${data.distance} miles away</p>` : ''}
                    `;
                    break;

                case 'nearby':
                    const hubsList = data.map(hub => `
                        <div class="knx-nearby-hub" data-hub-slug="${hub.slug}">
                            <div class="knx-nearby-hub-info">
                                <strong>${hub.name}</strong>
                                <span class="knx-hub-distance">${hub.distance} miles</span>
                            </div>
                            <button class="knx-btn-select">Select</button>
                        </div>
                    `).join('');

                    content = `
                        <div class="knx-modal-icon">📍</div>
                        <h3>Oops! Nothing found near you</h3>
                        <p>But we found these nearby hubs:</p>
                        <div class="knx-nearby-hubs-list">
                            ${hubsList}
                        </div>
                    `;
                    break;

                case 'not-found':
                    content = `
                        <div class="knx-modal-icon">😔</div>
                        <h3>${message}</h3>
                        <p>We're expanding soon! Browse our available hubs below.</p>
                    `;
                    break;

                case 'error':
                    content = `
                        <div class="knx-modal-icon">⚠️</div>
                        <h3>Location Error</h3>
                        <p>${message}</p>
                    `;
                    break;
            }

            this.modal.innerHTML = `
                <div class="knx-modal-overlay" data-close></div>
                <div class="knx-modal-content">
                    <button class="knx-modal-close" data-close>×</button>
                    ${content}
                </div>
            `;

            document.body.appendChild(this.modal);

            // Bind close buttons
            this.modal.querySelectorAll('[data-close]').forEach(el => {
                el.addEventListener('click', () => this.closeModal());
            });

            // Bind nearby hub selection
            if (type === 'nearby') {
                this.modal.querySelectorAll('.knx-nearby-hub').forEach(hub => {
                    hub.addEventListener('click', (e) => {
                        const slug = hub.dataset.hubSlug;
                        if (slug) {
                            this.closeModal();
                            this.redirectToHub(slug);
                        }
                    });
                });

                // Auto-close after 8 seconds
                setTimeout(() => this.closeModal(), 8000);
            } else if (type === 'not-found' || type === 'error') {
                // Auto-close after 5 seconds
                setTimeout(() => this.closeModal(), 5000);
            }
        }

        closeModal() {
            if (this.modal) {
                this.modal.remove();
                this.modal = null;
            }
        }

        highlightManualCards() {
            this.manualCards.forEach(card => {
                card.classList.add('knx-card-highlight');
            });

            setTimeout(() => {
                this.manualCards.forEach(card => {
                    card.classList.remove('knx-card-highlight');
                });
            }, 3000);
        }

        redirectToHub(slug) {
            console.log(`🚀 Redirecting to: /hub/${slug}`);
            window.location.href = `/hub/${slug}`;
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new LocationDetector());
    } else {
        new LocationDetector();
    }
})();
