<?php
/**
 * Course survey definitions, versions, and course assignment.
 *
 * Learner submission, QR, and reports are later phases. This class owns the
 * editable questionnaire and the freeze / pin rules from SPEC §59.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class survey_manager {

    public const TYPE_SINGLE = 'single';
    public const TYPE_MULTI = 'multi';
    public const TYPE_SCALE = 'scale';
    public const TYPE_TEXT = 'text';

    /**
     * @return string[]
     */
    public static function question_types(): array {
        return [self::TYPE_SINGLE, self::TYPE_MULTI, self::TYPE_SCALE, self::TYPE_TEXT];
    }

    /**
     * Create an empty survey and its first unfrozen version.
     */
    public static function create_survey(string $name, int $createdby): int {
        global $DB;
        $name = self::clean_name($name);
        if ($name === '') {
            throw new \moodle_exception('survey_error_name_required', 'local_tm_course');
        }
        $now = time();
        $survey = (object) [
            'name' => $name,
            'enabled' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => $createdby,
        ];
        $id = (int) $DB->insert_record('local_tm_course_svdef', $survey);
        $DB->insert_record('local_tm_course_svver', (object) [
            'surveyid' => $id,
            'versionno' => 1,
            'frozen' => 0,
            'timecreated' => $now,
            'createdby' => $createdby,
        ]);
        return $id;
    }

    public static function update_name(int $surveyid, string $name): void {
        global $DB;
        $name = self::clean_name($name);
        if ($name === '') {
            throw new \moodle_exception('survey_error_name_required', 'local_tm_course');
        }
        $DB->update_record('local_tm_course_svdef', (object) [
            'id' => $surveyid,
            'name' => $name,
            'timemodified' => time(),
        ]);
    }

    public static function set_enabled(int $surveyid, bool $enabled): void {
        global $DB;
        $DB->update_record('local_tm_course_svdef', (object) [
            'id' => $surveyid,
            'enabled' => $enabled ? 1 : 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * @return \stdClass[] newest id first
     */
    public static function list_surveys(): array {
        global $DB;
        $surveys = $DB->get_records('local_tm_course_svdef', null, 'timemodified DESC, id DESC');
        foreach ($surveys as $survey) {
            $version = self::current_version((int) $survey->id);
            $survey->versionid = $version ? (int) $version->id : 0;
            $survey->versionno = $version ? (int) $version->versionno : 0;
            $survey->versionfrozen = $version ? self::is_version_frozen((int) $version->id) : false;
            $survey->coursecount = $DB->count_records('local_tm_course_svcrs', ['surveyid' => $survey->id]);
        }
        return $surveys;
    }

    public static function get_survey(int $surveyid): \stdClass {
        global $DB;
        return $DB->get_record('local_tm_course_svdef', ['id' => $surveyid], '*', MUST_EXIST);
    }

    /**
     * @return \stdClass[] oldest version first
     */
    public static function list_versions(int $surveyid): array {
        global $DB;
        $versions = $DB->get_records('local_tm_course_svver', ['surveyid' => $surveyid], 'versionno ASC');
        foreach ($versions as $version) {
            $version->isfrozen = self::is_version_frozen((int) $version->id);
        }
        return $versions;
    }

    public static function current_version(int $surveyid): ?\stdClass {
        global $DB;
        $records = $DB->get_records('local_tm_course_svver', ['surveyid' => $surveyid], 'versionno DESC', '*', 0, 1);
        if (!$records) {
            return null;
        }
        return reset($records);
    }

    /**
     * A version is frozen once a session pin or a formal response points at it.
     */
    public static function is_version_frozen(int $versionid): bool {
        global $DB;
        if ($versionid <= 0) {
            return false;
        }
        if ($DB->record_exists('local_tm_course_svpin', ['versionid' => $versionid])) {
            return true;
        }
        return $DB->record_exists('local_tm_course_svresp', ['versionid' => $versionid]);
    }

    /**
     * @return int[] course ids assigned to this survey
     */
    public static function assigned_courseids(int $surveyid): array {
        global $DB;
        $rows = $DB->get_records('local_tm_course_svcrs', ['surveyid' => $surveyid], 'courseid ASC', 'id, courseid');
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->courseid;
        }
        return $ids;
    }

    /**
     * One course has at most one assignment row. Assigning moves the course off any other survey.
     */
    public static function assign_course(int $surveyid, int $courseid): void {
        global $DB;
        if ($surveyid <= 0 || $courseid <= 0) {
            throw new \moodle_exception('survey_error_notfound', 'local_tm_course');
        }
        self::get_survey($surveyid);
        $existing = $DB->get_record('local_tm_course_svcrs', ['courseid' => $courseid]);
        $now = time();
        if ($existing) {
            $DB->update_record('local_tm_course_svcrs', (object) [
                'id' => $existing->id,
                'surveyid' => $surveyid,
                'timemodified' => $now,
            ]);
            return;
        }
        $DB->insert_record('local_tm_course_svcrs', (object) [
            'courseid' => $courseid,
            'surveyid' => $surveyid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public static function unassign_course(int $courseid): void {
        global $DB;
        $DB->delete_records('local_tm_course_svcrs', ['courseid' => $courseid]);
    }

    /**
     * Replace this survey's course list. Courses removed here are unassigned.
     * Courses added here are moved from any other survey.
     *
     * @param int[] $courseids
     */
    public static function set_course_assignments(int $surveyid, array $courseids): void {
        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids))));
        $current = self::assigned_courseids($surveyid);
        foreach ($current as $courseid) {
            if (!in_array($courseid, $courseids, true)) {
                self::unassign_course($courseid);
            }
        }
        foreach ($courseids as $courseid) {
            self::assign_course($surveyid, $courseid);
        }
    }

    /**
     * Enabled survey currently assigned to a course, if any.
     */
    public static function active_surveyid_for_course(int $courseid): int {
        global $DB;
        $row = $DB->get_record('local_tm_course_svcrs', ['courseid' => $courseid]);
        if (!$row) {
            return 0;
        }
        $survey = $DB->get_record('local_tm_course_svdef', ['id' => $row->surveyid, 'enabled' => 1]);
        return $survey ? (int) $survey->id : 0;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_version_structure(int $versionid): array {
        global $DB;
        $sections = $DB->get_records('local_tm_course_svsec', ['versionid' => $versionid], 'sortorder ASC, id ASC');
        $items = $DB->get_records('local_tm_course_svitem', ['versionid' => $versionid], 'sortorder ASC, id ASC');
        $bysection = [];
        $loose = [];
        foreach ($sections as $section) {
            $bysection[(int) $section->id] = [
                'id' => (int) $section->id,
                'name' => (string) $section->name,
                'sortorder' => (int) $section->sortorder,
                'items' => [],
            ];
        }
        foreach ($items as $item) {
            $options = $DB->get_records('local_tm_course_svopt', ['itemid' => $item->id], 'sortorder ASC, id ASC');
            $optrows = [];
            foreach ($options as $option) {
                $optrows[] = [
                    'id' => (int) $option->id,
                    'stablekey' => (string) $option->stablekey,
                    'label' => (string) $option->label,
                    'sortorder' => (int) $option->sortorder,
                    'is_other' => (int) $option->is_other,
                ];
            }
            $row = [
                'id' => (int) $item->id,
                'stablekey' => (string) $item->stablekey,
                'qtype' => (string) $item->qtype,
                'title' => (string) $item->title,
                'help' => (string) ($item->helptext ?? ''),
                'required' => (int) $item->required,
                'sortorder' => (int) $item->sortorder,
                'scalemin' => (string) ($item->scalemin ?? ''),
                'scalemax' => (string) ($item->scalemax ?? ''),
                'allowother' => (int) $item->allowother,
                'options' => $optrows,
            ];
            $sectionid = (int) ($item->sectionid ?? 0);
            if ($sectionid > 0 && isset($bysection[$sectionid])) {
                $bysection[$sectionid]['items'][] = $row;
            } else {
                $loose[] = $row;
            }
        }
        $out = array_values($bysection);
        if ($loose) {
            $out[] = [
                'id' => 0,
                'name' => '',
                'sortorder' => 100000,
                'items' => $loose,
            ];
        }
        return $out;
    }

    /**
     * Save the editor payload onto the current version, or onto a new version when the current one is frozen.
     *
     * @param array<int,array<string,mixed>> $sections
     * @return int version id that now holds the saved structure
     */
    public static function save_structure(int $surveyid, array $sections, int $actorid): int {
        global $DB;
        $version = self::current_version($surveyid);
        if (!$version) {
            throw new \moodle_exception('survey_error_notfound', 'local_tm_course');
        }
        $normalised = self::normalise_sections($sections);
        $transaction = $DB->start_delegated_transaction();
        if (self::is_version_frozen((int) $version->id)) {
            $DB->set_field('local_tm_course_svver', 'frozen', 1, ['id' => $version->id]);
            $versionid = (int) $DB->insert_record('local_tm_course_svver', (object) [
                'surveyid' => $surveyid,
                'versionno' => ((int) $version->versionno) + 1,
                'frozen' => 0,
                'timecreated' => time(),
                'createdby' => $actorid,
            ]);
        } else {
            $versionid = (int) $version->id;
            self::delete_version_content($versionid);
        }
        self::write_sections($versionid, $normalised);
        $DB->set_field('local_tm_course_svdef', 'timemodified', time(), ['id' => $surveyid]);
        $transaction->allow_commit();
        return $versionid;
    }

    /**
     * Pin the survey version when the session start time has been reached.
     * Existing pins are left unchanged. Returns the pin id, or 0 when not yet pinnable.
     */
    public static function ensure_session_survey_pin(int $sessionid): int {
        global $DB;
        if ($sessionid <= 0) {
            return 0;
        }
        $existing = $DB->get_record('local_tm_course_svpin', ['sessionid' => $sessionid]);
        if ($existing) {
            return (int) $existing->id;
        }
        $session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid]);
        if (!$session || time() < (int) $session->starttime) {
            return 0;
        }
        $surveyid = self::active_surveyid_for_course((int) $session->courseid);
        if ($surveyid <= 0) {
            return 0;
        }
        $version = self::current_version($surveyid);
        if (!$version) {
            return 0;
        }
        return (int) $DB->insert_record('local_tm_course_svpin', (object) [
            'sessionid' => $sessionid,
            'versionid' => (int) $version->id,
            'opens_at' => (int) $session->starttime,
            'timecreated' => time(),
        ]);
    }

    /**
     * Before a session start time is stored: pin the old open instant when it has already passed,
     * and audit a change that happens after the questionnaire is open.
     *
     * Does not update local_tm_course_sessions. The caller still writes the new start time.
     */
    public static function lock_pin_before_starttime_edit(int $sessionid, int $oldstart, int $newstart, int $actorid): void {
        global $DB;
        if ($sessionid <= 0 || $oldstart === $newstart) {
            return;
        }
        $pin = $DB->get_record('local_tm_course_svpin', ['sessionid' => $sessionid]);
        if (!$pin && time() >= $oldstart) {
            $session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid]);
            if ($session) {
                $surveyid = self::active_surveyid_for_course((int) $session->courseid);
                $version = $surveyid > 0 ? self::current_version($surveyid) : null;
                if ($version) {
                    $pinid = $DB->insert_record('local_tm_course_svpin', (object) [
                        'sessionid' => $sessionid,
                        'versionid' => (int) $version->id,
                        'opens_at' => $oldstart,
                        'timecreated' => time(),
                    ]);
                    $pin = $DB->get_record('local_tm_course_svpin', ['id' => $pinid], '*', MUST_EXIST);
                }
            }
        }
        if (!$pin) {
            return;
        }
        $DB->insert_record('local_tm_course_svaud', (object) [
            'sessionid' => $sessionid,
            'oldstart' => $oldstart,
            'newstart' => $newstart,
            'actorid' => $actorid,
            'timecreated' => time(),
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @return array<int,array<string,mixed>>
     */
    public static function normalise_sections(array $sections): array {
        $out = [];
        $sorder = 0;
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $items = [];
            $iorder = 0;
            foreach ($section['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $title = trim(clean_param((string) ($item['title'] ?? ''), PARAM_TEXT));
                if ($title === '') {
                    continue;
                }
                $qtype = (string) ($item['qtype'] ?? '');
                if (!in_array($qtype, self::question_types(), true)) {
                    throw new \moodle_exception('survey_error_bad_type', 'local_tm_course');
                }
                $allowother = ($qtype === self::TYPE_MULTI && !empty($item['allowother'])) ? 1 : 0;
                $options = [];
                if ($qtype === self::TYPE_SINGLE || $qtype === self::TYPE_MULTI) {
                    $oorder = 0;
                    foreach ($item['options'] ?? [] as $option) {
                        if (!is_array($option)) {
                            continue;
                        }
                        $label = trim(clean_param((string) ($option['label'] ?? ''), PARAM_TEXT));
                        $isother = ($allowother && !empty($option['isother'])) ? 1 : 0;
                        if ($label === '' && !$isother) {
                            continue;
                        }
                        if ($label === '' && $isother) {
                            $label = get_string('survey_option_other', 'local_tm_course');
                        }
                        $options[] = [
                            'stablekey' => self::keep_key((string) ($option['stablekey'] ?? '')),
                            'label' => $label,
                            'sortorder' => $oorder,
                            'isother' => $isother,
                        ];
                        $oorder++;
                    }
                    if ($allowother && !self::options_have_other($options)) {
                        $options[] = [
                            'stablekey' => self::new_stablekey(),
                            'label' => get_string('survey_option_other', 'local_tm_course'),
                            'sortorder' => $oorder,
                            'isother' => 1,
                        ];
                    }
                    if (!$options) {
                        throw new \moodle_exception('survey_error_options_required', 'local_tm_course', $title);
                    }
                }
                $items[] = [
                    'stablekey' => self::keep_key((string) ($item['stablekey'] ?? '')),
                    'qtype' => $qtype,
                    'title' => $title,
                    'help' => trim(clean_param((string) ($item['help'] ?? ''), PARAM_TEXT)),
                    'required' => !empty($item['required']) ? 1 : 0,
                    'sortorder' => $iorder,
                    'scalemin' => trim(clean_param((string) ($item['scalemin'] ?? ''), PARAM_TEXT)),
                    'scalemax' => trim(clean_param((string) ($item['scalemax'] ?? ''), PARAM_TEXT)),
                    'allowother' => $allowother,
                    'options' => $options,
                ];
                $iorder++;
            }
            $name = trim(clean_param((string) ($section['name'] ?? ''), PARAM_TEXT));
            if ($name === '' && !$items) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'sortorder' => $sorder,
                'items' => $items,
            ];
            $sorder++;
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $options
     */
    private static function options_have_other(array $options): bool {
        foreach ($options as $option) {
            if (!empty($option['isother'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     */
    private static function write_sections(int $versionid, array $sections): void {
        global $DB;
        foreach ($sections as $section) {
            $sectionid = (int) $DB->insert_record('local_tm_course_svsec', (object) [
                'versionid' => $versionid,
                'name' => $section['name'],
                'sortorder' => (int) $section['sortorder'],
            ]);
            foreach ($section['items'] as $item) {
                $itemid = (int) $DB->insert_record('local_tm_course_svitem', (object) [
                    'versionid' => $versionid,
                    'sectionid' => $sectionid,
                    'stablekey' => $item['stablekey'],
                    'qtype' => $item['qtype'],
                    'title' => $item['title'],
                    'helptext' => $item['help'] !== '' ? $item['help'] : null,
                    'required' => (int) $item['required'],
                    'sortorder' => (int) $item['sortorder'],
                    'scalemin' => $item['scalemin'] !== '' ? $item['scalemin'] : null,
                    'scalemax' => $item['scalemax'] !== '' ? $item['scalemax'] : null,
                    'allowother' => (int) $item['allowother'],
                ]);
                foreach ($item['options'] as $option) {
                    $DB->insert_record('local_tm_course_svopt', (object) [
                        'itemid' => $itemid,
                        'stablekey' => $option['stablekey'],
                        'label' => $option['label'],
                        'sortorder' => (int) $option['sortorder'],
                        'isother' => (int) $option['isother'],
                    ]);
                }
            }
        }
    }

    private static function delete_version_content(int $versionid): void {
        global $DB;
        $items = $DB->get_records('local_tm_course_svitem', ['versionid' => $versionid], '', 'id');
        foreach ($items as $item) {
            $DB->delete_records('local_tm_course_svopt', ['itemid' => $item->id]);
        }
        $DB->delete_records('local_tm_course_svitem', ['versionid' => $versionid]);
        $DB->delete_records('local_tm_course_svsec', ['versionid' => $versionid]);
    }

    private static function clean_name(string $name): string {
        $name = trim(clean_param($name, PARAM_TEXT));
        if (\core_text::strlen($name) > 255) {
            $name = \core_text::substr($name, 0, 255);
        }
        return $name;
    }

    private static function keep_key(string $key): string {
        $key = trim(clean_param($key, PARAM_ALPHANUMEXT));
        if ($key === '' || \core_text::strlen($key) > 40) {
            return self::new_stablekey();
        }
        return $key;
    }

    private static function new_stablekey(): string {
        return 'k' . bin2hex(random_bytes(8));
    }
}
