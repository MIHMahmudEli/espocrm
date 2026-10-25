define('custom:views/meeting/record/edit', ['views/record/edit'], function (Dep) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            this.listenTo(this.model, 'change:parentId change:parentType', function () {
                this.fetchParentName();
            });

            this.fetchParentName();

            if (this.model.isNew()) {
                this.fillAttendees();
            }
        },

        fetchParentName: function () {
            var type = this.model.get('parentType');
            var id = this.model.get('parentId');

            if (!type || !id) {
                this.parentNameKey = null;
                return;
            }

            if (!this.getMetadata().get(['entityDefs', type, 'fields', 'name'])) return;

            var key = type + ':' + id;
            if (this.parentNameKey === key) return;
            this.parentNameKey = key;

            Espo.Ajax.getRequest(type + '/' + id, {
                select: 'id,name'
            }).then(function (response) {
                if (!response.name) return;
                if (this.parentNameKey !== key) return;

                var name = this.model.get('name');

                if (!name || name === this.autoFilledName) {
                    this.model.set('name', response.name);
                    this.autoFilledName = response.name;
                }
            }.bind(this));
        },

        fillAttendees: function () {
            var ids = this.model.get('usersIds');
            if (ids && ids.length) return;

            var user = this.getUser();
            if (!user || !user.id) return;

            var id = user.id;

            this.model.set({
                usersIds: [id],
                usersNames: (function () { var o = {}; o[id] = user.get('name'); return o; })(),
                usersColumns: (function () { var o = {}; o[id] = { status: 'None' }; return o; })()
            });
        }

    });

});
