<?php
if (!defined('ABSPATH')) exit;

class AICP_Pro_Ajax_Handler {

    public static function init() {
        add_action('wp_ajax_aicp_check_api_keys', [__CLASS__, 'handle_check_api_keys']);
        add_action('wp_ajax_aicp_start_sync', [__CLASS__, 'handle_start_sync']);
        // Reemplaza la acción de chat del plugin principal
        add_action('wp_ajax_aicp_chat_request', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_nopriv_aicp_chat_request', [__CLASS__, 'handle_chat_request']);
    }

    public static function handle_check_api_keys() {
        if (!current_user_can('manage_options') || !check_ajax_referer('aicp_global_settings_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Fallo de seguridad.']);
        }
        $result = AICP_OpenAI_Assistants_Manager::check_api_connection();
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => '¡Conexión con OpenAI exitosa!']);
    }

    public static function handle_start_sync() {
        if (!current_user_can('edit_posts') || !check_ajax_referer('aicp_save_meta_box_data', 'nonce', false)) {
            wp_send_json_error(['message' => 'Fallo de seguridad.']);
        }
        @set_time_limit(300); // 5 minutos de tiempo de ejecución
        AICP_OpenAI_Assistants_Manager::handle_sync_request();
    }

    public static function handle_chat_request() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $history = isset($_POST['history']) && is_array($_POST['history']) ? wp_unslash($_POST['history']) : [];
        
        if (empty($assistant_id) || empty($history)) {
            wp_send_json_error(['message' => 'Datos inválidos.']);
        }

        $user_message = end($history)['content'];
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : 'sess_' . uniqid();

        $response = AICP_OpenAI_Assistants_Manager::handle_chat($assistant_id, $user_message, $session_id);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        
        wp_send_json_success(['reply' => $response, 'session_id' => $session_id]);
    }
}