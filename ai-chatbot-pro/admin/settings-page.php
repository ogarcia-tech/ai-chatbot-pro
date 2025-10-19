<?php
/**
 * Crea la página de ajustes generales del plugin.
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('ABSPATH')) exit;

/**
 * Agrega el submenú de ajustes generales.
 */
function aicp_add_settings_page() {
    add_submenu_page(
        'edit.php?post_type=aicp_assistant',
        __('Ajustes Generales', 'ai-chatbot-pro'),
        __('Ajustes', 'ai-chatbot-pro'),
        'manage_options',
        'aicp-settings',
        'aicp_render_settings_page'
    );
}
add_action('admin_menu', 'aicp_add_settings_page');

/**
 * Registra los ajustes.
 */
function aicp_register_general_settings() {
    register_setting('aicp_settings_group', 'aicp_settings', 'aicp_general_settings_sanitize');
    register_setting('aicp_settings_group', 'aicp_assistant_templates', 'aicp_assistant_templates_sanitize');
    add_settings_section('aicp_api_key_section', __('Ajustes de la API de OpenAI', 'ai-chatbot-pro'), null, 'aicp-settings');
    add_settings_field('aicp_api_key', __('API Key', 'ai-chatbot-pro'), 'aicp_api_key_field_render', 'aicp-settings', 'aicp_api_key_section');
    add_settings_section('aicp_templates_section', __('Plantillas del asistente', 'ai-chatbot-pro'), 'aicp_templates_section_render', 'aicp-settings');
    add_settings_field('aicp_template_editor', __('Plantillas disponibles', 'ai-chatbot-pro'), 'aicp_template_editor_field_render', 'aicp-settings', 'aicp_templates_section');
    do_action('aicp_after_settings_fields');
}
add_action('admin_init', 'aicp_register_general_settings');

/**
 * Renderiza el campo de la API Key.
 */
function aicp_api_key_field_render() {
    $options = get_option('aicp_settings');
    $api_key = isset($options['api_key']) ? esc_attr($options['api_key']) : '';
    echo '<input type="password" name="aicp_settings[api_key]" value="' . $api_key . '" class="regular-text" placeholder="' . __('Introduce tu clave API aquí', 'ai-chatbot-pro') . '">';
}

/**
 * Renderiza el campo de webhook de leads.
 *
 * @deprecated Este ajuste se gestiona ahora por asistente.
 */
function aicp_lead_webhook_url_field_render() {
    $options = get_option('aicp_settings');
    $url = isset($options['lead_webhook_url']) ? esc_attr($options['lead_webhook_url']) : '';
    echo '<input type="url" name="aicp_settings[lead_webhook_url]" value="' . $url . '" class="regular-text" placeholder="' . __('URL para enviar leads', 'ai-chatbot-pro') . '">';
}

/**
 * Renderiza la página de ajustes.
 */
function aicp_render_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php settings_fields('aicp_settings_group'); ?>
            <?php do_settings_sections('aicp-settings'); ?>
            <?php

        do_action('aicp_after_settings_fields');
        ?>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function aicp_templates_section_render() {
    echo '<p>' . esc_html__('Edita las plantillas base que se utilizan al crear nuevos asistentes. Usa el formato JSON y asegúrate de mantener los identificadores únicos.', 'ai-chatbot-pro') . '</p>';
}

function aicp_template_editor_field_render() {
    $stored_templates = get_option('aicp_assistant_templates');
    if (!is_array($stored_templates) || empty($stored_templates)) {
        if (function_exists('aicp_get_default_assistant_templates')) {
            $stored_templates = aicp_get_default_assistant_templates();
        } else {
            $stored_templates = [];
        }
    }

    $json = wp_json_encode($stored_templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    ?>
    <textarea name="aicp_assistant_templates" rows="15" class="large-text code" spellcheck="false"><?php echo esc_textarea($json); ?></textarea>
    <p class="description"><?php esc_html_e('Introduce un array de objetos con los campos id, label y system_prompt_template. Los datos se sanitizan automáticamente antes de guardarse.', 'ai-chatbot-pro'); ?></p>
    <?php
}

/**
 * Sanitiza las opciones de ajustes generales.
 */
function aicp_general_settings_sanitize($input) {
    $sanitized = [];

    // Guarda los ajustes del plugin principal
    if (isset($input['api_key'])) {
        $sanitized['api_key'] = sanitize_text_field($input['api_key']);
    }

    // Si el addon PRO está activo, le pasa los datos para que guarde los suyos.
    if (class_exists('AICP_Pro_Features')) {
        $sanitized = apply_filters('aicp_sanitize_pro_settings', $sanitized, $input);
    }

    return $sanitized;
}

function aicp_assistant_templates_sanitize($input) {
    $raw = $input;

    if (is_string($raw)) {
        $raw = wp_unslash($raw);
        $decoded = json_decode($raw, true);
    } elseif (is_array($raw)) {
        $decoded = $raw;
    } else {
        $decoded = [];
    }

    if (!is_array($decoded)) {
        add_settings_error('aicp_assistant_templates', 'aicp_templates_invalid_json', __('El JSON proporcionado no es válido.', 'ai-chatbot-pro'));
        return get_option('aicp_assistant_templates', []);
    }

    if (!function_exists('aicp_sanitize_assistant_templates_array')) {
        require_once AICP_PLUGIN_DIR . 'includes/template-functions.php';
    }

    $sanitized = aicp_sanitize_assistant_templates_array($decoded);

    if (empty($sanitized)) {
        add_settings_error('aicp_assistant_templates', 'aicp_templates_empty', __('No se pudo guardar ninguna plantilla válida. Revisa el formato y los campos obligatorios.', 'ai-chatbot-pro'));
        return get_option('aicp_assistant_templates', []);
    }

    if (function_exists('aicp_clear_assistant_templates_cache')) {
        aicp_clear_assistant_templates_cache();
    }

    return $sanitized;
}
