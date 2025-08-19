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
    add_settings_section('aicp_api_key_section', __('Ajustes de la API de OpenAI', 'ai-chatbot-pro'), null, 'aicp-settings');
    add_settings_field('aicp_api_key', __('API Key', 'ai-chatbot-pro'), 'aicp_api_key_field_render', 'aicp-settings', 'aicp_api_key_section');
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