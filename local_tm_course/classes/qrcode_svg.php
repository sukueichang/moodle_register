<?php
/**
 * Survey QR helper. Prefers a remote QR image API (no Composer deps).
 * Local SVG encoder is not shipped; keep URLs short (~120 chars).
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class qrcode_svg {

    /**
     * HTML <img> for a QR pointing at $data (external qrserver fallback).
     */
    public static function img_html(string $data, int $size = 320): string {
        $size = max(120, min(640, $size));
        $src = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
            . '&data=' . rawurlencode($data);
        return \html_writer::empty_tag('img', [
            'src' => $src,
            'alt' => 'QR',
            'width' => $size,
            'height' => $size,
            'style' => 'max-width:100%;height:auto;background:#fff;padding:8px;',
        ]);
    }
}
