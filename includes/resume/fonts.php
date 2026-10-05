<?php
// Résumé fonts: the ones installed here, Google Fonts (downloaded once into data/resume/fonts), look-alikes for
// fonts that aren't available, and the type on the portfolio site. Only font names ever go to Google Fonts, and
// only jamiekerig.com is ever read. Ported from the desktop Résumé Builder's design.py.

declare(strict_types=1);

// Google Fonts worth offering for a résumé, with a rough class used to suggest look-alikes.
const GOOGLE_FONTS = [
    'Roboto' => 'sans', 'Open Sans' => 'sans', 'Lato' => 'sans', 'Montserrat' => 'sans', 'Source Sans 3' => 'sans',
    'Inter' => 'sans', 'Poppins' => 'sans', 'Nunito' => 'sans', 'Nunito Sans' => 'sans', 'Raleway' => 'sans',
    'Work Sans' => 'sans', 'Mulish' => 'sans', 'PT Sans' => 'sans', 'Noto Sans' => 'sans', 'IBM Plex Sans' => 'sans',
    'Rubik' => 'sans', 'Karla' => 'sans', 'DM Sans' => 'sans', 'Jost' => 'sans', 'Carlito' => 'sans', 'Arimo' => 'sans',
    'Questrial' => 'sans', 'Didact Gothic' => 'sans', 'Libre Franklin' => 'sans',
    'Merriweather' => 'serif', 'Lora' => 'serif', 'Playfair Display' => 'serif', 'Libre Baskerville' => 'serif',
    'EB Garamond' => 'serif', 'Cormorant Garamond' => 'serif', 'Crimson Pro' => 'serif', 'Source Serif 4' => 'serif',
    'Noto Serif' => 'serif', 'PT Serif' => 'serif', 'IBM Plex Serif' => 'serif', 'Unna' => 'serif', 'Vollkorn' => 'serif',
    'Gelasio' => 'serif', 'Tinos' => 'serif', 'Caladea' => 'serif', 'Averia Serif Libre' => 'serif', 'Bitter' => 'serif',
    'Spectral' => 'serif',
    'Roboto Condensed' => 'condensed', 'Fira Sans Extra Condensed' => 'condensed', 'Oswald' => 'condensed',
    'Barlow Condensed' => 'condensed', 'Archivo Narrow' => 'condensed', 'Pragati Narrow' => 'condensed',
    'Sofia Sans Extra Condensed' => 'condensed',
    'Abril Fatface' => 'display', 'Bebas Neue' => 'display', 'Josefin Sans' => 'display',
    'Courier Prime' => 'mono', 'IBM Plex Mono' => 'mono', 'Roboto Mono' => 'mono', 'Space Mono' => 'mono',
    'Caveat' => 'script', 'Dancing Script' => 'script', 'Great Vibes' => 'script',
];

// Fonts people have that are not free or not installed here, and the closest Google Fonts (the first is used
// for drawing when the font itself isn't available: Carlito, Caladea, Arimo and Tinos have the same widths).
const LOOK_ALIKES = [
    'helvetica' => ['Arimo', 'Roboto', 'Inter'], 'helvetica neue' => ['Inter', 'Arimo', 'Roboto'],
    'arial' => ['Arimo', 'Roboto', 'Open Sans'], 'calibri' => ['Carlito', 'Lato', 'Open Sans'],
    'cambria' => ['Caladea', 'Merriweather', 'Source Serif 4'], 'times' => ['Tinos', 'Libre Baskerville', 'Lora'],
    'times new roman' => ['Tinos', 'Libre Baskerville', 'Lora'], 'georgia' => ['Gelasio', 'Merriweather', 'Lora'],
    'garamond' => ['EB Garamond', 'Cormorant Garamond', 'Crimson Pro'], 'minion pro' => ['Crimson Pro', 'Source Serif 4', 'EB Garamond'],
    'gotham' => ['Montserrat', 'Nunito Sans', 'Work Sans'], 'proxima nova' => ['Montserrat', 'Nunito Sans', 'Mulish'],
    'futura' => ['Jost', 'Josefin Sans', 'Questrial'], 'avenir' => ['Nunito Sans', 'Mulish', 'Lato'],
    'myriad pro' => ['Source Sans 3', 'Open Sans', 'PT Sans'], 'baskerville' => ['Libre Baskerville', 'Lora', 'Spectral'],
    'didot' => ['Playfair Display', 'Libre Baskerville', 'Abril Fatface'], 'bodoni' => ['Playfair Display', 'Abril Fatface', 'Libre Baskerville'],
    'century gothic' => ['Didact Gothic', 'Questrial', 'Jost'], 'trebuchet ms' => ['Fira Sans Extra Condensed', 'Karla', 'Rubik'],
    'verdana' => ['Open Sans', 'Noto Sans', 'Nunito Sans'], 'tahoma' => ['Noto Sans', 'PT Sans', 'Open Sans'],
    'segoe ui' => ['Open Sans', 'Noto Sans', 'Source Sans 3'], 'palatino' => ['Libre Baskerville', 'Spectral', 'Lora'],
    'palatino linotype' => ['Libre Baskerville', 'Spectral', 'Lora'], 'book antiqua' => ['Libre Baskerville', 'Spectral', 'EB Garamond'],
    'optima' => ['Lato', 'Nunito Sans', 'Work Sans'], 'gill sans' => ['Lato', 'Nunito Sans', 'Josefin Sans'],
    'franklin gothic' => ['Libre Franklin', 'Roboto Condensed', 'Work Sans'], 'rockwell' => ['Bitter', 'Vollkorn', 'Merriweather'],
    'constantia' => ['Lora', 'Source Serif 4', 'Merriweather'], 'candara' => ['Nunito Sans', 'Lato', 'Mulish'],
    'corbel' => ['Nunito Sans', 'Lato', 'Open Sans'], 'aptos' => ['Inter', 'Open Sans', 'Source Sans 3'],
    'sf pro' => ['Inter', 'Roboto', 'Open Sans'], 'san francisco' => ['Inter', 'Roboto', 'Open Sans'],
    'lucida' => ['Noto Sans', 'PT Sans', 'Open Sans'], 'courier new' => ['Courier Prime', 'IBM Plex Mono', 'Roboto Mono'],
    'courier' => ['Courier Prime', 'IBM Plex Mono', 'Roboto Mono'],
];
const FALLBACK_BY_KIND = ['serif' => ['Lora', 'Merriweather', 'Source Serif 4'], 'sans' => ['Inter', 'Open Sans', 'Lato'],
    'mono' => ['Courier Prime', 'IBM Plex Mono', 'Roboto Mono'],
    'condensed' => ['Roboto Condensed', 'Fira Sans Extra Condensed', 'Oswald'],
    'script' => ['Caveat', 'Dancing Script', 'Great Vibes']];
const SERIF_FAMILIES = ['Times New Roman', 'Cambria', 'Georgia', 'Garamond', 'Book Antiqua', 'Palatino Linotype'];

// Regular, bold, italic, bold italic file names in the Windows font folders (only on a Windows PC).
const SYSTEM_FONT_FILES = [
    'Arial' => ['arial.ttf', 'arialbd.ttf', 'ariali.ttf', 'arialbi.ttf'],
    'Calibri' => ['calibri.ttf', 'calibrib.ttf', 'calibrii.ttf', 'calibriz.ttf'],
    'Cambria' => ['cambria.ttc', 'cambriab.ttf', 'cambriai.ttf', 'cambriaz.ttf'],
    'Georgia' => ['georgia.ttf', 'georgiab.ttf', 'georgiai.ttf', 'georgiaz.ttf'],
    'Times New Roman' => ['times.ttf', 'timesbd.ttf', 'timesi.ttf', 'timesbi.ttf'],
    'Garamond' => ['GARA.TTF', 'GARABD.TTF', 'GARAIT.TTF', 'GARABD.TTF'],
    'Verdana' => ['verdana.ttf', 'verdanab.ttf', 'verdanai.ttf', 'verdanaz.ttf'],
    'Tahoma' => ['tahoma.ttf', 'tahomabd.ttf', 'tahoma.ttf', 'tahomabd.ttf'],
    'Segoe UI' => ['segoeui.ttf', 'segoeuib.ttf', 'segoeuii.ttf', 'segoeuiz.ttf'],
    'Trebuchet MS' => ['trebuc.ttf', 'trebucbd.ttf', 'trebucit.ttf', 'trebucbi.ttf'],
    'Book Antiqua' => ['BKANT.TTF', 'ANTQUAB.TTF', 'ANTQUAI.TTF', 'ANTQUABI.TTF'],
    'Century Gothic' => ['GOTHIC.TTF', 'GOTHICB.TTF', 'GOTHICI.TTF', 'GOTHICBI.TTF'],
    'Palatino Linotype' => ['pala.ttf', 'palab.ttf', 'palai.ttf', 'palabi.ttf'],
    'Aptos' => ['Aptos.ttf', 'Aptos-Bold.ttf', 'Aptos-Italic.ttf', 'Aptos-Bold-Italic.ttf'],
];
const BUILTIN_FONTS = ['serif' => ['Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic'],
    'sans' => ['Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique']];
const FONT_STYLES = ['regular', 'bold', 'italic', 'bolditalic'];

// The type on jamiekerig.com, as read from its stylesheet (h1, h2, h3/h4 and the article text).
const PORTFOLIO_SITE = 'https://jamiekerig.com';
const PORTFOLIO_HOSTS = ['jamiekerig.com', 'www.jamiekerig.com', 'jltkerig.github.io'];
const PORTFOLIO_READ = [
    'checked_at' => '2026-10-02', 'live' => false,
    'roles' => ['Titles (h1, project names)' => 'Unna', 'Headings (h2)' => 'Roboto Condensed',
        'Sub-headings (h3, h4)' => 'Fira Sans Extra Condensed', 'Body text' => 'Merriweather'],
    'accents' => ['Volkhorn (quotes; no longer offered by Google Fonts)', 'Courier Prime (code)', 'Caveat (hand-written hello)'],
    'colors' => ['Dark' => '#353535', 'Accent yellow' => '#F7D136'],
];
// The résumé settings that follow from it. The site's yellow is too pale for white paper, so headings use the dark.
const PORTFOLIO_PRESET = [
    'font_family' => 'Merriweather', 'font_kind' => 'serif', 'name_font' => 'Unna', 'heading_font' => 'Roboto Condensed',
    'detail_font' => 'Fira Sans Extra Condensed', 'text_color' => '#353535', 'accent_color' => '#353535',
    'heading_case' => 'upper', 'heading_rule' => true, 'body_size' => 10, 'name_size' => 26, 'heading_size' => 12,
    'line_spacing' => 1.35,
];
const PORTFOLIO_ROLES = [['Titles (h1, project names)', 'h1'], ['Headings (h2)', 'h2'], ['Sub-headings (h3, h4)', 'h3']];

function font_slug(string $name): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
}

/** 'serif' or 'sans' for a font name (used when a font has to be replaced by a built-in one). */
function font_kind(string $name): string
{
    if (in_array($name, SERIF_FAMILIES, true) || (GOOGLE_FONTS[$name] ?? '') === 'serif') {
        return 'serif';
    }
    $lower = strtolower($name);
    return !str_contains($lower, 'sans') && preg_match('/serif|garamond|baskerville|times|georgia|roman/', $lower) ? 'serif' : 'sans';
}

/** Can fonts be downloaded? Tests turn this off (JOBFINDER_OFFLINE=1) so they never use the network. */
function font_downloads_enabled(): bool
{
    return getenv('JOBFINDER_OFFLINE') !== '1';
}

function system_font_dirs(): array
{
    $dirs = [];
    foreach ([(getenv('WINDIR') ?: 'C:\\Windows') . '\\Fonts', (getenv('LOCALAPPDATA') ?: '') . '\\Microsoft\\Windows\\Fonts'] as $dir) {
        if (PHP_OS_FAMILY === 'Windows' && is_dir($dir)) {
            $dirs[] = $dir;
        }
    }
    return $dirs;
}

function find_system_font(string $file): ?string
{
    foreach (system_font_dirs() as $dir) {
        if (is_file("$dir/$file")) {
            return "$dir/$file";
        }
    }
    return null;
}

/** Families in SYSTEM_FONT_FILES with all four styles installed (only on a Windows PC). */
function installed_fonts(): array
{
    static $found = null;
    if ($found === null) {
        $found = [];
        foreach (SYSTEM_FONT_FILES as $family => $files) {
            if (!array_filter($files, fn($f) => find_system_font($f) === null)) {
                $found[] = $family;
            }
        }
        sort($found);
    }
    return $found;
}

// --- Google Fonts: download once, keep in data/resume/fonts ------------------------------------------------

function google_font_dir(string $family): string
{
    return resume_dir('fonts/' . font_slug($family));
}

/** The four cached TTF paths (missing styles borrow the nearest one), or null when nothing is cached. */
function cached_google_files(string $family): ?array
{
    $folder = google_font_dir($family);
    $found = [];
    foreach (FONT_STYLES as $style) {
        if (is_file("$folder/$style.ttf")) {
            $found[$style] = "$folder/$style.ttf";
        }
    }
    if (!isset($found['regular'])) {
        return null;
    }
    $bold = $found['bold'] ?? $found['regular'];
    $italic = $found['italic'] ?? $found['regular'];
    return [$found['regular'], $bold, $italic, $found['bolditalic'] ?? $found['italic'] ?? $bold];
}

/** [[style, weight] => url] from a Google Fonts stylesheet. */
function google_css_files(string $css): array
{
    $files = [];
    preg_match_all('/@font-face\s*\{[^}]*\}/', $css, $blocks);
    foreach ($blocks[0] as $block) {
        if (preg_match('/font-style:\s*(\w+)/', $block, $style) && preg_match('/font-weight:\s*(\d+)/', $block, $weight)
            && preg_match('#url\((https://fonts\.gstatic\.com/[^)]+)\)#', $block, $url)) {
            $files[$style[1] . ' ' . (int) $weight[1]] ??= $url[1];
        }
    }
    return $files;
}

/** Fetch a Google Font's regular, bold and italic files. Returns the cached files or null. */
function download_google_font(string $family): ?array
{
    $cached = cached_google_files($family);
    if ($cached || !font_downloads_enabled() || !isset(GOOGLE_FONTS[$family])) {
        return $cached;
    }
    require_once APP_ROOT . '/includes/search/http.php';
    // An old browser name gets plain .ttf files rather than .woff2.
    $get = fn(string $url, int $limit) => http_request($url, ['agent' => 'Mozilla/4.0', 'timeout' => 10, 'max_bytes' => $limit]);
    $base = 'https://fonts.googleapis.com/css2?family=' . str_replace('%20', '+', rawurlencode($family));
    $files = [];
    foreach ([':ital,wght@0,400;0,700;1,400;1,700', ':wght@400;700', ''] as $axis) {
        try {
            $response = $get($base . $axis, 200000);
        } catch (Throwable) {
            continue;
        }
        if ($response['status'] === 200 && ($files = google_css_files($response['body']))) {
            break;
        }
    }
    if (!$files) {
        return null;
    }
    $folder = google_font_dir($family);
    if (!is_dir($folder)) {
        mkdir($folder, 0775, true);
    }
    $wanted = ['regular' => 'normal 400', 'bold' => 'normal 700', 'italic' => 'italic 400', 'bolditalic' => 'italic 700'];
    foreach ($wanted as $style => $key) {
        $url = $files[$key] ?? ($style === 'regular' ? ($files['normal 400'] ?? reset($files)) : null);
        if (!$url) {
            continue;
        }
        try {
            $data = $get($url, 3000000)['body'];
        } catch (Throwable) {
            continue;
        }
        if (in_array(substr($data, 0, 4), ["\x00\x01\x00\x00", 'true'], true)) { // a real TrueType file, not an error page
            file_put_contents("$folder/$style.ttf", $data);
        }
    }
    return cached_google_files($family);
}

/**
 * The four font sources (file paths, or built-in PDF font names) to draw a family with: installed here, a Google
 * Font (downloaded the first time), its closest Google look-alike, then the built-in font of the same kind.
 */
function font_sources(string $family, string $kind): array
{
    static $memo = [];
    if (isset($memo["$family|$kind"])) {
        return $memo["$family|$kind"];
    }
    $sources = null;
    if (isset(SYSTEM_FONT_FILES[$family]) && in_array($family, installed_fonts(), true)) {
        $sources = array_map('find_system_font', SYSTEM_FONT_FILES[$family]);
    }
    $sources ??= isset(GOOGLE_FONTS[$family]) ? download_google_font($family) : null;
    if ($sources === null) {
        $alike = LOOK_ALIKES[strtolower($family)][0] ?? null;
        $sources = $alike ? (cached_google_files($alike) ?? download_google_font($alike)) : null;
    }
    $sources ??= BUILTIN_FONTS[$kind === 'serif' ? 'serif' : 'sans'];
    return $memo["$family|$kind"] = $sources;
}

/** (installed fonts, Google Fonts) for the pickers; extras (fonts found in the résumé) join the first list. */
function font_choices(array $extra = []): array
{
    $installed = installed_fonts();
    $lead = [];
    foreach ($extra as $font) {
        if ($font && !in_array($font, $installed, true) && !isset(GOOGLE_FONTS[$font]) && !in_array($font, $lead, true)) {
            $lead[] = $font;
        }
    }
    $google = array_keys(GOOGLE_FONTS);
    sort($google);
    return [array_merge($lead, $installed), $google];
}

function similar_fonts(string $name, int $limit = 4): array
{
    $key = strtolower(trim($name));
    if (isset(LOOK_ALIKES[$key])) {
        return array_slice(LOOK_ALIKES[$key], 0, $limit);
    }
    foreach (LOOK_ALIKES as $word => $fonts) {
        if ($word !== '' && str_contains($key, $word)) {
            return array_slice($fonts, 0, $limit);
        }
    }
    $kind = GOOGLE_FONTS[$name] ?? (str_contains($key, 'mono') || str_contains($key, 'courier') ? 'mono'
        : (preg_match('/script|hand|brush/', $key) ? 'script' : (preg_match('/condensed|narrow/', $key) ? 'condensed' : font_kind($name))));
    $same = array_keys(array_filter(GOOGLE_FONTS, fn($k, $f) => $k === $kind && $f !== $name, ARRAY_FILTER_USE_BOTH));
    return array_slice(FALLBACK_BY_KIND[$kind] ?? $same, 0, $limit);
}

/** ['name', 'status' => installed | google | missing | same, 'text', 'similar'] for one font name. */
function font_status(?string $name): array
{
    $name = trim((string) $name);
    if ($name === '') {
        return ['name' => '', 'status' => 'same', 'text' => 'Same as the body font', 'similar' => []];
    }
    if (in_array($name, installed_fonts(), true)) {
        return ['name' => $name, 'status' => 'installed', 'text' => 'Installed on this computer', 'similar' => []];
    }
    if (isset(GOOGLE_FONTS[$name])) {
        $ready = cached_google_files($name) !== null;
        return ['name' => $name, 'status' => 'google', 'similar' => [],
            'text' => $ready ? 'Google Font, saved on this site' : 'Google Font, downloaded the first time it is used'];
    }
    $alike = LOOK_ALIKES[strtolower($name)][0] ?? null;
    return ['name' => $name, 'status' => 'missing', 'similar' => similar_fonts($name),
        'text' => 'Not available here or in Google Fonts' . ($alike ? "; drawn with $alike, which looks much the same" : '')];
}

// --- The portfolio site ------------------------------------------------------------------------------------------

/** One page from the portfolio site; redirects are followed only within it. */
function portfolio_get(string $url, int $limit = 1500000): array
{
    require_once APP_ROOT . '/includes/search/http.php';
    for ($hops = 0; $hops < 5; $hops++) {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host, PORTFOLIO_HOSTS, true)) {
            throw new RuntimeException("The site pointed somewhere else ($host); not followed.");
        }
        $response = http_request($url, ['follow' => false, 'agent' => 'ResumeBuilder/1.0', 'timeout' => 10, 'max_bytes' => $limit]);
        if ($response['status'] >= 300 && $response['status'] < 400 && !empty($response['headers']['location'])) {
            $url = resolve_url($url, $response['headers']['location']);
            continue;
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException("the site answered HTTP {$response['status']}");
        }
        return [$url, substr($response['body'], 0, $limit)];
    }
    throw new RuntimeException('too many redirects');
}

function resolve_url(string $base, string $relative): string
{
    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }
    $parts = parse_url($base);
    $root = $parts['scheme'] . '://' . $parts['host'];
    if (str_starts_with($relative, '//')) {
        return $parts['scheme'] . ':' . $relative;
    }
    if (str_starts_with($relative, '/')) {
        return $root . $relative;
    }
    $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');
    return $root . $dir . $relative;
}

function first_font(string $declaration): string
{
    $first = trim(explode(',', $declaration)[0], " \t\n\r'\"");
    return strtolower($first) === 'inherit' ? '' : $first;
}

/** [role => first font family] for h1, h2, h3 and the article text, plus every family the site imports. */
function read_fonts_from_css(string $css): array
{
    $family = function (string $selector_pattern) use ($css): string {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as [, $selectors, $body]) {
            if (preg_match($selector_pattern, $selectors) && preg_match('/font-family:\s*([^;}]+)/', $body, $found)
                && first_font($found[1]) !== '') {
                return first_font($found[1]);
            }
        }
        return '';
    };
    $roles = [];
    foreach (PORTFOLIO_ROLES as [$label, $tag]) {
        $roles[$label] = $family("/(^|[,\s;])$tag(\s*,|\s*$)/");
    }
    $roles['Body text'] = $family('/\.main-column p/') ?: $family('/(^|[,\s])p(\s*,|\s*$)/') ?: $family('/(^|[,\s])body(\s*,|\s*$)/');
    preg_match_all('/family=([A-Za-z0-9+]+)/', $css, $imported);
    return [array_filter($roles), array_values(array_unique(array_map(fn($f) => str_replace('+', ' ', $f), $imported[1])))];
}

/** Read jamiekerig.com's page and stylesheet again. Returns the result and saves it with the design. */
function check_portfolio(): array
{
    [$final_url, $html] = portfolio_get(PORTFOLIO_SITE);
    preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $html, $sheets);
    $css = '';
    foreach (array_slice($sheets[0], 0, 4) as $tag) {
        if (preg_match('/href=["\']([^"\']+)["\']/', $tag, $href)) {
            $css .= portfolio_get(resolve_url($final_url, html_entity_decode($href[1])))[1] . "\n";
        }
    }
    [$roles, $imported] = read_fonts_from_css($css);
    if (!$roles) {
        throw new RuntimeException("the site's stylesheet did not name any fonts");
    }
    $result = ['checked_at' => date('Y-m-d'), 'live' => true, 'roles' => $roles,
        'accents' => array_values(array_diff($imported, array_values($roles))), 'colors' => PORTFOLIO_READ['colors']];
    $state = design_state();
    $state['portfolio'] = $result;
    write_design_state($state);
    return $result;
}

function portfolio(): array
{
    return design_state()['portfolio'] ?: PORTFOLIO_READ;
}

/** The résumé settings for matching the portfolio, using what the last read of the site found. */
function portfolio_preset(): array
{
    $preset = PORTFOLIO_PRESET;
    $roles = portfolio()['roles'];
    foreach (['Titles (h1, project names)' => 'name_font', 'Headings (h2)' => 'heading_font',
                 'Sub-headings (h3, h4)' => 'detail_font', 'Body text' => 'font_family'] as $label => $key) {
        if (!empty($roles[$label])) {
            $preset[$key] = $roles[$label];
        }
    }
    $preset['font_kind'] = font_kind($preset['font_family']);
    return $preset;
}
