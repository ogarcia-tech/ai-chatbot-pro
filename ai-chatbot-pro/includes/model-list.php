<?php
// Prevent direct access
if (!defined('ABSPATH')) exit;

// Available models for AI Chatbot Pro
if (!defined('AICP_AVAILABLE_MODELS')) {
    define('AICP_AVAILABLE_MODELS', [
        'gpt-4o-mini' => 'GPT-4o mini',
        'gpt-4o'      => 'GPT-4o',
        'gpt-4.1'     => 'GPT-4.1',
        'gpt-4.1-mini'=> 'GPT-4.1 mini',
    ]);
}
