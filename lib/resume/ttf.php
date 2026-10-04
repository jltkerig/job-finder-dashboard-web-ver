<?php
// Reading TrueType fonts (.ttf, and the first font of a .ttc) for drawing PDFs: character widths, the glyph for
// each character, and a cut-down copy of the font holding only the letters a résumé uses, so the PDF stays small.

declare(strict_types=1);

final class TrueTypeFont
{
    public string $postscript_name = 'Font';
    public int $units = 1000;
    public array $bbox = [0, 0, 0, 0];
    public int $ascent = 0;
    public int $descent = 0;
    public int $cap_height = 0;
    public float $italic_angle = 0.0;
    public bool $fixed_pitch = false;
    public int $weight = 400;
    /** @var array<int, int> unicode code point => glyph id */
    public array $cmap = [];
    /** @var array<int, int> glyph id => advance width in font units */
    public array $advances = [];
    private string $data;
    private array $tables = [];
    private int $glyph_count = 0;
    private int $loca_format = 0;

    public function __construct(string $data)
    {
        $offset = 0;
        if (substr($data, 0, 4) === 'ttcf') {
            $offset = $this->u32($data, 12); // a collection: the first font in it
        }
        $version = substr($data, $offset, 4);
        if ($version === 'OTTO') {
            throw new RuntimeException('OpenType fonts with PostScript outlines are not supported.');
        }
        if ($version !== "\x00\x01\x00\x00" && $version !== 'true') {
            throw new RuntimeException('Not a TrueType font.');
        }
        $this->data = $data;
        $count = $this->u16($data, $offset + 4);
        for ($i = 0; $i < $count; $i++) {
            $at = $offset + 12 + $i * 16;
            $this->tables[substr($data, $at, 4)] = ['offset' => $this->u32($data, $at + 8), 'length' => $this->u32($data, $at + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap', 'loca', 'glyf'] as $needed) {
            if (!isset($this->tables[$needed])) {
                throw new RuntimeException("The font has no $needed table.");
            }
        }
        $head = $this->tables['head']['offset'];
        $this->units = $this->u16($data, $head + 18) ?: 1000;
        $this->bbox = [$this->s16($data, $head + 36), $this->s16($data, $head + 38), $this->s16($data, $head + 40), $this->s16($data, $head + 42)];
        $this->loca_format = $this->s16($data, $head + 50);
        $hhea = $this->tables['hhea']['offset'];
        $this->ascent = $this->s16($data, $hhea + 4);
        $this->descent = $this->s16($data, $hhea + 6);
        $metrics = $this->u16($data, $hhea + 34);
        $this->glyph_count = $this->u16($data, $this->tables['maxp']['offset'] + 4);
        $hmtx = $this->tables['hmtx']['offset'];
        $last = 0;
        for ($g = 0; $g < $this->glyph_count; $g++) {
            if ($g < $metrics) {
                $last = $this->u16($data, $hmtx + $g * 4);
            }
            $this->advances[$g] = $last;
        }
        $this->cap_height = (int) round($this->ascent * 0.7);
        if (isset($this->tables['OS/2'])) {
            $os2 = $this->tables['OS/2']['offset'];
            $this->weight = $this->u16($data, $os2 + 4);
            if ($this->u16($data, $os2) >= 2 && $this->tables['OS/2']['length'] >= 90) {
                $this->cap_height = $this->s16($data, $os2 + 88) ?: $this->cap_height;
            }
            // Typographic ascender and descender, which PDF viewers expect when they differ from hhea.
            $this->ascent = $this->s16($data, $os2 + 68) ?: $this->ascent;
            $this->descent = $this->s16($data, $os2 + 70) ?: $this->descent;
        }
        if (isset($this->tables['post'])) {
            $post = $this->tables['post']['offset'];
            $this->italic_angle = $this->s16($data, $post + 4) + $this->u16($data, $post + 6) / 65536;
            $this->fixed_pitch = $this->u32($data, $post + 12) !== 0;
        }
        $this->read_cmap();
        $this->read_name();
    }

    /** The glyph for a character (0, the "missing" box, when the font has none). */
    public function glyph(int $code_point): int
    {
        return $this->cmap[$code_point] ?? 0;
    }

    public function has(int $code_point): bool
    {
        return isset($this->cmap[$code_point]);
    }

    /** Width of a UTF-8 string at a size, in points. */
    public function width(string $text, float $size): float
    {
        $units = 0;
        foreach (mb_str_split($text) as $char) {
            $units += $this->advances[$this->glyph(mb_ord($char))] ?? 0;
        }
        return $units * $size / $this->units;
    }

    /**
     * The font with every glyph not in $glyphs emptied (glyph numbers stay the same, so the PDF can use them
     * directly). Pieces of composite glyphs (accented letters) are kept too.
     */
    public function subset(array $glyphs): string
    {
        $keep = [0 => true];
        foreach ($glyphs as $g) {
            $keep[$g] = true;
        }
        $pending = array_keys($keep);
        while ($pending) {
            $g = array_pop($pending);
            foreach ($this->components($g) as $part) {
                if (!isset($keep[$part])) {
                    $keep[$part] = true;
                    $pending[] = $part;
                }
            }
        }
        $glyf = '';
        $loca = [];
        for ($g = 0; $g < $this->glyph_count; $g++) {
            $loca[] = strlen($glyf);
            if (isset($keep[$g])) {
                $piece = $this->glyph_data($g);
                $glyf .= $piece . str_repeat("\0", (4 - strlen($piece) % 4) % 4);
            }
        }
        $loca[] = strlen($glyf);
        $tables = [];
        foreach (['cmap', 'cvt ', 'fpgm', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'prep'] as $tag) {
            if (isset($this->tables[$tag])) {
                $tables[$tag] = $this->table($tag);
            }
        }
        $head = $this->table('head');
        $head = substr_replace($head, "\0\0\0\0", 8, 4);    // checkSumAdjustment, filled in below
        $head = substr_replace($head, "\0\1", 50, 2);       // long offsets in loca
        $tables['head'] = $head;
        $tables['glyf'] = $glyf;
        $tables['loca'] = pack('N*', ...$loca);
        if (isset($this->tables['post'])) {
            $tables['post'] = "\x00\x03\x00\x00" . substr($this->table('post'), 4, 28); // version 3: no glyph names
        }
        ksort($tables, SORT_STRING);
        $count = count($tables);
        $power = 1;
        $log = 0;
        while ($power * 2 <= $count) {
            $power *= 2;
            $log++;
        }
        $font = pack('Nnnnn', 0x00010000, $count, $power * 16, $log, $count * 16 - $power * 16);
        $offset = 12 + $count * 16;
        $directory = '';
        $body = '';
        foreach ($tables as $tag => $content) {
            $directory .= $tag . pack('NNN', self::checksum($content), $offset + strlen($body), strlen($content));
            $body .= $content . str_repeat("\0", (4 - strlen($content) % 4) % 4);
        }
        $font .= $directory . $body;
        $head_at = 12 + $count * 16 + $this->offset_in($tables, 'head');
        $adjust = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
        return substr_replace($font, pack('N', $adjust), $head_at + 8, 4);
    }

    private function offset_in(array $tables, string $wanted): int
    {
        $offset = 0;
        foreach ($tables as $tag => $content) {
            if ($tag === $wanted) {
                return $offset;
            }
            $offset += strlen($content) + (4 - strlen($content) % 4) % 4;
        }
        return 0;
    }

    private static function checksum(string $data): int
    {
        $data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $data) as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }
        return $sum;
    }

    private function table(string $tag): string
    {
        return substr($this->data, $this->tables[$tag]['offset'], $this->tables[$tag]['length']);
    }

    private function glyph_data(int $g): string
    {
        $loca = $this->tables['loca']['offset'];
        if ($this->loca_format === 0) {
            $start = $this->u16($this->data, $loca + $g * 2) * 2;
            $end = $this->u16($this->data, $loca + $g * 2 + 2) * 2;
        } else {
            $start = $this->u32($this->data, $loca + $g * 4);
            $end = $this->u32($this->data, $loca + $g * 4 + 4);
        }
        return $end > $start ? substr($this->data, $this->tables['glyf']['offset'] + $start, $end - $start) : '';
    }

    /** The glyphs a composite glyph is built from. */
    private function components(int $g): array
    {
        $data = $this->glyph_data($g);
        if (strlen($data) < 10 || $this->s16($data, 0) >= 0) {
            return [];
        }
        $parts = [];
        $at = 10;
        do {
            $flags = $this->u16($data, $at);
            $parts[] = $this->u16($data, $at + 2);
            $at += 4 + (($flags & 0x0001) ? 4 : 2);
            if ($flags & 0x0008) {
                $at += 2;
            } elseif ($flags & 0x0040) {
                $at += 4;
            } elseif ($flags & 0x0080) {
                $at += 8;
            }
        } while (($flags & 0x0020) && $at < strlen($data));
        return $parts;
    }

    private function read_cmap(): void
    {
        $base = $this->tables['cmap']['offset'];
        $count = $this->u16($this->data, $base + 2);
        $best = null;
        $best_rank = 0;
        for ($i = 0; $i < $count; $i++) {
            $platform = $this->u16($this->data, $base + 4 + $i * 8);
            $encoding = $this->u16($this->data, $base + 6 + $i * 8);
            $offset = $base + $this->u32($this->data, $base + 8 + $i * 8);
            $format = $this->u16($this->data, $offset);
            $rank = match (true) {
                $format === 12 && ($platform === 3 && $encoding === 10 || $platform === 0) => 3,
                $format === 4 && ($platform === 3 && $encoding === 1 || $platform === 0) => 2,
                $format === 4 && $platform === 3 && $encoding === 0 => 1, // symbol fonts
                default => 0,
            };
            if ($rank > $best_rank) {
                [$best, $best_rank] = [$offset, $rank];
            }
        }
        if ($best === null) {
            return;
        }
        $format = $this->u16($this->data, $best);
        if ($format === 12) {
            $groups = $this->u32($this->data, $best + 12);
            for ($i = 0; $i < $groups; $i++) {
                $at = $best + 16 + $i * 12;
                $start = $this->u32($this->data, $at);
                $end = min($this->u32($this->data, $at + 4), $start + 70000);
                $glyph = $this->u32($this->data, $at + 8);
                for ($c = $start; $c <= $end; $c++) {
                    $this->cmap[$c] = $glyph + $c - $start;
                }
            }
            return;
        }
        $segments = $this->u16($this->data, $best + 6) / 2;
        $ends = $best + 14;
        $starts = $ends + $segments * 2 + 2;
        $deltas = $starts + $segments * 2;
        $ranges = $deltas + $segments * 2;
        for ($s = 0; $s < $segments; $s++) {
            $end = $this->u16($this->data, $ends + $s * 2);
            $start = $this->u16($this->data, $starts + $s * 2);
            $delta = $this->s16($this->data, $deltas + $s * 2);
            $range = $this->u16($this->data, $ranges + $s * 2);
            for ($c = $start; $c <= $end && $c !== 0xFFFF; $c++) {
                if ($range === 0) {
                    $glyph = ($c + $delta) & 0xFFFF;
                } else {
                    $at = $ranges + $s * 2 + $range + ($c - $start) * 2;
                    $glyph = $this->u16($this->data, $at);
                    if ($glyph !== 0) {
                        $glyph = ($glyph + $delta) & 0xFFFF;
                    }
                }
                if ($glyph !== 0) {
                    $this->cmap[$c] = $glyph;
                }
            }
        }
        // Symbol fonts put their characters at U+F020..F0FF; let plain codes reach them too.
        if ($best_rank === 1) {
            foreach ($this->cmap as $c => $glyph) {
                if ($c >= 0xF020 && $c <= 0xF0FF) {
                    $this->cmap[$c - 0xF000] ??= $glyph;
                }
            }
        }
    }

    private function read_name(): void
    {
        if (!isset($this->tables['name'])) {
            return;
        }
        $base = $this->tables['name']['offset'];
        $count = $this->u16($this->data, $base + 2);
        $strings = $base + $this->u16($this->data, $base + 4);
        for ($i = 0; $i < $count; $i++) {
            $at = $base + 6 + $i * 12;
            if ($this->u16($this->data, $at + 6) !== 6) {
                continue; // 6 is the PostScript name
            }
            $platform = $this->u16($this->data, $at);
            $raw = substr($this->data, $strings + $this->u16($this->data, $at + 10), $this->u16($this->data, $at + 8));
            $name = $platform === 1 ? $raw : mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
            $name = preg_replace('/[^A-Za-z0-9_-]/', '', $name);
            if ($name !== '') {
                $this->postscript_name = $name;
                return;
            }
        }
    }

    private function u16(string $data, int $at): int
    {
        return $at >= 0 && $at + 2 <= strlen($data) ? unpack('n', $data, $at)[1] : 0;
    }

    private function s16(string $data, int $at): int
    {
        $value = $this->u16($data, $at);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(string $data, int $at): int
    {
        return $at >= 0 && $at + 4 <= strlen($data) ? unpack('N', $data, $at)[1] : 0;
    }
}
