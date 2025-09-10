interface TemplatesMap { [key: string]: string; }

const ASSISTANT_TEMPLATES: TemplatesMap = {
  greeting: '<p>Hola, {{name}}!</p>'
};

function renderTemplate(name: string, data: Record<string, any> = {}): string {
  const template = ASSISTANT_TEMPLATES[name] || '';
  return template.replace(/{{\s*(\w+)\s*}}/g, (_, key) => String(data[key] ?? ''));
}

(window as any).ASSISTANT_TEMPLATES = ASSISTANT_TEMPLATES;
(window as any).renderTemplate = renderTemplate;
