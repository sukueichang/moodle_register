<?php
/**
 * CORS helpers for TCMS browser-bridge APIs.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class tcms_cors {

    /**
     * Allow production VM TCMS + local dev origins.
     */
    public static function apply_tcms_browser_cors(): void {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
        if ($origin === '' || !self::is_allowed_origin($origin)) {
            return;
        }
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
        header('Access-Control-Max-Age: 86400');
        header('Vary: Origin');
    }

    public static function is_allowed_origin(string $origin): bool {
        $exact = [
            'https://tcms.tm-robot.com',
            'http://localhost',
            'http://127.0.0.1',
        ];
        if (in_array($origin, $exact, true)) {
            return true;
        }
        // localhost with port
        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
            return true;
        }
        return false;
    }

    public static function exit_if_preflight(): void {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
