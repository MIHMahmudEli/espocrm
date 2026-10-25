define('custom:views/calendar/calendar', ['crm:views/calendar/calendar'], function (Dep) {

    return Dep.extend({

        MIN_META_DURATION: 45 * 60 * 1000,

        setup: function () {
            Dep.prototype.setup.call(this);

            this.template = 'custom:calendar/calendar';

            this.titleFormat = {
                month: 'MMMM YYYY',
                week: 'D MMM YYYY',
                day: 'dddd, MMMM D, YYYY'
            };

            this.events['keydown [data-action="refresh"]'] = 'actionRefreshKey';
        },

        actionRefreshKey: function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.keyCode === 13 || e.keyCode === 32) {
                e.preventDefault();
                this.actionRefresh();
            }
        },

        afterRender: function () {
            this.setupCalendarHook();
            Dep.prototype.afterRender.call(this);
        },

        setupCalendarHook: function () {
            var self = this;
            var current = this.calendar;

            Object.defineProperty(this, 'calendar', {
                configurable: true,
                enumerable: true,
                get: function () {
                    return current;
                },
                set: function (calendar) {
                    current = calendar;

                    if (calendar) {
                        self.applyCalendarCustomization(calendar);
                    }
                }
            });
        },

        applyCalendarCustomization: function (calendar) {
            try {
                calendar.setOption('slotMinHeight', 64);
                calendar.setOption('dayHeaderContent', this.dayHeaderContent.bind(this));
                calendar.setOption('eventContent', this.eventContent.bind(this));
                calendar.setOption('eventClassNames', this.eventClassNames.bind(this));
            } catch (e) {
                console.error(e);
            }
        },

        dayHeaderContent: function (arg) {
            var type = (arg.view && arg.view.type) || '';
            var calendar = this.calendar;

            if (type.indexOf('timeGrid') !== 0 || !arg.date || !calendar || !calendar.formatDate) {
                return arg.text;
            }

            var $content = $('<div>').addClass('cal-day-header');

            $content.append($('<span>').addClass('cal-dow').text(calendar.formatDate(arg.date, 'ddd')));
            $content.append($('<span>').addClass('cal-dom').text(calendar.formatDate(arg.date, 'D')));

            return {html: $content.get(0).innerHTML};
        },

        eventClassNames: function (arg) {
            var extendedProps = (arg.event && arg.event.extendedProps) || {};
            var classNames = [];
            var scope = extendedProps.scope;
            var status = extendedProps.status;

            if (scope) {
                classNames.push('cal-scope-' + this.slugify(scope));
            }

            if (scope && status) {
                classNames.push('cal-status-' + this.slugify(status));

                if (this.getEventTypeCompletedStatusList(scope).indexOf(status) !== -1) {
                    classNames.push('cal-is-completed');
                }
            }

            return classNames;
        },

        eventContent: function (arg) {
            var event = arg.event;
            var extendedProps = event.extendedProps || {};
            var type = (arg.view && arg.view.type) || '';
            var isTimeGrid = type.indexOf('timeGrid') === 0;

            var $content = $('<div>');
            var $frame = $('<div>').addClass('fc-event-main-frame');

            if (arg.timeText) {
                $frame.append($('<div>').addClass('fc-event-time').text(arg.timeText));
            }

            var $titleContainer = $('<div>').addClass('fc-event-title-container');
            var $title = $('<div>').addClass('fc-event-title fc-sticky').text(event.title || '\u00a0');

            $titleContainer.append($title);

            if (isTimeGrid && this.hasEventMeta(event)) {
                $titleContainer.addClass('fc-has-meta');
                $titleContainer.append(this.buildEventMeta(event));
            }

            $frame.append($titleContainer);
            $content.append($frame);

            var userIdList = extendedProps.userIdList || [];
            var userNameMap = extendedProps.userNameMap || {};

            userIdList.forEach(function (userId) {
                var userName = userNameMap[userId] || '';
                var avatarHtml = this.getHelper().getAvatarHtml(userId, 'small', 13);

                if (avatarHtml) {
                    avatarHtml += ' ';
                }

                $content.append(
                    $('<div>')
                        .addClass('user')
                        .css({overflow: 'hidden'})
                        .append(avatarHtml)
                        .append($('<span>').text(userName))
                );
            }, this);

            return {html: $content.get(0).innerHTML};
        },

        hasEventMeta: function (event) {
            var extendedProps = event.extendedProps || {};

            if (!extendedProps.scope || !extendedProps.status) {
                return false;
            }

            if (event.allDay) {
                return true;
            }

            if (!event.start) {
                return false;
            }

            var start = event.start.getTime();
            var end = event.end ? event.end.getTime() : start;

            return end - start >= this.MIN_META_DURATION;
        },

        buildEventMeta: function (event) {
            var extendedProps = event.extendedProps || {};
            var scope = extendedProps.scope;
            var status = extendedProps.status;
            var $meta = $('<div>').addClass('cal-event-meta');

            var iconClass = this.getMetadata().get(['clientDefs', scope, 'iconClass']) || 'far fa-circle';
            var typeLabel = this.translate(scope, 'scopeNames');

            $meta.append(
                $('<span>')
                    .addClass('cal-chip cal-chip-type')
                    .attr('title', typeLabel)
                    .append($('<i>').attr('class', iconClass).attr('aria-hidden', 'true'))
                    .append($('<span>').addClass('cal-chip-text').text(typeLabel))
            );

            var statusLabel = this.getLanguage().translateOption(status, 'status', scope) || status;

            $meta.append(
                $('<span>')
                    .addClass('cal-chip cal-chip-status cal-status-' + this.slugify(status))
                    .attr('title', statusLabel)
                    .append($('<i>').attr('class', this.getStatusIcon(scope, status)).attr('aria-hidden', 'true'))
                    .append($('<span>').addClass('cal-chip-text').text(statusLabel))
            );

            return $meta;
        },

        getStatusIcon: function (scope, status) {
            if (this.getEventTypeCanceledStatusList(scope).indexOf(status) !== -1) {
                return 'fas fa-ban';
            }

            if (this.getEventTypeCompletedStatusList(scope).indexOf(status) !== -1) {
                return 'fas fa-check';
            }

            return 'far fa-circle';
        },

        slugify: function (value) {
            return String(value)
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }

    });

});
