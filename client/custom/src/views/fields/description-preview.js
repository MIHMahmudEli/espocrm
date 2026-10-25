define('custom:views/fields/description-preview', ['views/fields/text'], function (Dep) {

    return Dep.extend({

        seeMoreDisabled: true,

        maxPreviewLength: 100,

        getValueForDisplay: function () {
            var value = Dep.prototype.getValueForDisplay.call(this);

            if (!value || !this.isReadMode()) {
                return value;
            }

            return this.buildPreview(value);
        },

        buildPreview: function (value) {
            var lines = String(value).split(/\r?\n/);

            var line = '';

            for (var i = 0; i < lines.length; i++) {
                var trimmed = lines[i].trim();

                if (trimmed) {
                    line = trimmed;
                    break;
                }
            }

            if (!line || line.length <= this.maxPreviewLength) {
                return line;
            }

            var cut = line.substring(0, this.maxPreviewLength);
            var boundary = cut.lastIndexOf(' ');

            if (boundary >= 20) {
                cut = cut.substring(0, boundary);
            }

            cut = cut.replace(/[\s.,;:!?"'\)\]]+$/, '');

            return cut + '…';
        }
    });

});
