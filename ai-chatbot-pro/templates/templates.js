"use strict";
var ASSISTANT_TEMPLATES = [];
function loadAssistantTemplates(url) {
    return fetch(url)
        .then(function (res) { return res.json(); })
        .then(function (data) {
        ASSISTANT_TEMPLATES = data;
        window.ASSISTANT_TEMPLATES = ASSISTANT_TEMPLATES;
        return ASSISTANT_TEMPLATES;
    });
}
function renderTemplate(template, data) {
    if (data === void 0) { data = {}; }
    return template.replace(/{{\s*([\w\.]+)\s*}}/g, function (_, key) {
        var parts = key.split('.');
        var value = data;
        for (var _i = 0, parts_1 = parts; _i < parts_1.length; _i++) {
            var part = parts_1[_i];
            value = value === null || value === void 0 ? void 0 : value[part];
            if (value === undefined || value === null)
                return '';
        }
        return String(value);
    });
}
window.ASSISTANT_TEMPLATES = ASSISTANT_TEMPLATES;
window.loadAssistantTemplates = loadAssistantTemplates;
window.renderTemplate = renderTemplate;
