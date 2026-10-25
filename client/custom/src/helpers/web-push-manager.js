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
            .register(basePath + 'sw.js', { scope: basePath })
            .then(function () {
                return navigator.serviceWorker.ready;
            })
            .then(function (registration) {
                set({ serviceWorkerReady: true });

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

                        if (!subscription || window.Notification.permission !== 'granted') {
                            return null;
                        }

                        /* Permission already granted: keep the server copy in
                           sync without asking the user anything. */
                        return ensureSubscription(registration);
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

                        if (!subscription || window.Notification.permission !== 'granted') {
                            return null;
                        }

                        return ensureSubscription(registration);
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
        getState: getState,
        onChange: onChange,
        getBasePath: function () { return basePath; }
    };
});
