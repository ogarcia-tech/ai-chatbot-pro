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
     * Sanitizar un valor de campo en función de su tipo
     */
    private static function sanitize_field_value($value, $type) {
        if (is_array($value)) {
            $value = implode(', ', array_map('sanitize_text_field', $value));
        }

        $value = is_scalar($value) ? (string) $value : '';

        switch ($type) {
            case 'email':
                return sanitize_email($value);
            case 'url':
            case 'website':
                return esc_url_raw($value);
            case 'phone':
                $clean = preg_replace('/[^0-9+\s\-().]/', '', $value);
                return sanitize_text_field($clean);
            case 'number':
                return is_numeric($value) ? (string) $value : sanitize_text_field($value);
            case 'textarea':
            case 'date':
            case 'text':
            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Sanitizar el conjunto de datos de un lead en base a la configuración del asistente
     */
    public static function sanitize_lead_input($lead_input, $assistant_id = 0, $assistant_settings = null, $field_definitions = null) {
        $lead_input = is_array($lead_input) ? $lead_input : [];

        if ($field_definitions === null) {
            $field_definitions = self::get_lead_field_definitions($assistant_id, $assistant_settings);
        }

        $sanitized = [];
        foreach ($field_definitions as $name => $definition) {
            if (!array_key_exists($name, $lead_input)) {
                continue;
            }

            $value = self::sanitize_field_value($lead_input[$name], $definition['type']);
            if ($value !== '' && $value !== null) {
                $sanitized[$name] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Extraer payloads estructurados de leads presentes en un contenido
     */
    private static function extract_structured_lead_payloads($content) {
        $payloads = [];

        if (!is_string($content) || strpos($content, '{') === false) {
            return $payloads;
        }

        if (preg_match_all('/\{(?:[^{}]|(?R))*\}/m', $content, $matches)) {
            foreach ($matches[0] as $json_candidate) {
                $decoded = json_decode($json_candidate, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
                self::collect_structured_payloads($decoded, $payloads);
            }
        }

        return $payloads;
    }

    /**
     * Recoger datos de lead de estructuras anidadas
     */
    private static function collect_structured_payloads($decoded, &$payloads) {
        if (!is_array($decoded)) {
            return;
        }

        if (isset($decoded['action']) && is_string($decoded['action']) && 'create_lead' === strtolower($decoded['action'])) {
            $data = [];
            if (isset($decoded['data']) && is_array($decoded['data'])) {
                $data = $decoded['data'];
            } else {
                $data = $decoded;
                unset($data['action']);
            }

            if (!empty($data)) {
                $payloads[] = $data;
            }
        }

        if (isset($decoded['create_lead']) && is_array($decoded['create_lead'])) {
            $payloads[] = $decoded['create_lead'];
        }

        foreach ($decoded as $value) {
            if (is_array($value)) {
                self::collect_structured_payloads($value, $payloads);
            }
        }
    }
    
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
    public static function detect_contact_data($conversation, $assistant_id = 0, $assistant_settings = null) {
        $field_definitions = self::get_lead_field_definitions($assistant_id, $assistant_settings);
        $required_fields   = self::extract_required_field_names($field_definitions);
        $lead_data         = [];

        if (!is_array($conversation)) {
            return [
                'has_lead'       => false,
                'is_complete'    => false,
                'data'           => [],
                'missing_fields' => $required_fields,
            ];
        }

        $user_messages = array_filter($conversation, function($message) {
            return isset($message['role']) && $message['role'] === 'user';
        });
        $assistant_messages = array_filter($conversation, function($message) {
            return isset($message['role']) && $message['role'] === 'assistant';
        });

        foreach ($assistant_messages as $message) {
            $content = isset($message['content']) ? $message['content'] : '';
            foreach (self::extract_structured_lead_payloads($content) as $payload) {
                $structured_data = self::sanitize_lead_input($payload, $assistant_id, $assistant_settings, $field_definitions);
                if (!empty($structured_data)) {
                    $lead_data = array_merge($lead_data, $structured_data);
                }
            }
        }

        $user_text = implode("\n", array_column($user_messages, 'content'));
        $fields_by_type = [];
        foreach ($field_definitions as $name => $definition) {
            $type = $definition['type'];
            if ($type === 'website') {
                $type = 'url';
            }
            if (!isset($fields_by_type[$type])) {
                $fields_by_type[$type] = [];
            }
            $fields_by_type[$type][] = $name;
        }

        $assignFirstAvailable = function($matches, $type) use (&$lead_data, $field_definitions, $fields_by_type) {
            if (empty($matches) || empty($fields_by_type[$type])) {
                return;
            }

            foreach ($matches as $value) {
                foreach ($fields_by_type[$type] as $field_name) {
                    if (!empty($lead_data[$field_name])) {
                        continue;
                    }

                    $sanitized = self::sanitize_field_value($value, $field_definitions[$field_name]['type']);
                    if ($sanitized !== '' && $sanitized !== null) {
                        $lead_data[$field_name] = $sanitized;
                        continue 2;
                    }
                }
            }
        };

        if (!empty($fields_by_type['email']) && preg_match_all(self::$email_pattern, $user_text, $email_matches)) {
            $assignFirstAvailable(array_unique($email_matches[0]), 'email');
        }

        if (!empty($fields_by_type['phone'])) {
            foreach (self::$phone_patterns as $pattern) {
                if (preg_match_all($pattern, $user_text, $phone_matches)) {
                    $assignFirstAvailable(array_unique($phone_matches[0]), 'phone');
                    break;
                }
            }
        }

        if (!empty($fields_by_type['url']) || !empty($fields_by_type['website'])) {
            if (preg_match_all(self::$url_pattern, $user_text, $url_matches)) {
                $common_email_domains = [
                    'gmail.com', 'yahoo.es', 'yahoo.com', 'hotmail.com', 'hotmail.es', 'outlook.com',
                    'outlook.es', 'msn.com', 'live.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com'
                ];
                $urls = [];
                foreach ($url_matches[0] as $potential_url) {
                    $cleaned_url = preg_replace('/^(https?:\/\/)?(www\.)?/', '', rtrim($potential_url, '/'));
                    if (in_array($cleaned_url, $urls, true) || in_array($cleaned_url, $common_email_domains, true)) {
                        continue;
                    }

                    $url = $potential_url;
                    if (!preg_match('/^https?:\/\//', $url)) {
                        $url = 'https://' . $url;
                    }

                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $urls[] = $url;
                    }
                }

                if (!empty($urls)) {
                    $type_key = !empty($fields_by_type['url']) ? 'url' : 'website';
                    $assignFirstAvailable($urls, $type_key);
                }
            }
        }

        if (isset($field_definitions['name']) && empty($lead_data['name'])) {
            $name = self::extract_name($user_text);
            if ($name) {
                $lead_data['name'] = self::sanitize_field_value($name, $field_definitions['name']['type']);
            }
        }

        $lead_data = self::sanitize_lead_input($lead_data, $assistant_id, $assistant_settings, $field_definitions);
        $lead_data = array_intersect_key($lead_data, $field_definitions);

        $missing_fields = self::get_missing_fields($lead_data, $assistant_id, $assistant_settings, $field_definitions);
        $has_partial_data = !empty($lead_data);
        $is_complete    = $has_partial_data && empty($missing_fields);

        return [
            'has_lead'       => $has_partial_data,
            'is_complete'    => $is_complete,
            'data'           => $lead_data,
            'missing_fields' => $missing_fields,
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
        $lead_info = self::detect_contact_data($conversation, $assistant_id);

        if (!$lead_info['is_complete']) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';

        $lead_payload = $lead_info['data'];
        if (!isset($lead_payload['captured_at'])) {
            $lead_payload['captured_at'] = current_time('mysql');
        }

        $wpdb->update(
            $table_name,
            [
                'has_lead'    => 1,
                'lead_data'   => wp_json_encode($lead_payload, JSON_UNESCAPED_UNICODE),
                'lead_status' => 'complete'
            ],
            ['id' => $log_id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        do_action('aicp_lead_detected', $lead_payload, $assistant_id, $log_id, 'complete');
    }

    private static function get_lead_field_definitions($assistant_id = 0, $assistant_settings = null) {
        $defaults = [
            'name' => [
                'label'    => __('nombre', 'ai-chatbot-pro'),
                'type'     => 'text',
                'required' => false,
            ],
            'email' => [
                'label'    => __('email', 'ai-chatbot-pro'),
                'type'     => 'email',
                'required' => true,
            ],
            'phone' => [
                'label'    => __('teléfono', 'ai-chatbot-pro'),
                'type'     => 'phone',
                'required' => false,
            ],
            'website' => [
                'label'    => __('sitio web', 'ai-chatbot-pro'),
                'type'     => 'url',
                'required' => false,
            ],
        ];

        $lead_fields = [];
        if (is_array($assistant_settings) && isset($assistant_settings['lead_fields']) && is_array($assistant_settings['lead_fields'])) {
            $lead_fields = $assistant_settings['lead_fields'];
        } elseif ($assistant_id) {
            $settings = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
            if (is_array($settings) && isset($settings['lead_fields']) && is_array($settings['lead_fields'])) {
                $lead_fields = $settings['lead_fields'];
            }
        }

        $normalized = [];
        foreach ($lead_fields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = sanitize_key($field['name'] ?? $key);
            if (!$name) {
                continue;
            }

            $label = sanitize_text_field($field['label'] ?? $name);
            $type  = sanitize_key($field['type'] ?? 'text');

            $normalized[$name] = [
                'label'    => $label ?: ucfirst(str_replace('_', ' ', $name)),
                'type'     => $type,
                'required' => !empty($field['required']),
            ];
        }

        return array_merge($defaults, $normalized);
    }

    private static function extract_required_field_names($field_definitions) {
        $required = [];
        foreach ($field_definitions as $name => $definition) {
            if (!empty($definition['required'])) {
                $required[] = $name;
            }
        }

        return $required;
    }

    public static function get_missing_fields($lead_data, $assistant_id = 0, $assistant_settings = null, $field_definitions = null) {
        $lead_data = is_array($lead_data) ? $lead_data : [];
        if ($field_definitions === null) {
            $field_definitions = self::get_lead_field_definitions($assistant_id, $assistant_settings);
        }

        $missing = [];
        foreach ($field_definitions as $name => $definition) {
            if (empty($definition['required'])) {
                continue;
            }

            $value = $lead_data[$name] ?? '';
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' || $value === null) {
                $missing[] = $name;
            }
        }

        return $missing;
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
    
    public static function handle_check_lead_status() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');

        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        if (!$log_id) {
            wp_send_json_error(['message' => __('ID de conversación inválido.', 'ai-chatbot-pro')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'aicp_chat_logs';

        $row = $wpdb->get_row($wpdb->prepare("SELECT assistant_id, lead_status, lead_data FROM {$table} WHERE id = %d", $log_id));
        if (!$row) {
            wp_send_json_error(['message' => __('No se encontró el registro solicitado.', 'ai-chatbot-pro')]);
        }

        $lead_data = json_decode($row->lead_data ?? '', true);
        if (!is_array($lead_data)) {
            $lead_data = [];
        }

        $status = $row->lead_status ?: 'none';
        $assistant_id = isset($row->assistant_id) ? (int) $row->assistant_id : 0;
        $missing_fields = self::get_missing_fields($lead_data, $assistant_id);

        wp_send_json_success([
            'status'          => $status,
            'lead_data'       => $lead_data,
            'missing_fields'  => $missing_fields,
            'message'         => self::get_missing_data_message($missing_fields, $assistant_id),
        ]);
    }

    public static function handle_calendar_lead() {
        check_ajax_referer('aicp_calendar_nonce', 'nonce');

        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;

        if (!$log_id || !$assistant_id) {
            wp_send_json_error(['message' => __('Datos inválidos para registrar el lead de calendario.', 'ai-chatbot-pro')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'aicp_chat_logs';

        $row = $wpdb->get_row($wpdb->prepare("SELECT lead_data FROM {$table} WHERE id = %d AND assistant_id = %d", $log_id, $assistant_id));
        if (!$row) {
            wp_send_json_error(['message' => __('No se encontró la conversación indicada.', 'ai-chatbot-pro')]);
        }

        $lead_data = json_decode($row->lead_data ?? '', true);
        if (!is_array($lead_data)) {
            $lead_data = [];
        }

        $lead_data['source'] = 'calendar';
        $lead_data['captured_at'] = current_time('mysql');

        $updated = $wpdb->update(
            $table,
            [
                'has_lead'    => 1,
                'lead_data'   => wp_json_encode($lead_data, JSON_UNESCAPED_UNICODE),
                'lead_status' => 'calendar',
            ],
            ['id' => $log_id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        if (false === $updated) {
            wp_send_json_error(['message' => __('No se pudo actualizar la conversación.', 'ai-chatbot-pro')]);
        }

        do_action('aicp_lead_detected', $lead_data, $assistant_id, $log_id, 'calendar');

        wp_send_json_success([
            'message'        => __('Lead marcado correctamente.', 'ai-chatbot-pro'),
            'lead_status'    => 'calendar',
            'lead_data'      => $lead_data,
            'missing_fields' => self::get_missing_fields($lead_data, $assistant_id),
        ]);
    }

    public static function get_lead_stats($assistant_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'aicp_chat_logs';

        $where_sql = 'WHERE has_lead = 1';
        if ($assistant_id) {
            $where_sql .= $wpdb->prepare(' AND assistant_id = %d', $assistant_id);
        }

        $stats = [
            'total_leads'     => 0,
            'complete_leads'  => 0,
            'partial_leads'   => 0,
            'calendar_leads'  => 0,
            'button_leads'    => 0,
            'form_leads'      => 0,
            'failed_leads'    => 0,
        ];

        $stats['total_leads'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where_sql}");

        $rows = $wpdb->get_results("SELECT lead_status, COUNT(*) AS total FROM {$table} {$where_sql} GROUP BY lead_status", ARRAY_A);
        foreach ((array) $rows as $row) {
            $status = $row['lead_status'] ?? 'unknown';
            $count  = isset($row['total']) ? (int) $row['total'] : 0;

            switch ($status) {
                case 'complete':
                    $stats['complete_leads'] = $count;
                    break;
                case 'partial':
                    $stats['partial_leads'] = $count;
                    break;
                case 'calendar':
                    $stats['calendar_leads'] = $count;
                    break;
                case 'button':
                    $stats['button_leads'] = $count;
                    break;
                case 'form':
                    $stats['form_leads'] = $count;
                    break;
                case 'failed':
                    $stats['failed_leads'] = $count;
                    break;
            }
        }

        return $stats;
    }

    public static function get_lead_field_config($assistant_id = 0, $assistant_settings = null) {
        $definitions = self::get_lead_field_definitions($assistant_id, $assistant_settings);
        $config = [];

        foreach ($definitions as $name => $definition) {
            $config[$name] = [
                'label'    => $definition['label'],
                'required' => !empty($definition['required']),
                'type'     => $definition['type'],
            ];
        }

        return $config;
    }

    public static function get_missing_data_message($missing_fields, $assistant_id = 0, $assistant_settings = null) {
        if (empty($missing_fields)) {
            return __('¡Perfecto! Tenemos toda tu información de contacto.', 'ai-chatbot-pro');
        }

        $field_definitions = self::get_lead_field_definitions($assistant_id, $assistant_settings);

        $translated = [];
        foreach ($missing_fields as $field) {
            if (isset($field_definitions[$field])) {
                $translated[] = $field_definitions[$field]['label'];
            } else {
                $translated[] = $field;
            }
        }

        if (count($translated) > 1) {
            $last = array_pop($translated);
            $missing_text = implode(', ', $translated) . ' ' . __('y', 'ai-chatbot-pro') . ' ' . $last;
        } else {
            $missing_text = $translated[0];
        }

        return sprintf(__('Necesitamos tu %s para completar el registro.', 'ai-chatbot-pro'), $missing_text);
    }
}
