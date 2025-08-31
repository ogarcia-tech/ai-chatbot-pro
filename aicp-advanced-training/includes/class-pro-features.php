<?php
if (!defined('ABSPATH')) exit;

class AICP_Pro_Features {

    public static function init() {
        add_action('aicp_pro_tab_content', [__CLASS__, 'render_pro_training_tab']);
        add_action('aicp_after_settings_fields', [__CLASS__, 'add_pro_settings_fields']);
        add_action('save_post_aicp_assistant', [__CLASS__, 'save_pro_assistant_settings'], 20, 1);
    }

    public static function render_pro_training_tab() {
        global $post;
        $assistant_id_wp = $post->ID;
        $settings = get_post_meta($assistant_id_wp, '_aicp_assistant_settings', true);
        
        $selected_posts = $settings['training_post_ids'] ?? [];
        $selected_cpts = $settings['training_post_types'] ?? [];
        
        // --- INICIO DE LA MODIFICACIÓN ---
        // Obtener las reglas de comportamiento guardadas
        $behavior_rules = $settings['behavior_rules'] ?? '';
        // Definir las reglas por defecto si el campo está vacío
        if (empty($behavior_rules)) {
            $behavior_rules = "1. Basa tus respuestas estrictamente en la información contenida en tu base de conocimiento. No utilices información externa ni hagas suposiciones.\n";
            $behavior_rules .= "2. Si la respuesta a una pregunta no se encuentra en tu base de conocimiento, responde amablemente que no tienes esa información.\n";
            $behavior_rules .= "3. NUNCA menciones que estás consultando archivos, documentos o una base de conocimiento. Actúa como si conocieras la información de forma natural.\n";
            $behavior_rules .= "4. NUNCA incluyas citas o referencias (como 【...】) en tus respuestas. La respuesta debe ser limpia y directa.";
        }
        // --- FIN DE LA MODIFICACIÓN ---
        
        $openai_assistant_id = get_post_meta($assistant_id_wp, '_aicp_openai_assistant_id', true);
        $vector_store_id = get_post_meta($assistant_id_wp, '_aicp_vector_store_id', true);
        $last_sync_count = get_post_meta($assistant_id_wp, '_aicp_last_sync_count', true);
        $last_sync_time = get_post_meta($assistant_id_wp, '_aicp_last_sync_time', true);

        $all_pages = get_posts(['post_type' => 'page', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
        $all_posts = get_posts(['post_type' => 'post', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
        $cpts = get_post_types(['public' => true, '_builtin' => false], 'objects');
        ?>
        <h4><?php _e('Entrenamiento de Contenido (con OpenAI)', 'ai-chatbot-pro'); ?></h4>
        <p class="description"><?php _e('Selecciona el contenido de tu web para crear una base de conocimiento directamente en OpenAI. El asistente usará esta información para responder.', 'ai-chatbot-pro'); ?></p>

        <div style="display: flex; gap: 20px; margin-top: 20px; max-width: 900px;">
            <div style="flex: 1;"><strong><?php _e('Páginas', 'ai-chatbot-pro'); ?></strong><div style="height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;"><?php foreach ($all_pages as $page): ?><label style="display: block;"><input type="checkbox" name="aicp_settings[training_post_ids][]" value="<?php echo esc_attr($page->ID); ?>" <?php checked(in_array($page->ID, $selected_posts)); ?>> <?php echo esc_html($page->post_title); ?></label><?php endforeach; ?></div></div>
            <div style="flex: 1;"><strong><?php _e('Entradas', 'ai-chatbot-pro'); ?></strong><div style="height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;"><?php foreach ($all_posts as $entry): ?><label style="display: block;"><input type="checkbox" name="aicp_settings[training_post_ids][]" value="<?php echo esc_attr($entry->ID); ?>" <?php checked(in_array($entry->ID, $selected_posts)); ?>> <?php echo esc_html($entry->post_title); ?></label><?php endforeach; ?></div></div>
        </div>
        <h4 style="margin-top: 30px;"><?php _e('Entrenamiento por Tipo de Contenido', 'ai-chatbot-pro'); ?></h4>
        <fieldset style="margin-top: 10px;"><?php foreach ($cpts as $cpt): ?><label style="margin-right: 15px; display:inline-block;"><input type="checkbox" name="aicp_settings[training_post_types][]" value="<?php echo esc_attr($cpt->name); ?>" <?php checked(in_array($cpt->name, $selected_cpts)); ?>> <?php echo esc_html($cpt->label); ?></label><?php endforeach; ?></fieldset>
        
        <h4 style="margin-top: 30px;"><?php _e('Reglas de Comportamiento (Prompt Avanzado)', 'ai-chatbot-pro'); ?></h4>
        <p class="description"><?php _e('Estas instrucciones se añaden a la personalidad base del asistente. Definen cómo debe usar la información sincronizada.', 'ai-chatbot-pro'); ?></p>
        <textarea name="aicp_settings[behavior_rules]" rows="6" class="large-text"><?php echo esc_textarea($behavior_rules); ?></textarea>
        <div id="aicp-training-controls" style="margin-top: 30px; display: flex; align-items: center; gap: 15px;">
            <button type="button" class="button button-primary" id="aicp-sync-button"><?php _e('Sincronizar con OpenAI', 'ai-chatbot-pro'); ?></button>
            <span id="aicp-sync-status" style="font-weight: bold;"></span>
        </div>

        <div style="margin-top: 20px; padding: 15px; background-color: #f7f7f7; border-left: 4px solid #7e8993;">
            <strong><?php _e('Estado de la Sincronización:', 'ai-chatbot-pro'); ?></strong>
            <p style="margin: 5px 0;"><?php if ($last_sync_time) : ?>Última sincronización: <?php echo date_i18n(get_option('date_format') . ' H:i', $last_sync_time); ?> (<?php echo esc_html($last_sync_count); ?> posts/páginas procesados).<?php else: ?>Este asistente no se ha sincronizado nunca.<?php endif; ?></p>
            <small>OpenAI Assistant ID: <?php echo esc_html($openai_assistant_id ?: 'N/A'); ?></small><br>
            <small>OpenAI Vector Store ID: <?php echo esc_html($vector_store_id ?: 'N/A'); ?></small>
        </div>
        <?php
    }
    
    public static function add_pro_settings_fields() {
        add_settings_section('aicp_pro_settings_section', __('Verificación de API (PRO)', 'ai-chatbot-pro'), null, 'aicp-settings');
        add_settings_field('aicp_check_api_connection', __('Verificar OpenAI', 'ai-chatbot-pro'), [__CLASS__, 'render_check_api_button'], 'aicp-settings', 'aicp_pro_settings_section');
    }
    
    public static function render_check_api_button() {
        ?>
        <button type="button" class="button" id="aicp-check-api-button">Verificar Conexión con OpenAI</button>
        <span id="aicp-api-status" style="font-weight: bold; margin-left: 10px;"></span>
        <p class="description"><?php _e('Usa este botón para confirmar que tu API Key de OpenAI es correcta y tiene los permisos y fondos necesarios.', 'ai-chatbot-pro'); ?></p>
        <?php
    }

    public static function save_pro_assistant_settings($post_id) {
        if (!isset($_POST['aicp_meta_box_nonce']) || !wp_verify_nonce($_POST['aicp_meta_box_nonce'], 'aicp_save_meta_box_data') || !current_user_can('edit_post', $post_id)) return;
        $current_settings = get_post_meta($post_id, '_aicp_assistant_settings', true);
        if (!is_array($current_settings)) $current_settings = [];
        $new_settings = $_POST['aicp_settings'] ?? [];
        
        $current_settings['training_post_ids'] = isset($new_settings['training_post_ids']) && is_array($new_settings['training_post_ids']) ? array_map('intval', $new_settings['training_post_ids']) : [];
        $current_settings['training_post_types'] = isset($new_settings['training_post_types']) && is_array($new_settings['training_post_types']) ? array_map('sanitize_text_field', $new_settings['training_post_types']) : [];
        
        // Guardar el nuevo campo de reglas de comportamiento
        if (isset($new_settings['behavior_rules'])) {
            $current_settings['behavior_rules'] = sanitize_textarea_field($new_settings['behavior_rules']);
        }

        update_post_meta($post_id, '_aicp_assistant_settings', $current_settings);
    }
}