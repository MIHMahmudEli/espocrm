define('custom:handlers/lead/defaults-preparator', ['handlers/model/defaults-preparator'], function (Dep) {

    /**
     * Prefills Assigned User on the Lead create form with the person who
     * opened it.
     *
     * EspoCRM already has this behaviour built in, but it is switched off for
     * a user whose preference "Do not fill assigned user if not required" is
     * on, and Lead's `assignedUser` field is not required. Rather than change
     * that preference for every scope, this preparator does it for Lead only.
     *
     * It is wired up through `modelDefaultsPreparator` in the custom Lead
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
