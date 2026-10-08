<?php
/**
 * Shared survey result cards: choice pie/bar, scale bars, text cloud/list.
 *
 * Charts are drawn by amd-free local JS (survey_viz.js) from data on these cards.
 * No CDN.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class survey_viz {

    /**
     * @param array $questions question_stats rows (or session snapshot questions)
     */
    public static function html(array $questions): string {
        if (!$questions) {
            return html_writer::div(get_string('survey_viz_empty', 'local_tm_course'), 'tm-sviz-empty');
        }
        $sections = [];
        $index = [];
        foreach ($questions as $q) {
            $name = (string) ($q['section'] ?? '');
            if (!isset($index[$name])) {
                $index[$name] = count($sections);
                $sections[] = ['name' => $name, 'questions' => []];
            }
            $sections[$index[$name]]['questions'][] = $q;
        }
        return self::html_sections($sections);
    }

    /**
     * @param array $sections [{name:string, questions:array}]
     */
    public static function html_sections(array $sections): string {
        if (!$sections) {
            return html_writer::div(get_string('survey_viz_empty', 'local_tm_course'), 'tm-sviz-empty');
        }
        $html = html_writer::start_div('tm-sviz');
        $qnum = 0;
        foreach ($sections as $sidx => $section) {
            $html .= html_writer::start_div('tm-sviz-section');
            $name = trim((string) ($section['name'] ?? ''));
            if ($name !== '') {
                $html .= html_writer::tag('h3',
                    html_writer::tag('span', sprintf('%02d', $sidx + 1), ['class' => 'tm-survey-section-num'])
                    . ' ' . s($name),
                    ['class' => 'tm-sviz-section-title']
                );
            }
            foreach ($section['questions'] ?? [] as $q) {
                $qnum++;
                $html .= self::card($qnum, $q);
            }
            $html .= html_writer::end_div();
        }
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * @param array $q
     */
    private static function card(int $qnum, array $q): string {
        $qtype = (string) ($q['qtype'] ?? '');
        $typelabel = self::type_label($qtype);
        $html = html_writer::start_div('tm-sviz-card', ['data-qtype' => $qtype]);
        $html .= html_writer::start_div('tm-sviz-head');
        $html .= html_writer::tag('span', get_string('survey_question_n', 'local_tm_course', $qnum), [
            'class' => 'tm-survey-qnum',
        ]);
        $html .= html_writer::start_div('tm-sviz-head-text');
        $html .= html_writer::tag('div', s((string) ($q['title'] ?? '')), ['class' => 'tm-sviz-title']);
        $help = trim((string) ($q['help'] ?? ''));
        if ($help !== '') {
            $html .= html_writer::tag('div', s($help), ['class' => 'tm-sviz-help']);
        }
        $html .= html_writer::tag('div',
            html_writer::tag('span', s($typelabel), ['class' => 'tm-survey-chip'])
            . ' '
            . html_writer::tag('span', get_string('survey_viz_answered', 'local_tm_course', (int) ($q['answered'] ?? 0)), [
                'class' => 'tm-survey-chip tm-survey-chip-opt',
            ]),
            ['class' => 'tm-sviz-chips']
        );
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();

        $answered = (int) ($q['answered'] ?? 0);
        if ($qtype === survey_manager::TYPE_SCALE) {
            $avg = $q['average'];
            $html .= html_writer::tag('div',
                get_string('survey_viz_average', 'local_tm_course', $avg !== null ? $avg : '—'),
                ['class' => 'tm-sviz-average']
            );
            $series = [];
            $counts = $q['scale_counts'] ?? [];
            for ($n = 1; $n <= 5; $n++) {
                $cnt = (int) ($counts[$n] ?? 0);
                $pct = $answered > 0 ? round($cnt / $answered * 100, 1) : 0;
                $series[] = ['label' => (string) $n, 'count' => $cnt, 'pct' => $pct];
            }
            $html .= self::chart_block('bar', $series, false);
            $html .= self::legend($series);
        } else if ($qtype === survey_manager::TYPE_SINGLE || $qtype === survey_manager::TYPE_MULTI) {
            $series = [];
            foreach ($q['options'] ?? [] as $opt) {
                $series[] = [
                    'label' => (string) ($opt['label'] ?? ''),
                    'count' => (int) ($opt['count'] ?? 0),
                    'pct' => (float) ($opt['pct'] ?? 0),
                ];
            }
            $default = $qtype === survey_manager::TYPE_MULTI ? 'bar' : 'pie';
            $html .= self::mode_switch($default, false);
            $html .= self::chart_block($default, $series, true);
            $html .= self::legend($series);
            if ($qtype === survey_manager::TYPE_MULTI) {
                $html .= html_writer::tag('p', get_string('survey_live_multi_note', 'local_tm_course'), [
                    'class' => 'tm-sviz-note',
                ]);
            }
        } else if ($qtype === survey_manager::TYPE_TEXT) {
            $texts = array_values(array_map('strval', $q['texts'] ?? []));
            $tokens = self::word_tokens($texts);
            $html .= self::mode_switch('cloud', true);
            $payload = json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            $html .= html_writer::div('', 'tm-sviz-chart tm-sviz-cloud is-active', [
                'data-tokens' => $payload,
                'data-mode' => 'cloud',
            ]);
            $listhtml = '';
            if (!$texts) {
                $listhtml = html_writer::div(get_string('survey_viz_nodata', 'local_tm_course'), 'text-muted');
            }
            foreach ($texts as $text) {
                $listhtml .= html_writer::div(s($text), 'tm-sviz-text');
            }
            $html .= html_writer::div($listhtml, 'tm-sviz-list', ['data-mode' => 'list', 'hidden' => 'hidden']);
        }
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * @param array<int,array{label:string,count:int,pct:float}> $series
     */
    private static function chart_block(string $mode, array $series, bool $switchable): string {
        $payload = json_encode($series, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        return html_writer::div('', 'tm-sviz-chart', [
            'data-series' => $payload,
            'data-mode' => $mode,
            'data-switchable' => $switchable ? '1' : '0',
        ]);
    }

    private static function mode_switch(string $active, bool $text): string {
        if ($text) {
            $modes = [
                'cloud' => get_string('survey_viz_cloud', 'local_tm_course'),
                'list' => get_string('survey_viz_list', 'local_tm_course'),
            ];
        } else {
            $modes = [
                'pie' => get_string('survey_viz_pie', 'local_tm_course'),
                'bar' => get_string('survey_viz_bar', 'local_tm_course'),
            ];
        }
        $html = html_writer::start_div('tm-sviz-switch', ['role' => 'group']);
        foreach ($modes as $mode => $label) {
            $class = 'tm-sviz-mode' . ($mode === $active ? ' is-active' : '');
            $html .= html_writer::tag('button', $label, [
                'type' => 'button',
                'class' => $class,
                'data-mode' => $mode,
            ]);
        }
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * @param array<int,array{label:string,count:int,pct:float}> $series
     */
    private static function legend(array $series): string {
        if (!$series) {
            return html_writer::div(get_string('survey_viz_nodata', 'local_tm_course'), 'text-muted');
        }
        $html = html_writer::start_tag('ul', ['class' => 'tm-sviz-legend']);
        foreach ($series as $i => $row) {
            $html .= html_writer::tag('li',
                html_writer::tag('span', '', ['class' => 'tm-sviz-swatch', 'data-i' => $i])
                . s($row['label'])
                . html_writer::tag('span', (int) $row['count'] . ' (' . s((string) $row['pct']) . '%)', [
                    'class' => 'tm-sviz-legend-num',
                ])
            );
        }
        $html .= html_writer::end_tag('ul');
        return $html;
    }

    private static function type_label(string $qtype): string {
        if ($qtype === survey_manager::TYPE_SINGLE) {
            return get_string('survey_type_single', 'local_tm_course');
        }
        if ($qtype === survey_manager::TYPE_MULTI) {
            return get_string('survey_type_multi', 'local_tm_course');
        }
        if ($qtype === survey_manager::TYPE_SCALE) {
            return get_string('survey_type_scale', 'local_tm_course');
        }
        if ($qtype === survey_manager::TYPE_TEXT) {
            return get_string('survey_type_text', 'local_tm_course');
        }
        return $qtype;
    }

    /**
     * Lightweight tokens for a word cloud. Latin words plus CJK runs and 2-char grams.
     * Drops emails and a small stopword list. Safe to extend later.
     *
     * @param string[] $texts
     * @return array<int,array{text:string,count:int}>
     */
    public static function word_tokens(array $texts): array {
        $stop = [
            'a' => 1, 'an' => 1, 'the' => 1, 'of' => 1, 'to' => 1, 'and' => 1, 'or' => 1,
            'for' => 1, 'in' => 1, 'on' => 1, 'is' => 1, 'it' => 1, 'this' => 1, 'that' => 1,
            '的' => 1, '了' => 1, '是' => 1, '在' => 1, '我' => 1, '有' => 1, '和' => 1,
            '就' => 1, '不' => 1, '也' => 1, '與' => 1, '及' => 1, '很' => 1, '都' => 1,
        ];
        $counts = [];
        foreach ($texts as $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            if (preg_match_all('/[A-Za-z][A-Za-z0-9_\-]{1,}/u', $text, $latin)) {
                foreach ($latin[0] as $word) {
                    self::add_token($counts, $stop, strtolower($word));
                }
            }
            if (preg_match_all('/[\x{4e00}-\x{9fff}]{2,12}/u', $text, $cjk)) {
                foreach ($cjk[0] as $run) {
                    $chars = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY);
                    $len = count($chars);
                    if ($len >= 2 && $len <= 6) {
                        self::add_token($counts, $stop, $run);
                    }
                    for ($i = 0; $i < $len - 1; $i++) {
                        self::add_token($counts, $stop, $chars[$i] . $chars[$i + 1]);
                    }
                }
            }
        }
        arsort($counts);
        $out = [];
        foreach ($counts as $token => $count) {
            $out[] = ['text' => (string) $token, 'count' => (int) $count];
            if (count($out) >= 40) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array<string,int> $counts
     * @param array<string,int> $stop
     */
    private static function add_token(array &$counts, array $stop, string $token): void {
        $token = trim($token);
        if ($token === '' || isset($stop[$token])) {
            return;
        }
        if (strpos($token, '@') !== false) {
            return;
        }
        if (!isset($counts[$token])) {
            $counts[$token] = 0;
        }
        $counts[$token]++;
    }

    public static function require_assets(): void {
        global $PAGE;
        $PAGE->requires->js('/local/tm_course/survey_viz.js');
        $PAGE->requires->css('/local/tm_course/styles.css');
    }
}
