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
    add_meta_box('aicp_main_settings_meta_box', __('Configuración del Asistente', 'ai-chatbot-pro'), 'aicp_render_main_meta_box', 'aicp_assistant', 'normal', 'high');
    add_meta_box('aicp_shortcode_meta_box', __('Shortcode', 'ai-chatbot-pro'), 'aicp_render_shortcode_meta_box', 'aicp_assistant', 'side', 'high');
}
add_action('add_meta_boxes_aicp_assistant', 'aicp_add_meta_boxes');
add_action('admin_footer', 'aicp_force_template_change_event');

function aicp_force_template_change_event() {
    global $post;
    if ($post && $post->post_type === 'aicp_assistant') {
        ?>
        <script>
            jQuery(document).ready(function($) {
                // Forzar el evento 'change' en el selector de plantillas para que los campos se rellenen
                $('#aicp_template_id').trigger('change');
            });
        </script>
        <?php
    }
}
/**
 * Carga los scripts y estilos necesarios para los meta boxes.
 */
function aicp_admin_scripts($hook) {
    global $post;

    $is_edit_screen = false;
    $post_id = 0;

    if ($hook === 'post.php' && isset($post->post_type) && 'aicp_assistant' === $post->post_type) {
        $is_edit_screen = true;
        $post_id = (int) $post->ID;
    } elseif ($hook === 'post-new.php') {
        $requested_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : '';
        if ('aicp_assistant' === $requested_type) {
            $is_edit_screen = true;
        }
    }

    if (!$is_edit_screen) {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_style('aicp-admin-styles', AICP_PLUGIN_URL . 'assets/css/admin.css', [], AICP_VERSION);
    wp_enqueue_style('aicp-chatbot-preview-styles', AICP_PLUGIN_URL . 'assets/css/chatbot.css', [], AICP_VERSION);
    wp_register_script('aicp-templates', AICP_PLUGIN_URL . 'templates/templates.js', [], AICP_VERSION, true);
    wp_enqueue_script('aicp-templates');
    wp_enqueue_script('aicp-admin-script', AICP_PLUGIN_URL . 'assets/js/admin-scripts.js', ['jquery', 'wp-color-picker', 'aicp-templates'], AICP_VERSION, true);

    $settings = [];
    if ($post_id > 0) {
        $settings = get_post_meta($post_id, '_aicp_assistant_settings', true);
    }
    if (!is_array($settings)) {
        $settings = [];
    }

    $default_bot_avatar  = AICP_PLUGIN_URL . 'assets/bot-default-avatar.png';
    $default_user_avatar = AICP_PLUGIN_URL . 'assets/user-default-avatar.png';
    $default_open_icon   = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>');

    $meta = [
        'brand' => sanitize_text_field(get_option('aicp_brand', '')),
        'domain' => sanitize_text_field(get_option('aicp_domain', '')),
        'services' => array_map('sanitize_text_field', (array) get_option('aicp_services', [])),
        'pricing_ranges' => array_map('sanitize_text_field', (array) get_option('aicp_pricing_ranges', [])),
        'timezone' => sanitize_text_field(wp_timezone_string()),
    ];

    wp_localize_script('aicp-admin-script', 'aicp_admin_params', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'assistant_id' => $post_id,
        'delete_nonce' => wp_create_nonce('aicp_delete_log_nonce'),
        'get_log_nonce' => wp_create_nonce('aicp_get_log_nonce'),
        'capture_lead_nonce' => wp_create_nonce('aicp_capture_lead_nonce'),
        'test_webhook_nonce' => wp_create_nonce('aicp_test_webhook_nonce'),
        'default_bot_avatar' => $default_bot_avatar,
        'default_user_avatar' => $default_user_avatar,
        'default_open_icon' => $default_open_icon,
        'templates_url' => add_query_arg('action', 'aicp_get_templates', admin_url('admin-ajax.php')),
        'template_description_fallback' => __('Selecciona una plantilla para precargar un prompt de sistema.', 'ai-chatbot-pro'),
        'lead_fields' => AICP_Lead_Manager::get_lead_field_config($post_id, $settings),
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
            'provider' => $settings['provider'] ?? 'openai',
            'model' => $settings['model'] ?? '',
            'master_prompt' => $settings['master_prompt'] ?? '',
            'template_id' => $settings['template_id'] ?? '',
            'quick_replies' => isset($settings['quick_replies']) && is_array($settings['quick_replies']) ? array_values($settings['quick_replies']) : [],
            'forward_to_webhook' => !empty($settings['forward_to_webhook']),
        ],
        'meta' => $meta,
        'webhook_warning' => __('Si activas el reenvío al webhook externo, el asistente ignorará las instrucciones internas y no podrás editarlas hasta desactivar la integración. ¿Deseas continuar?', 'ai-chatbot-pro'),
        'webhook_lock_message' => __('La integración con webhook está activa. Desactívala para volver a entrenar el asistente o modificar estas opciones PRO.', 'ai-chatbot-pro'),
        'test_webhook_labels' => [
            'empty_url'      => __('Introduce una URL de webhook antes de lanzar la prueba.', 'ai-chatbot-pro'),
            'request_error'  => __('No se pudo completar la solicitud. Revisa la consola o inténtalo de nuevo.', 'ai-chatbot-pro'),
            'success_title'  => __('El webhook respondió correctamente.', 'ai-chatbot-pro'),
            'error_title'    => __('El webhook devolvió un error.', 'ai-chatbot-pro'),
            'http_status'    => __('Código HTTP', 'ai-chatbot-pro'),
            'reply'          => __('Respuesta del webhook', 'ai-chatbot-pro'),
            'metadata'       => __('Metadatos recibidos', 'ai-chatbot-pro'),
            'metadata_empty' => __('El webhook no devolvió metadatos.', 'ai-chatbot-pro'),
            'payload'        => __('Payload enviado', 'ai-chatbot-pro'),
            'raw_body'       => __('Cuerpo de la respuesta', 'ai-chatbot-pro'),
            'request_headers'=> __('Cabeceras enviadas', 'ai-chatbot-pro'),
            'response_headers'=> __('Cabeceras de respuesta', 'ai-chatbot-pro'),
            'duration'       => __('Duración de la petición', 'ai-chatbot-pro'),
            'seconds'        => __('segundos', 'ai-chatbot-pro'),
            'error_code'     => __('Código de error', 'ai-chatbot-pro'),
            'sending'        => __('Enviando solicitud al webhook…', 'ai-chatbot-pro'),
            'request_url'    => __('URL solicitada', 'ai-chatbot-pro'),
            'hint'           => __('Sugerencia', 'ai-chatbot-pro'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'aicp_admin_scripts');

/**
 * Renderiza el contenido principal de los meta boxes con pestañas.
 */
function aicp_render_main_meta_box($post) {
    wp_nonce_field('aicp_save_meta_box_data', 'aicp_meta_box_nonce');
    $v = get_post_meta($post->ID, '_aicp_assistant_settings', true);
    if (!is_array($v)) $v = [];
    $forwarding_active = !empty($v['forward_to_webhook']) && !empty($v['forward_webhook_url']);
    $leads_tab_classes = 'aicp-tab-content';
    if ($forwarding_active) {
        $leads_tab_classes .= ' aicp-leads-tab--locked';
    }

    $pro_tab_classes = 'aicp-tab-content';
    if ($forwarding_active) {
        $pro_tab_classes .= ' aicp-pro-tab--locked';
    }
    ?>
    <div class="aicp-section-block" id="aicp-tab-instructions">
        <h3><?php _e('Instrucciones y modelo', 'ai-chatbot-pro'); ?></h3>
        <p class="description"><?php _e('Configura un único prompt maestro, elige el proveedor y el modelo, o aplica una de las plantillas de sistema incluidas.', 'ai-chatbot-pro'); ?></p>
        <?php aicp_render_instructions_tab($v); ?>
    </div>
    <div class="aicp-section-block" id="aicp-tab-design">
        <h3><?php _e('Diseño y apariencia', 'ai-chatbot-pro'); ?></h3>
        <div class="aicp-design-layout">
            <div class="aicp-design-settings">
                <?php aicp_render_design_tab($v); ?>
            </div>
            <div class="aicp-design-preview">
                <?php aicp_render_preview_panel(); ?>
            </div>
        </div>
    </div>
    <div class="aicp-section-block <?php echo esc_attr($leads_tab_classes); ?>" id="aicp-tab-leads" data-forwarding-active="<?php echo $forwarding_active ? '1' : '0'; ?>">
        <h3><?php _e('Leads y conversaciones', 'ai-chatbot-pro'); ?></h3>
        <?php aicp_render_leads_tab($post->ID, $v); ?>
    </div>
    <div class="aicp-section-block" id="aicp-tab-integrations">
        <h3><?php _e('Integraciones', 'ai-chatbot-pro'); ?></h3>
        <?php aicp_render_integrations_tab($post->ID, $v); ?>
    </div>

    <?php // Lógica corregida y limpia para mostrar el contenido PRO o el mensaje de venta.
    if (class_exists('AICP_Pro_Features')) : ?>
        <div class="aicp-section-block <?php echo esc_attr($pro_tab_classes); ?>" id="aicp-tab-pro" data-forwarding-active="<?php echo $forwarding_active ? '1' : '0'; ?>">
            <h3><?php _e('Funciones PRO', 'ai-chatbot-pro'); ?></h3>
            <div class="notice notice-warning inline aicp-pro-lock-notice"<?php echo $forwarding_active ? '' : ' style="display:none;"'; ?>>
                <p><?php _e('La integración con webhook está activa. Desactívala para volver a entrenar el asistente o modificar estas opciones PRO.', 'ai-chatbot-pro'); ?></p>
            </div>
            <?php
            do_action('aicp_pro_tab_content');
            ?>
        </div>
    <?php else: ?>
        <div class="aicp-section-block <?php echo esc_attr($pro_tab_classes); ?>" id="aicp-tab-pro-upsell" data-forwarding-active="<?php echo $forwarding_active ? '1' : '0'; ?>">
            <h3><?php _e('Funciones PRO', 'ai-chatbot-pro'); ?></h3>
            <?php aicp_render_pro_upsell(); ?>
        </div>
    <?php endif; ?>
    <?php
}

function aicp_render_instructions_tab($v) {
    $prompt = $v['master_prompt'] ?? '';
    $is_forwarding_enabled = !empty($v['forward_to_webhook']);
    $selected_model    = $v['model'] ?? '';
    ?>
    <div class="notice notice-warning inline aicp-instructions-lock-notice"<?php echo $is_forwarding_enabled ? '' : ' style="display:none;"'; ?>>
        <p><?php _e('La integración con webhook está activa. Desactívala para volver a entrenar el asistente o modificar estas opciones PRO.', 'ai-chatbot-pro'); ?></p>
    </div>
    <div class="aicp-instructions-fields<?php echo $is_forwarding_enabled ? ' aicp-instructions-locked' : ''; ?>">
        <table class="form-table">

        <tr>
            <th><label for="aicp_template_id"><?php _e('Plantilla de System Prompt', 'ai-chatbot-pro'); ?></label></th>
            <td>
                <select name="aicp_settings[template_id]" id="aicp_template_id" class="regular-text">
                    <option value=""><?php _e('Personalizado', 'ai-chatbot-pro'); ?></option>
                </select>
                <p id="aicp_template_description" class="description"><?php _e('Selecciona una de las 10 plantillas incluidas para rellenar el prompt maestro al instante.', 'ai-chatbot-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="aicp_provider"><?php _e('Proveedor de modelo', 'ai-chatbot-pro'); ?></label></th>
            <td>
                <?php $provider = $v['provider'] ?? 'openai'; ?>
                <select name="aicp_settings[provider]" id="aicp_provider">
                    <option value="openai" <?php selected($provider, 'openai'); ?>><?php _e('OpenAI / ChatGPT', 'ai-chatbot-pro'); ?></option>
                    <option value="gemini" <?php selected($provider, 'gemini'); ?>><?php _e('Google Gemini', 'ai-chatbot-pro'); ?></option>
                    <option value="custom" <?php selected($provider, 'custom'); ?>><?php _e('Otro proveedor', 'ai-chatbot-pro'); ?></option>
                </select>
                <p class="description"><?php _e('Elige qué driver se usará para procesar el prompt maestro.', 'ai-chatbot-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="aicp_model"><?php _e('Modelo', 'ai-chatbot-pro'); ?></label></th>
            <td>
                <input type="text" name="aicp_settings[model]" id="aicp_model" class="regular-text" value="<?php echo esc_attr($selected_model); ?>" placeholder="<?php esc_attr_e('Ej: gpt-4o-mini, gemini-1.5-pro, llama3', 'ai-chatbot-pro'); ?>">
                <p class="description"><?php _e('Introduce el nombre exacto del modelo que quieres usar en este asistente.', 'ai-chatbot-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label><?php _e('Respuestas Rápidas', 'ai-chatbot-pro'); ?></label></th>
            <td>
                <input type="text" name="aicp_settings[quick_replies][]" value="<?php echo esc_attr($v['quick_replies'][0] ?? ''); ?>" class="large-text" placeholder="<?php esc_attr_e('Ej: Me interesa el servicio de SEO', 'ai-chatbot-pro'); ?>"><br>
                <input type="text" name="aicp_settings[quick_replies][]" value="<?php echo esc_attr($v['quick_replies'][1] ?? ''); ?>" class="large-text" placeholder="<?php esc_attr_e('Ej: Quiero una web económica', 'ai-chatbot-pro'); ?>"><br>
                <input type="text" name="aicp_settings[quick_replies][]" value="<?php echo esc_attr($v['quick_replies'][2] ?? ''); ?>" class="large-text" placeholder="<?php esc_attr_e('Ej: ¿Podéis llamarme?', 'ai-chatbot-pro'); ?>">
                <p class="description"><?php _e('Estas respuestas aparecerán como botones clicables para el usuario.', 'ai-chatbot-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="aicp_custom_prompt"><?php _e('Prompt maestro (unificado)', 'ai-chatbot-pro'); ?></label></th>
            <td>
                <textarea name="aicp_settings[master_prompt]" id="aicp_custom_prompt" rows="12" class="large-text" placeholder="<?php esc_attr_e('El prompt maestro combina la plantilla y tus ajustes personalizados.', 'ai-chatbot-pro'); ?>"><?php echo esc_textarea($prompt); ?></textarea>
                <p class="description"><?php _e('Escribe o pega aquí el prompt maestro definitivo que usará el chatbot. Puedes partir de una plantilla y después personalizarlo libremente.', 'ai-chatbot-pro'); ?></p>
            </td>
        </tr>
        </table>
    </div>
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

function aicp_render_integrations_tab($assistant_id, $v) {
    $enabled     = !empty($v['forward_to_webhook']);
    $webhook_url = esc_url($v['forward_webhook_url'] ?? '');
    $secret      = sanitize_text_field($v['forward_webhook_secret'] ?? '');
    $timeout     = isset($v['forward_webhook_timeout']) ? max(5, intval($v['forward_webhook_timeout'])) : 15;

    echo '<h4>' . __('Webhook de Mensajes', 'ai-chatbot-pro') . '</h4>';
    echo '<table class="form-table"><tbody>';

    echo '<tr><th><label for="aicp_forward_to_webhook">' . __('Reenviar cada mensaje a un webhook externo', 'ai-chatbot-pro') . '</label></th>';
    echo '<td><label><input type="checkbox" name="aicp_settings[forward_to_webhook]" id="aicp_forward_to_webhook" value="1" ' . checked($enabled, true, false) . '> ' . __('Activar', 'ai-chatbot-pro') . '</label>';
    echo '<p class="description">' . __('Al activarlo, el chatbot enviará el mensaje del usuario y el contexto a la URL configurada antes de responder.', 'ai-chatbot-pro') . '</p></td></tr>';

    echo '<tr><th><label for="aicp_forward_webhook_url">' . __('URL del webhook', 'ai-chatbot-pro') . '</label></th>';
    echo '<td><input type="url" name="aicp_settings[forward_webhook_url]" id="aicp_forward_webhook_url" value="' . esc_attr($webhook_url) . '" class="regular-text" placeholder="https://tuservidor.com/webhook/chatbot" />';
    echo '<p class="description">' . __('Utiliza la URL que n8n (u otro servicio) genere para recibir las peticiones.', 'ai-chatbot-pro') . '</p></td></tr>';

    echo '<tr><th><label for="aicp_forward_webhook_secret">' . __('Cabecera secreta opcional', 'ai-chatbot-pro') . '</label></th>';
    echo '<td><input type="text" name="aicp_settings[forward_webhook_secret]" id="aicp_forward_webhook_secret" value="' . esc_attr($secret) . '" class="regular-text" placeholder="mi-token-secreto" />';
    echo '<p class="description">' . __('Si defines un valor, el plugin añadirá la cabecera <code>X-AICP-Webhook-Secret</code> en cada solicitud para que puedas validarla en n8n.', 'ai-chatbot-pro') . '</p></td></tr>';

    echo '<tr><th><label for="aicp_forward_webhook_timeout">' . __('Tiempo de espera', 'ai-chatbot-pro') . '</label></th>';
    echo '<td><input type="number" min="5" max="120" name="aicp_settings[forward_webhook_timeout]" id="aicp_forward_webhook_timeout" value="' . esc_attr($timeout) . '" class="small-text" /> ' . __('segundos', 'ai-chatbot-pro');
    echo '<p class="description">' . __('El chatbot mostrará el error si el webhook no responde a tiempo.', 'ai-chatbot-pro') . '</p></td></tr>';

    echo '<tr><th>' . __('Probar conexión', 'ai-chatbot-pro') . '</th>';
    echo '<td><button type="button" class="button button-secondary" id="aicp_test_webhook_button">' . esc_html__('Enviar mensaje de prueba', 'ai-chatbot-pro') . '</button>';
    echo ' <span class="spinner" id="aicp_test_webhook_spinner" style="float:none;margin-top:0;"></span>';
    echo '<p class="description">' . __('Envía un mensaje de prueba al webhook y revisa la respuesta sin salir del editor.', 'ai-chatbot-pro') . '</p>';
    echo '<div id="aicp_test_webhook_feedback" class="notice notice-alt inline" style="display:none;" aria-live="polite" role="status"></div>';
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<p class="description">' . __('Consulta la guía de integración con n8n incluida en la carpeta de documentación del plugin para ver ejemplos de payloads y respuestas esperadas.', 'ai-chatbot-pro') . '</p>';
}

function aicp_render_leads_tab($assistant_id, $v) {
    global $wpdb;

    $logs_table = $wpdb->prefix . 'aicp_chat_logs';

    echo '<p>' . __('El historial muestra las conversaciones y los datos estructurados capturados automáticamente. Los ajustes de leads se simplifican a los datos detectados dentro de cada conversación.', 'ai-chatbot-pro') . '</p>';
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

function aicp_compile_prompt($settings) {
    if (!class_exists('AICP_Prompt_Builder')) {
        require_once AICP_PLUGIN_DIR . 'includes/class-prompt-builder.php';
    }
    return AICP_Prompt_Builder::build($settings);
}

function aicp_save_meta_box_data($post_id) {
    if (!isset($_POST['aicp_meta_box_nonce']) || !wp_verify_nonce($_POST['aicp_meta_box_nonce'], 'aicp_save_meta_box_data')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $s = $_POST['aicp_settings'] ?? [];
    $current = get_post_meta($post_id, '_aicp_assistant_settings', true);
    if (!is_array($current)) $current = [];

    $was_forwarding = !empty($current['forward_to_webhook']) && !empty($current['forward_webhook_url']);
    $new_forward_flag = !empty($s['forward_to_webhook']) ? 1 : 0;
    $new_forward_url = isset($s['forward_webhook_url']) ? esc_url_raw($s['forward_webhook_url']) : '';
    $new_forward_secret = isset($s['forward_webhook_secret']) ? sanitize_text_field($s['forward_webhook_secret']) : '';
    $timeout_value = isset($s['forward_webhook_timeout']) ? intval($s['forward_webhook_timeout']) : 15;
    $new_forward_timeout = max(5, min(120, $timeout_value));
    $will_forward = $new_forward_flag && !empty($new_forward_url);
    $lock_sections = $was_forwarding && $will_forward;

    $current['forward_to_webhook'] = $new_forward_flag;
    $current['forward_webhook_url'] = $new_forward_url;
    $current['forward_webhook_secret'] = $new_forward_secret;
    $current['forward_webhook_timeout'] = $new_forward_timeout;

    if (!$lock_sections) {
        // Instrucciones
        $current['provider'] = isset($s['provider']) ? sanitize_key($s['provider']) : 'openai';
        $current['model'] = isset($s['model']) ? sanitize_text_field($s['model']) : '';
        $current['master_prompt'] = isset($s['master_prompt']) ? wp_kses_post(wp_unslash($s['master_prompt'])) : '';
        $current['template_id'] = isset($s['template_id']) ? sanitize_text_field($s['template_id']) : '';
        if (isset($s['quick_replies']) && is_array($s['quick_replies'])) {
            $current['quick_replies'] = array_values(array_filter(array_map('sanitize_text_field', $s['quick_replies'])));
        } else {
            $current['quick_replies'] = [];
        }

        // Ajustes de captura de leads simplificados
        $current['lead_history_enabled'] = 1;
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