<?php
if (!defined('ABSPATH')) exit;

if (!interface_exists('AICP_Model_Driver_Interface')) {
    require_once __DIR__ . '/class-model-driver.php';
}

class AICP_Generic_Driver implements AICP_Model_Driver_Interface {
    public function send_message($prompt, $history, $settings) {
        $api_key  = $settings['api_key'] ?? '';
        $endpoint = $settings['endpoint'] ?? '';
        $model    = $settings['model'] ?? '';
        $temperature = isset($settings['temperature']) ? floatval($settings['temperature']) : null;
        $max_tokens  = isset($settings['max_tokens']) ? intval($settings['max_tokens']) : null;

        if ($endpoint === '' || $model === '') {
            return new WP_Error('aicp_generic_missing', __('El endpoint y modelo personalizados son obligatorios.', 'ai-chatbot-pro'));
        }

        $payload = [
            'model'    => $model,
            'prompt'   => $prompt,
            'messages' => $history,
        ];
        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }
        if ($max_tokens !== null && $max_tokens > 0) {
            $payload['max_tokens'] = $max_tokens;
        }

        $headers = ['Content-Type' => 'application/json'];
        if ($api_key !== '') {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $response = wp_remote_post($endpoint, [
            'timeout' => 60,
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['reply'])) {
            return wp_kses_post($body['reply']);
        }
        if (isset($body['choices'][0]['message']['content'])) {
            return wp_kses_post($body['choices'][0]['message']['content']);
        }

        return new WP_Error('aicp_generic_invalid', __('No se pudo interpretar la respuesta del proveedor.', 'ai-chatbot-pro'));
    }
}
