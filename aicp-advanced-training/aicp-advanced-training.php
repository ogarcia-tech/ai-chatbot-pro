<?php
/**
 * Plugin Name: AI Chatbot Pro - Advanced Training
 * Description: Addon para AI Chatbot Pro que activa funcionalidades PRO como entrenamiento avanzado (RAG) y traspaso a humano.
 * Version: 1.0
 * Author: Óscar García
 */

if (!defined('ABSPATH')) exit;

/**
 * Carga las funcionalidades del addon PRO solo cuando WordPress se ha cargado
 * y después de que el plugin principal esté listo.
 */
add_action('plugins_loaded', 'aicp_pro_init');

function aicp_pro_init() {
    // Primero, nos aseguramos de que el plugin principal está activo.
    // 'AI_Chatbot_Pro' es el nombre de la clase principal de tu plugin base.
    if (!class_exists('AI_Chatbot_Pro')) {
        // Muestra un aviso en el panel de administración si el plugin base no está activo.
        add_action('admin_notices', 'aicp_pro_admin_notice_missing_main_plugin');
        return; // Detiene la carga del addon si el principal no está.
    }

    // Si el plugin principal sí está, cargamos los archivos del addon.
    require_once __DIR__ . '/includes/class-pro-features.php';
    require_once __DIR__ . '/includes/class-pro-ajax-handler.php';
    
    // "Arrancamos" cada pieza del addon.
    AICP_Pro_Features::init();
    AICP_Pro_Ajax_Handler::init();
    add_action('admin_enqueue_scripts', 'aicp_pro_enqueue_admin_scripts');
}
function aicp_pro_enqueue_admin_scripts($hook) {
    // Solo cargar el script en la página de edición de nuestro asistente
    if ($hook == 'post-new.php' || $hook == 'post.php') {
        global $post;
        if ($post && $post->post_type === 'aicp_assistant') {
            $plugin_url = plugin_dir_url(__FILE__);
            wp_enqueue_script(
                'aicp-admin-pro-js',
                $plugin_url . 'assets/js/admin-pro.js',
                ['jquery'],
                '1.0',
                true
            );
        }
    }
}
/**
 * Muestra un aviso en el panel de administración.
 */
function aicp_pro_admin_notice_missing_main_plugin() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('El addon "AI Chatbot Pro - Advanced Training" requiere que el plugin "AI Chatbot Pro" esté instalado y activo.', 'ai-chatbot-pro'); ?></p>
    </div>
    <?php
}