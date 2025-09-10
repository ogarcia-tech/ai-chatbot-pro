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
        // Si el usuario ha habilitado la edición manual, o si es el nuevo sistema, usamos el custom_prompt directamente.
        if (isset($settings['custom_prompt']) && !empty($settings['custom_prompt'])) {
            $prompt = $settings['custom_prompt'];
        } else {
             // Si no hay custom_prompt, construimos un prompt básico
            $parts = [];
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
            $prompt = implode("\n\n", $parts);
        }

        if (!empty($page_context)) {
            $prompt .= "\n\n--- INICIO DEL CONTEXTO DE LA PÁGINA ACTUAL ---\n" . $page_context . "\n--- FIN DEL CONTEXTO ---\n";
            $prompt .= 'Responde a las preguntas del usuario basándote en el contexto de la página proporcionado. Si la información no está en el contexto, indícalo amablemente.';
        }

        if (empty($prompt)) {
            $prompt = 'Eres un asistente de IA.';
        }
        
        return $prompt;
    }
}