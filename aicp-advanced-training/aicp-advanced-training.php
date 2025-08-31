<?php
/**
 * Plugin Name: AI Chatbot Pro - Advanced Training
 * Description: Addon para AI Chatbot Pro que usa la API de Asistentes de OpenAI para un entrenamiento avanzado.
 * Version: 2.0
 * Author: Óscar García
 */

if (!defined('ABSPATH')) exit;

// Se asegura de que el addon se cargue después del plugin principal
add_action('plugins_loaded', 'aicp_pro_init');

function aicp_pro_init() {
    // Comprueba si el plugin principal está activo
    if (!class_exists('AI_Chatbot_Pro')) {
        add_action('admin_notices', 'aicp_pro_admin_notice_missing_main_plugin');
        return;
    }

    // Carga de los componentes del addon
    require_once __DIR__ . '/includes/class-pro-features.php';
    require_once __DIR__ . '/includes/class-pro-ajax-handler.php';
    require_once __DIR__ . '/includes/class-openai-assistants-manager.php';

    // Inicia las clases
    AICP_Pro_Features::init();
    AICP_Pro_Ajax_Handler::init();

    // Carga los scripts necesarios en el panel de administración
    add_action('admin_enqueue_scripts', 'aicp_pro_enqueue_admin_scripts');

    // Esta es la clave: Si el addon está activo, se desactiva la función de chat del plugin base
    // para que la del addon tome el control total usando la API de Asistentes.
    remove_action('wp_ajax_aicp_chat_request', ['AICP_Ajax_Handler', 'handle_chat_request']);
    remove_action('wp_ajax_nopriv_aicp_chat_request', ['AICP_Ajax_Handler', 'handle_chat_request']);
}

/**
 * Carga los scripts de JavaScript en las páginas de administración correctas.
 */
function aicp_pro_enqueue_admin_scripts($hook) {
    global $post;

    $is_assistant_edit_page = ($hook == 'post-new.php' || $hook == 'post.php') && isset($post) && $post->post_type === 'aicp_assistant';
    $is_settings_page = ($hook === 'aicp_assistant_page_aicp-settings');

    if ($is_assistant_edit_page || $is_settings_page) {
        $plugin_url = plugin_dir_url(__FILE__);
        wp_enqueue_script(
            'aicp-admin-pro-js',
            $plugin_url . 'assets/js/admin-pro.js',
            ['jquery'],
            '2.0', // Versión del script
            true
        );

        // Prepara y pasa variables de PHP a JavaScript de forma segura
        $params = [ 'ajax_url' => admin_url('admin-ajax.php') ];
        if ($is_assistant_edit_page) {
            $params['assistant_id'] = $post->ID;
            $params['nonce'] = wp_create_nonce('aicp_save_meta_box_data');
        } else {
            $params['nonce'] = wp_create_nonce('aicp_global_settings_nonce');
        }
        wp_localize_script('aicp-admin-pro-js', 'aicp_pro_params', $params);
    }
}

/**
 * Muestra un aviso si el plugin principal no está activo.
 */
function aicp_pro_admin_notice_missing_main_plugin() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('El addon "Advanced Training" requiere el plugin "AI Chatbot Pro" para funcionar.', 'ai-chatbot-pro'); ?></p>
    </div>
    <?php
}