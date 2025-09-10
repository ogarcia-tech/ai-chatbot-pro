interface AssistantTemplate {
  id: string;
  label: string;
  description: string;
  variables: string[];
  system_prompt_template: string;
  quick_replies: string[];
  examples_by_intent?: { [key: string]: string };
  persona?: string;
  objective?: string;
  length_tone?: string;
  example?: string;
}

let ASSISTANT_TEMPLATES: AssistantTemplate[] = [];

function loadAssistantTemplates(url: string): Promise<AssistantTemplate[]> {
  return fetch(url)
    .then(res => res.json())
    .then((data: AssistantTemplate[]) => {
      ASSISTANT_TEMPLATES = data;
      (window as any).ASSISTANT_TEMPLATES = ASSISTANT_TEMPLATES;
      return ASSISTANT_TEMPLATES;
    });
}

function renderTemplate(template: string, data: Record<string, any> = {}): string {
  return template.replace(/{{\s*([\w\.]+)\s*}}/g, (_, key) => {
    const parts = key.split('.');
    let value: any = data;
    for (const part of parts) {
      value = value?.[part];
      if (value === undefined || value === null) return '';
    }
    return String(value);
  });
}

(window as any).ASSISTANT_TEMPLATES = ASSISTANT_TEMPLATES;
(window as any).loadAssistantTemplates = loadAssistantTemplates;
(window as any).renderTemplate = renderTemplate;
