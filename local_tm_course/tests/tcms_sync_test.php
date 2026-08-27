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
            'moodleSessionId', 'moodleCourseId', 'kpiArea', 'countForKpi',
        ] as $k) {
            $this->assertContains($k, $keys);
        }
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
