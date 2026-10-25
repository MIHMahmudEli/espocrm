define('custom:views/stream/notes/create', ['views/stream/notes/create'], function (Dep) {

    return Dep.extend({

        data: function () {
            var data = Dep.prototype.data.call(this);
            data.descriptionText = this.descriptionText || null;
            return data;
        },

        setup: function () {
            this.template = 'custom:stream/notes/create-call';

            Dep.prototype.setup.call(this);

            var parentType = this.model.get('parentType');

            if (parentType === 'Call') {
                var parentId = this.model.get('parentId');

                if (parentId) {
                    this.descriptionText = null;

                    Espo.Ajax.getRequest('Call/' + parentId, {
                        select: 'id,description'
                    }).then(function (response) {
                        if (response.description) {
                            this.descriptionText = response.description;
                            this.reRender();
                        }
                    }.bind(this));
                }
            }
        },

    });

});
