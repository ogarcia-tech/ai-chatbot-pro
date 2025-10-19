<?php

if (!function_exists('aicp_render_template')) {
    /**
     * Render a string template by replacing {{placeholders}} with metadata values.
     *
     * @param string $tpl  The template string containing placeholders.
     * @param array  $meta Associative array of values used for interpolation.
     *
     * @return string The interpolated template.
     */
    function aicp_render_template($tpl, $meta)
    {
        return preg_replace_callback('/{{\s*(.+?)\s*}}/', function ($matches) use ($meta) {
            $key = $matches[1];
            return array_key_exists($key, $meta) ? $meta[$key] : '';
        }, $tpl);
    }
}

if (!function_exists('aicp_clear_assistant_templates_cache')) {
    /**
     * Removes the transient cache for assistant templates so the latest data is used.
     */
    function aicp_clear_assistant_templates_cache()
    {
        delete_transient('aicp_assistant_templates_cache');
    }
}

if (!function_exists('aicp_normalize_assistant_template')) {
    /**
     * Normalizes and sanitizes the structure of an assistant template entry.
     *
     * @param array $template Raw template data.
     *
     * @return array|null Sanitized template or null if invalid.
     */
    function aicp_normalize_assistant_template($template)
    {
        if (!is_array($template)) {
            return null;
        }

        $id = isset($template['id']) ? sanitize_key($template['id']) : '';
        $label = isset($template['label']) ? sanitize_text_field($template['label']) : '';
        $system_prompt = isset($template['system_prompt_template']) ? sanitize_textarea_field($template['system_prompt_template']) : '';

        if ('' === $id || '' === $label || '' === $system_prompt) {
            return null;
        }

        $normalized = [
            'id'                     => $id,
            'label'                  => $label,
            'description'            => isset($template['description']) ? sanitize_text_field($template['description']) : '',
            'variables'              => [],
            'system_prompt_template' => $system_prompt,
        ];

        if (!empty($template['variables']) && is_array($template['variables'])) {
            $normalized['variables'] = array_values(array_filter(array_map('sanitize_key', $template['variables'])));
        }

        $text_fields = ['persona', 'objective', 'length_tone', 'example'];
        foreach ($text_fields as $field) {
            if (isset($template[$field])) {
                $normalized[$field] = sanitize_textarea_field($template[$field]);
            }
        }

        if (!empty($template['quick_replies']) && is_array($template['quick_replies'])) {
            $normalized['quick_replies'] = array_values(array_filter(array_map('sanitize_text_field', $template['quick_replies'])));
        }

        if (!empty($template['examples_by_intent']) && is_array($template['examples_by_intent'])) {
            $examples = [];
            foreach ($template['examples_by_intent'] as $key => $value) {
                $normalized_key = sanitize_key($key);
                if ($normalized_key === '') {
                    continue;
                }
                $examples[$normalized_key] = sanitize_textarea_field($value);
            }
            if (!empty($examples)) {
                $normalized['examples_by_intent'] = $examples;
            }
        }

        return $normalized;
    }
}

if (!function_exists('aicp_sanitize_assistant_templates_array')) {
    /**
     * Sanitizes an array of assistant templates.
     *
     * @param array $templates Raw templates array.
     *
     * @return array Sanitized array of templates.
     */
    function aicp_sanitize_assistant_templates_array($templates)
    {
        if (!is_array($templates)) {
            return [];
        }

        $sanitized = [];
        foreach ($templates as $template) {
            $normalized = aicp_normalize_assistant_template($template);
            if ($normalized) {
                $sanitized[$normalized['id']] = $normalized;
            }
        }

        return array_values($sanitized);
    }
}

if (!function_exists('aicp_get_default_assistant_templates')) {
    /**
     * Loads the default assistant templates from the bundled JSON file.
     *
     * @return array
     */
    function aicp_get_default_assistant_templates()
    {
        $file = trailingslashit(AICP_PLUGIN_DIR) . 'assistant_templates.json';
        if (!file_exists($file)) {
            return [];
        }

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }

        return aicp_sanitize_assistant_templates_array($data);
    }
}

if (!function_exists('aicp_get_assistant_templates')) {
    /**
     * Retrieves the assistant templates, either from the stored option or the default file.
     * Results are cached using transients for performance.
     *
     * @param bool $use_cache Whether to use the cached value.
     *
     * @return array
     */
    function aicp_get_assistant_templates($use_cache = true)
    {
        $cache_key = 'aicp_assistant_templates_cache';

        if ($use_cache) {
            $cached = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }

        $stored = get_option('aicp_assistant_templates');
        if (is_array($stored) && !empty($stored)) {
            $templates = aicp_sanitize_assistant_templates_array($stored);
        } else {
            $templates = aicp_get_default_assistant_templates();
        }

        set_transient($cache_key, $templates, DAY_IN_SECONDS);

        return $templates;
    }
}
