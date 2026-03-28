/*
 * KNX Driver Soft-Push Client
 * Canonical soft-push client for the authenticated driver session.
 * - Global boot guard
 * - Adaptive polling (visible/hidden)
 * - Immediate poll when returning to visible/focus
 * - Uses ServiceWorkerRegistration.showNotification to display local notifications
 * - Uses in-memory dedupe to avoid duplicate rendering in the same page session
 * - Delivery acknowledgement is delegated to the Service Worker on notification click
 */
(function () {
    'use strict';

    if (window.__KNX_DRIVER_SOFT_PUSH_CLIENT_BOOTED__) {
        return;
    }
    window.__KNX_DRIVER_SOFT_PUSH_CLIENT_BOOTED__ = true;

    if (typeof window.KNX_DRIVER_OPS_CONFIG === 'undefined') {
        console.warn('KNX: Driver soft-push client missing config. Aborting.');
        return;
    }

    var config = window.KNX_DRIVER_OPS_CONFIG || {};
    var POLL_VISIBLE_MS = config.pollVisibleMs || config.pollMs || 9000;
    var POLL_HIDDEN_MS  = config.pollHiddenMs  || 25000;
    var POLL_URL        = config.softPushPoll || null;
    var PREFS_URL       = config.prefsUrl || (POLL_URL ? POLL_URL.replace('/poll', '/prefs') : null);
    var ACK_URL         = config.ackUrl || (POLL_URL ? POLL_URL.replace('/poll', '/ack') : null);
    var SW_URL          = config.swUrl || null;
    var DEBUG           = !!config.debug;

    if (!POLL_URL) {
        console.warn('KNX: softPushPoll URL not provided. Soft-push disabled.');
        return;
    }

    var timer = null;
    var lastFetch = 0;
    var lastPrefsCheck = 0;
    var pollingEnabled = true;
    var seenNotifications = new Set();
    var swRegistrationPromise = null;

    function log() {
        if (!DEBUG) return;
        try {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('KNX soft-push:');
            console.log.apply(console, args);
        } catch (e) {}
    }

    function warn() {
        try {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('KNX soft-push:');
            console.warn.apply(console, args);
        } catch (e) {}
    }

    function requestNotificationPermissionIfNeeded() {
        if (!('Notification' in window)) return;

        try {
            if (Notification.permission === 'default') {
                Notification.requestPermission().then(function (perm) {
                    log('permission result', perm);
                });
            }
        } catch (e) {
            warn('permission request failed', e);
        }
    }

    function clearSeenIfNeeded() {
        if (seenNotifications.size > 200) {
            seenNotifications.clear();
        }
    }

    function scheduleNext() {
        clearTimeout(timer);

        if (!pollingEnabled) return;

        var ms = document.visibilityState === 'visible' ? POLL_VISIBLE_MS : POLL_HIDDEN_MS;
        timer = setTimeout(runPoll, ms);
    }

    function maybeRefreshPrefs() {
        if (!PREFS_URL) return Promise.resolve();

        var now = Date.now();
        if ((now - lastPrefsCheck) < 60000) {
            return Promise.resolve();
        }

        lastPrefsCheck = now;
        return refreshPrefs();
    }

    async function getOrCreateRegistration() {
        if (!('serviceWorker' in navigator)) {
            throw new Error('service_worker_unsupported');
        }

        if (!SW_URL) {
            throw new Error('missing_sw_url');
        }

        if (!swRegistrationPromise) {
            swRegistrationPromise = navigator.serviceWorker.register(SW_URL)
                .then(function (reg) {
                    log('service worker ready for notifications');
                    return reg;
                })
                .catch(function (err) {
                    swRegistrationPromise = null;
                    throw err;
                });
        }

        return swRegistrationPromise;
    }

    function runPoll() {
        if (!pollingEnabled) return;

        lastFetch = Date.now();

        fetch(POLL_URL, {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (res) {
                if (!res || !res.ok) {
                    if (res && (res.status === 401 || res.status === 403)) {
                        clearTimeout(timer);
                        pollingEnabled = false;
                        warn('polling unauthorized — stopped.');
                    } else {
                        warn('poll request failed', res ? res.status : 'no_response');
                        scheduleNext();
                    }
                    return null;
                }

                return res.json();
            })
            .then(function (json) {
                if (!json) return;

                if (json.ok !== true) {
                    clearTimeout(timer);
                    pollingEnabled = false;
                    warn('poll returned non-ok payload');
                    return;
                }

                if (json.has === true && json.payload) {
                    log('payload received', json.payload.notification_id || null);
                    showLocalNotification(json.payload);
                    scheduleNext();
                    return;
                }

                maybeRefreshPrefs().finally(function () {
                    scheduleNext();
                });
            })
            .catch(function (err) {
                warn('poll exception', err);
                scheduleNext();
            });
    }

    async function refreshPrefs() {
        if (!PREFS_URL) return;

        try {
            var resp = await fetch(PREFS_URL, {
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!resp || !resp.ok) return;

            var json = await resp.json();
            if (!json || json.ok !== true) return;

            var browserEnabled;
            if (typeof json.browser_push_enabled !== 'undefined') {
                browserEnabled = (json.browser_push_enabled === '1' || json.browser_push_enabled === 1 || json.browser_push_enabled === true);
            } else if (typeof json.soft_push_enabled !== 'undefined') {
                browserEnabled = (json.soft_push_enabled === '1' || json.soft_push_enabled === 1 || json.soft_push_enabled === true);
            } else {
                browserEnabled = true;
            }

            if (!browserEnabled) {
                pollingEnabled = false;
                clearTimeout(timer);
                log('polling disabled by browser push preference');
                return;
            }

            if (!pollingEnabled) {
                pollingEnabled = true;
                scheduleNext();
                log('polling re-enabled by browser push preference');
            }
        } catch (e) {
            warn('prefs refresh failed', e);
        }
    }

    function showLocalNotification(payload) {
        if (!payload || !payload.title || !payload.body || !payload.url) {
            warn('payload missing required notification fields');
            return;
        }

        if (!ACK_URL) {
            warn('ack url missing');
            return;
        }

        if (!('Notification' in window)) {
            warn('notification api unsupported');
            return;
        }

        if (Notification.permission !== 'granted') {
            log('notification skipped because permission is not granted', Notification.permission);
            return;
        }

        var nid = payload.notification_id ? String(payload.notification_id) : '';
        if (!nid) {
            warn('payload missing notification_id');
            return;
        }

        if (seenNotifications.has(nid)) {
            log('duplicate notification ignored', nid);
            return;
        }

        getOrCreateRegistration()
            .then(function (reg) {
                var notificationOptions = {
                    body: payload.body,
                    tag: 'knx-soft-push-' + nid,
                    renotify: false,
                    data: {
                        url: payload.url,
                        notification_id: nid,
                        ack_url: ACK_URL
                    }
                };

                return reg.showNotification(payload.title, notificationOptions).then(function () {
                    seenNotifications.add(nid);
                    clearSeenIfNeeded();
                    log('notification rendered', nid);
                });
            })
            .catch(function (err) {
                warn('notification render failed', err);
            });
    }

    function immediatePoll() {
        if (!pollingEnabled) return;
        if (Date.now() - lastFetch < 1000) return;
        runPoll();
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            immediatePoll();
        }
        scheduleNext();
    });

    window.addEventListener('focus', function () {
        immediatePoll();
    });

    try {
        getOrCreateRegistration().catch(function (err) {
            warn('initial service worker registration failed', err);
        });
    } catch (e) {
        warn('initial service worker bootstrap exception', e);
    }

    try {
        requestNotificationPermissionIfNeeded();
    } catch (e) {
        warn('permission bootstrap failed', e);
    }

    try {
        refreshPrefs().then(function () {
            immediatePoll();
            scheduleNext();
        });
    } catch (e) {
        immediatePoll();
        scheduleNext();
    }

    window.KNX_SOFT_PUSH = {
        pollNow: immediatePoll,
        stop: function () {
            clearTimeout(timer);
            pollingEnabled = false;
        },
        start: function () {
            pollingEnabled = true;
            scheduleNext();
        },
        refreshPrefs: refreshPrefs,
        getRegistration: getOrCreateRegistration
    };
})();