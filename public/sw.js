/**
 * EspoCRM Web Push service worker.
 *
 * Served from `<base>/sw.js` (`public/sw.js` through the rewrite rules), so
 * its registration scope is `<base>/`.
 *
 * It turns a push message from the server into a system notification and
 * routes a notification click back to the matching EspoCRM record. Everything
 * happens here, in the worker: the page does not have to be open, and it must
 * never be the thing that displays the notification.
 *
 * No build step: this file is served verbatim. Bump SW_VERSION on every
 * release so `self.skipWaiting()` picks the new worker up immediately and so
 * the diagnostics report can tell which worker an affected device is running.
 */

'use strict';

var SW_VERSION = '2026-10-04.1';

var SCOPE = self.registration.scope;
var ICON = SCOPE + 'client/img/favicon-196.png';

var DIAG_DB = 'espocrm-web-push';
var DIAG_STORE = 'diagnostics';

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(Promise.all([
        self.clients.claim(),
        diagWrite({ swVersion: SW_VERSION, activatedAt: now() })
    ]));
});

self.addEventListener('push', function (event) {
    var payload = readPayload(event);

    var title = typeof payload.title === 'string' && payload.title !== '' ?
        payload.title :
        'EspoCRM';

    var options = {
        body: typeof payload.body === 'string' ? payload.body : '',
        icon: ICON,
        badge: ICON,
        requireInteraction: payload.requireInteraction === true,
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

        /* Without this, replacing a notification that still sits in the
           operating system's notification centre is SILENT: the entry is
           updated in place and no banner is ever shown. That is the single
           most common reason "it reaches the notification centre but never
           pops up". `renotify` requires `tag`, which we have here. */
        options.renotify = true;
    }

    event.waitUntil(
        diagWrite({
            swVersion: SW_VERSION,
            lastPushAt: now(),
            pushCount: increment
        })
            .then(function () {
                return self.registration.showNotification(title, options);
            })
            .then(function () {
                return diagWrite({
                    lastNotifyAt: now(),
                    notifyCount: increment,
                    lastNotifyError: null
                });
            })
            .catch(function (error) {
                /* Never reject: a rejected waitUntil() tells the push service
                   the worker failed. Record the failure so the diagnostics
                   report can show it, then settle. */
                return diagWrite({
                    lastNotifyFailedAt: now(),
                    notifyFailedCount: increment,
                    lastNotifyError: errorName(error)
                });
            })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var data = event.notification.data || {};
    var url = typeof data.url === 'string' ? data.url : '';

    event.waitUntil(
        diagWrite({
            lastClickAt: now(),
            clickCount: increment
        }).then(function () {
            return openTarget(url);
        }, function () {
            return openTarget(url);
        })
    );
});

/**
 * Focus an already open EspoCRM window and navigate it to the record, or
 * open a new one when nothing is running.
 *
 * Always returns a promise: `event.waitUntil(undefined)` is a TypeError and
 * would leave the click handler broken for notifications that carry no URL.
 */
function openTarget(url) {
    if (!url) {
        url = SCOPE;
    }

    if (url.charAt(0) === '#') {
        url = SCOPE + url;
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

/**
 * Turns the push message into a plain object. A push without a body, or with
 * a body that is not JSON, still has to produce a visible notification -
 * `userVisibleOnly` is a promise to the browser, so there is no path here
 * that may return without showing something.
 */
function readPayload(event) {
    var payload = {};

    if (!event.data) {
        return payload;
    }

    try {
        payload = event.data.json();
    } catch (error) {
        try {
            payload = { body: event.data.text() };
        } catch (ignored) {
            payload = {};
        }
    }

    if (!payload || typeof payload !== 'object') {
        payload = {};
    }

    return payload;
}

function now() {
    return new Date().toISOString();
}

function increment(value) {
    return (typeof value === 'number' ? value : 0) + 1;
}

function errorName(error) {
    if (error && typeof error.name === 'string' && error.name !== '') {
        return error.name;
    }

    if (error && typeof error.message === 'string' && error.message !== '') {
        return error.message.substring(0, 200);
    }

    return 'Error';
}

/**
 * Small key/value store in IndexedDB so an affected device can be read after
 * the fact: last push received, last notification shown, last failure and the
 * worker version.
 *
 * It holds timestamps, counters and error *names* only - never a payload, a
 * token, an endpoint or a key. It never rejects: diagnostics must not be able
 * to break a notification.
 */
function diagWrite(patch) {
    return new Promise(function (resolve) {
        var request;

        try {
            request = indexedDB.open(DIAG_DB, 1);
        } catch (error) {
            resolve();

            return;
        }

        var settled = false;

        var watchdog = setTimeout(finish, 1000);

        function finish() {
            if (settled) {
                return;
            }

            settled = true;
            clearTimeout(watchdog);

            try {
                if (request.result) {
                    request.result.close();
                }
            } catch (ignored) {}

            resolve();
        }

        request.onupgradeneeded = function () {
            var db = request.result;

            if (!db.objectStoreNames.contains(DIAG_STORE)) {
                db.createObjectStore(DIAG_STORE);
            }
        };

        request.onerror = finish;
        request.onblocked = finish;

        request.onsuccess = function () {
            var db = request.result;
            var transaction;

            try {
                transaction = db.transaction(DIAG_STORE, 'readwrite');
            } catch (error) {
                finish();

                return;
            }

            transaction.oncomplete = finish;
            transaction.onerror = finish;
            transaction.onabort = finish;

            var store = transaction.objectStore(DIAG_STORE);
            var get = store.get('state');

            get.onerror = finish;

            get.onsuccess = function () {
                var state = {};
                var current = get.result;
                var key;
                var value;

                if (current && typeof current === 'object') {
                    for (key in current) {
                        if (Object.prototype.hasOwnProperty.call(current, key)) {
                            state[key] = current[key];
                        }
                    }
                }

                for (key in patch) {
                    if (!Object.prototype.hasOwnProperty.call(patch, key)) {
                        continue;
                    }

                    value = patch[key];

                    if (value === undefined) {
                        continue;
                    }

                    state[key] = typeof value === 'function' ? value(state[key]) : value;
                }

                state.swVersion = SW_VERSION;
                state.updatedAt = now();

                try {
                    store.put(state, 'state');
                } catch (error) {
                    finish();
                }
            };
        };
    });
}
