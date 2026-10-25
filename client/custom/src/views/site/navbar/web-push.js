/**
 * Navbar control for desktop (Web Push) notifications.
 *
 * This is the single place a user turns notifications on or off, which is
 * what keeps EspoCRM from calling Notification.requestPermission() behind
 * their back. The manager it delegates to never prompts on its own.
 */
define('custom:views/site/navbar/web-push', ['view', 'custom:helpers/web-push-manager'], function (Dep, manager) {

    return Dep.extend({

        template: 'custom:site/navbar/web-push',

        events: {
            'click a[data-action="toggleWebPush"]': 'actionToggle',
            'keydown a[data-action="toggleWebPush"]': 'actionToggleKey'
        },

        setup: function () {
            this.stopListeningManager = manager.onChange(this.onStateChange.bind(this));
        },

        remove: function () {
            if (this.stopListeningManager) {
                this.stopListeningManager();
                this.stopListeningManager = null;
            }

            return Dep.prototype.remove.call(this);
        },

        afterRender: function () {
            this.applyState(manager.getState());
        },

        onStateChange: function (state) {
            if (!this.$el || !this.$el.length) {
                return;
            }

            this.applyState(state);
        },

        /**
         * Recomputes the icon, colour and tooltip for the current state.
         * Done in place rather than through a re-render so a state change
         * cannot fight with the navbar's own DOM management.
         */
        applyState: function (state) {
            var $button = this.$el.find('.web-push-button');

            if (!$button.length) {
                return;
            }

            var $icon = $button.find('.web-push-icon');

            var title = '';
            var icon = 'far fa-bell';
            var colour = '';

            if (!state.supported) {
                title = this.translate('webPushUnsupported', 'messages', 'Global');
                icon = 'far fa-bell-slash';
                colour = 'text-muted';
            } else if (!state.enabled || !state.configured) {
                title = this.translate('webPushNotConfigured', 'messages', 'Global');
                icon = 'far fa-bell-slash';
                colour = 'text-muted';
            } else if (state.permission === 'denied') {
                title = this.translate('webPushBlocked', 'messages', 'Global');
                icon = 'fas fa-bell-slash';
                colour = 'text-danger';
            } else if (state.subscribed && state.permission === 'granted') {
                title = this.translate('webPushOn', 'messages', 'Global');
                icon = 'fas fa-bell';
                colour = 'text-success';
            } else {
                title = this.translate('webPushEnable', 'messages', 'Global');
                icon = 'far fa-bell';
            }

            if (state.busy) {
                title = this.translate('webPushBusy', 'messages', 'Global');
                icon = 'fas fa-spinner fa-spin';
            }

            $icon.attr('class', icon + ' web-push-icon ' + colour);
            $button.attr('title', title).attr('aria-label', title);

            this.$el.toggleClass('web-push-unavailable', !state.supported);
        },

        actionToggleKey: function (event) {
            if (event.key === 'Enter' || event.key === ' ' || event.keyCode === 13 || event.keyCode === 32) {
                event.preventDefault();
                this.actionToggle(event);
            }
        },

        actionToggle: function () {
            var state = manager.getState();

            if (!state.supported) {
                Espo.Ui.warning(this.translate('webPushUnsupported', 'messages', 'Global'));

                return;
            }

            if (!state.enabled || !state.configured) {
                Espo.Ui.warning(this.translate('webPushNotConfigured', 'messages', 'Global'));

                return;
            }

            if (state.permission === 'denied') {
                Espo.Ui.warning(this.translate('webPushBlocked', 'messages', 'Global'));

                return;
            }

            if (state.busy) {
                return;
            }

            if (state.subscribed && state.permission === 'granted') {
                this.disable();

                return;
            }

            this.enable();
        },

        enable: function () {
            Espo.Ui.notifyWait();

            manager.enable().then(function (result) {
                Espo.Ui.notify(false);

                if (result.permission === 'granted' && result.subscribed) {
                    Espo.Ui.success(this.translate('webPushEnabled', 'messages', 'Global'));

                    return;
                }

                if (result.permission === 'denied') {
                    Espo.Ui.warning(this.translate('webPushBlocked', 'messages', 'Global'));

                    return;
                }

                if (!result.configured) {
                    Espo.Ui.warning(this.translate('webPushNotConfigured', 'messages', 'Global'));

                    return;
                }

                Espo.Ui.info(this.translate('webPushNotEnabled', 'messages', 'Global'));
            }.bind(this)).catch(function () {
                Espo.Ui.notify(false);
            });
        },

        disable: function () {
            Espo.Ui.notifyWait();

            manager.disable().then(function () {
                Espo.Ui.notify(false);
                Espo.Ui.success(this.translate('webPushDisabled', 'messages', 'Global'));
            }.bind(this)).catch(function () {
                Espo.Ui.notify(false);
            });
        }
    });
});
