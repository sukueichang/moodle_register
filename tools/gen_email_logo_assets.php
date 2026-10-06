<?php
/**
 * Generate local_tm_course/classes/email_logo_assets.php from pix/email PNGs.
 */
$files = [
    'tm_robot_logo' => __DIR__ . '/../local_tm_course/pix/email/tm_robot_logo.png',
    'training_center_logo' => __DIR__ . '/../local_tm_course/pix/email/training_center_logo.png',
];

$entries = '';
foreach ($files as $k => $p) {
    if (!is_readable($p)) {
        fwrite(STDERR, "Missing $p\n");
        exit(1);
    }
    $bin = file_get_contents($p);
    $b64 = base64_encode($bin);
    echo "$k bytes=" . strlen($bin) . " b64=" . strlen($b64) . " magic=" . bin2hex(substr($bin, 0, 8)) . "\n";
    $entries .= "            '{$k}' => '" . $b64 . "',\n";
}

$out = <<<'PHP'
<?php
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

/**
 * Email logo PNG bytes: prefer on-disk pix/email, fallback to embedded base64.
 *
 * @package local_tm_course
 */
class email_logo_assets {

    /**
     * @return string|null Raw PNG bytes
     */
    public static function png_bytes(string $key): ?string {
        $disk = self::disk_path($key);
        if ($disk !== null && is_readable($disk)) {
            $raw = file_get_contents($disk);
            return ($raw === false || $raw === '') ? null : $raw;
        }
        $map = self::base64_map();
        if (!isset($map[$key])) {
            return null;
        }
        $bin = base64_decode($map[$key], true);
        return ($bin === false || $bin === '') ? null : $bin;
    }

    public static function filename(string $key): ?string {
        $names = [
            'tm_robot_logo' => 'tm_robot_logo.png',
            'training_center_logo' => 'training_center_logo.png',
        ];
        return $names[$key] ?? null;
    }

    public static function key_from_filename(string $filename): ?string {
        foreach (['tm_robot_logo' => 'tm_robot_logo.png', 'training_center_logo' => 'training_center_logo.png'] as $key => $name) {
            if ($name === $filename) {
                return $key;
            }
        }
        return null;
    }

    public static function disk_path(string $key): ?string {
        $filename = self::filename($key);
        if ($filename === null) {
            return null;
        }
        return dirname(__DIR__) . '/pix/email/' . $filename;
    }

    /**
     * @return array<string,string>
     */
    private static function base64_map(): array {
        return [
PHP;

$out .= $entries;
$out .= <<<'PHP'
        ];
    }
}
PHP;

$target = __DIR__ . '/../local_tm_course/classes/email_logo_assets.php';
file_put_contents($target, $out);
echo "Wrote $target size=" . filesize($target) . "\n";
