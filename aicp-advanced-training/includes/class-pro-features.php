<?php
if (!defined('ABSPATH')) exit;

class AICP_Pro_Features {

    public static function init() {
        add_action('aicp_pro_tab_content', [__CLASS__, 'render_pro_training_tab']);
        add_action('aicp_after_settings_fields', [__CLASS__, 'add_pro_settings_fields']);

        // Usar filtro del plugin base para integrar los ajustes PRO sin sobrescribir los existentes
        add_filter('aicp_save_assistant_settings', [__CLASS__, 'filter_pro_settings'], 10, 2);
    }

    public static function render_pro_training_tab() {
        global $post;
        $assistant_id_wp = $post->ID;
        $settings = get_post_meta($assistant_id_wp, '_aicp_assistant_settings', true);
        
        $selected_posts = $settings['training_post_ids'] ?? [];
        $selected_cpts = $settings['training_post_types'] ?? [];
        $selected_files = isset($settings['training_file_ids']) && is_array($settings['training_file_ids']) ? array_map('intval', $settings['training_file_ids']) : [];
        $auto_open_enabled = !empty($settings['auto_open_enabled']);
        $auto_open_delay = isset($settings['auto_open_delay']) ? absint($settings['auto_open_delay']) : 5;
        $auto_open_duration = isset($settings['auto_open_duration']) ? absint($settings['auto_open_duration']) : 0;
        $auto_open_message = isset($settings['auto_open_message']) ? $settings['auto_open_message'] : '';
        $integration_active = !empty($settings['forward_to_webhook']);
        
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
        $uploaded_files = [];
        if (!empty($selected_files)) {
            $uploaded_files = get_posts([
                'post_type'      => 'attachment',
                'post__in'       => $selected_files,
                'posts_per_page' => -1,
                'orderby'        => 'post__in',
            ]);
        }

        ?>
        <div class="notice notice-warning inline aicp-pro-training-lock-notice" <?php if (!$integration_active) : ?>style="display:none;"<?php endif; ?>>
            <p><?php _e('La integración con n8n está activa. Desactívala para volver a entrenar el asistente o modificar estas opciones PRO.', 'ai-chatbot-pro'); ?></p>
        </div>
        <div class="aicp-pro-training-fields" data-forwarding-active="<?php echo $integration_active ? '1' : '0'; ?>">
        <h4><?php _e('Entrenamiento de Contenido (con OpenAI)', 'ai-chatbot-pro'); ?></h4>
        <p class="description"><?php _e('Selecciona el contenido de tu web para crear una base de conocimiento directamente en OpenAI. El asistente usará esta información para responder.', 'ai-chatbot-pro'); ?></p>

        <div style="display: flex; gap: 20px; margin-top: 20px; max-width: 900px;">
            <div style="flex: 1;"><strong><?php _e('Páginas', 'ai-chatbot-pro'); ?></strong><div style="height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;"><?php foreach ($all_pages as $page): ?><label style="display: block;"><input type="checkbox" name="aicp_settings[training_post_ids][]" value="<?php echo esc_attr($page->ID); ?>" <?php checked(in_array($page->ID, $selected_posts)); ?>> <?php echo esc_html($page->post_title); ?></label><?php endforeach; ?></div></div>
            <div style="flex: 1;"><strong><?php _e('Entradas', 'ai-chatbot-pro'); ?></strong><div style="height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;"><?php foreach ($all_posts as $entry): ?><label style="display: block;"><input type="checkbox" name="aicp_settings[training_post_ids][]" value="<?php echo esc_attr($entry->ID); ?>" <?php checked(in_array($entry->ID, $selected_posts)); ?>> <?php echo esc_html($entry->post_title); ?></label><?php endforeach; ?></div></div>
        </div>
        <h4 style="margin-top: 30px;"><?php _e('Entrenamiento por Tipo de Contenido', 'ai-chatbot-pro'); ?></h4>
        <fieldset style="margin-top: 10px;"><?php foreach ($cpts as $cpt): ?><label style="margin-right: 15px; display:inline-block;"><input type="checkbox" name="aicp_settings[training_post_types][]" value="<?php echo esc_attr($cpt->name); ?>" <?php checked(in_array($cpt->name, $selected_cpts)); ?>> <?php echo esc_html($cpt->label); ?></label><?php endforeach; ?></fieldset>

        <h4 style="margin-top: 30px;"><?php _e('Archivos Personalizados', 'ai-chatbot-pro'); ?></h4>
        <p class="description"><?php _e('Complementa el entrenamiento con PDFs, documentos o textos externos. Se añadirán a la base de conocimiento del asistente mediante la herramienta de File Search de OpenAI.', 'ai-chatbot-pro'); ?></p>
        <div class="aicp-training-files-wrapper">
            <div class="aicp-training-files-actions">
                <button type="button" class="button" id="aicp-training-upload-button"><?php _e('Seleccionar archivos', 'ai-chatbot-pro'); ?></button>
                <span class="description"><?php _e('Formatos recomendados: PDF, TXT, DOCX, CSV, Markdown.', 'ai-chatbot-pro'); ?></span>
            </div>
            <input type="hidden" id="aicp_training_file_ids" name="aicp_settings[training_file_ids]" value="<?php echo esc_attr(implode(',', $selected_files)); ?>">
            <ul id="aicp-training-file-list" class="aicp-training-file-list">
                <?php foreach ($uploaded_files as $file) :
                    $file_path = get_attached_file($file->ID);
                    $filesize = ($file_path && file_exists($file_path)) ? size_format(filesize($file_path)) : '';
                ?>
                    <li data-file-id="<?php echo esc_attr($file->ID); ?>">
                        <span>
                            <strong><?php echo esc_html(get_the_title($file)); ?></strong>
                            <?php if ($filesize) : ?>
                                <small style="opacity:0.7;">(<?php echo esc_html($filesize); ?>)</small>
                            <?php endif; ?>
                        </span>
                        <button type="button" class="button-link aicp-training-file-remove" data-file-id="<?php echo esc_attr($file->ID); ?>"><?php _e('Eliminar', 'ai-chatbot-pro'); ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (empty($uploaded_files)) : ?>
                <p class="description" id="aicp-training-no-files"><?php _e('Todavía no has añadido archivos personalizados.', 'ai-chatbot-pro'); ?></p>
            <?php else : ?>
                <p class="description" id="aicp-training-no-files" style="display:none;">&nbsp;</p>
            <?php endif; ?>
        </div>

        <h4 style="margin-top: 30px;"><?php _e('Reglas de Comportamiento (Prompt Avanzado)', 'ai-chatbot-pro'); ?></h4>
        <p class="description"><?php _e('Estas instrucciones se añaden a la personalidad base del asistente. Definen cómo debe usar la información sincronizada.', 'ai-chatbot-pro'); ?></p>
        <textarea name="aicp_settings[behavior_rules]" rows="6" class="large-text"><?php echo esc_textarea($behavior_rules); ?></textarea>
        <div id="aicp-training-controls" style="margin-top: 30px; display: flex; align-items: center; gap: 15px;">
            <button type="button" class="button button-primary" id="aicp-sync-button"><?php _e('Sincronizar con OpenAI', 'ai-chatbot-pro'); ?></button>
            <span id="aicp-sync-status" style="font-weight: bold;"></span>
        </div>

        <hr style="margin: 30px 0;">
        <h4><?php _e('Comportamiento Avanzado del Widget', 'ai-chatbot-pro'); ?></h4>
        <p class="description"><?php _e('Configura la apertura automática del chat para maximizar la captación de leads.', 'ai-chatbot-pro'); ?></p>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Apertura Automática', 'ai-chatbot-pro'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="aicp_settings[auto_open_enabled]" value="1" <?php checked($auto_open_enabled); ?>>
                        <?php _e('Abrir el chat automáticamente tras cargar la página.', 'ai-chatbot-pro'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="aicp_auto_open_delay"><?php _e('Retraso antes de abrir (segundos)', 'ai-chatbot-pro'); ?></label></th>
                <td>
                    <input type="number" min="0" id="aicp_auto_open_delay" name="aicp_settings[auto_open_delay]" value="<?php echo esc_attr($auto_open_delay); ?>" class="small-text">
                    <span class="description"><?php _e('Tiempo que el widget esperará antes de mostrarse automáticamente.', 'ai-chatbot-pro'); ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="aicp_auto_open_duration"><?php _e('Tiempo visible antes de cerrarse (segundos)', 'ai-chatbot-pro'); ?></label></th>
                <td>
                    <input type="number" min="0" id="aicp_auto_open_duration" name="aicp_settings[auto_open_duration]" value="<?php echo esc_attr($auto_open_duration); ?>" class="small-text">
                    <span class="description"><?php _e('Usa 0 para mantener el chat abierto hasta que el usuario interactúe.', 'ai-chatbot-pro'); ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="aicp_auto_open_message"><?php _e('Mensaje de bienvenida automático', 'ai-chatbot-pro'); ?></label></th>
                <td>
                    <textarea id="aicp_auto_open_message" name="aicp_settings[auto_open_message]" rows="3" class="large-text"><?php echo esc_textarea($auto_open_message); ?></textarea>
                    <span class="description"><?php _e('Si se abre solo, el asistente mostrará este mensaje de bienvenida.', 'ai-chatbot-pro'); ?></span>
                </td>
            </tr>
        </table>

        <div style="margin-top: 20px; padding: 15px; background-color: #f7f7f7; border-left: 4px solid #7e8993;">
            <strong><?php _e('Estado de la Sincronización:', 'ai-chatbot-pro'); ?></strong>
            <p style="margin: 5px 0;"><?php if ($last_sync_time) : ?>Última sincronización: <?php echo date_i18n(get_option('date_format') . ' H:i', $last_sync_time); ?> (<?php echo esc_html($last_sync_count); ?> posts/páginas procesados).<?php else: ?>Este asistente no se ha sincronizado nunca.<?php endif; ?></p>
            <small>OpenAI Assistant ID: <?php echo esc_html($openai_assistant_id ?: 'N/A'); ?></small><br>
            <small>OpenAI Vector Store ID: <?php echo esc_html($vector_store_id ?: 'N/A'); ?></small>
        </div>
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

    /**
     * Ajusta los valores PRO dentro del proceso de guardado del plugin base.
     *
     * @param array $current_settings Ajustes ya procesados por el plugin principal.
     * @param array $input_post       Valores sin sanear provenientes del formulario.
     *
     * @return array
     */
    public static function filter_pro_settings($current_settings, $input_post) {
        if (!current_user_can('edit_posts')) {
            return $current_settings;
        }

        if (!is_array($current_settings)) {
            $current_settings = [];
        }

        $post_settings = is_array($input_post) ? $input_post : [];

        $current_settings['training_post_ids'] = isset($post_settings['training_post_ids']) && is_array($post_settings['training_post_ids'])
            ? array_map('intval', $post_settings['training_post_ids'])
            : [];

        $current_settings['training_post_types'] = isset($post_settings['training_post_types']) && is_array($post_settings['training_post_types'])
            ? array_map('sanitize_text_field', $post_settings['training_post_types'])
            : [];

        if (!empty($post_settings['training_file_ids'])) {
            $file_ids = array_filter(array_map('intval', explode(',', $post_settings['training_file_ids'])));
            $current_settings['training_file_ids'] = array_values($file_ids);
        } else {
            $current_settings['training_file_ids'] = [];
        }

        if (isset($post_settings['behavior_rules'])) {
            $current_settings['behavior_rules'] = sanitize_textarea_field($post_settings['behavior_rules']);
        }

        $current_settings['auto_open_enabled'] = !empty($post_settings['auto_open_enabled']) ? 1 : 0;
        $current_settings['auto_open_delay'] = isset($post_settings['auto_open_delay']) ? max(0, intval($post_settings['auto_open_delay'])) : 0;
        $current_settings['auto_open_duration'] = isset($post_settings['auto_open_duration']) ? max(0, intval($post_settings['auto_open_duration'])) : 0;
        $current_settings['auto_open_message'] = isset($post_settings['auto_open_message']) ? sanitize_textarea_field($post_settings['auto_open_message']) : '';

        return $current_settings;
    }
}
