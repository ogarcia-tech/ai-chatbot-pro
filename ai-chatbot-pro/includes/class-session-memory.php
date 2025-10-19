<?php
/**
 * Gestiona la memoria de las sesiones del chatbot en una cola circular.
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

class AICP_Session_Memory {
    /** Número máximo de mensajes a mantener por sesión. */
    public const MAX_MESSAGES = 15;

    /** Prefijo utilizado para almacenar los transients. */
    private const TRANSIENT_PREFIX = 'aicp_mem_';

    /** Tiempo de expiración de la memoria (en segundos). */
    private const EXPIRATION = 6 * HOUR_IN_SECONDS;

    /** Almacenamiento alternativo usado en entornos de test sin WordPress. */
    private static $fallback_store = [];

    /**
     * Obtiene y normaliza la conversación almacenada para una sesión.
     */
    public static function load($session_id, $ip = '', $assistant_id = 0) {
        $key = self::build_key($session_id, $ip, $assistant_id);
        $history = self::get_transient_value($key);

        if (!is_array($history) || empty($history)) {
            $history = self::load_from_logs($session_id, $assistant_id);
            if (!empty($history)) {
                self::set_transient_value($key, $history);
            }
        }

        return self::normalize_history($history);
    }

    /**
     * Guarda una conversación completa sobrescribiendo la cola circular.
     */
    public static function persist($session_id, $ip, array $conversation, $assistant_id = 0) {
        $normalized = self::normalize_history($conversation);
        $key = self::build_key($session_id, $ip, $assistant_id);
        self::set_transient_value($key, $normalized);
        return $normalized;
    }

    /**
     * Añade mensajes a la conversación manteniendo el límite de la cola circular.
     */
    public static function append($session_id, $ip, array $messages, $assistant_id = 0) {
        $current = self::load($session_id, $ip, $assistant_id);
        $merged = array_merge($current, self::normalize_history($messages));
        return self::persist($session_id, $ip, $merged, $assistant_id);
    }

    /**
     * Elimina la memoria almacenada para una sesión concreta.
     */
    public static function forget($session_id, $ip = '', $assistant_id = 0) {
        $key = self::build_key($session_id, $ip, $assistant_id);
        self::delete_transient_value($key);
    }

    /**
     * Construye una clave segura basada en la sesión, IP y asistente.
     */
    private static function build_key($session_id, $ip, $assistant_id) {
        $session = is_string($session_id) ? trim($session_id) : '';
        $ip_hash = is_string($ip) ? trim($ip) : '';
        $assistant = self::absint($assistant_id);

        if ('' === $session && '' === $ip_hash) {
            $ip_hash = 'guest';
        }

        $raw = $session . '|' . $ip_hash . '|' . $assistant;
        return self::TRANSIENT_PREFIX . md5($raw);
    }

    /**
     * Normaliza una conversación asegurando estructura y límite de elementos.
     */
    private static function normalize_history($conversation) {
        if (!is_array($conversation)) {
            return [];
        }

        $normalized = [];

        foreach ($conversation as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = self::sanitize_role($message['role'] ?? '');
            $content = self::sanitize_content($message['content'] ?? '');

            if ('' === $content || '' === $role) {
                continue;
            }

            $normalized[] = [
                'role'    => $role,
                'content' => $content,
            ];
        }

        if (count($normalized) > self::MAX_MESSAGES) {
            $normalized = array_slice($normalized, -self::MAX_MESSAGES);
        }

        return $normalized;
    }

    /**
     * Intenta reconstruir la conversación desde la tabla de logs si es posible.
     */
    private static function load_from_logs($session_id, $assistant_id = 0) {
        if (!is_string($session_id) || '' === trim($session_id)) {
            return [];
        }

        global $wpdb;
        if (!isset($wpdb) || !isset($wpdb->prefix)) {
            return [];
        }

        $table = $wpdb->prefix . 'aicp_chat_logs';
        $prepared = $wpdb->prepare(
            "SELECT conversation_log FROM {$table} WHERE session_id = %s" .
            ($assistant_id ? ' AND assistant_id = %d' : '') .
            ' ORDER BY id DESC LIMIT 1',
            $assistant_id ? [$session_id, $assistant_id] : [$session_id]
        );

        if (!$prepared) {
            return [];
        }

        $row = $wpdb->get_var($prepared);
        if (!$row) {
            return [];
        }

        $data = json_decode($row, true);
        if (!is_array($data)) {
            return [];
        }

        return self::normalize_history($data);
    }

    /** Wrapper para obtener un transient. */
    private static function get_transient_value($key) {
        if (function_exists('get_transient')) {
            $value = get_transient($key);
            return is_array($value) ? $value : [];
        }

        return self::$fallback_store[$key] ?? [];
    }

    /** Wrapper para guardar un transient. */
    private static function set_transient_value($key, array $value) {
        if (function_exists('set_transient')) {
            set_transient($key, $value, self::EXPIRATION);
            return;
        }

        self::$fallback_store[$key] = $value;
    }

    /** Wrapper para eliminar un transient. */
    private static function delete_transient_value($key) {
        if (function_exists('delete_transient')) {
            delete_transient($key);
            return;
        }

        unset(self::$fallback_store[$key]);
    }

    /**
     * Sanitiza el rol del mensaje.
     */
    private static function sanitize_role($role) {
        $role = is_string($role) ? strtolower($role) : '';

        if (function_exists('sanitize_key')) {
            $role = sanitize_key($role);
        } else {
            $role = preg_replace('/[^a-z0-9_\-]/', '', $role);
        }

        $allowed = ['user', 'assistant', 'system'];
        if (!in_array($role, $allowed, true)) {
            return '';
        }

        return $role;
    }

    /**
     * Sanitiza el contenido del mensaje.
     */
    private static function sanitize_content($content) {
        if (!is_string($content)) {
            return '';
        }

        if (function_exists('sanitize_textarea_field')) {
            return sanitize_textarea_field($content);
        }

        return trim(strip_tags($content));
    }

    /**
     * Alternativa a absint cuando WordPress no está disponible.
     */
    private static function absint($value) {
        if (function_exists('absint')) {
            return absint($value);
        }

        return abs((int) $value);
    }
}
