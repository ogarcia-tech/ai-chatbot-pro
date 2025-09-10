"use strict";
var ASSISTANT_TEMPLATES = {
    greeting: '<p>Hola, {{name}}!</p>'
};
function renderTemplate(name, data) {
    if (data === void 0) { data = {}; }
    var template = ASSISTANT_TEMPLATES[name] || '';
    return template.replace(/{{\s*(\w+)\s*}}/g, function (_, key) { var _a; return String((_a = data[key]) !== null && _a !== void 0 ? _a : ''); });
}
window.ASSISTANT_TEMPLATES = ASSISTANT_TEMPLATES;
window.renderTemplate = renderTemplate;
