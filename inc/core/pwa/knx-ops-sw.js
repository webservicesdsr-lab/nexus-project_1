/**
 * ==========================================================
 * Kingdom Nexus — OPS Service Worker (Push Inbox)
 * ==========================================================
 *
 * Responsibilities:
 * - Receive push messages for OPS audience.
 * - Show notification with safe defaults.
 * - On click, focus an existing window or open target URL.
 *
 * Security:
 * - No secrets. No PII.
 * - No fetch interception (so it won't affect site navigation).
 */

self.addEventListener('install', (event) => {
  // Activate updates faster
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    try {
      await self.clients.claim();
    } catch (e) {}
  })());
});

/**
 * Parse push payload safely.
 * Expected payload (example):
 * {
 *   "audience": "ops_orders",
 *   "title": "New order",
 *   "body": "#123",
 *   "url": "/ops-orders",
 *   "tag": "knx_ops_orders",
 *   "data": {}
 * }
 */
function parsePushData(event) {
  let payload = {};
  try {
    if (event && event.data) {
      // Try JSON first
      payload = event.data.json();
    }
  } catch (e) {
    try {
      // Fallback to text
      const txt = event.data ? event.data.text() : '';
      payload = { body: String(txt || '') };
    } catch (e2) {
      payload = {};
    }
  }

  if (!payload || typeof payload !== 'object') payload = {};

  const title = typeof payload.title === 'string' && payload.title.trim()
    ? payload.title.trim()
    : 'Kingdom Nexus';

  const body = typeof payload.body === 'string' ? payload.body : '';

  const url = (typeof payload.url === 'string' && payload.url.trim())
    ? payload.url.trim()
    : '/ops-orders';

  const tag = (typeof payload.tag === 'string' && payload.tag.trim())
    ? payload.tag.trim()
    : 'knx_ops';

  const data = (payload.data && typeof payload.data === 'object') ? payload.data : {};
  data.url = url;

  return { title, body, tag, data };
}

self.addEventListener('push', (event) => {
  event.waitUntil((async () => {
    const parsed = parsePushData(event);

    const options = {
      body: parsed.body,
      tag: parsed.tag,
      data: parsed.data,
      renotify: true,
      // You can add icon/badge later if you want:
      // icon: '/path/icon.png',
      // badge: '/path/badge.png',
    };

    try {
      await self.registration.showNotification(parsed.title, options);
    } catch (e) {
      // Fail-closed: do nothing
    }
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const targetUrl = (event.notification && event.notification.data && event.notification.data.url)
    ? String(event.notification.data.url)
    : '/ops-orders';

  event.waitUntil((async () => {
    try {
      const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

      // Try to focus an existing OPS tab
      for (const client of allClients) {
        if (!client || !client.url) continue;
        // If any window already open on same origin, focus it and navigate if needed
        try {
          const u = new URL(client.url);
          if (u && u.origin === self.location.origin) {
            await client.focus();
            // Navigate explicitly to targetUrl to bring them to inbox
            try {
              await client.navigate(new URL(targetUrl, self.location.origin).toString());
            } catch (eNav) {}
            return;
          }
        } catch (eUrl) {}
      }

      // Otherwise open a new window
      await self.clients.openWindow(new URL(targetUrl, self.location.origin).toString());
    } catch (e) {
      // Fail-closed
    }
  })());
});

// Optional: allow page to trigger update
self.addEventListener('message', (event) => {
  try {
    const msg = event && event.data ? event.data : null;
    if (msg && msg.type === 'KNX_SW_SKIP_WAITING') {
      self.skipWaiting();
    }
  } catch (e) {}
});
