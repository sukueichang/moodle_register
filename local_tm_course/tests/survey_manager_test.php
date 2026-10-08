<?php
/**
 * PHPUnit: course survey stage 1 (definitions, assignment, versions).
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/survey_manager.php');

/**
 * @covers \local_tm_course\survey_manager
 */
class survey_manager_test extends \advanced_testcase {

    public function test_two_courses_can_have_different_surveys(): void {
        $this->resetAfterTest(true);
        $a = $this->getDataGenerator()->create_course();
        $b = $this->getDataGenerator()->create_course();
        $surveya = survey_manager::create_survey('Course A survey', 2);
        $surveyb = survey_manager::create_survey('Course B survey', 2);
        survey_manager::assign_course($surveya, (int) $a->id);
        survey_manager::assign_course($surveyb, (int) $b->id);
        $this->assertSame($surveya, survey_manager::active_surveyid_for_course((int) $a->id));
        $this->assertSame($surveyb, survey_manager::active_surveyid_for_course((int) $b->id));
    }

    public function test_one_course_cannot_be_silently_reassigned(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Course X']);
        $first = survey_manager::create_survey('A Survey', 2);
        $second = survey_manager::create_survey('B Survey', 2);
        survey_manager::assign_course($first, (int) $course->id);
        try {
            survey_manager::assign_course($second, (int) $course->id);
            $this->fail('Expected course reassignment to be blocked');
        } catch (\moodle_exception $e) {
            $this->assertSame('survey_error_course_assigned', $e->errorcode);
        }
        $this->assertSame(1, $DB->count_records('local_tm_course_svcrs', ['courseid' => $course->id]));
        $this->assertSame($first, survey_manager::active_surveyid_for_course((int) $course->id));
        $this->assertSame([(int) $course->id], survey_manager::assigned_courseids($first));
        $this->assertSame([], survey_manager::assigned_courseids($second));

        // Failed set_course_assignments must not clear the original assignment.
        try {
            survey_manager::set_course_assignments($second, [(int) $course->id]);
            $this->fail('Expected set_course_assignments to be blocked');
        } catch (\moodle_exception $e) {
            $this->assertSame('survey_error_course_assigned', $e->errorcode);
        }
        $this->assertSame([(int) $course->id], survey_manager::assigned_courseids($first));
    }

    public function test_delete_survey_allowed_when_unused_and_clears_structure(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $surveyid = survey_manager::create_survey('Disposable', 2);
        survey_manager::save_structure($surveyid, [[
            'name' => 'S',
            'items' => [[
                'qtype' => survey_manager::TYPE_TEXT,
                'title' => 'Q1',
                'required' => 0,
            ]],
        ]], 2);
        survey_manager::assign_course($surveyid, (int) $course->id);
        $this->assertTrue(survey_manager::can_delete_survey($surveyid));

        survey_manager::delete_survey($surveyid);
        $this->assertFalse($DB->record_exists('local_tm_course_svdef', ['id' => $surveyid]));
        $this->assertSame(0, $DB->count_records('local_tm_course_svcrs', ['surveyid' => $surveyid]));
        $this->assertSame(0, $DB->count_records('local_tm_course_svver', ['surveyid' => $surveyid]));
        $this->assertSame(0, survey_manager::active_surveyid_for_course((int) $course->id));
    }

    public function test_delete_survey_blocked_when_pinned_or_has_response(): void {
        global $DB;
        $this->resetAfterTest(true);
        $surveyid = survey_manager::create_survey('In use', 2);
        $versionid = survey_manager::save_structure($surveyid, [[
            'name' => 'S',
            'items' => [[
                'qtype' => survey_manager::TYPE_SCALE,
                'title' => 'Rate',
                'required' => 1,
                'scalemin' => 'Lo',
                'scalemax' => 'Hi',
            ]],
        ]], 2);

        $DB->insert_record('local_tm_course_svpin', (object) [
            'sessionid' => 9001,
            'versionid' => $versionid,
            'opens_at' => time(),
            'timecreated' => time(),
        ]);
        $this->assertFalse(survey_manager::can_delete_survey($surveyid));
        try {
            survey_manager::delete_survey($surveyid);
            $this->fail('Expected delete to be blocked when pinned');
        } catch (\moodle_exception $e) {
            $this->assertSame('survey_error_cannot_delete', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('local_tm_course_svdef', ['id' => $surveyid]));

        $DB->delete_records('local_tm_course_svpin', ['versionid' => $versionid]);
        $DB->insert_record('local_tm_course_svresp', (object) [
            'enrolid' => 0,
            'versionid' => $versionid,
            'sessionid' => 9002,
            'userid' => 0,
            'email' => 'learner@example.com',
            'mapped' => 0,
            'timecreated' => time(),
        ]);
        $this->assertFalse(survey_manager::can_delete_survey($surveyid));
        try {
            survey_manager::delete_survey($surveyid);
            $this->fail('Expected delete to be blocked when responses exist');
        } catch (\moodle_exception $e) {
            $this->assertSame('survey_error_cannot_delete', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('local_tm_course_svdef', ['id' => $surveyid]));
    }

    public function test_copy_survey_copies_structure_not_courses_or_responses(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $surveyid = survey_manager::create_survey('Original Survey', 2);
        $versionid = survey_manager::save_structure($surveyid, [[
            'name' => 'Section One',
            'items' => [
                [
                    'qtype' => survey_manager::TYPE_SINGLE,
                    'title' => 'Pick',
                    'required' => 1,
                    'allowother' => 1,
                    'options' => [['label' => 'Yes'], ['label' => 'No']],
                ],
                [
                    'qtype' => survey_manager::TYPE_SCALE,
                    'title' => 'Rate',
                    'required' => 0,
                    'scalemin' => 'Low',
                    'scalemax' => 'High',
                ],
            ],
        ]], 2);
        survey_manager::assign_course($surveyid, (int) $course->id);

        $copyid = survey_manager::copy_survey($surveyid, 3);
        $copy = survey_manager::get_survey($copyid);
        $suffix = get_string('survey_copy_suffix', 'local_tm_course');
        $this->assertSame('Original Survey (' . $suffix . ')', $copy->name);
        $this->assertSame([], survey_manager::assigned_courseids($copyid));
        $this->assertSame([(int) $course->id], survey_manager::assigned_courseids($surveyid));

        $copystructure = survey_manager::get_version_structure(
            (int) survey_manager::current_version($copyid)->id
        );
        $this->assertSame('Section One', $copystructure[0]['name']);
        $this->assertCount(2, $copystructure[0]['items']);
        $this->assertSame('Pick', $copystructure[0]['items'][0]['title']);
        $this->assertSame(survey_manager::TYPE_SINGLE, $copystructure[0]['items'][0]['qtype']);
        $this->assertSame(1, (int) $copystructure[0]['items'][0]['allowother']);
        $this->assertSame('Low', $copystructure[0]['items'][1]['scalemin']);

        // Second copy gets a numbered suffix.
        $copy2 = survey_manager::get_survey(survey_manager::copy_survey($surveyid, 3));
        $this->assertSame('Original Survey (' . $suffix . ' 2)', $copy2->name);

        // Source version id must not become the copy's version.
        $this->assertNotSame(
            $versionid,
            (int) survey_manager::current_version($copyid)->id
        );
        $this->assertSame(0, $DB->count_records('local_tm_course_svtok', ['sessionid' => 0]));
    }

    public function test_question_types_roundtrip_and_enable_flag(): void {
        $this->resetAfterTest(true);
        $surveyid = survey_manager::create_survey('Mixed', 2);
        survey_manager::set_enabled($surveyid, false);
        $versionid = survey_manager::save_structure($surveyid, [[
            'name' => 'Block',
            'items' => [
                [
                    'qtype' => survey_manager::TYPE_SINGLE,
                    'title' => 'Pick one',
                    'help' => 'Choose',
                    'required' => 1,
                    'allowother' => 1,
                    'options' => [
                        ['label' => 'Yes'],
                        ['label' => 'No'],
                    ],
                ],
                [
                    'qtype' => survey_manager::TYPE_MULTI,
                    'title' => 'Pick many',
                    'required' => 0,
                    'allowother' => 1,
                    'options' => [
                        ['label' => 'Cost'],
                    ],
                ],
                [
                    'qtype' => survey_manager::TYPE_SCALE,
                    'title' => 'Score',
                    'required' => 1,
                    'scalemin' => 'Low',
                    'scalemax' => 'High',
                ],
                [
                    'qtype' => survey_manager::TYPE_TEXT,
                    'title' => 'Comment',
                    'required' => 0,
                ],
            ],
        ]], 2);

        $structure = survey_manager::get_version_structure($versionid);
        $this->assertCount(1, $structure);
        $this->assertSame('Block', $structure[0]['name']);
        $items = $structure[0]['items'];
        $this->assertCount(4, $items);
        $this->assertSame(survey_manager::TYPE_SINGLE, $items[0]['qtype']);
        $this->assertSame(1, $items[0]['allowother']);
        $this->assertCount(3, $items[0]['options']);
        $this->assertSame('Yes', $items[0]['options'][0]['label']);
        $this->assertSame(1, $items[0]['options'][2]['isother']);
        $this->assertSame(survey_manager::TYPE_MULTI, $items[1]['qtype']);
        $this->assertSame(1, $items[1]['allowother']);
        $this->assertSame(1, $items[1]['options'][1]['isother']);
        $this->assertSame('Low', $items[2]['scalemin']);
        $this->assertSame('High', $items[2]['scalemax']);
        $this->assertSame(survey_manager::TYPE_TEXT, $items[3]['qtype']);
        $this->assertSame([], $items[3]['options']);
        $this->assertSame(0, (int) survey_manager::get_survey($surveyid)->enabled);
        $this->assertFalse(survey_manager::is_version_frozen($versionid));

        survey_manager::set_enabled($surveyid, true);
        $reloaded = survey_manager::get_version_structure($versionid);
        $savedagain = survey_manager::save_structure($surveyid, $reloaded, 2);
        $this->assertSame($versionid, $savedagain);
        $again = survey_manager::get_version_structure($savedagain);
        $this->assertCount(2, $again[0]['items'][1]['options']);
        $this->assertSame(1, $again[0]['items'][1]['options'][1]['isother']);
    }

    public function test_unfrozen_save_updates_the_same_version(): void {
        global $DB;
        $this->resetAfterTest(true);
        $surveyid = survey_manager::create_survey('Draft', 2);
        $version = survey_manager::current_version($surveyid);
        survey_manager::save_structure($surveyid, [[
            'name' => 'S',
            'items' => [[
                'stablekey' => 'keepme',
                'qtype' => survey_manager::TYPE_TEXT,
                'title' => 'Before',
                'required' => 0,
            ]],
        ]], 2);
        $again = survey_manager::save_structure($surveyid, [[
            'name' => 'S',
            'items' => [[
                'stablekey' => 'keepme',
                'qtype' => survey_manager::TYPE_TEXT,
                'title' => 'After',
                'required' => 1,
            ]],
        ]], 2);
        $this->assertSame((int) $version->id, $again);
        $this->assertSame(1, $DB->count_records('local_tm_course_svver', ['surveyid' => $surveyid]));
        $structure = survey_manager::get_version_structure($again);
        $this->assertSame('After', $structure[0]['items'][0]['title']);
        $this->assertSame('keepme', $structure[0]['items'][0]['stablekey']);
    }

    public function test_frozen_version_is_not_rewritten_and_save_opens_a_new_version(): void {
        global $DB;
        $this->resetAfterTest(true);
        $surveyid = survey_manager::create_survey('Live', 2);
        $versionid = survey_manager::save_structure($surveyid, [[
            'name' => 'S',
            'items' => [[
                'stablekey' => 'q1',
                'qtype' => survey_manager::TYPE_SCALE,
                'title' => 'Original',
                'required' => 1,
                'scalemin' => 'No',
                'scalemax' => 'Yes',
            ]],
        ]], 2);
        $originalitemid = (int) $DB->get_field('local_tm_course_svitem', 'id', ['versionid' => $versionid, 'stablekey' => 'q1']);

        $DB->insert_record('local_tm_course_svresp', (object) [
            'enrolid' => 4242,
            'versionid' => $versionid,
            'sessionid' => 0,
            'userid' => 2,
            'timecreated' => time(),
        ]);
        $this->assertTrue(survey_manager::is_version_frozen($versionid));

        $newversion = survey_manager::save_structure($surveyid, [[
            'name' => 'S',
            'items' => [[
                'stablekey' => 'q1',
                'qtype' => survey_manager::TYPE_SCALE,
                'title' => 'Revised',
                'required' => 1,
                'scalemin' => 'No',
                'scalemax' => 'Yes',
            ]],
        ]], 2);

        $this->assertNotSame($versionid, $newversion);
        $this->assertSame(2, $DB->count_records('local_tm_course_svver', ['surveyid' => $surveyid]));
        $this->assertSame('Original', $DB->get_field('local_tm_course_svitem', 'title', ['id' => $originalitemid]));
        $this->assertSame($versionid, (int) $DB->get_field('local_tm_course_svitem', 'versionid', ['id' => $originalitemid]));
        $revised = survey_manager::get_version_structure($newversion);
        $this->assertSame('Revised', $revised[0]['items'][0]['title']);
        $this->assertSame('q1', $revised[0]['items'][0]['stablekey']);
        $this->assertNotSame($originalitemid, (int) $revised[0]['items'][0]['id']);
    }

    public function test_pin_and_starttime_audit_do_not_change_a_course_or_session_row(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Untouched course']);
        $before = $DB->count_records('local_tm_course_sessions');
        $start = time() - 3600;
        $sessionid = $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $course->id,
            'name' => 'Session stays',
            'starttime' => $start,
            'endtime' => time() + 3600,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $surveyid = survey_manager::create_survey('Pinned', 2);
        $versionid = survey_manager::save_structure($surveyid, [[
            'name' => '',
            'items' => [[
                'qtype' => survey_manager::TYPE_TEXT,
                'title' => 'Note',
            ]],
        ]], 2);
        survey_manager::assign_course($surveyid, (int) $course->id);
        $pinid = survey_manager::ensure_session_survey_pin((int) $sessionid);
        $this->assertGreaterThan(0, $pinid);
        $this->assertSame($pinid, survey_manager::ensure_session_survey_pin((int) $sessionid));
        $this->assertTrue(survey_manager::is_version_frozen($versionid));

        $newstart = time() + 86400;
        survey_manager::lock_pin_before_starttime_edit((int) $sessionid, $start, $newstart, 2);
        $pin = $DB->get_record('local_tm_course_svpin', ['sessionid' => $sessionid], '*', MUST_EXIST);
        $this->assertSame($start, (int) $pin->opens_at);
        $this->assertSame($versionid, (int) $pin->versionid);
        $this->assertSame(1, $DB->count_records('local_tm_course_svaud', ['sessionid' => $sessionid]));

        survey_manager::set_enabled($surveyid, false);
        $other = survey_manager::create_survey('Other', 2);
        survey_manager::assign_course($other, (int) $course->id);
        $pinagain = $DB->get_record('local_tm_course_svpin', ['id' => $pinid], '*', MUST_EXIST);
        $this->assertSame($versionid, (int) $pinagain->versionid);

        $this->assertSame('Untouched course', $DB->get_field('course', 'fullname', ['id' => $course->id]));
        $this->assertSame('Session stays', $DB->get_field('local_tm_course_sessions', 'name', ['id' => $sessionid]));
        $this->assertSame($before + 1, $DB->count_records('local_tm_course_sessions'));
        $this->assertNotFalse(class_exists(session_manager::class));
    }
}
