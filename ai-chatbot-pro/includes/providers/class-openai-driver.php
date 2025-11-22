<?php
if (!defined('ABSPATH')) exit;

if (!interface_exists('AICP_Model_Driver_Interface')) {
    require_once __DIR__ . '/class-model-driver.php';
}

class AICP_OpenAI_Driver implements AICP_Model_Driver_Interface {
    public function send_message($prompt, $history, $settings) {
        $api_key = $settings['api_key'] ?? '';
        if (empty($api_key)) {
            return new WP_Error('aicp_missing_api_key', __('Falta la API Key de OpenAI.', 'ai-chatbot-pro'));
        }

        $model = $settings['model'] ?? 'gpt-4o-mini';
        $url   = !empty($settings['base_url']) ? $settings['base_url'] : 'https://api.openai.com/v1/chat/completions';

        $messages = [];
        if (!empty($prompt)) {
            $messages[] = ['role' => 'system', 'content' => $prompt];
        }
        foreach ($history as $message) {
            if (!isset($message['role'], $message['content'])) continue;
            $messages[] = [
                'role'    => sanitize_text_field($message['role']),
                'content' => wp_kses_post($message['content']),
            ];
        }

        $payload = [
            'model'    => $model,
            'messages' => $messages,
        ];

        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['choices'][0]['message']['content'])) {
            return new WP_Error('aicp_openai_invalid', __('Respuesta inesperada del modelo OpenAI.', 'ai-chatbot-pro'));
        }

        return trim($body['choices'][0]['message']['content']);
    }
}
