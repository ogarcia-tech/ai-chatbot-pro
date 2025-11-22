<?php
if (!defined('ABSPATH')) exit;

if (!interface_exists('AICP_Model_Driver_Interface')) {
    require_once __DIR__ . '/class-model-driver.php';
}

class AICP_Gemini_Driver implements AICP_Model_Driver_Interface {
    public function send_message($prompt, $history, $settings) {
        $api_key = $settings['api_key'] ?? '';
        $model   = $settings['model'] ?? '';
        $endpoint = $settings['endpoint'] ?? '';

        if ($api_key === '' || $model === '' || $endpoint === '') {
            return new WP_Error('aicp_missing_gemini_config', __('Configura la API Key, modelo y endpoint de Gemini.', 'ai-chatbot-pro'));
        }

        $messages = [];
        if (!empty($prompt)) {
            $messages[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];
        }
        foreach ($history as $message) {
            if (!isset($message['role'], $message['content'])) continue;
            $messages[] = [
                'role'  => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => wp_kses_post($message['content'])]],
            ];
        }

        $payload = [
            'contents' => $messages,
        ];

        $url = trailingslashit($endpoint) . 'models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($api_key);
        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
            return trim($body['candidates'][0]['content']['parts'][0]['text']);
        }

        return new WP_Error('aicp_gemini_invalid', __('Respuesta inesperada del modelo Gemini.', 'ai-chatbot-pro'));
    }
}
