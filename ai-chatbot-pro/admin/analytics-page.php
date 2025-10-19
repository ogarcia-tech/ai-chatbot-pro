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
    $lead_stats = wp_parse_args($stats, [
        'total_leads'    => 0,
        'complete_leads' => 0,
        'partial_leads'  => 0,
        'calendar_leads' => 0,
        'button_leads'   => 0,
        'form_leads'     => 0,
        'failed_leads'   => 0,
    ]);
    $total_conversations = $assistant_id > 0
        ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $logs_table WHERE assistant_id = %d", $assistant_id))
        : (int) $wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $logs_table");

    $total_leads = $assistant_id > 0
        ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $logs_table WHERE has_lead = 1 AND assistant_id = %d", $assistant_id))
        : (int) $wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $logs_table WHERE has_lead = 1");
    $conversion_rate = $total_conversations > 0 ? round(($total_leads / $total_conversations) * 100, 2) : 0;

    $top_questions = $assistant_id > 0
        ? $wpdb->get_results($wpdb->prepare("SELECT first_user_message, COUNT(*) as count FROM $logs_table WHERE first_user_message != '' AND assistant_id = %d GROUP BY first_user_message ORDER BY count DESC LIMIT 5", $assistant_id))
        : $wpdb->get_results("SELECT first_user_message, COUNT(*) as count FROM $logs_table WHERE first_user_message != '' GROUP BY first_user_message ORDER BY count DESC LIMIT 5");

    $days_range = 14;
    $start_timestamp = strtotime('-' . ($days_range - 1) . ' days', current_time('timestamp'));
    $start_datetime = date('Y-m-d 00:00:00', $start_timestamp);

    $chart_where = 'timestamp >= %s';
    $chart_params = [$start_datetime];
    if ($assistant_id > 0) {
        $chart_where .= ' AND assistant_id = %d';
        $chart_params[] = $assistant_id;
    }

    $chart_query = $wpdb->prepare(
        "SELECT DATE(timestamp) as day,
                COUNT(DISTINCT session_id) as conversations,
                COUNT(DISTINCT CASE WHEN has_lead = 1 THEN session_id END) as leads
         FROM $logs_table
         WHERE $chart_where
         GROUP BY day
         ORDER BY day ASC",
        $chart_params
    );

    $chart_results = $wpdb->get_results($chart_query, ARRAY_A);
    $chart_map = [];
    foreach ($chart_results as $row) {
        $chart_map[$row['day']] = [
            'conversations' => (int) $row['conversations'],
            'leads' => (int) $row['leads'],
        ];
    }

    $chart_labels = [];
    $chart_conversations = [];
    $chart_leads = [];
    $chart_conversion_rates = [];

    for ($i = 0; $i < $days_range; $i++) {
        $date_timestamp = strtotime('+' . $i . ' days', $start_timestamp);
        $date = date('Y-m-d', $date_timestamp);
        $label = date_i18n('d M', $date_timestamp);
        $data_for_day = $chart_map[$date] ?? ['conversations' => 0, 'leads' => 0];
        $conversations = (int) $data_for_day['conversations'];
        $leads_count = (int) $data_for_day['leads'];

        $chart_labels[] = $label;
        $chart_conversations[] = $conversations;
        $chart_leads[] = $leads_count;
        $chart_conversion_rates[] = $conversations > 0 ? round(($leads_count / $conversations) * 100, 2) : 0;
    }

    $range_label = sprintf(__('Últimos %d días', 'ai-chatbot-pro'), $days_range);

    wp_enqueue_style('aicp-admin-styles', AICP_PLUGIN_URL . 'assets/css/admin.css', [], AICP_VERSION);
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', [], '4.4.4', true);
    wp_register_script('aicp-analytics-script', AICP_PLUGIN_URL . 'assets/js/analytics.js', ['chartjs'], AICP_VERSION, true);
    wp_localize_script('aicp-analytics-script', 'aicpAnalyticsData', [
        'labels' => $chart_labels,
        'conversations' => $chart_conversations,
        'leads' => $chart_leads,
        'conversionRates' => $chart_conversion_rates,
        'assistantId' => $assistant_id,
        'rangeLabel' => $range_label,
        'i18n' => [
            'chartTitle' => sprintf(__('Actividad del Chatbot (%s)', 'ai-chatbot-pro'), $range_label),
            'conversations' => __('Conversaciones', 'ai-chatbot-pro'),
            'leads' => __('Leads', 'ai-chatbot-pro'),
            'conversionRate' => __('Tasa de conversión (%)', 'ai-chatbot-pro'),
        ],
    ]);
    wp_enqueue_script('aicp-analytics-script');

    ?>
    <div class="wrap" id="aicp-analytics-page">
        <h1><?php _e('Analíticas del Chatbot', 'ai-chatbot-pro'); ?></h1>

        <div class="aicp-stats-boxes">
            <div class="aicp-stat-box"><h2><?php echo esc_html($total_conversations); ?></h2><p><?php _e('Conversaciones Totales', 'ai-chatbot-pro'); ?></p></div>
            <div class="aicp-stat-box"><h2><?php echo esc_html($total_leads); ?></h2><p><?php _e('Leads Capturados', 'ai-chatbot-pro'); ?></p></div>
            <div class="aicp-stat-box"><h2><?php echo esc_html($conversion_rate); ?>%</h2><p><?php _e('Tasa de Conversión', 'ai-chatbot-pro'); ?></p></div>
            <div class="aicp-stat-box"><h2><?php echo esc_html($lead_stats['complete_leads']); ?></h2><p><?php _e('Leads Completos', 'ai-chatbot-pro'); ?></p></div>
            <div class="aicp-stat-box"><h2><?php echo esc_html($lead_stats['partial_leads']); ?></h2><p><?php _e('Leads Incompletos', 'ai-chatbot-pro'); ?></p></div>
            <div class="aicp-stat-box"><h2><?php echo esc_html($lead_stats['calendar_leads']); ?></h2><p><?php _e('Reservas por Calendario', 'ai-chatbot-pro'); ?></p></div>
        </div>

        <div class="aicp-analytics-section">
            <h2><?php _e('Actividad Reciente', 'ai-chatbot-pro'); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(__('Resumen de conversaciones, leads y conversiones (%s).', 'ai-chatbot-pro'), $range_label)); ?></p>
            <div class="aicp-chart-wrapper">
                <canvas id="aicp-analytics-chart" height="280"></canvas>
            </div>
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
}
