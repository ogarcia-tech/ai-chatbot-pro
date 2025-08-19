<?php
/**
 * Fichero que se ejecuta al desinstalar el plugin.
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/class-installer.php';

AICP_Installer::uninstall();

