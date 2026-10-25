define('custom:handlers/defaults-preparator', ['handlers/model/defaults-preparator'], function (Dep) {

    /**
     * Prefills Assigned User on the create forms of Lead, Account, Call,
     * Meeting and CProductService with the person who opened them.
     *
     * EspoCRM already has this behaviour built in, but it is switched off for
     * a user whose preference "Do not fill assigned user if not required" is
     * on, and the field is not required on these scopes. Rather than change
     * that preference for every scope, this preparator does it for the five
     * scopes that wire it up through `modelDefaultsPreparator` in their
     * clientDefs. `helpers/model/defaults-populator` runs it when a *new*
     * model is created and applies the result with `set(..., {silent: true})`,
     * so it never touches a record that is being edited. Values coming from
     * the URL are applied afterwards and still win.
     *
     * It must never reject: the create form awaits it.
     */
    return class extends Dep {

        prepare(model) {
            try {
                if (!model || typeof model.hasField !== 'function' || !model.hasField('assignedUser')) {
                    return {};
                }

                if (model.get('assignedUserId')) {
                    return {};
                }

                var viewHelper = this.viewHelper;

                if (!viewHelper) {
                    return {};
                }

                // `view-helper` itself has no `getUser()`; `View.getUser()`
                // reads `this._helper.user`. Accept either shape.
                var user = typeof viewHelper.getUser === 'function' ?
                    viewHelper.getUser() : viewHelper.user;

                if (!user || !user.id || typeof user.get !== 'function') {
                    return {};
                }

                return {
                    assignedUserId: user.id,
                    assignedUserName: user.get('name')
                };
            } catch (e) {
                return {};
            }
        }

    };

});
