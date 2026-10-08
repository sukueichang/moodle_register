<?php
/**
 * PHPUnit: shared survey visualization helpers.
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/survey_viz.php');

/**
 * @covers \local_tm_course\survey_viz
 */
class survey_viz_test extends \advanced_testcase {

    public function test_word_tokens_drop_email_and_keep_cjk(): void {
        $this->resetAfterTest(true);
        $tokens = survey_viz::word_tokens([
            '課程很好 課程很好 contact me at learner@example.com',
            'The course was clear',
        ]);
        $texts = [];
        foreach ($tokens as $row) {
            $texts[] = $row['text'];
            $this->assertStringNotContainsString('@', $row['text']);
        }
        $this->assertNotContains('learner@example.com', $texts);
        $this->assertContains('課程', $texts);
        $this->assertContains('course', $texts);
    }

    public function test_html_includes_chart_payload_without_identity(): void {
        $this->resetAfterTest(true);
        $html = survey_viz::html([[
            'section' => 'S',
            'title' => 'Pick',
            'help' => 'Choose one',
            'qtype' => survey_manager::TYPE_SINGLE,
            'answered' => 2,
            'options' => [
                ['label' => 'Yes', 'count' => 2, 'pct' => 100],
                ['label' => 'No', 'count' => 0, 'pct' => 0],
            ],
            'texts' => [],
            'scale_counts' => [],
            'average' => null,
        ]]);
        $this->assertStringContainsString('data-series', $html);
        $this->assertStringContainsString('tm-sviz-mode', $html);
        $this->assertStringNotContainsString('@', $html);
    }
}
