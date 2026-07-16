<?php
/**
 * Classroom CRUD for TM physical rooms.
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class classroom_manager {

    /** @var int Minimum tables per classroom */
    const MIN_TABLES = 1;

    /** @var int Maximum tables per classroom (desk_number column supports 3 digits) */
    const MAX_TABLES = 99;

    /** Default install value if DB column missing (upgrade pending). */
    const DEFAULT_TABLE_COUNT = 6;

    /**
     * Safe table_count for display / logic (avoids notices if column absent).
     */
    public static function table_count(\stdClass $r): int {
        return isset($r->table_count) ? (int) $r->table_count : self::DEFAULT_TABLE_COUNT;
    }

    /**
     * @return \stdClass[]
     */
    public static function get_all(): array {
        global $DB;
        $rows = $DB->get_records('local_tm_classroom', [], 'name ASC');
        foreach ($rows as $id => $r) {
            if (!isset($r->table_count)) {
                $r->table_count = self::DEFAULT_TABLE_COUNT;
            }
        }
        return $rows;
    }

    /**
     * @return array (int)id => display string for dropdowns
     */
    public static function get_menu_options(): array {
        $out = [];
        foreach (self::get_all() as $r) {
            $label = $r->name;
            if (!empty(trim($r->location ?? ''))) {
                $label .= ' — ' . $r->location;
            }
            $out[(int) $r->id] = $label;
        }
        return $out;
    }

    public static function get(int $id): \stdClass {
        global $DB;
        $r = $DB->get_record('local_tm_classroom', ['id' => $id], '*', MUST_EXIST);
        if (!isset($r->table_count)) {
            $r->table_count = self::DEFAULT_TABLE_COUNT;
        }
        return $r;
    }

    /**
     * @throws \moodle_exception
     */
    public static function validate_data(array $data): void {
        if (empty(trim($data['name'] ?? ''))) {
            throw new \moodle_exception('error_classroom_name', 'local_tm_course');
        }
        $tc = (int) ($data['table_count'] ?? 0);
        if ($tc < self::MIN_TABLES || $tc > self::MAX_TABLES) {
            throw new \moodle_exception('error_classroom_tables_range', 'local_tm_course');
        }
    }

    public static function create(array $data): int {
        global $DB;
        self::validate_data($data);
        $rec = new \stdClass();
        $rec->name = clean_param($data['name'], PARAM_TEXT);
        $rec->location = clean_param($data['location'] ?? '', PARAM_TEXT);
        $rec->tcms_location = self::clean_tcms_location($data['tcms_location'] ?? '');
        $rec->table_count = (int) $data['table_count'];
        $rec->timecreated = time();
        $rec->timemodified = time();
        return (int) $DB->insert_record('local_tm_classroom', $rec);
    }

    public static function update(int $id, array $data): void {
        global $DB;
        self::validate_data($data);
        $rec = self::get($id);
        $rec->name = clean_param($data['name'], PARAM_TEXT);
        $rec->location = clean_param($data['location'] ?? '', PARAM_TEXT);
        $rec->tcms_location = self::clean_tcms_location($data['tcms_location'] ?? '');
        $rec->table_count = (int) $data['table_count'];
        $rec->timemodified = time();
        $DB->update_record('local_tm_classroom', $rec);
    }

    private static function clean_tcms_location($value): ?string {
        $v = trim((string) $value);
        return $v !== '' ? clean_param($v, PARAM_TEXT) : null;
    }

    /**
     * TCMS location label chosen for a classroom (empty when unset).
     */
    public static function get_tcms_location(int $id): string {
        global $DB;
        if ($id <= 0) {
            return '';
        }
        $v = $DB->get_field('local_tm_classroom', 'tcms_location', ['id' => $id]);
        return ($v === false || $v === null) ? '' : trim((string) $v);
    }

    public static function delete(int $id): void {
        global $DB;
        if ($DB->record_exists('local_tm_course_sessions', ['classroomid' => $id])) {
            throw new \moodle_exception('error_classroom_in_use', 'local_tm_course');
        }
        $DB->delete_records('local_tm_classroom', ['id' => $id]);
    }

    public static function session_location_label(\stdClass $classroom): string {
        $name = trim($classroom->name);
        $loc = trim($classroom->location ?? '');
        if ($loc === '') {
            return $name;
        }
        return $name . ' — ' . $loc;
    }
}
