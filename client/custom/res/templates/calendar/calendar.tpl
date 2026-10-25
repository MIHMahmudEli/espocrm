{{#if header}}
<div class="row button-container calendar-toolbar">
    <div class="col-sm-4 col-xs-5 calendar-toolbar-nav">
        <div class="btn-group range-switch-group">
            <button
                class="btn btn-text btn-icon"
                data-action="prev"
                title="{{translate 'Previous'}}"
                aria-label="{{translate 'Previous'}}"
            ><span class="fas fa-chevron-left" aria-hidden="true"></span></button>
            <button
                class="btn btn-text btn-icon"
                data-action="next"
                title="{{translate 'Next'}}"
                aria-label="{{translate 'Next'}}"
            ><span class="fas fa-chevron-right" aria-hidden="true"></span></button>
        </div>
        <div class="btn-group range-switch-group">
            <button
                class="btn btn-text strong"
                data-action="today"
                title="{{todayLabel}}"
                aria-label="{{todayLabel}}"
            >
                <span class="hidden-sm hidden-xs">{{todayLabel}}</span><span class="visible-sm visible-xs">{{todayLabelShort}}</span>
            </button>
        </div>

        <button
            class="btn btn-text{{#unless isCustomView}} hidden{{/unless}} btn-icon"
            data-action="editCustomView"
            title="{{translate 'Edit'}}"
            aria-label="{{translate 'Edit'}}"
        ><span class="fas fa-pencil-alt fa-sm" aria-hidden="true"></span></button>
    </div>

    <div class="date-title col-sm-4 col-xs-7 calendar-toolbar-title">
        <h4>
            <span
                style="cursor: pointer;"
                data-action="refresh"
                role="button"
                tabindex="0"
                title="{{translate 'Refresh'}}"
                aria-label="{{translate 'Refresh'}}"
            ></span>
        </h4>
    </div>

    <div class="col-sm-4 col-xs-12 calendar-toolbar-modes">
        <div class="btn-group pull-right mode-buttons">
            {{{modeButtons}}}
        </div>
    </div>
</div>
{{/if}}

<div class="calendar"></div>
