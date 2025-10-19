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
        add_action('wp_ajax_aicp_get_templates', [__CLASS__, 'handle_get_templates']);
        add_action('wp_ajax_nopriv_aicp_get_templates', [__CLASS__, 'handle_get_templates']);
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

    private static function ensure_session_id($session_id = '') {
        $session_id = is_string($session_id) ? sanitize_text_field($session_id) : '';

        if (empty($session_id)) {
            $session_id = 'aicp_' . wp_generate_uuid4();
        }

        return $session_id;
    }

    private static function get_client_ip() {
        $keys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($keys as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $ip_list = explode(',', wp_unslash($_SERVER[$key]));
            $candidate = trim($ip_list[0]);

            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }

    private static function sanitize_lead_payload($lead_input) {
        $sanitized = [];

        if (!is_array($lead_input)) {
            return $sanitized;
        }

        foreach ($lead_input as $key => $value) {
            if (is_scalar($value)) {
                $sanitized[sanitize_key($key)] = sanitize_text_field($value);
            }
        }

        return $sanitized;
    }

    private static function call_message_webhook($assistant_id, $settings, $session_id, $conversation, $page_context, $lead_data, $system_prompt) {
        $webhook_url = isset($settings['forward_webhook_url']) ? esc_url_raw($settings['forward_webhook_url']) : '';

        if (empty($webhook_url)) {
            return new WP_Error('invalid_webhook', __('No se ha configurado una URL de webhook válida.', 'ai-chatbot-pro'));
        }

        $last_user_message = '';
        for ($i = count($conversation) - 1; $i >= 0; $i--) {
            if (isset($conversation[$i]['role']) && 'user' === $conversation[$i]['role']) {
                $last_user_message = $conversation[$i]['content'] ?? '';
                break;
            }
        }

        $payload = [
            'conversation_id' => $session_id,
            'assistant_id'    => $assistant_id,
            'message'         => $last_user_message,
            'history'         => $conversation,
            'system_prompt'   => $system_prompt,
            'site'            => [
                'url'  => home_url(),
                'name' => get_bloginfo('name'),
            ],
            'meta'            => [
                'page_context' => $page_context,
                'lead_data'    => $lead_data,
            ],
        ];

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($settings['forward_webhook_secret'])) {
            $headers['X-AICP-Webhook-Secret'] = sanitize_text_field($settings['forward_webhook_secret']);
        }

        $timeout = isset($settings['forward_webhook_timeout']) ? max(5, intval($settings['forward_webhook_timeout'])) : 15;

        $response = wp_remote_post($webhook_url, [
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
            'timeout' => $timeout,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status >= 400) {
            return new WP_Error(
                'webhook_http_error',
                sprintf(__('El webhook devolvió un código HTTP %d.', 'ai-chatbot-pro'), $status)
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return new WP_Error('webhook_invalid_json', __('El webhook respondió con un JSON inválido.', 'ai-chatbot-pro'));
        }

        if (!isset($data['reply']) || !is_string($data['reply']) || '' === trim($data['reply'])) {
            return new WP_Error('webhook_missing_reply', __('El webhook no devolvió el campo "reply" con el texto de respuesta.', 'ai-chatbot-pro'));
        }

        $result = [
            'reply'     => sanitize_textarea_field($data['reply']),
            'metadata'  => [],
        ];

        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $result['metadata'] = $data['metadata'];
        }

        return $result;
    }

    public static function handle_get_templates() {
        if (!function_exists('aicp_get_assistant_templates')) {
            require_once AICP_PLUGIN_DIR . 'includes/template-functions.php';
        }

        $templates = aicp_get_assistant_templates(false);
        wp_send_json($templates);
    }

    public static function handle_chat_request() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');

        // --- INICIO DE LA MODIFICACIÓN ---
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        if (empty($assistant_id)) {
            wp_send_json_error(['message' => __('Datos inválidos.', 'ai-chatbot-pro')]);
        }

        $history = isset($_POST['history']) && is_array($_POST['history']) ? wp_unslash($_POST['history']) : [];
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        // Se añade la recepción del contexto de la página
        $page_context = isset($_POST['page_context']) ? sanitize_textarea_field(wp_unslash($_POST['page_context'])) : '';

        $incoming_session_id = isset($_POST['session_id']) ? wp_unslash($_POST['session_id']) : '';
        $session_id = self::ensure_session_id($incoming_session_id);
        $client_ip = self::get_client_ip();

        if (!empty($history)) {
            $history = AICP_Session_Memory::persist($session_id, $client_ip, $history, $assistant_id);
        } else {
            $history = AICP_Session_Memory::load($session_id, $client_ip, $assistant_id);
        }

        if (empty($assistant_id) || empty($history)) {
            wp_send_json_error(['message' => __('Datos inválidos.', 'ai-chatbot-pro')]);
        }

        $global_settings = get_option('aicp_settings');
        $s = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        if (!is_array($s)) { $s = []; }

        $lead_payload = [];
        if (isset($_POST['lead_data'])) {
            $lead_payload = self::sanitize_lead_payload(wp_unslash($_POST['lead_data']));
            $lead_payload = AICP_Lead_Manager::sanitize_lead_input($lead_payload, $assistant_id, $s);
        }

        $use_webhook = !empty($s['forward_to_webhook']) && !empty($s['forward_webhook_url']);

        $system_prompt = AICP_Prompt_Builder::build($s, $page_context);

        $short_term_memory = $history;
        $conversation = [];
        if ('' !== trim($system_prompt)) {
            $conversation[] = [
                'role'    => 'system',
                'content' => $system_prompt,
            ];
        }
        foreach ($short_term_memory as $item) {
            if (isset($item['role'], $item['content'])) {
                $conversation[] = ['role' => sanitize_key($item['role']), 'content' => sanitize_textarea_field($item['content'])];
            }
        }

        $reply = '';
        $metadata = [];

        if ($use_webhook) {
            $webhook_result = self::call_message_webhook($assistant_id, $s, $session_id, $conversation, $page_context, $lead_payload, $system_prompt);

            if (is_wp_error($webhook_result)) {
                wp_send_json_error(['message' => $webhook_result->get_error_message()]);
            }

            $reply    = $webhook_result['reply'];
            $metadata = $webhook_result['metadata'] ?? [];
        } else {
            $api_key = $global_settings['api_key'] ?? '';
            if (empty($api_key)) {
                wp_send_json_error(['message' => __('La API Key de OpenAI no está configurada.', 'ai-chatbot-pro')]);
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

            if (!isset($data['choices'][0]['message']['content'])) {
                wp_send_json_error(['message' => $data['error']['message'] ?? __('Respuesta inesperada.', 'ai-chatbot-pro')]);
            }

            $reply = $data['choices'][0]['message']['content'];
        }

        $reply = trim($reply);
        if ('' === $reply) {
            wp_send_json_error(['message' => __('La respuesta generada está vacía.', 'ai-chatbot-pro')]);
        }

        $full_history = $history;
        $full_history[] = ['role' => 'assistant', 'content' => $reply];

        AICP_Session_Memory::persist($session_id, $client_ip, $full_history, $assistant_id);

        $lead_info = AICP_Lead_Manager::detect_contact_data($full_history, $assistant_id, $s);

        $lead_payload = $lead_info['is_complete'] ? $lead_info['data'] : [];
        $new_log_id = self::save_conversation($log_id, $assistant_id, $session_id, $full_history, $lead_payload);

        $response_payload = [
            'reply'          => $reply,
            'log_id'         => $new_log_id,
            'lead_status'    => $lead_info['is_complete'] ? 'complete' : ($lead_info['has_lead'] ? 'partial' : 'none'),
            'missing_fields' => $lead_info['missing_fields'],
            'session_id'     => $session_id,
        ];

        if (!empty($metadata)) {
            $response_payload['webhook_metadata'] = $metadata;
        }

        wp_send_json_success($response_payload);
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
        if (empty($assistant_id)) {
            wp_send_json_error(['message' => __('Datos inválidos.', 'ai-chatbot-pro')]);
        }

        $log_id       = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $conversation = isset($_POST['conversation']) && is_array($_POST['conversation']) ? wp_unslash($_POST['conversation']) : [];
        $incoming_session_id = isset($_POST['session_id']) ? wp_unslash($_POST['session_id']) : '';
        $session_id = self::ensure_session_id($incoming_session_id);
        $client_ip = self::get_client_ip();

        if (!empty($conversation)) {
            $conversation = AICP_Session_Memory::persist($session_id, $client_ip, $conversation, $assistant_id);
        } else {
            $conversation = AICP_Session_Memory::load($session_id, $client_ip, $assistant_id);
        }

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
                    $missing_fields = AICP_Lead_Manager::get_missing_fields($lead_data, $assistant_id);
                    if (empty($missing_fields)) {
                        $lead_status = 'complete';
                    }
                }
            }
        }

        $new_log_id = self::save_conversation($log_id, $assistant_id, $session_id, $conversation);

        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        $wpdb->update(
            $table_name,
            [
                'has_lead'    => $lead_status === 'complete' ? 1 : 0,
                'lead_data'   => wp_json_encode($lead_data, JSON_UNESCAPED_UNICODE),
                'lead_status' => $lead_status
            ],
            ['id' => $new_log_id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        do_action('aicp_lead_detected', $lead_data, $assistant_id, $new_log_id, $lead_status);

        $response = ['status' => $lead_status];
        if ('complete' !== $lead_status) {
            $response['missing_fields'] = $missing_fields ?? AICP_Lead_Manager::get_missing_fields($lead_data, $assistant_id);
        }

        wp_send_json_success($response);
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

        $missing_fields = AICP_Lead_Manager::get_missing_fields($answers, $assistant_id);
        $lead_status    = empty($missing_fields) ? 'complete' : 'incomplete';

        if ($lead_status === 'complete') {
            do_action('aicp_lead_detected', $answers, $assistant_id, 0, 'complete');
        }

        $response = [
            'message' => __('Formulario enviado', 'ai-chatbot-pro'),
            'status'  => $lead_status,
        ];

        if ($lead_status !== 'complete') {
            $response['missing_fields'] = $missing_fields;
        }

        wp_send_json_success($response);
    }
}