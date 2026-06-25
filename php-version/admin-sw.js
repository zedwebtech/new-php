/**
 * Maventech Admin — service worker.
 *
 * Responsibilities:
 *   1. Cache the admin shell + assets for offline-friendly reloads.
 *   2. Periodic background poll for new admin events (orders, leads,
 *      reviews, emails, installs, sales, templates) — when running
 *      installed as a PWA we surface them as native OS notifications.
 *   3. Click-to-deep-link: tapping a notification opens the right
 *      admin tab.
 *
 * Real Web Push (VAPID) is intentionally NOT used — the admin is a
 * single-user product and a periodic background sync is enough.
 */
const CACHE_NAME = 'maventech-admin-v4';
const CORE_ASSETS = [
  '/admin-manifest.json',
  '/assets/css/style.css',
  '/assets/images/icons/admin-192.png',
  '/assets/images/icons/admin-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(CORE_ASSETS).catch(() => null))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Network-first for admin HTML so we never serve stale data after login;
// stale-while-revalidate for static asset GETs.
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.endsWith('.css') || url.pathname.endsWith('.js') ||
      url.pathname.startsWith('/assets/') || url.pathname.startsWith('/uploads/')) {
    event.respondWith(
      caches.open(CACHE_NAME).then(async (cache) => {
        const cached = await cache.match(event.request);
        const fetched = fetch(event.request).then((resp) => {
          if (resp && resp.status === 200) cache.put(event.request, resp.clone());
          return resp;
        }).catch(() => cached);
        return cached || fetched;
      })
    );
  }
});

// ---------------------------------------------------------------------
// Notification poll — runs every ~30 s when the PWA is in the
// background.  Each unread notification turns into a system toast.
// ---------------------------------------------------------------------
const POLL_URL = '/admin.php?ajax=notif_poll';
async function pollForNewNotifications() {
  try {
    const lastTs = await getLastTs();
    const url = POLL_URL + (lastTs ? '&since=' + encodeURIComponent(lastTs) : '');
    const res = await fetch(url, { credentials: 'include' });
    if (!res.ok) return;
    const data = await res.json();
    if (!data || !data.items || !data.items.length) return;
    const newest = data.items[0].created_at;
    await setLastTs(newest);
    for (const n of data.items) {
      await self.registration.showNotification(n.title, {
        body: n.body || '',
        icon: '/assets/images/icons/admin-192.png',
        badge: '/assets/images/icons/admin-192.png',
        tag:   'maventech-' + n.id,
        renotify: true,
        // OS plays its default notification sound for non-silent toasts;
        // vibrate buzzes the phone for ~600 ms total (Android only).
        silent: false,
        vibrate: [180, 80, 180],
        data:  { link: n.link || '/admin.php', id: n.id, type: n.type },
      });
    }
  } catch (e) { /* silent — admin will retry next interval */ }
}

self.addEventListener('periodicsync', (event) => {
  if (event.tag === 'maventech-admin-poll') {
    event.waitUntil(pollForNewNotifications());
  }
});

// Tab → SW polling fallback (when periodicsync isn't available).
self.addEventListener('message', (event) => {
  if (event.data === 'admin-poll') {
    event.waitUntil(pollForNewNotifications());
  }
});

// Click → open the right admin tab (or focus an existing window).
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const link = (event.notification.data && event.notification.data.link) || '/admin.php';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
      for (const w of wins) {
        if (w.url.includes('/admin.php')) { w.focus(); w.navigate && w.navigate(link); return; }
      }
      return self.clients.openWindow(link);
    })
  );
});

// Tiny IDB helper to remember the most-recent notification timestamp.
function idb() {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open('mv-admin', 1);
    open.onupgradeneeded = () => open.result.createObjectStore('kv');
    open.onsuccess = () => resolve(open.result);
    open.onerror   = () => reject(open.error);
  });
}
async function getLastTs() {
  const db = await idb();
  return new Promise((resolve) => {
    const tx = db.transaction('kv', 'readonly').objectStore('kv').get('lastTs');
    tx.onsuccess = () => resolve(tx.result || '');
    tx.onerror   = () => resolve('');
  });
}
async function setLastTs(ts) {
  const db = await idb();
  return new Promise((resolve) => {
    const tx = db.transaction('kv', 'readwrite').objectStore('kv');
    tx.put(ts, 'lastTs');
    tx.transaction.oncomplete = resolve;
    tx.transaction.onerror    = resolve;
  });
}
