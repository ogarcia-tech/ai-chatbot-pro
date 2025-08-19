<?php
/**
 * Plugin Name:    AI Chatbot Pro
 * Plugin URI:    https://metricaweb.es/
 * Description:    Crea y gestiona asistentes de chat personalizables con la API de OpenAI mediante shortcodes.
 * Version:    5.1.0
 * Author:    Óscar García / CEO Metricaweb
 * Author URI:    https://metricaweb.es/
 * Co-developed by:    Su Asistente de IA de Confianza 😉
 * License:    GPLv2 or later
 * License URI:    https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:    ai-chatbot-pro
 * Domain Path:    /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes del plugin
define('AICP_VERSION', '5.1.0');
define('AICP_PLUGIN_FILE', __FILE__);
define('AICP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AICP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AICP_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('AICP_DB_VERSION', '1.7'); // Incrementado para nuevas funcionalidades
define('AICP_MIN_PHP_VERSION', '7.4');
define('AICP_MIN_WP_VERSION', '5.0');

/**
 * Verificar requisitos del sistema
 */
function aicp_check_requirements() {
    if (version_compare(PHP_VERSION, AICP_MIN_PHP_VERSION, '<')) {
        deactivate_plugins(AICP_PLUGIN_BASENAME);
        wp_die(
            sprintf(
                /* translators: %1$s: Required PHP version, %2$s: Current PHP version */
                __('AI Chatbot Pro requiere PHP %1$s o superior. Tu versión actual es %2$s.', 'ai-chatbot-pro'),
                AICP_MIN_PHP_VERSION,
                PHP_VERSION
            )
        );
    }

    if (version_compare(get_bloginfo('version'), AICP_MIN_WP_VERSION, '<')) {
        deactivate_plugins(AICP_PLUGIN_BASENAME);
        wp_die(
            sprintf(
                /* translators: %1$s: Required WordPress version, %2$s: Current WordPress version */
                __('AI Chatbot Pro requiere WordPress %1$s o superior. Tu versión actual es %2$s.', 'ai-chatbot-pro'),
                AICP_MIN_WP_VERSION,
                get_bloginfo('version')
            )
        );
    }
}

/**
 * Función que se ejecuta en la activación del plugin
 */
function aicp_activate() {
    aicp_check_requirements();
    
    require_once AICP_PLUGIN_DIR . 'includes/class-installer.php';
    AICP_Installer::install();
    
    // Limpiar rewrite rules
    flush_rewrite_rules();
    
    // Hook para después de la activación
    do_action('aicp_plugin_activated');
}
register_activation_hook(__FILE__, 'aicp_activate');

/**
 * Función que se ejecuta en la desactivación del plugin
 */
function aicp_deactivate() {
    // Limpiar rewrite rules
    flush_rewrite_rules();
    
    // Hook para después de la desactivación
    do_action('aicp_plugin_deactivated');
}
register_deactivation_hook(__FILE__, 'aicp_deactivate');

/**
 * Clase principal del plugin
 */
final class AI_Chatbot_Pro {
    
    /**
     * Instancia única del plugin
     */
    private static $instance = null;
    
    /**
     * Versión de la base de datos instalada
     */
    private $db_version;
    
    /**
     * Obtener instancia única del plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor privado para evitar instanciación directa
     */
    private function __construct() {
        $this->db_version = get_option('aicp_db_version', '0');
        
        add_action('plugins_loaded', [$this, 'init']);
        add_action('init', [$this, 'load_textdomain']);
        
        // Hook para actualizaciones de base de datos
        add_action('plugins_loaded', [$this, 'maybe_update_db']);
    }
    
    /**
     * Inicializar el plugin
     */
    public function init() {
        // Verificar requisitos en cada carga
        aicp_check_requirements();
        
        // Cargar dependencias
        $this->load_dependencies();
        
        // Inicializar hooks
        $this->init_hooks();
        
        // Hook para después de la inicialización
        do_action('aicp_plugin_loaded');
    }
    
    /**
     * Cargar dependencias del plugin
     */
    private function load_dependencies() {
        // Clases principales
        require_once AICP_PLUGIN_DIR . 'includes/class-installer.php';
        require_once AICP_PLUGIN_DIR . 'includes/class-shortcode-handler.php';
        require_once AICP_PLUGIN_DIR . 'includes/class-ajax-handler.php';
        require_once AICP_PLUGIN_DIR . 'includes/class-lead-manager.php'; // Nueva clase
        
        // Admin
        if (is_admin()) {
            require_once AICP_PLUGIN_DIR . 'admin/cpt.php';
            require_once AICP_PLUGIN_DIR . 'admin/settings-page.php';
            require_once AICP_PLUGIN_DIR . 'admin/analytics-page.php';
            require_once AICP_PLUGIN_DIR . 'admin/export-functions.php';
            require_once AICP_PLUGIN_DIR . 'admin/assistant-meta-boxes.php';
        }
        
        // Frontend
        if (!is_admin()) {
            require_once AICP_PLUGIN_DIR . 'includes/class-frontend-loader.php';
        }
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Inicializar clases
        AICP_Shortcode_Handler::init();
        AICP_Ajax_Handler::init();
        AICP_Lead_Manager::init(); // Nueva clase
        
        if (is_admin()) {
            AICP_Export_Functions::init();
        }
        
        if (!is_admin()) {
            AICP_Frontend_Loader::init();
        }
        
        // Hooks de seguridad
        add_action('wp_loaded', [$this, 'security_headers']);
    }
    
    /**
     * Cargar archivos de traducción
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'ai-chatbot-pro',
            false,
            dirname(AICP_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    /**
     * Verificar si necesita actualizar la base de datos
     */
    public function maybe_update_db() {
        if (version_compare($this->db_version, AICP_DB_VERSION, '<')) {
            require_once AICP_PLUGIN_DIR . 'includes/class-installer.php';
            AICP_Installer::update_database($this->db_version);
            update_option('aicp_db_version', AICP_DB_VERSION);
        }
    }
    
    /**
     * Añadir headers de seguridad
     */
    public function security_headers() {
        if (!headers_sent()) {
            // Solo para páginas del plugin
            if (is_admin() && isset($_GET['post_type']) && $_GET['post_type'] === 'aicp_assistant') {
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
            }
        }
    }
    
    /**
     * Obtener versión del plugin
     */
    public function get_version() {
        return AICP_VERSION;
    }
    
    /**
     * Obtener versión de la base de datos
     */
    public function get_db_version() {
        return $this->db_version;
    }
}

// Inicializar el plugin
function aicp_init() {
    return AI_Chatbot_Pro::get_instance();
}

// Iniciar cuando WordPress esté listo
aicp_init();

/**
 * Función helper para obtener la instancia del plugin
 */
function aicp() {
    return AI_Chatbot_Pro::get_instance();
}