define('custom:views/call/record/detail', ['crm:views/call/record/detail'], function (Dep) {

    return Dep.extend({

        setupActionItems: function () {
            Dep.prototype.setupActionItems.call(this);

            var control = this.controlPlannedActionItems.bind(this);

            control();

            this.listenTo(this.model, 'change:status', control);

            this.model.onSync({
                owner: this,
                callback: control
            });
        },

        controlPlannedActionItems: function () {
            if (this.model.get('status') !== 'Planned') {
                return;
            }

            if (!this.getAcl().checkModel(this.model, 'edit')) {
                return;
            }

            this.showActionItem('setHeld');
            this.showActionItem('setNotHeld');
        }
    });

});
