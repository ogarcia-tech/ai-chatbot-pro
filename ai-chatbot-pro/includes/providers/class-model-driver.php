<?php
if (!defined('ABSPATH')) exit;

interface AICP_Model_Driver_Interface {
    public function send_message($prompt, $history, $settings);
}
