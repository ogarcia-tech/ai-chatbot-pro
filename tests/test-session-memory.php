<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('AICP_PLUGIN_DIR')) {
    define('AICP_PLUGIN_DIR', __DIR__ . '/../ai-chatbot-pro/');
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value) {
        return trim(strip_tags((string) $value));
    }
}

require_once AICP_PLUGIN_DIR . 'includes/class-session-memory.php';

$session = 'test-session';
$ip = '127.0.0.1';
$assistant_id = 7;

$history = [];
for ($i = 0; $i < 20; $i++) {
    $history[] = [
        'role'    => $i % 2 === 0 ? 'user' : 'assistant',
        'content' => 'mensaje-' . $i,
    ];
}

$stored = AICP_Session_Memory::persist($session, $ip, $history, $assistant_id);
assert(count($stored) === AICP_Session_Memory::MAX_MESSAGES);
assert($stored[0]['content'] === 'mensaje-5');

$loaded = AICP_Session_Memory::load($session, $ip, $assistant_id);
assert($loaded === $stored);

$updated = AICP_Session_Memory::append($session, $ip, [['role' => 'assistant', 'content' => 'nuevo']], $assistant_id);
assert(count($updated) === AICP_Session_Memory::MAX_MESSAGES);
assert(end($updated)['content'] === 'nuevo');
assert($updated[0]['content'] === 'mensaje-6');

AICP_Session_Memory::forget($session, $ip, $assistant_id);

class FakeWpdb {
    public $prefix = 'wp_';
    public $fixture = [];
    public $prepare_calls = 0;
    public $last_args = [];

    public function prepare($query, $args) {
        $this->prepare_calls++;
        $this->last_args = $args;
        return 'prepared-query';
    }

    public function get_var($query) {
        if ('prepared-query' !== $query) {
            return null;
        }
        return json_encode($this->fixture);
    }
}

global $wpdb;
$wpdb = new FakeWpdb();
$wpdb->fixture = [
    ['role' => 'system', 'content' => 'Config'],
    ['role' => 'user', 'content' => 'Hola <strong>mundo</strong>'],
    ['role' => 'assistant', 'content' => '¡Hola!']
];

$fallback = AICP_Session_Memory::load($session, $ip, $assistant_id);
assert(count($fallback) === 3);
assert($fallback[1]['content'] === 'Hola mundo');
assert($wpdb->prepare_calls === 1);

// La segunda llamada debe usar la memoria en caché, sin incrementar prepare_calls.
$cached = AICP_Session_Memory::load($session, $ip, $assistant_id);
assert($cached === $fallback);
assert($wpdb->prepare_calls === 1);

echo "Session memory tests passed\n";
