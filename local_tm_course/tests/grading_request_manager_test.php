<?php
/**
 * Quiz finished-attempt selection. Assign grading is not covered here.
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/grading_request_manager.php');

/**
 * @covers \local_tm_course\grading_request_manager
 */
class grading_request_manager_test extends \advanced_testcase {

    /**
     * @param int $id
     * @param int $attempt
     * @param string $state
     * @param mixed $sumgrades
     * @return \stdClass
     */
    private function attempt(int $id, int $attempt, string $state, $sumgrades): \stdClass {
        return (object) [
            'id' => $id,
            'attempt' => $attempt,
            'state' => $state,
            'sumgrades' => $sumgrades,
            'timefinish' => 1000 + $attempt,
        ];
    }

    public function test_latest_finished_null_sumgrades_stays_pending(): void {
        $picked = grading_request_manager::select_finished_quiz_attempt([
            $this->attempt(1, 1, 'finished', 45),
            $this->attempt(2, 2, 'finished', null),
        ]);
        $this->assertSame(2, (int) $picked->id);
        $this->assertFalse(grading_request_manager::quiz_sumgrades_present($picked->sumgrades));
    }

    public function test_both_finished_null_uses_highest_attempt(): void {
        $picked = grading_request_manager::select_finished_quiz_attempt([
            $this->attempt(1, 1, 'finished', null),
            $this->attempt(8, 8, 'finished', null),
        ]);
        $this->assertSame(8, (int) $picked->attempt);
        $this->assertFalse(grading_request_manager::quiz_sumgrades_present($picked->sumgrades));
    }

    public function test_highest_finished_with_sumgrades_is_used(): void {
        $picked = grading_request_manager::select_finished_quiz_attempt([
            $this->attempt(1, 1, 'finished', 10),
            $this->attempt(3, 3, 'finished', 20),
        ]);
        $this->assertSame(3, (int) $picked->id);
        $this->assertSame(20, $picked->sumgrades);
    }

    public function test_unfinished_attempts_are_ignored(): void {
        $picked = grading_request_manager::select_finished_quiz_attempt([
            $this->attempt(1, 1, 'finished', 50),
            $this->attempt(4, 4, 'inprogress', null),
            $this->attempt(5, 5, 'overdue', null),
            $this->attempt(6, 6, 'abandoned', 80),
        ]);
        $this->assertSame(1, (int) $picked->id);
        $this->assertSame(50, $picked->sumgrades);
    }

    public function test_quiz_grade_path_does_not_use_gradebook_or_manual_api(): void {
        $src = file_get_contents(dirname(__DIR__) . '/classes/grading_request_manager.php');
        $start = strpos($src, 'function quiz_latest_attempt_grade');
        $end = strpos($src, 'function gradebook_grade');
        $this->assertNotFalse($start);
        $this->assertGreaterThan($start, $end);
        $quiz = substr($src, $start, $end - $start);
        $this->assertStringNotContainsString('requires_manual_grading', $quiz);
        $this->assertStringNotContainsString('get_sum_marks', $quiz);
        $this->assertStringNotContainsString('gradebook_grade', $quiz);
        $this->assertStringContainsString('gradebook_grade(', substr($src, strpos($src, "modname'] === 'assign'")));
    }
}
