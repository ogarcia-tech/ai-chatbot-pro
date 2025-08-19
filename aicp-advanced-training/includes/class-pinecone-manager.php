<?php
if (!defined('ABSPATH')) exit;

class AICP_Pinecone_Manager {

    /**
     * Maneja la petición de sincronización con diagnóstico de errores mejorado.
     */
    public static function handle_sync_request() {
        // 1. Comprobaciones Iniciales y de Seguridad
        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        if ($assistant_id === 0) wp_send_json_error(['message' => 'Error Crítico: No se ha identificado al asistente.']);
        
        $settings = get_option('aicp_settings');
        $openai_api_key = $settings['api_key'] ?? '';
        $pinecone_api_key = $settings['pinecone_api_key'] ?? '';
        $pinecone_host = $settings['pinecone_host'] ?? '';

        if (empty($openai_api_key)) wp_send_json_error(['message' => 'Error de Configuración: Falta la API Key de OpenAI en Ajustes Generales.']);
        if (empty($pinecone_api_key)) wp_send_json_error(['message' => 'Error de Configuración: Falta la API Key de Pinecone en Ajustes Generales.']);
        if (empty($pinecone_host)) wp_send_json_error(['message' => 'Error de Configuración: Falta el Host de Pinecone en Ajustes Generales.']);

        // 2. Recoger y Preparar Contenido de WordPress
        $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
        $cpt_slugs = isset($_POST['cpt_slugs']) && is_array($_POST['cpt_slugs']) ? array_map('sanitize_text_field', $_POST['cpt_slugs']) : [];

        if (!empty($cpt_slugs)) {
            $cpt_post_ids = get_posts(['post_type' => $cpt_slugs, 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids']);
            if (!empty($cpt_post_ids)) $post_ids = array_unique(array_merge($post_ids, $cpt_post_ids));
        }

        if (empty($post_ids)) wp_send_json_error(['message' => 'No se encontró contenido publicable para sincronizar.']);
        
        $posts_to_index = get_posts(['post__in' => $post_ids, 'post_type' => 'any', 'posts_per_page' => -1, 'post_status' => 'publish']);
        
        if (empty($posts_to_index)) wp_send_json_success(['message' => '0 fragmentos procesados (el contenido seleccionado podría no estar publicado).', 'count' => 0]);

        // 3. Procesar y Subir a Pinecone
        $processed_count = 0;
        foreach ($posts_to_index as $post) {
            $full_content = self::get_full_post_content($post);
            $chunks = self::chunk_content(self::prepare_content($full_content));
            
            foreach ($chunks as $index => $chunk) {
                if (empty(trim($chunk))) continue;

                // Crear embedding (Paso 2)
                $vector = self::create_embedding($chunk, $openai_api_key);
                if (is_wp_error($vector)) {
                    wp_send_json_error(['message' => 'Error de OpenAI: ' . $vector->get_error_message()]);
                }

                // Subir a Pinecone (Paso 3)
                $vector_id = $assistant_id . '-' . $post->ID . '-' . $index;
                $metadata = ['assistant_id' => $assistant_id, 'post_id' => $post->ID, 'text' => $chunk];
                $upsert_result = self::upsert_to_pinecone(['id' => $vector_id, 'values' => $vector, 'metadata' => $metadata], $pinecone_api_key, $pinecone_host);
                
                if (is_wp_error($upsert_result)) {
                    wp_send_json_error(['message' => 'Error de Pinecone: ' . $upsert_result->get_error_message()]);
                }
                
                $processed_count++;
            }
        }

        update_post_meta($assistant_id, '_aicp_chunks_count', $processed_count);
        wp_send_json_success(['message' => sprintf('%d fragmentos procesados e indexados correctamente.', $processed_count), 'count' => $processed_count]);
    }

    private static function get_full_post_content($post) {
        $full_content = $post->post_title . "\n" . $post->post_content;
        if (function_exists('get_fields')) {
            $acf_fields = get_fields($post->ID);
            if (is_array($acf_fields)) {
                foreach ($acf_fields as $field_value) {
                    if (is_string($field_value) && !empty($field_value)) {
                        $full_content .= "\n" . strip_tags($field_value);
                    }
                }
            }
        }
        return $full_content;
    }

    public static function query_pinecone($query, $assistant_id) { /* ... Sin cambios ... */ }
    private static function prepare_content($content) { /* ... Sin cambios ... */ }
    private static function chunk_content($text, $chunk_size = 500) { /* ... Sin cambios ... */ }

    private static function create_embedding($text, $openai_api_key) {
        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'method'  => 'POST',
            'headers' => ['Content-Type'  => 'application/json', 'Authorization' => 'Bearer ' . $openai_api_key],
            'body'    => wp_json_encode(['model' => 'text-embedding-ada-002', 'input' => $text]),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) return $response;
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code !== 200) {
            $error_message = $body['error']['message'] ?? 'Error desconocido al conectar con OpenAI.';
            return new WP_Error('openai_error', $error_message);
        }
        
        return $body['data'][0]['embedding'] ?? new WP_Error('openai_error', 'Respuesta inesperada de OpenAI.');
    }

    private static function upsert_to_pinecone($vector_data, $pinecone_api_key, $pinecone_host) {
        $response = wp_remote_post($pinecone_host . '/vectors/upsert', [
            'method'  => 'POST',
            'headers' => ['Content-Type'  => 'application/json', 'Api-Key' => $pinecone_api_key],
            'body'    => wp_json_encode(['vectors' => [$vector_data]]),
            'timeout' => 60,
        ]);
        
        if (is_wp_error($response)) return $response;

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = $body['message'] ?? 'Error desconocido al conectar con Pinecone.';
            return new WP_Error('pinecone_error', "Código de error $response_code: $error_message");
        }
        
        return true;
    }
}
