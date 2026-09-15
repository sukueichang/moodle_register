<?php
/**
 * Minimal .xlsx first-sheet reader for equipment check import.
 * Uses PHP ZipArchive + SimpleXML only (no Composer / PhpSpreadsheet).
 *
 * @package    local_tm_course
 * @copyright  2026 Techman Robot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class equipment_check_xlsx_reader {

    /** Max upload / archive size in bytes (2 MiB). */
    public const MAX_BYTES = 2097152;

    /** Refuse archives claiming more uncompressed payload than this. */
    public const MAX_UNCOMPRESSED_BYTES = 10485760; // 10 MiB

    /** Soft cap on data rows (excluding header). */
    public const MAX_DATA_ROWS = 2000;

    /** Soft cap on columns read per row. */
    public const MAX_COLS = 40;

    /**
     * Read the first worksheet as a matrix of trimmed strings.
     * Completely blank rows are omitted.
     *
     * @return array<int,array<int,string>> 0-based rows of 0-based cells
     * @throws \moodle_exception
     */
    public static function read_first_sheet(string $filepath): array {
        if ($filepath === '' || !is_readable($filepath)) {
            throw new \moodle_exception('equipment_check_import_error_unreadable', 'local_tm_course');
        }
        $size = @filesize($filepath);
        if ($size === false || $size <= 0) {
            throw new \moodle_exception('equipment_check_import_error_empty_file', 'local_tm_course');
        }
        if ($size > self::MAX_BYTES) {
            throw new \moodle_exception('equipment_check_import_error_too_large', 'local_tm_course');
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($filepath);
        if ($opened !== true) {
            throw new \moodle_exception('equipment_check_import_error_bad_xlsx', 'local_tm_course');
        }

        try {
            self::assert_zip_safe($zip);
            $shared = self::read_shared_strings($zip);
            $sheetpath = self::resolve_first_sheet_path($zip);
            $sheetxml = $zip->getFromName($sheetpath);
            if ($sheetxml === false || $sheetxml === '') {
                throw new \moodle_exception('equipment_check_import_error_bad_xlsx', 'local_tm_course');
            }
            if (strlen($sheetxml) > self::MAX_UNCOMPRESSED_BYTES) {
                throw new \moodle_exception('equipment_check_import_error_too_large', 'local_tm_course');
            }
            return self::parse_sheet_xml($sheetxml, $shared);
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws \moodle_exception
     */
    private static function assert_zip_safe(\ZipArchive $zip): void {
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $uncompressed = (int) ($stat['size'] ?? 0);
            if ($uncompressed < 0 || $uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                throw new \moodle_exception('equipment_check_import_error_too_large', 'local_tm_course');
            }
            $total += $uncompressed;
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new \moodle_exception('equipment_check_import_error_too_large', 'local_tm_course');
            }
        }
    }

    /**
     * @return string[]
     */
    private static function read_shared_strings(\ZipArchive $zip): array {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') {
            return [];
        }
        $sx = self::load_xml($xml);
        if ($sx === null) {
            return [];
        }
        $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $out = [];
        $sis = $sx->xpath('//m:si') ?: [];
        foreach ($sis as $si) {
            $texts = $si->xpath('.//m:t') ?: [];
            $buf = '';
            foreach ($texts as $t) {
                $buf .= (string) $t;
            }
            $out[] = $buf;
        }
        return $out;
    }

    private static function resolve_first_sheet_path(\ZipArchive $zip): string {
        // Prefer workbook relationship target for the first sheet.
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $wbx = self::load_xml($wb);
            $relx = self::load_xml($rels);
            if ($wbx && $relx) {
                $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $wbx->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $relx->registerXPathNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
                $sheets = $wbx->xpath('//m:sheets/m:sheet') ?: [];
                if (!empty($sheets)) {
                    $rid = (string) ($sheets[0]['r:id'] ?? '');
                    if ($rid === '' && isset($sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id)) {
                        $rid = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
                    }
                    if ($rid !== '') {
                        foreach ($relx->xpath('//pr:Relationship') ?: [] as $rel) {
                            if ((string) ($rel['Id'] ?? '') === $rid) {
                                $target = (string) ($rel['Target'] ?? '');
                                $target = ltrim(str_replace('\\', '/', $target), '/');
                                if ($target !== '' && strpos($target, 'worksheets/') !== false) {
                                    return (strpos($target, 'xl/') === 0) ? $target : ('xl/' . $target);
                                }
                            }
                        }
                    }
                }
            }
        }
        // Fallback common path.
        if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
            return 'xl/worksheets/sheet1.xml';
        }
        throw new \moodle_exception('equipment_check_import_error_bad_xlsx', 'local_tm_course');
    }

    /**
     * @param string[] $shared
     * @return array<int,array<int,string>>
     */
    private static function parse_sheet_xml(string $sheetxml, array $shared): array {
        $sx = self::load_xml($sheetxml);
        if ($sx === null) {
            throw new \moodle_exception('equipment_check_import_error_bad_xlsx', 'local_tm_course');
        }
        $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = $sx->xpath('//m:sheetData/m:row') ?: [];
        $matrix = [];
        $datarows = 0;
        foreach ($rows as $row) {
            $cells = [];
            $maxcol = -1;
            foreach ($row->c as $c) {
                $ref = (string) ($c['r'] ?? '');
                $col = self::col_index_from_ref($ref);
                if ($col < 0 || $col >= self::MAX_COLS) {
                    continue;
                }
                $type = (string) ($c['t'] ?? '');
                $value = '';
                if ($type === 's') {
                    $idx = (int) ((string) ($c->v ?? '0'));
                    $value = $shared[$idx] ?? '';
                } else if ($type === 'inlineStr') {
                    $texts = $c->xpath('.//m:t') ?: [];
                    foreach ($texts as $t) {
                        $value .= (string) $t;
                    }
                } else if ($type === 'b') {
                    $value = ((string) ($c->v ?? '0') === '1') ? '1' : '0';
                } else {
                    $value = (string) ($c->v ?? '');
                }
                $cells[$col] = trim(self::normalize_cell_text($value));
                if ($col > $maxcol) {
                    $maxcol = $col;
                }
            }
            if ($maxcol < 0) {
                continue;
            }
            $line = [];
            $nonempty = false;
            for ($i = 0; $i <= $maxcol; $i++) {
                $line[$i] = $cells[$i] ?? '';
                if ($line[$i] !== '') {
                    $nonempty = true;
                }
            }
            if (!$nonempty) {
                continue;
            }
            $matrix[] = $line;
            // Count non-header rows loosely after first row.
            if (count($matrix) > 1) {
                $datarows++;
                if ($datarows > self::MAX_DATA_ROWS) {
                    throw new \moodle_exception('equipment_check_import_error_too_many_rows', 'local_tm_course');
                }
            }
        }
        return $matrix;
    }

    private static function normalize_cell_text(string $value): string {
        // Strip BOM / normalize NBSP.
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = preg_replace("/^\xEF\xBB\xBF/", '', $value) ?? $value;
        return $value;
    }

    /**
     * A1 → 0, B1 → 1, AA12 → 26.
     */
    private static function col_index_from_ref(string $ref): int {
        if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) {
            return -1;
        }
        $letters = strtoupper($m[1]);
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    private static function load_xml(string $xml): ?\SimpleXMLElement {
        $prev = libxml_use_internal_errors(true);
        try {
            $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
            return ($sx instanceof \SimpleXMLElement) ? $sx : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }
}
