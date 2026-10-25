{{#unless noEdit}}
<div class="pull-right right-container cell-buttons">
{{{right}}}
</div>
{{/unless}}

<div class="stream-head-container">
    <div class="pull-left">
        {{{avatar}}}
    </div>
    <div class="stream-head-text-container">
        {{#if iconHtml}}{{{iconHtml}}}{{/if}}<span class="text-muted message">{{{message}}}</span>
    </div>
</div>

{{#if statusText}}
    <div class="stream-post-container">
        <span class="label label-state label-{{statusStyle}}">{{statusText}}</span>
    </div>
{{/if}}

{{#if descriptionText}}
    <div class="stream-description-container" style="margin-top: 6px; padding: 6px 10px; background: #f5f5f5; border-left: 3px solid #ccc; border-radius: 2px;">
        <span style="color: #666; font-size: 12px;">{{{descriptionText}}}</span>
    </div>
{{/if}}

<div class="stream-date-container">
    <a class="text-muted small" href="#Note/view/{{model.id}}">{{{createdAt}}}</a>
</div>
