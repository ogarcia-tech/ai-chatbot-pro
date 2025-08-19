<?php
/**
 * Clase para gestionar leads y detección de datos de contacto
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class AICP_Lead_Manager {
    
    /**
     * Patrones para detectar emails, teléfonos y URLs
     */
    private static $email_pattern = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
    private static $phone_patterns = [
        '/\b(?:\+34|0034|34)?[ -]?[6789]\d{2}[ -]?\d{2}[ -]?\d{2}[ -]?\d{2}\b/', // España
        '/\b(?:\+\d{1,3}[ -]?)?\(?\d{3}\)?[ -]?\d{3}[ -]?\d{4}\b/', // Internacional
        '/\b\d{3}[ -]?\d{3}[ -]?\d{3}\b/', // Formato simple
    ];
    private static $url_pattern = '/(https?:\/\/)?([\w\-]+\.)+[\w\-]+(\/[\w\-._~:\/?#[\]@!$&\'()*+,;=]*)?/';
    
    /**
     * Inicializar la clase
     */
    public static function init() {
        add_action('wp_ajax_aicp_mark_calendar_lead', [__CLASS__, 'handle_calendar_lead']);
        add_action('wp_ajax_nopriv_aicp_mark_calendar_lead', [__CLASS__, 'handle_calendar_lead']);
        add_action('wp_ajax_aicp_check_lead_status', [__CLASS__, 'handle_check_lead_status']);
        add_action('wp_ajax_nopriv_aicp_check_lead_status', [__CLASS__, 'handle_check_lead_status']);
        
        // Hook para procesar leads después de guardar conversación
        add_action('aicp_conversation_saved', [__CLASS__, 'process_lead_data'], 10, 3);

        // Enviar lead a webhook si se configura
        add_action('aicp_lead_detected', [__CLASS__, 'send_lead_to_webhook'], 10, 4);

        // Notificar por email si corresponde

        add_action('aicp_lead_detected', [__CLASS__, 'email_lead_notification'], 10, 4);
    }
    
    /**
     * Detectar datos de contacto en una conversación
     */
    public static function detect_contact_data($conversation) {
        $lead_data = [];
        $has_contact = false;
        
        if (!is_array($conversation)) {
            return ['has_lead' => false, 'data' => [], 'missing_fields' => ['name', 'email', 'phone', 'website']];
        }

        // Filtramos para obtener solo los mensajes del usuario.
        $user_messages = array_filter($conversation, function($message) {
            return isset($message['role']) && $message['role'] === 'user';
        });
        // Concatenamos solo el texto del usuario para el análisis.
        $user_text = implode("\n", array_column($user_messages, 'content'));
        $common_email_domains = [
            'gmail.com', 'yahoo.es', 'yahoo.com', 'hotmail.com', 'hotmail.es', 'outlook.com', 
            'outlook.es', 'msn.com', 'live.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com'
        ];
        
        // Detectar emails del texto del usuario
        if (preg_match(self::$email_pattern, $user_text, $matches)) {
            $lead_data['email'] = sanitize_email($matches[0]);
            $has_contact = true;
        }
        
        // Detectar teléfonos del texto del usuario
        if (!isset($lead_data['phone'])) {
            foreach (self::$phone_patterns as $pattern) {
                if (preg_match($pattern, $user_text, $matches)) {
                    $phone = preg_replace('/[^\d+]/', '', $matches[0]);
                    if (strlen($phone) >= 9) {
                        $lead_data['phone'] = sanitize_text_field($matches[0]);
                        $has_contact = true;
                        break;
                    }
                }
            }
        }
        
        // Detectar URLs/websites del texto del usuario
        if (preg_match_all(self::$url_pattern, $user_text, $matches)) {
            foreach ($matches[0] as $potential_url) {
                // Limpiamos la URL para compararla con nuestra lista negra.
                $cleaned_url = preg_replace('/^(https?:\/\/)?(www\.)?/', '', rtrim($potential_url, '/'));
                
                // Si la URL encontrada NO está en nuestra lista de dominios de email, la aceptamos.
                if (!in_array($cleaned_url, $common_email_domains)) {
                    $url = $potential_url;
                    if (!preg_match('/^https?:\/\//', $url)) {
                        $url = 'https://' . $url;
                    }
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $lead_data['website'] = esc_url_raw($url);
                        break; // Nos quedamos con la primera web válida que no sea un email.
                    }
                }
            }
        }
        
        // Detectar nombre del texto del usuario
        if (!isset($lead_data['name'])) {
            $name = self::extract_name($user_text);
            if ($name) {
                $lead_data['name'] = sanitize_text_field($name);
            }
        }
        
        $required_fields = ['name', 'email', 'phone', 'website'];
        $missing_fields = array_diff($required_fields, array_keys($lead_data));
        $is_complete_lead = isset($lead_data['email']) || isset($lead_data['phone']);
        
        return [
            'has_lead' => $has_contact || isset($lead_data['name']), // Un nombre también puede iniciar un lead
            'is_complete' => $is_complete_lead,
            'data' => $lead_data,
            'missing_fields' => $missing_fields
        ];
    }
    
    /**
     * Extraer nombre de un mensaje (heurística mejorada)
     */
    private static function extract_name($content) {
        $patterns = [
            '/(?:me llamo|soy|mi nombre es)\s+([A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s+[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+)?)/i',
            '/(?:my name is|i am|i\'m)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                return trim($matches[1]);
            }
        }
        
        // Si no hay frases como "me llamo", busca un nombre propio simple.
        // Esto ayuda a capturar respuestas directas como "Oscar".
        $words = explode(' ', $content);
        if (count($words) < 4) { // Solo en mensajes cortos
            foreach($words as $word){
                // Un nombre propio suele empezar con mayúscula y no contener números.
                if (preg_match('/^[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+$/', $word)) {
                    return $word;
                }
            }
        }

        return null;
    }
    
    /**
     * Procesar datos de lead después de guardar conversación
     */
    public static function process_lead_data($log_id, $assistant_id, $conversation) {
        $lead_info = self::detect_contact_data($conversation);
        
        if ($lead_info['has_lead']) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'aicp_chat_logs';
            
            $lead_status = $lead_info['is_complete'] ? 'complete' : 'partial';
            
            $wpdb->update(
                $table_name,
                [
                    'has_lead' => 1,
                    'lead_data' => wp_json_encode($lead_info['data'], JSON_UNESCAPED_UNICODE),
                    'lead_status' => $lead_status
                ],
                ['id' => $log_id],
                ['%d', '%s', '%s'],
                ['%d']
            );
            do_action('aicp_lead_detected', $lead_info['data'], $assistant_id, $log_id, $lead_status);
        }
    }

    /**
     * Enviar los datos del lead a la URL configurada.
     */
    public static function send_lead_to_webhook($lead_data, $assistant_id, $log_id, $lead_status) {
        $settings = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        $url = isset($settings['webhook_url']) ? esc_url_raw($settings['webhook_url']) : '';

        if (!$url) {
            $options = get_option('aicp_settings');
            $url = isset($options['lead_webhook_url']) ? esc_url_raw($options['lead_webhook_url']) : '';
        }

        if (!$url) {
            return;
        }

        wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'lead_data'    => $lead_data,
                'assistant_id' => $assistant_id,
                'log_id'       => $log_id,
                'lead_status'  => $lead_status,
            ]),
            'timeout' => 15,
        ]);
    }


    /**
     * Enviar notificación por email con los datos del lead.
     */
    public static function email_lead_notification($lead_data, $assistant_id, $log_id, $lead_status) {
        $settings = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        $email    = isset($settings['lead_email']) ? sanitize_email($settings['lead_email']) : '';
        if (!$email) {
            $email = get_option('admin_email');
        }
        if (!$email) {
            return;
        }

        $subject = __('Nuevo lead detectado', 'ai-chatbot-pro');
        $lines   = [];
        foreach ($lead_data as $key => $value) {
            $lines[] = ucfirst($key) . ': ' . $value;
        }
        $message = implode("\n", $lines);

        wp_mail($email, $subject, $message);

    }
    
    /**
     * Resto de funciones sin modificar...
     */
    public static function handle_check_lead_status() { /* ...código original... */ }
    public static function handle_calendar_lead() { /* ...código original... */ }
    public static function get_lead_stats($assistant_id) { /* ...código original... */ }
    public static function get_missing_data_message($missing_fields) { /* ...código original... */ }
}