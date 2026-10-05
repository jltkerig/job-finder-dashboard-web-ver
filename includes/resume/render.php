<?php
// Draws résumés and cover letters as PDFs in the layout measured from the user's résumé. Ported from the desktop's
// pdf_render.py, which used ReportLab: the same styles, spacing and page breaks, with a small flow layout of
// our own (wrapped paragraphs, rules, and blocks kept together on one page) on top of pdf.php.

declare(strict_types=1);

require_once __DIR__ . '/pdf.php';

const PAGE_SIZES = ['letter' => [612.0, 792.0], 'a4' => [595.276, 841.89]];

/** Every layout setting, with the defaults filling anything an older draft does not have. */
function full_layout(?array $layout): array
{
    return array_merge(DEFAULT_LAYOUT, array_filter($layout ?? [], fn($v) => $v !== null));
}

/** Regular, bold, italic and bold-italic fonts for a family, loaded into the document. */
function render_font_set(PdfDocument $doc, string $family, string $kind): array
{
    $sources = font_sources($family, $kind);
    try {
        return array_map(fn($source) => $doc->font($source), $sources);
    } catch (Throwable $error) {
        error_log("Could not load font $family: " . $error->getMessage());
        return array_map(fn($source) => $doc->font($source), BUILTIN_FONTS[$kind === 'serif' ? 'serif' : 'sans']);
    }
}

/** The four fonts for a role (name, heading or detail); the body font when none is chosen. */
function render_role_fonts(PdfDocument $doc, array $layout, string $key): array
{
    $family = $layout[$key] ?: $layout['font_family'];
    return render_font_set($doc, $family, $layout[$key] ? font_kind($family) : $layout['font_kind']);
}

function render_styles(PdfDocument $doc, array $layout): array
{
    [$regular, $bold, $italic] = render_font_set($doc, $layout['font_family'], $layout['font_kind']);
    $name_bold = render_role_fonts($doc, $layout, 'name_font')[1];
    $heading_bold = render_role_fonts($doc, $layout, 'heading_font')[1];
    [$detail, , $detail_italic] = render_role_fonts($doc, $layout, 'detail_font');
    $size = (float) $layout['body_size'];
    $text = $layout['text_color'];
    $accent = $layout['accent_color'];
    $align = $layout['name_align'] === 'center' ? 'center' : 'left';
    $lead = $size * $layout['line_spacing'];
    $gap = $layout['section_gap'] ?? $size;
    $style = fn(array $s) => $s + ['color' => $text, 'align' => 'left', 'before' => 0.0, 'after' => 0.0, 'indent' => 0.0, 'leading' => $lead, 'size' => $size];
    return [
        'name' => $style(['font' => $name_bold, 'size' => (float) $layout['name_size'], 'leading' => $layout['name_size'] * 1.15, 'color' => $accent, 'align' => $align]),
        'headline' => $style(['font' => $detail, 'size' => $size + 1.5, 'leading' => ($size + 1.5) * 1.3, 'align' => $align, 'before' => 2.0]),
        'contact' => $style(['font' => $detail, 'size' => $size - 0.5, 'align' => $align, 'before' => 3.0]),
        'heading' => $style(['font' => $heading_bold, 'size' => (float) $layout['heading_size'], 'leading' => $layout['heading_size'] * 1.2, 'color' => $accent, 'before' => (float) $gap, 'after' => 2.0]),
        'body' => $style(['font' => $regular]),
        'item' => $style(['font' => $bold, 'before' => $size * 0.5]),
        'sub' => $style(['font' => $detail_italic]),
        'dates' => $style(['font' => $detail, 'align' => 'right', 'before' => $size * 0.5]),
        'bullet' => $style(['font' => $regular, 'indent' => $size * 1.4, 'bullet_indent' => $size * 0.4, 'before' => 1.0]),
        'letter' => $style(['font' => $regular, 'size' => $size + 0.5, 'leading' => ($size + 0.5) * 1.35, 'after' => $size * 0.9]),
    ];
}

/** The text broken into lines no wider than $width (long words are split). */
function wrap_text(PdfFont $font, float $size, string $text, float $width): array
{
    $lines = [];
    foreach (preg_split('/\R/u', $text) as $paragraph) {
        $line = '';
        foreach (preg_split('/ +/u', trim($paragraph)) as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($font->width($try, $size) <= $width) {
                $line = $try;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = $word;
            while ($font->width($line, $size) > $width && mb_strlen($line) > 1) {
                $cut = mb_strlen($line) - 1;
                while ($cut > 1 && $font->width(mb_substr($line, 0, $cut), $size) > $width) {
                    $cut--;
                }
                $lines[] = mb_substr($line, 0, $cut);
                $line = mb_substr($line, $cut);
            }
        }
        $lines[] = $line;
    }
    return $lines;
}

/**
 * A wrapped paragraph as a block piece: ['before', 'after', 'height', 'draw' => fn(doc, x, top)].
 * $bullet is drawn at the style's bullet indent on the first line.
 */
function para(string $text, array $style, float $width, string $bullet = ''): array
{
    $lines = trim($text) === '' ? [] : wrap_text($style['font'], $style['size'], $text, $width - $style['indent']);
    $leading = $style['leading'];
    return ['before' => $style['before'], 'after' => $style['after'], 'height' => count($lines) * $leading,
        'draw' => function (PdfDocument $doc, float $x, float $top) use ($lines, $style, $width, $leading, $bullet) {
            foreach ($lines as $i => $line) {
                // the baseline sits like ReportLab's: font size down from the top of the line, plus half the extra leading
                $base = $top + $i * $leading + $style['size'] + ($leading - $style['size'] * 1.2) / 2;
                $left = $x + $style['indent'];
                $free = $width - $style['indent'] - $style['font']->width($line, $style['size']);
                if ($style['align'] === 'center') {
                    $left += $free / 2;
                } elseif ($style['align'] === 'right') {
                    $left += $free;
                }
                if ($i === 0 && $bullet !== '') {
                    $doc->text($style['font'], $style['size'], $x + $style['bullet_indent'], $base, $bullet, $style['color']);
                }
                $doc->text($style['font'], $style['size'], $left, $base, $line, $style['color']);
            }
        }];
}

function rule(float $thickness, string $color, float $before, float $after): array
{
    return ['before' => $before, 'after' => $after, 'height' => $thickness,
        'draw' => fn(PdfDocument $doc, float $x, float $top, float $width) => $doc->line($x, $top + $thickness / 2, $x + $width, $top + $thickness / 2, $thickness, $color)];
}

/** Pieces side by side (each column a list of pieces), tops aligned, like a one-row table. */
function columns(array $cols, array $widths): array
{
    $heights = [];
    foreach ($cols as $i => $pieces) {
        $heights[$i] = array_sum(array_map(fn($p) => $p['before'] + $p['height'] + $p['after'], $pieces));
    }
    return ['before' => 0.0, 'after' => 0.0, 'height' => max($heights ?: [0]),
        'draw' => function (PdfDocument $doc, float $x, float $top) use ($cols, $widths) {
            foreach ($cols as $i => $pieces) {
                $y = $top;
                foreach ($pieces as $piece) {
                    $y += $piece['before'];
                    ($piece['draw'])($doc, $x, $y, $widths[$i]);
                    $y += $piece['height'] + $piece['after'];
                }
                $x += $widths[$i];
            }
        }];
}

/** Lays out blocks (each a list of pieces kept on one page when it fits) and returns the PDF. */
function flow(PdfDocument $doc, array $blocks, array $margins): string
{
    [$top, $right, $bottom, $left] = $margins;
    $width = $doc->width - $left - $right;
    $limit = $doc->height - $bottom;
    $doc->add_page();
    $y = $top;
    foreach ($blocks as $block) {
        $height = 0.0;
        foreach ($block as $i => $piece) {
            $height += ($i === 0 && $y <= $top ? 0 : $piece['before']) + $piece['height'] + $piece['after'];
        }
        if ($y > $top && $y + $height > $limit && $height - ($block[0]['before'] ?? 0) <= $limit - $top) {
            $doc->add_page();
            $y = $top;
        }
        foreach ($block as $piece) {
            // like ReportLab, space before is dropped at the top of a page
            $y += $y <= $top ? 0 : $piece['before'];
            if ($y > $top && $y + $piece['height'] > $limit) {
                $doc->add_page();
                $y = $top;
            }
            ($piece['draw'])($doc, $left, $y, $width);
            $y += $piece['height'] + $piece['after'];
        }
    }
    return $doc->output();
}

function render_document(array $layout, string $title, string $author): array
{
    [$page_width, $page_height] = PAGE_SIZES[$layout['page_size']] ?? PAGE_SIZES['letter'];
    $doc = new PdfDocument($page_width, $page_height, ['title' => $title, 'author' => $author, 'creator' => 'Résumé Builder']);
    $legacy = min((float) $layout['margin_in'], 0.75); // top and bottom were never wider than 3/4 inch
    $margin = fn(string $key, float $default) => ($layout[$key] ?? $default) * 72;
    $margins = [$margin('margin_top', $legacy), $margin('margin_right', (float) $layout['margin_in']),
        $margin('margin_bottom', $legacy), $margin('margin_left', (float) $layout['margin_in'])];
    return [$doc, $margins, $page_width - $margins[1] - $margins[3]];
}

/** The name, headline, contact line and rule at the top of both documents. */
function render_header(array $content, array $layout, array $styles, float $width, string $headline = ''): array
{
    $block = [para($content['full_name'], $styles['name'], $width)];
    if ($headline !== '') {
        $block[] = para($headline, $styles['headline'], $width);
    }
    $contact = array_values(array_filter(array_map('trim', $content['contact'] ?? []), 'strlen'));
    if ($contact) {
        $block[] = para(implode("  {$layout['contact_separator']}  ", $contact), $styles['contact'], $width);
    }
    if ($layout['header_rule']) {
        $block[] = rule(1.0, $layout['accent_color'], 6.0, 2.0);
    }
    return $block;
}

/** The chosen bullet when the font can draw it; a plain bullet otherwise. */
function render_bullet(PdfFont $font, string $char): string
{
    return $char !== '' && $font->has($char) ? $char : '•';
}

function render_resume(array $content, array $layout): string
{
    $layout = full_layout($layout);
    [$doc, $margins, $width] = render_document($layout, "{$content['full_name']} - Resume", $content['full_name']);
    $styles = render_styles($doc, $layout);
    $bullet = render_bullet($styles['body']['font'], (string) $layout['bullet_char']);
    $blocks = [render_header($content, $layout, $styles, $width, (string) ($content['headline'] ?? ''))];
    foreach ($content['sections'] as $section) {
        $entries = [];
        if (($section['text'] ?? '') !== '') {
            $entries[] = [para($section['text'], $styles['body'], $width)];
        }
        foreach ($section['items'] as $item) {
            $left = [];
            if ($item['heading'] !== '') {
                $left[] = para($item['heading'], $styles['item'], $item['dates'] !== '' ? $width * 0.7 : $width);
            }
            $sub = implode(' — ', array_filter([$item['subheading'], $item['location']], 'strlen'));
            if ($sub !== '') {
                $left[] = para($sub, $styles['sub'], $item['dates'] !== '' ? $width * 0.7 : $width);
            }
            $entry = $item['dates'] !== ''
                ? [columns([$left, [para($item['dates'], $styles['dates'], $width * 0.3)]], [$width * 0.7, $width * 0.3])]
                : $left;
            if ($item['text'] !== '') {
                $entry[] = para($item['text'], $styles['body'], $width);
            }
            foreach ($item['bullets'] as $text) {
                if (trim($text) !== '') {
                    $entry[] = para($text, $styles['bullet'], $width, $bullet);
                }
            }
            if ($entry) {
                $entries[] = $entry;
            }
        }
        array_push($blocks, ...render_section($section['title'], $entries, $layout, $styles, $width));
    }
    if (!empty($content['references'])) {
        $entries = array_map(fn($r) => render_reference($r, $styles, $width), $content['references']);
        array_push($blocks, ...render_section('References', $entries, $layout, $styles, $width));
    }
    return flow($doc, $blocks, $margins);
}

/** A section's heading kept with its first entry, so it never sits alone at the bottom of a page. */
function render_section(string $title, array $entries, array $layout, array $styles, float $width): array
{
    if (!$entries) {
        return [];
    }
    $heading = [para($layout['heading_case'] === 'upper' ? mb_strtoupper($title) : $title, $styles['heading'], $width)];
    if ($layout['heading_rule']) {
        $heading[] = rule(0.6, $layout['accent_color'], 0.0, 3.0);
    }
    $entries[0] = array_merge($heading, $entries[0]);
    return $entries;
}

function render_reference(array $ref, array $styles, float $width): array
{
    $block = [para($ref['name'], $styles['item'], $width)];
    $role = implode(', ', array_filter([$ref['job_title'] ?? '', $ref['company'] ?? ''], 'strlen'));
    if ($role !== '') {
        $block[] = para($role, $styles['body'], $width);
    }
    $relationship = $ref['relationship'] ?? '';
    $user_job = $ref['user_job'] ?? '';
    if ($relationship !== '' && $user_job !== '') {
        $known = "$relationship while I was $user_job";
    } else {
        $known = $relationship !== '' ? $relationship : ($user_job !== '' ? "Worked together when I was $user_job" : '');
    }
    if ($known !== '') {
        $block[] = para($known, $styles['sub'], $width);
    }
    $reach = implode('  |  ', array_filter([$ref['phone'] ?? '', $ref['email'] ?? ''], 'strlen'));
    if ($reach !== '') {
        $block[] = para($reach, $styles['body'], $width);
    }
    return $block;
}

function render_cover_letter(array $content, array $layout): string
{
    $layout = full_layout($layout);
    [$doc, $margins, $width] = render_document($layout, "{$content['full_name']} - Cover Letter", $content['full_name']);
    $styles = render_styles($doc, $layout);
    $spacer = fn(float $height) => ['before' => 0.0, 'after' => 0.0, 'height' => $height, 'draw' => fn() => null];
    $pieces = render_header($content, $layout, $styles, $width);
    $pieces[] = $spacer(14.0);
    $pieces[] = para($content['date'] !== '' ? $content['date'] : date('F j, Y'), $styles['letter'], $width);
    if ($content['recipient']) {
        $pieces[] = para(implode("\n", $content['recipient']), $styles['letter'], $width);
    }
    $pieces[] = para($content['greeting'], $styles['letter'], $width);
    foreach ($content['paragraphs'] as $paragraph) {
        $pieces[] = para($paragraph, $styles['letter'], $width);
    }
    $pieces[] = para($content['closing'], $styles['body'], $width);
    $pieces[] = $spacer(26.0);
    $pieces[] = para($content['signature'] !== '' ? $content['signature'] : $content['full_name'], $styles['body'], $width);
    // a letter flows freely across pages: one piece per block
    return flow($doc, array_map(fn($p) => [$p], $pieces), $margins);
}
