<?php
if (!defined('ABSPATH')) exit;

class AICP_Pro_Ajax_Handler {
    public static function init() {
        add_action('wp_ajax_aicp_pro_human_takeover', [__CLASS__, 'handle_human_takeover']);
        add_action('wp_ajax_nopriv_aicp_pro_human_takeover', [__CLASS__, 'handle_human_takeover']);
        add_action('wp_ajax_aicp_start_sync', [__CLASS__, 'handle_start_sync']);
    }

    public static function handle_start_sync() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aicp_save_meta_box_data')) {
            wp_send_json_error(['message' => 'Fallo de seguridad.']);
        }
        
        // Cargar el "cerebro" y pasarle el control
        require_once __DIR__ . '/class-pinecone-manager.php';
        AICP_Pinecone_Manager::handle_sync_request();
    }

    public static function handle_human_takeover() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');

        $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
        $conversation_json = isset($_POST['conversation']) ? wp_unslash($_POST['conversation']) : '[]';
        $conversation = json_decode($conversation_json, true);
        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;

        $assistant_name = get_the_title($assistant_id);
        $admin_email = get_option('admin_email');
        $subject = 'Solicitud de Asistencia Humana desde: ' . $assistant_name;
        
        $message = "Un usuario ha solicitado hablar con un humano desde el asistente '" . esc_html($assistant_name) . "'.<br><br>";
        if ($log_id > 0) {
             $message .= "Puedes ver la conversación en el historial del chatbot (Log ID: " . $log_id . ").<br><br>";
        }
        $message .= "<strong>--- Transcripción de la Conversación ---</strong><br><br>";

        if (is_array($conversation)) {
            foreach ($conversation as $msg) {
                $role = ($msg['role'] === 'user') ? 'Usuario' : 'Asistente';
                $message .= "<strong>" . $role . ":</strong> " . nl2br(esc_html($msg['content'])) . "<br><br>";
            }
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($admin_email, $subject, $message, $headers);

        wp_send_json_success(['message' => 'Notificación enviada.']);
    }
}