<?php
if (!defined('ABSPATH')) exit;

class AICP_Model_Router {
    public static function get_driver($provider) {
        switch ($provider) {
            case 'gemini':
                require_once AICP_PLUGIN_DIR . 'includes/providers/class-gemini-driver.php';
                return new AICP_Gemini_Driver();
            case 'custom':
                require_once AICP_PLUGIN_DIR . 'includes/providers/class-generic-driver.php';
                return new AICP_Generic_Driver();
            case 'openai':
            default:
                require_once AICP_PLUGIN_DIR . 'includes/providers/class-openai-driver.php';
                return new AICP_OpenAI_Driver();
        }
    }
}
