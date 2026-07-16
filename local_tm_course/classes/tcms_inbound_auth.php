<?php
/**
 * Shared Bearer auth for TCMS → Moodle inbound API (same token as outbound sync).
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class tcms_inbound_auth {

    /**
     * @return string
     */
    public static function expected_token(): string {
        return trim((string) get_config('local_tm_course', 'tcms_sync_token'));
    }

    /**
     * @return bool
     */
    public static function verify_request(): bool {
        $expected = self::expected_token();
        if ($expected === '') {
            return false;
        }
        $auth = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } else if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } else if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $k => $v) {
                if (strcasecmp((string) $k, 'Authorization') === 0) {
                    $auth = (string) $v;
                    break;
                }
            }
        }
        if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) {
            return false;
        }
        return hash_equals($expected, trim($m[1]));
    }

    /**
     * Emit JSON and exit.
     *
     * @param mixed $data
     */
    public static function json_response($data, int $status = 200): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function require_auth(): void {
        if (!self::verify_request()) {
            self::json_response(['error' => 'unauthorized'], 401);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function read_json_body(): array {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
