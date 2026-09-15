<?php
/**
 * Equipment check Excel import (append-only) for a single course modal.
 *
 * @package    local_tm_course
 * @copyright  2026 Techman Robot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/equipment_check_manager.php');
require_once(__DIR__ . '/equipment_check_xlsx_reader.php');
require_once(__DIR__ . '/enabled_course_manager.php');

class equipment_check_import_manager {

    public const RESULT_OK = 'ok';
    public const RESULT_DUP = 'duplicate';
    public const RESULT_ERR = 'error';

    public const SESSION_KEY = 'tm_equip_check_import';
    public const TOKEN_TTL = 1800; // 30 minutes

    /** Headers written to DB. */
    private const COL_ITEMNAME = 'itemname';
    private const COL_SCOPE = 'scope';
    private const COL_CHECKTYPE = 'checktype';
    private const COL_ENABLED = 'enabled';

    /** Ignored headers (read for column detection only). */
    private const IGNORED_HEADERS = ['課程', '分类', '分類', '備註', '备注', 'course', 'category', 'remark', 'note'];

    /**
     * Map Chinese (or known) header label → internal column key.
     */
    public static function map_header(string $header): ?string {
        $h = self::normalize_label($header);
        $map = [
            '檢查項目內容' => self::COL_ITEMNAME,
            '检查项目内容' => self::COL_ITEMNAME,
            'itemname' => self::COL_ITEMNAME,
            '適用範圍' => self::COL_SCOPE,
            '适用范围' => self::COL_SCOPE,
            'scope' => self::COL_SCOPE,
            '檢查型態' => self::COL_CHECKTYPE,
            '检查型态' => self::COL_CHECKTYPE,
            '檢查類型' => self::COL_CHECKTYPE,
            'checktype' => self::COL_CHECKTYPE,
            '啟用' => self::COL_ENABLED,
            '启用' => self::COL_ENABLED,
            'enabled' => self::COL_ENABLED,
        ];
        return $map[$h] ?? null;
    }

    /**
     * Strict scope mapping. Returns null when invalid (no fallback).
     */
    public static function map_scope(string $raw): ?string {
        $v = self::normalize_label($raw);
        $map = [
            '僅實體' => equipment_check_manager::SCOPE_ONSITE,
            '仅实体' => equipment_check_manager::SCOPE_ONSITE,
            'onsite' => equipment_check_manager::SCOPE_ONSITE,
            '僅視訊' => equipment_check_manager::SCOPE_ONLINE,
            '仅视讯' => equipment_check_manager::SCOPE_ONLINE,
            'online' => equipment_check_manager::SCOPE_ONLINE,
            '兩者皆可' => equipment_check_manager::SCOPE_BOTH,
            '两者皆可' => equipment_check_manager::SCOPE_BOTH,
            'both' => equipment_check_manager::SCOPE_BOTH,
        ];
        return $map[$v] ?? null;
    }

    /**
     * Strict checktype mapping. Returns null when invalid (no fallback).
     */
    public static function map_checktype(string $raw): ?string {
        $v = self::normalize_label($raw);
        // Exact known labels.
        $statuslabels = [
            '設備狀態型（正常／異常＋備註）',
            '设备状态型（正常／异常＋备注）',
            '狀態型（正常／異常＋備註）',
            '状态型（正常／异常＋备注）',
            'status',
        ];
        $tasklabels = [
            '準備確認型（完成／未完成）',
            '准备确认型（完成／未完成）',
            '任務型（完成／未完成）',
            '任务型（完成／未完成）',
            'task',
        ];
        foreach ($statuslabels as $label) {
            if ($v === self::normalize_label($label)) {
                return equipment_check_manager::TYPE_STATUS;
            }
        }
        foreach ($tasklabels as $label) {
            if ($v === self::normalize_label($label)) {
                return equipment_check_manager::TYPE_TASK;
            }
        }
        return null;
    }

    /**
     * Strict enabled mapping. Returns null when invalid.
     * Accepts 是/否; also 1/0 because Excel numeric cells often serialize that way.
     */
    public static function map_enabled(string $raw): ?int {
        $v = self::normalize_label($raw);
        if ($v === '是' || $v === '1') {
            return 1;
        }
        if ($v === '否' || $v === '0') {
            return 0;
        }
        return null;
    }

    /**
     * Validate uploaded file metadata before parse.
     *
     * @param array $fileinfo one $_FILES entry
     * @throws \moodle_exception
     */
    public static function assert_upload_ok(array $fileinfo): void {
        $error = (int) ($fileinfo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \moodle_exception('equipment_check_import_error_upload', 'local_tm_course');
        }
        $tmp = (string) ($fileinfo['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \moodle_exception('equipment_check_import_error_upload', 'local_tm_course');
        }
        $size = (int) ($fileinfo['size'] ?? 0);
        if ($size <= 0 || $size > equipment_check_xlsx_reader::MAX_BYTES) {
            throw new \moodle_exception('equipment_check_import_error_too_large', 'local_tm_course');
        }
        $name = (string) ($fileinfo['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new \moodle_exception('equipment_check_import_error_not_xlsx', 'local_tm_course');
        }
    }

    /**
     * Parse + validate Excel for preview. Stores commit payload in $SESSION.
     *
     * @param array $fileinfo $_FILES['file']
     * @return array{
     *   token:string,
     *   summary:array{total:int,ok:int,duplicate:int,error:int},
     *   rows:array<int,array{excel_row:int,itemname:string,scope_label:string,checktype_label:string,enabled_label:string,result:string,errors:string[]}>,
     *   can_commit:bool
     * }
     */
    public static function preview(int $courseid, array $fileinfo): array {
        self::assert_course_allowed($courseid);
        self::assert_upload_ok($fileinfo);

        $matrix = equipment_check_xlsx_reader::read_first_sheet((string) $fileinfo['tmp_name']);
        if (empty($matrix)) {
            throw new \moodle_exception('equipment_check_import_error_empty_sheet', 'local_tm_course');
        }

        $headerrow = array_shift($matrix);
        $colmap = self::build_column_map($headerrow);
        foreach ([self::COL_ITEMNAME, self::COL_SCOPE, self::COL_CHECKTYPE, self::COL_ENABLED] as $required) {
            if (!isset($colmap[$required])) {
                throw new \moodle_exception('equipment_check_import_error_missing_columns', 'local_tm_course');
            }
        }

        $dbkeys = equipment_check_manager::get_duplicate_key_set($courseid);
        $excelkeys = [];
        $previewrows = [];
        $commitrozs = [];
        $summary = ['total' => 0, 'ok' => 0, 'duplicate' => 0, 'error' => 0];

        // Excel row numbers: header is row 1; first data row is 2.
        $excelrownum = 1;
        foreach ($matrix as $line) {
            $excelrownum++;
            $rawitem = self::cell($line, $colmap[self::COL_ITEMNAME]);
            $rawscope = self::cell($line, $colmap[self::COL_SCOPE]);
            $rawtype = self::cell($line, $colmap[self::COL_CHECKTYPE]);
            $rawenabled = self::cell($line, $colmap[self::COL_ENABLED]);

            // Skip fully blank data lines (reader already drops blank rows, but defensive).
            if ($rawitem === '' && $rawscope === '' && $rawtype === '' && $rawenabled === '') {
                continue;
            }

            $summary['total']++;
            $errors = [];
            $itemname = trim($rawitem);
            if ($itemname === '') {
                $errors[] = get_string('equipment_check_import_err_itemname_blank', 'local_tm_course', $excelrownum);
            } else {
                $cleaned = clean_param($itemname, PARAM_TEXT);
                if ($cleaned === '') {
                    $errors[] = get_string('equipment_check_import_err_itemname_blank', 'local_tm_course', $excelrownum);
                } else if (\core_text::strlen($cleaned) > 255) {
                    $errors[] = get_string('equipment_check_import_err_itemname_long', 'local_tm_course', $excelrownum);
                } else {
                    $itemname = $cleaned;
                }
            }

            $scope = self::map_scope($rawscope);
            if ($scope === null) {
                $errors[] = get_string('equipment_check_import_err_scope', 'local_tm_course', (object) [
                    'row' => $excelrownum,
                    'value' => $rawscope,
                ]);
            }

            $checktype = self::map_checktype($rawtype);
            if ($checktype === null) {
                $errors[] = get_string('equipment_check_import_err_checktype', 'local_tm_course', (object) [
                    'row' => $excelrownum,
                    'value' => $rawtype,
                ]);
            }

            $enabled = self::map_enabled($rawenabled);
            if ($enabled === null) {
                $errors[] = get_string('equipment_check_import_err_enabled', 'local_tm_course', (object) [
                    'row' => $excelrownum,
                    'value' => $rawenabled,
                ]);
            }

            $result = self::RESULT_OK;
            if (!empty($errors)) {
                $result = self::RESULT_ERR;
                $summary['error']++;
            } else {
                $key = equipment_check_manager::make_duplicate_key($itemname, $scope, $checktype);
                if (isset($excelkeys[$key]) || isset($dbkeys[$key])) {
                    $result = self::RESULT_DUP;
                    $summary['duplicate']++;
                    $reason = isset($excelkeys[$key])
                        ? get_string('equipment_check_import_err_dup_excel', 'local_tm_course', $excelrownum)
                        : get_string('equipment_check_import_err_dup_db', 'local_tm_course', $excelrownum);
                    // Duplicate is a warning message, not a blocking error.
                    $errors[] = $reason;
                } else {
                    $excelkeys[$key] = $excelrownum;
                    $summary['ok']++;
                    $commitrozs[] = [
                        'itemname' => $itemname,
                        'scope' => $scope,
                        'checktype' => $checktype,
                        'enabled' => $enabled,
                        'excel_row' => $excelrownum,
                    ];
                }
            }

            $previewrows[] = [
                'excel_row' => $excelrownum,
                'itemname' => $itemname !== '' ? $itemname : $rawitem,
                'scope_label' => $rawscope,
                'checktype_label' => $rawtype,
                'enabled_label' => $rawenabled,
                'result' => $result,
                'errors' => $errors,
            ];
        }

        if ($summary['total'] === 0) {
            throw new \moodle_exception('equipment_check_import_error_empty_sheet', 'local_tm_course');
        }

        $token = random_string(32);
        self::store_session($token, $courseid, $commitrozs, $summary);

        return [
            'token' => $token,
            'summary' => $summary,
            'summary_text' => get_string('equipment_check_import_summary', 'local_tm_course', (object) $summary),
            'rows' => $previewrows,
            'can_commit' => ($summary['error'] === 0 && $summary['total'] > 0),
        ];
    }

    /**
     * Re-validate session payload and append items. Clears token after success or fatal failure.
     *
     * @return array{inserted:int,skipped:int,failed:int}
     */
    public static function commit(int $courseid, string $token): array {
        self::assert_course_allowed($courseid);
        $payload = self::load_session($token, $courseid);
        if ($payload === null) {
            throw new \moodle_exception('equipment_check_import_error_stale', 'local_tm_course');
        }

        // Server-side re-validation (strict): rebuild ok list; any hard error aborts.
        $dbkeys = equipment_check_manager::get_duplicate_key_set($courseid);
        $seen = [];
        $toinsert = [];
        $skipped = 0;
        foreach ($payload['rows'] as $row) {
            $name = trim((string) ($row['itemname'] ?? ''));
            $scope = (string) ($row['scope'] ?? '');
            $checktype = (string) ($row['checktype'] ?? '');
            $enabled = !empty($row['enabled']) ? 1 : 0;

            if ($name === '' || \core_text::strlen($name) > 255) {
                self::clear_session();
                throw new \moodle_exception('equipment_check_import_error_revalidate', 'local_tm_course');
            }
            if (!in_array($scope, [
                equipment_check_manager::SCOPE_ONSITE,
                equipment_check_manager::SCOPE_ONLINE,
                equipment_check_manager::SCOPE_BOTH,
            ], true)) {
                self::clear_session();
                throw new \moodle_exception('equipment_check_import_error_revalidate', 'local_tm_course');
            }
            if (!in_array($checktype, [
                equipment_check_manager::TYPE_STATUS,
                equipment_check_manager::TYPE_TASK,
            ], true)) {
                self::clear_session();
                throw new \moodle_exception('equipment_check_import_error_revalidate', 'local_tm_course');
            }
            $key = equipment_check_manager::make_duplicate_key($name, $scope, $checktype);
            if (isset($seen[$key]) || isset($dbkeys[$key])) {
                $skipped++;
                continue;
            }
            $seen[$key] = true;
            $toinsert[] = [
                'itemname' => $name,
                'scope' => $scope,
                'checktype' => $checktype,
                'enabled' => $enabled,
            ];
        }

        // If preview said errors existed, can_commit should be false — belt and suspenders:
        if (!empty($payload['summary']['error'])) {
            self::clear_session();
            throw new \moodle_exception('equipment_check_import_error_has_errors', 'local_tm_course');
        }

        try {
            $result = equipment_check_manager::append_items($courseid, $toinsert);
            self::clear_session();
            return [
                'inserted' => (int) $result['inserted'],
                'skipped' => (int) $result['skipped'] + $skipped,
                'failed' => 0,
                'message' => get_string('equipment_check_import_done', 'local_tm_course', (object) [
                    'inserted' => (int) $result['inserted'],
                    'skipped' => (int) $result['skipped'] + $skipped,
                    'failed' => 0,
                ]),
            ];
        } catch (\Throwable $e) {
            self::clear_session();
            // Delegated transaction rolls back on exception when not committed.
            throw new \moodle_exception('equipment_check_import_error_db', 'local_tm_course');
        }
    }

    /**
     * @param array<int,string> $headerrow
     * @return array<string,int> internal col => index
     */
    private static function build_column_map(array $headerrow): array {
        $map = [];
        foreach ($headerrow as $idx => $label) {
            $key = self::map_header((string) $label);
            if ($key !== null && !isset($map[$key])) {
                $map[$key] = (int) $idx;
                continue;
            }
            // Ignored columns intentionally skipped.
            $norm = self::normalize_label((string) $label);
            foreach (self::IGNORED_HEADERS as $ignored) {
                if ($norm === self::normalize_label($ignored)) {
                    continue 2;
                }
            }
        }
        return $map;
    }

    private static function cell(array $line, int $idx): string {
        return trim((string) ($line[$idx] ?? ''));
    }

    private static function normalize_label(string $value): string {
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = trim($value);
        // Unify common fullwidth punctuation variants for type labels.
        $value = str_replace(['(', ')', '+', '/'], ['（', '）', '＋', '／'], $value);
        // Collapse internal whitespace.
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        return $value;
    }

    private static function assert_course_allowed(int $courseid): void {
        global $DB;
        if ($courseid <= 0 || !enabled_course_manager::is_enabled($courseid)) {
            throw new \moodle_exception('equipment_check_import_error_course', 'local_tm_course');
        }
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            throw new \moodle_exception('equipment_check_import_error_course', 'local_tm_course');
        }
    }

    /**
     * @param array<int,array{itemname:string,scope:string,checktype:string,enabled:int,excel_row:int}> $rows
     * @param array{total:int,ok:int,duplicate:int,error:int} $summary
     */
    private static function store_session(string $token, int $courseid, array $rows, array $summary): void {
        global $SESSION;
        $SESSION->{self::SESSION_KEY} = [
            'token' => $token,
            'courseid' => $courseid,
            'rows' => $rows,
            'summary' => $summary,
            'timecreated' => time(),
        ];
    }

    /**
     * @return array{token:string,courseid:int,rows:array,summary:array,timecreated:int}|null
     */
    private static function load_session(string $token, int $courseid): ?array {
        global $SESSION;
        $data = $SESSION->{self::SESSION_KEY} ?? null;
        if (!is_array($data)) {
            return null;
        }
        if ((string) ($data['token'] ?? '') !== $token) {
            return null;
        }
        if ((int) ($data['courseid'] ?? 0) !== $courseid) {
            return null;
        }
        if ((time() - (int) ($data['timecreated'] ?? 0)) > self::TOKEN_TTL) {
            self::clear_session();
            return null;
        }
        return $data;
    }

    private static function clear_session(): void {
        global $SESSION;
        unset($SESSION->{self::SESSION_KEY});
    }
}
