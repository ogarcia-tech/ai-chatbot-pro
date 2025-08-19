<?php
/**
 * Plugin Name: AI Chatbot Pro - Advanced Training
 * Description: Addon para AI Chatbot Pro que activa funcionalidades PRO como entrenamiento avanzado (RAG) y traspaso a humano.
 * Version: 1.2
 * Author: Óscar García
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', 'aicp_pro_init');

function aicp_pro_init() {
    if (!class_exists('AI_Chatbot_Pro')) {
        add_action('admin_notices', 'aicp_pro_admin_notice_missing_main_plugin');
        return;
    }

    require_once __DIR__ . '/includes/class-pro-features.php';
    require_once __DIR__ . '/includes/class-pro-ajax-handler.php';
    require_once __DIR__ . '/includes/class-pinecone-manager.php';
    
    AICP_Pro_Features::init();
    AICP_Pro_Ajax_Handler::init();
    
    add_action('admin_enqueue_scripts', 'aicp_pro_enqueue_admin_scripts');

    // Enganchamos la función de búsqueda de contexto al filtro del plugin principal.
    add_filter('aicp_get_context', 'aicp_pro_fetch_context_from_pinecone', 10, 3);
}

/**
 * Función que se ejecuta a través del filtro para obtener contexto desde Pinecone.
 */
function aicp_pro_fetch_context_from_pinecone($context, $user_query, $assistant_id) {
    if (empty($user_query) || empty($assistant_id)) {
        return $context;
    }
    // Llama a la nueva función de consulta en Pinecone Manager.
    $pinecone_context = AICP_Pinecone_Manager::query_pinecone($user_query, $assistant_id);

    return !is_wp_error($pinecone_context) && !empty($pinecone_context) ? $pinecone_context : $context;
}

function aicp_pro_enqueue_admin_scripts($hook) {
    global $post;
    if (($hook == 'post-new.php' || $hook == 'post.php') && isset($post) && $post->post_type === 'aicp_assistant') {
        $plugin_url = plugin_dir_url(__FILE__);
        wp_enqueue_script(
            'aicp-admin-pro-js',
            $plugin_url . 'assets/js/admin-pro.js',
            ['jquery'],
            '1.2', // Incrementar versión para evitar caché
            true
        );
        // Pasamos todos los parámetros necesarios al script de forma segura
        wp_localize_script('aicp-admin-pro-js', 'aicp_pro_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aicp_save_meta_box_data'),
            'assistant_id' => $post->ID,
        ]);
    }
}

function aicp_pro_admin_notice_missing_main_plugin() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('El addon "AI Chatbot Pro - Advanced Training" requiere que el plugin "AI Chatbot Pro" esté instalado y activo.', 'ai-chatbot-pro'); ?></p>
    </div>
    <?php
}
