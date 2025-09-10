<?php
if (!defined('ABSPATH')) exit;

class AICP_Prompt_Builder {
    private static $templates = null;

    private static function get_template($id) {
        if (self::$templates === null) {
            $file = AICP_PLUGIN_DIR . 'assistant_templates.json';
            if (file_exists($file)) {
                $data = json_decode(file_get_contents($file), true);
                self::$templates = is_array($data) ? $data : [];
            } else {
                self::$templates = [];
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
        // Si el usuario ha habilitado la edición manual, usamos ese prompt directamente.
        if (isset($settings['custom_prompt']) && !empty($settings['custom_prompt'])) {
            return $settings['custom_prompt'];
        }

        $parts = [];
        $template_id = $settings['template_id'] ?? '';
        
        // Cargar plantilla si existe
        if ($template_id) {
            $template = self::get_template($template_id);
            if ($template && !empty($template['system_prompt_template'])) {
                // Preparar datos para los placeholders de la plantilla
                $meta = [
                    'brand'          => function_exists('get_option') ? get_option('aicp_brand', '') : '',
                    'domain'         => function_exists('get_option') ? get_option('aicp_domain', '') : '',
                    'services'       => function_exists('get_option') ? implode(', ', (array) get_option('aicp_services', [])) : '',
                    'pricing_ranges' => function_exists('get_option') ? (array) get_option('aicp_pricing_ranges', []) : [],
                    'timezone'       => function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC',
                ];
                // Los placeholders de pricing_ranges necesitan un tratamiento especial en el template
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
 
        // Añadir campos individuales solo si la plantilla no está seleccionada o si queremos un sistema mixto
        // En este caso, la plantilla se considera el prompt principal y los campos son modificadores.
        // La lógica actual ya los une. Solo hay que asegurarse de que no dupliquen información.
        // Si la plantilla ya tiene una persona, la sobrescribimos con el campo manual.
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

        if (!empty($page_context)) {
            $parts[] = "--- INICIO DEL CONTEXTO DE LA PÁGINA ACTUAL ---\n" . $page_context . "\n--- FIN DEL CONTEXTO ---";
            $parts[] = 'Responde a las preguntas del usuario basándote en el contexto de la página proporcionado. Si la información no está en el contexto, indícalo amablemente.';
        }

        $prompt = implode("\n\n", $parts);
        if (empty($prompt)) {
            $prompt = 'Eres un asistente de IA.';
        }
        return $prompt;
    }
}