<?php
if (!defined('ABSPATH')) exit;

class AICP_Pinecone_Manager {

    /**
     * Maneja la petición de sincronización.
     */
    public static function handle_sync_request() {
        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        if ($assistant_id === 0) {
            wp_send_json_error(['message' => 'Error: No se ha identificado al asistente.']);
        }
        
        $post_ids_to_index = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
        $cpt_slugs_to_index = isset($_POST['cpt_slugs']) && is_array($_POST['cpt_slugs']) ? array_map('sanitize_text_field', $_POST['cpt_slugs']) : [];

        if (!empty($cpt_slugs_to_index)) {
            $cpt_posts = get_posts([
                'post_type' => $cpt_slugs_to_index,
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'fields' => 'ids'
            ]);
            if (!empty($cpt_posts)) {
                $post_ids_to_index = array_unique(array_merge($post_ids_to_index, $cpt_posts));
            }
        }

        if (empty($post_ids_to_index)) {
            wp_send_json_error(['message' => 'No se encontró contenido publicable para sincronizar.']);
        }
        
        $posts_to_index = get_posts([
            'post__in' => $post_ids_to_index,
            'post_type' => 'any',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);
        
        if (empty($posts_to_index)) {
            wp_send_json_success(['message' => '0 fragmentos procesados.', 'count' => 0]);
        }

        $processed_count = 0;
        foreach ($posts_to_index as $post) {
            // --- MODIFICACIÓN: Incluir campos ACF ---
            $full_content = $post->post_title . "\n" . $post->post_content;
            if (function_exists('get_fields')) {
                $acf_fields = get_fields($post->ID);
                if (is_array($acf_fields)) {
                    foreach ($acf_fields as $field_name => $field_value) {
                        if (is_string($field_value) && !empty($field_value)) {
                            $full_content .= "\n" . strip_tags($field_value);
                        }
                    }
                }
            }
            $prepared_content = self::prepare_content($full_content);
            // --- FIN MODIFICACIÓN ---

            $chunks = self::chunk_content($prepared_content);
            
            foreach ($chunks as $index => $chunk) {
                if (empty(trim($chunk))) continue;

                $vector = self::create_embedding($chunk);
                if (is_wp_error($vector)) continue; 

                $vector_id = $assistant_id . '-' . $post->ID . '-' . $index;
                $success = self::upsert_to_pinecone([
                    'id' => $vector_id,
                    'values' => $vector,
                    'metadata' => [
                        'assistant_id' => $assistant_id, 
                        'post_id' => $post->ID, 
                        'text' => $chunk
                    ]
                ]);
                
                if ($success) $processed_count++;
            }
        }

        update_post_meta($assistant_id, '_aicp_chunks_count', $processed_count);
        wp_send_json_success([
            'message' => sprintf('%d fragmentos procesados e indexados.', $processed_count),
            'count' => $processed_count
        ]);
    }

    /**
     * --- NUEVA FUNCIÓN: Consulta a Pinecone para obtener contexto ---
     */
    public static function query_pinecone($query, $assistant_id) {
        $query_vector = self::create_embedding($query);
        if (is_wp_error($query_vector)) return '';

        $settings = get_option('aicp_settings');
        $pinecone_api_key = $settings['pinecone_api_key'] ?? '';
        $pinecone_host = $settings['pinecone_host'] ?? '';
        if (empty($pinecone_api_key) || empty($pinecone_host)) return '';

        $response = wp_remote_post($pinecone_host . '/query', [
            'method'  => 'POST',
            'headers' => ['Content-Type'  => 'application/json', 'Api-Key' => $pinecone_api_key],
            'body'    => json_encode([
                'vector' => $query_vector,
                'topK' => 3, // Obtener los 3 resultados más relevantes
                'includeMetadata' => true,
                'filter' => ['assistant_id' => ['$eq' => $assistant_id]] // Filtrar por el asistente actual
            ]),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) return '';
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $context = '';
        if (isset($body['matches']) && is_array($body['matches'])) {
            foreach ($body['matches'] as $match) {
                if (isset($match['metadata']['text'])) {
                    $context .= $match['metadata']['text'] . "\n---\n";
                }
            }
        }
        return $context;
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
        // ... (código original sin cambios) ...
    }

    private static function upsert_to_pinecone($vector_data) {
        // ... (código original sin cambios) ...
    }
}
