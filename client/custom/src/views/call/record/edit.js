define('custom:views/call/record/edit', ['views/record/edit'], function (Dep) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            this.listenTo(this.model, 'change:parentId', function (m, id, o) {
                if (!o.ui) return;
                if (this.model.get('parentType') === 'Lead' && id) {
                    this.fetchLeadData(id);
                }
            });

            this.listenTo(this.model, 'change:parentType', function (m, type, o) {
                if (!o.ui) return;
                if (type === 'Lead' && this.model.get('parentId')) {
                    this.fetchLeadData(this.model.get('parentId'));
                }
            });

            if (this.model.get('parentType') === 'Lead' && this.model.get('parentId')) {
                this.fetchLeadData(this.model.get('parentId'), {initial: true});
            }
        },

        fetchLeadData: function (leadId, options) {
            options = options || {};

            this.clearView('productServicePicker');

            Espo.Ajax.getRequest('Lead/' + leadId, {
                select: 'id,name,cProductServicesIds,cProductServicesNames'
            }).then(function (response) {
                if (
                    this.model.get('parentType') !== 'Lead' ||
                    this.model.get('parentId') !== leadId
                ) {
                    return;
                }

                if (response.name && !this.model.get('name')) {
                    this.model.set('name', response.name);
                }

                var idList = response.cProductServicesIds || [];

                if (!idList.length) {
                    return;
                }

                if (options.initial && (this.model.get('cProductServicesIds') || []).length) {
                    return;
                }

                this.applyLeadProductServices(
                    idList,
                    response.cProductServicesNames || {},
                    response.name || ''
                );
            }.bind(this));
        },

        applyLeadProductServices: function (idList, nameMap, leadName) {
            if (idList.length === 1) {
                this.setProductServices(idList, nameMap);

                return;
            }

            this.openProductServicePicker(idList, nameMap, leadName);
        },

        setProductServices: function (idList, nameMap) {
            var names = {};

            idList.forEach(function (id) {
                if (nameMap[id]) {
                    names[id] = nameMap[id];
                }
            });

            this.model.set({
                cProductServicesIds: idList.slice(0),
                cProductServicesNames: names
            });
        },

        openProductServicePicker: function (idList, nameMap, leadName) {
            var optionList = idList.map(function (id) {
                return {
                    id: id,
                    name: nameMap[id] || id
                };
            });

            this.createView('productServicePicker', 'custom:views/modals/select-product-service', {
                optionList: optionList,
                leadName: leadName,
                backdrop: true
            }).then(function (view) {
                this.listenToOnce(view, 'select', function (selectedIdList) {
                    this.setProductServices(selectedIdList, nameMap);

                    view.close();
                }.bind(this));

                view.render();
            }.bind(this));
        },

    });

});
