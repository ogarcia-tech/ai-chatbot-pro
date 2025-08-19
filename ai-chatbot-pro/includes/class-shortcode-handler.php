<?php
/**
 * Clase que gestiona el shortcode del plugin.
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('ABSPATH')) exit;

class AICP_Shortcode_Handler {

    private static $assistant_id_to_render = 0;
    private static $is_initialized = false;

    public static function init() {
        add_shortcode('ai_chatbot_pro', [__CLASS__, 'register_chatbot']);
        add_action('wp_footer', [__CLASS__, 'render_chatbot_in_footer']);
    }

    /**
     * Registra que un chatbot debe ser renderizado y encola los scripts.
     */
    public static function register_chatbot($atts) {
        if (self::$is_initialized) {
            return '<!-- AI Chatbot Pro: Ya cargado en esta página. -->';
        }

        $atts = shortcode_atts(['id' => 0], $atts, 'ai_chatbot_pro');
        $assistant_id = absint($atts['id']);

        if (!$assistant_id || get_post_type($assistant_id) !== 'aicp_assistant') {
            return '<!-- AI Chatbot Pro: ID de asistente no válido. -->';
        }

        self::$assistant_id_to_render = $assistant_id;
        self::$is_initialized = true;

        self::enqueue_assets($assistant_id);

        // El shortcode ya no devuelve HTML, solo activa la carga en el footer.
        return '';
    }

    /**
     * Encola todos los scripts y estilos necesarios.
     */
    private static function enqueue_assets($assistant_id) {
        $s = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        if (empty($s) || !is_array($s)) return;

        wp_enqueue_style('aicp-chatbot-style', AICP_PLUGIN_URL . 'assets/css/chatbot.css', [], AICP_VERSION);

        $colors = [
            'primary'   => $s['color_primary'] ?? '#0073aa',
            'bot_bg'    => $s['color_bot_bg'] ?? '#ffffff',
            'bot_text'  => $s['color_bot_text'] ?? '#333333',
            'user_bg'   => $s['color_user_bg'] ?? '#dcf8c6',
            'user_text' => $s['color_user_text'] ?? '#000000',
        ];
        $custom_css = ":root { --aicp-color-primary: {$colors['primary']}; --aicp-color-bot-bg: {$colors['bot_bg']}; --aicp-color-bot-text: {$colors['bot_text']}; --aicp-color-user-bg: {$colors['user_bg']}; --aicp-color-user-text: {$colors['user_text']}; }";
        wp_add_inline_style('aicp-chatbot-style', $custom_css);

        wp_enqueue_script('aicp-chatbot-script', AICP_PLUGIN_URL . 'assets/js/chatbot.js', ['jquery'], AICP_VERSION, true);

        $default_bot_avatar  = AICP_PLUGIN_URL . 'assets/bot-default-avatar.png';
        $default_user_avatar = AICP_PLUGIN_URL . 'assets/user-default-avatar.png';
        $default_open_icon   = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>');

        $bot_avatar  = !empty($s['bot_avatar_url']) ? esc_url($s['bot_avatar_url']) : $default_bot_avatar;
        $user_avatar = !empty($s['user_avatar_url']) ? esc_url($s['user_avatar_url']) : $default_user_avatar;
        $open_icon = !empty($s['open_icon_url']) ? esc_url($s['open_icon_url']) : $default_open_icon;

        if (is_user_logged_in()) {
            $user_avatar = get_avatar_url(get_current_user_id(), ['size' => 96]);
        }
        
        $suggested_messages = array_filter($s['suggested_messages'] ?? []);
        
        wp_localize_script('aicp-chatbot-script', 'aicp_chatbot_params', [
            'ajax_url'           => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('aicp_chat_nonce'),
            'feedback_nonce'     => wp_create_nonce('aicp_feedback_nonce'),
            'assistant_id'       => $assistant_id,
            'header_title'       => esc_html(get_the_title($assistant_id)),
            'bot_avatar'         => $bot_avatar,
            'user_avatar'        => $user_avatar,
            'open_icon'          => $open_icon,
            'position'           => $s['position'] ?? 'br',
            'suggested_messages' => $suggested_messages,
        ]);
    }

    /**
     * Renderiza el contenedor del chatbot en el footer si ha sido registrado.
     */
    public static function render_chatbot_in_footer() {
        if (self::$assistant_id_to_render > 0) {
            echo '<div id="aicp-chatbot-container"></div>';
        }
    }
}
