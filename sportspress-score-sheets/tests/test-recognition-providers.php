<?php
/**
 * Standalone tests for the recognition providers.
 *
 * Usage: php test-recognition-providers.php
 *
 * No WordPress, no database, and no HTTP. We define ABSPATH and stub the small
 * WordPress surface the provider classes touch at construct / is_configured /
 * unconfigured-recognize time, then load the real provider files and assert
 * their shape. Network-dependent code paths (a configured recognize()) are out
 * of scope and never exercised.
 */

// Mock WordPress bootstrap constant guarded by every class under test.
define('ABSPATH', dirname(__FILE__) . '/');

// ── Minimal WordPress stubs ──────────────────────────────────────────────────
// Only what the providers reference before any HTTP call.

if (!function_exists('get_option')) {
    /** Return the supplied default: no options are set in this harness. */
    function get_option($name, $default = false) {
        return $default;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit($string) {
        return rtrim((string) $string, '/');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public $data;

        public function __construct($code = '', $message = '', $data = '') {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

// ── Load classes under test ──────────────────────────────────────────────────

$recognition_dir = dirname(__FILE__) . '/../includes/recognition';

require_once $recognition_dir . '/interface-recognition-provider.php';
require_once $recognition_dir . '/class-extraction-result.php';
require_once $recognition_dir . '/class-abstract-llm-provider.php';

// Optional LLM providers written by a sibling agent. Load only if present so a
// not-yet-created file degrades to a skipped provider instead of a fatal.
$optional_providers = array(
    'claude'   => $recognition_dir . '/class-claude-provider.php',
    'gemini'   => $recognition_dir . '/class-gemini-provider.php',
    'openai'   => $recognition_dir . '/class-openai-provider.php',
);
$provider_classes = array();
$class_map        = array(
    'claude' => 'SPSS_Claude_Provider',
    'gemini' => 'SPSS_Gemini_Provider',
    'openai' => 'SPSS_OpenAI_Provider',
);
foreach ($optional_providers as $id => $file) {
    if (is_readable($file)) {
        require_once $file;
        if (class_exists($class_map[$id])) {
            $provider_classes[$id] = $class_map[$id];
        } else {
            echo "! NOTE: {$file} loaded but {$class_map[$id]} not defined; skipping {$id}.\n";
        }
    } else {
        echo "! NOTE: {$file} not present yet; skipping {$id} (orchestrator will re-run).\n";
    }
}

// The self-hosted provider is what this agent owns; it must load.
require_once $recognition_dir . '/class-selfhosted-provider.php';

// ── Test helpers ─────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function assert_test($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        echo "✓ PASS: $message\n";
        $passed++;
    } else {
        echo "✗ FAIL: $message\n";
        $failed++;
    }
}

/**
 * Tiny subclass exposing the abstract base's protected static media_type()
 * helper so we can assert it without touching the network.
 */
class SPSS_Test_LLM_Probe extends SPSS_Abstract_LLM_Provider {
    public function get_id(): string {
        return 'probe';
    }
    public function get_label(): string {
        return 'Probe';
    }
    protected function default_model(): string {
        return 'probe-model';
    }
    protected function key_constant(): string {
        return '';
    }
    protected function endpoint_url(): string {
        return 'http://127.0.0.1/never-called';
    }
    protected function auth_headers(): array {
        return array();
    }
    protected function build_body(string $image_b64, string $media_type, array $context): array {
        return array();
    }
    protected function parse_response($decoded) {
        return new WP_Error('unused', 'unused');
    }
    public function probe_media_type($path) {
        return self::media_type($path);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== Testing recognition providers ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

// ── Per-provider identity + configuration + unconfigured recognize ───────────

foreach ($provider_classes as $id => $class) {
    /** @var SPSS_Recognition_Provider $p */
    $p = new $class();

    assert_test(
        is_string($p->get_id()) && $p->get_id() !== '',
        "$id: get_id() is a non-empty string"
    );
    assert_test(
        is_string($p->get_label()) && $p->get_label() !== '',
        "$id: get_label() is a non-empty string"
    );

    // With no API-key option set, key-based providers are unconfigured.
    assert_test(
        $p->is_configured() === false,
        "$id: is_configured() is false with no key set"
    );

    // recognize() on an unconfigured provider must short-circuit to a WP_Error,
    // never fatal and never reach the network.
    $result = $p->recognize('/nonexistent/path.jpg', array());
    assert_test(
        is_wp_error($result),
        "$id: recognize() on an unconfigured provider returns WP_Error (no fatal, no HTTP)"
    );
}

// ── Self-hosted provider ─────────────────────────────────────────────────────

$sh = new SPSS_SelfHosted_Provider();

assert_test(
    $sh->get_id() === 'selfhosted',
    'selfhosted: get_id() is "selfhosted"'
);
assert_test(
    is_string($sh->get_label()) && $sh->get_label() !== '',
    'selfhosted: get_label() is a non-empty string'
);
// With no endpoint option set, the self-hosted provider is unconfigured (it does
// not silently assume a loopback sidecar is running).
assert_test(
    $sh->is_configured() === false,
    'selfhosted: is_configured() is false with no endpoint set'
);
assert_test(
    is_wp_error( $sh->recognize( '/nonexistent/path.jpg', array() ) ),
    'selfhosted: recognize() with no endpoint returns WP_Error (no fatal, no HTTP)'
);

// ── Shared media_type() helper (no network, no real file) ────────────────────

$probe = new SPSS_Test_LLM_Probe();
assert_test(
    $probe->probe_media_type('/tmp/whatever.jpg') === 'image/jpeg',
    'abstract base media_type() returns image/jpeg for a .jpg path'
);

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
