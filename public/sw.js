/*
 * V0Game service worker (served from the site root so its scope covers every universe directory).
 * It only handles Web Push: show the notification the server sent and open/focus the game when it is clicked.
 * Payload (JSON): { title, body, url, tag }.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = { body: event.data ? event.data.text() : '' };
  }
  const title = data.title || 'V0Game';
  event.waitUntil(
    self.registration.showNotification(title, {
      body: data.body || '',
      icon: 'icon-192.png',
      badge: 'icon-192.png',
      tag: data.tag || undefined,
      data: { url: data.url || './' },
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || './', self.registration.scope).href;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      const known = windows.find((client) => client.url === target) || windows.find((client) => client.url.startsWith(self.registration.scope));
      if (known) {
        return 'navigate' in known && known.url !== target ? known.navigate(target).then((client) => (client || known).focus()) : known.focus();
      }
      return self.clients.openWindow(target);
    }),
  );
});
