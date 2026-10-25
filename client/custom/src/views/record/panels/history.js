define('custom:views/record/panels/history', ['crm:views/record/panels/history'], function (Dep) {

    return Dep.extend({

        setup: function () {
            this.listLayout = {
                'Email': {
                    rows: [[
                        { name: 'ico', view: 'crm:views/fields/ico' },
                        { name: 'name', link: true }
                    ], [
                        { name: 'dateSent', soft: true },
                        { name: 'from' },
                        { name: 'hasAttachment', view: 'views/email/fields/has-attachment' }
                    ]]
                },
                'Call': {
                    rows: [[
                        { name: 'ico', view: 'crm:views/fields/ico' },
                        { name: 'name', link: true, view: 'views/event/fields/name-for-history' }
                    ], [
                        { name: 'dateStart', soft: true },
                        { name: 'assignedUser' },
                        { name: 'status', soft: true }
                    ], [
                        { name: 'cDescriptionDiff', view: 'custom:views/fields/description-change' }
                    ]]
                },
                'Meeting': {
                    rows: [[
                        { name: 'ico', view: 'crm:views/fields/ico' },
                        { name: 'name', link: true, view: 'views/event/fields/name-for-history' }
                    ], [
                        { name: 'dateStart', soft: true },
                        { name: 'assignedUser' },
                        { name: 'status', soft: true }
                    ], [
                        { name: 'cDescriptionDiff', view: 'custom:views/fields/description-change' }
                    ]]
                }
            };

            Dep.prototype.setup.call(this);

            this.activitiesUrl =
                this.serviceName + '/' + this.model.entityType + '/' + this.model.id + '/activities';

            this.applyProductServiceFilter();
            this.setupTypedCollectionSupport();
            this.setupAutoRefresh();
        },

        /**
         * Narrow the history list down to the product service(s) of the record
         * this panel is shown on. Records without a product-service field keep
         * the unfiltered history.
         */
        applyProductServiceFilter: function () {
            if (!this.collection) {
                return;
            }

            var idList = this.model.get('cProductServicesIds');

            if (Array.isArray(idList) && idList.length) {
                this.collection.data.productServiceIds = idList.join(',');
            } else {
                delete this.collection.data.productServiceIds;
            }
        },

        /**
         * The stock panel only refetches on `update-related:*`/`update-all`, and
         * `update-all` requires an `X-Record-Link-Updated` header. Saving the parent
         * record itself leaves the history list stale.
         */
        setupAutoRefresh: function () {
            this.listenTo(this.model, 'after:save', function () {
                if (this.historyRefreshTimer) {
                    clearTimeout(this.historyRefreshTimer);
                }

                this.historyRefreshTimer = setTimeout(function () {
                    this.historyRefreshTimer = null;

                    if (!this.collection) {
                        return;
                    }

                    this.applyProductServiceFilter();
                    this.collection.fetch();
                }.bind(this), 150);
            }.bind(this));
        },

        onRemove: function () {
            if (this.historyRefreshTimer) {
                clearTimeout(this.historyRefreshTimer);
                this.historyRefreshTimer = null;
            }

            if (Dep.prototype.onRemove) {
                Dep.prototype.onRemove.call(this);
            }
        },

        /**
         * The typed activities endpoint (…/history/list/Call) does not return the
         * `_scope` attribute, which MultiCollection requires to build row models.
         */
        setupTypedCollectionSupport: function () {
            var collection = this.collection;
            var prepareAttributes = collection.prepareAttributes;

            collection.prepareAttributes = function (response, options) {
                if (response && Array.isArray(response.list)) {
                    response.list.forEach(function (attributes) {
                        if (!attributes._scope) {
                            attributes._scope = 'Call';
                        }
                    });
                }

                return prepareAttributes.call(this, response, options);
            };
        },

        isNotReceivedFilterAvailable: function () {
            if (!this.getAcl().checkScope('Call')) {
                return false;
            }

            if (this.getMetadata().get(['scopes', this.model.entityType, 'activity'])) {
                return false;
            }

            return true;
        },

        setupFilterActions: function () {
            var available = this.isNotReceivedFilterAvailable();

            this.filterList = ['all'];

            if (available) {
                this.filterList.push('notReceived');
                this.filterList.push('planned');
            }

            var filter = this.getStoredFilter();

            if (filter === 'notReceived' && !available) {
                filter = null;
            }

            if (filter === 'planned' && !available) {
                filter = null;
            }

            this.filter = filter;

            Dep.prototype.setupFilterActions.call(this);
        },

        translateFilter: function (name) {
            if (name === 'notReceived') {
                return this.getLanguage().translateOption('Not Received', 'status', 'Call');
            }

            if (name === 'planned') {
                return this.getLanguage().translateOption('Planned', 'status', 'Call');
            }

            return Dep.prototype.translateFilter.call(this, name);
        },

        setFilter: function (filter) {
            this.filter = filter;

            this.collection.data.entityType = null;
            this.collection.where = [];
            this.collection.url = this.url;

            if (filter === 'notReceived') {
                this.collection.url = this.url + '/list/Call';
                this.collection.where = [{
                    attribute: 'status',
                    type: 'equals',
                    value: 'Not Received'
                }];

                return;
            }

            if (filter === 'planned') {
                this.collection.url = this.activitiesUrl + '/list/Call';
                this.collection.where = [{
                    attribute: 'status',
                    type: 'equals',
                    value: 'Planned'
                }];
            }
        }
    });

});
