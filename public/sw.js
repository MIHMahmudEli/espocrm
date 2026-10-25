/**
 * EspoCRM Web Push service worker.
 *
 * Served from `<base>/public/sw.js`, so its registration scope is `<base>/`.
 * It turns a push message from the server into a system notification and
 * routes a notification click back to the matching EspoCRM record.
 *
 * No build step: this file is served verbatim.
 */

'use strict';

var SCOPE = self.registration.scope;
var ICON = SCOPE + 'client/img/favicon-196.png';

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var payload = {};

    if (event.data) {
        try {
            payload = event.data.json();
        } catch (error) {
            payload = { body: event.data.text() };
        }
    }

    var title = typeof payload.title === 'string' && payload.title !== '' ?
        payload.title :
        'EspoCRM';

    var options = {
        body: typeof payload.body === 'string' ? payload.body : '',
        icon: ICON,
        badge: ICON,
        requireInteraction: false,
        data: {
            url: typeof payload.url === 'string' ? payload.url : '',
            notificationId: typeof payload.notificationId === 'string' ? payload.notificationId : '',
            type: typeof payload.type === 'string' ? payload.type : '',
            entityType: typeof payload.entityType === 'string' ? payload.entityType : '',
            entityId: typeof payload.entityId === 'string' ? payload.entityId : '',
            timestamp: typeof payload.timestamp === 'number' ? payload.timestamp : 0
        }
    };

    if (payload.tag) {
        options.tag = String(payload.tag);
    }

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var data = event.notification.data || {};
    var url = typeof data.url === 'string' ? data.url : '';

    event.waitUntil(openTarget(url));
});

/**
 * Focus an already open EspoCRM window and navigate it to the record, or
 * open a new one when nothing is running.
 */
function openTarget(url) {
    if (url.charAt(0) === '#') {
        url = SCOPE + url;
    }

    if (!url) {
        return undefined;
    }

    return self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];

                if (client.url.indexOf(SCOPE) !== 0) {
                    continue;
                }

                if (typeof client.focus !== 'function') {
                    continue;
                }

                return client.focus().then(function (focused) {
                    if (!focused) {
                        return null;
                    }

                    if (typeof focused.navigate === 'function') {
                        return focused.navigate(url).catch(function () {
                            return focused;
                        });
                    }

                    return focused;
                });
            }

            if (typeof self.clients.openWindow === 'function') {
                return self.clients.openWindow(url);
            }

            return null;
        });
}
