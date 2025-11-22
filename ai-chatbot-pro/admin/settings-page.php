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
    register_setting('aicp_settings_group', 'aicp_model_providers', 'aicp_model_providers_sanitize');
    add_settings_section('aicp_provider_section', __('Modelos y Proveedores', 'ai-chatbot-pro'), null, 'aicp-settings');
    add_settings_field('aicp_provider_openai', __('OpenAI / ChatGPT', 'ai-chatbot-pro'), 'aicp_provider_openai_render', 'aicp-settings', 'aicp_provider_section');
    add_settings_field('aicp_provider_gemini', __('Google Gemini', 'ai-chatbot-pro'), 'aicp_provider_gemini_render', 'aicp-settings', 'aicp_provider_section');
    add_settings_field('aicp_provider_custom', __('Otros proveedores', 'ai-chatbot-pro'), 'aicp_provider_custom_render', 'aicp-settings', 'aicp_provider_section');
    do_action('aicp_after_settings_fields');
}
add_action('admin_init', 'aicp_register_general_settings');

/**
 * Renderiza el campo de la API Key.
 */
function aicp_provider_openai_render() {
    if (!class_exists('AICP_Crypto_Helper')) {
        require_once AICP_PLUGIN_DIR . 'includes/class-crypto-helper.php';
    }
    $settings = get_option('aicp_model_providers', []);
    $config   = $settings['openai'] ?? [];
    $api_key  = isset($config['api_key']) ? AICP_Crypto_Helper::decrypt($config['api_key']) : '';
    $model    = esc_attr($config['model'] ?? 'gpt-4o-mini');
    $base_url = esc_url($config['base_url'] ?? '');
    ?>
    <p><label for="aicp_openai_api_key"><?php _e('API Key', 'ai-chatbot-pro'); ?></label><br>
    <input type="password" name="aicp_model_providers[openai][api_key]" id="aicp_openai_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="sk-..." /></p>
    <p><label for="aicp_openai_model"><?php _e('Modelo', 'ai-chatbot-pro'); ?></label><br>
    <input type="text" name="aicp_model_providers[openai][model]" id="aicp_openai_model" value="<?php echo $model; ?>" class="regular-text" placeholder="gpt-4o" /></p>
    <p><label for="aicp_openai_base_url"><?php _e('URL alternativa', 'ai-chatbot-pro'); ?></label><br>
    <input type="url" name="aicp_model_providers[openai][base_url]" id="aicp_openai_base_url" value="<?php echo $base_url; ?>" class="regular-text" placeholder="https://tu-proxy/v1/chat/completions" /></p>
    <?php
}

function aicp_provider_gemini_render() {
    if (!class_exists('AICP_Crypto_Helper')) {
        require_once AICP_PLUGIN_DIR . 'includes/class-crypto-helper.php';
    }
    $settings = get_option('aicp_model_providers', []);
    $config   = $settings['gemini'] ?? [];
    $api_key  = isset($config['api_key']) ? AICP_Crypto_Helper::decrypt($config['api_key']) : '';
    $model    = esc_attr($config['model'] ?? '');
    $endpoint = esc_url($config['endpoint'] ?? '');
    ?>
    <p><label for="aicp_gemini_api_key"><?php _e('API Key', 'ai-chatbot-pro'); ?></label><br>
    <input type="password" name="aicp_model_providers[gemini][api_key]" id="aicp_gemini_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" /></p>
    <p><label for="aicp_gemini_model"><?php _e('Modelo', 'ai-chatbot-pro'); ?></label><br>
    <input type="text" name="aicp_model_providers[gemini][model]" id="aicp_gemini_model" value="<?php echo $model; ?>" class="regular-text" placeholder="gemini-1.5-pro" /></p>
    <p><label for="aicp_gemini_endpoint"><?php _e('Ruta de endpoint', 'ai-chatbot-pro'); ?></label><br>
    <input type="url" name="aicp_model_providers[gemini][endpoint]" id="aicp_gemini_endpoint" value="<?php echo $endpoint; ?>" class="regular-text" placeholder="https://generativelanguage.googleapis.com/v1beta/" /></p>
    <?php
}

function aicp_provider_custom_render() {
    if (!class_exists('AICP_Crypto_Helper')) {
        require_once AICP_PLUGIN_DIR . 'includes/class-crypto-helper.php';
    }
    $settings   = get_option('aicp_model_providers', []);
    $config     = $settings['custom'] ?? [];
    $api_key    = isset($config['api_key']) ? AICP_Crypto_Helper::decrypt($config['api_key']) : '';
    $model      = esc_attr($config['model'] ?? '');
    $endpoint   = esc_url($config['endpoint'] ?? '');
    $temperature = esc_attr($config['temperature'] ?? '');
    $max_tokens  = esc_attr($config['max_tokens'] ?? '');
    ?>
    <p><label for="aicp_custom_api_key"><?php _e('API Key', 'ai-chatbot-pro'); ?></label><br>
    <input type="password" name="aicp_model_providers[custom][api_key]" id="aicp_custom_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" /></p>
    <p><label for="aicp_custom_endpoint"><?php _e('Endpoint', 'ai-chatbot-pro'); ?></label><br>
    <input type="url" name="aicp_model_providers[custom][endpoint]" id="aicp_custom_endpoint" value="<?php echo $endpoint; ?>" class="regular-text" placeholder="https://mi-api.chat" /></p>
    <p><label for="aicp_custom_model"><?php _e('Modelo', 'ai-chatbot-pro'); ?></label><br>
    <input type="text" name="aicp_model_providers[custom][model]" id="aicp_custom_model" value="<?php echo $model; ?>" class="regular-text" /></p>
    <p><label for="aicp_custom_temperature"><?php _e('Temperatura (opcional)', 'ai-chatbot-pro'); ?></label><br>
    <input type="number" step="0.1" name="aicp_model_providers[custom][temperature]" id="aicp_custom_temperature" value="<?php echo $temperature; ?>" class="small-text" /></p>
    <p><label for="aicp_custom_max_tokens"><?php _e('Máx. tokens (opcional)', 'ai-chatbot-pro'); ?></label><br>
    <input type="number" name="aicp_model_providers[custom][max_tokens]" id="aicp_custom_max_tokens" value="<?php echo $max_tokens; ?>" class="small-text" /></p>
    <?php
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

    // Si el addon PRO está activo, le pasa los datos para que guarde los suyos.
    if (class_exists('AICP_Pro_Features')) {
        $sanitized = apply_filters('aicp_sanitize_pro_settings', $sanitized, $input);
    }

    return $sanitized;
}

function aicp_model_providers_sanitize($input) {
    if (!class_exists('AICP_Crypto_Helper')) {
        require_once AICP_PLUGIN_DIR . 'includes/class-crypto-helper.php';
    }
    $sanitized = [
        'openai' => [
            'api_key' => isset($input['openai']['api_key']) ? AICP_Crypto_Helper::encrypt(sanitize_text_field($input['openai']['api_key'])) : '',
            'model'   => isset($input['openai']['model']) ? sanitize_text_field($input['openai']['model']) : '',
            'base_url'=> isset($input['openai']['base_url']) ? esc_url_raw($input['openai']['base_url']) : '',
        ],
        'gemini' => [
            'api_key'  => isset($input['gemini']['api_key']) ? AICP_Crypto_Helper::encrypt(sanitize_text_field($input['gemini']['api_key'])) : '',
            'model'    => isset($input['gemini']['model']) ? sanitize_text_field($input['gemini']['model']) : '',
            'endpoint' => isset($input['gemini']['endpoint']) ? esc_url_raw($input['gemini']['endpoint']) : '',
        ],
        'custom' => [
            'api_key'     => isset($input['custom']['api_key']) ? AICP_Crypto_Helper::encrypt(sanitize_text_field($input['custom']['api_key'])) : '',
            'endpoint'    => isset($input['custom']['endpoint']) ? esc_url_raw($input['custom']['endpoint']) : '',
            'model'       => isset($input['custom']['model']) ? sanitize_text_field($input['custom']['model']) : '',
            'temperature' => isset($input['custom']['temperature']) ? floatval($input['custom']['temperature']) : '',
            'max_tokens'  => isset($input['custom']['max_tokens']) ? intval($input['custom']['max_tokens']) : '',
        ],
    ];

    return $sanitized;
}
