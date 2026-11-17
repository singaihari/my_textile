<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Code 128 (subset B) barcode as inline SVG — no external libraries, prints crisply on thermal and laser printers.
 */
final class Barcode
{
    public const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** @return int[] symbol values including start, checksum and stop */
    public static function encode(string $text): array
    {
        if ($text === '' || !preg_match('/^[\x20-\x7E]+$/', $text)) {
            throw new \InvalidArgumentException('Code 128B supports printable ASCII only');
        }
        $codes = [104];
        $sum = 104;
        foreach (str_split($text) as $i => $ch) {
            $v = ord($ch) - 32;
            $codes[] = $v;
            $sum += $v * ($i + 1);
        }
        $codes[] = $sum % 103;
        $codes[] = 106;
        return $codes;
    }

    public static function svg(string $text, int $height = 50, float $module = 1.6, bool $showText = true): string
    {
        $x = 10 * $module;   // quiet zone
        $rects = '';
        foreach (self::encode($text) as $code) {
            $pattern = self::PATTERNS[$code];
            foreach (str_split($pattern) as $i => $w) {
                $width = (int)$w * $module;
                if ($i % 2 === 0) {
                    $rects .= sprintf('<rect x="%.2f" y="0" width="%.2f" height="%d"/>', $x, $width, $height);
                }
                $x += $width;
            }
        }
        $total = $x + 10 * $module;
        $textH = $showText ? 14 : 0;
        $label = $showText ? sprintf('<text x="%.2f" y="%d" font-family="monospace" font-size="11" text-anchor="middle" fill="#000">%s</text>',
            $total / 2, $height + 12, htmlspecialchars($text, ENT_QUOTES)) : '';
        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %.2f %d" width="%.2f" height="%d" role="img" aria-label="Barcode %s" class="barcode-svg"><rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%s</g>%s</svg>',
            $total, $height + $textH, $total, $height + $textH, htmlspecialchars($text, ENT_QUOTES), $rects, $label);
    }
}
