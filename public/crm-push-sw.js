self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
// PWA shell only: deliberately do not cache authenticated ERP data.
self.addEventListener('fetch', () => {});

self.addEventListener('push', event => {
    if (!event.data) return;
    const payload = event.data.json();
    event.waitUntil(self.registration.showNotification(payload.title || 'MintERP CRM', {
        body: payload.body || '',
        icon: payload.icon || '/images/alexiasoft-logo.png',
        badge: payload.badge || '/images/alexiasoft-logo.png',
        actions: payload.actions || [],
        tag: payload.tag || 'crm-notification',
        data: payload.data || {},
        requireInteraction: payload.requireInteraction || false,
        vibrate: payload.vibrate || [200, 100, 200],
    }));
});
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/crm/notifications', self.location.origin).href;
    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windows => {
        const existing = windows.find(client => client.url === url);
        return existing ? existing.focus() : clients.openWindow(url);
    }));
});
