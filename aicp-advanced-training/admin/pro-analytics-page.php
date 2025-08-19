<?php
/**
 * Crea la página de Dashboard Avanzado para la versión PRO.
 *
 * @package AI_Chatbot_Pro_Pack
 */
if (!defined('ABSPATH')) exit;

class AICP_Pro_Analytics_Page {
    
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_pro_analytics_page'], 20); // Prioridad 20 para que aparezca después
    }

    public static function add_pro_analytics_page() {
        add_submenu_page(
            'edit.php?post_type=aicp_assistant',
            __('Dashboard PRO', 'aicp-pro'),
            __('Dashboard PRO', 'aicp-pro'),
            'manage_options',
            'aicp-pro-dashboard',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        global $wpdb;
        $logs_table = $wpdb->prefix . 'aicp_chat_logs';

        // Datos para las gráficas (ejemplo de los últimos 30 días)
        $leads_by_day = $wpdb->get_results("SELECT DATE(timestamp) as day, COUNT(*) as count FROM $logs_table WHERE has_lead = 1 AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY day ORDER BY day ASC");
        
        $labels = [];
        $data = [];
        foreach($leads_by_day as $row) {
            $labels[] = date('d M', strtotime($row->day));
            $data[] = $row->count;
        }
        ?>
        <div class="wrap" id="aicp-pro-dashboard">
            <h1><?php _e('Dashboard de Rendimiento (PRO)', 'aicp-pro'); ?></h1>
            <div class="aicp-chart-container">
                <h2><?php _e('Leads Capturados (Últimos 30 días)', 'aicp-pro'); ?></h2>
                <canvas id="aicp-leads-chart"></canvas>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const ctx = document.getElementById('aicp-leads-chart');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($labels); ?>,
                        datasets: [{
                            label: 'Leads por Día',
                            data: <?php echo json_encode($data); ?>,
                            fill: false,
                            borderColor: 'rgb(75, 192, 192)',
                            tension: 0.1
                        }]
                    },
                    options: {
                        scales: { y: { beginAtZero: true } }
                    }
                });
            });
        </script>
        <?php
    }
}
AICP_Pro_Analytics_Page::init();
