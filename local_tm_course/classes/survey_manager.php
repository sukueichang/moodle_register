<?php
/**
 * Course survey definitions, versions, pin/open rules, and learner submission.
 *
 * Phase 3+4: email quick-access tokens, QR board helpers, mapped responses (SPEC §59).
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class survey_manager {

    /** my_records / survey.php UI states (SPEC §59.6). */
    public const STATE_NONE = 'none';
    public const STATE_NOT_OPEN = 'not_open';
    public const STATE_FILL = 'fill';
    public const STATE_VIEW = 'view';

    public const TYPE_SINGLE = 'single';
    public const TYPE_MULTI = 'multi';
    public const TYPE_SCALE = 'scale';
    public const TYPE_TEXT = 'text';

    /** Response mapping flags (svresp.mapped). */
    public const MAPPED = 1;
    public const UNMAPPED = 0;

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
                    'isother' => (int) $option->isother,
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
        $pinid = (int) $DB->insert_record('local_tm_course_svpin', (object) [
            'sessionid' => $sessionid,
            'versionid' => (int) $version->id,
            'opens_at' => (int) $session->starttime,
            'timecreated' => time(),
        ]);
        if ($pinid > 0) {
            self::ensure_session_survey_token($sessionid, true);
        }
        return $pinid;
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
                    self::ensure_session_survey_token($sessionid, true);
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
                $allowother = (
                    ($qtype === self::TYPE_SINGLE || $qtype === self::TYPE_MULTI)
                    && !empty($item['allowother'])
                ) ? 1 : 0;
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

    /**
     * Real learner user id for an enrolment row, or 0 when unbound placeholder.
     */
    public static function learner_userid_for_enrol(\stdClass $enrol): int {
        $linked = (int) ($enrol->linked_userid ?? 0);
        if ($linked > 0) {
            return $linked;
        }
        $userid = (int) ($enrol->userid ?? 0);
        if ($userid <= 0) {
            return 0;
        }
        if (enrolment_manager::is_placeholder_holder_userid($userid)) {
            return 0;
        }
        return $userid;
    }

    /**
     * Enrolment is bound to a real learner (approved-fill denominator base).
     */
    public static function is_enrolment_bound(\stdClass $enrol): bool {
        return self::learner_userid_for_enrol($enrol) > 0;
    }

    public static function get_pin(int $sessionid): ?\stdClass {
        global $DB;
        if ($sessionid <= 0) {
            return null;
        }
        $pin = $DB->get_record('local_tm_course_svpin', ['sessionid' => $sessionid]);
        return $pin ?: null;
    }

    public static function get_response_by_enrolid(int $enrolid): ?\stdClass {
        global $DB;
        if ($enrolid <= 0) {
            return null;
        }
        $row = $DB->get_record('local_tm_course_svresp', ['enrolid' => $enrolid]);
        return $row ?: null;
    }

    /**
     * Whether the session questionnaire is open for fill-in (SPEC §59.5).
     * Does not require attendance.
     */
    public static function is_session_survey_open(int $sessionid): bool {
        global $DB;
        if ($sessionid <= 0) {
            return false;
        }
        self::ensure_session_survey_pin($sessionid);
        $pin = self::get_pin($sessionid);
        if ($pin) {
            return time() >= (int) $pin->opens_at;
        }
        $session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid], 'id, starttime, courseid');
        if (!$session) {
            return false;
        }
        if (time() < (int) $session->starttime) {
            return false;
        }
        return self::active_surveyid_for_course((int) $session->courseid) > 0;
    }

    /**
     * Find this user's enrolment on a session (direct userid or linked_userid).
     */
    public static function find_user_enrolment_for_session(int $sessionid, int $userid): ?\stdClass {
        global $DB;
        if ($sessionid <= 0 || $userid <= 0) {
            return null;
        }
        $sql = "SELECT e.*
                  FROM {local_tm_course_enrolments} e
                 WHERE e.sessionid = :sessionid
                   AND (e.userid = :uid1 OR e.linked_userid = :uid2)
              ORDER BY e.id ASC";
        $rows = $DB->get_records_sql($sql, [
            'sessionid' => $sessionid,
            'uid1' => $userid,
            'uid2' => $userid,
        ]);
        foreach ($rows as $enrol) {
            if (self::learner_userid_for_enrol($enrol) === $userid) {
                return $enrol;
            }
        }
        return null;
    }

    /**
     * Eligible to submit a new response (approved + bound + quick open + no response yet).
     */
    public static function can_user_submit(int $sessionid, int $userid): bool {
        $enrol = self::find_user_enrolment_for_session($sessionid, $userid);
        if (!$enrol) {
            return false;
        }
        if ((int) $enrol->status !== session_manager::ENROL_APPROVED) {
            return false;
        }
        if (self::learner_userid_for_enrol($enrol) !== $userid) {
            return false;
        }
        if (self::get_response_for_user_session($sessionid, $userid)) {
            return false;
        }
        return self::is_quick_survey_accepting($sessionid);
    }

    /**
     * Can view own submitted answers (even after cancel/reject).
     */
    public static function can_user_view_response(int $sessionid, int $userid): bool {
        return self::get_response_for_user_session($sessionid, $userid) !== null;
    }

    /**
     * UI state for my_records / survey entry (SPEC §59.6).
     * FILL only when token is enabled and time-open (is_quick_survey_accepting).
     *
     * @return string one of STATE_* constants
     */
    public static function my_records_survey_state(\stdClass $enrol, int $userid): string {
        global $DB;
        if (self::learner_userid_for_enrol($enrol) !== $userid) {
            return self::STATE_NONE;
        }
        $sessionid = (int) $enrol->sessionid;
        if (self::get_response_for_user_session($sessionid, $userid)) {
            return self::STATE_VIEW;
        }
        if ((int) $enrol->status !== session_manager::ENROL_APPROVED) {
            return self::STATE_NONE;
        }
        $courseid = (int) ($enrol->courseid ?? 0);
        if ($courseid <= 0) {
            $courseid = (int) $DB->get_field('local_tm_course_sessions', 'courseid', ['id' => $sessionid]);
        }
        // Ensure pin attempt for past-start sessions (also creates token when pin is new).
        self::ensure_session_survey_pin($sessionid);
        $pin = self::get_pin($sessionid);
        $hasquestionnaire = $pin || self::active_surveyid_for_course($courseid) > 0;
        if (!$hasquestionnaire) {
            return self::STATE_NONE;
        }
        if (!self::is_session_survey_open($sessionid)) {
            return self::STATE_NOT_OPEN;
        }
        if (!self::is_quick_survey_accepting($sessionid)) {
            // Time-open but admin closed the token — treat as not fillable.
            return self::STATE_NOT_OPEN;
        }
        return self::STATE_FILL;
    }

    /**
     * Load submitted answers keyed by itemid for view mode.
     *
     * @return array<int,array{valueint:?int,valuetext:string,othertext:string,optionids:int[]}>
     */
    public static function get_response_answers(int $responseid): array {
        global $DB;
        $answers = $DB->get_records('local_tm_course_svans', ['responseid' => $responseid], 'id ASC');
        $out = [];
        foreach ($answers as $answer) {
            $picks = $DB->get_records('local_tm_course_svpick', ['answerid' => $answer->id], 'id ASC', 'id, optionid');
            $optionids = [];
            foreach ($picks as $pick) {
                $optionids[] = (int) $pick->optionid;
            }
            $out[(int) $answer->itemid] = [
                'valueint' => $answer->valueint !== null ? (int) $answer->valueint : null,
                'valuetext' => (string) ($answer->valuetext ?? ''),
                'othertext' => (string) ($answer->othertext ?? ''),
                'optionids' => $optionids,
            ];
        }
        return $out;
    }

    /**
     * Phase 2 API: approved learner submit via their Moodle email (delegates to email path).
     *
     * @param array<int|string,mixed> $rawanswers POST answer payload keyed by itemid
     * @return int response id
     */
    public static function submit_response(int $sessionid, int $userid, array $rawanswers): int {
        global $DB;

        $enrol = self::find_user_enrolment_for_session($sessionid, $userid);
        if (!$enrol) {
            throw new \moodle_exception('survey_error_not_eligible', 'local_tm_course');
        }
        if ((int) $enrol->status !== session_manager::ENROL_APPROVED) {
            throw new \moodle_exception('survey_error_not_eligible', 'local_tm_course');
        }
        if (self::learner_userid_for_enrol($enrol) !== $userid) {
            throw new \moodle_exception('survey_error_not_eligible', 'local_tm_course');
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id, email', MUST_EXIST);
        $email = self::normalize_email((string) ($user->email ?? ''));
        if ($email === '') {
            throw new \moodle_exception('survey_error_not_eligible', 'local_tm_course');
        }

        // Ensure token exists so is_quick_survey_accepting can pass for Phase 2 tests / my_records.
        self::ensure_session_survey_pin($sessionid);
        if (!self::get_token_for_session($sessionid)) {
            self::ensure_session_survey_token($sessionid, true);
        }

        return self::submit_response_by_email($sessionid, $email, $rawanswers);
    }

    /**
     * Trim + lowercase; return '' if not a valid email.
     */
    public static function normalize_email(string $email): string {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return $email;
    }

    /**
     * @return \stdClass|null
     */
    public static function get_token_by_value(string $token) {
        global $DB;
        $token = trim($token);
        if ($token === '' || \core_text::strlen($token) > 64) {
            return null;
        }
        $row = $DB->get_record('local_tm_course_svtok', ['token' => $token]);
        return $row ? $row : null;
    }

    /**
     * @return \stdClass|null
     */
    public static function get_token_for_session(int $sessionid) {
        global $DB;
        if ($sessionid <= 0) {
            return null;
        }
        $row = $DB->get_record('local_tm_course_svtok', ['sessionid' => $sessionid]);
        return $row ? $row : null;
    }

    /**
     * Create token row if missing. When $enable is true and row exists, set enabled=1.
     *
     * @return \stdClass
     */
    public static function ensure_session_survey_token(int $sessionid, bool $enable = true) {
        global $DB;
        if ($sessionid <= 0) {
            throw new \moodle_exception('survey_error_no_survey', 'local_tm_course');
        }
        $existing = self::get_token_for_session($sessionid);
        $now = time();
        if ($existing) {
            if ($enable && !(int) $existing->enabled) {
                $DB->update_record('local_tm_course_svtok', (object) [
                    'id' => (int) $existing->id,
                    'enabled' => 1,
                    'timemodified' => $now,
                ]);
                $existing->enabled = 1;
                $existing->timemodified = $now;
            }
            return $existing;
        }
        $token = bin2hex(random_bytes(32));
        $id = (int) $DB->insert_record('local_tm_course_svtok', (object) [
            'sessionid' => $sessionid,
            'token' => $token,
            'enabled' => $enable ? 1 : 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return $DB->get_record('local_tm_course_svtok', ['id' => $id], '*', MUST_EXIST);
    }

    public static function set_session_survey_token_enabled(int $sessionid, bool $enabled): void {
        global $DB;
        $row = self::ensure_session_survey_token($sessionid, false);
        $DB->update_record('local_tm_course_svtok', (object) [
            'id' => (int) $row->id,
            'enabled' => $enabled ? 1 : 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Replace token string and set enabled=1 (old string immediately invalid).
     *
     * @return \stdClass
     */
    public static function regenerate_session_survey_token(int $sessionid) {
        global $DB;
        $row = self::ensure_session_survey_token($sessionid, false);
        $now = time();
        $token = bin2hex(random_bytes(32));
        $DB->update_record('local_tm_course_svtok', (object) [
            'id' => (int) $row->id,
            'token' => $token,
            'enabled' => 1,
            'timemodified' => $now,
        ]);
        $row->token = $token;
        $row->enabled = 1;
        $row->timemodified = $now;
        return $row;
    }

    /**
     * @return \moodle_url
     */
    public static function quick_fill_url(string $token) {
        return new \moodle_url('/local/tm_course/survey.php', ['t' => $token]);
    }

    /**
     * @return \stdClass|null
     */
    public static function get_response_by_email(int $sessionid, int $versionid, string $email) {
        global $DB;
        $email = self::normalize_email($email);
        if ($email === '' || $sessionid <= 0 || $versionid <= 0) {
            return null;
        }
        $row = $DB->get_record('local_tm_course_svresp', [
            'sessionid' => $sessionid,
            'versionid' => $versionid,
            'email' => $email,
        ]);
        return $row ? $row : null;
    }

    /**
     * Map an email to userid/enrolid for a session (approved enrolment preferred).
     *
     * @return array{email:string,userid:int,enrolid:int,mapped:int}
     */
    public static function map_email_to_session(int $sessionid, string $email): array {
        global $DB;
        $email = self::normalize_email($email);
        $out = [
            'email' => $email,
            'userid' => 0,
            'enrolid' => 0,
            'mapped' => self::UNMAPPED,
        ];
        if ($email === '' || $sessionid <= 0) {
            return $out;
        }
        $sql = "SELECT u.id
                  FROM {user} u
                 WHERE " . $DB->sql_equal('u.email', ':email', false, false) . "
                   AND u.deleted = 0
              ORDER BY u.id ASC";
        $users = $DB->get_records_sql($sql, ['email' => $email], 0, 1);
        if (!$users) {
            return $out;
        }
        $user = reset($users);
        $userid = (int) $user->id;
        $out['userid'] = $userid;

        $sql = "SELECT e.*
                  FROM {local_tm_course_enrolments} e
                 WHERE e.sessionid = :sessionid
                   AND (e.userid = :uid1 OR e.linked_userid = :uid2)
              ORDER BY CASE WHEN e.status = :approved THEN 0 ELSE 1 END, e.id ASC";
        $rows = $DB->get_records_sql($sql, [
            'sessionid' => $sessionid,
            'uid1' => $userid,
            'uid2' => $userid,
            'approved' => session_manager::ENROL_APPROVED,
        ]);
        $enrol = null;
        foreach ($rows as $candidate) {
            if (self::learner_userid_for_enrol($candidate) === $userid) {
                $enrol = $candidate;
                break;
            }
        }
        if ($enrol) {
            $out['enrolid'] = (int) $enrol->id;
            $out['mapped'] = self::MAPPED;
        }
        return $out;
    }

    /**
     * Pin open by time AND token exists AND enabled.
     */
    public static function is_quick_survey_accepting(int $sessionid): bool {
        if (!self::is_session_survey_open($sessionid)) {
            return false;
        }
        $tok = self::get_token_for_session($sessionid);
        return $tok && (int) $tok->enabled === 1;
    }

    public static function count_session_responses(int $sessionid): int {
        global $DB;
        if ($sessionid <= 0) {
            return 0;
        }
        return (int) $DB->count_records('local_tm_course_svresp', ['sessionid' => $sessionid]);
    }

    /**
     * Approved enrolment headcount for the session.
     */
    public static function expected_headcount(int $sessionid): int {
        return session_manager::confirmed_count($sessionid);
    }

    /**
     * Response for a user on a session: enrol-bound first, then email+pinned version.
     *
     * @return \stdClass|null
     */
    public static function get_response_for_user_session(int $sessionid, int $userid) {
        global $DB;
        if ($sessionid <= 0 || $userid <= 0) {
            return null;
        }
        $enrol = self::find_user_enrolment_for_session($sessionid, $userid);
        if ($enrol) {
            $byenrol = self::get_response_by_enrolid((int) $enrol->id);
            if ($byenrol) {
                return $byenrol;
            }
        }
        $user = $DB->get_record('user', ['id' => $userid], 'id, email');
        if (!$user) {
            return null;
        }
        $email = self::normalize_email((string) ($user->email ?? ''));
        if ($email === '') {
            return null;
        }
        $pin = self::get_pin($sessionid);
        if ($pin) {
            $byemail = self::get_response_by_email($sessionid, (int) $pin->versionid, $email);
            if ($byemail) {
                return $byemail;
            }
        }
        $row = $DB->get_record_sql(
            "SELECT * FROM {local_tm_course_svresp}
              WHERE sessionid = :sessionid AND email = :email
           ORDER BY id DESC",
            ['sessionid' => $sessionid, 'email' => $email],
            0,
            1
        );
        return $row ? $row : null;
    }

    /**
     * Public / QR submit path keyed by email.
     *
     * @param array<int|string,mixed> $rawanswers
     * @return int response id
     */
    public static function submit_response_by_email(int $sessionid, string $email, array $rawanswers): int {
        global $DB;

        $email = self::normalize_email($email);
        if ($email === '') {
            throw new \moodle_exception('survey_error_invalid_email', 'local_tm_course');
        }

        if (!self::is_quick_survey_accepting($sessionid)) {
            throw new \moodle_exception('survey_error_not_open', 'local_tm_course');
        }

        $pinid = self::ensure_session_survey_pin($sessionid);
        $pin = self::get_pin($sessionid);
        if (!$pin || $pinid <= 0) {
            throw new \moodle_exception('survey_error_no_survey', 'local_tm_course');
        }
        $versionid = (int) $pin->versionid;

        if (self::get_response_by_email($sessionid, $versionid, $email)) {
            throw new \moodle_exception('survey_error_already_submitted', 'local_tm_course');
        }

        $map = self::map_email_to_session($sessionid, $email);
        $enrolid = (int) $map['enrolid'];
        $userid = (int) $map['userid'];
        $mapped = (int) $map['mapped'];

        if ($enrolid > 0 && self::get_response_by_enrolid($enrolid)) {
            throw new \moodle_exception('survey_error_already_submitted', 'local_tm_course');
        }

        $structure = self::get_version_structure($versionid);
        $validated = self::validate_answer_payload($structure, $rawanswers);

        try {
            $transaction = $DB->start_delegated_transaction();
            if ($DB->record_exists('local_tm_course_svresp', [
                'sessionid' => $sessionid,
                'versionid' => $versionid,
                'email' => $email,
            ])) {
                throw new \moodle_exception('survey_error_already_submitted', 'local_tm_course');
            }
            if ($enrolid > 0 && $DB->record_exists('local_tm_course_svresp', ['enrolid' => $enrolid])) {
                throw new \moodle_exception('survey_error_already_submitted', 'local_tm_course');
            }
            $responseid = (int) $DB->insert_record('local_tm_course_svresp', (object) [
                'enrolid' => $enrolid,
                'versionid' => $versionid,
                'sessionid' => $sessionid,
                'userid' => $userid,
                'email' => $email,
                'mapped' => $mapped,
                'timecreated' => time(),
            ]);
            foreach ($validated as $row) {
                $answerid = (int) $DB->insert_record('local_tm_course_svans', (object) [
                    'responseid' => $responseid,
                    'itemid' => (int) $row['itemid'],
                    'valueint' => $row['valueint'],
                    'valuetext' => $row['valuetext'],
                    'othertext' => $row['othertext'],
                ]);
                foreach ($row['optionids'] as $optionid) {
                    $DB->insert_record('local_tm_course_svpick', (object) [
                        'answerid' => $answerid,
                        'optionid' => (int) $optionid,
                    ]);
                }
            }
            $transaction->allow_commit();
            return $responseid;
        } catch (\dml_exception $e) {
            if (self::get_response_by_email($sessionid, $versionid, $email)
                || ($enrolid > 0 && self::get_response_by_enrolid($enrolid))) {
                throw new \moodle_exception('survey_error_already_submitted', 'local_tm_course');
            }
            throw $e;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $structure
     * @param array<int|string,mixed> $rawanswers
     * @return array<int,array{itemid:int,valueint:?int,valuetext:?string,othertext:?string,optionids:int[]}>
     */
    public static function validate_answer_payload(array $structure, array $rawanswers): array {
        $items = [];
        foreach ($structure as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $items[(int) $item['id']] = $item;
            }
        }
        $out = [];
        foreach ($items as $itemid => $item) {
            $payload = $rawanswers[$itemid] ?? $rawanswers[(string) $itemid] ?? [];
            if (!is_array($payload)) {
                $payload = [];
            }
            $required = !empty($item['required']);
            $qtype = (string) $item['qtype'];
            $optionsbyid = [];
            foreach ($item['options'] ?? [] as $option) {
                $optionsbyid[(int) $option['id']] = $option;
            }

            $valueint = null;
            $valuetext = null;
            $othertext = null;
            $optionids = [];

            if ($qtype === self::TYPE_SCALE) {
                $raw = $payload['value'] ?? $payload['scale'] ?? '';
                if ($raw === '' || $raw === null) {
                    if ($required) {
                        throw new \moodle_exception('survey_error_required', 'local_tm_course', '', $item['title']);
                    }
                    continue;
                }
                $valueint = (int) clean_param((string) $raw, PARAM_INT);
                if ($valueint < 1 || $valueint > 5) {
                    throw new \moodle_exception('survey_error_bad_answer', 'local_tm_course', '', $item['title']);
                }
            } else if ($qtype === self::TYPE_TEXT) {
                $text = trim(clean_param((string) ($payload['value'] ?? $payload['text'] ?? ''), PARAM_TEXT));
                if ($text === '') {
                    if ($required) {
                        throw new \moodle_exception('survey_error_required', 'local_tm_course', '', $item['title']);
                    }
                    continue;
                }
                $valuetext = $text;
            } else if ($qtype === self::TYPE_SINGLE) {
                $oid = (int) clean_param((string) ($payload['option'] ?? $payload['value'] ?? '0'), PARAM_INT);
                if ($oid <= 0 || !isset($optionsbyid[$oid])) {
                    if ($required) {
                        throw new \moodle_exception('survey_error_required', 'local_tm_course', '', $item['title']);
                    }
                    continue;
                }
                $valueint = $oid;
                $optionids = [$oid];
                if (!empty($optionsbyid[$oid]['isother'])) {
                    $othertext = trim(clean_param((string) ($payload['other'] ?? ''), PARAM_TEXT));
                    if ($othertext === '') {
                        throw new \moodle_exception('survey_error_other_required', 'local_tm_course', '', $item['title']);
                    }
                }
            } else if ($qtype === self::TYPE_MULTI) {
                $rawopts = $payload['options'] ?? $payload['option'] ?? [];
                if (!is_array($rawopts)) {
                    $rawopts = [$rawopts];
                }
                foreach ($rawopts as $rawoid) {
                    $oid = (int) clean_param((string) $rawoid, PARAM_INT);
                    if ($oid > 0 && isset($optionsbyid[$oid])) {
                        $optionids[] = $oid;
                    }
                }
                $optionids = array_values(array_unique($optionids));
                if (!$optionids) {
                    if ($required) {
                        throw new \moodle_exception('survey_error_required', 'local_tm_course', '', $item['title']);
                    }
                    continue;
                }
                $needsother = false;
                foreach ($optionids as $oid) {
                    if (!empty($optionsbyid[$oid]['isother'])) {
                        $needsother = true;
                        break;
                    }
                }
                if ($needsother) {
                    $othertext = trim(clean_param((string) ($payload['other'] ?? ''), PARAM_TEXT));
                    if ($othertext === '') {
                        throw new \moodle_exception('survey_error_other_required', 'local_tm_course', '', $item['title']);
                    }
                }
            } else {
                throw new \moodle_exception('survey_error_bad_type', 'local_tm_course');
            }

            $out[] = [
                'itemid' => $itemid,
                'valueint' => $valueint,
                'valuetext' => $valuetext,
                'othertext' => $othertext,
                'optionids' => $qtype === self::TYPE_MULTI ? $optionids : [],
            ];
        }
        return $out;
    }
}
