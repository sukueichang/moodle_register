<?php
/**
 * Moodle courses enabled for TM session "linked course" picker.
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/prerequisite_manager.php');

class enabled_course_manager {

    /**
     * @return int[]
     */
    public static function get_enabled_ids(): array {
        global $DB;
        $ids = $DB->get_fieldset_select('local_tm_enabled_courses', 'courseid', '1=1', []);
        $ids = array_map('intval', $ids);
        sort($ids);
        return $ids;
    }

    public static function is_enabled(int $courseid): bool {
        global $DB;
        return $DB->record_exists('local_tm_enabled_courses', ['courseid' => $courseid]);
    }

    /**
     * Replace enabled courses and their mode-specific defaults for reservation/scheduling.
     *
     * @param array<int,array{
     *   courseid:int,
     *   default_duration_hours?:float,
     *   default_duration_hours_onsite?:float,
     *   default_duration_hours_online?:float,
     *   allow_onsite?:int|bool,
     *   allow_online?:int|bool,
     *   allowed_classroomids?:array<int>,
     *   online_classroomid?:int|null
     * }> $entries
     */
    public static function save_enabled(array $entries): void {
        global $DB;
        $preservedprereq = [];
        $preservedtcmstype = [];
        foreach ($DB->get_records('local_tm_enabled_courses', [], '', 'courseid, default_prerequisite_rules, tcms_course_type') as $row) {
            $json = trim((string)($row->default_prerequisite_rules ?? ''));
            if ($json !== '') {
                $preservedprereq[(int)$row->courseid] = $json;
            }
            $tcmstype = trim((string)($row->tcms_course_type ?? ''));
            if ($tcmstype !== '') {
                $preservedtcmstype[(int)$row->courseid] = $tcmstype;
            }
        }
        $DB->delete_records('local_tm_enabled_courses');
        foreach ($entries as $entry) {
            $cid = (int) ($entry['courseid'] ?? 0);
            if ($cid <= 0 || $cid === SITEID) {
                continue;
            }
            if (!$DB->record_exists('course', ['id' => $cid])) {
                continue;
            }
            $hours = isset($entry['default_duration_hours']) ? (float) $entry['default_duration_hours'] : 8.0;
            $hours = max(0.5, min(168.0, $hours));
            $hoursonsite = isset($entry['default_duration_hours_onsite'])
                ? (float) $entry['default_duration_hours_onsite']
                : $hours;
            $hoursonline = isset($entry['default_duration_hours_online'])
                ? (float) $entry['default_duration_hours_online']
                : $hours;
            $hoursonsite = max(0.5, min(168.0, $hoursonsite));
            $hoursonline = max(0.5, min(168.0, $hoursonline));
            $allowonsite = !empty($entry['allow_onsite']) ? 1 : 0;
            $allowonline = !empty($entry['allow_online']) ? 1 : 0;
            $allowed = [];
            if (!empty($entry['allowed_classroomids']) && is_array($entry['allowed_classroomids'])) {
                foreach ($entry['allowed_classroomids'] as $rid) {
                    $rid = (int)$rid;
                    if ($rid > 0) {
                        $allowed[$rid] = true;
                    }
                }
            }
            $rec = new \stdClass();
            $rec->courseid = $cid;
            $rec->default_duration_hours = $hours;
            $rec->default_duration_hours_onsite = $hoursonsite;
            $rec->default_duration_hours_online = $hoursonline;
            $rec->allow_onsite = $allowonsite;
            $rec->allow_online = $allowonline;
            $rec->allowed_classroomids = empty($allowed) ? null : implode(',', array_keys($allowed));
            $onlineclassroomid = isset($entry['online_classroomid']) ? (int)$entry['online_classroomid'] : 0;
            $rec->online_classroomid = $onlineclassroomid > 0 ? $onlineclassroomid : null;
            if (array_key_exists('default_prerequisite_rules', $entry)) {
                $rules = is_array($entry['default_prerequisite_rules'])
                    ? $entry['default_prerequisite_rules']
                    : null;
                if (is_string($entry['default_prerequisite_rules'])) {
                    $decoded = json_decode(trim($entry['default_prerequisite_rules']), true);
                    $rules = is_array($decoded) ? $decoded : null;
                }
                $normalized = $rules !== null ? prerequisite_manager::normalize_rules($rules) : null;
                $rec->default_prerequisite_rules = prerequisite_manager::encode_for_storage($normalized);
            } else {
                $rec->default_prerequisite_rules = $preservedprereq[$cid] ?? null;
            }
            if (array_key_exists('tcms_course_type', $entry)) {
                $t = trim((string) $entry['tcms_course_type']);
                $rec->tcms_course_type = $t !== '' ? $t : null;
            } else {
                $rec->tcms_course_type = $preservedtcmstype[$cid] ?? null;
            }
            $rec->timecreated = time();
            $DB->insert_record('local_tm_enabled_courses', $rec);
        }
    }

    /**
     * Default prerequisite rules for new sessions of a TM-enabled course.
     *
     * @return array{operator:string,rules:array<int,array>}|null
     */
    public static function get_default_prerequisite_rules(int $courseid): ?array {
        global $DB;
        if ($courseid <= 0) {
            return null;
        }
        $json = $DB->get_field('local_tm_enabled_courses', 'default_prerequisite_rules', ['courseid' => $courseid]);
        if ($json === false || trim((string)$json) === '') {
            return null;
        }
        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? prerequisite_manager::normalize_rules($decoded) : null;
    }

    /**
     * @return array<int,array{operator:string,rules:array<int,array>}|null>
     */
    public static function get_default_prerequisite_rules_map(): array {
        global $DB;
        $rows = $DB->get_records('local_tm_enabled_courses', [], '', 'courseid, default_prerequisite_rules');
        $out = [];
        foreach ($rows as $row) {
            $cid = (int)$row->courseid;
            $json = trim((string)($row->default_prerequisite_rules ?? ''));
            if ($json === '') {
                $out[$cid] = null;
                continue;
            }
            $decoded = json_decode($json, true);
            $out[$cid] = is_array($decoded) ? prerequisite_manager::normalize_rules($decoded) : null;
        }
        return $out;
    }

    /**
     * @param array{operator:string,rules:array<int,array>}|null $rules
     */
    public static function save_default_prerequisite_rules(int $courseid, ?array $rules): void {
        global $DB;
        if ($courseid <= 0 || !$DB->record_exists('local_tm_enabled_courses', ['courseid' => $courseid])) {
            throw new \moodle_exception('error_course_not_enabled', 'local_tm_course');
        }
        $normalized = $rules !== null ? prerequisite_manager::normalize_rules($rules) : null;
        prerequisite_manager::validate_rules($normalized);
        $DB->set_field(
            'local_tm_enabled_courses',
            'default_prerequisite_rules',
            prerequisite_manager::encode_for_storage($normalized),
            ['courseid' => $courseid]
        );
    }

    /**
     * @param int[] $courseids
     */
    public static function set_enabled_ids(array $courseids): void {
        $entries = [];
        foreach ($courseids as $cid) {
            $entries[] = ['courseid' => (int) $cid, 'default_duration_hours' => 8.0];
        }
        self::save_enabled($entries);
    }

    /**
     * Linked-course dropdown: id => fullname for enabled courses (visible).
     *
     * @return array<int,string>
     */
    public static function get_course_menu(): array {
        global $DB;
        $ids = self::get_enabled_ids();
        if (empty($ids)) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $sql = "SELECT id, fullname FROM {course} WHERE id $insql AND visible = 1 ORDER BY fullname ASC";
        return $DB->get_records_sql_menu($sql, $params);
    }

    /**
     * Default session length for auto scheduling (hours). Falls back to 8 when unknown.
     */
    public static function get_default_duration_hours(int $courseid, string $deliverymode = 'onsite'): float {
        global $DB;
        if ($courseid <= 0) {
            return 8.0;
        }
        if (!$DB->record_exists('local_tm_enabled_courses', ['courseid' => $courseid])) {
            return 8.0;
        }
        $field = ($deliverymode === 'online') ? 'default_duration_hours_online' : 'default_duration_hours_onsite';
        $v = $DB->get_field('local_tm_enabled_courses', $field, ['courseid' => $courseid]);
        if ($v === false || $v === null || (float)$v <= 0) {
            $v = $DB->get_field('local_tm_enabled_courses', 'default_duration_hours', ['courseid' => $courseid]);
        }
        if ($v === false) {
            return 8.0;
        }
        return max(0.5, min(168.0, (float) $v));
    }

    /**
     * @return array<int,float> courseid => default_duration_hours
     */
    public static function get_duration_map(): array {
        global $DB;
        $rows = $DB->get_records('local_tm_enabled_courses', [], '', 'courseid, default_duration_hours');
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->courseid] = max(0.5, min(168.0, (float) $r->default_duration_hours));
        }
        return $out;
    }

    /**
     * @return array<int,float> courseid => default_duration_hours_onsite
     */
    public static function get_duration_map_onsite(): array {
        global $DB;
        $rows = $DB->get_records('local_tm_enabled_courses', [], '', 'courseid, default_duration_hours_onsite, default_duration_hours');
        $out = [];
        foreach ($rows as $r) {
            $v = (float)($r->default_duration_hours_onsite ?? $r->default_duration_hours ?? 8.0);
            $out[(int) $r->courseid] = max(0.5, min(168.0, $v));
        }
        return $out;
    }

    /**
     * @return array<int,float> courseid => default_duration_hours_online
     */
    public static function get_duration_map_online(): array {
        global $DB;
        $rows = $DB->get_records('local_tm_enabled_courses', [], '', 'courseid, default_duration_hours_online, default_duration_hours');
        $out = [];
        foreach ($rows as $r) {
            $v = (float)($r->default_duration_hours_online ?? $r->default_duration_hours ?? 8.0);
            $out[(int) $r->courseid] = max(0.5, min(168.0, $v));
        }
        return $out;
    }

    /**
     * @return array<int,bool> courseid => allow_onsite
     */
    public static function get_allow_onsite_map(): array {
        global $DB;
        $rows = $DB->get_records('local_tm_enabled_courses', [], '', 'courseid, allow_onsite');
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->courseid] = (int)($r->allow_onsite ?? 1) === 1;
        }
        return $out;
    }

    /**
     * @return array<int,bool> courseid => allow_online
     */
    public static function get_allow_online_map(): array {
        global $DB;
        $rows = $DB->get_records('local_tm_enabled_courses', [], '', 'courseid, allow_online');
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->courseid] = (int)($r->allow_online ?? 1) === 1;
        }
        return $out;
    }

    /**
     * @return int[]
     */
    public static function get_enabled_ids_by_delivery_mode(string $deliverymode): array {
        global $DB;
        $field = ($deliverymode === 'online') ? 'allow_online' : 'allow_onsite';
        $ids = $DB->get_fieldset_select('local_tm_enabled_courses', 'courseid', $field . ' = 1', []);
        $ids = array_map('intval', $ids);
        sort($ids);
        return $ids;
    }

    /**
     * TCMS courseTypes label chosen for a course (empty when unset).
     */
    public static function get_tcms_course_type(int $courseid): string {
        global $DB;
        if ($courseid <= 0) {
            return '';
        }
        $v = $DB->get_field('local_tm_enabled_courses', 'tcms_course_type', ['courseid' => $courseid]);
        return ($v === false || $v === null) ? '' : trim((string) $v);
    }

    /**
     * @return array<int,string> courseid => TCMS courseTypes label
     */
    public static function get_tcms_course_type_map(): array {
        global $DB;
        $rows = $DB->get_records('local_tm_enabled_courses', [], '', 'courseid, tcms_course_type');
        $out = [];
        foreach ($rows as $r) {
            $t = trim((string) ($r->tcms_course_type ?? ''));
            if ($t !== '') {
                $out[(int) $r->courseid] = $t;
            }
        }
        return $out;
    }

    /**
     * @return array<int,array<int>> courseid => [classroomid...]
     */
    public static function get_classroom_map(): array {
        global $DB;
        $rows = $DB->get_records('local_tm_enabled_courses', [], '', 'courseid, allowed_classroomids');
        $out = [];
        foreach ($rows as $r) {
            $cid = (int)$r->courseid;
            $out[$cid] = [];
            $csv = trim((string)($r->allowed_classroomids ?? ''));
            if ($csv === '') {
                continue;
            }
            $parts = explode(',', $csv);
            $seen = [];
            foreach ($parts as $p) {
                $rid = (int)trim($p);
                if ($rid > 0 && empty($seen[$rid])) {
                    $seen[$rid] = true;
                    $out[$cid][] = $rid;
                }
            }
        }
        return $out;
    }

    /**
     * @return array<int,int> courseid => online_classroomid (>0)
     */
    public static function get_online_classroom_map(): array {
        global $DB;
        $rows = $DB->get_records('local_tm_enabled_courses', [], '', 'courseid, online_classroomid');
        $out = [];
        foreach ($rows as $r) {
            $rid = (int)($r->online_classroomid ?? 0);
            if ($rid > 0) {
                $out[(int)$r->courseid] = $rid;
            }
        }
        return $out;
    }

    public static function get_online_classroom_id(int $courseid): int {
        global $DB;
        if ($courseid <= 0) {
            return 0;
        }
        $rid = $DB->get_field('local_tm_enabled_courses', 'online_classroomid', ['courseid' => $courseid]);
        return ($rid !== false && (int)$rid > 0) ? (int)$rid : 0;
    }

    /**
     * @param int[] $courseids
     * @return int[] course ids missing online_classroomid
     */
    public static function get_missing_online_classroom_course_ids(array $courseids): array {
        $missing = [];
        foreach ($courseids as $cid) {
            $cid = (int)$cid;
            if ($cid > 0 && self::get_online_classroom_id($cid) <= 0) {
                $missing[] = $cid;
            }
        }
        return $missing;
    }

    /**
     * @param int[] $courseids
     * @param array<int,string> $idtoname optional course id => display name
     */
    public static function format_missing_online_classroom_error(array $courseids, array $idtoname = []): string {
        global $DB;
        $names = [];
        foreach ($courseids as $cid) {
            $cid = (int)$cid;
            if ($cid <= 0) {
                continue;
            }
            if (!empty($idtoname[$cid])) {
                $names[] = $idtoname[$cid];
                continue;
            }
            $name = $DB->get_field('course', 'fullname', ['id' => $cid]);
            $names[] = $name ? format_string((string)$name) : ('#' . $cid);
        }
        if (empty($names)) {
            return get_string('reservation_calendar_error_online_classroom_unconfigured', 'local_tm_course');
        }
        return get_string(
            'reservation_error_online_classroom_unconfigured_list',
            'local_tm_course',
            implode('、', $names)
        );
    }

    /**
     * Resolve classroom for reservation calendar pre-scheduling.
     * Online: admin-configured online_classroomid only (no list fallback).
     */
    public static function resolve_plan_classroom(
        int $courseid,
        string $deliverymode,
        int $preferredclassroomid = 0,
        int $fallbackclassroomid = 0
    ): int {
        global $DB;

        if ($courseid <= 0) {
            return 0;
        }

        if ($deliverymode === 'online') {
            $rid = self::get_online_classroom_id($courseid);
            if ($rid > 0 && $DB->record_exists('local_tm_classroom', ['id' => $rid])) {
                return $rid;
            }
            return 0;
        }

        $classroommap = self::get_classroom_map();
        $allowed = $classroommap[$courseid] ?? [];
        if (!empty($allowed) && $preferredclassroomid > 0 && in_array($preferredclassroomid, $allowed, true)) {
            return $preferredclassroomid;
        }
        if (!empty($allowed)) {
            return (int)reset($allowed);
        }
        if ($preferredclassroomid > 0 && $DB->record_exists('local_tm_classroom', ['id' => $preferredclassroomid])) {
            return $preferredclassroomid;
        }
        if ($fallbackclassroomid > 0 && $DB->record_exists('local_tm_classroom', ['id' => $fallbackclassroomid])) {
            return $fallbackclassroomid;
        }
        return 0;
    }
}
