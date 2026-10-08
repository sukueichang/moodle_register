<?php
/**
 * Minimal multi-sheet XLSX writer (ZipArchive + shared strings).
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class survey_xlsx_writer {

    /**
     * Write sheets to an .xlsx path.
     *
     * @param string $path
     * @param array<int,array{name:string,rows:array}> $sheets
     */
    public static function write(string $path, array $sheets): void {
        if (!class_exists(\ZipArchive::class)) {
            throw new \moodle_exception('survey_export_no_zip', 'local_tm_course');
        }
        if (!$sheets) {
            throw new \moodle_exception('survey_export_empty', 'local_tm_course');
        }

        $shared = [];
        $sharedindex = [];
        $sheetxmls = [];
        $sheetnames = [];
        $si = 0;
        foreach ($sheets as $sheet) {
            $si++;
            $name = self::safe_sheet_name((string) ($sheet['name'] ?? ('Sheet' . $si)));
            $sheetnames[$si] = $name;
            $rows = isset($sheet['rows']) && is_array($sheet['rows']) ? $sheet['rows'] : [];
            $sheetrows = '';
            $rnum = 1;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cells = '';
                $c = 0;
                foreach ($row as $value) {
                    $value = (string) $value;
                    if (!isset($sharedindex[$value])) {
                        $sharedindex[$value] = count($shared);
                        $shared[] = $value;
                    }
                    $ref = self::col_letters($c) . $rnum;
                    $idx = $sharedindex[$value];
                    $cells .= '<c r="' . $ref . '" t="s"><v>' . $idx . '</v></c>';
                    $c++;
                }
                $sheetrows .= '<row r="' . $rnum . '">' . $cells . '</row>';
                $rnum++;
            }
            $sheetxmls[$si] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<sheetData>' . $sheetrows . '</sheetData></worksheet>';
        }

        $sst = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            . count($shared) . '" uniqueCount="' . count($shared) . '">';
        foreach ($shared as $s) {
            $sst .= '<si><t>' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
        }
        $sst .= '</sst>';

        $sheetnodes = '';
        $relnodes = '';
        $overrides = '';
        foreach ($sheetnames as $id => $name) {
            $rid = 'rId' . $id;
            $sheetnodes .= '<sheet name="' . htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '" sheetId="' . $id . '" r:id="' . $rid . '"/>';
            $relnodes .= '<Relationship Id="' . $rid . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . $id . '.xml"/>';
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $id . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $sstrelid = 'rId' . (count($sheetnames) + 1);
        $relnodes .= '<Relationship Id="' . $sstrelid . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"'
            . ' Target="sharedStrings.xml"/>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetnodes . '</sheets></workbook>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/></Relationships>';
        $wbrels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relnodes . '</Relationships>';
        $contenttypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $overrides
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';

        $zip = new \ZipArchive();
        $ok = $zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        if ($ok !== true) {
            throw new \moodle_exception('survey_export_write_failed', 'local_tm_course');
        }
        $zip->addFromString('[Content_Types].xml', $contenttypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbrels);
        foreach ($sheetxmls as $id => $xml) {
            $zip->addFromString('xl/worksheets/sheet' . $id . '.xml', $xml);
        }
        $zip->addFromString('xl/sharedStrings.xml', $sst);
        $zip->close();
    }

    private static function safe_sheet_name(string $name): string {
        $name = preg_replace('/[\\\\\/\\?\\*\\[\\]:]/', '', $name);
        $name = trim((string) $name);
        if ($name === '') {
            $name = 'Sheet';
        }
        if (\core_text::strlen($name) > 31) {
            $name = \core_text::substr($name, 0, 31);
        }
        return $name;
    }

    private static function col_letters(int $index): string {
        $index++;
        $s = '';
        while ($index > 0) {
            $index--;
            $s = chr(65 + ($index % 26)) . $s;
            $index = intdiv($index, 26);
        }
        return $s;
    }
}
