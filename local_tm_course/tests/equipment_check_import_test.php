<?php
/**
 * PHPUnit: equipment check Excel import mapping / duplicate key helpers.
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/equipment_check_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/equipment_check_import_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/equipment_check_xlsx_reader.php');

/**
 * @covers \local_tm_course\equipment_check_import_manager
 * @covers \local_tm_course\equipment_check_manager
 * @covers \local_tm_course\equipment_check_xlsx_reader
 */
class equipment_check_import_test extends \advanced_testcase {

    public function test_map_scope_strict(): void {
        $this->assertSame('onsite', equipment_check_import_manager::map_scope('僅實體'));
        $this->assertSame('online', equipment_check_import_manager::map_scope('僅視訊'));
        $this->assertSame('both', equipment_check_import_manager::map_scope('兩者皆可'));
        $this->assertSame('onsite', equipment_check_import_manager::map_scope('  僅實體  '));
        $this->assertNull(equipment_check_import_manager::map_scope('現場'));
        $this->assertNull(equipment_check_import_manager::map_scope(''));
        $this->assertNull(equipment_check_import_manager::map_scope('physical'));
    }

    public function test_map_checktype_variants(): void {
        $this->assertSame('status', equipment_check_import_manager::map_checktype('設備狀態型（正常／異常＋備註）'));
        $this->assertSame('status', equipment_check_import_manager::map_checktype('狀態型（正常／異常＋備註）'));
        $this->assertSame('status', equipment_check_import_manager::map_checktype('狀態型(正常/異常+備註)'));
        $this->assertSame('task', equipment_check_import_manager::map_checktype('準備確認型（完成／未完成）'));
        $this->assertSame('task', equipment_check_import_manager::map_checktype('任務型（完成／未完成）'));
        $this->assertNull(equipment_check_import_manager::map_checktype('未知型態'));
        $this->assertNull(equipment_check_import_manager::map_checktype(''));
    }

    public function test_map_enabled_strict(): void {
        $this->assertSame(1, equipment_check_import_manager::map_enabled('是'));
        $this->assertSame(0, equipment_check_import_manager::map_enabled('否'));
        $this->assertSame(1, equipment_check_import_manager::map_enabled('1'));
        $this->assertSame(0, equipment_check_import_manager::map_enabled('0'));
        $this->assertNull(equipment_check_import_manager::map_enabled('Y'));
        $this->assertNull(equipment_check_import_manager::map_enabled('true'));
        $this->assertNull(equipment_check_import_manager::map_enabled(''));
    }

    public function test_duplicate_key_excludes_enabled(): void {
        $a = equipment_check_manager::make_duplicate_key('AI Server', 'onsite', 'status');
        $b = equipment_check_manager::make_duplicate_key('AI Server', 'onsite', 'status');
        $c = equipment_check_manager::make_duplicate_key('AI Server', 'online', 'status');
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function test_append_items_preserves_existing_ids(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $courseid = (int) $course->id;

        // Mark course enabled so import course gate can pass in other tests.
        $DB->insert_record('local_tm_enabled_courses', (object) [
            'courseid' => $courseid,
            'default_duration_hours' => 8,
            'default_duration_hours_onsite' => 8,
            'default_duration_hours_online' => 8,
            'allow_onsite' => 1,
            'allow_online' => 1,
            'timecreated' => time(),
        ]);

        $id1 = equipment_check_manager::create_item($courseid, 'Existing A', 'both', 'status', 1, 10);
        $before = $DB->get_record('local_tm_equip_check_item', ['id' => $id1], '*', MUST_EXIST);

        $result = equipment_check_manager::append_items($courseid, [
            ['itemname' => 'Existing A', 'scope' => 'both', 'checktype' => 'status', 'enabled' => 1], // dup
            ['itemname' => 'New B', 'scope' => 'onsite', 'checktype' => 'task', 'enabled' => 0],
        ]);

        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $after = $DB->get_record('local_tm_equip_check_item', ['id' => $id1], '*', MUST_EXIST);
        $this->assertEquals($before->itemname, $after->itemname);
        $this->assertEquals($before->sortorder, $after->sortorder);

        $new = $DB->get_record('local_tm_equip_check_item', [
            'courseid' => $courseid,
            'itemname' => 'New B',
        ], '*', MUST_EXIST);
        $this->assertSame('onsite', $new->scope);
        $this->assertSame('task', $new->checktype);
        $this->assertSame(0, (int) $new->enabled);
        $this->assertGreaterThan((int) $before->sortorder, (int) $new->sortorder);
    }

    public function test_xlsx_reader_roundtrip_minimal_sheet(): void {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }
        $this->resetAfterTest(true);
        $path = $this->make_minimal_xlsx([
            ['Moodle 課前設備檢查項目盤點表', '', '', '', '', '', ''],
            ['填寫說明', '', '', '', '', '', ''],
            ['', '', '', '', '', '', ''], // blank → dropped
            ['課程', '順序', '分類', '檢查項目內容', '適用範圍', '檢查型態', '備註'],
            ['Ignored Course', '1', '手臂功能', 'AI Server 可正常連線', '僅實體', '設備狀態型（正常／異常＋備註）', ''],
            ['Ignored Course', '2', '上課教具', '已備妥教具', '兩者皆可', '準備確認型（完成／未完成）', 'x'],
        ]);
        $matrix = equipment_check_xlsx_reader::read_first_sheet($path);
        // blank row dropped → title, instruction, header, 2 data = 5
        $this->assertCount(5, $matrix);
        $header = equipment_check_import_manager::find_header_row($matrix);
        $this->assertSame(4, $header['excel_row']);
        $this->assertArrayHasKey('itemname', $header['colmap']);
        $this->assertArrayHasKey('order', $header['colmap']);
        $this->assertArrayNotHasKey('enabled', $header['colmap']);
        $this->assertSame('AI Server 可正常連線', $matrix[$header['index'] + 1]['cells'][$header['colmap']['itemname']]);
        @unlink($path);
    }

    public function test_bt_check_fixture_header_and_validation(): void {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }
        global $CFG;
        $path = $CFG->dirroot . '/local/tm_course/tests/fixtures/bt_check.xlsx';
        if (!is_readable($path)) {
            $this->markTestSkipped('bt_check.xlsx fixture missing');
        }
        $matrix = equipment_check_xlsx_reader::read_first_sheet($path);
        $header = equipment_check_import_manager::find_header_row($matrix);
        $this->assertSame(4, $header['excel_row']);
        $this->assertArrayNotHasKey('enabled', $header['colmap']);

        $ok = 0;
        $errors = [];
        for ($i = $header['index'] + 1; $i < count($matrix); $i++) {
            $cells = $matrix[$i]['cells'];
            $excelrow = (int) $matrix[$i]['excel_row'];
            $item = trim((string) ($cells[$header['colmap']['itemname']] ?? ''));
            $scope = equipment_check_import_manager::map_scope((string) ($cells[$header['colmap']['scope']] ?? ''));
            $type = equipment_check_import_manager::map_checktype((string) ($cells[$header['colmap']['checktype']] ?? ''));
            $order = equipment_check_import_manager::map_order((string) ($cells[$header['colmap']['order']] ?? ''));
            if ($item === '' || $scope === null || $type === null) {
                $errors[] = $excelrow;
                continue;
            }
            $this->assertNotNull($order);
            $ok++;
        }
        $this->assertSame(14, $ok, 'bt_check.xlsx should yield 14 valid data rows');
        $this->assertSame([], $errors);
    }

    /**
     * Build a tiny OOXML spreadsheet for reader tests.
     *
     * @param array<int,array<int,string>> $rows
     */
    private function make_minimal_xlsx(array $rows): string {
        $shared = [];
        $sharedindex = [];
        $sheetrows = '';
        $rnum = 1;
        foreach ($rows as $row) {
            $cells = '';
            foreach ($row as $c => $value) {
                $value = (string) $value;
                if (!isset($sharedindex[$value])) {
                    $sharedindex[$value] = count($shared);
                    $shared[] = $value;
                }
                $ref = $this->col_letters($c) . $rnum;
                $idx = $sharedindex[$value];
                $cells .= '<c r="' . $ref . '" t="s"><v>' . $idx . '</v></c>';
            }
            $sheetrows .= '<row r="' . $rnum . '">' . $cells . '</row>';
            $rnum++;
        }
        $sst = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            . count($shared) . '" uniqueCount="' . count($shared) . '">';
        foreach ($shared as $s) {
            $sst .= '<si><t>' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
        }
        $sst .= '</sst>';

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetrows . '</sheetData></worksheet>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/></Relationships>';
        $wbrels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"'
            . ' Target="sharedStrings.xml"/></Relationships>';
        $contenttypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';

        $path = tempnam(sys_get_temp_dir(), 'eqx') . '.xlsx';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE));
        $zip->addFromString('[Content_Types].xml', $contenttypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbrels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/sharedStrings.xml', $sst);
        $zip->close();
        return $path;
    }

    private function col_letters(int $index): string {
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
