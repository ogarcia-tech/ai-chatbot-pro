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
    private static function curl_upload_file($file_path, $filename) {
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
            'file'    => new CURLFile($file_path, 'text/plain', $filename)
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
        if (!empty($cpt_slugs)) {
            $cpt_posts = get_posts(['post_type' => $cpt_slugs, 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids']);
            if (!empty($cpt_posts)) $post_ids = array_unique(array_merge($post_ids, $cpt_posts));
        }
        if (empty($post_ids)) wp_send_json_error(['message' => 'No se encontró contenido para sincronizar.']);
        
        $posts_to_index = get_posts(['post__in' => $post_ids, 'post_type' => 'any', 'posts_per_page' => -1, 'post_status' => 'publish']);
        if (empty($posts_to_index)) wp_send_json_error(['message' => 'El contenido seleccionado no está publicado.']);

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
        
        $response = self::curl_upload_file($temp_file_path, $filename);
        @unlink($temp_file_path);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Paso 1/3 Fallido (Subida de Archivo): ' . $response->get_error_message()]);
        }
        $file_id = $response['id'];

        $vector_store_id = get_post_meta($assistant_id_wp, '_aicp_vector_store_id', true);
        if (empty($vector_store_id)) {
            $vs_response = self::remote_request('vector_stores', ['name' => "Knowledgebase for Assistant {$assistant_id_wp}", 'file_ids' => [$file_id]]);
            $vs_body = json_decode(wp_remote_retrieve_body($vs_response), true);
            if (wp_remote_retrieve_response_code($vs_response) !== 200) {
                 wp_send_json_error(['message' => "Paso 2/3 Fallido (Crear Vector Store): " . ($vs_body['error']['message'] ?? 'Error desconocido')]);
            }
            $vector_store_id = $vs_body['id'];
            update_post_meta($assistant_id_wp, '_aicp_vector_store_id', $vector_store_id);
        } else {
            self::remote_request("vector_stores/{$vector_store_id}/files", ['file_id' => $file_id]);
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
        update_post_meta($assistant_id_wp, '_aicp_last_sync_count', count($posts_to_index));
        update_post_meta($assistant_id_wp, '_aicp_last_sync_time', time());

        wp_send_json_success(['message' => '¡Sincronización con OpenAI completada!', 'count' => count($posts_to_index)]);
    }

    public static function handle_chat($assistant_id_wp, $user_message, $session_id) {
        $openai_assistant_id = get_post_meta($assistant_id_wp, '_aicp_openai_assistant_id', true);
        if (empty($openai_assistant_id)) return new WP_Error('config_error', 'Este asistente no está sincronizado.');

        $thread_id = get_transient("aicp_thread_{$session_id}");
        if (empty($thread_id)) {
            $response = self::remote_request('threads', []);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $thread_id = $body['id'];
            set_transient("aicp_thread_{$session_id}", $thread_id, DAY_IN_SECONDS);
        }

        self::remote_request("threads/{$thread_id}/messages", ['role' => 'user', 'content' => $user_message]);
        
        $run_response = self::remote_request("threads/{$thread_id}/runs", ['assistant_id' => $openai_assistant_id]);
        $run_body = json_decode(wp_remote_retrieve_body($run_response), true);
        if(empty($run_body['id'])) return new WP_Error('run_error', 'No se pudo iniciar la ejecución del asistente en OpenAI.');
        $run_id = $run_body['id'];

        $start_time = time();
        while (time() - $start_time < 30) {
            $run_status_response = self::remote_request("threads/{$thread_id}/runs/{$run_id}", [], 'GET');
            $run_status_body = json_decode(wp_remote_retrieve_body($run_status_response), true);
            if (in_array($run_status_body['status'], ['completed', 'failed', 'cancelled'])) break;
            sleep(1);
        }

        if ($run_status_body['status'] !== 'completed') return new WP_Error('run_error', 'La IA tardó demasiado en responder o encontró un error. Estado: ' . $run_status_body['status']);
        
        $messages_response = self::remote_request("threads/{$thread_id}/messages?limit=1", [], 'GET');
        $messages_body = json_decode(wp_remote_retrieve_body($messages_response), true);

        if (!empty($messages_body['data'][0]['content'][0]['text']['value'])) {
             $raw_response = $messages_body['data'][0]['content'][0]['text']['value'];
             $clean_response = preg_replace('/【.*?】/u', '', $raw_response);
             return trim($clean_response);
        }

        return new WP_Error('no_response', 'La IA no proporcionó una respuesta válida.');
    }
}