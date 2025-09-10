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
