<?php
// Measures the uploaded résumé's design: fonts, sizes, colours, margins, headings, spacing and bullets. Ported
// from the desktop's resume_file.py and design_report.py, which read PDFs with PyMuPDF; here a small reader
// follows each page's drawing commands to find every piece of text with its font, size, colour and position,
// and the thin lines drawn under headings, then groups the text into spans, lines and blocks the same way.

declare(strict_types=1);

const SUBSET_PREFIX = '/^[A-Z]{6}\+/';
// PostScript names in PDFs ("TimesNewRomanPSMT", "Calibri-Bold") back to family names.
const PDF_FONT_NAMES = [
    'timesnewroman' => 'Times New Roman', 'times' => 'Times New Roman', 'arial' => 'Arial',
    'helvetica' => 'Arial', 'calibri' => 'Calibri', 'cambria' => 'Cambria', 'georgia' => 'Georgia',
    'garamond' => 'Garamond', 'ebgaramond' => 'Garamond', 'verdana' => 'Verdana', 'tahoma' => 'Tahoma',
    'segoeui' => 'Segoe UI', 'trebuchetms' => 'Trebuchet MS', 'bookantiqua' => 'Book Antiqua',
    'centurygothic' => 'Century Gothic', 'palatinolinotype' => 'Palatino Linotype', 'palatino' => 'Palatino Linotype',
    'aptos' => 'Aptos', 'lato' => 'Lato', 'roboto' => 'Roboto', 'opensans' => 'Open Sans',
];
const DESIGN_BULLETS = ['•' => '•', '●' => '•', "\u{F0B7}" => '•', '·' => '·', '▪' => '▪', '■' => '▪', '◦' => '○', '○' => '○',
    '–' => '–', '-' => '-', '›' => '›'];
const DESIGN_CONTACT = '/@|https?:\/\/|www\.|\.com|\(\d{3}\)|\d{3}[-. ]\d{4}|linkedin|portfolio:/i';
const DESIGN_YEAR = '/\b(?:19|20)\d{2}\b/';
const DESIGN_SEPARATORS = '|•·/';
const SPAN_BOLD = 16;
const SPAN_ITALIC = 2;

function family_name(string $raw): string
{
    $name = preg_replace(SUBSET_PREFIX, '', $raw);
    $name = preg_split('/[-,]/', $name)[0];
    $key = strtolower(str_replace(' ', '', preg_replace('/(PSMT|PS|MT|Std|Pro)$/', '', $name)));
    return PDF_FONT_NAMES[$key] ?? $name;
}

function color_hex(int $color): string
{
    return sprintf('#%06X', $color & 0xFFFFFF);
}

function is_dark_color(string $hex): bool
{
    [$r, $g, $b] = array_map(fn($i) => hexdec(substr($hex, $i, 2)), [1, 3, 5]);
    return max($r, $g, $b) < 70 || (abs($r - $g) < 20 && abs($g - $b) < 20 && max($r, $g, $b) < 110);
}

function half_point(float $size): float
{
    return round($size * 2) / 2;
}

// --- Reading a PDF page with positions ------------------------------------------------------------------------

/** A dictionary value, following an indirect reference ("12 0 R") once. */
function pdf_value(string $dict, string $key, array $objects): ?string
{
    if (!preg_match('#/' . $key . '(?![A-Za-z0-9])\s*(\[(?:[^\[\]]|\[[^\]]*\])*\]|<<.*?>>|\d+\s+\d+\s+R|/[^\s/\[\]<>()]+|-?[\d.]+|\((?:\\\\.|[^\\\\)])*\))#s', $dict, $m)) {
        return null;
    }
    if (preg_match('#^(\d+)\s+\d+\s+R$#', $m[1], $ref)) {
        $object = $objects[(int) $ref[1]] ?? null;
        return $object === null ? null : trim($object['dict']);
    }
    return $m[1];
}

function pdf_numbers(?string $text): array
{
    preg_match_all('#-?\d*\.?\d+#', (string) $text, $m);
    return array_map('floatval', $m[0]);
}

/** The pages' object numbers in reading order (following the page tree; object order when it can't be followed). */
function pdf_page_numbers(string $data, array $objects): array
{
    $root = null;
    if (preg_match_all('#/Root\s+(\d+)\s+0\s+R#', $data, $m)) {
        $root = (int) end($m[1]);
    }
    $pages = [];
    $walk = function (int $number, int $depth) use (&$walk, &$pages, $objects) {
        $dict = $objects[$number]['dict'] ?? '';
        if ($depth > 20 || count($pages) > 500) {
            return;
        }
        if (preg_match('#/Type\s*/Page(?!s)\b#', $dict)) {
            $pages[] = $number;
            return;
        }
        $kids = pdf_value($dict, 'Kids', $objects);
        if ($kids !== null && preg_match_all('#(\d+)\s+\d+\s+R#', $kids, $refs)) {
            foreach ($refs[1] as $kid) {
                $walk((int) $kid, $depth + 1);
            }
        }
    };
    if ($root !== null && isset($objects[$root]) && preg_match('#/Pages\s+(\d+)\s+0\s+R#', $objects[$root]['dict'], $tree)) {
        $walk((int) $tree[1], 0);
    }
    if (!$pages) {
        foreach ($objects as $number => $object) {
            if (preg_match('#/Type\s*/Page(?!s)\b#', $object['dict'])) {
                $pages[] = $number;
            }
        }
        sort($pages);
    }
    return $pages;
}

/** A page dictionary value, looking up the page tree for inherited ones (Resources, MediaBox). */
function pdf_inherited(int $page, string $key, array $objects): ?string
{
    $dict = $objects[$page]['dict'] ?? '';
    for ($depth = 0; $depth < 10; $depth++) {
        $value = pdf_value($dict, $key, $objects);
        if ($value !== null) {
            return $value;
        }
        $parent = pdf_refs_of($dict, 'Parent')[0] ?? null;
        if ($parent === null || !isset($objects[$parent])) {
            return null;
        }
        $dict = $objects[$parent]['dict'];
    }
    return null;
}

/** What is needed to place and read text in one font: name, style flags, code width, glyph widths, text map. */
function pdf_font_info(string $font, array $objects): array
{
    $base = ltrim((string) pdf_value($font, 'BaseFont', $objects), '/');
    $composite = str_contains((string) pdf_value($font, 'Subtype', $objects), 'Type0');
    $widths = [];
    $default = 1000.0;
    $descriptor_dict = $font;
    if ($composite) {
        $descendants = (string) pdf_value($font, 'DescendantFonts', $objects);
        $cid = preg_match('#(\d+)\s+\d+\s+R#', $descendants, $ref) ? ($objects[(int) $ref[1]]['dict'] ?? '') : $descendants;
        $default = (float) (pdf_value($cid, 'DW', $objects) ?? 1000);
        // /W [ first [w w w] first last w ... ]
        $w = (string) pdf_value($cid, 'W', $objects);
        preg_match_all('#\[[^\]]*\]|-?\d*\.?\d+#', substr($w, 1, -1), $parts);
        $tokens = $parts[0];
        for ($i = 0; $i < count($tokens);) {
            $first = (int) $tokens[$i];
            if (isset($tokens[$i + 1]) && $tokens[$i + 1][0] === '[') {
                foreach (pdf_numbers($tokens[$i + 1]) as $n => $width) {
                    $widths[$first + $n] = $width;
                }
                $i += 2;
            } elseif (isset($tokens[$i + 2])) {
                for ($code = $first, $last = min((int) $tokens[$i + 1], $first + 65535); $code <= $last; $code++) {
                    $widths[$code] = (float) $tokens[$i + 2];
                }
                $i += 3;
            } else {
                break;
            }
        }
        $descriptor_dict = $cid;
    } else {
        $first = (int) (pdf_value($font, 'FirstChar', $objects) ?? 0);
        foreach (pdf_numbers(pdf_value($font, 'Widths', $objects)) as $n => $width) {
            $widths[$first + $n] = $width;
        }
        if (!$widths) {
            // one of the 14 standard fonts, which PDFs may use without widths
            static $standard = null;
            $standard ??= require APP_ROOT . '/resources/fonts/standard-widths.php';
            $key = preg_match('/Times/i', $base) ? 'Times-Roman' : 'Helvetica';
            foreach (array_keys($standard) as $name) {
                if (strcasecmp($name, $base) === 0) {
                    $key = $name;
                }
            }
            foreach ($standard[$key] as $n => $width) {
                $widths[32 + $n] = (float) $width;
            }
            $default = 500.0;
        }
    }
    $descriptor = (string) pdf_value($descriptor_dict, 'FontDescriptor', $objects);
    $flags = (int) (pdf_value($descriptor, 'Flags', $objects) ?? 0);
    $weight = (float) (pdf_value($descriptor, 'FontWeight', $objects) ?? 0);
    $angle = (float) (pdf_value($descriptor, 'ItalicAngle', $objects) ?? 0);
    $ascent = (float) (pdf_value($descriptor, 'Ascent', $objects) ?? 0);
    $descent = (float) (pdf_value($descriptor, 'Descent', $objects) ?? 0);
    $span_flags = 0;
    if (preg_match('/bold|black|heavy|semibold|demi/i', $base) || $weight >= 600 || ($flags & 262144)) {
        $span_flags |= SPAN_BOLD;
    }
    if (preg_match('/italic|oblique/i', $base) || $angle != 0 || ($flags & 64)) {
        $span_flags |= SPAN_ITALIC;
    }
    $map_ref = pdf_refs_of($font, 'ToUnicode')[0] ?? null;
    return ['name' => $base, 'flags' => $span_flags, 'bytes' => $composite ? 2 : 1, 'widths' => $widths, 'default' => $default,
        'map' => $map_ref !== null ? pdf_cmap(pdf_stream($objects[$map_ref] ?? null)) : [],
        'ascent' => $ascent > 0 && $ascent < 1500 ? $ascent / 1000 : 0.9,
        'descent' => $descent < 0 && $descent > -800 ? $descent / 1000 : -0.25];
}

/** [resource name => font info] for a resources dictionary. */
function pdf_resource_fonts(?string $resources, array $objects): array
{
    $fonts = [];
    $dict = (string) pdf_value((string) $resources, 'Font', $objects);
    preg_match_all('#/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R#', $dict, $entries, PREG_SET_ORDER);
    foreach ($entries as [, $name, $ref]) {
        if (isset($objects[(int) $ref])) {
            $fonts[$name] = pdf_font_info($objects[(int) $ref]['dict'], $objects);
        }
    }
    return $fonts;
}

function matrix_multiply(array $a, array $b): array
{
    return [$a[0] * $b[0] + $a[1] * $b[2], $a[0] * $b[1] + $a[1] * $b[3], $a[2] * $b[0] + $a[3] * $b[2],
        $a[2] * $b[1] + $a[3] * $b[3], $a[4] * $b[0] + $a[5] * $b[2] + $b[4], $a[4] * $b[1] + $a[5] * $b[3] + $b[5]];
}

/** A colour operator's operands as a 0xRRGGBB number (grey, RGB or CMYK by how many there are). */
function operand_color(array $values): int
{
    $c = array_map(fn($v) => max(0.0, min(1.0, (float) $v)), $values);
    [$r, $g, $b] = match (count($c)) {
        1 => [$c[0], $c[0], $c[0]],
        3 => $c,
        4 => [(1 - $c[0]) * (1 - $c[3]), (1 - $c[1]) * (1 - $c[3]), (1 - $c[2]) * (1 - $c[3])],
        default => [0, 0, 0],
    };
    return ((int) round($r * 255) << 16) | ((int) round($g * 255) << 8) | (int) round($b * 255);
}

/**
 * Every piece of text and every straight line drawn by a content stream. Text pieces: text, font, size, flags,
 * color, x0, x1, base (baseline y), ascent, descent. Lines: x0, y0, x1, y1, color. Coordinates from the page's top.
 */
function pdf_page_marks(string $content, array $fonts, float $height, array $objects, ?string $resources, int $depth = 0, ?array $ctm = null): array
{
    $texts = [];
    $lines = [];
    $state = ['ctm' => $ctm ?? [1, 0, 0, 1, 0, 0], 'fill' => 0, 'stroke' => 0, 'width' => 1.0];
    $saved = [];
    $tm = $tlm = [1, 0, 0, 1, 0, 0];
    $font = null;
    $size = 0.0;
    $char_space = $word_space = $rise = $leading = 0.0;
    $scale = 1.0;
    $path = [];
    $start = $point = null;
    $tokens = '#\((?:\\\\.|[^\\\\)])*\)|<<|>>|<[0-9A-Fa-f\s]*>|\[(?:\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]*>|[^\]])*\]|/[^\s/\[\]()<>]+|-?\d*\.?\d+|[A-Za-z\'"*]+#s';
    preg_match_all($tokens, $content, $matches);
    $stack = [];
    $point_at = function (float $x, float $y) use (&$state) {
        $m = $state['ctm'];
        return [$x * $m[0] + $y * $m[2] + $m[4], $x * $m[1] + $y * $m[3] + $m[5]];
    };

    $show = function (string $string) use (&$tm, &$font, &$size, &$char_space, &$word_space, &$rise, &$scale, &$state, &$texts, $height) {
        if ($font === null) {
            return;
        }
        $bytes = pdf_string_bytes($string);
        $text = '';
        $advance = 0.0;
        $step = $font['bytes'];
        for ($i = 0; $i + $step <= strlen($bytes); $i += $step) {
            $code = $step === 2 ? (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]) : ord($bytes[$i]);
            $hex = sprintf($step === 2 ? '%04X' : '%02X', $code);
            if ($font['map']) {
                $char = $font['map'][$hex] ?? ($font['map'][sprintf('%04X', $code)] ?? '');
            } else {
                $char = pdf_latin(chr($code & 0xFF));
            }
            $text .= $char;
            $width = ($font['widths'][$code] ?? $font['default']) / 1000 * $size + $char_space;
            if ($step === 1 && $code === 32) {
                $width += $word_space;
            }
            $advance += $width * $scale;
        }
        $trm = matrix_multiply([1, 0, 0, 1, 0, $rise], matrix_multiply($tm, $state['ctm']));
        $x_scale = hypot($trm[0], $trm[1]);
        $y_scale = hypot($trm[2], $trm[3]);
        if ($text !== '' && $y_scale > 0) {
            $texts[] = ['text' => $text, 'font' => $font['name'], 'flags' => $font['flags'], 'size' => $size * $y_scale,
                'color' => $state['fill'], 'x0' => $trm[4], 'x1' => $trm[4] + $advance * $x_scale, 'base' => $height - $trm[5],
                'ascent' => $font['ascent'], 'descent' => $font['descent']];
        }
        $tm = matrix_multiply([1, 0, 0, 1, $advance, 0], $tm);
    };

    foreach ($matches[0] as $token) {
        $first = $token[0];
        if ($first === '(' || $first === '<' || $first === '[' || $first === '/' || $token === '>>' || is_numeric($token)) {
            $stack[] = $token;
            continue;
        }
        $n = fn(int $back) => (float) ($stack[count($stack) - $back] ?? 0);
        switch ($token) {
            case 'q':
                $saved[] = $state;
                break;
            case 'Q':
                $state = array_pop($saved) ?? $state;
                break;
            case 'cm':
                $state['ctm'] = matrix_multiply([$n(6), $n(5), $n(4), $n(3), $n(2), $n(1)], $state['ctm']);
                break;
            case 'w':
                $state['width'] = $n(1);
                break;
            case 'rg': case 'g': case 'k': case 'sc': case 'scn':
                $state['fill'] = operand_color(array_filter($stack, 'is_numeric'));
                break;
            case 'RG': case 'G': case 'K': case 'SC': case 'SCN':
                $state['stroke'] = operand_color(array_filter($stack, 'is_numeric'));
                break;
            case 'BT':
                $tm = $tlm = [1, 0, 0, 1, 0, 0];
                break;
            case 'Tf':
                $font = $fonts[ltrim($stack[count($stack) - 2] ?? '', '/')] ?? null;
                $size = $n(1);
                break;
            case 'Tc':
                $char_space = $n(1);
                break;
            case 'Tw':
                $word_space = $n(1);
                break;
            case 'Tz':
                $scale = $n(1) / 100;
                break;
            case 'TL':
                $leading = $n(1);
                break;
            case 'Ts':
                $rise = $n(1);
                break;
            case 'Td':
                $tm = $tlm = matrix_multiply([1, 0, 0, 1, $n(2), $n(1)], $tlm);
                break;
            case 'TD':
                $leading = -$n(1);
                $tm = $tlm = matrix_multiply([1, 0, 0, 1, $n(2), $n(1)], $tlm);
                break;
            case 'Tm':
                $tm = $tlm = [$n(6), $n(5), $n(4), $n(3), $n(2), $n(1)];
                break;
            case 'T*':
                $tm = $tlm = matrix_multiply([1, 0, 0, 1, 0, -$leading], $tlm);
                break;
            case "'":
            case '"':
                if ($token === '"') {
                    $word_space = $n(3);
                    $char_space = $n(2);
                }
                $tm = $tlm = matrix_multiply([1, 0, 0, 1, 0, -$leading], $tlm);
                $show(end($stack) ?: '');
                break;
            case 'Tj':
                $show(end($stack) ?: '');
                break;
            case 'TJ':
                $array = end($stack) ?: '[]';
                preg_match_all('#\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]*>|-?\d*\.?\d+#s', substr($array, 1, -1), $parts);
                foreach ($parts[0] as $part) {
                    if (is_numeric($part)) {
                        $tm = matrix_multiply([1, 0, 0, 1, -(float) $part / 1000 * $size * $scale, 0], $tm);
                    } else {
                        $show($part);
                    }
                }
                break;
            case 'm':
                $start = $point = $point_at($n(2), $n(1));
                break;
            case 'l':
                $to = $point_at($n(2), $n(1));
                if ($point !== null) {
                    $path[] = [$point, $to];
                }
                $point = $to;
                break;
            case 'h':
                if ($point !== null && $start !== null) {
                    $path[] = [$point, $start];
                }
                break;
            case 're':
                [$x, $y, $w, $h] = [$n(4), $n(3), $n(2), $n(1)];
                $corners = [$point_at($x, $y), $point_at($x + $w, $y + $h)];
                $path[] = ['rect' => $corners];
                break;
            case 'S': case 's': case 'f': case 'F': case 'f*': case 'B': case 'B*': case 'b': case 'b*':
                $stroked = in_array($token, ['S', 's', 'B', 'B*', 'b', 'b*'], true);
                $line_width = $state['width'] * hypot($state['ctm'][2], $state['ctm'][3]);
                foreach ($path as $segment) {
                    if (isset($segment['rect'])) {
                        [[$ax, $ay], [$bx, $by]] = $segment['rect'];
                        $color = $stroked && !in_array($token, ['B', 'B*', 'b', 'b*'], true) ? $state['stroke'] : $state['fill'];
                    } elseif ($stroked) {
                        [[$ax, $ay], [$bx, $by]] = $segment;
                        $half = $line_width / 2;
                        $ay -= $half;
                        $by += $half;
                        $color = $state['stroke'];
                    } else {
                        continue;
                    }
                    $lines[] = ['x0' => min($ax, $bx), 'x1' => max($ax, $bx), 'y0' => $height - max($ay, $by), 'y1' => $height - min($ay, $by), 'color' => $color];
                }
                $path = [];
                $point = $start = null;
                break;
            case 'n':
                $path = [];
                $point = $start = null;
                break;
            case 'Do':
                // a form drawn on the page (some PDF makers put all the text in one)
                $name = ltrim($stack[count($stack) - 1] ?? '', '/');
                $xobjects = (string) pdf_value((string) $resources, 'XObject', $objects);
                if ($depth < 3 && preg_match('#/' . preg_quote($name, '#') . '\s+(\d+)\s+\d+\s+R#', $xobjects, $ref)) {
                    $form = $objects[(int) $ref[1]] ?? null;
                    if ($form && str_contains($form['dict'], '/Form')) {
                        $form_resources = pdf_value($form['dict'], 'Resources', $objects) ?? $resources;
                        $matrix = pdf_numbers(pdf_value($form['dict'], 'Matrix', $objects));
                        $inner = pdf_page_marks(pdf_stream($form), pdf_resource_fonts($form_resources, $objects) + $fonts, $height,
                            $objects, $form_resources, $depth + 1, matrix_multiply(count($matrix) === 6 ? $matrix : [1, 0, 0, 1, 0, 0], $state['ctm']));
                        array_push($texts, ...$inner['texts']);
                        array_push($lines, ...$inner['lines']);
                    }
                }
                break;
        }
        $stack = [];
    }
    return ['texts' => $texts, 'lines' => $lines];
}

/**
 * Text pieces grouped like PyMuPDF's get_text("dict"): spans (one font, size and colour) in lines (one baseline, no
 * wide gap) in blocks (lines close together). Each line: block, spans, bbox, text, origin (baseline y).
 */
function group_text_lines(array $texts): array
{
    usort($texts, fn($a, $b) => [round($a['base'], 1), $a['x0']] <=> [round($b['base'], 1), $b['x0']]);
    $rows = [];
    foreach ($texts as $piece) {
        $placed = false;
        foreach (array_reverse(array_keys($rows)) as $key) {
            $row = &$rows[$key];
            $last = end($row);
            $tolerance = max(1.0, min($last['size'], $piece['size']) * 0.3);
            if (abs($last['base'] - $piece['base']) <= $tolerance) {
                $gap = $piece['x0'] - $last['x1'];
                if ($gap > -$piece['size'] && $gap < max($last['size'], $piece['size']) * 3) {
                    $row[] = $piece;
                    $placed = true;
                }
            }
            unset($row);
            if ($placed || abs($rows[$key][0]['base'] - $piece['base']) > 50) {
                break;
            }
        }
        if (!$placed) {
            $rows[] = [$piece];
        }
    }
    $lines = [];
    foreach ($rows as $pieces) {
        $spans = [];
        foreach ($pieces as $piece) {
            $span = end($spans);
            $same = $span && $span['font'] === $piece['font'] && abs($span['size'] - $piece['size']) < 0.05 && $span['color'] === $piece['color'];
            $space = $span && $piece['x0'] - $span['bbox'][2] > $piece['size'] * 0.15 && !str_ends_with($span['text'], ' ') && !str_starts_with($piece['text'], ' ');
            if ($same) {
                $last = count($spans) - 1;
                $spans[$last]['text'] .= ($space ? ' ' : '') . $piece['text'];
                $spans[$last]['bbox'][2] = max($spans[$last]['bbox'][2], $piece['x1']);
                continue;
            }
            if ($space) {
                $spans[count($spans) - 1]['text'] .= ' ';
            }
            $spans[] = ['text' => $piece['text'], 'font' => $piece['font'], 'size' => $piece['size'], 'flags' => $piece['flags'],
                'color' => $piece['color'], 'origin' => [$piece['x0'], $piece['base']],
                'bbox' => [$piece['x0'], $piece['base'] - $piece['ascent'] * $piece['size'], $piece['x1'], $piece['base'] - $piece['descent'] * $piece['size']]];
        }
        $spans = array_values(array_filter(array_map(function ($s) {
            $s['text'] = strtr($s['text'], ["\u{200B}" => ' ', "\u{200C}" => ' ', "\u{200D}" => ' ', "\u{FEFF}" => ' ', "\u{00A0}" => ' ']);
            return $s;
        }, $spans), fn($s) => trim($s['text']) !== ''));
        if (!$spans) {
            continue;
        }
        $bbox = [min(array_map(fn($s) => $s['bbox'][0], $spans)), min(array_map(fn($s) => $s['bbox'][1], $spans)),
            max(array_map(fn($s) => $s['bbox'][2], $spans)), max(array_map(fn($s) => $s['bbox'][3], $spans))];
        $lines[] = ['spans' => $spans, 'bbox' => $bbox, 'text' => trim(implode('', array_column($spans, 'text'))), 'origin' => $spans[0]['origin'][1]];
    }
    usort($lines, fn($a, $b) => [$a['bbox'][1], $a['bbox'][0]] <=> [$b['bbox'][1], $b['bbox'][0]]);
    // Blocks (paragraphs), as PyMuPDF makes them: a line joins the block above it when it starts near the same left
    // edge and sits no more than a line and a half below; a bulleted line only joins other bulleted lines.
    $block = -1;
    $open = [];
    foreach ($lines as $i => $line) {
        $size = $line['spans'][0]['size'];
        $found = null;
        $bulleted = isset(DESIGN_BULLETS[mb_substr(ltrim($line['text']), 0, 1)]);
        foreach ($open as $number => [$last, $last_bulleted]) {
            $gap = $line['origin'] - $last['origin'];
            if ($bulleted === $last_bulleted && abs($last['bbox'][0] - $line['bbox'][0]) < $size * 3 && $gap > 0 && $gap <= $size * 1.5) {
                $found = $number;
            }
        }
        if ($found === null) {
            $found = ++$block;
        }
        $lines[$i]['block'] = $found;
        $open[$found] = [$line, $bulleted];
    }
    return $lines;
}

/** The first page of a PDF read for measuring: width, height, pages, lines, rules (thin lines), later (pages 2-6). */
function first_pdf_page(string $data): array
{
    $objects = pdf_objects($data);
    $numbers = pdf_page_numbers($data, $objects);
    if (!$numbers) {
        throw new InvalidArgumentException('The PDF has no pages.');
    }
    $read = function (int $number) use ($objects): array {
        $box = pdf_numbers(pdf_inherited($number, 'MediaBox', $objects)) ?: [0, 0, 612, 792];
        $width = abs($box[2] - $box[0]);
        $height = abs($box[3] - $box[1]);
        $resources = pdf_inherited($number, 'Resources', $objects);
        $fonts = pdf_resource_fonts($resources, $objects);
        $content = '';
        $contents = pdf_value($objects[$number]['dict'], 'Contents', $objects);
        foreach (pdf_refs_of($objects[$number]['dict'], 'Contents') as $ref) {
            $object = $objects[$ref] ?? null;
            if ($object !== null && $object['stream'] === null && preg_match_all('#(\d+)\s+\d+\s+R#', $object['dict'], $parts)) {
                foreach ($parts[1] as $part) {
                    $content .= pdf_stream($objects[(int) $part] ?? null) . "\n";
                }
            } else {
                $content .= pdf_stream($object) . "\n";
            }
        }
        unset($contents);
        // the page's own origin may not be at (0, 0)
        $marks = pdf_page_marks($content, $fonts, $height + $box[1], $objects, $resources, 0, [1, 0, 0, 1, -$box[0], 0]);
        return [$width, $height, $marks];
    };
    [$width, $height, $marks] = $read($numbers[0]);
    $later = [];
    foreach (array_slice($numbers, 1, 5) as $number) {
        foreach (group_text_lines($read($number)[2]['texts']) as $line) {
            $first = $line['spans'][0];
            $later[] = [$line['text'], family_name($first['font']), half_point($first['size']), (bool) ($first['flags'] & SPAN_BOLD)];
        }
    }
    $rules = array_values(array_filter($marks['lines'], fn($r) => $r['y1'] - $r['y0'] < 2.5 && $r['x1'] - $r['x0'] > $width * 0.3));
    return ['width' => $width, 'height' => $height, 'pages' => count($numbers), 'lines' => group_text_lines($marks['texts']),
        'rules' => $rules, 'later' => $later];
}

// --- Measuring the layout ------------------------------------------------------------------------------------

function is_upper_text(string $text): bool
{
    return preg_match('/\p{L}/u', $text) && mb_strtoupper($text) === $text;
}

function span_bold(array $span): bool
{
    return (bool) ($span['flags'] & SPAN_BOLD) || stripos($span['font'], 'bold') !== false;
}

function span_italic(array $span): bool
{
    return (bool) ($span['flags'] & SPAN_ITALIC) || preg_match('/italic|oblique/i', $span['font']);
}

/** The value counted most (first counted wins ties, like Python's Counter). */
function most_common(array $counts)
{
    $best = null;
    foreach ($counts as $key => $count) {
        if ($best === null || $count > $counts[$best]) {
            $best = $key;
        }
    }
    return $best;
}

/** Fonts, sizes, colours and alignment from the first page (resume_file.measure_pdf_layout). */
function measure_pdf_layout(array $page): array
{
    $lines = $page['lines'];
    if (!$lines) {
        throw new InvalidArgumentException('No text was found in the PDF. Is it a scanned image?');
    }
    $width = $page['width'];
    $weights = $fonts = $colors = [];
    $largest = null;
    foreach ($lines as $l => $line) {
        foreach ($line['spans'] as $s => $span) {
            $length = mb_strlen($span['text']);
            $weights[(string) half_point($span['size'])] = ($weights[(string) half_point($span['size'])] ?? 0) + $length;
            $family = family_name($span['font']);
            $fonts[$family] = ($fonts[$family] ?? 0) + $length;
            $colors[color_hex($span['color'])] = ($colors[color_hex($span['color'])] ?? 0) + 1;
            if ($largest === null || $span['size'] > $lines[$largest[0]]['spans'][$largest[1]]['size']) {
                $largest = [$l, $s];
            }
        }
    }
    $body_size = (float) most_common($weights);
    $big = $lines[$largest[0]]['spans'][$largest[1]];
    $name_center = ($big['bbox'][0] + $big['bbox'][2]) / 2;
    $left = min(array_map(fn($l) => $l['bbox'][0], $lines));
    $headings = [];
    foreach ($lines as $l => $line) {
        $first = $line['spans'][0];
        $text = $line['text'];
        if (($l === $largest[0] && $largest[1] === 0) || mb_strlen($text) > 40 || !preg_match('/[A-Za-z]/', $text)) {
            continue;
        }
        if ($first['size'] >= $body_size + 0.75 || (span_bold($first) && is_upper_text($text))) {
            $headings[] = [$text, $first];
        }
    }
    $heading_sizes = [];
    $accents = [];
    foreach ($headings as [, $span]) {
        $heading_sizes[(string) half_point($span['size'])] = ($heading_sizes[(string) half_point($span['size'])] ?? 0) + 1;
        $accents[color_hex($span['color'])] = ($accents[color_hex($span['color'])] ?? 0) + 1;
    }
    $accents[color_hex($big['color'])] = ($accents[color_hex($big['color'])] ?? 0) + 2;
    arsort($accents);
    $accent = null;
    foreach (array_keys($accents) as $color) {
        if (!is_dark_color($color)) {
            $accent = $color;
            break;
        }
    }
    $text_color = most_common($colors);
    $family = (string) most_common($fonts);
    $upper = count(array_filter($headings, fn($h) => is_upper_text($h[0])));
    $wide_rules = array_filter($page['rules'], fn($r) => $r['x1'] - $r['x0'] > $width * 0.5);
    return [
        'font_family' => $family,
        'font_kind' => in_array($family, SERIF_FAMILIES, true) ? 'serif' : 'sans',
        'body_size' => $body_size,
        'name_size' => half_point($big['size']),
        'heading_size' => $heading_sizes ? (float) most_common($heading_sizes) : $body_size + 1,
        'accent_color' => $accent ?? $text_color,
        'text_color' => $text_color,
        'name_align' => abs($name_center - $width / 2) < $width * 0.08 ? 'center' : 'left',
        'heading_case' => $headings && $upper * 2 >= count($headings) ? 'upper' : 'title',
        'heading_rule' => count($wide_rules) >= 2,
        'margin_in' => max(0.4, min(1.25, round($left / 72 * 20) / 20)),
    ];
}

function page_name(float $width, float $height): string
{
    foreach (['Letter' => [612, 792], 'A4' => [595, 842], 'Legal' => [612, 1008]] as $name => [$w, $h]) {
        if (abs($width - $w) < 4 && abs($height - $h) < 4) {
            return $name;
        }
    }
    return sprintf('%.1f x %.1f in', $width / 72, $height / 72);
}

function text_case(string $text): string
{
    preg_match_all('/\p{L}/u', $text, $letters);
    if ($letters[0] && mb_strtoupper($text) === $text) {
        return 'upper';
    }
    preg_match_all('/[A-Za-z]+/', $text, $words);
    $long = array_filter($words[0], fn($w) => strlen($w) > 3);
    return $long && !array_filter($long, fn($w) => !ctype_upper($w[0])) ? 'title' : 'sentence';
}

function median(array $values): float
{
    sort($values);
    $count = count($values);
    $middle = intdiv($count, 2);
    return $count % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

/** Facts about the first page (design_report.analyze_pdf); null when it has no text. */
function analyze_pdf_page(array $page): ?array
{
    $lines = $page['lines'];
    if (!$lines) {
        return null;
    }
    [$width, $height] = [$page['width'], $page['height']];
    $sizes = $fonts = [];
    foreach ($lines as $line) {
        foreach ($line['spans'] as $span) {
            $length = mb_strlen($span['text']);
            $size = (string) half_point($span['size']);
            $sizes[$size] = ($sizes[$size] ?? 0) + $length;
            $family = family_name($span['font']);
            $fonts[$family] ??= ['chars' => 0, 'sizes' => []];
            $fonts[$family]['chars'] += $length;
            $fonts[$family]['sizes'][$size] = (float) $size;
        }
    }
    $body_size = (float) most_common($sizes);
    $max_size = fn(array $line) => max(array_map(fn($s) => $s['size'], $line['spans']));
    $biggest = 0;
    foreach ($lines as $i => $line) {
        if ($max_size($line) > $max_size($lines[$biggest])) {
            $biggest = $i;
        }
    }
    $big = $lines[$biggest];
    $body_lines = array_filter($lines, fn($l) => abs($l['spans'][0]['size'] - $body_size) < 0.6);
    $body_looks = [];
    foreach ($body_lines as $line) {
        $key = family_name($line['spans'][0]['font']) . "\t" . color_hex($line['spans'][0]['color']);
        $body_looks[$key] = ($body_looks[$key] ?? 0) + 1;
    }
    $body_span = $body_looks ? explode("\t", (string) most_common($body_looks)) : ['', ''];

    $candidate = fn(int $i) => $i !== $biggest && mb_strlen($lines[$i]['text']) <= 40 && preg_match('/[A-Za-z]/', $lines[$i]['text'])
        && !preg_match(DESIGN_CONTACT, $lines[$i]['text']) && $lines[$i]['bbox'][1] > $big['bbox'][3]
        && ($lines[$i]['spans'][0]['size'] >= $body_size + 0.75 || (span_bold($lines[$i]['spans'][0]) && is_upper_text($lines[$i]['text'])));
    $look_key = fn(array $line) => family_name($line['spans'][0]['font']) . "\t" . half_point($line['spans'][0]['size']) . "\t" . (span_bold($line['spans'][0]) ? 1 : 0);
    $groups = [];
    foreach ($lines as $i => $line) {
        if ($candidate($i)) {
            $groups[$look_key($line)] = ($groups[$look_key($line)] ?? 0) + 1;
        }
    }
    // Section headings: the largest look used at least twice (or the most used one); entry titles: the next look down.
    $ranked = array_keys($groups);
    $order = array_flip($ranked);
    usort($ranked, function ($a, $b) use ($groups, $order) {
        $key = fn($k) => [-($groups[$k] >= 2 ? 1 : 0), -(float) explode("\t", $k)[1], -$groups[$k], $order[$k]];
        return $key($a) <=> $key($b);
    });
    $section_key = $ranked[0] ?? null;
    $entry_key = null;
    if ($section_key !== null) {
        [$section_font, $section_size] = explode("\t", $section_key);
        foreach (array_slice($ranked, 1) as $key) {
            [$font, $size] = explode("\t", $key);
            if ((float) $size < (float) $section_size || $font !== $section_font) {
                $entry_key = $key;
                break;
            }
        }
    }
    $headings = $entries = [];
    foreach ($lines as $i => $line) {
        if ($candidate($i) && $look_key($line) === $section_key) {
            $headings[] = $line;
        } elseif ($candidate($i) && $entry_key !== null && $look_key($line) === $entry_key) {
            $entries[] = $line;
        }
    }
    $later_headings = [];
    foreach ($page['later'] as [$text, $family, $size, $bold]) {
        if ($section_key !== null && "$family\t$size\t" . ($bold ? 1 : 0) === $section_key && $text !== '' && mb_strlen($text) <= 40
            && !preg_match(DESIGN_CONTACT, $text)) {
            $later_headings[] = $text;
        }
    }

    $left = min(array_map(fn($l) => $l['bbox'][0], $lines));
    $right = $width - max(array_map(fn($l) => $l['bbox'][2], $lines));
    $top = min(array_map(fn($l) => $l['bbox'][1], $lines));
    $gap_bottom = $height - max(array_map(fn($l) => $l['bbox'][3], $lines));
    $center = ($big['bbox'][0] + $big['bbox'][2]) / 2;

    // Header: everything between the name and the first heading.
    $first_heading_y = $headings ? $headings[0]['bbox'][1] : $height;
    $header = [];
    foreach ($lines as $i => $line) {
        if ($i !== $biggest && $big['bbox'][3] - 1 <= $line['bbox'][1] && $line['bbox'][1] < $first_heading_y) {
            $header[] = $line;
        }
    }
    $joined = implode(' ', array_column($header, 'text'));
    $separators = [];
    foreach (mb_str_split($joined) as $char) {
        if (str_contains(DESIGN_SEPARATORS, $char) && preg_match('/\s' . preg_quote($char, '/') . '\s/u', $joined)) {
            $separators[$char] = ($separators[$char] ?? 0) + 1;
        }
    }
    $header_rule = null;
    foreach ($page['rules'] as $rule) {
        if ($big['bbox'][3] <= $rule['y0'] && $rule['y0'] <= $first_heading_y) {
            $header_rule = $rule;
            break;
        }
    }
    $heading_rules = array_values(array_filter($page['rules'], function ($r) use ($headings) {
        foreach ($headings as $h) {
            if ($r['y0'] - $h['bbox'][3] >= 0 && $r['y0'] - $h['bbox'][3] <= 8) {
                return true;
            }
        }
        return false;
    }));
    $any_rule = $heading_rules[0] ?? $header_rule;

    // Line spacing: baseline distance between neighbouring body lines of the same block.
    $gaps = [];
    $by_block = [];
    foreach ($body_lines as $line) {
        $by_block[$line['block']][] = $line;
    }
    foreach ($by_block as $same) {
        usort($same, fn($a, $b) => $a['origin'] <=> $b['origin']);
        for ($i = 1; $i < count($same); $i++) {
            $gap = $same[$i]['origin'] - $same[$i - 1]['origin'];
            if ($gap > 0 && $gap < $body_size * 2.6) {
                $gaps[] = $gap;
            }
        }
    }
    $spacing = $gaps ? round(round(median($gaps) / $body_size / 0.05) * 0.05, 2) : null;

    // Space above headings, beyond a normal line.
    $before = [];
    foreach ($headings as $h) {
        $above = array_filter($lines, fn($l) => $l['bbox'][3] <= $h['bbox'][1] + 0.5 && $l !== $h);
        if ($above) {
            $before[] = $h['bbox'][1] - max(array_map(fn($l) => $l['bbox'][3], $above));
        }
    }
    $section_gap = $before ? (int) round(median($before)) : null;

    // Bullets: lines that start with a bullet character (Word's symbol bullet comes through as a private character).
    $bullets = [];
    $indents = [];
    foreach ($lines as $line) {
        $first = trim($line['spans'][0]['text']);
        $char = mb_substr($first, 0, 1);
        if ($first !== '' && isset(DESIGN_BULLETS[$char])
            && (mb_strlen($first) === 1 || ctype_space(mb_substr($first, 1, 1)) || count($line['spans']) > 1)) {
            $bullets[DESIGN_BULLETS[$char]] = ($bullets[DESIGN_BULLETS[$char]] ?? 0) + 1;
            $indents[] = $line['bbox'][0] - $left;
        }
    }

    // Two columns: many lines start well to the right while others start at the left margin.
    $right_start = count(array_filter($lines, fn($l) => $l['bbox'][0] > $width * 0.45 && $l['bbox'][2] - $l['bbox'][0] < $width * 0.45));
    $left_start = count(array_filter($lines, fn($l) => $l['bbox'][0] < $width * 0.2 && $l['bbox'][2] < $width * 0.62));
    $columns = $right_start >= 6 && $left_start >= 6 && $right_start >= count($lines) * 0.25 ? 2 : 1;

    $dated = array_filter($lines, fn($l) => preg_match(DESIGN_YEAR, $l['text']));
    $dates_right = count(array_filter($dated, fn($l) => $l['bbox'][0] > $width * 0.55 && $l['bbox'][2] >= $width - $right - 36));

    $look = function (array $line) {
        $s = $line['spans'][0];
        return ['font' => family_name($s['font']), 'size' => half_point($s['size']), 'color' => color_hex($s['color']),
            'bold' => span_bold($s), 'italic' => span_italic($s)];
    };
    $heading_looks = array_map($look, $headings);
    $count_of = function (array $values) {
        $counts = [];
        foreach ($values as $value) {
            $counts[(string) $value] = ($counts[(string) $value] ?? 0) + 1;
        }
        return most_common($counts);
    };
    $cases = array_map(fn($h) => text_case($h['text']), $headings);
    $bold_count = $italic_count = 0;
    foreach ($lines as $line) {
        foreach ($line['spans'] as $span) {
            $bold_count += span_bold($span) ? 1 : 0;
            $italic_count += span_italic($span) ? 1 : 0;
        }
    }
    uasort($fonts, fn($a, $b) => $b['chars'] <=> $a['chars']);
    $font_rows = [];
    foreach ($fonts as $family => $entry) {
        $font_sizes = array_values($entry['sizes']);
        sort($font_sizes);
        $font_rows[] = ['font' => (string) $family, 'chars' => $entry['chars'], 'sizes' => $font_sizes];
    }
    return [
        'page' => page_name($width, $height), 'pages' => $page['pages'],
        'margins' => ['left' => round($left / 72, 2), 'right' => round($right / 72, 2), 'top' => round($top / 72, 2),
            'bottom' => $gap_bottom < 1.5 * 72 ? round($gap_bottom / 72, 2) : null],
        'columns' => $columns,
        'name' => ['text' => $big['text']] + $look($big) + ['align' => abs($center - $width / 2) < $width * 0.08 ? 'center' : 'left'],
        'header' => ['lines' => count($header), 'size' => $header ? $look($header[0])['size'] : null,
            'font' => $header ? $look($header[0])['font'] : null,
            'separator' => $separators ? (string) most_common($separators) : null,
            'rule' => $header_rule !== null, 'sample' => $header ? mb_substr($header[0]['text'], 0, 80) : ''],
        'headings' => ['items' => array_merge(array_column($headings, 'text'), $later_headings),
            'case' => $headings ? $count_of($cases) : null,
            'font' => $heading_looks ? $count_of(array_column($heading_looks, 'font')) : null,
            'size' => $heading_looks ? (float) $count_of(array_column($heading_looks, 'size')) : null,
            'color' => $heading_looks ? $count_of(array_column($heading_looks, 'color')) : null,
            'bold' => $heading_looks ? count(array_filter(array_column($heading_looks, 'bold'))) * 2 >= count($heading_looks) : null,
            'rule' => (bool) $heading_rules,
            'rule_color' => $any_rule ? color_hex($any_rule['color']) : null],
        'entries' => $entries ? ['count' => count($entries)] + $look($entries[0]) : null,
        'body' => ['font' => $body_span[0], 'size' => $body_size, 'color' => $body_span[1], 'spacing' => $spacing],
        'section_gap' => $section_gap,
        'bullets' => ['char' => $bullets ? (string) most_common($bullets) : null, 'count' => array_sum($bullets),
            'indent' => $indents ? round(median($indents) / 72, 2) : null],
        'dates' => ['count' => count($dated), 'right' => $dates_right],
        'emphasis' => ['bold' => $bold_count, 'italic' => $italic_count],
        'fonts' => $font_rows,
    ];
}

/** The extra layout settings the page facts show, to add to the measured ones. */
function layout_from_facts(?array $facts): array
{
    if (!$facts) {
        return [];
    }
    $out = ['page_size' => $facts['page'] === 'A4' ? 'a4' : 'letter'];
    foreach (['left', 'right', 'top', 'bottom'] as $side) {
        if (!empty($facts['margins'][$side])) {
            $out["margin_$side"] = min(1.5, max(0.3, $facts['margins'][$side]));
        }
    }
    if (!empty($facts['body']['spacing'])) {
        $out['line_spacing'] = min(1.8, max(1.0, $facts['body']['spacing']));
    }
    if (!empty($facts['section_gap'])) {
        $out['section_gap'] = min(40, max(2, $facts['section_gap']));
    }
    if (in_array($facts['bullets']['char'], ['•', '–', '-', '·', '▪', '○', '›'], true)) {
        $out['bullet_char'] = $facts['bullets']['char'];
    }
    if (in_array($facts['header']['separator'], ['|', '•', '·', '/'], true)) {
        $out['contact_separator'] = $facts['header']['separator'];
    }
    $out['header_rule'] = $facts['header']['rule'];
    $headings = $facts['headings'];
    if ($headings['size']) {
        $out['heading_size'] = min(24, max(8, $headings['size']));
        $out['heading_case'] = $headings['case'] === 'upper' ? 'upper' : 'title';
        $out['heading_rule'] = $headings['rule'];
        if ($headings['color']) {
            $out['accent_color'] = $headings['color'];
        }
    }
    if ($facts['name']['font'] !== $facts['body']['font']) {
        $out['name_font'] = $facts['name']['font'];
    }
    if ($headings['font'] && $headings['font'] !== $facts['body']['font']) {
        $out['heading_font'] = $headings['font'];
    }
    return $out;
}

// --- Describing the design ------------------------------------------------------------------------------------

function inches_text(float $value): string
{
    return rtrim(rtrim(sprintf('%.2f', $value), '0'), '.') . ' in';
}

function points_text($value): string
{
    return rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
}

/** [[label, sentence]] describing the design; works from the settings alone when there are no page facts. */
function describe_design(?array $facts, array $layout): array
{
    $rows = [];
    if (!$facts) {
        $rows[] = ['Page', 'Only the document\'s styles could be read, so these are the settings it set.'];
        $rows[] = ['Margins', inches_text((float) $layout['margin_in']) . ' all round.'];
        $rows[] = ['Name', "{$layout['font_family']}, " . points_text($layout['name_size']) . " pt, {$layout['name_align']}-aligned, color {$layout['accent_color']}."];
        $rows[] = ['Section Headings', points_text($layout['heading_size']) . ' pt, ' . ($layout['heading_case'] === 'upper' ? 'in capitals' : 'as written')
            . ($layout['heading_rule'] ? ', with a line under each' : '') . '.'];
        $rows[] = ['Body Text', "{$layout['font_family']}, " . points_text($layout['body_size']) . " pt, color {$layout['text_color']}."];
        return $rows;
    }
    $m = $facts['margins'];
    $rows[] = ['Page', "{$facts['page']} paper, {$facts['pages']} page" . ($facts['pages'] != 1 ? 's' : '') . ', '
        . ($facts['columns'] == 2 ? 'two columns' : 'a single column') . '.'];
    $rows[] = ['Margins', 'Left ' . inches_text($m['left']) . ', right ' . inches_text($m['right']) . ', top ' . inches_text($m['top'])
        . ($m['bottom'] ? ', bottom ' . inches_text($m['bottom']) . '.' : '. The page is not full, so the bottom margin cannot be measured.')];
    $n = $facts['name'];
    $rows[] = ['Name', "“{$n['text']}” in {$n['font']}" . ($n['bold'] ? ' bold' : '') . ', ' . points_text($n['size']) . " pt, {$n['align']}-aligned, color {$n['color']}."];
    $h = $facts['header'];
    if ($h['lines']) {
        $rows[] = ['Header and Contact', "{$h['lines']} line" . ($h['lines'] != 1 ? 's' : '') . " under the name in {$h['font']}, " . points_text($h['size']) . ' pt'
            . ($h['separator'] ? ", items separated by “{$h['separator']}”" : '')
            . ($h['rule'] ? ', with a line under the header.' : ', with no line under the header.')];
    } else {
        $rows[] = ['Header and Contact', 'No contact lines were found under the name.'];
    }
    $hd = $facts['headings'];
    if ($hd['items']) {
        $case = ['upper' => 'in capitals', 'title' => 'in Title Case', 'sentence' => 'in sentence case'][$hd['case']] ?? 'as written';
        $rows[] = ['Section Headings', count($hd['items']) . " headings, $case, {$hd['font']}" . ($hd['bold'] ? ' bold' : '') . ', ' . points_text($hd['size']) . ' pt, '
            . "color {$hd['color']}" . ($hd['rule'] ? ", each with a line under it ({$hd['rule_color']})." : ', with no lines under them.')];
        $rows[] = ['Section Order', implode(' → ', $hd['items'])];
    }
    $en = $facts['entries'] ?? null;
    if ($en) {
        $rows[] = ['Job and Entry Titles', "{$en['count']} titles in {$en['font']}" . ($en['bold'] ? ' bold' : '') . ', ' . points_text($en['size']) . " pt, color {$en['color']}."];
    }
    $b = $facts['body'];
    $rows[] = ['Body Text', "{$b['font']}, " . points_text($b['size']) . " pt, color {$b['color']}"
        . ($b['spacing'] ? ', line height ' . sprintf('%.2f', $b['spacing']) . ' times the text size.' : '.')];
    if ($facts['section_gap']) {
        $rows[] = ['Spacing', "About {$facts['section_gap']} pt of space above each section heading."];
    }
    $bl = $facts['bullets'];
    $rows[] = ['Bullets', $bl['count']
        ? "{$bl['count']} bullet points using “{$bl['char']}”" . ($bl['indent'] ? ', indented ' . inches_text($bl['indent']) . '.' : '.')
        : 'No bullet points were found.'];
    $d = $facts['dates'];
    if ($d['count']) {
        $rows[] = ['Dates', $d['right'] * 2 >= $d['count'] ? 'Dates sit at the right edge of the line.' : 'Dates follow the text on the same line.'];
    }
    $e = $facts['emphasis'];
    $rows[] = ['Bold and Italic', "{$e['bold']} bold and {$e['italic']} italic pieces of text."];
    $rows[] = ['Fonts Used', implode('; ', array_map(fn($f) => "{$f['font']} (" . implode(', ', array_map('points_text', $f['sizes'])) . ' pt)',
        array_slice($facts['fonts'], 0, 6)))];
    return $rows;
}

function design_notes_text(array $rows): string
{
    return implode("\n", array_map(fn($row) => "{$row[0]}: {$row[1]}", $rows));
}

function fonts_seen(?array $facts): array
{
    return array_column($facts['fonts'] ?? [], 'font');
}

// --- Word files ---------------------------------------------------------------------------------------------------

/** [text, layout] from a .docx's paragraphs and styles (resume_file.docx_text_and_layout). */
function docx_text_and_layout(string $data): array
{
    $temp = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($temp, $data);
    $zip = new ZipArchive();
    if ($zip->open($temp) !== true) {
        @unlink($temp);
        throw new InvalidArgumentException('That file does not look like a Word .docx file.');
    }
    $document_xml = (string) $zip->getFromName('word/document.xml');
    $styles_xml = (string) $zip->getFromName('word/styles.xml');
    $zip->close();
    @unlink($temp);
    $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $load = function (string $xml) {
        $dom = new DOMDocument();
        if ($xml === '' || !@$dom->loadXML($xml, LIBXML_NONET)) {
            return null;
        }
        return $dom;
    };
    $doc = $load($document_xml);
    if ($doc === null) {
        throw new InvalidArgumentException('That file does not look like a Word .docx file.');
    }
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('w', $ns);
    $attr = fn(?DOMNode $node, string $name) => $node instanceof DOMElement ? $node->getAttributeNS($ns, $name) : '';

    // styles: id => [name, size (pt), bold, color]; plus the Normal style's font
    $styles = [];
    $body_size = 11.0;
    $family = 'Calibri';
    $style_dom = $load($styles_xml);
    if ($style_dom) {
        $sx = new DOMXPath($style_dom);
        $sx->registerNamespace('w', $ns);
        $default_font = $sx->query('//w:docDefaults//w:rFonts')->item(0);
        $default_size = $sx->query('//w:docDefaults//w:sz')->item(0);
        if ($default_font && $attr($default_font, 'ascii') !== '') {
            $family = $attr($default_font, 'ascii');
        }
        if ($default_size && $attr($default_size, 'val') !== '') {
            $body_size = (float) $attr($default_size, 'val') / 2;
        }
        foreach ($sx->query('//w:style') as $style) {
            $id = $attr($style, 'styleId');
            $name = $attr($sx->query('w:name', $style)->item(0), 'val');
            $sz = $sx->query('w:rPr/w:sz', $style)->item(0);
            $font = $sx->query('w:rPr/w:rFonts', $style)->item(0);
            $color = $sx->query('w:rPr/w:color', $style)->item(0);
            $styles[$id] = ['name' => $name, 'size' => $sz ? (float) $attr($sz, 'val') / 2 : null,
                'bold' => $sx->query('w:rPr/w:b', $style)->length > 0, 'color' => $color ? $attr($color, 'val') : ''];
            if ($attr($style, 'default') === '1' && $attr($style, 'type') === 'paragraph') {
                if ($sz) {
                    $body_size = (float) $attr($sz, 'val') / 2;
                }
                if ($font && $attr($font, 'ascii') !== '') {
                    $family = $attr($font, 'ascii');
                }
            }
        }
    }

    $paragraphs = [];
    foreach ($xpath->query('//w:body/w:p') as $p) {
        $runs = [];
        foreach ($xpath->query('.//w:r', $p) as $r) {
            $text = '';
            foreach ($xpath->query('w:t|w:tab|w:br', $r) as $part) {
                $text .= $part->localName === 't' ? $part->textContent : ($part->localName === 'tab' ? "\t" : "\n");
            }
            $sz = $xpath->query('w:rPr/w:sz', $r)->item(0);
            $bold = $xpath->query('w:rPr/w:b', $r)->item(0);
            $color = $xpath->query('w:rPr/w:color', $r)->item(0);
            $runs[] = ['text' => $text, 'size' => $sz ? (float) $attr($sz, 'val') / 2 : null,
                'bold' => $bold ? !in_array($attr($bold, 'val'), ['0', 'false'], true) : null,
                'color' => $color ? $attr($color, 'val') : ''];
        }
        $text = implode('', array_column($runs, 'text'));
        if (trim($text) === '') {
            continue;
        }
        $style_id = $attr($xpath->query('w:pPr/w:pStyle', $p)->item(0), 'val');
        $paragraphs[] = ['text' => $text, 'runs' => $runs, 'style' => $styles[$style_id] ?? ['name' => $style_id, 'size' => null, 'bold' => false, 'color' => ''],
            'align' => $attr($xpath->query('w:pPr/w:jc', $p)->item(0), 'val'),
            'border' => $xpath->query('w:pPr/w:pBdr', $p)->length > 0];
    }
    $text = implode("\n", array_column($paragraphs, 'text'));
    foreach ($xpath->query('//w:tbl/w:tr') as $row) {
        $cells = [];
        foreach ($xpath->query('w:tc', $row) as $cell) {
            if (trim($cell->textContent) !== '') {
                $cells[] = trim($cell->textContent);
            }
        }
        $text .= "\n" . implode(' | ', $cells);
    }

    $size_of = function (array $p) use ($body_size) {
        $sizes = array_filter(array_column($p['runs'], 'size'));
        return $sizes ? max($sizes) : ($p['style']['size'] ?? $body_size);
    };
    $bold_of = fn(array $run, array $p) => $run['bold'] ?? $p['style']['bold'];
    $name = $paragraphs[0] ?? null;
    $headings = array_values(array_filter(array_slice($paragraphs, 1), function ($p) use ($size_of, $bold_of, $body_size) {
        if (mb_strlen($p['text']) > 40) {
            return false;
        }
        $runs = array_filter($p['runs'], fn($r) => trim($r['text']) !== '');
        $all_bold = $runs && !array_filter($runs, fn($r) => !$bold_of($r, $p));
        return str_starts_with(strtolower($p['style']['name']), 'heading') || $size_of($p) > $body_size + 0.5
            || ($all_bold && is_upper_text($p['text']));
    }));
    $colors = [];
    foreach (array_merge($headings, $name ? [$name] : []) as $p) {
        foreach ($p['runs'] as $run) {
            $color = $run['color'] ?: $p['style']['color'];
            if (preg_match('/^[0-9A-Fa-f]{6}$/', $color)) {
                $colors['#' . strtoupper($color)] = ($colors['#' . strtoupper($color)] ?? 0) + 1;
            }
        }
    }
    arsort($colors);
    $accent = '#222222';
    foreach (array_keys($colors) as $color) {
        if (!is_dark_color($color)) {
            $accent = $color;
            break;
        }
    }
    $margin = 0.75;
    $margins = $xpath->query('//w:sectPr/w:pgMar')->item(0);
    if ($margins && $attr($margins, 'left') !== '') {
        $margin = round((float) $attr($margins, 'left') / 1440, 2);
    }
    $family = family_name($family);
    $upper = count(array_filter($headings, fn($p) => is_upper_text($p['text'])));
    return [$text, [
        'font_family' => $family,
        'font_kind' => in_array($family, SERIF_FAMILIES, true) ? 'serif' : 'sans',
        'body_size' => $body_size,
        'name_size' => $name ? $size_of($name) : $body_size * 2,
        'heading_size' => $headings ? $size_of($headings[0]) : $body_size + 1,
        'accent_color' => $accent,
        'text_color' => '#222222',
        'name_align' => $name && $name['align'] === 'center' ? 'center' : 'left',
        'heading_case' => $headings && $upper * 2 >= count($headings) ? 'upper' : 'title',
        'heading_rule' => (bool) array_filter($headings, fn($p) => $p['border']),
        'margin_in' => $margin,
    ]];
}
