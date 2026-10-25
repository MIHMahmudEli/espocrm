define('custom:views/fields/description-change', ['custom:views/fields/description-preview'], function (Dep) {

    return Dep.extend({

        /**
         * The History row must show what actually changed on the last edit of
         * `description`, not the whole value. `cDescriptionDiff` is filled in by
         * the `Espo\Custom\Hooks\Call\DescriptionDiff` before-save hook. Records
         * that have never been edited since that hook existed fall back to the
         * first-line preview of the full description.
         */
        getValueForDisplay: function () {
            if (!this.isReadMode()) {
                return Dep.prototype.getValueForDisplay.call(this);
            }

            var changed = this.model.get('cDescriptionDiff');

            if (changed) {
                return String(changed);
            }

            var description = this.model.get('description');

            if (!description) {
                return '';
            }

            return this.buildPreview(description);
        },

        data: function () {
            var data = Dep.prototype.data.call(this);

            // `views/fields/text` derives `isNotEmpty` from `this.name`
            // (cDescriptionDiff), which is empty whenever we fall back to the
            // description preview. Base it on the value actually rendered.
            if (this.isReadMode() && data.value) {
                data.isNotEmpty = true;
            }

            // Render the changed lines verbatim. `complexText` would run the
            // value through markdown, which joins single newlines into one
            // paragraph and hides which lines actually changed.
            if (this.isReadMode()) {
                data.displayRawText = true;
            }

            return data;
        }

    });

});
