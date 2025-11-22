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
        if (!empty($settings['master_prompt'])) {
            return trim(wp_kses_post($settings['master_prompt']));
        }

        if (!empty($page_context)) {
            return trim("--- CONTEXTO ---\n" . $page_context);
        }

        return 'Eres un asistente de IA.';
    }
}