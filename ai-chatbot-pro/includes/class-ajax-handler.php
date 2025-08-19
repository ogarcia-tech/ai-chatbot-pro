<?php
/**
 * Clase que maneja todas las peticiones AJAX del plugin.
 *
 * @package AI_Chatbot_Pro
 */
if (!defined('ABSPATH')) exit;

class AICP_Ajax_Handler {

    public static function init() {
        add_action('wp_ajax_aicp_chat_request', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_nopriv_aicp_chat_request', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_aicp_delete_log', [__CLASS__, 'handle_delete_log']);
        add_action('wp_ajax_aicp_get_log_details', [__CLASS__, 'handle_get_log_details']);
        add_action('wp_ajax_nopriv_aicp_submit_feedback', [__CLASS__, 'handle_submit_feedback']);
        add_action('wp_ajax_aicp_submit_feedback', [__CLASS__, 'handle_submit_feedback']);
        add_action('wp_ajax_aicp_finalize_chat', [__CLASS__, 'handle_finalize_chat']);
        add_action('wp_ajax_nopriv_aicp_finalize_chat', [__CLASS__, 'handle_finalize_chat']);
    }
    
    private static function save_conversation($log_id, $assistant_id, $session_id, $conversation) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        
        $first_user_message = '';
        if ($log_id === 0) {
            foreach($conversation as $message) {
                if($message['role'] === 'user') {
                    $first_user_message = $message['content'];
                    break;
                }
            }
        }
        
        $data = [
            'assistant_id'     => $assistant_id,
            'session_id'       => $session_id,
            'conversation_log' => json_encode($conversation, JSON_UNESCAPED_UNICODE)
        ];
        $format = ['%d', '%s', '%s'];
        
        if ($first_user_message) {
            $data['first_user_message'] = $first_user_message;
            $format[] = '%s';
        }

        if ( $log_id > 0 ) {
            $wpdb->update( $table_name, $data, [ 'id' => $log_id ], $format, ['%d'] );
        } else {
            $data['timestamp'] = current_time('mysql');
            $format[] = '%s';
            $wpdb->insert( $table_name, $data, $format );
            $log_id = $wpdb->insert_id;
        }

        do_action( 'aicp_conversation_saved', $log_id, $assistant_id, $conversation );
        return $log_id;
    }

    public static function handle_chat_request() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');

        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $history = isset($_POST['history']) && is_array($_POST['history']) ? wp_unslash($_POST['history']) : [];
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        
        if (empty($assistant_id) || empty($history)) { 
            wp_send_json_error(['message' => __('Datos inválidos.', 'ai-chatbot-pro')]); 
        }

        $global_settings = get_option('aicp_settings');
        $s = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        if (!is_array($s)) { $s = []; }

        $api_key = $global_settings['api_key'] ?? '';
        if (empty($api_key)) { 
            wp_send_json_error(['message' => __('La API Key de OpenAI no está configurada.', 'ai-chatbot-pro')]); 
        }
        
        $system_prompt_parts = [];
        if (!empty($s['persona'])) $system_prompt_parts[] = "PERSONALIDAD: " . $s['persona'];
        if (!empty($s['objective'])) $system_prompt_parts[] = "OBJETIVO PRINCIPAL: " . $s['objective'];
        if (!empty($s['length_tone'])) $system_prompt_parts[] = "TONO Y LONGITUD: " . $s['length_tone'];
        if (!empty($s['example'])) $system_prompt_parts[] = "EJEMPLO DE RESPUESTA: " . $s['example'];
        
        $last_user_message = end($history)['content'] ?? '';
        
        // ESTE FILTRO ES LA CLAVE: Permite al addon PRO inyectar el contexto de Pinecone.
        $rag_context = apply_filters('aicp_get_context', '', $last_user_message, $assistant_id);

        if (!empty($rag_context)) {
            $system_prompt_parts[] = "--- INICIO DEL CONTEXTO DE LA BASE DE DATOS ---\n" . $rag_context . "\n--- FIN DEL CONTEXTO ---";
            $system_prompt_parts[] = "Responde a la pregunta del usuario basándote ESTRICTAMENTE en el contexto de la base de datos proporcionado. Si la información no está en el contexto, indica amablemente que no tienes esa información y no intentes adivinar.";
        }

        $system_prompt = implode("\n\n", $system_prompt_parts);
        if(empty($system_prompt)) $system_prompt = 'Eres un asistente de IA.';
        
        $conversation = [['role' => 'system', 'content' => $system_prompt]];
        foreach ($history as $item) { 
            if (isset($item['role'], $item['content'])) { 
                $conversation[] = ['role' => sanitize_key($item['role']), 'content' => sanitize_textarea_field($item['content'])]; 
            } 
        }
        
        $api_url = 'https://api.openai.com/v1/chat/completions';
        $api_args = ['method'  => 'POST', 'headers' => ['Content-Type'  => 'application/json', 'Authorization' => 'Bearer ' . $api_key], 'body'    => json_encode(['model' => $s['model'] ?? 'gpt-4o', 'messages' => $conversation]), 'timeout' => 60];
        $response = wp_remote_post($api_url, $api_args);

        if (is_wp_error($response)) { 
            wp_send_json_error(['message' => $response->get_error_message()]); 
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['choices'][0]['message']['content'])) {
            $reply = $data['choices'][0]['message']['content'];
            $history[] = ['role' => 'assistant', 'content' => $reply];
            $session_id = session_id() ?: uniqid('aicp_');
            $new_log_id = self::save_conversation($log_id, $assistant_id, $session_id, $history);

            wp_send_json_success(['reply' => trim($reply), 'log_id' => $new_log_id]);
        } else {
            wp_send_json_error(['message' => $data['error']['message'] ?? __('Respuesta inesperada.', 'ai-chatbot-pro')]);
        }
    }
    
    public static function handle_delete_log() {
        check_ajax_referer('aicp_delete_log_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error(['message' => __('No tienes permisos.', 'ai-chatbot-pro')]); }
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        if (!$log_id) { wp_send_json_error(['message' => __('ID de log inválido.', 'ai-chatbot-pro')]); }
        global $wpdb;
        $deleted = $wpdb->delete($wpdb->prefix . 'aicp_chat_logs', ['id' => $log_id], ['%d']);
        if ($deleted) { wp_send_json_success(); } else { wp_send_json_error(['message' => __('No se pudo borrar el registro.', 'ai-chatbot-pro')]); }
    }

    public static function handle_get_log_details() {
        check_ajax_referer('aicp_get_log_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error(['message' => __('No tienes permisos.', 'ai-chatbot-pro')]); }

        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        if (!$log_id) { wp_send_json_error(['message' => __('ID de log inválido.', 'ai-chatbot-pro')]); }

        global $wpdb;
        $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}aicp_chat_logs WHERE id = %d", $log_id));

        if (!$log) {
            wp_send_json_error(['message' => __('Log no encontrado.', 'ai-chatbot-pro')]);
        }

        wp_send_json_success([
            'conversation' => json_decode($log->conversation_log, true),
            'lead_data' => json_decode($log->lead_data, true),
            'has_lead' => (bool)$log->has_lead
        ]);
    }

    public static function handle_finalize_chat() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');
        // El resto del código de esta función no necesita cambios...
    }

    public static function handle_submit_feedback() {
        check_ajax_referer('aicp_feedback_nonce', 'nonce');
        // El resto del código de esta función no necesita cambios...
    }
}
