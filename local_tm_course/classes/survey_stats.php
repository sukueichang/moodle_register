<?php
/**
 * Survey response filters, summary, question stats, and export row builders.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class survey_stats {

    /**
     * Build filter object from request-style params.
     *
     * @param array<string,mixed> $params
     * @return \stdClass
     */
    public static function filters_from_params(array $params) {
        $f = new \stdClass();
        $f->surveyid = (int) ($params['surveyid'] ?? 0);
        $f->versionid = (int) ($params['versionid'] ?? 0);
        $f->courseid = (int) ($params['courseid'] ?? 0);
        $f->sessionid = (int) ($params['sessionid'] ?? 0);
        $f->datefrom = (int) ($params['datefrom'] ?? 0);
        $f->dateto = (int) ($params['dateto'] ?? 0);
        $f->email = trim((string) ($params['email'] ?? ''));
        $mapped = $params['mapped'] ?? -1;
        if ($mapped === '' || $mapped === null) {
            $mapped = -1;
        }
        $f->mapped = (int) $mapped; // -1 = all, 1 = mapped, 0 = unmatched
        return $f;
    }

    /**
     * @param \stdClass $filters
     * @return array{0:string,1:array}
     */
    public static function build_sql_where($filters): array {
        global $DB;
        $wheres = ['1=1'];
        $params = [];

        if (!empty($filters->sessionid)) {
            $wheres[] = 'r.sessionid = :sessionid';
            $params['sessionid'] = (int) $filters->sessionid;
        }
        if (!empty($filters->versionid)) {
            $wheres[] = 'r.versionid = :versionid';
            $params['versionid'] = (int) $filters->versionid;
        }
        if (!empty($filters->surveyid)) {
            $wheres[] = 'v.surveyid = :surveyid';
            $params['surveyid'] = (int) $filters->surveyid;
        }
        if (!empty($filters->courseid)) {
            $wheres[] = 's.courseid = :courseid';
            $params['courseid'] = (int) $filters->courseid;
        }
        if (!empty($filters->datefrom)) {
            $wheres[] = 'r.timecreated >= :datefrom';
            $params['datefrom'] = (int) $filters->datefrom;
        }
        if (!empty($filters->dateto)) {
            $wheres[] = 'r.timecreated <= :dateto';
            $params['dateto'] = (int) $filters->dateto;
        }
        if (isset($filters->mapped) && (int) $filters->mapped >= 0) {
            $wheres[] = 'r.mapped = :mapped';
            $params['mapped'] = (int) $filters->mapped;
        }
        if (!empty($filters->email)) {
            $wheres[] = $DB->sql_like('r.email', ':emaillike', false, false);
            $params['emaillike'] = '%' . $DB->sql_like_escape($filters->email) . '%';
        }

        return [implode(' AND ', $wheres), $params];
    }

    /**
     * @param \stdClass $filters
     */
    public static function count_responses($filters): int {
        global $DB;
        list($where, $params) = self::build_sql_where($filters);
        $sql = "SELECT COUNT(r.id)
                  FROM {local_tm_course_svresp} r
                  JOIN {local_tm_course_svver} v ON v.id = r.versionid
                  JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                 WHERE {$where}";
        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * @param \stdClass $filters
     * @return \stdClass[]
     */
    public static function list_responses($filters, int $page = 0, int $perpage = 50): array {
        global $DB;
        list($where, $params) = self::build_sql_where($filters);
        $sql = "SELECT r.*, s.name AS sessionname, s.courseid, s.starttime,
                       c.fullname AS coursename, v.surveyid, v.versionno, d.name AS surveyname
                  FROM {local_tm_course_svresp} r
                  JOIN {local_tm_course_svver} v ON v.id = r.versionid
                  JOIN {local_tm_course_svdef} d ON d.id = v.surveyid
                  JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                  JOIN {course} c ON c.id = s.courseid
                 WHERE {$where}
              ORDER BY r.timecreated DESC, r.id DESC";
        $limitfrom = max(0, $page) * max(1, $perpage);
        return $DB->get_records_sql($sql, $params, $limitfrom, max(1, $perpage));
    }

    /**
     * @param \stdClass $filters
     * @return array{response_count:int,mapped_count:int,unmatched_count:int,expected_headcount:?int,rate:?float}
     */
    public static function summary($filters): array {
        global $DB;
        list($where, $params) = self::build_sql_where($filters);
        $sql = "SELECT COUNT(r.id) AS response_count,
                       SUM(CASE WHEN r.mapped = 1 THEN 1 ELSE 0 END) AS mapped_count,
                       SUM(CASE WHEN r.mapped = 0 THEN 1 ELSE 0 END) AS unmatched_count
                  FROM {local_tm_course_svresp} r
                  JOIN {local_tm_course_svver} v ON v.id = r.versionid
                  JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                 WHERE {$where}";
        $row = $DB->get_record_sql($sql, $params);
        $responsecount = $row ? (int) $row->response_count : 0;
        $mappedcount = $row ? (int) $row->mapped_count : 0;
        $unmatchedcount = $row ? (int) $row->unmatched_count : 0;

        $expected = null;
        $rate = null;
        if (!empty($filters->sessionid)) {
            $expected = survey_manager::expected_headcount((int) $filters->sessionid);
            if ($expected > 0) {
                $rate = round($responsecount / $expected * 100, 1);
            }
        }

        return [
            'response_count' => $responsecount,
            'mapped_count' => $mappedcount,
            'unmatched_count' => $unmatchedcount,
            'expected_headcount' => $expected,
            'rate' => $rate,
        ];
    }

    /**
     * Resolve versionid for question breakdown, or 0 if ambiguous.
     *
     * @param \stdClass $filters
     * @return array{versionid:int,message:string}
     */
    public static function resolve_stats_versionid($filters): array {
        global $DB;
        if (!empty($filters->versionid)) {
            return ['versionid' => (int) $filters->versionid, 'message' => ''];
        }
        if (!empty($filters->sessionid)) {
            $pin = survey_manager::get_pin((int) $filters->sessionid);
            if ($pin) {
                return ['versionid' => (int) $pin->versionid, 'message' => ''];
            }
        }
        if (!empty($filters->surveyid)) {
            $ver = survey_manager::current_version((int) $filters->surveyid);
            if ($ver) {
                return ['versionid' => (int) $ver->id, 'message' => ''];
            }
        }
        list($where, $params) = self::build_sql_where($filters);
        $sql = "SELECT DISTINCT r.versionid
                  FROM {local_tm_course_svresp} r
                  JOIN {local_tm_course_svver} v ON v.id = r.versionid
                  JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                 WHERE {$where}";
        $ids = $DB->get_fieldset_sql($sql, $params);
        if (count($ids) === 1) {
            return ['versionid' => (int) reset($ids), 'message' => ''];
        }
        return [
            'versionid' => 0,
            'message' => get_string('survey_stats_need_version', 'local_tm_course'),
        ];
    }

    /**
     * Question stats for the same response rows as summary / list / Excel.
     *
     * Does not add a version or session condition. Versions inside that set are
     * merged by item stable key when qtype and choice keys are compatible.
     *
     * @param \stdClass $filters
     * @return array{versionid:int,message:string,questions:array}
     */
    public static function question_stats($filters): array {
        $versions = self::dataset_versions($filters);
        if (!$versions && !empty($filters->sessionid)) {
            // Live page with no replies yet still shows the pinned questionnaire.
            $pin = survey_manager::get_pin((int) $filters->sessionid);
            if ($pin) {
                $versions = [(int) $pin->versionid => 0];
            }
        }
        if (!$versions) {
            return [
                'versionid' => 0,
                'message' => '',
                'questions' => [],
            ];
        }

        $versionid = 0;
        if (count($versions) === 1) {
            $versionid = (int) key($versions);
        }
        $questions = [];
        foreach (self::question_groups($versions) as $group) {
            $questions[] = self::aggregate_question_group($filters, $group);
        }

        return [
            'versionid' => $versionid,
            'message' => '',
            'questions' => $questions,
        ];
    }

    /**
     * Versions that actually appear in the filtered responses. Highest versionno first.
     *
     * @param \stdClass $filters
     * @return array<int,int> versionid => versionno
     */
    private static function dataset_versions($filters): array {
        global $DB;
        list($where, $params) = self::build_sql_where($filters);
        $sql = "SELECT DISTINCT r.versionid, v.versionno
                  FROM {local_tm_course_svresp} r
                  JOIN {local_tm_course_svver} v ON v.id = r.versionid
                  JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                 WHERE {$where}";
        $rows = $DB->get_records_sql($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->versionid] = (int) $row->versionno;
        }
        arsort($out);
        return $out;
    }

    /**
     * @param array<int,int> $versions versionid => versionno, newest first
     * @return array<int,array>
     */
    private static function question_groups(array $versions): array {
        $groups = [];
        $order = [];
        foreach ($versions as $versionid => $versionno) {
            unset($versionno);
            $structure = survey_manager::get_version_structure((int) $versionid);
            foreach ($structure as $section) {
                foreach ($section['items'] ?? [] as $item) {
                    $key = (string) ($item['stablekey'] ?? '');
                    if ($key === '') {
                        $key = 'item-' . (int) $item['id'];
                    }
                    if (!isset($groups[$key])) {
                        $groups[$key] = [
                            'itemid' => (int) $item['id'],
                            'title' => (string) $item['title'],
                            'help' => (string) ($item['help'] ?? ''),
                            'section' => (string) ($section['name'] ?? ''),
                            'copies' => [],
                        ];
                        $order[] = $key;
                    }
                    $groups[$key]['copies'][] = $item;
                }
            }
        }
        $list = [];
        foreach ($order as $key) {
            $list[] = $groups[$key];
        }
        return $list;
    }

    /**
     * @param \stdClass $filters
     * @param array $group
     * @return array
     */
    private static function aggregate_question_group($filters, array $group): array {
        global $DB;
        $copies = $group['copies'];
        $qtypes = [];
        foreach ($copies as $item) {
            $qtypes[(string) $item['qtype']] = true;
        }
        $qtype = (string) key($qtypes);
        $entry = [
            'itemid' => (int) $group['itemid'],
            'title' => (string) $group['title'],
            'help' => (string) $group['help'],
            'qtype' => $qtype,
            'section' => (string) $group['section'],
            'answered' => 0,
            'average' => null,
            'scale_counts' => [],
            'options' => [],
            'texts' => [],
            'note' => '',
        ];
        $conflict = self::question_group_conflict($copies, $qtypes);
        if ($conflict !== '') {
            $entry['qtype'] = '';
            $entry['note'] = get_string('survey_stats_q_incompatible', 'local_tm_course');
            $entry['help'] = $entry['note'];
            return $entry;
        }

        $itemids = [];
        foreach ($copies as $item) {
            $itemids[] = (int) $item['id'];
        }
        list($where, $params) = self::build_sql_where($filters);
        list($itemsql, $itemparams) = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'stitem');
        $params = $params + $itemparams;

        if ($qtype === survey_manager::TYPE_SCALE) {
            $sql = "SELECT a.valueint, COUNT(1) AS cnt
                      FROM {local_tm_course_svans} a
                      JOIN {local_tm_course_svresp} r ON r.id = a.responseid
                      JOIN {local_tm_course_svver} v ON v.id = r.versionid
                      JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                     WHERE a.itemid {$itemsql} AND a.valueint IS NOT NULL AND {$where}
                  GROUP BY a.valueint";
            $rows = $DB->get_records_sql($sql, $params);
            $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $sum = 0;
            $n = 0;
            foreach ($rows as $row) {
                $val = (int) $row->valueint;
                $cnt = (int) $row->cnt;
                if ($val >= 1 && $val <= 5) {
                    $counts[$val] += $cnt;
                    $sum += $val * $cnt;
                    $n += $cnt;
                }
            }
            $entry['scale_counts'] = $counts;
            $entry['answered'] = $n;
            $entry['average'] = $n > 0 ? round($sum / $n, 2) : null;
            return $entry;
        }

        if ($qtype === survey_manager::TYPE_SINGLE || $qtype === survey_manager::TYPE_MULTI) {
            $sql = "SELECT COUNT(DISTINCT a.responseid)
                      FROM {local_tm_course_svans} a
                      JOIN {local_tm_course_svresp} r ON r.id = a.responseid
                      JOIN {local_tm_course_svver} v ON v.id = r.versionid
                      JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                     WHERE a.itemid {$itemsql} AND {$where}";
            $answered = (int) $DB->count_records_sql($sql, $params);
            $entry['answered'] = $answered;
            $options = self::merged_option_labels($copies);
            $opts = [];
            foreach ($options as $optkey => $label) {
                $optionids = self::option_ids_for_key($copies, (string) $optkey);
                $cnt = 0;
                if ($optionids) {
                    list($osql, $oparams) = $DB->get_in_or_equal($optionids, SQL_PARAMS_NAMED, 'stopt');
                    if ($qtype === survey_manager::TYPE_SINGLE) {
                        $csql = "SELECT COUNT(1)
                                   FROM {local_tm_course_svans} a
                                   JOIN {local_tm_course_svresp} r ON r.id = a.responseid
                                   JOIN {local_tm_course_svver} v ON v.id = r.versionid
                                   JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                                  WHERE a.itemid {$itemsql} AND a.valueint {$osql} AND {$where}";
                    } else {
                        $csql = "SELECT COUNT(1)
                                   FROM {local_tm_course_svpick} p
                                   JOIN {local_tm_course_svans} a ON a.id = p.answerid
                                   JOIN {local_tm_course_svresp} r ON r.id = a.responseid
                                   JOIN {local_tm_course_svver} v ON v.id = r.versionid
                                   JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                                  WHERE a.itemid {$itemsql} AND p.optionid {$osql} AND {$where}";
                    }
                    $cnt = (int) $DB->count_records_sql($csql, $params + $oparams);
                }
                $pct = $answered > 0 ? round($cnt / $answered * 100, 1) : 0.0;
                $opts[] = [
                    'optionid' => $optionids ? (int) $optionids[0] : 0,
                    'label' => $label,
                    'count' => $cnt,
                    'pct' => $pct,
                ];
            }
            $entry['options'] = $opts;
            return $entry;
        }

        if ($qtype === survey_manager::TYPE_TEXT) {
            $sql = "SELECT a.valuetext
                      FROM {local_tm_course_svans} a
                      JOIN {local_tm_course_svresp} r ON r.id = a.responseid
                      JOIN {local_tm_course_svver} v ON v.id = r.versionid
                      JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                     WHERE a.itemid {$itemsql} AND a.valuetext IS NOT NULL AND a.valuetext <> '' AND {$where}
                  ORDER BY a.id ASC";
            $texts = $DB->get_fieldset_sql($sql, $params);
            $texts = array_values(array_slice($texts, 0, 500));
            $entry['answered'] = count($texts);
            $entry['texts'] = $texts;
        }
        return $entry;
    }

    /**
     * @param array $copies
     * @param array<string,bool> $qtypes
     */
    private static function question_group_conflict(array $copies, array $qtypes): string {
        if (count($qtypes) > 1) {
            return 'qtype';
        }
        $qtype = (string) key($qtypes);
        if ($qtype !== survey_manager::TYPE_SINGLE && $qtype !== survey_manager::TYPE_MULTI) {
            return '';
        }
        $sets = [];
        foreach ($copies as $item) {
            $set = [];
            foreach ($item['options'] ?? [] as $option) {
                $key = (string) ($option['stablekey'] ?? '');
                if ($key !== '') {
                    $set[$key] = true;
                }
            }
            if ($set) {
                $sets[] = $set;
            }
        }
        $n = count($sets);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (!array_intersect_key($sets[$i], $sets[$j])) {
                    return 'options';
                }
            }
        }
        return '';
    }

    /**
     * Option labels from the newest copy, then keys that exist only on older copies.
     *
     * @param array $copies
     * @return array<string,string>
     */
    private static function merged_option_labels(array $copies): array {
        $labels = [];
        foreach ($copies as $item) {
            foreach ($item['options'] ?? [] as $option) {
                $key = (string) ($option['stablekey'] ?? '');
                if ($key === '') {
                    $key = 'opt-' . (int) $option['id'];
                }
                if (!isset($labels[$key])) {
                    $labels[$key] = (string) $option['label'];
                }
            }
        }
        return $labels;
    }

    /**
     * @param array $copies
     * @return int[]
     */
    private static function option_ids_for_key(array $copies, string $optkey): array {
        $ids = [];
        foreach ($copies as $item) {
            foreach ($item['options'] ?? [] as $option) {
                $key = (string) ($option['stablekey'] ?? '');
                if ($key === '') {
                    $key = 'opt-' . (int) $option['id'];
                }
                if ($key === $optkey) {
                    $ids[] = (int) $option['id'];
                }
            }
        }
        return $ids;
    }

    /**
     * Aggregate stats for one session only. No emails, user ids, or response rows.
     *
     * @return array{
     *   response_count:int,
     *   mapped_count:int,
     *   unmatched_count:int,
     *   expected_headcount:?int,
     *   message:string,
     *   sections:array<int,array{name:string,questions:array}>
     * }
     */
    public static function session_live_snapshot(int $sessionid): array {
        $filters = self::filters_from_params(['sessionid' => $sessionid]);
        $summary = self::summary($filters);
        $stats = self::question_stats($filters);
        $sections = [];
        $index = [];
        foreach ($stats['questions'] as $q) {
            $name = (string) ($q['section'] ?? '');
            if (!isset($index[$name])) {
                $index[$name] = count($sections);
                $sections[] = ['name' => $name, 'questions' => []];
            }
            $safe = [
                'title' => (string) $q['title'],
                'help' => (string) ($q['help'] ?? ''),
                'qtype' => (string) $q['qtype'],
                'answered' => (int) $q['answered'],
                'average' => $q['average'],
                'scale_counts' => $q['scale_counts'],
                'options' => [],
                'texts' => [],
            ];
            foreach ($q['options'] as $opt) {
                $safe['options'][] = [
                    'label' => (string) $opt['label'],
                    'count' => (int) $opt['count'],
                    'pct' => (float) $opt['pct'],
                ];
            }
            foreach ($q['texts'] as $text) {
                $safe['texts'][] = (string) $text;
            }
            $sections[$index[$name]]['questions'][] = $safe;
        }
        return [
            'response_count' => (int) $summary['response_count'],
            'mapped_count' => (int) $summary['mapped_count'],
            'unmatched_count' => (int) $summary['unmatched_count'],
            'expected_headcount' => $summary['expected_headcount'],
            'message' => (string) $stats['message'],
            'sections' => $sections,
        ];
    }

    /**
     * Sheet 1 (Responses) and sheet 2 (Statistics) row arrays for xlsx export.
     *
     * @param \stdClass $filters
     * @return array{0:array,1:array} [responses rows, statistics rows]
     */
    public static function export_rows($filters): array {
        $responses = [[
            'ID', 'Email', 'Mapped', 'Enrol ID', 'User ID', 'Session', 'Course',
            'Survey', 'Version', 'Submitted',
        ]];
        $page = 0;
        $perpage = 500;
        do {
            $batch = self::list_responses($filters, $page, $perpage);
            foreach ($batch as $row) {
                $responses[] = [
                    (string) $row->id,
                    (string) $row->email,
                    ((int) $row->mapped === survey_manager::MAPPED) ? 'mapped' : 'unmatched',
                    (string) $row->enrolid,
                    (string) $row->userid,
                    (string) $row->sessionname,
                    (string) $row->coursename,
                    (string) $row->surveyname,
                    (string) $row->versionno,
                    userdate((int) $row->timecreated, '%Y-%m-%d %H:%M'),
                ];
            }
            $page++;
        } while (count($batch) === $perpage);

        $summary = self::summary($filters);
        $stats = self::question_stats($filters);
        $statistics = [[
            'Metric / Question', 'Detail', 'Value', 'Extra',
        ]];
        $statistics[] = ['Summary', 'response_count', (string) $summary['response_count'], ''];
        $statistics[] = ['Summary', 'mapped_count', (string) $summary['mapped_count'], ''];
        $statistics[] = ['Summary', 'unmatched_count', (string) $summary['unmatched_count'], ''];
        if ($summary['expected_headcount'] !== null) {
            $statistics[] = ['Summary', 'expected_headcount', (string) $summary['expected_headcount'], ''];
        }
        if ($summary['rate'] !== null) {
            $statistics[] = ['Summary', 'rate_pct', (string) $summary['rate'], ''];
        }
        if ($stats['message'] !== '') {
            $statistics[] = ['Note', $stats['message'], '', ''];
        }
        foreach ($stats['questions'] as $q) {
            $title = (string) $q['title'];
            if (!empty($q['note'])) {
                $statistics[] = [$title, 'note', (string) $q['note'], ''];
            }
            $statistics[] = [$title, 'type', (string) $q['qtype'], 'answered=' . $q['answered']];
            if ($q['qtype'] === survey_manager::TYPE_SCALE) {
                $statistics[] = [$title, 'average', (string) ($q['average'] ?? ''), ''];
                foreach ($q['scale_counts'] as $score => $cnt) {
                    $statistics[] = [$title, 'score_' . $score, (string) $cnt, ''];
                }
            } else if ($q['qtype'] === survey_manager::TYPE_SINGLE || $q['qtype'] === survey_manager::TYPE_MULTI) {
                foreach ($q['options'] as $opt) {
                    $statistics[] = [
                        $title,
                        (string) $opt['label'],
                        (string) $opt['count'],
                        (string) $opt['pct'] . '%',
                    ];
                }
            } else if ($q['qtype'] === survey_manager::TYPE_TEXT) {
                foreach ($q['texts'] as $text) {
                    $statistics[] = [$title, 'text', (string) $text, ''];
                }
            }
        }

        return [$responses, $statistics];
    }

    /**
     * Fill a MoodleExcelWorkbook with Responses + Statistics sheets (same filters as UI).
     *
     * @param \MoodleExcelWorkbook $workbook
     * @param \stdClass $filters
     */
    public static function fill_moodle_excel_workbook($workbook, $filters): void {
        list($responses, $statistics) = self::export_rows($filters);
        self::write_sheet_rows($workbook->add_worksheet('Responses'), $responses);
        self::write_sheet_rows($workbook->add_worksheet('Statistics'), $statistics);
    }

    /**
     * @param \MoodleExcelWorksheet $worksheet
     * @param array<int,array<int,string>> $rows
     */
    private static function write_sheet_rows($worksheet, array $rows): void {
        $r = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $c = 0;
            foreach ($row as $value) {
                // Always string — preserves email / leading zeros / Chinese safely.
                $worksheet->write_string($r, $c, (string) $value);
                $c++;
            }
            $r++;
        }
        if ($r === 0) {
            $worksheet->write_string(0, 0, '');
        }
    }
}
