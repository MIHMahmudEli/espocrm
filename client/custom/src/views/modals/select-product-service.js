define('custom:views/modals/select-product-service', ['views/modal', 'jquery'], function (Dep, $) {

    /**
     * Choose which of a lead's product services to put on a Call.
     *
     * The list it renders is passed in by the caller (the Call record view),
     * never fetched here, so only the product services of the lead whose
     * parent record was picked can ever be shown.
     */
    return Dep.extend({

        template: 'custom:modals/select-product-service',

        events: {
            'click a.add': function (e) {
                var id = $(e.currentTarget).attr('data-value');

                this.trigger('select', [id]);
            },
            'change .ps-checkbox': function (e) {
                var id = $(e.currentTarget).attr('data-value');

                if (e.currentTarget.checked) {
                    if (this.checkedList.indexOf(id) === -1) {
                        this.checkedList.push(id);
                    }
                } else {
                    var index = this.checkedList.indexOf(id);
                    if (index !== -1) {
                        this.checkedList.splice(index, 1);
                    }
                }

                if (this.checkedList.length) {
                    this.enableButton('select');
                } else {
                    this.disableButton('select');
                }
            }
        },

        setup: function () {
            this.optionList = this.options.optionList || [];
            this.checkedList = [];

            var leadName = this.options.leadName || '';

            this.headerText = this.translate('selectProductService', 'labels', 'CProductService');

            this.message = this.translate('selectProductServiceMessage', 'labels', 'CProductService');

            if (leadName) {
                this.message = this
                    .translate('selectProductServiceMessageFor', 'labels', 'CProductService')
                    .replace('{lead}', leadName);
            }

            this.buttonList = [
                {
                    name: 'select',
                    style: 'primary',
                    label: 'Select',
                    disabled: true,
                    onClick: function () {
                        this.trigger('select', this.checkedList.slice(0));
                    }.bind(this)
                },
                {
                    name: 'cancel',
                    label: 'Cancel'
                }
            ];
        },

        data: function () {
            return {
                optionList: this.optionList,
                message: this.message
            };
        }

    });

});
