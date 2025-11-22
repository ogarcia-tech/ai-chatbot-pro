<?php
/**
 * Clase que maneja todas las peticiones AJAX del plugin.
 *
 * @package AI_Chatbot_Pro
 */
if (!defined('ABSPATH')) exit;

class AICP_Ajax_Handler {

    /**
     * Inicializa los hooks AJAX.
     */
    public static function init() {
        // Hooks de chat (pueden ser reemplazados por el addon PRO si está activo)
        add_action('wp_ajax_aicp_chat_request', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_nopriv_aicp_chat_request', [__CLASS__, 'handle_chat_request']);

        // Hooks de gestión de logs y pruebas (siempre activos)
        add_action('wp_ajax_aicp_delete_log', [__CLASS__, 'handle_delete_log']);
        add_action('wp_ajax_aicp_get_log_details', [__CLASS__, 'handle_get_log_details']);
        add_action('wp_ajax_aicp_test_webhook', [__CLASS__, 'handle_test_webhook']); // Para probar webhook desde admin
        add_action('wp_ajax_aicp_test_model_connection', [__CLASS__, 'handle_test_model_connection']);
        add_action('wp_ajax_aicp_get_templates', [__CLASS__, 'handle_get_templates']); // Para cargar plantillas en admin

        // Hooks de feedback
        add_action('wp_ajax_nopriv_aicp_submit_feedback', [__CLASS__, 'handle_submit_feedback']);
        add_action('wp_ajax_aicp_submit_feedback', [__CLASS__, 'handle_submit_feedback']);

        // Hooks relacionados con leads (pueden ser usados por el frontend directamente)
        add_action('wp_ajax_aicp_submit_lead_form', [__CLASS__, 'handle_submit_lead_form']);
        add_action('wp_ajax_nopriv_aicp_submit_lead_form', [__CLASS__, 'handle_submit_lead_form']);
        add_action('wp_ajax_aicp_finalize_chat', [__CLASS__, 'handle_finalize_chat']); // Para análisis final si se cierra el chat
        add_action('wp_ajax_nopriv_aicp_finalize_chat', [__CLASS__, 'handle_finalize_chat']);
    }

    /**
     * Guarda la conversación en la base de datos.
     */
    private static function save_conversation($log_id, $assistant_id, $session_id, $conversation, $lead_data = []) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';

        // Validar IDs
        $log_id = is_numeric($log_id) ? absint($log_id) : 0;
        $assistant_id = absint($assistant_id);
        $session_id = sanitize_text_field($session_id);

        if (empty($assistant_id) || empty($session_id)) {
             error_log("AICP Save Conversation Error: Assistant ID or Session ID empty.");
             return $log_id > 0 ? $log_id : 0;
        }

        $first_user_message = '';
        if ($log_id === 0) {
            foreach ($conversation as $message) {
                if (isset($message['role'], $message['content']) && $message['role'] === 'user') {
                    $first_user_message = mb_substr(sanitize_text_field($message['content']), 0, 255);
                    break;
                }
            }
        }

        // Sanitizar conversación
        $sanitized_conversation = [];
        if (is_array($conversation)) {
             foreach ($conversation as $message) {
                  if (isset($message['role'], $message['content']) && is_scalar($message['role']) && is_scalar($message['content'])) {
                       $role = sanitize_key((string) $message['role']);
                       $content = sanitize_textarea_field((string) $message['content']);
                       if (in_array($role, ['user', 'assistant', 'system']) && $content !== '') {
                            $sanitized_conversation[] = ['role' => $role, 'content' => $content];
                       }
                  }
             }
        }

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

        // Preparar datos
        $data = [
            'assistant_id'     => $assistant_id,
            'session_id'       => $session_id,
            'timestamp'        => current_time('mysql', 1), // GMT
            'conversation_log' => $conversation_json,
        ];
        $format = ['%d', '%s', '%s', '%s'];

        if ($first_user_message) {
            $data['first_user_message'] = $first_user_message;
            $format[] = '%s';
        }

        // Sanitizar lead_data
        $sanitized_lead_data = [];
        if (!empty($lead_data) && is_array($lead_data)) {
            if (class_exists('AICP_Lead_Manager') && method_exists('AICP_Lead_Manager', 'sanitize_lead_input')) {
                 $sanitized_lead_data = AICP_Lead_Manager::sanitize_lead_input($lead_data, $assistant_id);
            } else {
                foreach ($lead_data as $key => $value) { /* ... sanitización básica ... */ }
            }
        }
        if (!empty($sanitized_lead_data)) {
            $data['has_lead'] = 1;
            $data['lead_data'] = wp_json_encode($sanitized_lead_data, JSON_UNESCAPED_UNICODE);
            $format[] = '%d';
            $format[] = '%s';
        }

        // Actualizar o Insertar
        $existing_log_id = ($log_id > 0) ? $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE id = %d", $log_id)) : null;
        if ($existing_log_id) {
            $wpdb->update($table_name, $data, ['id' => $log_id], $format, ['%d']);
        } else {
            $wpdb->insert($table_name, $data, $format);
            $log_id = $wpdb->insert_id;
            if (!$log_id) {
                 error_log("AICP DB Insert Error: Failed to insert chat log.");
                 return 0;
            }
        }

        // Acción después de guardar (si Lead Manager existe)
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
        if (empty($session_id) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $session_id)) {
            $session_id = 'aicp_' . wp_generate_uuid4();
        }
        return $session_id;
    }

    /**
     * Obtiene la IP del cliente de forma segura.
     * CAMBIO: Hecho public static
     */
    public static function get_client_ip() {
        $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip_list = explode(',', wp_unslash($_SERVER[$key]));
                $candidate = trim(reset($ip_list));
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
        return '0.0.0.0';
    }

    /**
     * Sanitiza el payload de lead recibido del frontend.
     */
    private static function sanitize_lead_payload($lead_input) {
        $sanitized = [];
        if (!is_array($lead_input)) return $sanitized;
        foreach ($lead_input as $key => $value) {
            if (is_scalar($value)) {
                $sanitized[sanitize_key($key)] = sanitize_text_field($value);
            }
        }
        return $sanitized;
    }

    /**
     * Comprueba si un host externo es accesible según la configuración de WP.
     */
    private static function is_host_accessible($host) {
        if (empty($host)) return true;
        if (!defined('WP_HTTP_BLOCK_EXTERNAL') || !WP_HTTP_BLOCK_EXTERNAL) return true;
        if (!defined('WP_ACCESSIBLE_HOSTS') || !is_string(WP_ACCESSIBLE_HOSTS) || '' === WP_ACCESSIBLE_HOSTS) return false;
        $allowed_hosts = array_filter(array_map('trim', explode(',', WP_ACCESSIBLE_HOSTS)));
        if (empty($allowed_hosts)) return false;
        $host = strtolower($host);
        foreach ($allowed_hosts as $allowed) {
            $allowed = strtolower($allowed);
            if ('*' === $allowed) return true;
            if ($host === $allowed) return true;
            if (strpos($allowed, '*.') === 0) {
                $suffix = substr($allowed, 1);
                if ($suffix && substr($host, -strlen($suffix)) === $suffix && strlen($host) > strlen($suffix)) {
                     return true;
                }
            }
        }
        return false;
    }


    /**
     * Llama al webhook externo y procesa la respuesta de forma flexible.
     * CAMBIO: Hecho public static y lógica flexible añadida.
     */
    public static function call_message_webhook($assistant_id, $settings, $session_id, $conversation, $page_context, $lead_data, $system_prompt) {
        $webhook_url = isset($settings['forward_webhook_url']) ? esc_url_raw($settings['forward_webhook_url']) : '';
        if (empty($webhook_url) || !wp_http_validate_url($webhook_url)) {
            return new WP_Error('invalid_webhook', __('No se ha configurado una URL de webhook válida.', 'ai-chatbot-pro'));
        }

        $last_user_message = '';
        if(is_array($conversation)){ // Añadir comprobación de array
            for ($i = count($conversation) - 1; $i >= 0; $i--) {
                if (isset($conversation[$i]['role']) && 'user' === $conversation[$i]['role']) {
                    $last_user_message = $conversation[$i]['content'] ?? '';
                    break;
                }
            }
        }


        $payload = [
            'conversation_id' => $session_id, 'assistant_id' => $assistant_id,
            'message' => $last_user_message, 'history' => $conversation,
            'system_prompt' => $system_prompt,
            'site' => ['url' => home_url(), 'name' => get_bloginfo('name')],
            'meta' => ['page_context' => $page_context, 'lead_data' => $lead_data],
        ];

        $host = wp_parse_url($webhook_url, PHP_URL_HOST);
        if (!self::is_host_accessible($host)) {
             return new WP_Error('webhook_blocked_external', __('Las peticiones HTTP externas están bloqueadas para este host.', 'ai-chatbot-pro'), [ /* ... error data ... */ ]);
        }


        $headers = [
            'Content-Type' => 'application/json; charset=utf-8', 'Accept' => 'application/json, */*;q=0.1',
            'User-Agent' => 'AI Chatbot Pro Webhook/1.0; ' . home_url(),
        ];
        if (!empty($settings['forward_webhook_secret'])) {
            $headers['X-AICP-Webhook-Secret'] = sanitize_text_field($settings['forward_webhook_secret']);
        }
        $timeout = isset($settings['forward_webhook_timeout']) ? max(5, intval($settings['forward_webhook_timeout'])) : 15;
        $json_payload = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (false === $json_payload) {
             return new WP_Error('webhook_json_encoding', __('No se pudo serializar el payload del webhook a JSON.', 'ai-chatbot-pro'), [ /* ... error data ... */ ]);
        }

        $request_args = [
            'method' => 'POST', 'headers' => $headers, 'body' => $json_payload,
            'timeout' => $timeout, 'blocking' => true, 'data_format' => 'body',
        ];
        $request_args = apply_filters('aicp_webhook_request_args', $request_args, $payload, $assistant_id);

        $start = microtime(true);
        $response = wp_remote_post($webhook_url, $request_args);
        $duration = microtime(true) - $start;

        if (is_wp_error($response)) {
             $error_data = $response->get_error_data(); if (!is_array($error_data)) $error_data = [];
             $error_data = array_merge($error_data, ['http_status' => 0, 'request_payload' => $payload, 'request_headers' => $request_args['headers'], 'request_url' => $webhook_url, 'duration' => $duration]);
             $error_code = $response->get_error_code() ?: 'webhook_http_failure';
             return new WP_Error($error_code, $response->get_error_message(), $error_data);
        }

        $status = wp_remote_retrieve_response_code($response);
        $raw_headers = wp_remote_retrieve_headers($response);
        $headers_array = is_object($raw_headers) ? $raw_headers->getAll() : (array) $raw_headers;
        $body = wp_remote_retrieve_body($response);

        if ($status >= 400) {
             return new WP_Error('webhook_http_error', sprintf(__('El webhook devolvió un código HTTP %d.', 'ai-chatbot-pro'), $status), ['http_status' => $status, 'response_body' => $body, 'request_payload' => $payload, 'request_headers' => $request_args['headers'], 'request_url' => $webhook_url, 'response_headers'=> $headers_array, 'duration' => $duration]);
        }

        // --- INICIO LÓGICA FLEXIBLE ---
        $reply = ''; $metadata = []; $data = json_decode($body, true);
        if (is_array($data)) {
            if (isset($data['reply']) && is_string($data['reply'])) $reply = trim($data['reply']);
            elseif (isset($data['output']) && is_string($data['output'])) $reply = trim($data['output']);
            elseif (isset($data['text']) && is_string($data['text'])) $reply = trim($data['text']);
            elseif (isset($data['message']) && is_string($data['message'])) $reply = trim($data['message']);
            elseif (isset($data['response']) && is_string($data['response'])) $reply = trim($data['response']);
            elseif (count($data) === 1) { $first_value = reset($data); if (is_string($first_value)) $reply = trim($first_value); }
            if (isset($data['metadata']) && is_array($data['metadata'])) $metadata = $data['metadata'];
        }
        if ($reply === '' && is_string($body) && trim($body) !== '') {
            if (strip_tags($body) === $body) $reply = trim($body);
        }
        // --- FIN LÓGICA FLEXIBLE ---

        if ('' === $reply) {
            return new WP_Error('webhook_empty_or_invalid_response', __('El webhook no devolvió una respuesta de texto válida.', 'ai-chatbot-pro'), ['http_status' => $status, 'response_body' => $body, /* ... more error data ... */ 'duration' => $duration ]);
        }

        $sanitized_reply = sanitize_textarea_field($reply);
        $result = [
            'reply' => $sanitized_reply, 'metadata' => $metadata,
            'http_status' => $status, 'raw_body' => $body, 'request_payload' => $payload,
            'request_headers' => $request_args['headers'], 'request_url' => $webhook_url,
            'response_headers'=> $headers_array, 'duration' => $duration,
        ];
        return $result;
    }


    /**
     * Maneja la petición AJAX para probar el webhook desde el admin.
     */
    public static function handle_test_webhook() {
        // Verificar nonce y permisos
        check_ajax_referer('aicp_test_webhook_nonce', 'nonce');
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        if ($assistant_id > 0) { if (!current_user_can('edit_post', $assistant_id)) wp_die(); }
        else { if (!current_user_can('edit_posts')) wp_die(); }

        $webhook_url = isset($_POST['webhook_url']) ? esc_url_raw(wp_unslash($_POST['webhook_url'])) : '';
        if (empty($webhook_url) || !wp_http_validate_url($webhook_url)) {
            wp_send_json_error(['message' => __('Introduce una URL de webhook válida.', 'ai-chatbot-pro')]);
        }
        $secret = isset($_POST['secret']) ? sanitize_text_field(wp_unslash($_POST['secret'])) : '';
        $timeout = isset($_POST['timeout']) ? intval($_POST['timeout']) : 15;
        $timeout = max(5, min(120, $timeout));

        // Cargar ajustes y preparar datos de prueba
        $settings = ($assistant_id > 0) ? get_post_meta($assistant_id, '_aicp_assistant_settings', true) : [];
        if (!is_array($settings)) $settings = [];
        $test_settings = $settings; // Copiar ajustes
        $test_settings['forward_webhook_url'] = $webhook_url; // Sobrescribir con los del formulario
        $test_settings['forward_webhook_secret'] = $secret;
        $test_settings['forward_webhook_timeout'] = $timeout;

        $system_prompt = '';
        if (class_exists('AICP_Prompt_Builder')) {
            $system_prompt = AICP_Prompt_Builder::build($settings, '');
        } elseif (defined('AICP_PLUGIN_DIR') && file_exists(AICP_PLUGIN_DIR . 'includes/class-prompt-builder.php')) {
            require_once AICP_PLUGIN_DIR . 'includes/class-prompt-builder.php';
            if (class_exists('AICP_Prompt_Builder')) {
                 $system_prompt = AICP_Prompt_Builder::build($settings, '');
            }
        }
        if ($system_prompt === '') $system_prompt = 'Test system prompt.';


        $conversation = [];
        if ('' !== trim($system_prompt)) $conversation[] = ['role' => 'system', 'content' => $system_prompt];
        $test_message = __('Mensaje de prueba desde WordPress para comprobar el webhook.', 'ai-chatbot-pro');
        $conversation[] = ['role' => 'user', 'content' => $test_message];
        $session_id = 'aicp_test_' . wp_generate_uuid4();
        $page_context = __('Prueba manual del webhook desde el panel de administración.', 'ai-chatbot-pro');

        // Llamar a la función de webhook (ahora es public)
        $result = self::call_message_webhook($assistant_id, $test_settings, $session_id, $conversation, $page_context, [], $system_prompt);

        // Enviar respuesta al frontend
        if (is_wp_error($result)) {
            $error_data = ['message' => $result->get_error_message()];
            $extra = $result->get_error_data();
            if (is_array($extra)) { /* ... copiar datos extra al error_data ... */ }
            if (is_array($extra)) {
                 $fields_to_copy = ['http_status', 'response_body' => 'raw_body', 'request_payload' => 'payload', 'request_headers', 'response_headers', 'request_url', 'duration', 'hint'];
                 foreach ($fields_to_copy as $source_key => $target_key) {
                      if (is_int($source_key)) { $source_key = $target_key; } // Si no es asociativo
                      if (isset($extra[$source_key])) {
                           $error_data[$target_key] = $extra[$source_key];
                      }
                 }
            }

            $error_code = $result->get_error_code();
            if (!empty($error_code)) $error_data['error_code'] = $error_code;
            wp_send_json_error($error_data);
        } else {
            $response = [
                'message' => __('El webhook respondió correctamente.', 'ai-chatbot-pro'),
                'http_status' => $result['http_status'] ?? null, 'reply' => $result['reply'],
                'payload' => $result['request_payload'] ?? [], 'request_headers' => $result['request_headers'] ?? [],
                'request_url' => $result['request_url'] ?? $webhook_url,
            ];
            if (!empty($result['metadata'])) $response['metadata'] = $result['metadata'];
            if (!empty($result['raw_body'])) $response['raw_body'] = $result['raw_body'];
            if (!empty($result['response_headers'])) $response['response_headers'] = $result['response_headers'];
            if (isset($result['duration'])) $response['duration'] = floatval($result['duration']);
            wp_send_json_success($response);
        }
    }


    /**
     * Maneja la prueba de conexión con proveedores de modelo desde la pantalla de ajustes.
     */
    public static function handle_test_model_connection() {
        check_ajax_referer('aicp_test_model_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción.', 'ai-chatbot-pro')]);
        }

        $provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
        if ($provider === '') {
            wp_send_json_error(['message' => __('Selecciona un proveedor para probar la conexión.', 'ai-chatbot-pro')]);
        }

        $settings = [
            'api_key' => isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '',
            'model'   => isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '',
        ];

        if (isset($_POST['base_url'])) {
            $settings['base_url'] = esc_url_raw(wp_unslash($_POST['base_url']));
        }
        if (isset($_POST['endpoint'])) {
            $settings['endpoint'] = esc_url_raw(wp_unslash($_POST['endpoint']));
        }
        if (isset($_POST['temperature']) && $_POST['temperature'] !== '') {
            $settings['temperature'] = floatval(wp_unslash($_POST['temperature']));
        }
        if (isset($_POST['max_tokens']) && $_POST['max_tokens'] !== '') {
            $settings['max_tokens'] = intval(wp_unslash($_POST['max_tokens']));
        }

        $missing_fields = false;
        switch ($provider) {
            case 'gemini':
                $missing_fields = empty($settings['api_key']) || empty($settings['model']) || empty($settings['endpoint']);
                break;
            case 'custom':
                $missing_fields = empty($settings['model']) || empty($settings['endpoint']);
                break;
            default:
                $missing_fields = empty($settings['api_key']) || empty($settings['model']);
                break;
        }

        if ($missing_fields) {
            wp_send_json_error(['message' => __('Rellena todos los campos obligatorios antes de probar.', 'ai-chatbot-pro')]);
        }

        if (!class_exists('AICP_Model_Router')) {
            require_once AICP_PLUGIN_DIR . 'includes/class-model-router.php';
        }

        $driver = AICP_Model_Router::get_driver($provider);
        if (!$driver || !method_exists($driver, 'send_message')) {
            wp_send_json_error(['message' => __('No se pudo inicializar el driver del modelo.', 'ai-chatbot-pro')]);
        }

        $prompt  = __('Prueba de conexión desde el panel de ajustes.', 'ai-chatbot-pro');
        $history = [
            ['role' => 'user', 'content' => __('Mensaje de verificación rápida para comprobar la API.', 'ai-chatbot-pro')],
        ];

        $response = $driver->send_message($prompt, $history, $settings);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code    = $response->get_error_code();
            $payload       = ['message' => $error_message];
            if (!empty($error_code)) {
                $payload['code'] = $error_code;
            }
            wp_send_json_error($payload);
        }

        $preview = '';
        if (is_string($response) && $response !== '') {
            $preview = wp_strip_all_tags(wp_html_excerpt($response, 240, '…'));
        }

        wp_send_json_success([
            'message' => __('Conexión verificada correctamente.', 'ai-chatbot-pro'),
            'preview' => $preview,
        ]);
    }


    /**
     * Maneja la petición AJAX para obtener las plantillas.
     */
    public static function handle_get_templates() {
         // Cargar funciones de plantilla si es necesario
         if (!function_exists('aicp_get_assistant_templates') && defined('AICP_PLUGIN_DIR') && file_exists(AICP_PLUGIN_DIR . 'includes/template-functions.php')) {
             require_once AICP_PLUGIN_DIR . 'includes/template-functions.php';
         }
        if (function_exists('aicp_get_assistant_templates')) {
            $templates = aicp_get_assistant_templates(false); // Forzar recarga desde opción/archivo
            wp_send_json($templates);
        } else {
            wp_send_json_error(['message' => 'Template functions not available.']);
        }
    }


    /**
     * Maneja la petición de chat principal (puede ser sobreescrita por el addon PRO).
     */
    public static function handle_chat_request() {
        // Verificar Nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'aicp_chat_nonce')) {
             wp_send_json_error(['message' => __('Fallo de seguridad (Nonce inválido).', 'ai-chatbot-pro')]); return;
        }

        // Obtener y validar datos
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $history_input= isset($_POST['history']) && is_array($_POST['history']) ? wp_unslash($_POST['history']) : [];
        $log_id       = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $page_context = isset($_POST['page_context']) ? sanitize_textarea_field(wp_unslash($_POST['page_context'])) : '';
        $incoming_session_id = isset($_POST['session_id']) ? wp_unslash($_POST['session_id']) : '';

        if (empty($assistant_id) || 'publish' !== get_post_status($assistant_id)) {
            wp_send_json_error(['message' => __('Asistente inválido o no publicado.', 'ai-chatbot-pro')]); return;
        }

        // Session ID y IP
        $session_id = self::ensure_session_id($incoming_session_id);
        $client_ip = self::get_client_ip(); // Ahora es public

        // Cargar/Persistir historial
         $history = [];
         if (!class_exists('AICP_Session_Memory') && defined('AICP_PLUGIN_DIR') && file_exists(AICP_PLUGIN_DIR . 'includes/class-session-memory.php')) {
             require_once AICP_PLUGIN_DIR . 'includes/class-session-memory.php';
         }
         if (class_exists('AICP_Session_Memory')) {
             if (!empty($history_input)) { $history = AICP_Session_Memory::persist($session_id, $client_ip, $history_input, $assistant_id); }
             else { $history = AICP_Session_Memory::load($session_id, $client_ip, $assistant_id); }
         } else { /* ... fallback simple ... */ }

         $is_first_message = ($log_id === 0 && count(array_filter($history, function($m){ return isset($m['role']) && $m['role']==='user'; })) <= 1);
         if (empty($history) && !$is_first_message) {
              wp_send_json_error(['message' => __('Historial vacío inesperadamente.', 'ai-chatbot-pro'), 'session_id' => $session_id]); return;
         }


        // Cargar ajustes
        $global_settings = get_option('aicp_settings');
        $assistant_settings = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        if (!is_array($assistant_settings)) $assistant_settings = [];

        // Datos de lead (si se enviaron)
        $lead_payload_input = isset($_POST['lead_data']) ? self::sanitize_lead_payload(wp_unslash($_POST['lead_data'])) : [];
         if (!empty($lead_payload_input) && class_exists('AICP_Lead_Manager')) {
             $lead_payload_input = AICP_Lead_Manager::sanitize_lead_input($lead_payload_input, $assistant_id, $assistant_settings);
         }


        // Decidir si usar webhook
        $use_webhook = !empty($assistant_settings['forward_to_webhook']) && !empty($assistant_settings['forward_webhook_url']);

        // Calcular prompt maestro
        if (!class_exists('AICP_Prompt_Builder') && defined('AICP_PLUGIN_DIR') && file_exists(AICP_PLUGIN_DIR . 'includes/class-prompt-builder.php')) {
            require_once AICP_PLUGIN_DIR . 'includes/class-prompt-builder.php';
        }
        $system_prompt = class_exists('AICP_Prompt_Builder') ? AICP_Prompt_Builder::build($assistant_settings, $page_context) : 'Eres un asistente.';

        // Conversación para API/Webhook
        $full_conversation_for_api = [];
        if ('' !== trim($system_prompt)) $full_conversation_for_api[] = ['role' => 'system', 'content' => $system_prompt];
        foreach ($history as $item) $full_conversation_for_api[] = $item;

        $reply = ''; $metadata = [];

        // --- LÓGICA DE WEBHOOK o API ---
        if ($use_webhook) {
            $webhook_result = self::call_message_webhook($assistant_id, $assistant_settings, $session_id, $full_conversation_for_api, $page_context, $lead_payload_input, $system_prompt);
            if (is_wp_error($webhook_result)) {
                wp_send_json_error(['message' => $webhook_result->get_error_message()]); return;
            }
            $reply = $webhook_result['reply'];
            $metadata = $webhook_result['metadata'] ?? [];
        } else {
            if (!class_exists('AICP_Model_Router')) {
                require_once AICP_PLUGIN_DIR . 'includes/class-model-router.php';
            }
            if (!class_exists('AICP_Crypto_Helper')) {
                require_once AICP_PLUGIN_DIR . 'includes/class-crypto-helper.php';
            }

            $providers = get_option('aicp_model_providers', []);
            $provider_key = $assistant_settings['provider'] ?? 'openai';
            $provider_settings = $providers[$provider_key] ?? [];

            if (!empty($provider_settings['api_key'])) {
                $provider_settings['api_key'] = AICP_Crypto_Helper::decrypt($provider_settings['api_key']);
            }

            $driver = AICP_Model_Router::get_driver($provider_key);
            $driver_result = $driver->send_message($system_prompt, $history, $provider_settings);

            if (is_wp_error($driver_result)) {
                wp_send_json_error(['message' => $driver_result->get_error_message()]); return;
            }

            $reply = $driver_result;
        }
        // --- FIN LÓGICA WEBHOOK o API ---

        if ('' === $reply) {
             wp_send_json_error(['message' => __('La respuesta generada está vacía.', 'ai-chatbot-pro')]); return;
        }

        // Guardar conversación y detectar lead
        $history_to_save = $history;
        $history_to_save[] = ['role' => 'assistant', 'content' => $reply];

        $lead_info = ['is_complete' => false, 'has_lead' => false, 'missing_fields' => [], 'data' => []];
         if (!class_exists('AICP_Lead_Manager') && defined('AICP_PLUGIN_DIR') && file_exists(AICP_PLUGIN_DIR . 'includes/class-lead-manager.php')) {
             require_once AICP_PLUGIN_DIR . 'includes/class-lead-manager.php';
         }
        if (class_exists('AICP_Lead_Manager')) {
            // Si usamos webhook, los datos de lead son solo informativos localmente
            // Si usamos API local, estos son los datos que se guardarán si están completos
            $lead_info = AICP_Lead_Manager::detect_contact_data($history_to_save, $assistant_id, $assistant_settings);
        }

        // Guardar usando la función de esta clase
        $lead_payload_final = ($lead_info['is_complete'] && !$use_webhook) ? $lead_info['data'] : []; // Solo guardar si está completo Y NO es webhook
        $new_log_id = self::save_conversation($log_id, $assistant_id, $session_id, $history_to_save, $lead_payload_final);

        // Enviar respuesta al frontend
        $response_payload = [
            'reply'          => $reply,
            'log_id'         => $new_log_id,
            'lead_status'    => $lead_info['is_complete'] ? 'complete' : ($lead_info['has_lead'] ? 'partial' : 'none'),
            'missing_fields' => $lead_info['missing_fields'] ?? [],
            'session_id'     => $session_id,
        ];
        if (!empty($metadata)) { // Añadir metadatos del webhook si existen
            $response_payload['webhook_metadata'] = $metadata;
        }
        wp_send_json_success($response_payload);
        exit; // Terminar
    }


    /**
     * Maneja el borrado de un log.
     */
    public static function handle_delete_log() {
        check_ajax_referer('aicp_delete_log_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error(['message' => __('No tienes permisos.', 'ai-chatbot-pro')]); return; }
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        if (!$log_id) { wp_send_json_error(['message' => __('ID de log inválido.', 'ai-chatbot-pro')]); return; }
        global $wpdb;
        $deleted = $wpdb->delete($wpdb->prefix . 'aicp_chat_logs', ['id' => $log_id], ['%d']);
        if ($deleted) { wp_send_json_success(); }
        else { wp_send_json_error(['message' => __('No se pudo borrar el registro.', 'ai-chatbot-pro')]); }
        exit;
    }

    /**
     * Maneja la obtención de detalles de un log.
     */
    public static function handle_get_log_details() {
        check_ajax_referer('aicp_get_log_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error(['message' => __('No tienes permisos.', 'ai-chatbot-pro')]); return; }
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        if (!$log_id) { wp_send_json_error(['message' => __('ID de log inválido.', 'ai-chatbot-pro')]); return; }
        global $wpdb;
        $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}aicp_chat_logs WHERE id = %d", $log_id));
        if (!$log) { wp_send_json_error(['message' => __('Log no encontrado.', 'ai-chatbot-pro')]); return; }

        wp_send_json_success([
            'conversation' => json_decode($log->conversation_log, true) ?: [], // Devolver array vacío si falla decode
            'lead_data'    => json_decode($log->lead_data, true) ?: [],      // Devolver array vacío si falla decode
            'has_lead'     => (bool)$log->has_lead,
            'timestamp'    => $log->timestamp,
            'session_id'   => $log->session_id
        ]);
        exit;
    }

    /**
     * Maneja la finalización del chat (análisis final).
     */
    public static function handle_finalize_chat() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');
        // Lógica similar a handle_chat_request pero sin enviar a API/Webhook, solo guarda y analiza lead.
        // ... (obtener datos, cargar historial, etc.) ...
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $history_input= isset($_POST['conversation']) && is_array($_POST['conversation']) ? wp_unslash($_POST['conversation']) : []; // El frontend envía 'conversation' aquí
        $log_id       = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $incoming_session_id = isset($_POST['session_id']) ? wp_unslash($_POST['session_id']) : '';

        if (empty($assistant_id)) { wp_send_json_error(['message' => __('ID inválido.', 'ai-chatbot-pro')]); return; }

        $session_id = self::ensure_session_id($incoming_session_id);
        $client_ip = self::get_client_ip();
        $history = [];
        // Cargar/Persistir usando Session_Memory
         if (class_exists('AICP_Session_Memory')) {
             if (!empty($history_input)) { $history = AICP_Session_Memory::persist($session_id, $client_ip, $history_input, $assistant_id); }
             else { $history = AICP_Session_Memory::load($session_id, $client_ip, $assistant_id); }
         } else { /* ... fallback ... */ }

        if (empty($history)) { wp_send_json_error(['message' => __('Historial vacío.', 'ai-chatbot-pro')]); return; }

        // Analizar lead
        $lead_info = ['is_complete' => false, 'has_lead' => false, 'missing_fields' => [], 'data' => []];
        if (class_exists('AICP_Lead_Manager')) {
             $lead_info = AICP_Lead_Manager::detect_contact_data($history, $assistant_id);
        }

        // Guardar (actualizar) el log final
        $lead_payload_final = $lead_info['is_complete'] ? $lead_info['data'] : [];
        $new_log_id = self::save_conversation($log_id, $assistant_id, $session_id, $history, $lead_payload_final);

        // Responder estado del lead
        wp_send_json_success([
            'status' => $lead_info['is_complete'] ? 'complete' : ($lead_info['has_lead'] ? 'partial' : 'none'),
            'missing_fields' => $lead_info['missing_fields'] ?? [],
            'log_id' => $new_log_id,
            'session_id' => $session_id
        ]);
        exit;
    }


    /**
     * Maneja el envío de feedback.
     */
    public static function handle_submit_feedback() {
        check_ajax_referer('aicp_feedback_nonce', 'nonce');
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $feedback = isset($_POST['feedback']) ? intval($_POST['feedback']) : 0;
        if (!$log_id || !in_array($feedback, [1, -1])) { wp_send_json_error(['message' => __('Datos inválidos.', 'ai-chatbot-pro')]); return; }
        global $wpdb;
        $updated = $wpdb->update($wpdb->prefix . 'aicp_chat_logs', ['feedback' => $feedback], ['id' => $log_id], ['%d'], ['%d']);
        if ($updated !== false) { wp_send_json_success(); }
        else { wp_send_json_error(['message' => __('No se pudo guardar el feedback.', 'ai-chatbot-pro')]); }
        exit;
    }

    /**
     * Maneja el envío de un formulario de lead explícito (si se implementa).
     */
    public static function handle_submit_lead_form() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $answers = isset($_POST['answers']) && is_array($_POST['answers']) ? wp_unslash($_POST['answers']) : [];
        if (!$assistant_id || empty($answers)) { wp_send_json_error(['message' => __('Datos incompletos.', 'ai-chatbot-pro')]); return; }

        // Sanitizar y validar respuestas
        $sanitized_answers = [];
        if (class_exists('AICP_Lead_Manager')) {
             $sanitized_answers = AICP_Lead_Manager::sanitize_lead_input($answers, $assistant_id);
        } else { /* ... fallback sanitización ... */ }

        $missing_fields = [];
        if (class_exists('AICP_Lead_Manager')) {
            $missing_fields = AICP_Lead_Manager::get_missing_fields($sanitized_answers, $assistant_id);
        }
        $lead_status = empty($missing_fields) ? 'complete' : 'incomplete'; // Cambiado de 'incomplete' a 'partial' podría tener sentido

        if ($lead_status === 'complete') {
            // Guardar el lead (podría crear un nuevo log o asociarlo a uno existente si se pasa log_id)
            $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0; // Obtener log_id si se envió
            $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : self::ensure_session_id('');
            // Podríamos querer guardar una 'conversación' mínima indicando que el lead vino del form
            $conversation = [['role' => 'system', 'content' => 'Lead capturado vía formulario explícito.'], ['role' => 'user', 'content' => json_encode($sanitized_answers)]];
            self::save_conversation($log_id, $assistant_id, $session_id, $conversation, $sanitized_answers);
            // Disparar acción
            do_action('aicp_lead_detected', $sanitized_answers, $assistant_id, $log_id > 0 ? $log_id : 0, 'form'); // 'form' como status
        }

        $response = ['message' => __('Formulario enviado', 'ai-chatbot-pro'), 'status' => $lead_status];
        if ($lead_status !== 'complete') $response['missing_fields'] = $missing_fields;
        wp_send_json_success($response);
        exit;
    }

} // Fin de la clase AICP_Ajax_Handler

