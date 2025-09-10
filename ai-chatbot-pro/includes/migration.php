<?php

require_once __DIR__ . '/template-functions.php';

$templates_path = dirname(__DIR__) . '/assistant_templates.json';
$templates = [];
if (file_exists($templates_path)) {
    $json = file_get_contents($templates_path);
    $templates = json_decode($json, true);
}

if (!function_exists('aicp_render_template') || empty($templates)) {
    return;
}

$template_key = isset($meta['template']) ? $meta['template'] : 'default';
$template = $templates[$template_key] ?? '';
$system_prompt = $template ? aicp_render_template($template, $meta) : '';
