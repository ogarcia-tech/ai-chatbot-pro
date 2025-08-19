<?php
/**
 * Clase que maneja la búsqueda de contenido para el entrenamiento.
 *
 * @package AI_Chatbot_Pro
 */
if (!defined('ABSPATH')) exit;

class AICP_Training_Handler {

    /**
     * Obtiene contexto relevante usando la búsqueda nativa de WordPress.
     * Es rápido, estable y no sobrecarga el servidor.
     *
     * @param string $query La pregunta del usuario.
     * @param int $assistant_id El ID del asistente.
     * @return string El contexto encontrado.
     */
    public static function get_relevant_context($query, $assistant_id) {
        $settings = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        if (!is_array($settings)) {
            $settings = [];
        }
        $post_types = $settings['training_post_types'] ?? [];

        if (empty($query) || empty($post_types)) {
            return '';
        }

        $search_args = [
            's' => sanitize_text_field($query),
            'post_type' => $post_types,
            'posts_per_page' => 3, // Coger los 3 resultados más relevantes
            'post_status' => 'publish',
            'orderby' => 'relevance',
        ];

        $search_query = new WP_Query($search_args);
        $context = '';

        if ($search_query->have_posts()) {
            foreach ($search_query->posts as $post) {
                // Usamos el objeto $post directamente para mayor estabilidad en AJAX
                $content = wp_strip_all_tags($post->post_content);
                $content = preg_replace('/\s+/', ' ', $content); // Reemplazar múltiples espacios/saltos de línea con uno solo
                
                $context .= "Título: " . esc_html($post->post_title) . "\nContenido: " . $content . "\n\n---\n\n";
            }
        }
        
        // Limitar la longitud total del contexto para no exceder los límites del prompt
        if (mb_strlen($context) > 3000) {
            $context = mb_substr($context, 0, 3000) . '...';
        }

        return $context;
    }

    // Las funciones de embeddings se dejan para la versión PRO y no se usan en la versión gratuita.
    // Esto evita los problemas de rendimiento.
    public static function process_training($assistant_id) {
        // Esta función ahora estaría en el Add-on PRO.
        return new WP_Error('pro_feature', 'El entrenamiento con Embeddings es una característica PRO.');
    }
}
