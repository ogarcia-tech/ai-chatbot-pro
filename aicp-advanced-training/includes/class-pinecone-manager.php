<?php
if (!defined('ABSPATH')) exit;

class AICP_Pinecone_Manager {

    /**
     * Inicia el proceso de sincronización real.
     */
    public static function handle_sync_request() {
        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        if ($assistant_id === 0) {
            wp_send_json_error(['message' => 'Error: No se ha identificado al asistente.']);
        }
        
        // --- INICIO DE LA CORRECCIÓN ---
        // Recibimos los IDs de posts/páginas individuales
        $post_ids_to_index = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
        
        // Recibimos los slugs de los Tipos de Contenido Personalizado (CPT)
        $cpt_slugs_to_index = isset($_POST['cpt_slugs']) && is_array($_POST['cpt_slugs']) ? array_map('sanitize_text_field', $_POST['cpt_slugs']) : [];

        // Si se seleccionaron CPTs, buscamos todos sus posts y añadimos sus IDs a la lista
        if (!empty($cpt_slugs_to_index)) {
            $cpt_posts = get_posts([
                'post_type' => $cpt_slugs_to_index,
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'fields' => 'ids' // Solo necesitamos los IDs para optimizar la consulta
            ]);
            if (!empty($cpt_posts)) {
                // Fusionamos los IDs individuales con los encontrados en los CPTs y eliminamos duplicados
                $post_ids_to_index = array_unique(array_merge($post_ids_to_index, $cpt_posts));
            }
        }
        // --- FIN DE LA CORRECCIÓN ---

        if (empty($post_ids_to_index)) {
            // Se cambia el mensaje de error para ser más claro
            wp_send_json_error(['message' => 'No se encontró contenido publicable para sincronizar con las opciones seleccionadas.']);
        }
        
        $posts_to_index = get_posts([
            'post__in' => $post_ids_to_index,
            'post_type' => 'any',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);
        
        if (empty($posts_to_index)) {
            wp_send_json_success([
                'message' => '0 fragmentos de contenido procesados. Asegúrate de que el contenido seleccionado está publicado.',
                'count' => 0
            ]);
        }

        $processed_count = 0;
        foreach ($posts_to_index as $post) {
            $chunks = self::chunk_content(self::prepare_content($post->post_content));
            
            foreach ($chunks as $index => $chunk) {
                if (empty(trim($chunk))) continue;

                $vector = self::create_embedding($chunk);
                if (is_wp_error($vector)) {
                    continue; 
                }

                $vector_id = $post->ID . '-' . $index;
                $success = self::upsert_to_pinecone([
                    'id' => $vector_id,
                    'values' => $vector,
                    'metadata' => ['post_id' => $post->ID, 'text' => $chunk]
                ]);
                
                if ($success) {
                    $processed_count++;
                }
            }
        }

        update_post_meta($assistant_id, '_aicp_chunks_count', $processed_count);

        wp_send_json_success([
            'message' => sprintf('%d fragmentos de contenido procesados e indexados correctamente.', $processed_count),
            'count' => $processed_count
        ]);
    }

    private static function prepare_content($content) {
        $content = wp_strip_all_tags($content, false);
        $content = preg_replace('/\s+/', ' ', $content);
        return trim($content);
    }

    private static function chunk_content($text, $chunk_size = 500) {
        return str_split($text, $chunk_size);
    }

    private static function create_embedding($text) {
        $settings = get_option('aicp_settings');
        $openai_api_key = $settings['api_key'] ?? '';

        if (empty($openai_api_key)) {
            return new WP_Error('api_error', 'No se ha configurado la API Key de OpenAI.');
        }

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'method'  => 'POST',
            'headers' => ['Content-Type'  => 'application/json', 'Authorization' => 'Bearer ' . $openai_api_key],
            'body'    => json_encode(['model' => 'text-embedding-ada-002', 'input' => $text]),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['data'][0]['embedding'])) {
            return $body['data'][0]['embedding'];
        }
        return new WP_Error('api_error', 'Respuesta inesperada de OpenAI: ' . wp_remote_retrieve_body($response));
    }

    private static function upsert_to_pinecone($vector_data) {
        $settings = get_option('aicp_settings');
        $pinecone_api_key = $settings['pinecone_api_key'] ?? '';
        $pinecone_host = $settings['pinecone_host'] ?? '';

        if (empty($pinecone_api_key) || empty($pinecone_host)) {
            return false;
        }

        $response = wp_remote_post($pinecone_host . '/vectors/upsert', [
            'method'  => 'POST',
            'headers' => ['Content-Type'  => 'application/json', 'Api-Key' => $pinecone_api_key],
            'body'    => json_encode(['vectors' => [$vector_data]]),
            'timeout' => 60,
        ]);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
}
