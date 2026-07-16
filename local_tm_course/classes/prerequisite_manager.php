<?php
/**
 * Session prerequisite rules — parse, validate, evaluate.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/enabled_course_manager.php');

class prerequisite_manager {

    public const OPERATOR_AND = 'and';
    public const OPERATOR_OR = 'or';
    public const VERIFY_COURSE = 'course';
    public const VERIFY_ACTIVITIES = 'activities';
    public const VERIFY_GRADES = 'grades';
    public const ACTIVITY_ALL = 'all';
    public const ACTIVITY_ANY = 'any';

    /** Stored on session when admin explicitly clears all prerequisite rules. */
    private const EXPLICIT_EMPTY_RULES_JSON = '{"operator":"and","rules":[]}';

    /**
     * Whether a session has any prerequisite configuration (stored or course default).
     */
    public static function session_has_prerequisites(\stdClass $session): bool {
        $rules = self::resolve_session_rules($session);
        return $rules !== null && !empty($rules['rules']);
    }

    /**
     * Effective prerequisite rules: session-stored, else course-mapping defaults.
     *
     * @return array{operator:string,rules:array<int,array>}|null
     */
    public static function resolve_session_rules(\stdClass $session): ?array {
        if (self::is_explicitly_no_prerequisites($session)) {
            return null;
        }
        $stored = self::parse_stored_session_rules($session);
        if ($stored !== null) {
            return $stored;
        }
        $courseid = (int) ($session->courseid ?? 0);
        if ($courseid > 0) {
            return enabled_course_manager::get_default_prerequisite_rules($courseid);
        }
        return null;
    }

    /**
     * Whether this session has no stored rules and inherits course-mapping defaults.
     */
    public static function session_inherits_course_prerequisite_defaults(\stdClass $session): bool {
        if (self::is_explicitly_no_prerequisites($session)) {
            return false;
        }
        if (self::parse_stored_session_rules($session) !== null) {
            return false;
        }
        if ((int) ($session->prerequisite_courseid ?? 0) > 0) {
            return false;
        }
        $courseid = (int) ($session->courseid ?? 0);
        if ($courseid <= 0) {
            return false;
        }
        $defaults = enabled_course_manager::get_default_prerequisite_rules($courseid);
        return $defaults !== null && !empty($defaults['rules']);
    }

    /**
     * Decode rules stored on the session row only (no course-default fallback).
     *
     * @return array{operator:string,rules:array<int,array>}|null
     */
    public static function parse_stored_session_rules(\stdClass $session): ?array {
        $json = trim((string) ($session->prerequisite_rules ?? ''));
        if ($json !== '' && !self::is_explicit_empty_rules_json($json)) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return self::normalize_rules($decoded);
            }
        }
        $legacy = (int) ($session->prerequisite_courseid ?? 0);
        if ($legacy > 0) {
            return self::normalize_rules([
                'operator' => self::OPERATOR_AND,
                'rules' => [
                    ['courseid' => $legacy, 'verify_type' => self::VERIFY_COURSE],
                ],
            ]);
        }
        return null;
    }

    /**
     * @deprecated Use resolve_session_rules() for runtime checks/display.
     * @return array{operator:string,rules:array<int,array>}|null
     */
    public static function parse_session_rules(\stdClass $session): ?array {
        return self::resolve_session_rules($session);
    }

    public static function is_explicitly_no_prerequisites(\stdClass $session): bool {
        $json = trim((string) ($session->prerequisite_rules ?? ''));
        return $json !== '' && self::is_explicit_empty_rules_json($json);
    }

    private static function is_explicit_empty_rules_json(string $json): bool {
        if ($json === self::EXPLICIT_EMPTY_RULES_JSON) {
            return true;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return false;
        }
        $items = $decoded['rules'] ?? null;
        return is_array($items) && count($items) === 0;
    }

    /**
     * @param array $raw decoded JSON or form-built structure
     * @return array{operator:string,rules:array<int,array>}|null null when empty
     */
    public static function normalize_rules(array $raw): ?array {
        $operator = \core_text::strtolower(trim((string)($raw['operator'] ?? self::OPERATOR_AND)));
        if (!in_array($operator, [self::OPERATOR_AND, self::OPERATOR_OR], true)) {
            $operator = self::OPERATOR_AND;
        }
        $items = $raw['rules'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        $rules = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $courseid = (int)($item['courseid'] ?? 0);
            if ($courseid <= 0 || $courseid === SITEID) {
                continue;
            }
            $verify = \core_text::strtolower(trim((string)($item['verify_type'] ?? self::VERIFY_COURSE)));
            if (!in_array($verify, [self::VERIFY_COURSE, self::VERIFY_ACTIVITIES, self::VERIFY_GRADES], true)) {
                $verify = self::VERIFY_COURSE;
            }
            $rule = [
                'courseid' => $courseid,
                'verify_type' => $verify,
            ];
            if ($verify === self::VERIFY_GRADES) {
                $conditions = self::normalize_grade_conditions((array)($item['grade_conditions'] ?? []));
                if (empty($conditions)) {
                    continue;
                }
                $gradeop = \core_text::strtolower(trim((string)($item['grade_operator'] ?? self::ACTIVITY_ALL)));
                if (!in_array($gradeop, [self::ACTIVITY_ALL, self::ACTIVITY_ANY], true)) {
                    $gradeop = self::ACTIVITY_ALL;
                }
                $rule['grade_conditions'] = $conditions;
                $rule['grade_operator'] = $gradeop;
            } else if ($verify === self::VERIFY_ACTIVITIES) {
                $cmids = [];
                foreach ((array)($item['cmids'] ?? []) as $cmid) {
                    $cmid = (int)$cmid;
                    if ($cmid > 0) {
                        $cmids[$cmid] = $cmid;
                    }
                }
                $cmids = array_values($cmids);
                if (empty($cmids)) {
                    continue;
                }
                $actop = \core_text::strtolower(trim((string)($item['activity_operator'] ?? self::ACTIVITY_ALL)));
                if (!in_array($actop, [self::ACTIVITY_ALL, self::ACTIVITY_ANY], true)) {
                    $actop = self::ACTIVITY_ALL;
                }
                $rule['cmids'] = $cmids;
                $rule['activity_operator'] = $actop;
            }
            $rules[] = $rule;
        }
        if (empty($rules)) {
            return null;
        }
        return [
            'operator' => $operator,
            'rules' => $rules,
        ];
    }

    /**
     * @throws \moodle_exception
     */
    public static function validate_rules(?array $rules): void {
        if ($rules === null) {
            return;
        }
        foreach ($rules['rules'] as $rule) {
            $courseid = (int)$rule['courseid'];
            if (!enabled_course_manager::is_enabled($courseid)) {
                throw new \moodle_exception('error_prerequisite_course_not_enabled', 'local_tm_course');
            }
            if ($rule['verify_type'] === self::VERIFY_ACTIVITIES) {
                foreach ($rule['cmids'] as $cmid) {
                    if (!self::cmid_belongs_to_course((int)$cmid, $courseid)) {
                        throw new \moodle_exception('error_prerequisite_activity_invalid', 'local_tm_course');
                    }
                }
            }
            if ($rule['verify_type'] === self::VERIFY_GRADES) {
                foreach ($rule['grade_conditions'] as $cond) {
                    if (!self::cmid_belongs_to_course((int)$cond['cmid'], $courseid)) {
                        throw new \moodle_exception('error_prerequisite_activity_invalid', 'local_tm_course');
                    }
                }
            }
        }
    }

    public static function encode_for_storage(?array $rules): ?string {
        if ($rules === null || empty($rules['rules'])) {
            return self::EXPLICIT_EMPTY_RULES_JSON;
        }
        return json_encode($rules, JSON_UNESCAPED_UNICODE);
    }

    /**
     * First rule course id for legacy column sync (optional).
     */
    public static function legacy_courseid_from_rules(?array $rules): ?int {
        if ($rules === null || empty($rules['rules'])) {
            return null;
        }
        $first = (int)$rules['rules'][0]['courseid'];
        return $first > 0 ? $first : null;
    }

    /**
     * Build rules from edit_session POST.
     *
     * @return array{operator:string,rules:array<int,array>}|null
     */
    public static function build_from_post(
        string $operator,
        array $courseids,
        array $verifytypes,
        array $activityoperators,
        array $cmidsbyrow,
        array $gradeconditionsbyrow = [],
        array $gradeoperatorsbyrow = []
    ): ?array {
        $rawrules = [];
        $count = max(count($courseids), count($verifytypes));
        for ($i = 0; $i < $count; $i++) {
            $courseid = (int)($courseids[$i] ?? 0);
            if ($courseid <= 0) {
                continue;
            }
            $verify = \core_text::strtolower(trim((string)($verifytypes[$i] ?? self::VERIFY_COURSE)));
            $item = [
                'courseid' => $courseid,
                'verify_type' => $verify,
            ];
            if ($verify === self::VERIFY_GRADES) {
                $rawconds = [];
                if (isset($gradeconditionsbyrow[$i]) && is_array($gradeconditionsbyrow[$i])) {
                    $rawconds = $gradeconditionsbyrow[$i];
                }
                $item['grade_conditions'] = $rawconds;
                $gradeop = \core_text::strtolower(trim((string)($gradeoperatorsbyrow[$i] ?? self::ACTIVITY_ALL)));
                $item['grade_operator'] = $gradeop;
            } else if ($verify === self::VERIFY_ACTIVITIES) {
                $rowcmids = [];
                if (isset($cmidsbyrow[$i]) && is_array($cmidsbyrow[$i])) {
                    foreach ($cmidsbyrow[$i] as $cmid) {
                        $cmid = (int)$cmid;
                        if ($cmid > 0) {
                            $rowcmids[] = $cmid;
                        }
                    }
                }
                $item['cmids'] = $rowcmids;
                $actop = \core_text::strtolower(trim((string)($activityoperators[$i] ?? self::ACTIVITY_ALL)));
                $item['activity_operator'] = $actop;
            }
            $rawrules[] = $item;
        }
        return self::normalize_rules([
            'operator' => $operator,
            'rules' => $rawrules,
        ]);
    }

    public static function user_meets_rules(int $userid, ?array $rules): bool {
        $eval = self::evaluate_user($userid, $rules);
        return !empty($eval['met']);
    }

    /**
     * @return array{met:bool,missing:array<int,array{label:string,courseid:int,reasons?:string[]}>}
     */
    public static function evaluate_user(int $userid, ?array $rules): array {
        if ($rules === null || empty($rules['rules']) || $userid < 2) {
            return ['met' => true, 'missing' => []];
        }
        $operator = $rules['operator'];
        $missing = [];
        $passedany = false;
        $failedall = false;

        foreach ($rules['rules'] as $rule) {
            $rulemet = self::user_meets_rule($userid, $rule);
            if ($rulemet) {
                $passedany = true;
                if ($operator === self::OPERATOR_OR) {
                    return ['met' => true, 'missing' => []];
                }
            } else {
                $failedall = true;
                $missing[] = [
                    'courseid' => (int)$rule['courseid'],
                    'label' => self::format_rule_label($rule),
                    'reasons' => self::get_rule_failure_reasons($userid, $rule),
                ];
                if ($operator === self::OPERATOR_AND) {
                    // Keep collecting for debrief; short-circuit met is false.
                }
            }
        }

        if ($operator === self::OPERATOR_OR) {
            return ['met' => $passedany, 'missing' => $missing];
        }
        return ['met' => !$failedall && !empty($rules['rules']), 'missing' => $missing];
    }

    /**
     * Human-readable reasons why a single normalized rule is not met for the user.
     *
     * @param array $rule normalized rule row
     * @return string[]
     */
    public static function get_rule_failure_reasons(int $userid, array $rule): array {
        if (self::user_meets_rule($userid, $rule)) {
            return [];
        }

        global $CFG;
        require_once($CFG->dirroot . '/lib/completionlib.php');

        $courseid = (int)$rule['courseid'];
        $coursename = self::get_course_display_name($courseid);
        $verify = $rule['verify_type'] ?? self::VERIFY_COURSE;

        if ($verify === self::VERIFY_GRADES) {
            return self::explain_grade_rule_failures($userid, $rule);
        }

        $course = get_course($courseid, false, IGNORE_MISSING);
        if (!$course) {
            return [get_string('prerequisite_reason_course_not_found', 'local_tm_course', $coursename)];
        }

        $completion = new \completion_info($course);
        if (!$completion->is_enabled()) {
            return [get_string('prerequisite_reason_completion_disabled', 'local_tm_course', $coursename)];
        }

        if ($verify === self::VERIFY_COURSE) {
            return [get_string('prerequisite_reason_course_incomplete', 'local_tm_course', $coursename)];
        }

        return self::explain_activity_rule_failures($userid, $rule, $completion);
    }

    /**
     * @param array<int,array{label?:string,courseid?:int,reasons?:string[]}> $missing
     * @return string[]
     */
    public static function flatten_missing_reasons(array $missing): array {
        $lines = [];
        foreach ($missing as $item) {
            foreach ((array)($item['reasons'] ?? []) as $reason) {
                $reason = trim((string)$reason);
                if ($reason !== '') {
                    $lines[] = $reason;
                }
            }
        }
        return array_values($lines);
    }

    /**
     * @param array<int,array{label?:string,courseid?:int,reasons?:string[]}> $missing
     */
    public static function format_missing_reason_list(array $missing): string {
        $lines = self::flatten_missing_reasons($missing);
        if (!empty($lines)) {
            $sep = get_string('prerequisite_reason_separator', 'local_tm_course');
            return implode($sep, $lines);
        }
        return self::format_missing_course_name_list($missing);
    }

    /**
     * @param array $rule normalized rule row
     */
    public static function user_meets_rule(int $userid, array $rule): bool {
        global $CFG;
        require_once($CFG->dirroot . '/lib/completionlib.php');

        $courseid = (int)$rule['courseid'];
        $course = get_course($courseid, false, IGNORE_MISSING);
        if (!$course) {
            return false;
        }

        if (($rule['verify_type'] ?? '') === self::VERIFY_GRADES) {
            return self::user_meets_grade_rule($userid, $rule);
        }

        $completion = new \completion_info($course);
        if (!$completion->is_enabled()) {
            return false;
        }

        if ($rule['verify_type'] === self::VERIFY_COURSE) {
            return $completion->is_course_complete($userid);
        }

        $cmids = array_map('intval', (array)($rule['cmids'] ?? []));
        if (empty($cmids)) {
            return false;
        }
        $actop = $rule['activity_operator'] ?? self::ACTIVITY_ALL;
        $completed = 0;
        foreach ($cmids as $cmid) {
            if (self::is_activity_complete($completion, $cmid, $userid)) {
                $completed++;
                if ($actop === self::ACTIVITY_ANY) {
                    return true;
                }
            } else if ($actop === self::ACTIVITY_ALL) {
                return false;
            }
        }
        return $actop === self::ACTIVITY_ALL && $completed === count($cmids);
    }

    /**
     * @return array<int,array{cmid:int,label:string}>
     */
    public static function get_completion_activities(int $courseid): array {
        global $CFG;
        require_once($CFG->dirroot . '/lib/completionlib.php');
        require_once($CFG->dirroot . '/lib/modinfolib.php');

        if ($courseid <= 0 || !enabled_course_manager::is_enabled($courseid)) {
            return [];
        }
        $course = get_course($courseid, false, IGNORE_MISSING);
        if (!$course) {
            return [];
        }
        $completion = new \completion_info($course);
        if (!$completion->is_enabled()) {
            return [];
        }
        $modinfo = get_fast_modinfo($course);
        $out = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress || empty($cm->id)) {
                continue;
            }
            if ($completion->is_enabled($cm) === COMPLETION_TRACKING_NONE) {
                continue;
            }
            $name = $cm->get_formatted_name();
            $out[] = [
                'cmid' => (int)$cm->id,
                'label' => format_string($name, true, ['context' => \context_module::instance($cm->id)]),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp($a['label'], $b['label']);
        });
        return $out;
    }

    /**
     * Activities with a Moodle gradebook item (includes attendance with grades).
     *
     * @return array<int,array{cmid:int,label:string}>
     */
    public static function get_gradeable_activities(int $courseid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/lib/modinfolib.php');

        if ($courseid <= 0 || !enabled_course_manager::is_enabled($courseid)) {
            return [];
        }
        $course = get_course($courseid, false, IGNORE_MISSING);
        if (!$course) {
            return [];
        }

        $sql = "SELECT cm.id AS cmid, gi.itemmodule, gi.iteminstance
                  FROM {grade_items} gi
                  JOIN {modules} m ON m.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.course = gi.courseid
                       AND cm.module = m.id
                       AND cm.instance = gi.iteminstance
                 WHERE gi.courseid = :courseid
                   AND gi.itemtype = 'mod'
                   AND gi.itemnumber = 0
                   AND (cm.deletioninprogress = 0 OR cm.deletioninprogress IS NULL)
              ORDER BY gi.sortorder ASC, cm.id ASC";
        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid]);
        $modinfo = get_fast_modinfo($course);
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $cmid = (int)$row->cmid;
            if ($cmid <= 0 || isset($seen[$cmid])) {
                continue;
            }
            if (empty($modinfo->cms[$cmid])) {
                continue;
            }
            $seen[$cmid] = true;
            $cm = $modinfo->cms[$cmid];
            $out[] = [
                'cmid' => $cmid,
                'label' => format_string($cm->get_formatted_name(), true, ['context' => \context_module::instance($cmid)]),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp($a['label'], $b['label']);
        });
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $raw
     * @return array<int,array{cmid:int,min:?float,max:?float}>
     */
    private static function normalize_grade_conditions(array $raw): array {
        $conditions = [];
        foreach ($raw as $cond) {
            if (!is_array($cond)) {
                continue;
            }
            $cmid = (int)($cond['cmid'] ?? 0);
            if ($cmid <= 0) {
                continue;
            }
            $min = self::normalize_grade_threshold($cond['min'] ?? null);
            $max = self::normalize_grade_threshold($cond['max'] ?? null);
            if ($min === null && $max === null) {
                continue;
            }
            if ($min !== null && $max !== null && $min >= $max) {
                continue;
            }
            $conditions[] = [
                'cmid' => $cmid,
                'min' => $min,
                'max' => $max,
            ];
        }
        return $conditions;
    }

    /**
     * @param mixed $value
     */
    private static function normalize_grade_threshold($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $num = (float)$value;
        if ($num < 0) {
            $num = 0.0;
        }
        if ($num > 100) {
            $num = 100.0;
        }
        return round($num, 5);
    }

    /**
     * @param array $rule normalized grade rule row
     */
    private static function user_meets_grade_rule(int $userid, array $rule): bool {
        $courseid = (int)$rule['courseid'];
        $conditions = (array)($rule['grade_conditions'] ?? []);
        if (empty($conditions)) {
            return false;
        }
        $gradeop = $rule['grade_operator'] ?? self::ACTIVITY_ALL;
        $matched = 0;
        foreach ($conditions as $cond) {
            $ok = self::user_meets_grade_condition(
                $courseid,
                (int)$cond['cmid'],
                $userid,
                array_key_exists('min', $cond) ? $cond['min'] : null,
                array_key_exists('max', $cond) ? $cond['max'] : null
            );
            if ($ok) {
                $matched++;
                if ($gradeop === self::ACTIVITY_ANY) {
                    return true;
                }
            } else if ($gradeop === self::ACTIVITY_ALL) {
                return false;
            }
        }
        return $gradeop === self::ACTIVITY_ALL && $matched === count($conditions);
    }

    private static function user_meets_grade_condition(
        int $courseid,
        int $cmid,
        int $userid,
        ?float $minpercent,
        ?float $maxpercent
    ): bool {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        if (!self::cmid_belongs_to_course($cmid, $courseid)) {
            return false;
        }

        $percent = self::get_user_cm_grade_percentage($courseid, $cmid, $userid);
        if ($percent === null) {
            return false;
        }

        if ($minpercent !== null && $percent + 0.00001 < $minpercent) {
            return false;
        }
        if ($maxpercent !== null && $percent >= $maxpercent - 0.00001) {
            return false;
        }
        return true;
    }

    private static function get_user_cm_grade_percentage(int $courseid, int $cmid, int $userid): ?float {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id,module,instance,course', IGNORE_MISSING);
        if (!$cm || (int)$cm->course !== $courseid) {
            return null;
        }
        $modname = $DB->get_field('modules', 'name', ['id' => (int)$cm->module], IGNORE_MISSING);
        if ($modname === false || $modname === '') {
            return null;
        }

        $gradeitem = \grade_item::fetch([
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => (int)$cm->instance,
            'itemnumber' => 0,
        ]);
        if (!$gradeitem) {
            return null;
        }

        $grade = \grade_grade::fetch(['itemid' => (int)$gradeitem->id, 'userid' => $userid]);
        if (!$grade || $grade->finalgrade === null) {
            return null;
        }

        $grademin = (float)$gradeitem->grademin;
        $grademax = (float)$gradeitem->grademax;
        if ($grademax <= $grademin) {
            return null;
        }

        $final = (float)$grade->finalgrade;
        return (($final - $grademin) / ($grademax - $grademin)) * 100.0;
    }

    /**
     * Human-readable labels for course-module ids without loading course/lib.php
     * (safe when called from namespaced code during batch enrol redirects).
     *
     * @param int[] $cmids
     * @return string[]
     */
    private static function get_cm_display_names(array $cmids): array {
        global $DB;
        $cmids = array_values(array_filter(array_map('intval', $cmids)));
        if (empty($cmids)) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT cm.id, cm.module, cm.instance, m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id $insql
                AND (cm.deletioninprogress = 0 OR cm.deletioninprogress IS NULL)",
            $params
        );
        $labels = [];
        foreach ($cmids as $cmid) {
            if (!isset($records[$cmid])) {
                continue;
            }
            $rec = $records[$cmid];
            $modname = clean_param((string)$rec->modname, PARAM_PLUGIN);
            $instance = (int)$rec->instance;
            $name = '';
            if ($modname !== '' && $instance > 0 && $DB->get_manager()->table_exists($modname)) {
                $name = (string)$DB->get_field($modname, 'name', ['id' => $instance], IGNORE_MISSING);
            }
            $labels[] = $name !== '' ? format_string($name) : ($modname !== '' ? $modname : 'cm') . ' #' . $cmid;
        }
        return $labels;
    }

    /**
     * @param array $rule normalized rule
     */
    public static function format_rule_label(array $rule): string {
        global $DB;
        $courseid = (int)$rule['courseid'];
        $cname = $DB->get_field('course', 'fullname', ['id' => $courseid], IGNORE_MISSING);
        $cname = $cname ? format_string($cname) : ('#' . $courseid);
        if (($rule['verify_type'] ?? '') === self::VERIFY_COURSE) {
            return get_string('prerequisite_label_course', 'local_tm_course', $cname);
        }
        if (($rule['verify_type'] ?? '') === self::VERIFY_GRADES) {
            $parts = [];
            foreach ((array)($rule['grade_conditions'] ?? []) as $cond) {
                $cmid = (int)($cond['cmid'] ?? 0);
                $names = self::get_cm_display_names($cmid > 0 ? [$cmid] : []);
                $actlabel = !empty($names) ? $names[0] : get_string('prerequisite_activities', 'local_tm_course');
                $reqparts = [];
                if (array_key_exists('min', $cond) && $cond['min'] !== null) {
                    $reqparts[] = get_string('prerequisite_grade_min', 'local_tm_course', $cond['min']);
                }
                if (array_key_exists('max', $cond) && $cond['max'] !== null) {
                    $reqparts[] = get_string('prerequisite_grade_max', 'local_tm_course', $cond['max']);
                }
                $parts[] = $actlabel . ' (' . implode(', ', $reqparts) . ')';
            }
            $gradeop = ($rule['grade_operator'] ?? self::ACTIVITY_ALL) === self::ACTIVITY_ANY
                ? get_string('prerequisite_activity_any', 'local_tm_course')
                : get_string('prerequisite_activity_all', 'local_tm_course');
            return get_string('prerequisite_label_grades', 'local_tm_course', (object)[
                'course' => $cname,
                'operator' => $gradeop,
                'conditions' => !empty($parts) ? implode('; ', $parts) : '—',
            ]);
        }
        $cmids = array_map('intval', (array)($rule['cmids'] ?? []));
        $labels = self::get_cm_display_names($cmids);
        $actop = ($rule['activity_operator'] ?? self::ACTIVITY_ALL) === self::ACTIVITY_ANY
            ? get_string('prerequisite_activity_any', 'local_tm_course')
            : get_string('prerequisite_activity_all', 'local_tm_course');
        $activitylist = !empty($labels) ? implode(', ', $labels) : get_string('prerequisite_activities', 'local_tm_course');
        return get_string('prerequisite_label_activities', 'local_tm_course', (object)[
            'course' => $cname,
            'operator' => $actop,
            'activities' => $activitylist,
        ]);
    }

    /**
     * @param array $rule normalized grade rule row
     * @return string[]
     */
    private static function explain_grade_rule_failures(int $userid, array $rule): array {
        $courseid = (int)$rule['courseid'];
        $conditions = (array)($rule['grade_conditions'] ?? []);
        if (empty($conditions)) {
            return [get_string('prerequisite_reason_grade_none', 'local_tm_course')];
        }
        $reasons = [];
        foreach ($conditions as $cond) {
            $cmid = (int)($cond['cmid'] ?? 0);
            if ($cmid <= 0) {
                continue;
            }
            $min = array_key_exists('min', $cond) ? $cond['min'] : null;
            $max = array_key_exists('max', $cond) ? $cond['max'] : null;
            if (self::user_meets_grade_condition($courseid, $cmid, $userid, $min, $max)) {
                continue;
            }
            $reasons[] = self::format_grade_condition_failure_reason($courseid, $cmid, $userid, $min, $max);
        }
        return $reasons;
    }

    private static function format_grade_condition_failure_reason(
        int $courseid,
        int $cmid,
        int $userid,
        ?float $minpercent,
        ?float $maxpercent
    ): string {
        $names = self::get_cm_display_names([$cmid]);
        $activity = !empty($names) ? $names[0] : get_string('prerequisite_activities', 'local_tm_course');
        $percent = self::get_user_cm_grade_percentage($courseid, $cmid, $userid);
        $current = $percent !== null ? round($percent, 1) : null;
        $params = (object)['activity' => $activity];

        if ($minpercent !== null && $maxpercent !== null) {
            $params->min = $minpercent;
            $params->max = $maxpercent;
            if ($current === null) {
                return get_string('prerequisite_reason_grade_range_none', 'local_tm_course', $params);
            }
            $params->current = $current;
            return get_string('prerequisite_reason_grade_range', 'local_tm_course', $params);
        }
        if ($minpercent !== null) {
            $params->required = $minpercent;
            if ($current === null) {
                return get_string('prerequisite_reason_grade_min_none', 'local_tm_course', $params);
            }
            $params->current = $current;
            return get_string('prerequisite_reason_grade_min', 'local_tm_course', $params);
        }
        if ($maxpercent !== null) {
            $params->required = $maxpercent;
            if ($current === null) {
                return get_string('prerequisite_reason_grade_max_none', 'local_tm_course', $params);
            }
            $params->current = $current;
            return get_string('prerequisite_reason_grade_max', 'local_tm_course', $params);
        }
        return get_string('prerequisite_reason_grade_unknown', 'local_tm_course', $activity);
    }

    /**
     * @param array $rule normalized activity rule row
     * @return string[]
     */
    private static function explain_activity_rule_failures(int $userid, array $rule, \completion_info $completion): array {
        $cmids = array_map('intval', (array)($rule['cmids'] ?? []));
        if (empty($cmids)) {
            return [get_string('prerequisite_reason_activity_none', 'local_tm_course')];
        }
        $names = self::get_cm_display_names($cmids);
        $reasons = [];
        foreach ($cmids as $idx => $cmid) {
            if (self::is_activity_complete($completion, $cmid, $userid)) {
                continue;
            }
            $activity = $names[$idx] ?? (get_string('prerequisite_activities', 'local_tm_course') . ' #' . $cmid);
            $reasons[] = get_string('prerequisite_reason_activity_incomplete', 'local_tm_course', $activity);
        }
        return $reasons;
    }

    private static function is_activity_complete(\completion_info $completion, int $cmid, int $userid): bool {
        global $DB;
        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id,course,deletioninprogress', IGNORE_MISSING);
        if (!$cm || !empty($cm->deletioninprogress)) {
            return false;
        }
        $data = $completion->get_data((object)$cm, false, $userid);
        $state = (int)($data->completionstate ?? 0);
        return in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true);
    }

    private static function cmid_belongs_to_course(int $cmid, int $courseid): bool {
        global $DB;
        $row = $DB->get_record('course_modules', ['id' => $cmid], 'id,course,deletioninprogress', IGNORE_MISSING);
        return $row && empty($row->deletioninprogress) && (int)$row->course === $courseid;
    }

    /**
     * Moodle course fullname for learner-facing prerequisite messages (no activity detail).
     */
    public static function get_course_display_name(int $courseid): string {
        global $DB;
        if ($courseid <= 0) {
            return '';
        }
        $cname = $DB->get_field('course', 'fullname', ['id' => $courseid], IGNORE_MISSING);
        return $cname ? format_string($cname) : ('#' . $courseid);
    }

    /**
     * Comma-separated unique prerequisite course names configured on a session.
     *
     * @param array{operator:string,rules:array<int,array>}|null $rules
     */
    /**
     * @return string[] unique prerequisite course display names (learner-facing).
     *
     * @param array{operator:string,rules:array<int,array>}|null $rules
     */
    public static function get_learner_required_course_names(?array $rules): array {
        if ($rules === null || empty($rules['rules'])) {
            return [];
        }
        $names = [];
        foreach ($rules['rules'] as $rule) {
            $cid = (int)($rule['courseid'] ?? 0);
            if ($cid > 0) {
                $names[$cid] = self::get_course_display_name($cid);
            }
        }
        return array_values(array_filter($names));
    }

    /**
     * @return string[]
     */
    public static function get_learner_required_course_names_for_session(\stdClass $session): array {
        return self::get_learner_required_course_names(self::resolve_session_rules($session));
    }

    public static function format_learner_required_course_list(?array $rules): string {
        $names = self::get_learner_required_course_names($rules);
        if (empty($names)) {
            return '';
        }
        $sep = get_string('prerequisite_learner_name_separator', 'local_tm_course');
        return implode($sep, $names);
    }

    /**
     * Comma-separated unique prerequisite course names from evaluate_user() missing rows.
     *
     * @param array<int,array{courseid?:int,label?:string}> $missing
     */
    public static function format_missing_course_name_list(array $missing): string {
        $names = [];
        foreach ($missing as $item) {
            $cid = (int)($item['courseid'] ?? 0);
            if ($cid > 0) {
                $names[$cid] = self::get_course_display_name($cid);
            }
        }
        $names = array_values(array_filter($names));
        if (empty($names)) {
            return '';
        }
        $sep = get_string('prerequisite_learner_name_separator', 'local_tm_course');
        return implode($sep, $names);
    }

    /**
     * Learner-facing unmet message (course names only; hides activity-level rules).
     *
     * @param array{operator:string,rules:array<int,array>}|null $rules
     * @param array<int,array{courseid?:int,label?:string}> $missing
     */
    public static function format_learner_unmet_message(?array $rules, array $missing): string {
        $list = self::format_missing_reason_list($missing);
        if ($list === '') {
            return get_string('error_prerequisite', 'local_tm_course');
        }
        $operator = $rules['operator'] ?? self::OPERATOR_AND;
        if ($operator === self::OPERATOR_OR) {
            return get_string('prerequisite_learner_unmet_or', 'local_tm_course', $list);
        }
        return get_string('prerequisite_learner_unmet_and', 'local_tm_course', $list);
    }

    /**
     * @return string|null null when session has no prerequisites or the user meets them
     */
    public static function get_learner_unmet_message_for_session(\stdClass $session, int $userid): ?string {
        $rules = self::resolve_session_rules($session);
        if ($rules === null) {
            return null;
        }
        $eval = self::evaluate_user($userid, $rules);
        if (!empty($eval['met'])) {
            return null;
        }
        return self::format_learner_unmet_message($rules, $eval['missing']);
    }

    /**
     * @throws \moodle_exception when prerequisites are configured and not met
     */
    public static function assert_learner_prerequisites(\stdClass $session, int $userid): void {
        $rules = self::resolve_session_rules($session);
        if ($rules === null) {
            return;
        }
        $eval = self::evaluate_user($userid, $rules);
        if (!empty($eval['met'])) {
            return;
        }
        $list = self::format_missing_reason_list($eval['missing']);
        $key = ($rules['operator'] ?? self::OPERATOR_AND) === self::OPERATOR_OR
            ? 'prerequisite_learner_unmet_or'
            : 'prerequisite_learner_unmet_and';
        throw new \moodle_exception($key, 'local_tm_course', '', $list);
    }
}
