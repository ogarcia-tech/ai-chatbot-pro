<?php
/**
 * Crea la página de analíticas del plugin.
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('ABSPATH')) exit;

function aicp_add_analytics_page() {
    add_submenu_page(
        'edit.php?post_type=aicp_assistant',
        __('Analíticas', 'ai-chatbot-pro'),
        __('Analíticas', 'ai-chatbot-pro'),
        'manage_options',
        'aicp-analytics',
        'aicp_render_analytics_page'
    );
}
add_action('admin_menu', 'aicp_add_analytics_page');

function aicp_render_analytics_page() {
    global $wpdb;
    $logs_table = $wpdb->prefix . 'aicp_chat_logs';

    $assistant_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
    $stats = class_exists('AICP_Lead_Manager') ? AICP_Lead_Manager::get_lead_stats($assistant_id) : [];
    $total_conversations = $wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $logs_table");
    $total_leads = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table WHERE has_lead = 1");
    $conversion_rate = $total_conversations > 0 ? round(($total_leads / $total_conversations) * 100, 2) : 0;
    $top_questions = $wpdb->get_results("SELECT first_user_message, COUNT(*) as count FROM $logs_table WHERE first_user_message != '' GROUP BY first_user_message ORDER BY count DESC LIMIT 5");

    ?>
    <div class="wrap" id="aicp-analytics-page">
        <h1><?php _e('Analíticas del Chatbot', 'ai-chatbot-pro'); ?></h1>
        
        <div class="aicp-stats-boxes">
            <div class="aicp-stat-box"><h2><?php echo esc_html($total_conversations); ?></h2><p><?php _e('Conversaciones Totales', 'ai-chatbot-pro'); ?></p></div>
            <div class="aicp-stat-box"><h2><?php echo esc_html($total_leads); ?></h2><p><?php _e('Leads Capturados', 'ai-chatbot-pro'); ?></p></div>
            <div class="aicp-stat-box"><h2><?php echo esc_html($conversion_rate); ?>%</h2><p><?php _e('Tasa de Conversión', 'ai-chatbot-pro'); ?></p></div>
        </div>

        <div class="aicp-analytics-section">
            <h2><?php _e('Preguntas Más Frecuentes', 'ai-chatbot-pro'); ?></h2>
            <p class="description"><?php _e('Las primeras preguntas que los usuarios hacen al iniciar una conversación.', 'ai-chatbot-pro'); ?></p>
            <?php if (!empty($top_questions)): ?>
                <table class="wp-list-table widefat striped">
                    <thead><tr><th><?php _e('Pregunta', 'ai-chatbot-pro'); ?></th><th style="width: 150px;"><?php _e('Nº de Veces', 'ai-chatbot-pro'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($top_questions as $question): ?>
                            <tr><td><?php echo esc_html($question->first_user_message); ?></td><td><?php echo esc_html($question->count); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php _e('No hay datos suficientes.', 'ai-chatbot-pro'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php
echo '<div class="aicp-stat-box"><h2>' . esc_html($stats['calendar_leads']) . '</h2><p>' . __('Leads por Calendario', 'ai-chatbot-pro') . '</p></div>';
   
}
