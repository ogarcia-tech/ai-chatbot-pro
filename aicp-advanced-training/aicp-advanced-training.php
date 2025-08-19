<?php
/**
 * Plugin Name: AI Chatbot Pro - Advanced Training
 * Description: Addon para AI Chatbot Pro que activa funcionalidades PRO como entrenamiento avanzado (RAG) y traspaso a humano.
 * Version: 1.0
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
    require_once __DIR__ . '/includes/class-pinecone-manager.php'; // Asegurarse de que se carga
    
    AICP_Pro_Features::init();
    AICP_Pro_Ajax_Handler::init();
    
    add_action('admin_enqueue_scripts', 'aicp_pro_enqueue_admin_scripts');

    // --- INICIO DE LA MODIFICACIÓN ---
    // Enganchamos la función de búsqueda de contexto al filtro del plugin principal.
    add_filter('aicp_get_context', 'aicp_pro_fetch_context_from_pinecone', 10, 3);
    // --- FIN DE LA MODIFICACIÓN ---
}

// --- INICIO DE LA NUEVA FUNCIÓN ---
/**
 * Función que se ejecuta a través del filtro para obtener contexto desde Pinecone.
 */
function aicp_pro_fetch_context_from_pinecone($context, $user_query, $assistant_id) {
    // Llama a la nueva función de consulta en Pinecone Manager.
    $pinecone_context = AICP_Pinecone_Manager::query_pinecone($user_query, $assistant_id);

    // Si Pinecone devuelve un contexto, lo usamos. Si no, devolvemos el contexto original (vacío).
    return !is_wp_error($pinecone_context) && !empty($pinecone_context) ? $pinecone_context : $context;
}
// --- FIN DE LA NUEVA FUNCIÓN ---

function aicp_pro_enqueue_admin_scripts($hook) {
    // ... (código original sin cambios) ...
}

function aicp_pro_admin_notice_missing_main_plugin() {
    // ... (código original sin cambios) ...
}
