<?php
if (!defined('ABSPATH')) exit;

class AICP_Pinecone_Manager {

    public static function handle_sync_request() {
        check_ajax_referer('aicp_save_meta_box_data', 'nonce');
        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        if ($assistant_id === 0) wp_send_json_error(['message' => 'Error: No se ha identificado al asistente.']);

        $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
        $cpt_slugs = isset($_POST['cpt_slugs']) && is_array($_POST['cpt_slugs']) ? array_map('sanitize_text_field', $_POST['cpt_slugs']) : [];

        if (!empty($cpt_slugs)) {
            $cpt_posts = get_posts(['post_type' => $cpt_slugs, 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids']);
            if (!empty($cpt_posts)) $post_ids = array_unique(array_merge($post_ids, $cpt_posts));
        }

        if (empty($post_ids)) wp_send_json_error(['message' => 'No se encontró contenido publicable para sincronizar.']);
        
        $posts_to_index = get_posts(['post__in' => $post_ids, 'post_type' => 'any', 'posts_per_page' => -1, 'post_status' => 'publish']);
        
        if (empty($posts_to_index)) wp_send_json_success(['message' => '0 fragmentos procesados.', 'count' => 0]);

        $processed_count = 0;
        foreach ($posts_to_index as $post) {
            $full_content = $post->post_title . "\n" . $post->post_content;
            if (function_exists('get_fields')) { // Comprueba si ACF está activo
                $acf_fields = get_fields($post->ID);
                if (is_array($acf_fields)) {
                    foreach ($acf_fields as $field_value) {
                        if (is_string($field_value) && !empty($field_value)) $full_content .= "\n" . strip_tags($field_value);
                    }
                }
            }
            
            $chunks = self::chunk_content(self::prepare_content($full_content));
            
            foreach ($chunks as $index => $chunk) {
                if (empty(trim($chunk))) continue;
                $vector = self::create_embedding($chunk);
                if (is_wp_error($vector)) continue; 

                $vector_id = $assistant_id . '-' . $post->ID . '-' . $index;
                $metadata = ['assistant_id' => $assistant_id, 'post_id' => $post->ID, 'text' => $chunk];
                if (self::upsert_to_pinecone(['id' => $vector_id, 'values' => $vector, 'metadata' => $metadata])) $processed_count++;
            }
        }

        update_post_meta($assistant_id, '_aicp_chunks_count', $processed_count);
        wp_send_json_success(['message' => sprintf('%d fragmentos procesados e indexados.', $processed_count), 'count' => $processed_count]);
    }

    public static function query_pinecone($query, $assistant_id) {
        $query_vector = self::create_embedding($query);
        if (is_wp_error($query_vector)) return '';

        $settings = get_option('aicp_settings');
        $pinecone_api_key = $settings['pinecone_api_key'] ?? '';
        $pinecone_host = $settings['pinecone_host'] ?? '';
        if (empty($pinecone_api_key) || empty($pinecone_host)) return '';

        $response = wp_remote_post($pinecone_host . '/query', [
            'method'  => 'POST',
            'headers' => ['Content-Type' => 'application/json', 'Api-Key' => $pinecone_api_key],
            'body'    => json_encode(['vector' => $query_vector, 'topK' => 3, 'includeMetadata' => true, 'filter' => ['assistant_id' => ['$eq' => $assistant_id]]]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) return '';
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $context = '';
        if (!empty($body['matches'])) {
            foreach ($body['matches'] as $match) {
                if (!empty($match['metadata']['text'])) $context .= $match['metadata']['text'] . "\n---\n";
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
        $settings = get_option('aicp_settings');
        $openai_api_key = $settings['api_key'] ?? '';
        if (empty($openai_api_key)) return new WP_Error('api_error', 'No se ha configurado la API Key de OpenAI.');

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'method'  => 'POST',
            'headers' => ['Content-Type'  => 'application/json', 'Authorization' => 'Bearer ' . $openai_api_key],
            'body'    => json_encode(['model' => 'text-embedding-ada-002', 'input' => $text]),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['data'][0]['embedding'] ?? new WP_Error('api_error', 'Respuesta inesperada de OpenAI.');
    }

    private static function upsert_to_pinecone($vector_data) {
        $settings = get_option('aicp_settings');
        $pinecone_api_key = $settings['pinecone_api_key'] ?? '';
        $pinecone_host = $settings['pinecone_host'] ?? '';
        if (empty($pinecone_api_key) || empty($pinecone_host)) return false;

        $response = wp_remote_post($pinecone_host . '/vectors/upsert', [
            'method'  => 'POST',
            'headers' => ['Content-Type'  => 'application/json', 'Api-Key' => $pinecone_api_key],
            'body'    => json_encode(['vectors' => [$vector_data]]),
            'timeout' => 60,
        ]);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
}
