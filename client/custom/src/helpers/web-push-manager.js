/**
 * EspoCRM Web Push manager.
 *
 * Owns everything about the browser side of Path B:
 *
 *   - service worker registration (no permission needed for that),
 *   - the notification permission state machine,
 *   - creating / removing the PushSubscription,
 *   - keeping the server copy of the subscription in sync.
 *
 * It never prompts on its own. The only place that calls
 * Notification.requestPermission() is enable(), which is reached from a
 * explicit user gesture on the navbar button. That is what keeps the browser
 * from classifying EspoCRM as a site that nags for permissions.
 */
define('custom:helpers/web-push-manager', [], function () {

    var STATE = {
        supported: false,
        permission: 'default',
        serviceWorkerReady: false,
        configured: false,
        enabled: true,
        subscribed: false,
        subscriptionCount: 0,
        busy: false
    };

    var listeners = [];
    var application = null;
    var basePath = '/';
    var registrationPromise = null;
    var initialised = false;

    /* ------------------------------------------------------------- helpers */

    function getState() {
        var copy = {};

        for (var key in STATE) {
            if (Object.prototype.hasOwnProperty.call(STATE, key)) {
                copy[key] = STATE[key];
            }
        }

        return copy;
    }

    function emit() {
        var snapshot = getState();

        for (var i = 0; i < listeners.length; i++) {
            try {
                listeners[i](snapshot);
            } catch (error) {
                console.warn('[web-push] state listener failed', error);
            }
        }
    }

    function set(patch) {
        var changed = false;

        for (var key in patch) {
            if (!Object.prototype.hasOwnProperty.call(patch, key)) {
                continue;
            }

            if (STATE[key] !== patch[key]) {
                STATE[key] = patch[key];
                changed = true;
            }
        }

        if (changed) {
            emit();
        }
    }

    function resolveBasePath() {
        var base = '';

        if (typeof Espo !== 'undefined' && Espo.Utils && typeof Espo.Utils.obtainBaseUrl === 'function') {
            base = Espo.Utils.obtainBaseUrl();
        } else {
            base = window.location.pathname;

            if (/\.php$/i.test(base)) {
                var lastSlash = base.lastIndexOf('/');
                base = lastSlash <= 0 ? '/' : base.substring(0, lastSlash) + '/';
            }
        }

        if (base.charAt(base.length - 1) !== '/') {
            base += '/';
        }

        return base;
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var output = new Uint8Array(raw.length);

        for (var i = 0; i < raw.length; i++) {
            output[i] = raw.charCodeAt(i);
        }

        return output;
    }

    function uint8ArrayToUrlBase64(bytes) {
        var raw = '';

        for (var i = 0; i < bytes.length; i++) {
            raw += String.fromCharCode(bytes[i]);
        }

        return window.btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function sameServerKey(subscription, serverKey) {
        var key = subscription.options && subscription.options.applicationServerKey;

        if (!key) {
            /* Nothing to compare against - reuse the existing subscription. */
            return true;
        }

        var bytes = (key instanceof ArrayBuffer || ArrayBuffer.isView(key))
            ? new Uint8Array(key)
            : null;

        if (!bytes) {
            return true;
        }

        return uint8ArrayToUrlBase64(bytes) === serverKey;
    }

    /**
     * Asks the browser to re-check `sw.js` right now instead of waiting for
     * the next navigation. Together with `updateViaCache: 'none'` this is
     * what pulls a stale device onto the current worker without a hard reload.
     */
    function refreshServiceWorker(registration) {
        if (!registration || typeof registration.update !== 'function') {
            return;
        }

        try {
            var updating = registration.update();

            if (updating && typeof updating.catch === 'function') {
                updating.catch(function () {
                    /* Offline, or the server said no: the browser retries on
                       the next navigation. Never surfaces to the user. */
                });
            }
        } catch (error) {
            console.warn('[web-push] service worker update check failed', error);
        }
    }

    /* ---------------------------------------------------------- diagnostics */

    var DIAG_DB = 'espocrm-web-push';
    var DIAG_STORE = 'diagnostics';

    /**
     * Reads the record the service worker writes on every push. It only ever
     * contains timestamps, counters, the worker version and an error name -
     * no payload, no endpoint, no token.
     *
     * @return {Promise<Object|null>}
     */
    function readWorkerDiagnostics() {
        return new Promise(function (resolve) {
            var request;

            try {
                request = window.indexedDB.open(DIAG_DB, 1);
            } catch (error) {
                resolve(null);

                return;
            }

            var settled = false;
            var result = null;
            var watchdog = window.setTimeout(function () {
                finish(null);
            }, 1000);

            function finish(state) {
                if (settled) {
                    return;
                }

                settled = true;
                window.clearTimeout(watchdog);

                try {
                    if (request.result) {
                        request.result.close();
                    }
                } catch (ignored) {}

                resolve(state);
            }

            request.onupgradeneeded = function () {
                var db = request.result;

                if (!db.objectStoreNames.contains(DIAG_STORE)) {
                    db.createObjectStore(DIAG_STORE);
                }
            };

            request.onerror = function () {
                finish(null);
            };

            request.onblocked = function () {
                finish(null);
            };

            request.onsuccess = function () {
                var db = request.result;
                var transaction;

                try {
                    transaction = db.transaction(DIAG_STORE, 'readonly');
                } catch (error) {
                    finish(null);

                    return;
                }

                transaction.oncomplete = function () {
                    finish(result);
                };

                transaction.onerror = function () {
                    finish(null);
                };

                transaction.onabort = function () {
                    finish(null);
                };

                var get = transaction.objectStore(DIAG_STORE).get('state');

                get.onsuccess = function () {
                    result = get.result && typeof get.result === 'object' ? get.result : null;
                };
            };
        });
    }

    /**
     * Only the host of the push endpoint is reported (`fcm.googleapis.com`,
     * `updates.push.services.mozilla.com`, …). The endpoint URL and the
     * p256dh/auth keys are secrets and are never returned or transmitted.
     */
    function endpointHost(endpoint) {
        if (typeof endpoint !== 'string' || endpoint === '') {
            return null;
        }

        var match = /^[a-z][a-z0-9+.-]*:\/\/([^/?#]+)/i.exec(endpoint);

        return match ? match[1] : null;
    }

    /**
     * Everything needed to compare a working device with an affected one.
     *
     * @return {Promise<Object>}
     */
    function getDiagnostics() {
        var report = {
            collectedAt: new Date().toISOString(),
            origin: window.location.origin,
            basePath: basePath,
            supported: STATE.supported,
            permission: ('Notification' in window) ? window.Notification.permission : 'unsupported',
            configured: STATE.configured,
            enabled: STATE.enabled,
            subscribed: STATE.subscribed,
            subscriptionCount: STATE.subscriptionCount,
            userAgent: navigator.userAgent,
            serviceWorker: null,
            subscription: null,
            workerDiagnostics: null
        };

        return Promise.resolve(registrationPromise || null)
            .then(function (registration) {
                if (!registration) {
                    return null;
                }

                var script = registration.active ||
                    registration.waiting ||
                    registration.installing;

                report.serviceWorker = {
                    scope: registration.scope,
                    scriptURL: script && script.scriptURL ? script.scriptURL : null,
                    state: script && script.state ? script.state : null
                };

                return readLocalSubscription(registration);
            })
            .then(function (subscription) {
                if (subscription) {
                    report.subscription = {
                        endpointHost: endpointHost(subscription.endpoint),
                        expirationTime: subscription.expirationTime || null,
                        hasKeys: !!(
                            subscription.keys &&
                            subscription.keys.p256dh &&
                            subscription.keys.auth
                        )
                    };
                }

                return readWorkerDiagnostics();
            })
            .then(function (workerDiagnostics) {
                report.workerDiagnostics = workerDiagnostics;

                return report;
            })
            .catch(function (error) {
                report.error = String((error && error.name) || error);

                return report;
            });
    }

    /**
     * Sends the report above to the server so it can be stored next to the
     * subscription information the server already has.
     *
     * @return {Promise<Object>} {server, client}
     */
    function sendDiagnostics() {
        return getDiagnostics().then(function (report) {
            return Espo.Ajax.postRequest('PushSubscription/action/diagnostics', {
                client: report
            }).then(function (response) {
                return response || {};
            }, function () {
                /* The local report is still useful if the call fails. */
                return { client: report, server: null };
            });
        });
    }

    /* --------------------------------------------------------------- status */

    function loadServerStatus() {
        return Espo.Ajax.getRequest('PushSubscription/action/status')
            .then(function (data) {
                set({
                    configured: !!(data && data.configured),
                    enabled: !(data && data.enabled === false),
                    subscriptionCount: (data && data.subscriptionCount) || 0
                });
            })
            .catch(function () {
                /* The endpoint is optional: the button still works locally. */
            });
    }

    /**
     * True when this browser currently holds a PushSubscription.
     */
    function readLocalSubscription(registration) {
        if (!registration || !registration.pushManager) {
            return Promise.resolve(null);
        }

        return registration.pushManager.getSubscription();
    }

    /* --------------------------------------------------------- subscription */

    function storeSubscription(subscription) {
        var data = typeof subscription.toJSON === 'function'
            ? subscription.toJSON()
            : {
                endpoint: subscription.endpoint,
                expirationTime: subscription.expirationTime,
                keys: subscription.keys
            };

        return Espo.Ajax.postRequest('PushSubscription/action/subscribe', {
            endpoint: data.endpoint,
            expirationTime: data.expirationTime === undefined ? null : data.expirationTime,
            keys: {
                p256dh: data.keys.p256dh,
                auth: data.keys.auth
            }
        });
    }

    /**
     * Creates the PushSubscription if it is missing or was issued for a
     * different VAPID key, then stores it server side.
     */
    function ensureSubscription(registration) {
        return Espo.Ajax.getRequest('PushSubscription/action/vapidPublicKey')
            .then(function (data) {
                if (!data || !data.configured || !data.publicKey) {
                    set({ configured: false });

                    return null;
                }

                set({ configured: true });

                return readLocalSubscription(registration).then(function (existing) {
                    if (existing && sameServerKey(existing, data.publicKey)) {
                        return existing;
                    }

                    var releasing = existing ? existing.unsubscribe() : Promise.resolve(false);

                    return releasing.then(function () {
                        return registration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(data.publicKey)
                        });
                    });
                });
            })
            .then(function (subscription) {
                if (!subscription) {
                    return null;
                }

                return storeSubscription(subscription).then(function () {
                    set({ subscribed: true });

                    return loadServerStatus();
                });
            });
    }

    /* ----------------------------------------------------------------- api */

    /**
     * Called once when the application has started. Registers the service
     * worker and, when permission was already granted in an earlier session,
     * refreshes the stored subscription. Never triggers a permission prompt.
     */
    function init(application) {
        if (initialised) {
            return Promise.resolve(getState());
        }

        initialised = true;
        application = application;
        basePath = resolveBasePath();

        if (
            typeof window === 'undefined' ||
            typeof navigator === 'undefined' ||
            !('serviceWorker' in navigator) ||
            !('Notification' in window) ||
            !('PushManager' in window)
        ) {
            set({ supported: false });

            return Promise.resolve(getState());
        }

        set({
            supported: true,
            permission: window.Notification.permission
        });

        registrationPromise = navigator.serviceWorker
            .register(basePath + 'sw.js', {
                scope: basePath,
                /* Never satisfy the worker script from the HTTP cache. A
                   cached worker is how an affected device ends up running a
                   version the server has already replaced. */
                updateViaCache: 'none'
            })
            .then(function () {
                return navigator.serviceWorker.ready;
            })
            .then(function (registration) {
                set({ serviceWorkerReady: true });

                refreshServiceWorker(registration);

                return registration;
            })
            .catch(function (error) {
                console.warn('[web-push] service worker registration failed', error);

                return null;
            });

        return registrationPromise
            .then(function (registration) {
                return loadServerStatus().then(function () {
                    if (!registration) {
                        return null;
                    }

                    return readLocalSubscription(registration).then(function (subscription) {
                        set({ subscribed: !!subscription });

                        if (window.Notification.permission !== 'granted') {
                            /* Never prompt here - the navbar toggle does that. */
                            return null;
                        }

                        /* Permission is already granted, so a subscription that
                           went missing (cleared site data, a replaced worker, a
                           rotated VAPID key) is repaired silently. */
                        return ensureSubscription(registration)
                            .then(function () {
                                return readLocalSubscription(registration);
                            })
                            .then(function (current) {
                                set({ subscribed: !!current });
                            });
                    });
                });
            })
            .catch(function (error) {
                console.warn('[web-push] initialisation failed', error);
            })
            .then(function () {
                return getState();
            });
    }

    /**
     * The explicit "Enable Desktop Notifications" action.
     *
     * @return {Promise<Object>} the state afterwards, never rejects.
     */
    function enable() {
        if (!STATE.supported || STATE.busy) {
            return Promise.resolve(getState());
        }

        /* The browser will not show the prompt again after a denial, and we
           must not hammer it. Surface the state instead. */
        if (window.Notification.permission === 'denied') {
            set({ permission: 'denied' });

            return Promise.resolve(getState());
        }

        set({ busy: true });

        return Promise.resolve(window.Notification.requestPermission())
            .then(function (permission) {
                set({ permission: permission });

                if (permission !== 'granted') {
                    return null;
                }

                return registrationPromise.then(function (registration) {
                    if (!registration) {
                        return null;
                    }

                    return ensureSubscription(registration);
                });
            })
            .catch(function (error) {
                console.warn('[web-push] enable failed', error);
            })
            .then(function () {
                set({ busy: false });

                return getState();
            });
    }

    /**
     * Turns notifications off for this browser only: other devices of the
     * same account keep their own subscriptions.
     *
     * @return {Promise<Object>}
     */
    function disable() {
        if (!STATE.supported || STATE.busy) {
            return Promise.resolve(getState());
        }

        set({ busy: true });

        return registrationPromise
            .then(function (registration) {
                if (!registration) {
                    return null;
                }

                return readLocalSubscription(registration).then(function (subscription) {
                    if (!subscription) {
                        return null;
                    }

                    var endpoint = subscription.endpoint;

                    return subscription.unsubscribe().then(function () {
                        return Espo.Ajax.postRequest('PushSubscription/action/unsubscribe', {
                            endpoint: endpoint
                        });
                    });
                });
            })
            .catch(function (error) {
                console.warn('[web-push] disable failed', error);
            })
            .then(function () {
                set({ busy: false, subscribed: false, subscriptionCount: 0 });

                return loadServerStatus();
            })
            .then(function () {
                return getState();
            });
    }

    /**
     * Sends a real push to this account so the whole chain can be verified
     * without waiting for a scheduled job.
     */
    function sendTest() {
        return Espo.Ajax.postRequest('PushSubscription/action/sendTest', {
            title: 'EspoCRM',
            body: 'Test notification'
        });
    }

    /**
     * Re-checks everything after a login/logout without ever prompting.
     *
     * The browser subscription itself is unchanged - only which account it
     * belongs to server side - so re-storing it transfers ownership.
     *
     * @return {Promise<Object>}
     */
    function resync() {
        if (!STATE.supported || !registrationPromise) {
            return Promise.resolve(getState());
        }

        return registrationPromise
            .then(function (registration) {
                if (!registration) {
                    return null;
                }

                return loadServerStatus().then(function () {
                    set({ permission: window.Notification.permission });

                    return readLocalSubscription(registration).then(function (subscription) {
                        set({ subscribed: !!subscription });

                        if (window.Notification.permission !== 'granted') {
                            return null;
                        }

                        return ensureSubscription(registration)
                            .then(function () {
                                return readLocalSubscription(registration);
                            })
                            .then(function (current) {
                                set({ subscribed: !!current });
                            });
                    });
                });
            })
            .catch(function (error) {
                console.warn('[web-push] resync failed', error);
            })
            .then(function () {
                return getState();
            });
    }

    function onChange(listener) {
        if (typeof listener !== 'function') {
            return function () {};
        }

        listeners.push(listener);

        try {
            listener(getState());
        } catch (error) {
            console.warn('[web-push] state listener failed', error);
        }

        return function () {
            var index = listeners.indexOf(listener);

            if (index !== -1) {
                listeners.splice(index, 1);
            }
        };
    }

    return {
        init: init,
        resync: resync,
        enable: enable,
        disable: disable,
        sendTest: sendTest,
        getDiagnostics: getDiagnostics,
        sendDiagnostics: sendDiagnostics,
        getState: getState,
        onChange: onChange,
        getBasePath: function () { return basePath; }
    };
});
