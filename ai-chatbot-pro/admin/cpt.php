<?php
/**
 * Registra el Custom Post Type para los Asistentes.
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('ABSPATH')) exit;

function aicp_register_assistant_cpt() {
    $labels = [
        'name'                  => _x('Asistentes de Chat', 'Post Type General Name', 'ai-chatbot-pro'),
        'singular_name'         => _x('Asistente', 'Post Type Singular Name', 'ai-chatbot-pro'),
        'menu_name'             => __('Asistentes de Chat', 'ai-chatbot-pro'),
        'all_items'             => __('Todos los Asistentes', 'ai-chatbot-pro'),
        'add_new_item'          => __('Añadir Nuevo Asistente', 'ai-chatbot-pro'),
        'add_new'               => __('Añadir Nuevo', 'ai-chatbot-pro'),
        'edit_item'             => __('Editar Asistente', 'ai-chatbot-pro'),
    ];
    $args = [
        'label'                 => __('Asistente', 'ai-chatbot-pro'),
        'description'           => __('Asistentes de chat personalizables', 'ai-chatbot-pro'),
        'labels'                => $labels,
        'supports'              => ['title'],
        'hierarchical'          => false,
        'public'                => false,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'menu_position'         => 80,
        'menu_icon'             => 'dashicons-format-chat',
        'show_in_admin_bar'     => false,
        'can_export'            => true,
        'exclude_from_search'   => true,
        'publicly_queryable'    => false,
        'capability_type'       => 'post',
        'show_in_rest'          => true,
    ];
    register_post_type('aicp_assistant', $args);
}
add_action('init', 'aicp_register_assistant_cpt', 0);
