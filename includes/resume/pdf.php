<?php
// A small PDF writer: pages with text and lines, TrueType fonts embedded as subsets (with a map back to the
// characters, so the text can be selected, searched and read by applicant tracking systems), and the PDF's
// built-in Helvetica and Times when no font file is available.

declare(strict_types=1);

require_once __DIR__ . '/ttf.php';

interface PdfFont
{
    public function width(string $text, float $size): float;
    public function has(string $char): bool;
}

final class PdfTrueTypeFont implements PdfFont
{
    public string $resource = '';
    /** @var array<int, int> glyph id => unicode code point, for the glyphs drawn */
    public array $used = [];

    public function __construct(public TrueTypeFont $font)
    {
    }

    public function width(string $text, float $size): float
    {
        return $this->font->width($text, $size);
    }

    public function has(string $char): bool
    {
        return $this->font->has(mb_ord($char));
    }

    /** The text as a hex string of glyph numbers. */
    public function encode(string $text): string
    {
        $hex = '';
        foreach (mb_str_split($text) as $char) {
            $code = mb_ord($char);
            $glyph = $this->font->glyph($code);
            $this->used[$glyph] ??= $code;
            $hex .= sprintf('%04X', $glyph);
        }
        return "<$hex>";
    }
}

final class PdfStandardFont implements PdfFont
{
    public string $resource = '';
    private array $widths;

    public function __construct(public string $name)
    {
        static $tables = null;
        $tables ??= require APP_ROOT . '/resources/fonts/standard-widths.php';
        $this->widths = $tables[$name] ?? $tables['Helvetica'];
    }

    private static function bytes(string $text): string
    {
        $out = '';
        foreach (mb_str_split($text) as $char) {
            $byte = @mb_convert_encoding($char, 'Windows-1252', 'UTF-8');
            $out .= strlen($byte) === 1 && $byte !== '?' || $char === '?' ? $byte : '?';
        }
        return $out;
    }

    public function width(string $text, float $size): float
    {
        $units = 0;
        foreach (str_split(self::bytes($text)) as $byte) {
            $code = ord($byte);
            $units += $code >= 32 ? $this->widths[$code - 32] : 0;
        }
        return $units * $size / 1000;
    }

    public function has(string $char): bool
    {
        return self::bytes($char) !== '?' || $char === '?';
    }

    public function encode(string $text): string
    {
        return '(' . strtr(self::bytes($text), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r']) . ')';
    }
}

final class PdfDocument
{
    private array $pages = [];
    private array $fonts = [];
    private string $current = '';

    public function __construct(public float $width, public float $height, private array $info = [])
    {
    }

    public function add_page(): void
    {
        if ($this->current !== '' || $this->pages) {
            $this->pages[] = $this->current;
        }
        $this->current = '';
    }

    /** A font from a .ttf/.ttc file, or a built-in one by name ("Helvetica-Bold"); each loaded once. */
    public function font(string $source): PdfFont
    {
        if (!isset($this->fonts[$source])) {
            $font = is_file($source)
                ? new PdfTrueTypeFont(new TrueTypeFont((string) file_get_contents($source)))
                : new PdfStandardFont($source);
            $font->resource = 'F' . (count($this->fonts) + 1);
            $this->fonts[$source] = $font;
        }
        return $this->fonts[$source];
    }

    /** Text with its baseline starting at (x, y), measured from the top-left corner of the page. */
    public function text(PdfFont $font, float $size, float $x, float $y, string $text, string $color): void
    {
        if ($text === '') {
            return;
        }
        $this->current .= sprintf("BT %s rg /%s %s Tf 1 0 0 1 %s %s Tm %s Tj ET\n", self::rgb($color), $font->resource,
            self::n($size), self::n($x), self::n($this->height - $y), $font->encode($text));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $thickness, string $color): void
    {
        $this->current .= sprintf("%s RG %s w %s %s m %s %s l S\n", self::rgb($color), self::n($thickness),
            self::n($x1), self::n($this->height - $y1), self::n($x2), self::n($this->height - $y2));
    }

    public function output(): string
    {
        $pages = $this->pages;
        $pages[] = $this->current;
        $objects = [];
        $add = function (string $body) use (&$objects): int {
            $objects[] = $body;
            return count($objects);
        };
        $stream = fn(string $data, string $extra = '') => '<< /Length ' . strlen($data) . " /Filter /FlateDecode$extra >>\nstream\n$data\nendstream";

        $catalog = $add('');
        $tree = $add('');
        $font_refs = '';
        foreach ($this->fonts as $font) {
            $font_refs .= "/$font->resource " . $this->write_font($font, $add, $stream) . ' 0 R ';
        }
        $kids = [];
        foreach ($pages as $content) {
            $content_ref = $add($stream(gzcompress($content, 9)));
            $kids[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources << /Font << %s>> >> /Contents %d 0 R >>',
                $tree, self::n($this->width), self::n($this->height), $font_refs, $content_ref));
        }
        $objects[$catalog - 1] = "<< /Type /Catalog /Pages $tree 0 R >>";
        $objects[$tree - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($k) => "$k 0 R", $kids)) . '] /Count ' . count($kids) . ' >>';
        $info = '';
        foreach (['Title', 'Author', 'Creator'] as $key) {
            if (!empty($this->info[strtolower($key)])) {
                $info .= "/$key " . self::text_string($this->info[strtolower($key)]) . ' ';
            }
        }
        $info_ref = $add("<< {$info}/Producer (Job Finder) /CreationDate (D:" . date('YmdHis') . ') >>');

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $id = md5($pdf);
        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root $catalog 0 R /Info $info_ref 0 R /ID [<$id> <$id>] >>\nstartxref\n$xref\n%%EOF\n";
    }

    private function write_font(PdfFont $font, callable $add, callable $stream): int
    {
        if ($font instanceof PdfStandardFont) {
            return $add("<< /Type /Font /Subtype /Type1 /BaseFont /$font->name /Encoding /WinAnsiEncoding >>");
        }
        /** @var PdfTrueTypeFont $font */
        $ttf = $font->font;
        $used = $font->used;
        ksort($used);
        $program = $ttf->subset(array_keys($used));
        $file_ref = $add($stream(gzcompress($program, 9), ' /Length1 ' . strlen($program)));
        $scale = 1000 / $ttf->units;
        $tag = substr(strtoupper(md5(implode(',', array_keys($used)) . $ttf->postscript_name)), 0, 6);
        $tag = strtr($tag, '0123456789', 'GHIJKLMNOP');
        $name = "$tag+$ttf->postscript_name";
        $flags = 32 | ($ttf->fixed_pitch ? 1 : 0) | ($ttf->italic_angle != 0 ? 64 : 0);
        $descriptor = $add(sprintf('<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%d %d %d %d] /ItalicAngle %s '
            . '/Ascent %d /Descent %d /CapHeight %d /StemV %d /FontFile2 %d 0 R >>', $name, $flags,
            $ttf->bbox[0] * $scale, $ttf->bbox[1] * $scale, $ttf->bbox[2] * $scale, $ttf->bbox[3] * $scale,
            self::n($ttf->italic_angle), $ttf->ascent * $scale, $ttf->descent * $scale, $ttf->cap_height * $scale,
            $ttf->weight >= 600 ? 120 : 80, $file_ref));
        $widths = '';
        foreach ($used as $glyph => $code) {
            $widths .= "$glyph [" . (int) round(($ttf->advances[$glyph] ?? 0) * $scale) . '] ';
        }
        $cid = $add("<< /Type /Font /Subtype /CIDFontType2 /BaseFont /$name /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> "
            . "/FontDescriptor $descriptor 0 R /W [$widths] /CIDToGIDMap /Identity >>");
        $map = '';
        foreach (array_chunk($used, 100, true) as $chunk) {
            $map .= count($chunk) . " beginbfchar\n";
            foreach ($chunk as $glyph => $code) {
                $map .= sprintf("<%04X> <%s>\n", $glyph, strtoupper(bin2hex(mb_convert_encoding(mb_chr($code), 'UTF-16BE', 'UTF-8'))));
            }
            $map .= "endbfchar\n";
        }
        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . $map . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
        $to_unicode = $add($stream(gzcompress($cmap, 9)));
        return $add("<< /Type /Font /Subtype /Type0 /BaseFont /$name /Encoding /Identity-H /DescendantFonts [$cid 0 R] /ToUnicode $to_unicode 0 R >>");
    }

    private static function rgb(string $hex): string
    {
        $hex = preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? $hex : '#000000';
        return implode(' ', array_map(fn($i) => self::n(hexdec(substr($hex, $i, 2)) / 255), [1, 3, 5]));
    }

    private static function n(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
        return $text === '-0' || $text === '' ? '0' : $text;
    }

    /** A PDF text string in UTF-16 (for titles and names with accents). */
    private static function text_string(string $text): string
    {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
