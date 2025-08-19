<?php
/**
 * Desbloquea y modifica la interfaz del plugin base para añadir las funciones PRO.
 */
if (!defined('ABSPATH')) exit;

class AICP_Pro_Features {

    public static function init() {
        // Engancha el contenido de la pestaña PRO.
        add_action('aicp_pro_tab_content', [__CLASS__, 'render_pro_training_tab']);
        
        // Engancha los campos de API en la página de Ajustes.
        add_action('aicp_after_settings_fields', [__CLASS__, 'add_pro_settings_fields']);

        // Engancha la lógica de guardado de los campos de API.
        add_filter('aicp_sanitize_pro_settings', [__CLASS__, 'sanitize_api_settings'], 10, 2);

        // Engancha nuestra función de guardado directamente a la acción de WordPress.
        // Se ejecuta después de la función de guardado del plugin principal (prioridad 20 > 10).
        add_action('save_post_aicp_assistant', [__CLASS__, 'save_pro_assistant_settings'], 20, 1);
    }

    /**
     * Muestra la nueva interfaz de entrenamiento mejorada.
     */
    public static function render_pro_training_tab() {
        global $post;
        $settings = get_post_meta($post->ID, '_aicp_assistant_settings', true);
        
        $selected_posts = $settings['training_post_ids'] ?? [];
        $selected_cpts = $settings['training_post_types'] ?? [];
        $chunks_count = get_post_meta($post->ID, '_aicp_chunks_count', true) ?: 0;

        $all_pages = get_posts(['post_type' => 'page', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
        $all_posts = get_posts(['post_type' => 'post', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
        $cpts = get_post_types(['public' => true, '_builtin' => false], 'objects');
        ?>
        <h4><?php _e('Entrenamiento Específico de Contenido', 'ai-chatbot-pro'); ?></h4>
        <p class="description"><?php _e('Selecciona las páginas y entradas exactas que quieres usar para entrenar a este asistente.', 'ai-chatbot-pro'); ?></p>
        
        <div style="display: flex; gap: 20px; margin-top: 20px; max-width: 900px;">
            <div style="flex: 1;">
                <strong><?php _e('Páginas', 'ai-chatbot-pro'); ?></strong>
                <div style="height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                    <?php foreach ($all_pages as $page): ?>
                        <label style="display: block;"><input type="checkbox" name="aicp_settings[training_post_ids][]" value="<?php echo esc_attr($page->ID); ?>" <?php checked(in_array($page->ID, $selected_posts)); ?>> <?php echo esc_html($page->post_title); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="flex: 1;">
                <strong><?php _e('Entradas', 'ai-chatbot-pro'); ?></strong>
                <div style="height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                     <?php foreach ($all_posts as $entry): ?>
                        <label style="display: block;"><input type="checkbox" name="aicp_settings[training_post_ids][]" value="<?php echo esc_attr($entry->ID); ?>" <?php checked(in_array($entry->ID, $selected_posts)); ?>> <?php echo esc_html($entry->post_title); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <h4 style="margin-top: 30px;"><?php _e('Entrenamiento por Tipo de Contenido', 'ai-chatbot-pro'); ?></h4>
        <p class="description"><?php _e('Selecciona para entrenar con todos los posts de los siguientes tipos de contenido personalizado.', 'ai-chatbot-pro'); ?></p>
        <fieldset style="margin-top: 10px;">
            <?php foreach ($cpts as $cpt): ?>
                <label style="margin-right: 15px; display:inline-block;">
                    <input type="checkbox" name="aicp_settings[training_post_types][]" value="<?php echo esc_attr($cpt->name); ?>" <?php checked(in_array($cpt->name, $selected_cpts)); ?>>
                    <?php echo esc_html($cpt->label); ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
        
        <div id="aicp-training-controls" style="margin-top: 30px;">
            <button type="button" class="button button-primary" id="aicp-sync-button"><?php _e('Sincronizar Todo el Contenido Seleccionado', 'ai-chatbot-pro'); ?></button>
            <span id="aicp-sync-status" style="margin-left: 10px; line-height: 2.5; font-weight: bold;"></span>
        </div>
        <p><strong><?php _e('Estado Actual:', 'ai-chatbot-pro'); ?></strong> <span id="aicp-chunk-count-display"><?php echo $chunks_count; ?></span> <?php _e('fragmentos de conocimiento en la base de datos.', 'ai-chatbot-pro'); ?></p>
        <?php
    }

    /**
     * Guarda los ajustes específicos del asistente (como los posts y CPTs seleccionados).
     */
    public static function save_pro_assistant_settings($post_id) {
        if (!isset($_POST['aicp_meta_box_nonce']) || !wp_verify_nonce($_POST['aicp_meta_box_nonce'], 'aicp_save_meta_box_data')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $current_settings = get_post_meta($post_id, '_aicp_assistant_settings', true);
        if (!is_array($current_settings)) $current_settings = [];

        $new_settings = $_POST['aicp_settings'] ?? [];
        
        // Guardar la selección individual de posts/páginas
        if (isset($new_settings['training_post_ids']) && is_array($new_settings['training_post_ids'])) {
            $current_settings['training_post_ids'] = array_map('intval', $new_settings['training_post_ids']);
        } else {
            $current_settings['training_post_ids'] = []; 
        }

        // Guardar la selección de tipos de contenido
        if (isset($new_settings['training_post_types']) && is_array($new_settings['training_post_types'])) {
            $current_settings['training_post_types'] = array_map('sanitize_text_field', $new_settings['training_post_types']);
        } else {
            $current_settings['training_post_types'] = [];
        }

        update_post_meta($post_id, '_aicp_assistant_settings', $current_settings);
    }
    
    // --- FUNCIONES PARA LOS AJUSTES GENERALES (API KEYS) ---

    public static function add_pro_settings_fields() {
        add_settings_section('aicp_pro_settings_section', __('Ajustes de Base de Datos Vectorial (PRO)', 'ai-chatbot-pro'), null, 'aicp-settings');
        add_settings_field('aicp_pinecone_api_key', __('Pinecone API Key', 'ai-chatbot-pro'), [__CLASS__, 'render_pinecone_api_key_field'], 'aicp-settings', 'aicp_pro_settings_section');
        add_settings_field('aicp_pinecone_host', __('Pinecone Host', 'ai-chatbot-pro'), [__CLASS__, 'render_pinecone_host_field'], 'aicp-settings', 'aicp_pro_settings_section');
    }

    public static function render_pinecone_api_key_field() {
        $options = get_option('aicp_settings');
        $key = isset($options['pinecone_api_key']) ? esc_attr($options['pinecone_api_key']) : '';
        echo '<input type="password" name="aicp_settings[pinecone_api_key]" value="' . $key . '" class="regular-text">';
    }

    public static function render_pinecone_host_field() {
        $options = get_option('aicp_settings');
        $host = isset($options['pinecone_host']) ? esc_attr($options['pinecone_host']) : '';
        echo '<input type="url" name="aicp_settings[pinecone_host]" value="' . $host . '" class="large-text" placeholder="https://tu-indice-xxxx.svc.tu-region.pinecone.io">';
    }
    
    public static function sanitize_api_settings($sanitized_input, $input) {
        if (isset($input['pinecone_api_key'])) {
            $sanitized_input['pinecone_api_key'] = sanitize_text_field($input['pinecone_api_key']);
        }
        if (isset($input['pinecone_host'])) {
            $sanitized_input['pinecone_host'] = esc_url_raw($input['pinecone_host']);
        }
        return $sanitized_input;
    }
}