<?php
/**
 * Pure TCMS API URL / auth helpers (no DB). Used by sync and unit tests.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class tcms_endpoint {

    /** Production VM TCMS (API root — never include /Project/). */
    public const DEFAULT_BASE_URL = 'https://tcms.tm-robot.com';

    public const SESSIONS_PATH = '/api/integrations/moodle/sessions';

    /**
     * Normalise admin-configured base URL: trim, strip trailing slash and /Project SPA path.
     */
    public static function normalize_base_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            $url = self::DEFAULT_BASE_URL;
        }
        $url = rtrim($url, '/');
        // Frontend route must not be part of the API base.
        if (preg_match('#/Project$#i', $url)) {
            $url = rtrim((string) preg_replace('#/Project$#i', '', $url), '/');
        }
        return $url;
    }

    public static function sessions_collection_url(string $base): string {
        return self::normalize_base_url($base) . self::SESSIONS_PATH;
    }

    public static function session_item_url(string $base, int $moodlesessionid): string {
        return self::sessions_collection_url($base) . '/' . $moodlesessionid;
    }

    public static function authorization_header(string $token): string {
        return 'Authorization: Bearer ' . $token;
    }

    /**
     * Payload keys Moodle must always send (VM may not persist all yet).
     *
     * @return string[]
     */
    public static function required_payload_keys(): array {
        return [
            'moodleSessionId',
            'moodleCourseId',
            'startDate',
            'endDate',
            'startTime',
            'endTime',
            'courseTypes',
            'location',
            'status',
            'kpiArea',
            'countForKpi',
            'customerNames',
            'customerCount',
            'studentCount',
            'studentsReached',
        ];
    }
}
