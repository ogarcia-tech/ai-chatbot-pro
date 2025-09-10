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
        add_action('wp_ajax_aicp_submit_lead_form', [__CLASS__, 'handle_submit_lead_form']);
        add_action('wp_ajax_nopriv_aicp_submit_lead_form', [__CLASS__, 'handle_submit_lead_form']);
        add_action('wp_ajax_aicp_finalize_chat', [__CLASS__, 'handle_finalize_chat']);
        add_action('wp_ajax_nopriv_aicp_finalize_chat', [__CLASS__, 'handle_finalize_chat']);
    }
    
    private static function save_conversation($log_id, $assistant_id, $session_id, $conversation, $lead_data = []) {
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
            'timestamp'        => current_time('mysql'),
            'conversation_log' => wp_json_encode($conversation, JSON_UNESCAPED_UNICODE)
        ];
        $format = ['%d', '%s', '%s', '%s'];
        
        if ($first_user_message) {
            $data['first_user_message'] = $first_user_message;
            $format[] = '%s';
        }

        if (!empty($lead_data)) {
            $data['has_lead'] = 1;
            $data['lead_data'] = wp_json_encode($lead_data, JSON_UNESCAPED_UNICODE);
            $format[] = '%d';
            $format[] = '%s';
        }


        if ( $log_id > 0 ) {
            $wpdb->update( $table_name, $data, [ 'id' => $log_id ], $format, ['%d'] );
        } else {
            $wpdb->insert( $table_name, $data, $format );
            $log_id = $wpdb->insert_id;
        }

        // Se dispara la acción para que el Lead Manager procese la conversación
        do_action( 'aicp_conversation_saved', $log_id, $assistant_id, $conversation );

        return $log_id;
    }

    public static function handle_chat_request() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');

        // --- INICIO DE LA MODIFICACIÓN ---
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $history = isset($_POST['history']) && is_array($_POST['history']) ? wp_unslash($_POST['history']) : [];
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        // Se añade la recepción del contexto de la página
        $page_context = isset($_POST['page_context']) ? sanitize_textarea_field(wp_unslash($_POST['page_context'])) : '';

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

        $system_prompt = AICP_Prompt_Builder::build($s, $page_context);

        
        $short_term_memory = array_slice($history, -10);
        $conversation = [['role' => 'system', 'content' => $system_prompt]];
        foreach ($short_term_memory as $item) { 
            if (isset($item['role'], $item['content'])) { 
                $conversation[] = ['role' => sanitize_key($item['role']), 'content' => sanitize_textarea_field($item['content'])]; 
            } 
        }
        
        $api_url = 'https://api.openai.com/v1/chat/completions';
        $model = $s['model'] ?? array_key_first(AICP_AVAILABLE_MODELS);
        if (!isset(AICP_AVAILABLE_MODELS[$model])) {
            $model = array_key_first(AICP_AVAILABLE_MODELS);
        }
        $api_args = [
            'method'  => 'POST',
            'headers' => ['Content-Type'  => 'application/json', 'Authorization' => 'Bearer ' . $api_key],
            'body'    => wp_json_encode(['model' => $model, 'messages' => $conversation]),
            'timeout' => 60,
        ];
        $response = wp_remote_post($api_url, $api_args);

        if (is_wp_error($response)) { 
            wp_send_json_error(['message' => $response->get_error_message()]); 
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['choices'][0]['message']['content'])) {
            $reply = $data['choices'][0]['message']['content'];

            $full_history = $history;
            $full_history[] = ['role' => 'assistant', 'content' => $reply];

            // Llamada al Lead Manager para detectar información de contacto.
            $lead_info = AICP_Lead_Manager::detect_contact_data($full_history);

            $session_id = session_id() ?: uniqid('aicp_');
            // La función save_conversation ahora se encarga de llamar a la acción para procesar el lead.
            $new_log_id = self::save_conversation($log_id, $assistant_id, $session_id, $full_history, $lead_info['data']);

            wp_send_json_success([
                'reply'          => trim($reply),
                'log_id'         => $new_log_id,
                'lead_status'    => $lead_info['is_complete'] ? 'complete' : ($lead_info['has_lead'] ? 'partial' : 'none'),
                'missing_fields' => $lead_info['missing_fields'],
            ]);
        } else {
            wp_send_json_error(['message' => $data['error']['message'] ?? __('Respuesta inesperada.', 'ai-chatbot-pro')]);
        }
        // --- FIN DE LA MODIFICACIÓN ---
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

        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $log_id       = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $conversation = isset($_POST['conversation']) && is_array($_POST['conversation']) ? wp_unslash($_POST['conversation']) : [];

        if (!$assistant_id || empty($conversation)) {
            wp_send_json_error(['message' => __('Datos inválidos.', 'ai-chatbot-pro')]);
        }

        $global_settings = get_option('aicp_settings');
        $api_key = $global_settings['api_key'] ?? '';
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('La API Key de OpenAI no está configurada.', 'ai-chatbot-pro')]);
        }

        $conversation_text = '';
        foreach ($conversation as $msg) {
            $role = isset($msg['role']) ? strtoupper($msg['role']) : 'USER';
            $content = isset($msg['content']) ? $msg['content'] : '';
            $conversation_text .= "$role: $content\n";
        }

        $prompt = 'Extrae nombre, email y teléfono del siguiente chat y responde en formato JSON {"name":"","email":"","phone":""}:\n' . $conversation_text;

        $api_url = 'https://api.openai.com/v1/chat/completions';
        $api_args = [
            'method'  => 'POST',
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => wp_json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ]
            ]),
            'timeout' => 60,
        ];

        $response = wp_remote_post($api_url, $api_args);

        $lead_data = [];
        $lead_status = 'failed';

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (isset($data['choices'][0]['message']['content'])) {
                $parsed = json_decode($data['choices'][0]['message']['content'], true);
                if (is_array($parsed)) {
                    $lead_data = [
                        'name'  => $parsed['name']  ?? '',
                        'email' => $parsed['email'] ?? '',
                        'phone' => $parsed['phone'] ?? ''
                    ];
                    if (!empty($lead_data['email']) || !empty($lead_data['phone'])) {
                        $lead_status = 'complete';
                    }
                }
            }
        }

        $session_id = session_id() ?: uniqid('aicp_');
        $new_log_id = self::save_conversation($log_id, $assistant_id, $session_id, $conversation);

        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        $wpdb->update(
            $table_name,
            [
                'has_lead'   => $lead_status === 'complete' ? 1 : 0,
                'lead_data'  => wp_json_encode($lead_data, JSON_UNESCAPED_UNICODE),
                'lead_status'=> $lead_status
            ],
            ['id' => $new_log_id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        do_action('aicp_lead_detected', $lead_data, $assistant_id, $new_log_id, $lead_status);

        wp_send_json_success(['status' => $lead_status]);
    }

    public static function handle_submit_feedback() {
        check_ajax_referer('aicp_feedback_nonce', 'nonce');
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $feedback = isset($_POST['feedback']) ? intval($_POST['feedback']) : 0;

        if (!$log_id || !in_array($feedback, [1, -1])) {
            wp_send_json_error(['message' => 'Datos inválidos.']);
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        $updated = $wpdb->update($table_name, ['feedback' => $feedback], ['id' => $log_id], ['%d'], ['%d']);

        if ($updated !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => 'No se pudo guardar el feedback.']);
        }
    }

    public static function handle_submit_lead_form() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $answers = isset($_POST['answers']) && is_array($_POST['answers']) ? array_map('sanitize_text_field', $_POST['answers']) : [];

        if (!$assistant_id || empty($answers)) {
            wp_send_json_error(['message' => __('Datos incompletos.', 'ai-chatbot-pro')]);
        }

        do_action('aicp_lead_detected', $answers, $assistant_id, 0, 'form');

        wp_send_json_success(['message' => __('Formulario enviado', 'ai-chatbot-pro')]);
    }
}