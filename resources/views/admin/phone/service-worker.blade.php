// Nuvabill admin phone app {{ $version }}: shows push alerts and opens the page an alert points
// to. It never keeps admin pages on the phone; without a connection it shows a short offline page.
const SCOPE = {!! json_encode($scope) !!};
const START = {!! json_encode($start) !!};
const ICON = {!! json_encode($icon) !!};
const BADGE = {!! json_encode($badge) !!};
const OFFLINE = {!! json_encode($offline, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!};

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(event.request).catch(() => new Response(OFFLINE, { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
});

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch (error) {
        data = { title: event.data ? event.data.text() : '' };
    }

    event.waitUntil(self.registration.showNotification(data.title || 'Nuvabill', {
        body: data.body || '',
        icon: ICON,
        badge: BADGE,
        tag: data.tag || undefined,
        renotify: Boolean(data.tag),
        data: { url: data.url || START },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || START, self.location.origin);

    // Only pages of this admin area are opened.
    const url = target.origin === self.location.origin && target.pathname.startsWith(SCOPE) ? target.href : new URL(START, self.location.origin).href;

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        for (const client of windows) {
            if (new URL(client.url).pathname.startsWith(SCOPE) && 'navigate' in client) {
                return client.navigate(url).then((opened) => (opened || client).focus());
            }
        }

        return self.clients.openWindow(url);
    }));
});
