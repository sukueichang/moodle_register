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
     * @param \stdClass $filters
     * @return array{versionid:int,message:string,questions:array}
     */
    public static function question_stats($filters): array {
        global $DB;
        $resolved = self::resolve_stats_versionid($filters);
        $versionid = (int) $resolved['versionid'];
        if ($versionid <= 0) {
            return [
                'versionid' => 0,
                'message' => $resolved['message'],
                'questions' => [],
            ];
        }

        $f2 = clone $filters;
        $f2->versionid = $versionid;
        list($where, $params) = self::build_sql_where($f2);

        $structure = survey_manager::get_version_structure($versionid);
        $questions = [];
        foreach ($structure as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $itemid = (int) $item['id'];
                $qtype = (string) $item['qtype'];
                $entry = [
                    'itemid' => $itemid,
                    'title' => (string) $item['title'],
                    'qtype' => $qtype,
                    'section' => (string) ($section['name'] ?? ''),
                    'answered' => 0,
                    'average' => null,
                    'scale_counts' => [],
                    'options' => [],
                    'texts' => [],
                ];

                if ($qtype === survey_manager::TYPE_SCALE) {
                    $sql = "SELECT a.valueint, COUNT(1) AS cnt
                              FROM {local_tm_course_svans} a
                              JOIN {local_tm_course_svresp} r ON r.id = a.responseid
                              JOIN {local_tm_course_svver} v ON v.id = r.versionid
                              JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                             WHERE a.itemid = :itemid AND a.valueint IS NOT NULL AND {$where}
                          GROUP BY a.valueint";
                    $p = $params + ['itemid' => $itemid];
                    $rows = $DB->get_records_sql($sql, $p);
                    $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
                    $sum = 0;
                    $n = 0;
                    foreach ($rows as $row) {
                        $val = (int) $row->valueint;
                        $cnt = (int) $row->cnt;
                        if ($val >= 1 && $val <= 5) {
                            $counts[$val] = $cnt;
                            $sum += $val * $cnt;
                            $n += $cnt;
                        }
                    }
                    $entry['scale_counts'] = $counts;
                    $entry['answered'] = $n;
                    $entry['average'] = $n > 0 ? round($sum / $n, 2) : null;
                } else if ($qtype === survey_manager::TYPE_SINGLE || $qtype === survey_manager::TYPE_MULTI) {
                    $sql = "SELECT COUNT(DISTINCT a.responseid)
                              FROM {local_tm_course_svans} a
                              JOIN {local_tm_course_svresp} r ON r.id = a.responseid
                              JOIN {local_tm_course_svver} v ON v.id = r.versionid
                              JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                             WHERE a.itemid = :itemid AND {$where}";
                    $answered = (int) $DB->count_records_sql($sql, $params + ['itemid' => $itemid]);
                    $entry['answered'] = $answered;
                    $opts = [];
                    foreach ($item['options'] ?? [] as $option) {
                        $oid = (int) $option['id'];
                        if ($qtype === survey_manager::TYPE_SINGLE) {
                            $csql = "SELECT COUNT(1)
                                       FROM {local_tm_course_svans} a
                                       JOIN {local_tm_course_svresp} r ON r.id = a.responseid
                                       JOIN {local_tm_course_svver} v ON v.id = r.versionid
                                       JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                                      WHERE a.itemid = :itemid AND a.valueint = :oid AND {$where}";
                            $cnt = (int) $DB->count_records_sql($csql, $params + ['itemid' => $itemid, 'oid' => $oid]);
                        } else {
                            $csql = "SELECT COUNT(1)
                                       FROM {local_tm_course_svpick} p
                                       JOIN {local_tm_course_svans} a ON a.id = p.answerid
                                       JOIN {local_tm_course_svresp} r ON r.id = a.responseid
                                       JOIN {local_tm_course_svver} v ON v.id = r.versionid
                                       JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                                      WHERE a.itemid = :itemid AND p.optionid = :oid AND {$where}";
                            $cnt = (int) $DB->count_records_sql($csql, $params + ['itemid' => $itemid, 'oid' => $oid]);
                        }
                        $pct = $answered > 0 ? round($cnt / $answered * 100, 1) : 0.0;
                        $opts[] = [
                            'optionid' => $oid,
                            'label' => (string) $option['label'],
                            'count' => $cnt,
                            'pct' => $pct,
                        ];
                    }
                    $entry['options'] = $opts;
                } else if ($qtype === survey_manager::TYPE_TEXT) {
                    $sql = "SELECT a.valuetext
                              FROM {local_tm_course_svans} a
                              JOIN {local_tm_course_svresp} r ON r.id = a.responseid
                              JOIN {local_tm_course_svver} v ON v.id = r.versionid
                              JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
                             WHERE a.itemid = :itemid AND a.valuetext IS NOT NULL AND a.valuetext <> '' AND {$where}
                          ORDER BY a.id ASC";
                    $texts = $DB->get_fieldset_sql($sql, $params + ['itemid' => $itemid]);
                    $texts = array_values(array_slice($texts, 0, 500));
                    $entry['answered'] = count($texts);
                    $entry['texts'] = $texts;
                }

                $questions[] = $entry;
            }
        }

        return [
            'versionid' => $versionid,
            'message' => '',
            'questions' => $questions,
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
}
