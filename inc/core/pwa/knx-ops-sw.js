/* KNX Service Worker — minimal local notification support
 * Responsibilities:
 * - Install/activate lifecycle
 * - Handle notificationclick to acknowledge the soft-push row
 * - Focus or open the URL provided in notification.data.url
 * - Keep minimal surface; do not implement push subscription or fetch handlers
 */
'use strict';

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

function knxAbsoluteUrl(input) {
    try {
        return new URL(input || '/', self.location.origin).href;
    } catch (e) {
        return new URL('/', self.location.origin).href;
    }
}

function knxAckNotification(data) {
    try {
        if (!data || !data.ack_url || !data.notification_id) {
            return Promise.resolve();
        }

        return fetch(knxAbsoluteUrl(data.ack_url), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                notification_id: parseInt(data.notification_id, 10)
            })
        }).catch(function () {
            return Promise.resolve();
        });
    } catch (e) {
        return Promise.resolve();
    }
}

function knxFocusOrOpen(url) {
    var targetUrl = knxAbsoluteUrl(url);

    return self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true
    }).then(function (clientList) {
        for (var i = 0; i < clientList.length; i++) {
            var client = clientList[i];

            try {
                if (!client || !client.url) continue;

                var clientUrl = new URL(client.url, self.location.origin).href;
                if (clientUrl === targetUrl) {
                    return client.focus();
                }
            } catch (e) {
                // Ignore malformed client URLs.
            }
        }

        return self.clients.openWindow(targetUrl);
    });
}

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var data = {};
    try {
        data = event.notification && event.notification.data ? event.notification.data : {};
    } catch (e) {
        data = {};
    }

    var url = data && data.url ? data.url : '/';

    event.waitUntil(
        knxAckNotification(data).then(function () {
            return knxFocusOrOpen(url);
        })
    );
});