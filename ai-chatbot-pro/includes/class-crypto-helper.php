<?php
if (!defined('ABSPATH')) exit;

class AICP_Crypto_Helper {
    protected static function get_key() {
        $secret = wp_salt('auth');
        return hash('sha256', $secret, true);
    }

    protected static function get_iv() {
        return substr(hash('sha256', wp_salt('secure_auth')), 0, 16);
    }

    public static function encrypt($value) {
        if ($value === '') return '';
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', self::get_key(), 0, self::get_iv());
        return base64_encode($encrypted);
    }

    public static function decrypt($value) {
        if ($value === '') return '';
        $decoded = base64_decode($value, true);
        if ($decoded === false) return '';
        $decrypted = openssl_decrypt($decoded, 'AES-256-CBC', self::get_key(), 0, self::get_iv());
        return $decrypted === false ? '' : $decrypted;
    }
}
