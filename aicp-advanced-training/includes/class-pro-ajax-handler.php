<?php
if (!defined('ABSPATH')) exit;

class AICP_Pro_Ajax_Handler {

    private static function save_conversation($log_id, $assistant_id, $session_id, $conversation, $lead_data = []) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';

        $first_user_message = '';
        if ($log_id === 0) {
            foreach ($conversation as $message) {
                if (isset($message['role'], $message['content']) && $message['role'] === 'user') {
                    $first_user_message = $message['content'];
                    break;
                }
            }
        }

        $data = [
            'assistant_id'     => $assistant_id,
            'session_id'       => $session_id,
            'timestamp'        => current_time('mysql'),
            'conversation_log' => wp_json_encode($conversation, JSON_UNESCAPED_UNICODE),
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

        if ($log_id > 0) {
            $wpdb->update($table_name, $data, ['id' => $log_id], $format, ['%d']);
        } else {
            $wpdb->insert($table_name, $data, $format);
            $log_id = $wpdb->insert_id;
        }

        do_action('aicp_conversation_saved', $log_id, $assistant_id, $conversation);

        return $log_id;
    }

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
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $page_context = isset($_POST['page_context']) ? sanitize_textarea_field(wp_unslash($_POST['page_context'])) : '';
        $incoming_session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';

        if (empty($assistant_id) || empty($history)) {
            wp_send_json_error(['message' => __('Datos inválidos.', 'ai-chatbot-pro')]);
        }

        $global_settings = get_option('aicp_settings');
        $api_key = $global_settings['api_key'] ?? '';
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('La API Key de OpenAI no está configurada.', 'ai-chatbot-pro')]);
        }

        $assistant_settings = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        if (!is_array($assistant_settings)) {
            $assistant_settings = [];
        }

        $system_prompt = AICP_Prompt_Builder::build($assistant_settings, $page_context);

        $short_term_history = array_slice($history, -10);
        $conversation_payload = [['role' => 'system', 'content' => $system_prompt]];
        foreach ($short_term_history as $item) {
            if (isset($item['role'], $item['content'])) {
                $conversation_payload[] = [
                    'role' => sanitize_key($item['role']),
                    'content' => sanitize_textarea_field($item['content']),
                ];
            }
        }

        $user_message = end($short_term_history)['content'] ?? '';
        if ($user_message === '') {
            wp_send_json_error(['message' => __('Datos inválidos.', 'ai-chatbot-pro')]);
        }

        $session_id = $incoming_session_id;
        $assistant_response = AICP_OpenAI_Assistants_Manager::handle_chat($assistant_id, $user_message, $session_id);
        $reply = '';
        if (is_wp_error($assistant_response)) {
            if ($assistant_response->get_error_code() === 'config_error') {
                $session_id = $session_id ?: 'aicp_' . wp_generate_uuid4();
                $api_url = 'https://api.openai.com/v1/chat/completions';
                $model = $assistant_settings['model'] ?? array_key_first(AICP_AVAILABLE_MODELS);
                if (!isset(AICP_AVAILABLE_MODELS[$model])) {
                    $model = array_key_first(AICP_AVAILABLE_MODELS);
                }
                $api_args = [
                    'method'  => 'POST',
                    'headers' => [
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $api_key,
                    ],
                    'body'    => wp_json_encode([
                        'model'    => $model,
                        'messages' => $conversation_payload,
                    ]),
                    'timeout' => 60,
                ];

                $api_response = wp_remote_post($api_url, $api_args);
                if (is_wp_error($api_response)) {
                    wp_send_json_error(['message' => $api_response->get_error_message()]);
                }

                $body = json_decode(wp_remote_retrieve_body($api_response), true);
                if (isset($body['choices'][0]['message']['content'])) {
                    $reply = trim($body['choices'][0]['message']['content']);
                } else {
                    $error_message = $body['error']['message'] ?? __('Respuesta inesperada.', 'ai-chatbot-pro');
                    wp_send_json_error(['message' => $error_message]);
                }
            } else {
                wp_send_json_error(['message' => $assistant_response->get_error_message()]);
            }
        } else {
            $reply = $assistant_response['reply'];
            $session_id = $assistant_response['session_id'];
        }

        $full_history = $history;
        $full_history[] = ['role' => 'assistant', 'content' => $reply];

        $lead_info = AICP_Lead_Manager::detect_contact_data($full_history, $assistant_id, $assistant_settings);

        $lead_payload = $lead_info['is_complete'] ? $lead_info['data'] : [];
        $new_log_id = self::save_conversation($log_id, $assistant_id, $session_id, $full_history, $lead_payload);

        wp_send_json_success([
            'reply'          => $reply,
            'log_id'         => $new_log_id,
            'lead_status'    => $lead_info['is_complete'] ? 'complete' : ($lead_info['has_lead'] ? 'partial' : 'none'),
            'missing_fields' => $lead_info['missing_fields'],
            'session_id'     => $session_id,
        ]);
    }
}