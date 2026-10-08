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

    public function test_word_tokens_keep_each_answer_whole(): void {
        $this->resetAfterTest(true);
        $tokens = survey_viz::word_tokens([
            '我想要吃大麥克',
            "Hi I'm watlon",
            '我想要吃大麥克',
            '   ',
        ]);
        $map = [];
        foreach ($tokens as $row) {
            $map[$row['text']] = $row['count'];
        }
        $this->assertCount(2, $map);
        $this->assertSame(2, $map['我想要吃大麥克']);
        $this->assertSame(1, $map["Hi I'm watlon"]);
        $this->assertSame('我想要吃大麥克', $tokens[0]['text']);
        $this->assertArrayNotHasKey('我想', $map);
        $this->assertArrayNotHasKey('Hi', $map);
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
