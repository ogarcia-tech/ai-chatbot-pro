<?php
// Evitar el acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agrega el menú de opciones en el panel de administración.
 */
function aicp_add_admin_menu() {
    add_menu_page(
        'AI Chatbot Pro',
        'AI Chatbot',
        'manage_options',
        'ai-chatbot-pro',
        'aicp_settings_page_html',
        'dashicons-format-chat',
        80
    );
}
add_action('admin_menu', 'aicp_add_admin_menu');

/**
 * Registra los ajustes del plugin.
 */
function aicp_register_settings() {
    register_setting('aicp_settings_group', 'aicp_settings', 'aicp_sanitize_options');

    // Sección de Ajustes Generales
    add_settings_section(
        'aicp_general_settings_section',
        'Ajustes Generales',
        null,
        'ai-chatbot-pro'
    );

    add_settings_field('enable_chatbot', 'Activar Chatbot', 'aicp_enable_chatbot_render', 'ai-chatbot-pro', 'aicp_general_settings_section');
    add_settings_field('api_key', 'API Key de OpenAI', 'aicp_api_key_render', 'ai-chatbot-pro', 'aicp_general_settings_section');
    add_settings_field('model', 'Modelo de IA', 'aicp_model_render', 'ai-chatbot-pro', 'aicp_general_settings_section');

    // Sección de Instrucciones del Asistente
    add_settings_section(
        'aicp_instructions_section',
        'Instrucciones del Asistente (Prompt)',
        'aicp_instructions_section_callback',
        'ai-chatbot-pro'
    );

    add_settings_field('assistant_name', 'Nombre del Asistente', 'aicp_assistant_name_render', 'ai-chatbot-pro', 'aicp_instructions_section');
    add_settings_field('assistant_persona', 'Personalidad e Instrucciones', 'aicp_assistant_persona_render', 'ai-chatbot-pro', 'aicp_instructions_section');
    add_settings_field('first_message', 'Mensaje de Bienvenida', 'aicp_first_message_render', 'ai-chatbot-pro', 'aicp_instructions_section');

    // Sección de Diseño
     add_settings_section(
        'aicp_design_section',
        'Diseño del Chat',
        null,
        'ai-chatbot-pro'
    );
    add_settings_field('theme_color', 'Color del Tema', 'aicp_theme_color_render', 'ai-chatbot-pro', 'aicp_design_section');
}
add_action('admin_init', 'aicp_register_settings');

// Funciones de renderizado de campos
function aicp_enable_chatbot_render() {
    $options = get_option('aicp_settings');
    echo '<input type="checkbox" name="aicp_settings[enable_chatbot]" ' . checked(1, $options['enable_chatbot'] ?? 0, false) . ' value="1">';
    echo '<p class="description">Marca esta casilla para mostrar el chatbot en tu sitio web.</p>';
}

function aicp_api_key_render() {
    $options = get_option('aicp_settings');
    echo '<input type="password" name="aicp_settings[api_key]" value="' . esc_attr($options['api_key'] ?? '') . '" class="regular-text">';
}

function aicp_model_render() {
    $options = get_option('aicp_settings');
    $model = isset($options['model']) && isset(AICP_AVAILABLE_MODELS[$options['model']]) ? $options['model'] : array_key_first(AICP_AVAILABLE_MODELS);
    echo '<select name="aicp_settings[model]">';
    foreach (AICP_AVAILABLE_MODELS as $value => $label) {
        printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($model, $value, false), esc_html($label));
    }
    echo '</select>';
}

function aicp_instructions_section_callback() {
    echo '<p>Define aquí cómo se comportará tu asistente, qué dirá y cuál es su objetivo.</p>';
}

function aicp_assistant_name_render() {
    $options = get_option('aicp_settings');
    echo '<input type="text" name="aicp_settings[assistant_name]" value="' . esc_attr($options['assistant_name'] ?? 'Asistente IA') . '" class="regular-text">';
    echo '<p class="description">Este nombre aparecerá en la cabecera del chat.</p>';
}

function aicp_assistant_persona_render() {
    $options = get_option('aicp_settings');
    $content = $options['assistant_persona'] ?? 'Eres un asistente amable y servicial que ayuda a los usuarios con sus preguntas sobre nuestro sitio web.';
    echo '<textarea name="aicp_settings[assistant_persona]" rows="8" class="large-text">' . esc_textarea($content) . '</textarea>';
    echo '<p class="description">Sé muy específico. Por ejemplo: "Eres Ana, una experta en marketing digital. Tu objetivo es responder preguntas y animar a los usuarios a solicitar un presupuesto."</p>';
}

function aicp_first_message_render() {
    $options = get_option('aicp_settings');
    echo '<input type="text" name="aicp_settings[first_message]" value="' . esc_attr($options['first_message'] ?? '¡Hola! ¿En qué puedo ayudarte hoy?') . '" class="large-text">';
     echo '<p class="description">El primer mensaje que el chatbot mostrará al usuario cuando abra la ventana.</p>';
}

function aicp_theme_color_render() {
    $options = get_option('aicp_settings');
    $color = $options['theme_color'] ?? '#0073aa';
    echo '<input type="color" name="aicp_settings[theme_color]" value="' . esc_attr($color) . '">';
    echo '<p class="description">Elige el color principal para la cabecera y los botones del chat.</p>';
}


// Función de sanitización
function aicp_sanitize_options($input) {
    $sanitized_input = [];
    if (isset($input['enable_chatbot'])) {
        $sanitized_input['enable_chatbot'] = absint($input['enable_chatbot']);
    }
    if (isset($input['api_key'])) {
        $sanitized_input['api_key'] = sanitize_text_field($input['api_key']);
    }
    if (isset($input['model'])) {
        $model = sanitize_text_field($input['model']);
        $sanitized_input['model'] = array_key_exists($model, AICP_AVAILABLE_MODELS) ? $model : array_key_first(AICP_AVAILABLE_MODELS);
    }
    if (isset($input['assistant_name'])) {
        $sanitized_input['assistant_name'] = sanitize_text_field($input['assistant_name']);
    }
    if (isset($input['assistant_persona'])) {
        $sanitized_input['assistant_persona'] = sanitize_textarea_field($input['assistant_persona']);
    }
    if (isset($input['first_message'])) {
        $sanitized_input['first_message'] = sanitize_text_field($input['first_message']);
    }
     if (isset($input['theme_color'])) {
        $sanitized_input['theme_color'] = sanitize_hex_color($input['theme_color']);
    }
    return $sanitized_input;
}

/**
 * Dibuja el HTML de la página de ajustes.
 */
function aicp_settings_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <p>Gestiona la configuración de tu chatbot con inteligencia artificial.</p>

        <form action="options.php" method="post">
            <?php
            settings_fields('aicp_settings_group');
            do_settings_sections('ai-chatbot-pro');
            do_action('aicp_after_settings_fields'); 
            submit_button('Guardar Cambios');
            ?>
        </form>
    </div>
    <?php
}
