define('custom:handlers/create-task-prefill', [], function () {

    /**
     * Supplies the extra attributes for a Task created from a Lead's Tasks
     * panel (the `+` button).
     *
     * The Task `name` is copied from the Lead by `createAttributeMap` in the
     * Lead clientDefs. This handler adds what metadata cannot express: the
     * description shown at the top of the record's History panel.
     *
     * It mirrors the History panel's own query exactly
     * (`crm:views/record/panels/history`: url Activities/{scope}/{id}/history,
     * orderBy dateStart, order desc, maxSize recordsPerPageSmall||5) so that
     * "the latest history row" means the same thing here as on screen. Each
     * row shows `cDescriptionDiff` when there is one (the History panel's
     * `description-change` field does the same) and the full `description`
     * otherwise.
     *
     * Nothing in here may ever reject: `CreateRelated.process` awaits this
     * value, so a thrown error would leave the `+` button doing nothing.
     */
    return class {

        constructor(helper, options) {
            this.helper = helper;
            this.options = options || {};
        }

        getAttributes(model) {
            try {
                if (!model || !model.id || typeof Espo === 'undefined' || !Espo.Ajax) {
                    return Promise.resolve({});
                }

                var url = 'Activities/' + model.entityType + '/' + model.id +
                    '/history?orderBy=dateStart&order=desc&maxSize=5' +
                    '&select=cDescriptionDiff,description,name';

                return Espo.Ajax.getRequest(url).then(function (result) {
                    var list = (result && result.list) || [];

                    for (var i = 0; i < list.length; i++) {
                        var diff = (list[i].cDescriptionDiff || '').trim();
                        var description = (list[i].description || '').trim();
                        var value = diff || description;

                        if (value) {
                            return { description: value };
                        }
                    }

                    return {};
                }, function () {
                    return {};
                });
            } catch (e) {
                return Promise.resolve({});
            }
        }

    };

});
