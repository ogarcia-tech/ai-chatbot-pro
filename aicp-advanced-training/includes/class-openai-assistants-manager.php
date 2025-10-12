<?php
if (!defined('ABSPATH')) exit;

class AICP_OpenAI_Assistants_Manager {

    private static function get_api_key() {
        $settings = get_option('aicp_settings');
        return $settings['api_key'] ?? null;
    }

    /**
     * Nueva función de subida de archivos usando cURL directo para máxima compatibilidad.
     */
    private static function curl_upload_file($file_path, $filename, $mime_type = 'application/octet-stream') {
        $api_key = self::get_api_key();
        if (!$api_key) return new WP_Error('api_error', 'Falta la API Key de OpenAI.');

        if (!function_exists('curl_init')) {
            return new WP_Error('curl_missing', 'La extensión cURL de PHP no está activada en tu servidor. Contacta con tu hosting.');
        }
        if (!class_exists('CURLFile')) {
            return new WP_Error('curlfile_missing', 'La clase CURLFile no está disponible. Requiere PHP 5.5 o superior.');
        }

        $ch = curl_init();

        $post_fields = [
            'purpose' => 'assistants',
            'file'    => new CURLFile($file_path, $mime_type, $filename)
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.openai.com/v1/files',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'OpenAI-Beta: assistants=v2'
            ],
            CURLOPT_TIMEOUT => 120, // Aumentamos el tiempo de espera a 2 minutos
        ]);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return new WP_Error('curl_error', 'Error de cURL: ' . $curl_error);
        }
        
        $response_body = json_decode($result, true);

        if ($http_code !== 200) {
            $error_message = $response_body['error']['message'] ?? 'Error desconocido durante la subida del archivo.';
            return new WP_Error('upload_error', "Error de OpenAI ($http_code): $error_message");
        }

        return $response_body;
    }
    
    private static function remote_request($endpoint, $body = [], $method = 'POST') {
        $api_key = self::get_api_key();
        if (!$api_key) return new WP_Error('api_error', 'Falta la API Key de OpenAI.');

        $args = [
            'method'  => $method,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'OpenAI-Beta'   => 'assistants=v2'
            ],
            'timeout' => 60,
        ];
        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }
        return wp_remote_request('https://api.openai.com/v1/' . $endpoint, $args);
    }

    public static function check_api_connection() {
        $response = self::remote_request('models', [], 'GET');
        if(is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return new WP_Error('auth_error', "Error de OpenAI ($code): " . ($body['error']['message'] ?? 'Desconocido'));
        }
        return true;
    }

    public static function handle_sync_request() {
        $assistant_id_wp = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        
        $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
        $cpt_slugs = isset($_POST['cpt_slugs']) && is_array($_POST['cpt_slugs']) ? array_map('sanitize_text_field', $_POST['cpt_slugs']) : [];
        $file_ids_input = $_POST['file_ids'] ?? [];
        if (is_string($file_ids_input)) {
            $file_ids_input = explode(',', $file_ids_input);
        }
        $attachment_ids = is_array($file_ids_input) ? array_filter(array_map('intval', $file_ids_input)) : [];
        $attachment_ids = array_values(array_unique($attachment_ids));

        if (!empty($cpt_slugs)) {
            $cpt_posts = get_posts(['post_type' => $cpt_slugs, 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids']);
            if (!empty($cpt_posts)) $post_ids = array_unique(array_merge($post_ids, $cpt_posts));
        }
        if (empty($post_ids) && empty($attachment_ids)) wp_send_json_error(['message' => 'No se encontró contenido para sincronizar.']);

        $posts_to_index = [];
        if (!empty($post_ids)) {
            $posts_to_index = get_posts(['post__in' => $post_ids, 'post_type' => 'any', 'posts_per_page' => -1, 'post_status' => 'publish']);
            if (empty($posts_to_index) && empty($attachment_ids)) {
                wp_send_json_error(['message' => 'El contenido seleccionado no está publicado.']);
            }
        }

        $uploaded_file_ids = [];
        $total_sources = 0;

        if (!empty($posts_to_index)) {
            $file_content = "";
            foreach ($posts_to_index as $post) {
                $file_content .= "== Título: " . $post->post_title . " ==\nURL: " . get_permalink($post->ID) . "\n\n";
                $content = strip_tags($post->post_content);
                $content = preg_replace('/\s+/', ' ', $content);
                $file_content .= trim($content) . "\n\n---\n\n";
            }

            $temp_file_path = wp_tempnam('aicp_sync_');
            file_put_contents($temp_file_path, $file_content);
            $filename = "wordpress-content-{$assistant_id_wp}-" . time() . ".txt";

            $response = self::curl_upload_file($temp_file_path, $filename, 'text/plain');
            @unlink($temp_file_path);

            if (is_wp_error($response)) {
                wp_send_json_error(['message' => 'Paso 1/3 Fallido (Subida de Archivo): ' . $response->get_error_message()]);
            }
            $uploaded_file_ids[] = $response['id'];
            $total_sources += count($posts_to_index);
        }

        if (!empty($attachment_ids)) {
            foreach ($attachment_ids as $attachment_id) {
                $file_path = get_attached_file($attachment_id);
                if (!$file_path || !file_exists($file_path)) {
                    wp_send_json_error(['message' => sprintf('El archivo personalizado (ID %d) no está disponible en el servidor.', $attachment_id)]);
                }
                $filename = basename($file_path);
                $mime_type = get_post_mime_type($attachment_id);
                if (empty($mime_type)) {
                    $filetype = wp_check_filetype($filename);
                    $mime_type = $filetype['type'] ?: 'application/octet-stream';
                }
                $response = self::curl_upload_file($file_path, $filename, $mime_type);
                if (is_wp_error($response)) {
                    wp_send_json_error(['message' => 'Error al subir archivo personalizado (ID ' . $attachment_id . '): ' . $response->get_error_message()]);
                }
                $uploaded_file_ids[] = $response['id'];
                $total_sources++;
            }
        }

        if (empty($uploaded_file_ids)) {
            wp_send_json_error(['message' => 'No se pudo subir ningún archivo a OpenAI.']);
        }

        $vector_store_id = get_post_meta($assistant_id_wp, '_aicp_vector_store_id', true);
        if (empty($vector_store_id)) {
            $vs_response = self::remote_request('vector_stores', ['name' => "Knowledgebase for Assistant {$assistant_id_wp}", 'file_ids' => $uploaded_file_ids]);
            $vs_body = json_decode(wp_remote_retrieve_body($vs_response), true);
            if (wp_remote_retrieve_response_code($vs_response) !== 200) {
                 wp_send_json_error(['message' => "Paso 2/3 Fallido (Crear Vector Store): " . ($vs_body['error']['message'] ?? 'Error desconocido')]);
            }
            $vector_store_id = $vs_body['id'];
            update_post_meta($assistant_id_wp, '_aicp_vector_store_id', $vector_store_id);
        } else {
            foreach ($uploaded_file_ids as $file_id) {
                self::remote_request("vector_stores/{$vector_store_id}/files", ['file_id' => $file_id]);
            }
        }
        
        $s = get_post_meta($assistant_id_wp, '_aicp_assistant_settings', true);
        // --- INICIO DE LA MODIFICACIÓN DE INSTRUCCIONES ---
        if (empty($s['persona'])) {
            $s['persona'] = 'Eres un consultor experto de Suple.ai. Tu principal objetivo es entender las necesidades del cliente, explicar cómo Suple.ai puede ayudarle y animarle a agendar una llamada o demo para convertirlo en un lead.';
        }
        $base_prompt = AICP_Prompt_Builder::build($s);
        $behavior_rules = $s['behavior_rules'] ?? '';

        // Si el usuario no ha personalizado las reglas, usamos unas nuevas reglas por defecto mucho más inteligentes.
        if (empty($behavior_rules)) {
            $behavior_rules = "1. **Directiva Principal:** Tu personalidad y objetivo principal (descritos arriba) mandan sobre todo lo demás. Siempre hablas como un consultor de la empresa y tu meta final es capturar un lead.\n\n";
            $behavior_rules .= "2. **Herramienta Secundaria (Base de Conocimiento):** Tienes acceso a documentos con información específica. ÚSALOS SÓLO cuando un usuario haga una pregunta fáctica concreta (sobre precios, fechas, características, etc.).\n\n";
            $behavior_rules .= "3. **Lógica de Actuación:** Para saludos y preguntas generales, usa tu personalidad. Para preguntas específicas, consulta tu base de conocimiento. Después de responder una pregunta específica, DEBES volver a tu objetivo principal y continuar la conversación para captar el lead. Ejemplo: 'La charla es el 29 de mayo. ¿Te gustaría que te avisemos cuando se abran las inscripciones? Puedo tomar nota de tu email.'\n\n";
            $behavior_rules .= "4. **Reglas Finales (Inquebrantables):**\n";
            $behavior_rules .= "   - NUNCA menciones tu 'base de conocimiento', 'archivos' o 'documentos'.\n";
            $behavior_rules .= "   - NUNCA incluyas citas o referencias como 【...】.\n";
            $behavior_rules .= "   - Habla siempre en nombre de la empresa ('nosotros en Suple.ai...', 'podemos ayudarte a...').";
        }

        $instructions = $base_prompt;
        if (!empty($behavior_rules)) {
            $instructions .= "\n\n== REGLAS DE COMPORTAMIENTO Y USO DE HERRAMIENTAS ==\n" . $behavior_rules;
        }
        // --- FIN DE LA MODIFICACIÓN DE INSTRUCCIONES ---
        $assistant_config = [
            'model' => 'gpt-4o', 'name' => get_the_title($assistant_id_wp), 'instructions' => $instructions,
            'tools' => [['type' => 'file_search']], 'tool_resources' => ['file_search' => ['vector_store_ids' => [$vector_store_id]]]
        ];

        $openai_assistant_id = get_post_meta($assistant_id_wp, '_aicp_openai_assistant_id', true);
        $request_method = empty($openai_assistant_id) ? 'assistants' : "assistants/{$openai_assistant_id}";
        $response = self::remote_request($request_method, $assistant_config);
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200) {
            wp_send_json_error(['message' => "Paso 3/3 Fallido (Crear Asistente): " . ($body['error']['message'] ?? 'Error desconocido')]);
        }
        
        update_post_meta($assistant_id_wp, '_aicp_openai_assistant_id', $body['id']);
        update_post_meta($assistant_id_wp, '_aicp_last_sync_count', $total_sources);
        update_post_meta($assistant_id_wp, '_aicp_last_sync_time', time());

        wp_send_json_success(['message' => '¡Sincronización con OpenAI completada!', 'count' => $total_sources, 'files' => count($uploaded_file_ids)]);
    }

    private static function sanitize_session_id($session_id) {
        $session_id = is_string($session_id) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $session_id) : '';
        if (empty($session_id)) {
            $session_id = 'aicp_' . wp_generate_uuid4();
        }
        return $session_id;
    }

    private static function get_thread_transient_key($session_id) {
        return 'aicp_thread_' . md5($session_id);
    }

    public static function handle_chat($assistant_id_wp, $user_message, $session_id = '') {
        $openai_assistant_id = get_post_meta($assistant_id_wp, '_aicp_openai_assistant_id', true);
        if (empty($openai_assistant_id)) {
            return new WP_Error('config_error', 'Este asistente no está sincronizado.');
        }

        $session_id = self::sanitize_session_id($session_id);
        $thread_key = self::get_thread_transient_key($session_id);

        $thread_id = get_transient($thread_key);
        if (empty($thread_id)) {
            $response = self::remote_request('threads', []);
            if (is_wp_error($response)) {
                return $response;
            }
            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if ($code !== 200 || empty($body['id'])) {
                $message = $body['error']['message'] ?? 'No se pudo crear el hilo en OpenAI.';
                return new WP_Error('thread_error', $message);
            }
            $thread_id = $body['id'];
            set_transient($thread_key, $thread_id, DAY_IN_SECONDS);
        }

        $message_response = self::remote_request("threads/{$thread_id}/messages", ['role' => 'user', 'content' => $user_message]);
        if (is_wp_error($message_response)) {
            return $message_response;
        }

        $run_response = self::remote_request("threads/{$thread_id}/runs", ['assistant_id' => $openai_assistant_id]);
        if (is_wp_error($run_response)) {
            return $run_response;
        }
        $run_body = json_decode(wp_remote_retrieve_body($run_response), true);
        if (empty($run_body['id'])) {
            $error_message = $run_body['error']['message'] ?? 'No se pudo iniciar la ejecución del asistente en OpenAI.';
            return new WP_Error('run_error', $error_message);
        }
        $run_id = $run_body['id'];

        $start_time = time();
        $run_status_body = null;
        while (time() - $start_time < 30) {
            $run_status_response = self::remote_request("threads/{$thread_id}/runs/{$run_id}", [], 'GET');
            if (is_wp_error($run_status_response)) {
                return $run_status_response;
            }
            $run_status_body = json_decode(wp_remote_retrieve_body($run_status_response), true);
            if (isset($run_status_body['status']) && in_array($run_status_body['status'], ['completed', 'failed', 'cancelled'], true)) {
                break;
            }
            sleep(1);
        }

        if (!isset($run_status_body['status']) || $run_status_body['status'] !== 'completed') {
            $status = $run_status_body['status'] ?? 'unknown';
            return new WP_Error('run_error', 'La IA tardó demasiado en responder o encontró un error. Estado: ' . $status);
        }

        $messages_response = self::remote_request("threads/{$thread_id}/messages?limit=1", [], 'GET');
        if (is_wp_error($messages_response)) {
            return $messages_response;
        }
        $messages_body = json_decode(wp_remote_retrieve_body($messages_response), true);

        if (!empty($messages_body['data'][0]['content'][0]['text']['value'])) {
            $raw_response = $messages_body['data'][0]['content'][0]['text']['value'];
            $clean_response = preg_replace('/【.*?】/u', '', $raw_response);
            return [
                'reply' => trim($clean_response),
                'session_id' => $session_id,
            ];
        }

        return new WP_Error('no_response', 'La IA no proporcionó una respuesta válida.');
    }
}