/**
 * EspoCRM Web Push bootstrap.
 *
 * Loaded as a plain script from `client/custom/src/web-push.js`, after
 * `espo-main.js` and before the inline boot script in `html/main.html`.
 *
 * It wraps `window.require()` so it can patch `App.prototype.start()` once
 * the application object exists, then hands over to the `web-push-manager`
 * module, which registers the service worker and keeps the subscription of
 * the signed-in user in sync.
 *
 * This file deliberately does NOT ask for notification permission. The only
 * thing that may do that is the explicit "Enable desktop notifications"
 * button in the navbar, so the browser never sees an unsolicited prompt.
 *
 * The actual delivery is done by the server side NotificationService.
 */
(function () {
    'use strict';

    if (typeof window === 'undefined') {
        return;
    }

    var hookedRequire = false;
    var patchedApp = false;

    var fallbackModuleId = 'custom:helpers/web-push-manager';

    function hookRequire() {
        if (hookedRequire) {
            return;
        }

        if (typeof window.require !== 'function') {
            window.setTimeout(hookRequire, 20);

            return;
        }

        hookedRequire = true;

        var originalRequire = window.require;

        window.require = function (name, callback) {
            if (name === 'app' && typeof callback === 'function') {
                var args = Array.prototype.slice.call(arguments);

                args[1] = function (App) {
                    patchApp(App);

                    return callback.apply(this, arguments);
                };

                return originalRequire.apply(window, args);
            }

            return originalRequire.apply(window, arguments);
        };
    }

    function patchApp(App) {
        if (patchedApp || !App || !App.prototype) {
            return;
        }

        patchedApp = true;

        var originalStart = App.prototype.start;

        if (typeof originalStart !== 'function') {
            return;
        }

        App.prototype.start = function () {
            var result = originalStart.apply(this, arguments);

            onAppStart(this);

            return result;
        };
    }

    function onAppStart(app) {
        window.require(fallbackModuleId, function (manager) {
            window.EspoWebPush = manager;

            manager.init(app);

            if (app && typeof app.on === 'function') {
                /* A different account signing in on the same browser must
                   take ownership of this device's subscription. */
                app.on('login', function () {
                    if (typeof manager.resync === 'function') {
                        manager.resync();
                    }
                });

                /* Stop pushing to whoever signed out; the next sign-in
                   re-subscribes without needing the prompt again. */
                app.on('logout', function () {
                    if (manager.getState().subscribed) {
                        manager.disable();
                    }
                });
            }
        });
    }

    hookRequire();
})();
