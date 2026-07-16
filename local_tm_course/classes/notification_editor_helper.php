<?php
/**
 * Shared HTML editor + body formatting for notification templates.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class notification_editor_helper {

    /**
     * Editor options for plugin notification bodies (no file uploads).
     *
     * @return array
     */
    public static function get_editor_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 0,
            'maxbytes' => 0,
            'changeformat' => 0,
            'context' => \context_system::instance(),
            'noclean' => true,
            'trusttext' => false,
        ];
    }

    /**
     * Print a textarea and attach the site preferred HTML editor (Atto / TinyMCE).
     *
     * @param string $elementid DOM id and form field name
     * @param string $text Current content (HTML or legacy plain text)
     * @param int $rows Textarea rows before editor init
     */
    public static function print_body_editor(string $elementid, string $text, int $rows = 8): void {
        global $CFG;
        require_once($CFG->libdir . '/editorlib.php');

        $displaytext = self::prepare_editor_text($text);
        echo '<textarea id="' . s($elementid) . '" name="' . s($elementid) . '"'
            . ' class="form-control" rows="' . (int) $rows . '">';
        echo htmlspecialchars($displaytext, ENT_QUOTES, 'UTF-8');
        echo '</textarea>';

        $editor = editors_get_preferred_editor(FORMAT_HTML);
        $editor->use_editor($elementid, ['maxfiles' => 0], self::get_editor_options());
    }

    /**
     * Read submitted body from request and normalise for storage.
     */
    public static function read_submitted_body(string $paramname): string {
        $raw = optional_param($paramname, '', PARAM_RAW);
        return self::clean_body_for_storage($raw);
    }

    /**
     * Normalise stored body for the editor (upgrade legacy plain text to simple HTML).
     */
    public static function prepare_editor_text(string $text): string {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }
        if (self::looks_like_html($text)) {
            return $text;
        }
        return self::plain_text_to_html($text);
    }

    /**
     * Turn template body into safe HTML for email composition.
     */
    public static function format_email_body_html(string $body): string {
        $body = trim((string) $body);
        if ($body === '') {
            return '';
        }
        if (!self::looks_like_html($body)) {
            return self::plain_text_to_html($body);
        }
        return format_text($body, FORMAT_HTML, [
            'noclean' => true,
            'context' => \context_system::instance(),
            'filter' => false,
            'para' => false,
        ]);
    }

    public static function clean_body_for_storage(string $body): string {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        if (self::looks_like_html($body)) {
            return clean_text($body, FORMAT_HTML, ['noclean' => true]);
        }
        return $body;
    }

    private static function looks_like_html(string $text): bool {
        return (bool) preg_match('/<\s*\w+/u', $text);
    }

    private static function plain_text_to_html(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $chunks = preg_split('/\n\s*\n/', $text) ?: [];
        $html = '';
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $escaped = s($chunk);
            $escaped = str_replace("\n", '<br />' . "\n", $escaped);
            $html .= '<p>' . $escaped . '</p>';
        }
        return $html !== '' ? $html : '<p></p>';
    }
}
