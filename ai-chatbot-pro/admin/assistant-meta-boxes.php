<?php
/**
 * Define y gestiona todos los meta boxes para el CPT de Asistentes.
 *
 * @package AI_Chatbot_Pro
 */
if (!defined('ABSPATH')) exit;

/**
 * Añade los meta boxes a la pantalla de edición de asistentes.
 */
function aicp_add_meta_boxes() {
    add_action('edit_form_top', function($post) {
        if ($post->post_type !== 'aicp_assistant') return;
        echo '<h2 class="nav-tab-wrapper aicp-nav-tab-wrapper">';
        echo '<a href="#aicp-tab-instructions" class="nav-tab nav-tab-active">' . __('Instrucciones', 'ai-chatbot-pro') . '</a>';
        echo '<a href="#aicp-tab-design" class="nav-tab">' . __('Diseño', 'ai-chatbot-pro') . '</a>';
        echo '<a href="#aicp-tab-leads" class="nav-tab">' . __('Leads', 'ai-chatbot-pro') . '</a>';
        
        // Lógica corregida para mostrar la pestaña PRO o el mensaje de venta.
        if (class_exists('AICP_Pro_Features')) {
            echo '<a href="#aicp-tab-pro" class="nav-tab">' . __('Funciones PRO', 'ai-chatbot-pro') . ' <span class="aicp-pro-tag">PRO</span></a>';
        } else {
             echo '<a href="#aicp-tab-pro-upsell" class="nav-tab">' . __('Funciones PRO', 'ai-chatbot-pro') . ' <span class="aicp-pro-tag">PRO</span></a>';
        }
        echo '</h2>';
    });

    add_meta_box('aicp_main_settings_meta_box', __('Configuración del Asistente', 'ai-chatbot-pro'), 'aicp_render_main_meta_box', 'aicp_assistant', 'normal', 'high');
    add_meta_box('aicp_shortcode_meta_box', __('Shortcode', 'ai-chatbot-pro'), 'aicp_render_shortcode_meta_box', 'aicp_assistant', 'side', 'high');
}
add_action('add_meta_boxes_aicp_assistant', 'aicp_add_meta_boxes');

/**
 * Carga los scripts y estilos necesarios para los meta boxes.
 */
function aicp_admin_scripts($hook) {
    global $post;
    if (($hook == 'post-new.php' || $hook == 'post.php') && isset($post->post_type) && 'aicp_assistant' === $post->post_type) {
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style('aicp-admin-styles', AICP_PLUGIN_URL . 'assets/css/admin.css', [], AICP_VERSION);
        wp_enqueue_style('aicp-chatbot-preview-styles', AICP_PLUGIN_URL . 'assets/css/chatbot.css', [], AICP_VERSION);
        wp_enqueue_script('aicp-admin-script', AICP_PLUGIN_URL . 'assets/js/admin-scripts.js', ['jquery', 'wp-color-picker'], AICP_VERSION, true);
        
        $settings = get_post_meta($post->ID, '_aicp_assistant_settings', true);
        if (!is_array($settings)) $settings = [];

        $default_bot_avatar  = AICP_PLUGIN_URL . 'assets/bot-default-avatar.png';
        $default_user_avatar = AICP_PLUGIN_URL . 'assets/user-default-avatar.png';
        $default_open_icon   = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>');

        wp_localize_script('aicp-admin-script', 'aicp_admin_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'assistant_id' => $post->ID,
            'delete_nonce' => wp_create_nonce('aicp_delete_log_nonce'),
            'get_log_nonce' => wp_create_nonce('aicp_get_log_nonce'),
            'capture_lead_nonce' => wp_create_nonce('aicp_capture_lead_nonce'),
            'default_bot_avatar' => $default_bot_avatar,
            'default_user_avatar' => $default_user_avatar,
            'default_open_icon' => $default_open_icon,
            'initial_settings' => [
                'bot_avatar_url' => $settings['bot_avatar_url'] ?? $default_bot_avatar,
                'user_avatar_url' => $settings['user_avatar_url'] ?? $default_user_avatar,
                'open_icon_url' => $settings['open_icon_url'] ?? $default_open_icon,
                'position' => $settings['position'] ?? 'br',
                'color_primary' => $settings['color_primary'] ?? '#0073aa',
                'color_bot_bg' => $settings['color_bot_bg'] ?? '#ffffff',
                'color_bot_text' => $settings['color_bot_text'] ?? '#333333',
                'color_user_bg' => $settings['color_user_bg'] ?? '#dcf8c6',
                'color_user_text' => $settings['color_user_text'] ?? '#000000',
            ]
        ]);
    }
}
add_action('admin_enqueue_scripts', 'aicp_admin_scripts');

/**
 * Renderiza el contenido principal de los meta boxes con pestañas.
 */
function aicp_render_main_meta_box($post) {
    wp_nonce_field('aicp_save_meta_box_data', 'aicp_meta_box_nonce');
    $v = get_post_meta($post->ID, '_aicp_assistant_settings', true);
    if (!is_array($v)) $v = [];
    ?>
    <div id="aicp-tab-instructions" class="aicp-tab-content">
        <?php aicp_render_instructions_tab($v); ?>
    </div>
    <div id="aicp-tab-design" class="aicp-tab-content" style="display:none;">
        <div class="aicp-design-layout">
            <div class="aicp-design-settings">
                <?php aicp_render_design_tab($v); ?>
            </div>
            <div class="aicp-design-preview">
                <?php aicp_render_preview_panel(); ?>
            </div>
        </div>
    </div>
    <div id="aicp-tab-leads" class="aicp-tab-content" style="display:none;">
        <?php aicp_render_leads_tab($post->ID, $v); ?>
    </div>

    <?php // Lógica corregida y limpia para mostrar el contenido PRO o el mensaje de venta.
    if (class_exists('AICP_Pro_Features')) : ?>
        <div id="aicp-tab-pro" class="aicp-tab-content" style="display:none;">
            <?php 
            do_action('aicp_pro_tab_content'); 
            ?>
        </div>
    <?php else: ?>
        <div id="aicp-tab-pro-upsell" class="aicp-tab-content" style="display:none;">
            <?php aicp_render_pro_upsell(); ?>
        </div>
    <?php endif; ?>
    <?php
}

function aicp_render_instructions_tab($v) {
    ?>
    <table class="form-table">
        <tr><th><label for="aicp_model"><?php _e('Modelo de IA', 'ai-chatbot-pro'); ?></label></th><td><select name="aicp_settings[model]" id="aicp_model" class="regular-text"><option value="gpt-4o" <?php selected($v['model'] ?? 'gpt-4o', 'gpt-4o'); ?>>GPT-4o</option><option value="gpt-4-turbo-preview" <?php selected($v['model'] ?? '', 'gpt-4-turbo-preview'); ?>>GPT-4 Turbo</option></select></td></tr>
        <tr><th><label for="aicp_persona"><?php _e('Nombre y Personalidad', 'ai-chatbot-pro'); ?></label></th><td><textarea name="aicp_settings[persona]" id="aicp_persona" rows="3" class="large-text"><?php echo esc_textarea($v['persona'] ?? 'Te llamas Ana, eres una asistente virtual experta en marketing digital.'); ?></textarea></td></tr>
        <tr><th><label for="aicp_objective"><?php _e('Objetivo Principal', 'ai-chatbot-pro'); ?></label></th><td><textarea name="aicp_settings[objective]" id="aicp_objective" rows="2" class="large-text"><?php echo esc_textarea($v['objective'] ?? 'Mi objetivo es ayudar a los usuarios a encontrar la información que necesitan y animarles a contactar para obtener un presupuesto.'); ?></textarea></td></tr>
        <tr><th><label for="aicp_length_tone"><?php _e('Longitud y Tono', 'ai-chatbot-pro'); ?></label></th><td><textarea name="aicp_settings[length_tone]" id="aicp_length_tone" rows="3" class="large-text"><?php echo esc_textarea($v['length_tone'] ?? 'Intenta ser lo más concisa posible, manteniendo un tono amable y profesional.'); ?></textarea></td></tr>
        <tr><th><label for="aicp_example"><?php _e('Ejemplo de Respuesta', 'ai-chatbot-pro'); ?></label></th><td><textarea name="aicp_settings[example]" id="aicp_example" rows="5" class="large-text"><?php echo esc_textarea($v['example'] ?? 'Si el cliente pregunta por el precio de una web, responde: "El precio de una web puede variar mucho, pero para darte una idea, nuestros proyectos suelen empezar en 1.500€. ¿Te gustaría que te preparásemos un presupuesto detallado sin compromiso?"'); ?></textarea></td></tr>
        <tr><th><label><?php _e('Mensajes Sugeridos', 'ai-chatbot-pro'); ?></label></th><td><input type="text" name="aicp_settings[suggested_messages][]" value="<?php echo esc_attr($v['suggested_messages'][0] ?? ''); ?>" class="large-text" placeholder="<?php _e('Ej: Me interesa el servicio de SEO', 'ai-chatbot-pro'); ?>"><br><input type="text" name="aicp_settings[suggested_messages][]" value="<?php echo esc_attr($v['suggested_messages'][1] ?? ''); ?>" class="large-text" placeholder="<?php _e('Ej: Quiero una web económica', 'ai-chatbot-pro'); ?>"><br><input type="text" name="aicp_settings[suggested_messages][]" value="<?php echo esc_attr($v['suggested_messages'][2] ?? ''); ?>" class="large-text" placeholder="<?php _e('Ej: ¿Podéis llamarme?', 'ai-chatbot-pro'); ?>"><p class="description"><?php _e('Estos mensajes aparecerán como botones clicables para el usuario.', 'ai-chatbot-pro'); ?></p></td></tr>
    </table>
    <?php
}

function aicp_render_design_tab($v) {
    $default_bot_avatar  = AICP_PLUGIN_URL . 'assets/bot-default-avatar.png';
    $default_user_avatar = AICP_PLUGIN_URL . 'assets/user-default-avatar.png';
    $default_open_icon   = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>');

    $bot_avatar  = $v['bot_avatar_url'] ?? $default_bot_avatar;
    $user_avatar = $v['user_avatar_url'] ?? $default_user_avatar;
    $open_icon = $v['open_icon_url'] ?? $default_open_icon;
    $position = $v['position'] ?? 'br';
    ?>
    <table class="form-table">
        <tr><th><h3><?php _e('Avatares e Iconos', 'ai-chatbot-pro'); ?></h3></th><td><hr></td></tr>
        <tr><th><label><?php _e('Avatar del Bot', 'ai-chatbot-pro'); ?></label></th><td><?php aicp_render_uploader('bot_avatar', $bot_avatar); ?></td></tr>
        <tr><th><label><?php _e('Avatar de Usuario (por defecto)', 'ai-chatbot-pro'); ?></label></th><td><?php aicp_render_uploader('user_avatar', $user_avatar); ?><p class="description"><?php _e('Si un usuario ha iniciado sesión, se usará su avatar de WordPress.', 'ai-chatbot-pro'); ?></p></td></tr>
        <tr><th><label><?php _e('Icono del Botón Flotante', 'ai-chatbot-pro'); ?></label></th><td><?php aicp_render_uploader('open_icon', $open_icon); ?></td></tr>
        <tr><th><h3><?php _e('Posición y Colores', 'ai-chatbot-pro'); ?></h3></th><td><hr></td></tr>
        <tr><th><label for="aicp_position"><?php _e('Posición del Widget', 'ai-chatbot-pro'); ?></label></th><td><select name="aicp_settings[position]" id="aicp_position"><option value="br" <?php selected($position, 'br'); ?>><?php _e('Abajo a la Derecha', 'ai-chatbot-pro'); ?></option><option value="bl" <?php selected($position, 'bl'); ?>><?php _e('Abajo a la Izquierda', 'ai-chatbot-pro'); ?></option></select></td></tr>
        <tr><th><label><?php _e('Color Principal', 'ai-chatbot-pro'); ?></label></th><td><input type="text" name="aicp_settings[color_primary]" value="<?php echo esc_attr($v['color_primary'] ?? '#0073aa'); ?>" class="aicp-color-picker" data-preview-var="--aicp-color-primary"></td></tr>
        <tr><th><label><?php _e('Burbuja del Bot', 'ai-chatbot-pro'); ?></label></th><td><label><?php _e('Fondo:', 'ai-chatbot-pro'); ?> <input type="text" name="aicp_settings[color_bot_bg]" value="<?php echo esc_attr($v['color_bot_bg'] ?? '#ffffff'); ?>" class="aicp-color-picker" data-preview-var="--aicp-color-bot-bg"></label> <label><?php _e('Texto:', 'ai-chatbot-pro'); ?> <input type="text" name="aicp_settings[color_bot_text]" value="<?php echo esc_attr($v['color_bot_text'] ?? '#333333'); ?>" class="aicp-color-picker" data-preview-var="--aicp-color-bot-text"></label></td></tr>
        <tr><th><label><?php _e('Burbuja del Usuario', 'ai-chatbot-pro'); ?></label></th><td><label><?php _e('Fondo:', 'ai-chatbot-pro'); ?> <input type="text" name="aicp_settings[color_user_bg]" value="<?php echo esc_attr($v['color_user_bg'] ?? '#dcf8c6'); ?>" class="aicp-color-picker" data-preview-var="--aicp-color-user-bg"></label> <label><?php _e('Texto:', 'ai-chatbot-pro'); ?> <input type="text" name="aicp_settings[color_user_text]" value="<?php echo esc_attr($v['color_user_text'] ?? '#000000'); ?>" class="aicp-color-picker" data-preview-var="--aicp-color-user-text"></label></td></tr>
    </table>
    <?php
}

function aicp_render_preview_panel() {
    ?>
    <h4><?php _e('Previsualización en Vivo', 'ai-chatbot-pro'); ?></h4>
    <div id="aicp-preview-container">
        <div id="aicp-preview-chatbot-container" class="position-br">
            <div id="aicp-chat-window" class="active" style="position: relative; bottom: auto; right: auto; opacity: 1; transform: none; visibility: visible;">
                <div class="aicp-chat-header"><div class="aicp-header-avatar"><img src="" alt="Avatar del bot" id="preview_bot_avatar"></div><div class="aicp-header-title"><?php _e('Asistente de Prueba', 'ai-chatbot-pro'); ?></div></div>
                <div class="aicp-chat-body"><div class="aicp-chat-message bot"><div class="aicp-message-avatar"><img src="" alt="Avatar" id="preview_bot_avatar_chat"></div><div class="aicp-message-bubble"><?php _e('¡Hola! Esta es una previsualización.', 'ai-chatbot-pro'); ?></div></div><div class="aicp-chat-message user"><div class="aicp-message-avatar"><img src="" alt="Avatar" id="preview_user_avatar_chat"></div><div class="aicp-message-bubble"><?php _e('¡Genial! Puedo ver los cambios en tiempo real.', 'ai-chatbot-pro'); ?></div></div></div>
                <div class="aicp-chat-footer"><form id="aicp-chat-form"><input type="text" placeholder="Escribe un mensaje..." disabled><button type="submit" disabled><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button></form></div>
            </div>
            <button id="aicp-chat-toggle-button"><span class="aicp-open-icon"><img src="" alt="Abrir chat" id="preview_open_icon"></span></button>
        </div>
    </div>
    <?php
}

function aicp_render_leads_tab($assistant_id, $v) {
    global $wpdb;
    $logs_table = $wpdb->prefix . 'aicp_chat_logs';

    $auto_collect = !empty($v['lead_auto_collect']);
    $lead_email   = $v['lead_email'] ?? '';
    $webhook      = esc_url($v['webhook_url'] ?? '');

    echo '<h4>' . __('Ajustes de Captura de Leads', 'ai-chatbot-pro') . '</h4>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label>' . __('Captura Automática', 'ai-chatbot-pro') . '</label></th><td><label><input type="checkbox" name="aicp_settings[lead_auto_collect]" value="1" ' . checked($auto_collect, true, false) . '> ' . __('Solicitar datos de contacto automáticamente', 'ai-chatbot-pro') . '</label></td></tr>';
    echo '<tr><th><label for="aicp_lead_email">' . __('Email de notificación', 'ai-chatbot-pro') . '</label></th><td><input type="email" id="aicp_lead_email" name="aicp_settings[lead_email]" value="' . esc_attr($lead_email) . '" class="regular-text" /><br /><span class="description">' . sprintf(__('Si se deja vacío, se usará %s.', 'ai-chatbot-pro'), esc_html(get_option('admin_email'))) . '</span></td></tr>';
    echo '<tr><th><label for="aicp_webhook_url">' . __('Webhook URL', 'ai-chatbot-pro') . '</label></th><td><input type="url" name="aicp_settings[webhook_url]" id="aicp_webhook_url" value="' . esc_attr($webhook) . '" class="regular-text" placeholder="https://example.com/webhook" /></td></tr>';
    echo '</tbody></table>';

    // --- SECCIÓN DE MENSAJES DE CIERRE ELIMINADA ---

    // Historial de Conversaciones
    $table_name = $wpdb->prefix . 'aicp_chat_logs';

    // Paginación
    $items_per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $items_per_page;

    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT id, timestamp, has_lead, first_user_message FROM $table_name WHERE assistant_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
        $assistant_id,
        $items_per_page,
        $offset
    ));
    $leads_count   = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE assistant_id = %d AND has_lead = 1", $assistant_id));
    $history_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE assistant_id = %d", $assistant_id));

    $export_leads_url   = $leads_count > 0 ? wp_nonce_url(admin_url('edit.php?post_type=aicp_assistant&aicp_export=leads&assistant_id=' . $assistant_id), 'aicp_export_nonce_' . $assistant_id) : '#';
    $export_history_url = $history_count > 0 ? wp_nonce_url(admin_url('edit.php?post_type=aicp_assistant&aicp_export=history&assistant_id=' . $assistant_id), 'aicp_export_nonce_' . $assistant_id) : '#';
    ?>
    <h4><?php _e('Historial de Conversaciones', 'ai-chatbot-pro'); ?></h4>
    <div class="aicp-history-actions">
        <a href="<?php echo esc_url($export_leads_url); ?>" class="button" <?php if ($leads_count == 0) echo 'disabled title="' . esc_attr__('No hay leads para exportar', 'ai-chatbot-pro') . '"'; ?>>
            <?php _e('Exportar Leads (CSV)', 'ai-chatbot-pro'); ?>
        </a>
        <a href="<?php echo esc_url($export_history_url); ?>" class="button" <?php if ($history_count == 0) echo 'disabled title="' . esc_attr__('No hay historial para exportar', 'ai-chatbot-pro') . '"'; ?>>
            <?php _e('Exportar Historial (CSV)', 'ai-chatbot-pro'); ?>
        </a>
    </div>
    <?php
    echo '<div id="aicp-chat-history-container">';
    if (empty($logs)) {
        echo '<p>' . __('No hay conversaciones registradas.', 'ai-chatbot-pro') . '</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped aicp-logs-table">';
        echo '<thead><tr>
                <th style="width:180px;">' . __('Fecha', 'ai-chatbot-pro') . '</th>
                <th>' . __('Inicio de la Conversación', 'ai-chatbot-pro') . '</th>
                <th style="width:60px; text-align:center;">' . __('Lead', 'ai-chatbot-pro') . '</th>
                <th style="width:200px;">' . __('Acciones', 'ai-chatbot-pro') . '</th>
            </tr></thead>';
        echo '<tbody>';
        foreach ($logs as $log) {
            echo '<tr data-log-id="' . $log->id . '">';
           echo '<td>' . date_i18n(get_option('date_format') . ' H:i', strtotime($log->timestamp)) . '</td>';
            echo '<td>' . esc_html(wp_trim_words($log->first_user_message, 15, '...')) . '</td>';
            echo '<td style="text-align:center;">';
            if ($log->has_lead) {
                echo '<span class="dashicons dashicons-yes-alt" style="color: #4CAF50;" title="' . __('Lead capturado', 'ai-chatbot-pro') . '"></span>';
            } else {
                echo '<span class="dashicons dashicons-no-alt" style="color: #F44336;" title="' . __('Sin lead', 'ai-chatbot-pro') . '"></span>';
            }
            echo '</td>';
            echo '<td>';
            echo '<button class="button button-secondary aicp-view-log-details" data-log-id="' . $log->id . '">' . __('Ver', 'ai-chatbot-pro') . '</button> ';
            echo '<button class="button button-link-delete aicp-delete-log-list" data-log-id="' . $log->id . '">' . __('Borrar', 'ai-chatbot-pro') . '</button>';
            echo '</td>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $logs_table WHERE assistant_id = %d", $assistant_id));
        $total_pages = ceil($total_items / $items_per_page);
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $total_pages,
                'current' => $current_page,
            ]);
            echo '</div></div>';
        }
    }
    echo '</div>';
    echo '<div id="aicp-log-modal-backdrop" style="display:none;"><div id="aicp-log-modal-content"><div id="aicp-log-modal-close">&times;</div><div id="aicp-log-modal-body"></div></div></div>';
}

function aicp_render_pro_tab() {
    // Esta función ahora solo se usa como respaldo, pero la dejamos por si acaso.
    ?>
    <div class="aicp-pro-feature-wrapper">
        <h3><?php _e('Funciones PRO', 'ai-chatbot-pro'); ?></h3>
        <p><?php _e('Activa el addon AI Chatbot Pro - Advanced Training para desbloquear esta sección.', 'ai-chatbot-pro'); ?></p>
    </div>
    <?php
}

function aicp_render_uploader($id, $value) { ?><div class="aicp-uploader-wrapper"><img src="<?php echo esc_url($value); ?>" id="<?php echo esc_attr($id); ?>_preview" class="aicp-preview-image"><input type="hidden" name="aicp_settings[<?php echo esc_attr($id); ?>_url]" id="<?php echo esc_attr($id); ?>_url" value="<?php echo esc_url($value); ?>"><button type="button" class="button button-secondary aicp-upload-button" data-target-id="<?php echo esc_attr($id); ?>"><?php _e('Elegir Imagen', 'ai-chatbot-pro'); ?></button><button type="button" class="button button-link aicp-remove-button" data-target-id="<?php echo esc_attr($id); ?>"><?php _e('Quitar', 'ai-chatbot-pro'); ?></button></div><?php }

function aicp_render_shortcode_meta_box($post) { ?><p><?php _e('Usa este shortcode para mostrar el asistente.', 'ai-chatbot-pro'); ?></p><input type="text" readonly value="[ai_chatbot_pro id=&quot;<?php echo $post->ID; ?>&quot;]" class="widefat" onfocus="this.select();"><?php }

function aicp_save_meta_box_data($post_id) {
    if (!isset($_POST['aicp_meta_box_nonce']) || !wp_verify_nonce($_POST['aicp_meta_box_nonce'], 'aicp_save_meta_box_data')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $s = $_POST['aicp_settings'] ?? [];
    $current = get_post_meta($post_id, '_aicp_assistant_settings', true);
    if (!is_array($current)) $current = [];

    // Instrucciones
    $current['model'] = isset($s['model']) ? sanitize_text_field($s['model']) : 'gpt-4o';
    $current['persona'] = isset($s['persona']) ? sanitize_textarea_field($s['persona']) : '';
    $current['objective'] = isset($s['objective']) ? sanitize_textarea_field($s['objective']) : '';
    $current['length_tone'] = isset($s['length_tone']) ? sanitize_textarea_field($s['length_tone']) : '';
    $current['example'] = isset($s['example']) ? sanitize_textarea_field($s['example']) : '';
    if (isset($s['suggested_messages']) && is_array($s['suggested_messages'])) { 
        $current['suggested_messages'] = array_map('sanitize_text_field', $s['suggested_messages']); 
    }

    // Diseño
    $current['bot_avatar_url'] = isset($s['bot_avatar_url']) ? esc_url_raw($s['bot_avatar_url']) : '';
    $current['user_avatar_url'] = isset($s['user_avatar_url']) ? esc_url_raw($s['user_avatar_url']) : '';
    $current['open_icon_url'] = isset($s['open_icon_url']) ? esc_url_raw($s['open_icon_url']) : '';
    $current['position'] = isset($s['position']) ? sanitize_key($s['position']) : 'br';
    $current['color_primary'] = isset($s['color_primary']) ? sanitize_hex_color($s['color_primary']) : '#0073aa';
    $current['color_bot_bg'] = isset($s['color_bot_bg']) ? sanitize_hex_color($s['color_bot_bg']) : '#ffffff';
    $current['color_bot_text'] = isset($s['color_bot_text']) ? sanitize_hex_color($s['color_bot_text']) : '#333333';
    $current['color_user_bg'] = isset($s['color_user_bg']) ? sanitize_hex_color($s['color_user_bg']) : '#dcf8c6';
    $current['color_user_text'] = isset($s['color_user_text']) ? sanitize_hex_color($s['color_user_text']) : '#000000';

    // Ajustes de captura de leads
    $current['lead_auto_collect'] = !empty($s['lead_auto_collect']) ? 1 : 0;
    $current['lead_email']        = isset($s['lead_email']) ? sanitize_email($s['lead_email']) : '';
    $current['webhook_url']       = isset($s['webhook_url']) ? esc_url_raw($s['webhook_url']) : '';
  

    // Se elimina el guardado de los mensajes de cierre que ya no existen
    unset($current['lead_action_messages']);
    unset($current['lead_closing_messages']); // También eliminamos el campo antiguo por si acaso
    if (class_exists('AICP_Pro_Features')) {
        $current = apply_filters('aicp_save_assistant_settings', $current, $s);
    }
    // Los campos PRO se guardan vacíos en la versión gratuita
    $current['training_post_types'] = [];
    
    update_post_meta($post_id, '_aicp_assistant_settings', $current);
}
add_action('save_post_aicp_assistant', 'aicp_save_meta_box_data');

// Esta es la función para mostrar el mensaje de venta, ahora en el lugar correcto.
function aicp_render_pro_upsell() {
    ?>
    <div class="aicp-pro-feature-wrapper">
        <h3><?php _e('Desbloquea todo el Potencial con la Versión PRO', 'ai-chatbot-pro'); ?></h3>
        <p><?php _e('Consigue el addon AI Chatbot Pro - Advanced Training para activar funcionalidades exclusivas:', 'ai-chatbot-pro'); ?></p>
        <ul>
            <li><strong><?php _e('Entrenamiento Avanzado (RAG):', 'ai-chatbot-pro'); ?></strong> <?php _e('Entrena a tu bot con todo el contenido de tu web, PDFs y más para respuestas increíblemente precisas.', 'ai-chatbot-pro'); ?></li>
            <li><strong><?php _e('Analytics Mejoradas:', 'ai-chatbot-pro'); ?></strong> <?php _e('Accede a gráficas y métricas avanzadas para entender el rendimiento de tus asistentes.', 'ai-chatbot-pro'); ?></li>
            <li><strong><?php _e('Traspaso a Humano:', 'ai-chatbot-pro'); ?></strong> <?php _e('Permite a los usuarios solicitar asistencia directa por email.', 'ai-chatbot-pro'); ?></li>
        </ul>
        <a href="https://metricaweb.es" target="_blank" class="button button-primary"><?php _e('Conseguir AI Chatbot Pro', 'ai-chatbot-pro'); ?></a>
    </div>
    <?php
}