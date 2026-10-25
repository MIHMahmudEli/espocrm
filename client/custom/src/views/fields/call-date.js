define('custom:views/fields/call-date', ['views/fields/date', 'moment', 'jquery'], function (Dep, moment, $) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            this.listenTo(this.model, 'change:' + this.name, function () {
                if (this.isEditMode()) {
                    this.renderHint();
                }
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (this.isEditMode()) {
                this.renderHint();
            }
        },

        renderHint: function () {
            if (!this.$el) {
                return;
            }

            var text = this.getHintText();

            var $hint = this.$el.find('.call-date-hint');

            if (!text) {
                $hint.remove();

                return;
            }

            if (!$hint.length) {
                $hint = $('<span class="call-date-hint"></span>').css({
                    display: 'block',
                    marginTop: '2px',
                    fontSize: '12px',
                    lineHeight: '1.3',
                    color: '#8794a1'
                });

                this.$el.append($hint);
            }

            $hint.text(text);
        },

        getHintText: function () {
            var value = this.model.get(this.name);

            if (!value) {
                return '';
            }

            var timeZone = this.getDateTime().getTimeZone();
            var day = moment.tz(value + ' 00:00:00', 'YYYY-MM-DD HH:mm:ss', timeZone).startOf('day');
            var today = moment.tz(timeZone).startOf('day');
            var diff = day.diff(today, 'days');

            if (diff === 0) {
                return this.translate('Today');
            }

            if (diff === 1) {
                return this.translate('Tomorrow');
            }

            if (diff === -1) {
                return this.translate('Yesterday');
            }

            return '';
        }

    });

});
