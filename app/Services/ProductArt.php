<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Generates original, clean product illustrations (SVG) for catalog items: a garment silhouette in the
 * product's colour on a soft studio background, with optional print pattern. Fully offline; no external art.
 */
final class ProductArt
{
    public const KINDS = ['shirt', 'tshirt', 'polo', 'kurti', 'saree', 'jeans', 'trousers', 'leggings', 'frock', 'kidset', 'towel',
        'bedsheet', 'fabric', 'blouse', 'dupatta', 'veshti', 'vest', 'nighty', 'romper', 'lehenga', 'pillow'];

    public const COLORS = ['White' => '#F8FAFC', 'Black' => '#1F2937', 'Blue' => '#2563EB', 'Navy' => '#1E3A8A', 'Sky Blue' => '#60A5FA', 'Red' => '#DC2626',
        'Maroon' => '#7F1D1D', 'Pink' => '#EC4899', 'Green' => '#15803D', 'Olive' => '#65A30D', 'Yellow' => '#EAB308', 'Mustard' => '#CA8A04',
        'Orange' => '#EA580C', 'Purple' => '#7C3AED', 'Grey' => '#6B7280', 'Beige' => '#D6C7A1', 'Brown' => '#78350F', 'Cream' => '#F5EBD0',
        'Gold' => '#C9A227', 'Multi' => '#0EA5E9'];

    public static function hexFor(string $colorName): string
    {
        return self::COLORS[$colorName] ?? '#2563EB';
    }

    public static function svg(string $kind, string $hex, string $pattern = '', string $accent = '#C9A227'): string
    {
        $base = self::norm($hex);
        $isLight = self::luma($base) > 200;
        $stroke = $isLight ? '#94A3B8' : self::mix($base, '#000000', 0.35);
        $shade = self::mix($base, '#000000', 0.18);
        $hi = self::mix($base, '#FFFFFF', 0.35);
        $bgA = $isLight ? '#E2E8F0' : self::mix($base, '#FFFFFF', 0.86);
        $bgB = $isLight ? '#F8FAFC' : self::mix($base, '#FFFFFF', 0.96);
        $patColor = $isLight ? '#94A3B8' : ($pattern === 'floral' ? self::mix($base, '#FFFFFF', 0.55) : self::mix($base, '#FFFFFF', 0.28));
        $accent = self::norm($accent);

        $patDef = match ($pattern) {
            'stripes' => '<pattern id="p" width="14" height="14" patternUnits="userSpaceOnUse" patternTransform="rotate(90)"><rect width="5" height="14" fill="' . $patColor . '" opacity=".55"/></pattern>',
            'checks' => '<pattern id="p" width="24" height="24" patternUnits="userSpaceOnUse"><rect width="24" height="8" fill="' . $patColor . '" opacity=".45"/><rect width="8" height="24" fill="' . $patColor . '" opacity=".45"/></pattern>',
            'dots' => '<pattern id="p" width="16" height="16" patternUnits="userSpaceOnUse"><circle cx="8" cy="8" r="2.4" fill="' . $patColor . '" opacity=".8"/></pattern>',
            'floral' => '<pattern id="p" width="38" height="38" patternUnits="userSpaceOnUse"><g fill="' . $patColor . '" opacity=".85"><circle cx="10" cy="10" r="3"/><circle cx="10" cy="4" r="2.6"/><circle cx="16" cy="10" r="2.6"/><circle cx="10" cy="16" r="2.6"/><circle cx="4" cy="10" r="2.6"/><circle cx="29" cy="29" r="2"/><circle cx="29" cy="24" r="1.8"/><circle cx="34" cy="29" r="1.8"/><circle cx="29" cy="34" r="1.8"/><circle cx="24" cy="29" r="1.8"/></g></pattern>',
            'zari' => '<pattern id="p" width="22" height="22" patternUnits="userSpaceOnUse"><path d="M11 3 L14 11 L11 19 L8 11 Z" fill="' . $accent . '" opacity=".7"/></pattern>',
            default => '',
        };
        [$body, $details] = self::shape($kind, $base, $shade, $hi, $stroke, $accent, $isLight);
        $patLayer = $patDef ? str_replace(['fill="BASE"', 'stroke="STROKE"'], ['fill="url(#p)"', 'stroke="none"'], $body) : '';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400" width="400" height="400">'
            . '<defs><linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $bgB . '"/><stop offset="1" stop-color="' . $bgA . '"/></linearGradient>'
            . '<radialGradient id="sh" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#0F172A" stop-opacity=".22"/><stop offset="1" stop-color="#0F172A" stop-opacity="0"/></radialGradient>'
            . '<linearGradient id="gl" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFFFFF" stop-opacity=".22"/><stop offset=".5" stop-color="#FFFFFF" stop-opacity="0"/><stop offset="1" stop-color="#000000" stop-opacity=".12"/></linearGradient>'
            . $patDef . '</defs>'
            . '<rect width="400" height="400" rx="0" fill="url(#bg)"/>'
            . '<ellipse cx="200" cy="356" rx="130" ry="16" fill="url(#sh)"/>'
            . str_replace(['fill="BASE"', 'stroke="STROKE"'], ['fill="' . $base . '"', 'stroke="' . $stroke . '"'], $body)
            . $patLayer
            . str_replace(['fill="BASE"', 'stroke="STROKE"'], ['fill="url(#gl)"', 'stroke="none"'], $body)
            . $details
            . '</svg>';
    }

    /** @return array{0:string,1:string} [body paths using fill="BASE" stroke="STROKE", detail markup] */
    private static function shape(string $k, string $base, string $shade, string $hi, string $st, string $acc, bool $light): array
    {
        $p = fn(string $d) => '<path d="' . $d . '" fill="BASE" stroke="STROKE" stroke-width="3" stroke-linejoin="round"/>';
        $line = fn(string $d, string $c, float $w = 3, string $extra = '') => '<path d="' . $d . '" fill="none" stroke="' . $c . '" stroke-width="' . $w . '" stroke-linecap="round" stroke-linejoin="round" ' . $extra . '/>';
        $btn = fn(float $x, float $y) => '<circle cx="' . $x . '" cy="' . $y . '" r="4" fill="' . $hi . '" stroke="' . $st . '" stroke-width="1.5"/>';
        $sv = $light ? '#64748B' : $hi;
        switch ($k) {
            case 'shirt':
                $b = $p('M140 92 L176 76 Q200 96 224 76 L260 92 L322 134 L300 180 L270 164 L270 334 Q200 344 130 334 L130 164 L100 180 L78 134 Z');
                $d = '<path d="M176 76 L200 116 L224 76 L214 68 Q200 82 186 68 Z" fill="' . $hi . '" stroke="' . $st . '" stroke-width="2.5" stroke-linejoin="round"/>'
                    . $line('M200 116 L200 334', $st, 2) . $btn(200, 146) . $btn(200, 186) . $btn(200, 226) . $btn(200, 266) . $btn(200, 306)
                    . '<rect x="222" y="164" width="32" height="36" rx="3" fill="none" stroke="' . $st . '" stroke-width="2"/>'
                    . $line('M108 172 L128 160 M292 172 L272 160', $st, 2);
                return [$b, $d];
            case 'tshirt':
            case 'polo':
                $b = $p('M134 86 Q200 118 266 86 L328 122 L302 174 L268 158 L268 334 L132 334 L132 158 L98 174 L72 122 Z');
                $d = $k === 'polo'
                    ? '<path d="M170 88 L200 120 L230 88" fill="' . $hi . '" stroke="' . $st . '" stroke-width="2.5"/>' . $line('M200 120 L200 168', $st, 2) . $btn(200, 136) . $btn(200, 156)
                    : $line('M168 90 Q200 116 232 90', $st, 3.5);
                $d .= $line('M106 166 L132 152 M294 166 L268 152', $st, 2) . $line('M134 318 L266 318', $sv, 1.5, 'stroke-dasharray="5 5"');
                return [$b, $d];
            case 'vest':
                $b = $p('M152 74 Q166 124 200 124 Q234 124 248 74 L268 80 Q258 132 272 164 L272 334 L128 334 L128 164 Q142 132 132 80 Z');
                return [$b, $line('M134 318 L266 318', $sv, 1.5, 'stroke-dasharray="5 5"')];
            case 'kurti':
            case 'nighty':
                $b = $p('M160 70 Q200 96 240 70 L276 86 L304 178 L280 186 L262 142 L270 252 L302 348 L98 348 L130 252 L138 142 L120 186 L96 178 L124 86 Z');
                $d = $k === 'kurti'
                    ? '<path d="M180 78 L200 118 L220 78" fill="none" stroke="' . $acc . '" stroke-width="4"/><path d="M102 334 L298 334 L300 346 L100 346 Z" fill="' . $acc . '" opacity=".85"/>'
                        . '<g fill="' . $acc . '">' . implode('', array_map(fn($x) => '<circle cx="' . $x . '" cy="322" r="3"/>', range(116, 284, 21))) . '</g>'
                    : $line('M170 78 Q200 110 230 78', $st, 3) . $line('M200 112 L200 170', $st, 2);
                return [$b, $d];
            case 'saree':
            case 'veshti':
                $border = $k === 'veshti' ? $acc : $acc;
                $layer = fn(int $y, int $w, string $f) => '<g><rect x="' . (200 - $w / 2) . '" y="' . $y . '" width="' . $w . '" height="64" rx="8" fill="' . $f . '" stroke="' . $st . '" stroke-width="3"/>'
                    . '<rect x="' . (200 - $w / 2) . '" y="' . ($y + 46) . '" width="' . $w . '" height="12" fill="' . $border . '"/>'
                    . '<rect x="' . (200 - $w / 2) . '" y="' . ($y + 8) . '" width="' . $w . '" height="5" fill="' . $border . '" opacity=".75"/></g>';
                $b = '<path d="M86 230 L314 230 Q322 230 322 238 L322 330 Q322 338 314 338 L86 338 Q78 338 78 330 L78 238 Q78 230 86 230 Z" fill="BASE" stroke="STROKE" stroke-width="3"/>';
                $d = '<rect x="78" y="306" width="244" height="18" fill="' . $border . '"/><rect x="78" y="240" width="244" height="6" fill="' . $border . '" opacity=".8"/>'
                    . '<g fill="' . $border . '" opacity=".9">' . implode('', array_map(fn($x) => '<path d="M' . $x . ' 300 l6 -10 l6 10 z"/>', range(90, 300, 22))) . '</g>'
                    . $layer(160, 228, $shade) . $layer(96, 212, $base)
                    . ($k === 'saree' ? '<path d="M282 96 Q320 150 300 230 L282 230 Q296 160 262 104 Z" fill="' . $shade . '" stroke="' . $st . '" stroke-width="2.5"/>' : '');
                return [$b, $d];
            case 'jeans':
            case 'trousers':
                $b = $p('M142 70 L258 70 L266 112 L254 344 L208 344 L200 166 L192 344 L146 344 L134 112 Z');
                $d = '<rect x="142" y="70" width="116" height="18" fill="' . $shade . '" stroke="' . $st . '" stroke-width="2.5"/>'
                    . $line('M200 88 L200 140', $st, 2)
                    . ($k === 'jeans' ? $line('M146 96 Q166 116 184 96 M216 96 Q234 116 254 96', $st, 2) . $line('M150 120 L144 340 M250 120 L256 340', $hi, 1.5, 'stroke-dasharray="6 5"')
                        . '<rect x="160" y="72" width="6" height="16" fill="' . $hi . '"/><rect x="234" y="72" width="6" height="16" fill="' . $hi . '"/>'
                        : $line('M171 92 L176 340 M229 92 L224 340', $hi, 1.5));
                return [$b, $d];
            case 'leggings':
                $b = $p('M158 70 L242 70 L246 100 L234 346 L208 346 L200 150 L192 346 L166 346 L154 100 Z');
                return [$b, '<rect x="158" y="70" width="84" height="16" fill="' . $shade . '"/>' . $line('M200 86 L200 150', $st, 1.5)];
            case 'frock':
            case 'lehenga':
                $b = $p('M168 80 Q200 100 232 80 L252 90 L264 142 L242 152 L234 172 L304 322 Q200 350 96 322 L166 172 L158 152 L136 142 L148 90 Z');
                $d = '<rect x="160" y="164" width="80" height="14" rx="4" fill="' . $acc . '"/>'
                    . '<path d="M108 312 Q200 338 292 312 L298 324 Q200 350 102 324 Z" fill="' . $acc . '" opacity=".9"/>'
                    . $line('M180 180 L150 318 M200 180 L200 330 M220 180 L250 318', $shade, 2)
                    . ($k === 'frock' ? '<circle cx="200" cy="171" r="8" fill="' . $hi . '" stroke="' . $st . '" stroke-width="2"/>' : '');
                return [$b, $d];
            case 'kidset':
                $b = $p('M152 60 Q200 84 248 60 L294 86 L276 124 L250 112 L250 214 L150 214 L150 112 L124 124 L106 86 Z')
                    . $p('M150 228 L250 228 L258 330 L210 330 L200 268 L190 330 L142 330 Z');
                return [$b, $line('M176 63 Q200 82 224 63', $st, 3) . '<rect x="150" y="228" width="100" height="14" fill="' . $acc . '" opacity=".8"/>'];
            case 'romper':
                $b = $p('M160 70 Q200 92 240 70 L280 92 L264 132 L248 124 L252 252 L228 330 L204 330 L200 290 L196 330 L172 330 L148 252 L152 124 L136 132 L120 92 Z');
                return [$b, $btn(200, 120) . $btn(200, 150) . $btn(200, 180) . $line('M176 74 Q200 96 224 74', $st, 3)];
            case 'towel':
                $fold = fn(int $y, string $f) => '<rect x="96" y="' . $y . '" width="208" height="58" rx="14" fill="' . $f . '" stroke="' . $st . '" stroke-width="3"/>'
                    . '<rect x="96" y="' . ($y + 38) . '" width="208" height="8" fill="' . $acc . '" opacity=".8"/>';
                $b = '<rect x="96" y="276" width="208" height="58" rx="14" fill="BASE" stroke="STROKE" stroke-width="3"/>';
                return [$b, '<rect x="96" y="314" width="208" height="8" fill="' . $acc . '" opacity=".8"/>' . $fold(212, $shade) . $fold(148, $base) . $fold(84, $hi)];
            case 'bedsheet':
            case 'pillow':
                $b = '<rect x="70" y="190" width="260" height="140" rx="12" fill="BASE" stroke="STROKE" stroke-width="3"/>';
                $d = '<rect x="70" y="300" width="260" height="14" fill="' . $acc . '" opacity=".85"/>'
                    . '<rect x="104" y="' . ($k === 'pillow' ? 120 : 110) . '" width="192" height="96" rx="40" fill="' . $hi . '" stroke="' . $st . '" stroke-width="3"/>'
                    . $line('M124 160 Q200 140 276 160', $st, 1.5, 'opacity=".6"');
                return [$b, $d];
            case 'fabric':
                $b = '<path d="M130 120 L300 120 L300 250 L130 250 Z" fill="BASE" stroke="STROKE" stroke-width="3"/>'
                    . '<path d="M300 250 L300 120 Q336 150 330 250 L310 344 L120 344 L130 250 Z" fill="BASE" stroke="STROKE" stroke-width="3"/>';
                $d = '<ellipse cx="130" cy="185" rx="34" ry="65" fill="' . $shade . '" stroke="' . $st . '" stroke-width="3"/>'
                    . '<ellipse cx="130" cy="185" rx="12" ry="24" fill="#E2E8F0" stroke="' . $st . '" stroke-width="2"/>'
                    . $line('M150 130 L290 130', $hi, 2, 'opacity=".7"') . $line('M128 340 L306 340', $st, 2, 'opacity=".5"');
                return [$b, $d];
            case 'blouse':
                $b = '<rect x="104" y="110" width="192" height="210" rx="10" fill="BASE" stroke="STROKE" stroke-width="3"/>';
                $d = '<rect x="104" y="280" width="192" height="24" fill="' . $acc . '"/><rect x="104" y="124" width="192" height="6" fill="' . $acc . '" opacity=".8"/>'
                    . '<g fill="' . $acc . '">' . implode('', array_map(fn($x) => '<circle cx="' . $x . '" cy="292" r="3.5" fill="' . $hi . '"/>', range(120, 280, 20))) . '</g>'
                    . '<path d="M296 110 L320 134 L320 304 L296 320 Z" fill="' . $shade . '" stroke="' . $st . '" stroke-width="2.5"/>';
                return [$b, $d];
            case 'dupatta':
                $b = $p('M90 110 Q200 70 310 110 Q280 220 312 330 Q200 300 88 330 Q120 220 90 110 Z');
                $fr = implode('', array_map(fn($x) => '<path d="M' . $x . ' ' . (330 - ($x - 88) * 0) . ' l0 14" stroke="' . $acc . '" stroke-width="2"/>', range(96, 304, 9)));
                return [$b, '<path d="M92 310 Q200 282 310 310 L312 330 Q200 300 88 330 Z" fill="' . $acc . '" opacity=".9"/>' . $fr];
        }
        return [$p('M120 100 L280 100 L280 320 L120 320 Z'), ''];
    }

    private static function norm(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? '#' . strtoupper($hex) : '#2563EB';
    }

    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    private static function luma(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    }

    public static function mix(string $a, string $b, float $t): string
    {
        [$r1, $g1, $b1] = self::rgb(self::norm($a));
        [$r2, $g2, $b2] = self::rgb(self::norm($b));
        return sprintf('#%02X%02X%02X', (int)round($r1 + ($r2 - $r1) * $t), (int)round($g1 + ($g2 - $g1) * $t), (int)round($b1 + ($b2 - $b1) * $t));
    }
}
