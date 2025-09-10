<?php
/**
 * Migraciones del plugin.
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ejecuta la migración de asistentes a la versión 1.
 *
 * Crea la opción `aicp_migration_v1_done` para asegurar que la migración
 * solo se ejecute una vez.
 */
function aicp_run_migration_v1() {
    // Crear la opción si no existe
    if (false === get_option('aicp_migration_v1_done')) {
        add_option('aicp_migration_v1_done', 0);
    }

    // Si la migración ya se ha ejecutado, salir
    if (get_option('aicp_migration_v1_done')) {
        return;
    }

    // Obtener todos los asistentes existentes
    $assistants = get_posts([
        'post_type'   => 'aicp_assistant',
        'numberposts' => -1,
        'post_status' => 'any',
    ]);

    foreach ($assistants as $assistant) {
        // Aquí iría la lógica de migración específica para cada asistente
        // Actualmente no se requiere ninguna transformación concreta.
    }

    // Marcar la migración como completada
    update_option('aicp_migration_v1_done', 1);
}

// Ejecutar migración en el hook init
add_action('init', 'aicp_run_migration_v1');
