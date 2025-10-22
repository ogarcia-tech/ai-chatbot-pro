<?php
if (!defined('ABSPATH')) exit;

class AICP_Pro_Ajax_Handler {

    /**
     * Guarda la conversación en la base de datos.
     * Adaptada para coincidir con la lógica del plugin base y sanitizar mejor.
     */
    private static function save_conversation($log_id, $assistant_id, $session_id, $conversation, $lead_data = []) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';

        // Validar IDs
        $log_id = is_numeric($log_id) ? absint($log_id) : 0;
        $assistant_id = absint($assistant_id);
        $session_id = sanitize_text_field($session_id); // ensure_session_id ya lo hace, pero por si acaso

        if (empty($assistant_id) || empty($session_id)) {
             error_log("AICP Save Conversation Error: Assistant ID or Session ID empty."); // Log para depuración
             return $log_id > 0 ? $log_id : 0; // Devolver ID original si es inválido
        }


        $first_user_message = '';
        // Buscar primer mensaje solo si estamos creando un nuevo log (log_id es 0)
        if ($log_id === 0) {
            foreach ($conversation as $message) {
                if (isset($message['role'], $message['content']) && $message['role'] === 'user') {
                    $first_user_message = mb_substr(sanitize_text_field($message['content']), 0, 255); // Limitar y sanitizar
                    break;
                }
            }
        }

        // Sanitizar conversación antes de guardar
        $sanitized_conversation = [];
        if (is_array($conversation)) {
             foreach ($conversation as $message) {
                  if (isset($message['role'], $message['content']) && is_scalar($message['role']) && is_scalar($message['content'])) {
                       $role = sanitize_key((string) $message['role']);
                       $content = sanitize_textarea_field((string) $message['content']);
                       // Permitir system role si viene de la memoria
                       if (in_array($role, ['user', 'assistant', 'system']) && $content !== '') {
                            $sanitized_conversation[] = ['role' => $role, 'content' => $content];
                       }
                  }
             }
        }


        // Evitar guardar JSON vacío o solo con system prompt si es un nuevo log
        $has_user_or_assistant = false;
        foreach ($sanitized_conversation as $msg) {
             if ($msg['role'] === 'user' || $msg['role'] === 'assistant') {
                  $has_user_or_assistant = true;
                  break;
             }
        }
        if ($log_id === 0 && !$has_user_or_assistant) {
            return 0; // No insertar si es nuevo y no hay mensajes reales
        }
        $conversation_json = wp_json_encode($sanitized_conversation, JSON_UNESCAPED_UNICODE);


        // Preparar datos para DB
        $data = [
            'assistant_id'     => $assistant_id,
            'session_id'       => $session_id,
            'timestamp'        => current_time('mysql', 1), // Usar GMT
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
             // Cargar Lead Manager si es necesario para sanitizar
             if (!class_exists('AICP_Lead_Manager') && defined('AICP_PLUGIN_DIR') && file_exists(AICP_PLUGIN_DIR . 'includes/class-lead-manager.php')) {
                 require_once AICP_PLUGIN_DIR . 'includes/class-lead-manager.php';
             }

            if (class_exists('AICP_Lead_Manager') && method_exists('AICP_Lead_Manager', 'sanitize_lead_input')) {
                // Usar sanitización del Lead Manager si está disponible
                 $sanitized_lead_data = AICP_Lead_Manager::sanitize_lead_input($lead_data, $assistant_id);
            } else {
                // Sanitización básica si Lead Manager no existe o el método no está
                foreach ($lead_data as $key => $value) {
                     if (is_scalar($value)) {
                         $sanitized_lead_data[sanitize_key($key)] = sanitize_text_field($value);
                     } elseif (is_array($value)) {
                         $sanitized_lead_data[sanitize_key($key)] = implode(', ', array_map('sanitize_text_field', $value));
                     }
                }
            }
        }

        if (!empty($sanitized_lead_data)) {
            $data['has_lead'] = 1;
            $data['lead_data'] = wp_json_encode($sanitized_lead_data, JSON_UNESCAPED_UNICODE);
            $format[] = '%d';
            $format[] = '%s';
        }

        // Determinar si actualizar o insertar
        $existing_log_id = ($log_id > 0) ? $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE id = %d", $log_id)) : null;

        if ($existing_log_id) {
            // Actualizar registro existente
            $wpdb->update($table_name, $data, ['id' => $log_id], $format, ['%d']);
        } else {
            // Insertar nuevo registro (la comprobación $has_user_or_assistant ya se hizo)
            $wpdb->insert($table_name, $data, $format);
            $log_id = $wpdb->insert_id; // Obtener el nuevo ID
            if (!$log_id) { // Comprobar si la inserción falló
                 error_log("AICP DB Insert Error: Failed to insert chat log. Data: " . print_r($data, true));
                 return 0; // Indicar fallo
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
        // Validar formato básico para evitar IDs inválidos y limitar longitud
        if (empty($session_id) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $session_id)) { // Longitud máxima 100
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

        // Reemplazar la acción de chat del plugin principal si este addon está activo
        // Usar una prioridad mayor para asegurar que se registre después y pueda eliminar la del core
        $hook_priority = 10;
        $core_priority = has_action('wp_ajax_aicp_chat_request', ['AICP_Ajax_Handler', 'handle_chat_request']);
        if ($core_priority !== false) {
             remove_action('wp_ajax_aicp_chat_request', ['AICP_Ajax_Handler', 'handle_chat_request'], $core_priority);
             remove_action('wp_ajax_nopriv_aicp_chat_request', ['AICP_Ajax_Handler', 'handle_chat_request'], $core_priority);
        }

        add_action('wp_ajax_aicp_chat_request', [__CLASS__, 'handle_chat_request'], $hook_priority);
        add_action('wp_ajax_nopriv_aicp_chat_request', [__CLASS__, 'handle_chat_request'], $hook_priority);
    }


    /**
     * Maneja la verificación de la API Key de OpenAI.
     */
    public static function handle_check_api_keys() {
        // Verificar permisos y nonce
        if (!current_user_can('manage_options') ) {
             wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'ai-chatbot-pro')]);
             return;
        }
        // El nonce debería estar en aicp_pro_params si se cargó el script, comprobarlo
        $nonce_action = 'aicp_global_settings_nonce'; // Asumiendo que este es el nonce correcto
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        // Descomentar si el nonce se envía y se llama 'nonce'
        // if (!wp_verify_nonce($nonce, $nonce_action)) {
        //      wp_send_json_error(['message' => __('Fallo de seguridad (Nonce inválido).', 'ai-chatbot-pro')]);
        //      return;
        // }


        // Asegurarse de que la clase Manager exista
        if (!class_exists('AICP_OpenAI_Assistants_Manager')) {
             $manager_path = __DIR__ . '/class-openai-assistants-manager.php';
             if (file_exists($manager_path)) require_once $manager_path;
             else wp_send_json_error(['message' => __('Clase Manager no encontrada.', 'ai-chatbot-pro')]);
        }

        // Realizar la comprobación
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
        // Verificar permisos y nonce
        if (!current_user_can('edit_posts')) {
             wp_send_json_error(['message' => __('No tienes permisos suficientes para editar posts.', 'ai-chatbot-pro')]);
             return;
        }
         $nonce_action = 'aicp_save_meta_box_data'; // Nonce de guardado del metabox
         $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
         if (!wp_verify_nonce($nonce, $nonce_action)) {
              wp_send_json_error(['message' => __('Fallo de seguridad (Nonce inválido).', 'ai-chatbot-pro')]);
              return;
         }


         // Asegurarse de que la clase Manager exista
        if (!class_exists('AICP_OpenAI_Assistants_Manager')) {
             $manager_path = __DIR__ . '/class-openai-assistants-manager.php';
             if (file_exists($manager_path)) require_once $manager_path;
             else wp_send_json_error(['message' => __('Clase Manager no encontrada.', 'ai-chatbot-pro')]);
        }

        @set_time_limit(300); // 5 minutos de tiempo de ejecución
        AICP_OpenAI_Assistants_Manager::handle_sync_request(); // Llama a la función que hace el trabajo
    }

    /**
     * Maneja la petición de chat del frontend, priorizando el webhook si está activo.
     */
    public static function handle_chat_request() {
        // Verificar Nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'aicp_chat_nonce')) {
             wp_send_json_error(['message' => __('Fallo de seguridad (Nonce inválido).', 'ai-chatbot-pro')]);
             return; // Salir si el nonce falla
        }

        // Obtener y validar datos de entrada
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $history_input= isset($_POST['history']) && is_array($_POST['history']) ? wp_unslash($_POST['history']) : [];
        $log_id       = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $page_context = isset($_POST['page_context']) ? sanitize_textarea_field(wp_unslash($_POST['page_context'])) : '';
        $incoming_session_id = isset($_POST['session_id']) ? wp_unslash($_POST['session_id']) : '';

        if (empty($assistant_id)) {
            wp_send_json_error(['message' => __('ID de asistente inválido.', 'ai-chatbot-pro')]);
            return;
        }
        // Verificar si el asistente existe y está publicado
        if ('publish' !== get_post_status($assistant_id)) {
             wp_send_json_error(['message' => __('Asistente no encontrado o no publicado.', 'ai-chatbot-pro')]);
             return;
        }


        // Asegurarse de tener un session_id
        $session_id = self::ensure_session_id($incoming_session_id);

        // Definir $client_ip (requiere la clase principal o fallback)
        $client_ip = '0.0.0.0';
         // Definir AICP_PLUGIN_DIR si no existe (importante para require_once)
         if (!defined('AICP_PLUGIN_DIR')) {
             $plugin_base_dir = trailingslashit(plugin_dir_path(dirname(__DIR__, 2))) . 'ai-chatbot-pro/';
             if (is_dir($plugin_base_dir)) { define('AICP_PLUGIN_DIR', $plugin_base_dir); }
             else { define('AICP_PLUGIN_DIR', ''); } // Puede fallar si la estructura es distinta
         }

         // Cargar Ajax_Handler principal si es necesario
         $core_ajax_handler_path = AICP_PLUGIN_DIR . 'includes/class-ajax-handler.php';
         if (!class_exists('AICP_Ajax_Handler') && file_exists($core_ajax_handler_path)) {
             require_once $core_ajax_handler_path;
         }
         // Obtener IP después de asegurar que la clase/función exista
         if (method_exists('AICP_Ajax_Handler', 'get_client_ip')) {
             $client_ip = AICP_Ajax_Handler::get_client_ip();
         } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $client_ip = sanitize_text_field($_SERVER['REMOTE_ADDR']); // Fallback
         }


        // Cargar/Persistir historial usando la clase del core si existe
         $history = []; // Historial de memoria
         // Cargar Session_Memory si es necesario
         if (!class_exists('AICP_Session_Memory') && defined('AICP_PLUGIN_DIR') && file_exists(AICP_PLUGIN_DIR . 'includes/class-session-memory.php')) {
             require_once AICP_PLUGIN_DIR . 'includes/class-session-memory.php';
         }

         if (class_exists('AICP_Session_Memory')) {
             if (!empty($history_input)) {
                 $history = AICP_Session_Memory::persist($session_id, $client_ip, $history_input, $assistant_id);
             } else {
                 $history = AICP_Session_Memory::load($session_id, $client_ip, $assistant_id);
             }
         } else {
             // Fallback si Session_Memory no existe: usar el historial recibido sanitizado
             foreach ($history_input as $msg) { /* ... sanitización ... */ }
             // ... límite de memoria simple ...
             if (is_array($history_input)) { // Asegurar que es array
                 foreach ($history_input as $msg) {
                      if (isset($msg['role'], $msg['content']) && is_scalar($msg['role']) && is_scalar($msg['content'])) {
                           $role = sanitize_key((string)$msg['role']);
                           $content = sanitize_textarea_field((string)$msg['content']);
                           if (in_array($role, ['user', 'assistant', 'system']) && $content !== '') {
                                $history[] = ['role' => $role, 'content' => $content];
                           }
                      }
                 }
                 $memory_limit = 15; // Límite por defecto
                 if (count($history) > $memory_limit) {
                      $history = array_slice($history, -$memory_limit);
                 }
             }

         }


        // Verificar historial después de cargarlo/persistirlo
        $is_first_message = ($log_id === 0 && count(array_filter($history, function($m){ return isset($m['role']) && $m['role']==='user'; })) <= 1);
        if (empty($history) && !$is_first_message) {
             wp_send_json_error(['message' => __('Historial de conversación vacío inesperadamente.', 'ai-chatbot-pro'), 'session_id' => $session_id]);
             return; // Salir
        }


        // Cargar ajustes del asistente
        $assistant_settings = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        if (!is_array($assistant_settings)) {
            $assistant_settings = [];
        }

        // --- INICIO DE LA MODIFICACIÓN: Prioridad Webhook ---
        $use_webhook = !empty($assistant_settings['forward_to_webhook']) && !empty($assistant_settings['forward_webhook_url']);

        // Calcular system_prompt
        $system_prompt = '';
        // Cargar Prompt_Builder si es necesario
         if (!class_exists('AICP_Prompt_Builder') && defined('AICP_PLUGIN_DIR') && file_exists(AICP_PLUGIN_DIR . 'includes/class-prompt-builder.php')) {
             require_once AICP_PLUGIN_DIR . 'includes/class-prompt-builder.php';
         }
        if (class_exists('AICP_Prompt_Builder')) {
            $system_prompt = AICP_Prompt_Builder::build($assistant_settings, $page_context);
        } else {
             $system_prompt = $assistant_settings['custom_prompt'] ?? 'Eres un asistente de IA.'; // Fallback
        }


        // Preparar la conversación completa para enviar (al webhook o a OpenAI)
        $full_conversation_for_api = [];
        if ('' !== trim($system_prompt)) {
            $full_conversation_for_api[] = ['role' => 'system', 'content' => $system_prompt];
        }
        foreach ($history as $item) { // $history ya está sanitizado
            $full_conversation_for_api[] = $item;
        }

        // --- LÓGICA DEL WEBHOOK ---
        if ($use_webhook) {
            // Asegurarse de que la clase y método del core existan y sean públicos
            if (!class_exists('AICP_Ajax_Handler') || !method_exists('AICP_Ajax_Handler', 'call_message_webhook')) {
                 wp_send_json_error(['message' => __('Error interno: La función de webhook no está disponible.', 'ai-chatbot-pro')]);
                 return; // Salir
            }

            // Preparar $lead_payload (vacío)
            $lead_payload_for_webhook = [];

            // Llama a la función del manejador principal
            $webhook_result = AICP_Ajax_Handler::call_message_webhook(
                $assistant_id, $assistant_settings, $session_id, $full_conversation_for_api,
                $page_context, $lead_payload_for_webhook, $system_prompt
            );

            if (is_wp_error($webhook_result)) {
                wp_send_json_error(['message' => $webhook_result->get_error_message()]);
            } else {
                 $reply    = $webhook_result['reply']; // Ya sanitizada
                 $metadata = $webhook_result['metadata'] ?? [];

                 $history_to_save = $history;
                 $history_to_save[] = ['role' => 'assistant', 'content' => $reply];

                 // Intentar detectar lead localmente para guardarlo
                 $lead_info_final = ['is_complete' => false, 'has_lead' => false, 'missing_fields' => [], 'data' => []];
                 // Cargar Lead Manager si es necesario
                 if (!class_exists('AICP_Lead_Manager') && defined('AICP_PLUGIN_DIR') && file_exists(AICP_PLUGIN_DIR . 'includes/class-lead-manager.php')) {
                      require_once AICP_PLUGIN_DIR . 'includes/class-lead-manager.php';
                 }
                 if (class_exists('AICP_Lead_Manager')) {
                      $lead_info_final = AICP_Lead_Manager::detect_contact_data($history_to_save, $assistant_id, $assistant_settings);
                 }

                 // Guardar conversación y lead si aplica
                 $new_log_id = self::save_conversation(
                     $log_id, $assistant_id, $session_id, $history_to_save,
                     ($lead_info_final['is_complete'] ? $lead_info_final['data'] : [])
                 );

                 // Enviar respuesta al frontend
                 $response_payload = [
                     'reply'          => $reply,
                     'log_id'         => $new_log_id,
                     'lead_status'    => $lead_info_final['is_complete'] ? 'complete' : ($lead_info_final['has_lead'] ? 'partial' : 'none'),
                     'missing_fields' => $lead_info_final['missing_fields'] ?? [],
                     'session_id'     => $session_id,
                 ];
                 if (!empty($metadata)) {
                     $response_payload['webhook_metadata'] = $metadata;
                 }
                 wp_send_json_success($response_payload);
            }
             exit; // Salir después de manejar el webhook
        }
        // --- FIN DE LA LÓGICA DEL WEBHOOK ---


        // --- Inicio de la lógica original del Addon (si NO se usa webhook) ---
        $global_settings = get_option('aicp_settings');
        $api_key = $global_settings['api_key'] ?? '';
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('La API Key de OpenAI no está configurada.', 'ai-chatbot-pro')]);
            return; // Salir
        }

        // Obtener último mensaje del usuario del historial ya procesado
        $user_message = '';
        $history_reversed = array_reverse($history);
        foreach($history_reversed as $msg) {
             if ($msg['role'] === 'user') {
                 $user_message = $msg['content'];
                 break;
             }
        }
        // Si no se encuentra (p.ej., solo mensaje de sistema), intentar obtenerlo del input original
        if ($user_message === '' && !empty($history_input)) {
             $last_input = end($history_input);
             if ($last_input && isset($last_input['role']) && $last_input['role'] === 'user' && !empty($last_input['content'])) {
                  $user_message = sanitize_textarea_field($last_input['content']);
                  // Añadir al historial si no estaba
                  $found_in_history = false;
                  foreach($history as $h_msg) if ($h_msg['role']==='user' && $h_msg['content']===$user_message) $found_in_history=true;
                  if (!$found_in_history) {
                      $history[] = ['role' => 'user', 'content' => $user_message];
                      $full_conversation_for_api[] = ['role' => 'user', 'content' => $user_message];
                  }

             }
        }
         // Si sigue vacío, es un error
        if ($user_message === '') {
             wp_send_json_error(['message' => __('Mensaje de usuario vacío o no encontrado.', 'ai-chatbot-pro')]);
             return; // Salir
        }


        // Intenta usar la API de Asistentes (OpenAI Assistants API)
        // Asegurarse de que la clase Manager exista
        if (!class_exists('AICP_OpenAI_Assistants_Manager')) {
             $manager_path = __DIR__ . '/class-openai-assistants-manager.php';
             if (file_exists($manager_path)) require_once $manager_path;
             else { // Si no existe, directamente ir al fallback
                 $assistant_response = new WP_Error('config_error', 'Assistants Manager no disponible.');
             }
        }
        // Si la clase existe, llamar a handle_chat
        if (class_exists('AICP_OpenAI_Assistants_Manager')) {
            $assistant_response = AICP_OpenAI_Assistants_Manager::handle_chat($assistant_id, $user_message, $session_id);
        }


        $reply = '';
        $final_session_id = $session_id; // Por defecto, mantener el session ID

        if (is_wp_error($assistant_response)) {
            // Si falla la API de Asistentes, usar la API de Chat Completions como fallback
            if (in_array($assistant_response->get_error_code(), ['config_error', 'run_error', 'thread_error', 'no_response'])) {

                $api_url = 'https://api.openai.com/v1/chat/completions';

                // Definir $model asegurándose de que AICP_AVAILABLE_MODELS existe
                $model = $assistant_settings['model'] ?? null;
                 if (!defined('AICP_AVAILABLE_MODELS')) {
                     // Cargar model-list.php si no está definida la constante
                     $model_list_path = AICP_PLUGIN_DIR . 'includes/model-list.php';
                     if (file_exists($model_list_path)) { require_once $model_list_path; }
                 }
                 // Validar modelo o usar fallback
                 if (!defined('AICP_AVAILABLE_MODELS') || !isset(AICP_AVAILABLE_MODELS[$model])) {
                     $model = defined('AICP_AVAILABLE_MODELS') ? array_key_first(AICP_AVAILABLE_MODELS) : 'gpt-4o-mini';
                 }

                // Usar $full_conversation_for_api que ya incluye system prompt + historial
                $api_args = [
                    'method'  => 'POST',
                    'headers' => [
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $api_key,
                    ],
                    'body'    => wp_json_encode(['model' => $model, 'messages' => $full_conversation_for_api]),
                    'timeout' => 60,
                ];

                $api_response = wp_remote_post($api_url, $api_args);

                if (is_wp_error($api_response)) {
                    wp_send_json_error(['message' => $api_response->get_error_message()]);
                    return; // Salir
                }

                $body = json_decode(wp_remote_retrieve_body($api_response), true);
                if (isset($body['choices'][0]['message']['content'])) {
                    $reply = trim($body['choices'][0]['message']['content']);
                } else {
                    $error_message = $body['error']['message'] ?? __('Respuesta inesperada de la API de Chat Completions.', 'ai-chatbot-pro');
                    // Log detallado del error para depuración
                    error_log("AICP OpenAI Error: " . $error_message . " | Body: " . wp_remote_retrieve_body($api_response));
                    wp_send_json_error(['message' => $error_message]);
                    return; // Salir
                }
                // Mantener el session ID existente
                $final_session_id = $session_id;

            } else {
                // Otro error de la API de Asistentes que no sea fallback
                wp_send_json_error(['message' => $assistant_response->get_error_message()]);
                return; // Salir
            }
        } else {
            // Éxito con la API de Asistentes
            $reply = $assistant_response['reply'] ?? '';
            $final_session_id = $assistant_response['session_id'] ?? $session_id; // Usar el session ID del thread si existe
        }

        // Validar respuesta final
        if ('' === $reply) {
             wp_send_json_error(['message' => __('La respuesta generada está vacía.', 'ai-chatbot-pro')]);
             return; // Salir
        }

        // Guardar conversación completa (historial sin system prompt + nueva respuesta)
        $history_to_save = $history;
        $history_to_save[] = ['role' => 'assistant', 'content' => $reply];

        // Detectar lead
        $lead_info = ['is_complete' => false, 'has_lead' => false, 'missing_fields' => [], 'data' => []];
        // Cargar Lead Manager si es necesario
        if (!class_exists('AICP_Lead_Manager') && defined('AICP_PLUGIN_DIR') && file_exists(AICP_PLUGIN_DIR . 'includes/class-lead-manager.php')) {
             require_once AICP_PLUGIN_DIR . 'includes/class-lead-manager.php';
        }
        if (class_exists('AICP_Lead_Manager')) {
             $lead_info = AICP_Lead_Manager::detect_contact_data($history_to_save, $assistant_id, $assistant_settings);
        }

        // Guardar conversación y lead si aplica
        $lead_payload_final = $lead_info['is_complete'] ? $lead_info['data'] : [];
        $new_log_id = self::save_conversation($log_id, $assistant_id, $final_session_id, $history_to_save, $lead_payload_final);

        // Enviar respuesta al frontend
        wp_send_json_success([
            'reply'          => $reply,
            'log_id'         => $new_log_id,
            'lead_status'    => $lead_info['is_complete'] ? 'complete' : ($lead_info['has_lead'] ? 'partial' : 'none'),
            'missing_fields' => $lead_info['missing_fields'] ?? [], // Asegurar que siempre exista
            'session_id'     => $final_session_id, // Devolver el ID de sesión correcto
        ]);
        exit; // Terminar ejecución explícitamente

    }

} // Fin de la clase AICP_Pro_Ajax_Handler
?>
