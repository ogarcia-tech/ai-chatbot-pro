<?php
if (!defined('ABSPATH')) exit;

class AICP_Prompt_Builder {
    private static $templates = null;

    private static function get_template($id) {
        if (self::$templates === null) {
            if (function_exists('aicp_get_assistant_templates')) {
                self::$templates = aicp_get_assistant_templates();
            } else {
                $file = AICP_PLUGIN_DIR . 'assistant_templates.json';
                if (file_exists($file)) {
                    $data = json_decode(file_get_contents($file), true);
                    self::$templates = is_array($data) ? $data : [];
                } else {
                    self::$templates = [];
                }
            }
        }
        foreach (self::$templates as $tpl) {
            if (!empty($tpl['id']) && $tpl['id'] === $id) {
                return $tpl;
            }
        }
        return null;
    }

    public static function build($settings, $page_context = '') {
        $forwarding_enabled = !empty($settings['forward_to_webhook']);
        $behavior_rules     = isset($settings['behavior_rules']) ? trim($settings['behavior_rules']) : '';
        $append_behavior    = !$forwarding_enabled && $behavior_rules !== '';

        $prompt = '';

        if (isset($settings['custom_prompt']) && !empty($settings['use_custom_prompt'])) {
            $prompt = $settings['custom_prompt'];
        } else {
            $parts = [];
            $template_id = $settings['template_id'] ?? '';

            if ($template_id) {
                $template = self::get_template($template_id);
                if ($template && !empty($template['system_prompt_template'])) {
                    $meta = [
                        'brand'          => function_exists('get_option') ? get_option('aicp_brand', '') : '',
                        'domain'         => function_exists('get_option') ? get_option('aicp_domain', '') : '',
                        'services'       => function_exists('get_option') ? implode(', ', (array) get_option('aicp_services', [])) : '',
                        'pricing_ranges' => function_exists('get_option') ? (array) get_option('aicp_pricing_ranges', []) : [],
                        'timezone'       => function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC',
                    ];

                    foreach ($meta['pricing_ranges'] as $key => $value) {
                        $meta['pricing_ranges.' . $key] = $value;
                    }

                    if (function_exists('aicp_render_template')) {
                        $parts[] = aicp_render_template($template['system_prompt_template'], $meta);
                    } else {
                        $parts[] = $template['system_prompt_template'];
                    }
                }
            }

            if (!empty($settings['persona'])) {
                $parts[] = 'PERSONALIDAD: ' . $settings['persona'];
            }
            if (!empty($settings['objective'])) {
                $parts[] = 'OBJETIVO PRINCIPAL: ' . $settings['objective'];
            }
            if (!empty($settings['length_tone'])) {
                $parts[] = 'TONO Y LONGITUD: ' . $settings['length_tone'];
            }
            if (!empty($settings['example'])) {
                $parts[] = 'EJEMPLO DE RESPUESTA: ' . $settings['example'];
            }

            if (!empty($settings['lead_fields']) && is_array($settings['lead_fields'])) {
                $fields_desc = [];
                foreach ($settings['lead_fields'] as $field) {
                    $required = !empty($field['required']) ? '(Obligatorio)' : '(Opcional)';
                    $label    = $field['label'] ?? ($field['name'] ?? '');
                    if ($label === '') {
                        continue;
                    }
                    $fields_desc[] = "- {$label} {$required}";
                }

                if (!empty($fields_desc)) {
                    $parts[] = "INSTRUCCIÓN DE CAPTURA DE DATOS:\nDebes obtener la siguiente información del usuario de manera natural durante la conversación:\n"
                        . implode("\n", $fields_desc)
                        . "\n\nCuando obtengas estos datos, el sistema los detectará automáticamente. No menciones 'JSON' ni códigos internos al usuario, solo pide los datos.";
                }
            }

            if (!empty($page_context)) {
                $parts[] = "--- INICIO DEL CONTEXTO DE LA PÁGINA ACTUAL ---\n" . $page_context . "\n--- FIN DEL CONTEXTO ---";
                $parts[] = 'Responde a las preguntas del usuario basándote en el contexto de la página proporcionado. Si la información no está en el contexto, indícalo amablemente.';
            }

            $prompt = implode("\n\n", $parts);
        }

        $prompt = trim($prompt);

        if ($append_behavior) {
            $behavior_block = "== REGLAS DE COMPORTAMIENTO (Filtro Adicional) ==\n" . $behavior_rules;
            $prompt = $prompt === '' ? $behavior_block : $prompt . "\n\n" . $behavior_block;
        }

        if ($prompt === '') {
            $prompt = 'Eres un asistente de IA.';
        }

        return $prompt;
    }
}