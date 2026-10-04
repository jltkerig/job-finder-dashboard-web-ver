<?php
// Text from an uploaded résumé: Word (.docx) and PDF files, read in plain PHP (shared hosting can't install the
// Python libraries the desktop app uses). Good enough for text-based résumés; a scanned image has no text to read.

declare(strict_types=1);

/** The text of a .docx: its paragraphs, then its table cells, one per line. */
function docx_text(string $data): string
{
    $temp = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($temp, $data);
    $zip = new ZipArchive();
    $text = '';
    if ($zip->open($temp) === true) {
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        $xml = preg_replace('#<w:tab[^>]*/>#', "\t", $xml);
        $xml = preg_replace('#<w:br[^>]*/>#', "\n", $xml);
        $xml = preg_replace('#</w:p>#', "\n", $xml);
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    @unlink($temp);
    return $text;
}

// --- PDF --------------------------------------------------------------------------------------------------

/** The text of a PDF, page by page (up to 20 pages), keeping line breaks where the text moves down a line. */
function pdf_text(string $data): string
{
    $objects = pdf_objects($data);
    $pages = [];
    foreach ($objects as $number => $object) {
        if (preg_match('#/Type\s*/Page(?!s)\b#', $object['dict'])) {
            $pages[] = $number;
        }
    }
    sort($pages);
    $text = '';
    foreach (array_slice($pages, 0, 20) as $number) {
        $page = $objects[$number];
        $fonts = pdf_page_fonts($page['dict'], $objects);
        foreach (pdf_refs_of($page['dict'], 'Contents') as $ref) {
            $content = $objects[$ref] ?? null;
            if ($content === null) {
                continue;
            }
            // Contents can be an array object of streams.
            if ($content['stream'] === null && preg_match_all('#(\d+)\s+0\s+R#', $content['dict'], $parts)) {
                foreach ($parts[1] as $part) {
                    $text .= pdf_content_text(pdf_stream($objects[(int) $part] ?? null), $fonts);
                }
                continue;
            }
            $text .= pdf_content_text(pdf_stream($content), $fonts);
        }
        $text .= "\n";
    }
    return $text;
}

/** [object number => ['dict' => text, 'stream' => raw bytes or null]], including objects inside object streams. */
function pdf_objects(string $data): array
{
    $objects = [];
    preg_match_all('#(\d+)\s+(\d+)\s+obj\b#', $data, $starts, PREG_OFFSET_CAPTURE);
    foreach ($starts[0] as $i => [$header, $offset]) {
        $number = (int) $starts[1][$i][0];
        $begin = $offset + strlen($header);
        $end = strpos($data, 'endobj', $begin);
        if ($end === false) {
            continue;
        }
        $body = substr($data, $begin, $end - $begin);
        $stream = null;
        $at = strpos($body, 'stream');
        if ($at !== false && preg_match('#/Length\b#', substr($body, 0, $at))) {
            $dict = substr($body, 0, $at);
            $raw = ltrim(substr($body, $at + 6), "\r\n");
            $raw = preg_replace('#\s*endstream\s*$#', '', $raw);
            $stream = $raw;
        } else {
            $dict = $body;
        }
        $objects[$number] = ['dict' => $dict, 'stream' => $stream];
    }
    // Compressed object streams (PDF 1.5+) hold many small objects such as fonts and pages.
    foreach ($objects as $object) {
        if (!preg_match('#/Type\s*/ObjStm#', $object['dict'])) {
            continue;
        }
        $content = pdf_stream($object);
        $count = preg_match('#/N\s+(\d+)#', $object['dict'], $m) ? (int) $m[1] : 0;
        $first = preg_match('#/First\s+(\d+)#', $object['dict'], $m) ? (int) $m[1] : 0;
        $pairs = preg_split('#\s+#', trim(substr($content, 0, $first)));
        for ($i = 0; $i < $count; $i++) {
            $num = (int) ($pairs[$i * 2] ?? 0);
            $off = (int) ($pairs[$i * 2 + 1] ?? 0);
            $next = isset($pairs[$i * 2 + 3]) ? (int) $pairs[$i * 2 + 3] : strlen($content) - $first;
            if (!isset($objects[$num])) {
                $objects[$num] = ['dict' => substr($content, $first + $off, $next - $off), 'stream' => null];
            }
        }
    }
    return $objects;
}

function pdf_stream(?array $object): string
{
    if ($object === null || $object['stream'] === null) {
        return '';
    }
    $raw = $object['stream'];
    if (str_contains($object['dict'], 'FlateDecode')) {
        $inflated = @gzuncompress($raw);
        if ($inflated === false) {
            $inflated = @gzinflate(substr($raw, 2));
        }
        return $inflated === false ? '' : $inflated;
    }
    return $raw;
}

/** Object numbers referred to by /Key (one reference, or an array of them). */
function pdf_refs_of(string $dict, string $key): array
{
    if (preg_match('#/' . $key . '\s*(\d+)\s+0\s+R#', $dict, $m)) {
        return [(int) $m[1]];
    }
    if (preg_match('#/' . $key . '\s*\[([^\]]*)\]#', $dict, $m) && preg_match_all('#(\d+)\s+0\s+R#', $m[1], $refs)) {
        return array_map('intval', $refs[1]);
    }
    return [];
}

/** [font resource name => ToUnicode map] for a page (looking up inherited resources as well). */
function pdf_page_fonts(string $page_dict, array $objects): array
{
    $resources = $page_dict;
    for ($depth = 0; $depth < 5 && !str_contains($resources, '/Font'); $depth++) {
        $ref = pdf_refs_of($resources, 'Resources')[0] ?? (pdf_refs_of($resources, 'Parent')[0] ?? null);
        if ($ref === null || !isset($objects[$ref])) {
            break;
        }
        $resources = $objects[$ref]['dict'];
    }
    if (preg_match('#/Font\s*(\d+)\s+0\s+R#', $resources, $m) && isset($objects[(int) $m[1]])) {
        $font_dict = $objects[(int) $m[1]]['dict'];
    } elseif (preg_match('#/Font\s*<<(.*?)>>#s', $resources, $m)) {
        $font_dict = $m[1];
    } else {
        return [];
    }
    $fonts = [];
    preg_match_all('#/([^\s/<>\[\]()]+)\s+(\d+)\s+0\s+R#', $font_dict, $entries, PREG_SET_ORDER);
    foreach ($entries as [, $name, $ref]) {
        $font = $objects[(int) $ref]['dict'] ?? '';
        $map = pdf_refs_of($font, 'ToUnicode')[0] ?? null;
        $fonts[$name] = $map !== null ? pdf_cmap(pdf_stream($objects[$map] ?? null)) : [];
    }
    return $fonts;
}

/** A ToUnicode CMap as [code (hex) => text]. */
function pdf_cmap(string $cmap): array
{
    $map = [];
    $utf = fn(string $hex) => mb_convert_encoding(hex2bin(strlen($hex) % 2 ? "0$hex" : $hex), 'UTF-8', 'UTF-16BE');
    if (preg_match_all('#beginbfchar(.*?)endbfchar#s', $cmap, $blocks)) {
        foreach ($blocks[1] as $block) {
            preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>#', $block, $pairs, PREG_SET_ORDER);
            foreach ($pairs as [, $code, $target]) {
                $map[strtoupper($code)] = $utf($target);
            }
        }
    }
    if (preg_match_all('#beginbfrange(.*?)endbfrange#s', $cmap, $blocks)) {
        foreach ($blocks[1] as $block) {
            preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])#', $block, $ranges, PREG_SET_ORDER);
            foreach ($ranges as [, $low, $high, $target]) {
                $width = strlen($low);
                $start = hexdec($low);
                $end = min(hexdec($high), $start + 2000);
                if ($target[0] === '[') {
                    preg_match_all('#<([0-9A-Fa-f]+)>#', $target, $each);
                    foreach ($each[1] as $n => $hex) {
                        $map[strtoupper(str_pad(dechex($start + $n), $width, '0', STR_PAD_LEFT))] = $utf($hex);
                    }
                } else {
                    $base = hexdec(trim($target, '<>'));
                    for ($code = $start; $code <= $end; $code++) {
                        $map[strtoupper(str_pad(dechex($code), $width, '0', STR_PAD_LEFT))] =
                            $utf(str_pad(dechex($base + $code - $start), 4, '0', STR_PAD_LEFT));
                    }
                }
            }
        }
    }
    return $map;
}

/** The text shown by one page's content stream. */
function pdf_content_text(string $content, array $fonts): string
{
    $out = '';
    $map = [];
    $last_y = null;
    $tokens = '#\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]*>|\[(?:\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]*>|[^\]])*\]|/[^\s/\[\]()<>]+|-?\d*\.?\d+|[A-Za-z\'"*]+#s';
    preg_match_all($tokens, $content, $matches);
    $stack = [];
    foreach ($matches[0] as $token) {
        $first = $token[0];
        if ($first === '(' || $first === '<' || $first === '[' || $first === '/' || is_numeric($token)) {
            $stack[] = $token;
            continue;
        }
        switch ($token) {
            case 'Tf':
                $name = ltrim($stack[count($stack) - 2] ?? '', '/');
                $map = $fonts[$name] ?? [];
                break;
            case 'Tj':
            case "'":
            case '"':
                if ($token !== 'Tj') {
                    $out .= "\n";
                }
                $out .= pdf_show(end($stack) ?: '', $map);
                break;
            case 'TJ':
                $array = end($stack) ?: '';
                preg_match_all('#\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]*>|-?\d*\.?\d+#s', substr($array, 1, -1), $parts);
                foreach ($parts[0] as $part) {
                    if (is_numeric($part)) {
                        if ((float) $part < -200) {
                            $out .= ' '; // a wide gap between words
                        }
                    } else {
                        $out .= pdf_show($part, $map);
                    }
                }
                break;
            case 'Td':
            case 'TD':
                $dy = (float) ($stack[count($stack) - 1] ?? 0);
                $out .= abs($dy) > 0.5 ? "\n" : ' ';
                break;
            case 'Tm':
                $y = (float) ($stack[count($stack) - 1] ?? 0);
                if ($last_y !== null && abs($y - $last_y) > 0.5) {
                    $out .= "\n";
                } elseif ($last_y !== null) {
                    $out .= ' ';
                }
                $last_y = $y;
                break;
            case 'T*':
                $out .= "\n";
                break;
            case 'ET':
                $out .= ' ';
                break;
        }
        $stack = [];
    }
    return $out . "\n";
}

/** One shown string: a literal (with escapes) or hex string, through the font's map when it has one. */
function pdf_show(string $string, array $map): string
{
    if ($string === '') {
        return '';
    }
    if ($string[0] === '<') {
        $hex = strtoupper(preg_replace('/\s+/', '', substr($string, 1, -1)));
        if ($map) {
            $width = strlen((string) array_key_first($map)) ?: 4;
            $text = '';
            for ($i = 0; $i < strlen($hex); $i += $width) {
                $text .= $map[substr($hex, $i, $width)] ?? '';
            }
            return $text;
        }
        return pdf_latin((string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex));
    }
    $raw = substr($string, 1, -1);
    $bytes = preg_replace_callback('#\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r?\n)#', function ($m) {
        $c = $m[1];
        $simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", '(' => '(', ')' => ')', '\\' => '\\'];
        if (isset($simple[$c])) {
            return $simple[$c];
        }
        if ($c[0] === "\r" || $c[0] === "\n") {
            return '';
        }
        return chr(octdec($c) & 0xFF);
    }, $raw);
    if ($map) {
        $width = intdiv(strlen((string) array_key_first($map)) ?: 2, 2);
        $text = '';
        for ($i = 0; $i < strlen($bytes); $i += $width) {
            $text .= $map[strtoupper(bin2hex(substr($bytes, $i, $width)))] ?? '';
        }
        return $text;
    }
    return pdf_latin($bytes);
}

/** Single-byte PDF text (WinAnsi, close to Windows-1252) as UTF-8. */
function pdf_latin(string $bytes): string
{
    return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
}
