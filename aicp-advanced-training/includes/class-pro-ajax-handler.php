<?php
if (!defined('ABSPATH')) exit;

class AICP_Pro_Ajax_Handler {

    /**
     * Guarda la conversación en la base de datos.
     * Adaptada para coincidir con la lógica del plugin base.
     */
    private static function save_conversation($log_id, $assistant_id, $session_id, $conversation, $lead_data = []) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';

        $first_user_message = '';
        if ($log_id === 0 || $log_id === null) { // Asegurar que 0 o null activen la búsqueda
            foreach ($conversation as $message) {
                if (isset($message['role'], $message['content']) && $message['role'] === 'user') {
                    $first_user_message = mb_substr(sanitize_text_field($message['content']), 0, 255); // Limitar y sanitizar
                    break;
                }
            }
        }

        // Sanitizar conversación antes de guardar
        $sanitized_conversation = [];
        foreach ($conversation as $message) {
             if (isset($message['role'], $message['content'])) {
                  $role = sanitize_key($message['role']);
                  $content = sanitize_textarea_field($message['content']);
                  if (in_array($role, ['user', 'assistant', 'system']) && $content !== '') {
                       $sanitized_conversation[] = ['role' => $role, 'content' => $content];
                  }
             }
        }
        $conversation_json = wp_json_encode($sanitized_conversation, JSON_UNESCAPED_UNICODE);


        $data = [
            'assistant_id'     => absint($assistant_id),
            'session_id'       => sanitize_text_field($session_id),
            'timestamp'        => current_time('mysql'),
            'conversation_log' => $conversation_json,
        ];
        $format = ['%d', '%s', '%s', '%s'];

        if ($first_user_message) {
            $data['first_user_message'] = $first_user_message;
            $format[] = '%s';
        }

        // Sanitizar lead_data antes de guardar
        $sanitized_lead_data = [];
        if (!empty($lead_data) && is_array($lead_data)) {
            if (class_exists('AICP_Lead_Manager')) {
                // Usar sanitización del Lead Manager si está disponible
                 $sanitized_lead_data = AICP_Lead_Manager::sanitize_lead_input($lead_data, $assistant_id);
            } else {
                // Sanitización básica si Lead Manager no existe
                foreach ($lead_data as $key => $value) {
                     $sanitized_lead_data[sanitize_key($key)] = sanitize_text_field($value);
                }
            }
        }

        if (!empty($sanitized_lead_data)) {
            $data['has_lead'] = 1;
            $data['lead_data'] = wp_json_encode($sanitized_lead_data, JSON_UNESCAPED_UNICODE);
            $format[] = '%d';
            $format[] = '%s';
        }

        if ($log_id > 0) {
            $wpdb->update($table_name, $data, ['id' => $log_id], $format, ['%d']);
        } else {
             // Solo insertar si hay al menos un mensaje de usuario o asistente
             $has_user_or_assistant = false;
             foreach ($sanitized_conversation as $msg) {
                  if ($msg['role'] === 'user' || $msg['role'] === 'assistant') {
                       $has_user_or_assistant = true;
                       break;
                  }
             }
             if ($has_user_or_assistant) {
                  $wpdb->insert($table_name, $data, $format);
                  $log_id = $wpdb->insert_id;
             } else {
                 $log_id = 0; // No insertar si solo hay mensaje de sistema
             }

        }

        // Se dispara la acción para que el Lead Manager procese la conversación si está activo
        if ($log_id > 0 && class_exists('AICP_Lead_Manager')) {
             do_action('aicp_conversation_saved', $log_id, $assistant_id, $sanitized_conversation);
        }

        return $log_id;
    }

    /**
     * Asegura que exista un ID de sesión válido.
     */
    private static function ensure_session_id($session_id = '') {
        $session_id = is_string($session_id) ? sanitize_text_field($session_id) : '';
        // Validar formato básico para evitar IDs inválidos
        if (empty($session_id) || !preg_match('/^[a-zA-Z0-9_-]+$/', $session_id)) {
            $session_id = 'aicp_' . wp_generate_uuid4();
        }
        return $session_id;
    }

    /**
     * Inicializa los hooks AJAX para el addon PRO.
     */
    public static function init() {
        add_action('wp_ajax_aicp_check_api_keys', [__CLASS__, 'handle_check_api_keys']);
        add_action('wp_ajax_aicp_start_sync', [__CLASS__, 'handle_start_sync']);
        // Reemplaza la acción de chat del plugin principal
        add_action('wp_ajax_aicp_chat_request', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_nopriv_aicp_chat_request', [__CLASS__, 'handle_chat_request']);
    }

    /**
     * Maneja la verificación de la API Key de OpenAI.
     */
    public static function handle_check_api_keys() {
        if (!current_user_can('manage_options') || !check_ajax_referer('aicp_global_settings_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Fallo de seguridad.', 'ai-chatbot-pro')]);
        }
        $result = AICP_OpenAI_Assistants_Manager::check_api_connection();
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => __('¡Conexión con OpenAI exitosa!', 'ai-chatbot-pro')]);
    }

    /**
     * Maneja el inicio de la sincronización con OpenAI Assistants API.
     */
    public static function handle_start_sync() {
        if (!current_user_can('edit_posts') || !check_ajax_referer('aicp_save_meta_box_data', 'nonce', false)) {
            wp_send_json_error(['message' => __('Fallo de seguridad.', 'ai-chatbot-pro')]);
        }
        @set_time_limit(300); // 5 minutos de tiempo de ejecución
        AICP_OpenAI_Assistants_Manager::handle_sync_request(); // Llama a la función que hace el trabajo
    }

    /**
     * Maneja la petición de chat del frontend, priorizando el webhook si está activo.
     */
    public static function handle_chat_request() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');

        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $history      = isset($_POST['history']) && is_array($_POST['history']) ? wp_unslash($_POST['history']) : [];
        $log_id       = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $page_context = isset($_POST['page_context']) ? sanitize_textarea_field(wp_unslash($_POST['page_context'])) : '';
        $incoming_session_id = isset($_POST['session_id']) ? wp_unslash($_POST['session_id']) : ''; // No sanitizar aquí, ensure_session_id lo hace

        if (empty($assistant_id)) {
            wp_send_json_error(['message' => __('ID de asistente inválido.', 'ai-chatbot-pro')]);
        }

        // Asegurarse de tener un session_id y cargarlo/persistirlo
        $session_id = self::ensure_session_id($incoming_session_id);

        // Definir $client_ip (requiere la clase principal)
        $client_ip = '0.0.0.0';
        if (method_exists('AICP_Ajax_Handler', 'get_client_ip')) {
             $client_ip = AICP_Ajax_Handler::get_client_ip();
        } elseif (function_exists('$_SERVER') && isset($_SERVER['REMOTE_ADDR'])) {
            $client_ip = sanitize_text_field($_SERVER['REMOTE_ADDR']);
        }


        // Cargar/Persistir historial usando la clase del core si existe
        if (class_exists('AICP_Session_Memory')) {
            if (!empty($history)) {
                $history = AICP_Session_Memory::persist($session_id, $client_ip, $history, $assistant_id);
            } else {
                $history = AICP_Session_Memory::load($session_id, $client_ip, $assistant_id);
            }
        } else {
            // Fallback si Session_Memory no existe: usar el historial recibido
            $temp_history = [];
            foreach ($history as $msg) { // Sanitización básica
                if (isset($msg['role'], $msg['content'])) {
                     $temp_history[] = [
                          'role' => sanitize_key($msg['role']),
                          'content' => sanitize_textarea_field($msg['content'])
                     ];
                }
            }
            $history = $temp_history;
        }


        if (empty($history)) {
             // Permitir historial vacío solo si es la primera petición (log_id es 0)
             if ($log_id > 0) {
                 wp_send_json_error(['message' => __('Historial de conversación inválido o vacío.', 'ai-chatbot-pro')]);
             }
             // Si log_id es 0, puede ser el primer mensaje, continuar.
        }


        $assistant_settings = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        if (!is_array($assistant_settings)) {
            $assistant_settings = [];
        }

        // --- INICIO DE LA MODIFICACIÓN: Prioridad Webhook ---
        $use_webhook = !empty($assistant_settings['forward_to_webhook']) && !empty($assistant_settings['forward_webhook_url']);

        // Calcular system_prompt y conversation ANTES del if, ya que se usan en ambos casos
        $system_prompt = '';
        if (class_exists('AICP_Prompt_Builder')) {
            $system_prompt = AICP_Prompt_Builder::build($assistant_settings, $page_context);
        } else {
             $system_prompt = $assistant_settings['custom_prompt'] ?? 'Eres un asistente de IA.'; // Fallback simple
        }


        // Preparar la conversación completa para enviar (al webhook o a OpenAI)
        // Incluye system prompt + historial de memoria
        $full_conversation_for_api = [];
        if ('' !== trim($system_prompt)) {
            $full_conversation_for_api[] = ['role' => 'system', 'content' => $system_prompt];
        }
        foreach ($history as $item) {
             // Ya sanitizado por Session_Memory o el fallback
            $full_conversation_for_api[] = $item;
        }

        // --- LÓGICA DEL WEBHOOK ---
        if ($use_webhook) {
            // Asegurarse de que la clase del manejador principal exista y sea accesible
            if (!class_exists('AICP_Ajax_Handler') || !method_exists('AICP_Ajax_Handler', 'call_message_webhook')) {
                 $core_ajax_handler_path = AICP_PLUGIN_DIR . 'includes/class-ajax-handler.php';
                 if (file_exists($core_ajax_handler_path)) {
                      require_once $core_ajax_handler_path;
                      // Verificar de nuevo después de incluir
                      if (!class_exists('AICP_Ajax_Handler') || !method_exists('AICP_Ajax_Handler', 'call_message_webhook')) {
                           wp_send_json_error(['message' => __('Error interno: La función de webhook no está accesible.', 'ai-chatbot-pro')]);
                      }
                 } else {
                      wp_send_json_error(['message' => __('Error interno: No se encuentra el manejador principal.', 'ai-chatbot-pro')]);
                 }
            }

            // Preparar $lead_payload (vacío por ahora, el webhook lo gestiona externamente)
            $lead_payload_for_webhook = [];

            // Llama a la función del manejador principal para enviar al webhook
            $webhook_result = AICP_Ajax_Handler::call_message_webhook(
                $assistant_id,
                $assistant_settings, // Contiene la URL y secreto del webhook
                $session_id,
                $full_conversation_for_api, // Usamos la conversación completa con system prompt
                $page_context,
                $lead_payload_for_webhook,
                $system_prompt
            );

            if (is_wp_error($webhook_result)) {
                wp_send_json_error(['message' => $webhook_result->get_error_message()]);
            } else {
                 $reply    = $webhook_result['reply']; // Ya sanitizada por call_message_webhook
                 $metadata = $webhook_result['metadata'] ?? [];

                 // Guardar la conversación completa (historial sin system prompt + nueva respuesta)
                 $history_to_save = $history;
                 $history_to_save[] = ['role' => 'assistant', 'content' => $reply];

                 // Intentar detectar lead para guardarlo localmente si aplica, aunque el webhook lo gestione
                 $lead_info_final = ['is_complete' => false, 'has_lead' => false, 'missing_fields' => [], 'data' => []];
                 if (class_exists('AICP_Lead_Manager')) {
                      $lead_info_final = AICP_Lead_Manager::detect_contact_data($history_to_save, $assistant_id, $assistant_settings);
                 }

                 // Guardar usando la función save_conversation de ESTA clase
                 $new_log_id = self::save_conversation(
                     $log_id,
                     $assistant_id,
                     $session_id,
                     $history_to_save, // Guarda el historial sin el prompt de sistema
                     ($lead_info_final['is_complete'] ? $lead_info_final['data'] : []) // Guarda el lead si está completo
                 );

                 // Envía la respuesta al frontend
                 $response_payload = [
                     'reply'          => $reply,
                     'log_id'         => $new_log_id,
                     // El estado del lead lo gestiona el webhook, usamos lo detectado localmente como info
                     'lead_status'    => $lead_info_final['is_complete'] ? 'complete' : ($lead_info_final['has_lead'] ? 'partial' : 'none'),
                     'missing_fields' => $lead_info_final['missing_fields'] ?? [],
                     'session_id'     => $session_id,
                 ];
                 if (!empty($metadata)) {
                     $response_payload['webhook_metadata'] = $metadata;
                 }
                wp_send_json_success($response_payload);
            }
             // Importante: Salir aquí para no ejecutar el resto de la función del addon
             exit;
        }
        // --- FIN DE LA LÓGICA DEL WEBHOOK ---


        // --- Inicio de la lógica original del Addon (si NO se usa webhook) ---
        $global_settings = get_option('aicp_settings');
        $api_key = $global_settings['api_key'] ?? '';
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('La API Key de OpenAI no está configurada.', 'ai-chatbot-pro')]);
        }

        $user_message = end($history)['content'] ?? ''; // Último mensaje del historial persistido/cargado
        if ($user_message === '') {
            // Podría ser el primer mensaje si $history estaba vacío al principio
             if (isset($_POST['history']) && is_array($_POST['history'])) {
                 $posted_history = wp_unslash($_POST['history']);
                 $last_posted = end($posted_history);
                 if ($last_posted && isset($last_posted['role']) && $last_posted['role'] === 'user' && !empty($last_posted['content'])) {
                      $user_message = sanitize_textarea_field($last_posted['content']);
                 }
             }
             if ($user_message === '') {
                 wp_send_json_error(['message' => __('Mensaje de usuario vacío o no encontrado.', 'ai-chatbot-pro')]);
             }
        }


        // Intenta usar la API de Asistentes (OpenAI Assistants API)
        $assistant_response = AICP_OpenAI_Assistants_Manager::handle_chat($assistant_id, $user_message, $session_id);

        $reply = '';
        $final_session_id = $session_id; // Inicializar con el ID de sesión existente

        if (is_wp_error($assistant_response)) {
            // Si falla la API de Asistentes (p.ej., no sincronizado), usa la API de Chat Completions como fallback
            if ($assistant_response->get_error_code() === 'config_error' || $assistant_response->get_error_code() === 'run_error') {

                $api_url = 'https://api.openai.com/v1/chat/completions';

                // Definir $model asegurándose de que AICP_AVAILABLE_MODELS existe
                $model = $assistant_settings['model'] ?? null;
                 if (!defined('AICP_AVAILABLE_MODELS')) {
                     $model_list_path = AICP_PLUGIN_DIR . 'includes/model-list.php';
                     if (file_exists($model_list_path)) {
                          require_once $model_list_path;
                     }
                 }
                 if (!defined('AICP_AVAILABLE_MODELS') || !isset(AICP_AVAILABLE_MODELS[$model])) {
                     $model = defined('AICP_AVAILABLE_MODELS') ? array_key_first(AICP_AVAILABLE_MODELS) : 'gpt-4o-mini'; // Fallback final
                 }

                $api_args = [
                    'method'  => 'POST',
                    'headers' => [
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $api_key,
                    ],
                    'body'    => wp_json_encode([
                        'model'    => $model,
                        'messages' => $full_conversation_for_api, // Usamos la conversación completa con system prompt
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
                    $error_message = $body['error']['message'] ?? __('Respuesta inesperada de la API de Chat Completions.', 'ai-chatbot-pro');
                    wp_send_json_error(['message' => $error_message]);
                }
                // Mantener el session ID existente ya que no se creó un hilo nuevo
                $final_session_id = $session_id;

            } else {
                // Otro error de la API de Asistentes
                wp_send_json_error(['message' => $assistant_response->get_error_message()]);
            }
        } else {
            // Éxito con la API de Asistentes
            $reply = $assistant_response['reply'];
            $final_session_id = $assistant_response['session_id']; // Usar el session ID asociado al thread
        }

        if ('' === $reply) {
             wp_send_json_error(['message' => __('La respuesta generada está vacía.', 'ai-chatbot-pro')]);
        }


        // Guardar conversación completa (historial sin system prompt + nueva respuesta)
        $history_to_save = $history;
        $history_to_save[] = ['role' => 'assistant', 'content' => $reply];

        $lead_info = ['is_complete' => false, 'has_lead' => false, 'missing_fields' => [], 'data' => []];
        if (class_exists('AICP_Lead_Manager')) {
             $lead_info = AICP_Lead_Manager::detect_contact_data($history_to_save, $assistant_id, $assistant_settings);
        }

        $lead_payload_final = $lead_info['is_complete'] ? $lead_info['data'] : [];
        $new_log_id = self::save_conversation($log_id, $assistant_id, $final_session_id, $history_to_save, $lead_payload_final);

        // Enviar respuesta al frontend
        wp_send_json_success([
            'reply'          => $reply,
            'log_id'         => $new_log_id,
            'lead_status'    => $lead_info['is_complete'] ? 'complete' : ($lead_info['has_lead'] ? 'partial' : 'none'),
            'missing_fields' => $lead_info['missing_fields'],
            'session_id'     => $final_session_id, // Devolver el ID de sesión correcto
        ]);
    }

} // Fin de la clase AICP_Pro_Ajax_Handler
?>
