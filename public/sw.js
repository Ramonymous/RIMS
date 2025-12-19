// Service Worker for Push Notifications

self.addEventListener('push', function(event) {
    if (!event.data) {
        return;
    }

    const data = event.data.json();
    const title = data.title || 'RIMS Notification';
    const options = {
        body: data.body || 'Anda memiliki notifikasi baru',
        icon: data.icon || '/favicon.ico',
        badge: data.badge || '/favicon.ico',
        tag: data.tag || 'default-tag',
        data: data.data || {},
        requireInteraction: true,
        actions: data.actions || [
            { action: 'view', title: 'Lihat' },
            { action: 'close', title: 'Tutup' }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();

    if (event.action === 'view' || !event.action) {
        const urlToOpen = event.notification.data.url || '/request-list';
        
        event.waitUntil(
            clients.matchAll({ type: 'window', includeUncontrolled: true })
                .then(function(clientList) {
                    // Check if there's already a window open
                    for (let i = 0; i < clientList.length; i++) {
                        const client = clientList[i];
                        if (client.url === urlToOpen && 'focus' in client) {
                            return client.focus();
                        }
                    }
                    // Open new window if none found
                    if (clients.openWindow) {
                        return clients.openWindow(urlToOpen);
                    }
                })
        );
    }
});

self.addEventListener('install', function(event) {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(clients.claim());
});
