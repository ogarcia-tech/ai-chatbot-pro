<?php
/**
 * Clase para manejar la instalación y actualización del plugin
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class AICP_Installer {
    
    /**
     * Instalar el plugin
     */
    public static function install() {
        // Crear o actualizar tablas
        self::create_or_update_tables();
        
        // Crear opciones por defecto
        self::create_default_options();
        
        // Actualizar versión de la base de datos
        if (version_compare(get_option('aicp_db_version', '0.0'), AICP_DB_VERSION, '<')) {
            update_option('aicp_db_version', AICP_DB_VERSION);
        }
        
        // Hook después de la instalación
        do_action('aicp_after_install');
    }
    
    /**
     * Crear o actualizar tablas de la base de datos
     */
    private static function create_or_update_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        
        // SQL para crear la tabla principal
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            assistant_id bigint(20) unsigned NOT NULL,
            session_id varchar(255) NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            first_user_message text,
            conversation_log longtext NOT NULL,
            feedback tinyint(1) DEFAULT NULL,
            has_lead tinyint(1) DEFAULT 0,
            lead_data longtext DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY assistant_id (assistant_id),
            KEY session_id (session_id),
            KEY timestamp (timestamp),
            KEY has_lead (has_lead)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Verificar y añadir campos que puedan faltar en instalaciones antiguas
        self::ensure_table_fields($table_name);

    }
    
    /**
     * Asegurar que todos los campos necesarios existen en la tabla
     */
    private static function ensure_table_fields($table_name) {
        global $wpdb;
        
        // Obtener columnas actuales
        $columns = $wpdb->get_col("DESC $table_name", 0);
        
        // Campos e índices requeridos según la tabla
        if (strpos($table_name, 'aicp_chat_logs') !== false) {
            $required_fields = [
                'has_lead' => 'TINYINT(1) DEFAULT 0',
                'lead_data' => 'LONGTEXT DEFAULT NULL',
                'ip_address' => 'VARCHAR(45) DEFAULT NULL',
                'user_agent' => 'TEXT DEFAULT NULL',
                'feedback' => 'TINYINT(1) DEFAULT NULL'
            ];

            $required_indexes = [
                'has_lead' => "ADD INDEX has_lead (has_lead)",
                'timestamp' => "ADD INDEX timestamp (timestamp)"
            ];
        } else {
            $required_fields = [];
            $required_indexes = [];
        }

        // Añadir campos faltantes
        foreach ($required_fields as $field => $definition) {
            if (!in_array($field, $columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD $field $definition");
            }
        }

        // Añadir índices si no existen
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name");
        $existing_indexes = array_column($indexes, 'Key_name');

        foreach ($required_indexes as $index_name => $index_sql) {
            if (!in_array($index_name, $existing_indexes)) {
                $wpdb->query("ALTER TABLE $table_name $index_sql");
            }
        }
    }
    
    /**
     * Crear opciones por defecto
     */
    private static function create_default_options() {
        // Configuración global por defecto
        $default_settings = [
            'api_key' => '',
            'rate_limit' => 30,
            'enable_logging' => true,
            'enable_feedback' => true
        ];
        
        if (!get_option('aicp_settings')) {
            add_option('aicp_settings', $default_settings);
        }
        
        // Versión de la base de datos
        if (!get_option('aicp_db_version')) {
            add_option('aicp_db_version', AICP_DB_VERSION);
        }
        
        // Fecha de instalación
        if (!get_option('aicp_install_date')) {
            add_option('aicp_install_date', current_time('mysql'));
        }
    }
    
    /**
     * Actualizar base de datos desde versión anterior
     */
    public static function update_database($old_version) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        
        // Migraciones específicas por versión
        if (version_compare($old_version, '1.5', '<')) {
            // Migración a versión 1.5 - añadir campos de leads
            self::migrate_to_1_5($table_name);
        }
        
        if (version_compare($old_version, '1.6', '<')) {
            // Migración a versión 1.6 - añadir campos de tracking
            self::migrate_to_1_6($table_name);
        }

        if (version_compare($old_version, '1.7', '<')) {
            // Migrar datos de la tabla antigua de leads
            self::migrate_leads_table($table_name);
        }
        
        // Asegurar que todos los campos están presentes
        self::ensure_table_fields($table_name);
        
        // Hook después de actualizar
        do_action('aicp_after_database_update', $old_version, AICP_DB_VERSION);
    }
    
    /**
     * Migración a versión 1.5
     */
    private static function migrate_to_1_5($table_name) {
        global $wpdb;
        
        $columns = $wpdb->get_col("DESC $table_name", 0);
        
        if (!in_array('has_lead', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD has_lead TINYINT(1) DEFAULT 0");
        }
        
        if (!in_array('lead_data', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD lead_data LONGTEXT DEFAULT NULL");
        }
        
        // Migrar datos existentes si es necesario
        self::migrate_existing_lead_data($table_name);
    }
    
    /**
     * Migración a versión 1.6
     */
    private static function migrate_to_1_6($table_name) {
        global $wpdb;
        
        $columns = $wpdb->get_col("DESC $table_name", 0);
        
        if (!in_array('ip_address', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD ip_address VARCHAR(45) DEFAULT NULL");
        }
        
        if (!in_array('user_agent', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD user_agent TEXT DEFAULT NULL");
        }
    }
    
    /**
     * Migrar datos de leads existentes
     */
    private static function migrate_existing_lead_data($table_name) {
        global $wpdb;
        
        // Obtener conversaciones que no han sido procesadas para leads
        $logs = $wpdb->get_results(
            "SELECT id, conversation_log FROM $table_name WHERE has_lead = 0 AND conversation_log IS NOT NULL"
        );
        
        foreach ($logs as $log) {
            $conversation = json_decode($log->conversation_log, true);
            if (!is_array($conversation)) {
                continue;
            }
            
            // Usar la clase Lead Manager si existe
            if (class_exists('AICP_Lead_Manager')) {
                $lead_info = AICP_Lead_Manager::detect_contact_data($conversation);
                
                if ($lead_info['has_lead']) {
                    $wpdb->update(
                        $table_name,
                        [
                            'has_lead' => 1,
                            'lead_data' => wp_json_encode($lead_info['data'], JSON_UNESCAPED_UNICODE)
                        ],
                        ['id' => $log->id],
                        ['%d', '%s'],
                        ['%d']
                    );
                }
            }
        }
    }

    /**
     * Migrar registros desde la tabla wp_aicp_leads al nuevo esquema
     */
    private static function migrate_leads_table($table_name) {
        global $wpdb;

        $leads_table = $wpdb->prefix . 'aicp_leads';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $leads_table));
        if ($exists !== $leads_table) {
            return;
        }

        $leads = $wpdb->get_results("SELECT * FROM $leads_table");
        foreach ($leads as $lead) {
            $lead_data = [];
            if ($lead->lead_data) {
                $decoded = json_decode($lead->lead_data, true);
                if (is_array($decoded)) {
                    $lead_data = $decoded;
                }
            }

            foreach (['name', 'email', 'phone', 'website'] as $field) {
                if (!empty($lead->$field) && !isset($lead_data[$field])) {
                    $lead_data[$field] = $lead->$field;
                }
            }

            $lead_status = $lead->status ?: 'partial';

            if ($lead->log_id > 0) {
                $wpdb->update(
                    $table_name,
                    [
                        'has_lead'   => 1,
                        'lead_data'  => wp_json_encode($lead_data, JSON_UNESCAPED_UNICODE),
                        'lead_status'=> $lead_status,
                    ],
                    ['id' => $lead->log_id],
                    ['%d','%s','%s'],
                    ['%d']
                );
            } else {
                $wpdb->insert(
                    $table_name,
                    [
                        'assistant_id' => $lead->assistant_id,
                        'session_id'   => 'legacy-lead-' . $lead->id,
                        'timestamp'    => $lead->created_at,
                        'has_lead'     => 1,
                        'lead_data'    => wp_json_encode($lead_data, JSON_UNESCAPED_UNICODE),
                        'lead_status'  => $lead_status,
                    ],
                    ['%d','%s','%s','%d','%s','%s']
                );
            }
        }

        $wpdb->query("DROP TABLE IF EXISTS $leads_table");
    }
    
    /**
     * Desinstalar el plugin (solo se ejecuta desde uninstall.php)
     */
    public static function uninstall() {
        global $wpdb;
        
        // Eliminar tablas personalizadas
        $logs_table  = $wpdb->prefix . 'aicp_chat_logs';
        $leads_table = $wpdb->prefix . 'aicp_leads';
        $wpdb->query("DROP TABLE IF EXISTS $logs_table");

        $wpdb->query("DROP TABLE IF EXISTS $leads_table");
        
        // Eliminar opciones
        delete_option('aicp_settings');
        delete_option('aicp_db_version');
        delete_option('aicp_install_date');
        
        // Eliminar metadatos de posts
        $wpdb->delete($wpdb->postmeta, ['meta_key' => '_aicp_assistant_settings']);
        
        // Eliminar posts de asistentes
        $assistants = get_posts([
            'post_type' => 'aicp_assistant',
            'numberposts' => -1,
            'post_status' => 'any'
        ]);
        
        foreach ($assistants as $assistant) {
            wp_delete_post($assistant->ID, true);
        }
        
        // Limpiar transients
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_aicp_%'");
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_aicp_%'");
        
        // Hook después de desinstalar
        do_action('aicp_after_uninstall');
    }
    
    /**
     * Verificar integridad de la base de datos
     */
    public static function check_database_integrity() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        
        // Verificar que la tabla existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            return false;
        }
        
        // Verificar campos requeridos
        $columns = $wpdb->get_col("DESC $table_name", 0);
        $required_columns = ['id', 'assistant_id', 'session_id', 'conversation_log', 'has_lead', 'lead_data'];
        
        foreach ($required_columns as $column) {
            if (!in_array($column, $columns)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Reparar base de datos si es necesario
     */
    public static function repair_database() {
        if (!self::check_database_integrity()) {
            self::create_or_update_tables();
            return true;
        }
        return false;
    }
}