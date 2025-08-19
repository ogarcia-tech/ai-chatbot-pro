<?php
/**
 * Maneja las exportaciones a CSV.
 *
 * @package AI_Chatbot_Pro
 */
if (!defined('ABSPATH')) exit;

class AICP_Export_Functions {

    public static function init() {
        add_action('admin_init', [__CLASS__, 'handle_export_request']);
    }

    public static function handle_export_request() {
        if (!isset($_GET['aicp_export']) || !isset($_GET['assistant_id'])) return;
        if (!current_user_can('edit_posts')) wp_die(__('No tienes permisos.', 'ai-chatbot-pro'));
        
        $export_type = sanitize_key($_GET['aicp_export']);
        $assistant_id = absint($_GET['assistant_id']);
        check_admin_referer('aicp_export_nonce_' . $assistant_id);

        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        
        if ($export_type === 'leads') {
            $data = $wpdb->get_results($wpdb->prepare("SELECT lead_data, timestamp FROM $table_name WHERE assistant_id = %d AND has_lead = 1", $assistant_id), ARRAY_A);
            self::generate_csv($data, 'leads-' . $assistant_id);
        }

        if ($export_type === 'history') {
            $data = $wpdb->get_results($wpdb->prepare("SELECT conversation_log, timestamp FROM $table_name WHERE assistant_id = %d", $assistant_id), ARRAY_A);
            self::generate_csv($data, 'history-' . $assistant_id, true);
        }
    }

    private static function generate_csv($data, $filename_prefix, $is_history = false) {
        if (empty($data)) wp_die(__('No hay datos para exportar.', 'ai-chatbot-pro'));
        
        $filename = $filename_prefix . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=' . $filename);
        $output = fopen('php://output', 'w');

        if ($is_history) {
            fputcsv($output, ['Timestamp', 'Role', 'Message']);
            foreach ($data as $row) {
                $conversation = json_decode($row['conversation_log'], true);
                if (is_array($conversation)) {
                    foreach ($conversation as $message) {
                        if ($message['role'] === 'system') continue;
                        fputcsv($output, [$row['timestamp'], $message['role'], $message['content']]);
                    }
                }
            }
        } else { // Leads
            $field_map = [
                'name'    => 'NOMBRE',
                'email'   => 'EMAIL',
                'phone'   => 'TELEFONO',
                'website' => 'WEB',
            ];

            $additional_keys = [];
            foreach ($data as $row) {
                $lead_data = json_decode($row['lead_data'], true);
                if (is_array($lead_data)) {
                    $additional_keys = array_merge(
                        $additional_keys,
                        array_diff(array_keys($lead_data), array_keys($field_map))
                    );
                }
            }
            $additional_keys = array_unique($additional_keys);

            $headers = array_merge(['timestamp'], array_values($field_map), $additional_keys);
            fputcsv($output, $headers);

            foreach ($data as $row) {
                $lead_data = json_decode($row['lead_data'], true);
                $csv_row = [
                    $row['timestamp'],
                    $lead_data['name'] ?? '',
                    $lead_data['email'] ?? '',
                    $lead_data['phone'] ?? '',
                    $lead_data['website'] ?? '',
                ];

                foreach ($additional_keys as $key) {
                    $csv_row[] = $lead_data[$key] ?? '';
                }

                fputcsv($output, $csv_row);
            }
        }
        fclose($output);
        exit;
    }
}
