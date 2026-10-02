<?php
/**
 * Moodle PHPUnit coverage for TCMS endpoint helpers + sync date gate.
 *
 * @package    local_tm_course
 * @category   test
 */

namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/tcms_endpoint.php');
require_once($CFG->dirroot . '/local/tm_course/classes/tcms_sync_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/tcms_cors.php');

/**
 * @covers \local_tm_course\tcms_endpoint
 * @covers \local_tm_course\tcms_sync_manager
 * @covers \local_tm_course\tcms_cors
 */
class tcms_sync_test extends \advanced_testcase {

    public function test_normalize_base_url_and_paths(): void {
        $this->assertSame(
            'https://tcms.tm-robot.com',
            tcms_endpoint::normalize_base_url('')
        );
        $this->assertSame(
            'https://tcms.tm-robot.com',
            tcms_endpoint::normalize_base_url('https://tcms.tm-robot.com/Project/')
        );
        $this->assertSame(
            'https://tcms.tm-robot.com/api/integrations/moodle/sessions',
            tcms_endpoint::sessions_collection_url('https://tcms.tm-robot.com/')
        );
        $this->assertSame(
            'https://tcms.tm-robot.com/api/integrations/moodle/sessions/99',
            tcms_endpoint::session_item_url('https://tcms.tm-robot.com', 99)
        );
    }

    public function test_authorization_header_bearer(): void {
        $this->assertSame(
            'Authorization: Bearer abc123',
            tcms_endpoint::authorization_header('abc123')
        );
    }

    public function test_required_payload_keys(): void {
        $keys = tcms_endpoint::required_payload_keys();
        foreach ([
            'customerNames', 'customerCount', 'studentCount', 'studentsReached',
            'moodleSessionId', 'moodleCourseId', 'teachingLanguage', 'kpiArea', 'countForKpi',
        ] as $k) {
            $this->assertContains($k, $keys);
        }
    }

    public function test_build_payload_sends_teaching_language_codes(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $start = strtotime('2026-10-15 09:00:00');

        $zh = $this->insert_session((int) $course->id, session_manager::LANG_ZH_TW, $start, 'Morning ZH');
        $en = $this->insert_session((int) $course->id, session_manager::LANG_ENGLISH, $start + DAYSECS, 'Morning EN');

        $zhpayload = tcms_sync_manager::build_payload($zh);
        $enpayload = tcms_sync_manager::build_payload($en);

        $this->assertIsArray($zhpayload);
        $this->assertIsArray($enpayload);
        $this->assertSame('zh_tw', $zhpayload['teachingLanguage']);
        $this->assertSame('en', $enpayload['teachingLanguage']);
        $this->assertNotSame('繁體中文', $zhpayload['teachingLanguage']);
        $this->assertNotSame('English', $enpayload['teachingLanguage']);
        $this->assertSame((int) $course->id, $zhpayload['moodleCourseId']);
        $this->assertSame((int) $course->id, $enpayload['moodleCourseId']);
        $this->assertNotSame($zhpayload['moodleSessionId'], $enpayload['moodleSessionId']);
        $this->assertSame('moodle', $zhpayload['source']);
        $this->assertSame('onsite', $zhpayload['deliveryMode']);
        $this->assertSame('To Do', $zhpayload['status']);
        $this->assertSame('A-1', $zhpayload['kpiArea']);
        $this->assertTrue($zhpayload['countForKpi']);
        $this->assertSame(['Customized'], $zhpayload['courseTypes']);
        $this->assertSame('', $zhpayload['customerNames']);
        $this->assertSame(0, $zhpayload['customerCount']);
        $this->assertSame(0, $zhpayload['studentCount']);
        $this->assertSame(0, $zhpayload['studentsReached']);
        $this->assert_legacy_payload_keys($zhpayload);
        $this->assert_legacy_payload_keys($enpayload);
        $this->assert_hash_covers_teaching_language($zhpayload);
        $this->assert_hash_covers_teaching_language($enpayload);
        $this->assertNotSame($zhpayload['_hash'], $enpayload['_hash']);
    }

    public function test_language_change_updates_payload_without_new_session(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $start = strtotime('2026-10-16 09:00:00');
        $session = $this->insert_session((int) $course->id, session_manager::LANG_ZH_TW, $start, 'Language change');

        $before = tcms_sync_manager::build_payload($session);
        $this->assertSame('zh_tw', $before['teachingLanguage']);

        $DB->set_field('local_tm_course_sessions', 'teaching_language', session_manager::LANG_ENGLISH, ['id' => $session->id]);
        $reloaded = $DB->get_record('local_tm_course_sessions', ['id' => $session->id], '*', MUST_EXIST);
        $after = tcms_sync_manager::build_payload($reloaded);

        $this->assertSame((int) $session->id, $after['moodleSessionId']);
        $this->assertSame('en', $after['teachingLanguage']);
        $this->assertNotSame($before['_hash'], $after['_hash']);
        $this->assertSame($before['title'], $after['title']);
        $this->assertSame($before['startDate'], $after['startDate']);
        $this->assertSame($before['startTime'], $after['startTime']);
        $this->assertSame($before['deliveryMode'], $after['deliveryMode']);
        $this->assertSame($before['status'], $after['status']);
        $this->assertSame(1, $DB->count_records('local_tm_course_sessions', ['courseid' => $course->id]));
        $this->assert_hash_covers_teaching_language($after);
    }

    public function test_teaching_language_does_not_change_sync_skip_or_error(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $start = strtotime('2026-10-17 09:00:00');
        $session = $this->insert_session((int) $course->id, session_manager::LANG_ENGLISH, $start, 'Sync gates');
        $this->enable_course((int) $course->id);

        set_config('tcms_sync_enabled', 0, 'local_tm_course');
        tcms_sync_manager::push_session((int) $session->id);
        $row = $DB->get_record('local_tm_course_sessions', ['id' => $session->id], 'tcms_sync_status,tcms_sync_error', MUST_EXIST);
        $this->assertSame(tcms_sync_manager::SYNC_SKIPPED, $row->tcms_sync_status);

        set_config('tcms_sync_enabled', 1, 'local_tm_course');
        set_config('tcms_sync_token', '', 'local_tm_course');
        $DB->set_field('local_tm_course_sessions', 'starttime', 0, ['id' => $session->id]);
        $DB->set_field('local_tm_course_sessions', 'endtime', 0, ['id' => $session->id]);
        tcms_sync_manager::push_session((int) $session->id);
        $row = $DB->get_record('local_tm_course_sessions', ['id' => $session->id], 'tcms_sync_status,tcms_sync_error', MUST_EXIST);
        $this->assertSame(tcms_sync_manager::SYNC_ERROR, $row->tcms_sync_status);
        $this->assertSame('Invalid session payload', $row->tcms_sync_error);

        $DB->set_field('local_tm_course_sessions', 'starttime', $start, ['id' => $session->id]);
        $DB->set_field('local_tm_course_sessions', 'endtime', $start + (8 * HOURSECS), ['id' => $session->id]);
        tcms_sync_manager::push_session((int) $session->id);
        $row = $DB->get_record('local_tm_course_sessions', ['id' => $session->id], 'tcms_sync_status,tcms_sync_error', MUST_EXIST);
        $this->assertSame(tcms_sync_manager::SYNC_ERROR, $row->tcms_sync_status);
        $this->assertSame('TCMS API URL or token not configured', $row->tcms_sync_error);
        $this->assertSame(1, $DB->count_records('local_tm_course_sessions', ['courseid' => $course->id]));
        $this->assertSame(
            'https://tcms.tm-robot.com/api/integrations/moodle/sessions',
            tcms_endpoint::sessions_collection_url('https://tcms.tm-robot.com')
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assert_legacy_payload_keys(array $payload): void {
        $expected = [
            'source', 'moodleSessionId', 'moodleCourseId', 'title',
            'startDate', 'endDate', 'startTime', 'endTime', 'courseTypes',
            'location', 'moodleClassroomId', 'moodleClassroomName', 'deliveryMode',
            'teachingLanguage', 'status', 'kpiArea', 'countForKpi',
            'customerNames', 'customerCount', 'studentCount', 'studentsReached', '_hash',
        ];
        $actual = array_keys($payload);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assert_hash_covers_teaching_language(array $payload): void {
        $core = $payload;
        unset($core['_hash']);
        $this->assertSame(sha1(json_encode($core)), $payload['_hash']);
        $this->assertArrayNotHasKey('_hash', $core);
    }

    private function insert_session(int $courseid, string $language, int $start, string $name): \stdClass {
        global $DB;
        $now = time();
        $record = (object) [
            'courseid' => $courseid,
            'classroomid' => 0,
            'name' => $name,
            'location' => 'HQ',
            'teaching_language' => $language,
            'delivery_mode' => session_manager::DELIVERY_ONSITE,
            'starttime' => $start,
            'endtime' => $start + (8 * HOURSECS),
            'duration_hours' => 8,
            'num_desks' => 6,
            'persons_per_desk' => 3,
            'approval_mode' => session_manager::APPROVAL_MANUAL,
            'status' => session_manager::STATUS_OPEN,
            'auto_close_exempt' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => 0,
            'session_kind' => session_manager::SESSION_KIND_STANDARD,
        ];
        $id = $DB->insert_record('local_tm_course_sessions', $record);
        return $DB->get_record('local_tm_course_sessions', ['id' => $id], '*', MUST_EXIST);
    }

    private function enable_course(int $courseid): void {
        global $DB;
        $DB->insert_record('local_tm_enabled_courses', (object) [
            'courseid' => $courseid,
            'default_duration_hours' => 8,
            'default_duration_hours_onsite' => 8,
            'default_duration_hours_online' => 8,
            'allow_onsite' => 1,
            'allow_online' => 1,
            'timecreated' => time(),
        ]);
    }

    public function test_sync_from_date_threshold(): void {
        $this->resetAfterTest(true);
        set_config('tcms_sync_from_date', '2026-08-01', 'local_tm_course');

        $before = (object) ['starttime' => strtotime('2026-07-31 10:00:00')];
        $on = (object) ['starttime' => strtotime('2026-08-01 00:00:00')];
        $after = (object) ['starttime' => strtotime('2026-08-15 09:00:00')];

        $this->assertFalse(tcms_sync_manager::session_meets_sync_from_date($before));
        $this->assertTrue(tcms_sync_manager::session_meets_sync_from_date($on));
        $this->assertTrue(tcms_sync_manager::session_meets_sync_from_date($after));

        set_config('tcms_sync_from_date', '', 'local_tm_course');
        $this->assertTrue(tcms_sync_manager::session_meets_sync_from_date($before));
    }

    public function test_api_base_url_from_config(): void {
        $this->resetAfterTest(true);
        set_config('tcms_api_base_url', 'https://tcms.tm-robot.com/Project/', 'local_tm_course');
        $this->assertSame('https://tcms.tm-robot.com', tcms_sync_manager::api_base_url());
    }

    public function test_cors_allows_vm_origin(): void {
        $this->assertTrue(tcms_cors::is_allowed_origin('https://tcms.tm-robot.com'));
        $this->assertFalse(tcms_cors::is_allowed_origin('https://tcms-e49a5.web.app'));
    }
}
