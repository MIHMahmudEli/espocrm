define('custom:views/lead/record/edit-small', ['views/record/edit-small'], function (Dep) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            this.productPriceMap = {};
            this.opportunityAmountCalcId = 0;

            this.listenTo(this.model, 'change:cProductServicesIds', function (m, v, o) {
                if (!o || !o.ui) {
                    return;
                }

                this.recalculateOpportunityAmount();
            });
        },

        /**
         * Sum the prices of the selected Product Services into `opportunityAmount`.
         * Runs only when the selection changes, so a manually typed amount is kept
         * until the selection is touched again.
         */
        recalculateOpportunityAmount: function () {
            var idList = this.model.get('cProductServicesIds') || [];

            var calcId = ++this.opportunityAmountCalcId;

            if (!idList.length) {
                this.model.set('opportunityAmount', null);

                return;
            }

            var unknownIdList = idList.filter(function (id) {
                return !Object.prototype.hasOwnProperty.call(this.productPriceMap, id);
            }, this);

            if (unknownIdList.length) {
                Espo.Ajax.getRequest('CProductService', {
                    select: 'id,price',
                    where: [{
                        attribute: 'id',
                        type: 'in',
                        value: unknownIdList
                    }],
                    maxSize: 100
                }).then(function (response) {
                    var list = (response || {}).list || [];

                    list.forEach(function (item) {
                        this.productPriceMap[item.id] = item.price;
                    }, this);

                    unknownIdList.forEach(function (id) {
                        if (!Object.prototype.hasOwnProperty.call(this.productPriceMap, id)) {
                            this.productPriceMap[id] = null;
                        }
                    }, this);

                    if (calcId === this.opportunityAmountCalcId) {
                        this.applyOpportunityAmount(idList);
                    }
                }.bind(this), function () {});

                return;
            }

            this.applyOpportunityAmount(idList);
        },

        applyOpportunityAmount: function (idList) {
            var amount = 0;

            idList.forEach(function (id) {
                var price = Number(this.productPriceMap[id]);

                if (!isNaN(price)) {
                    amount += price;
                }
            }, this);

            amount = Math.round(amount);

            if (this.model.get('opportunityAmount') !== amount) {
                this.model.set('opportunityAmount', amount);
            }
        }

    });

});
