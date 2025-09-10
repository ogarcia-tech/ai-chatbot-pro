<?php
// Minimal WordPress stubs
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('AICP_PLUGIN_DIR')) {
    define('AICP_PLUGIN_DIR', __DIR__ . '/../ai-chatbot-pro/');
}
function get_option($name, $default = '') {
    $options = [
        'aicp_brand' => 'ACME',
        'aicp_domain' => 'example.com',
        'aicp_services' => ['web','seo'],
        'aicp_pricing_ranges' => ['web' => '$1000'],
    ];
    return $options[$name] ?? $default;
}
function wp_timezone_string() { return 'UTC'; }
function sanitize_text_field($v) { return $v; }
function sanitize_textarea_field($v) { return $v; }

require_once AICP_PLUGIN_DIR . 'includes/template-functions.php';
require_once AICP_PLUGIN_DIR . 'includes/class-prompt-builder.php';

// Test without template
$settings = [
    'persona' => 'Soy Ana',
    'objective' => 'Ayudar',
    'length_tone' => 'Amable',
    'example' => 'Ejemplo',
];
$prompt = AICP_Prompt_Builder::build($settings, 'Contexto');
assert(str_contains($prompt, 'PERSONALIDAD: Soy Ana'));
assert(str_contains($prompt, 'OBJETIVO PRINCIPAL: Ayudar'));
assert(str_contains($prompt, '--- INICIO DEL CONTEXTO DE LA PÁGINA ACTUAL ---'));

// Test with template
$settings2 = [
    'template_id' => 'ecommerce_support_upsell',
];
$prompt2 = AICP_Prompt_Builder::build($settings2);
assert(str_contains($prompt2, 'Eres asistente de ACME para e-commerce'));

echo "All tests passed\n";
