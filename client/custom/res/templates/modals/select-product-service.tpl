<div class="margin-top margin-bottom-2x text-muted">{{message}}</div>

{{#unless optionList}}
    <div>{{translate 'No Data'}}</div>
{{/unless}}

{{#if optionList}}
    <ul class="list-group list-group-panel array-add-list-group no-side-margin">
        {{#each optionList}}
            <li class="list-group-item clearfix" data-name="{{id}}">
                <input
                    class="cell form-checkbox form-checkbox-small ps-checkbox"
                    type="checkbox"
                    data-value="{{id}}"
                >
                <a
                    role="button"
                    tabindex="0"
                    class="add text-bold"
                    data-value="{{id}}"
                >{{name}}</a>
            </li>
        {{/each}}
    </ul>
{{/if}}
