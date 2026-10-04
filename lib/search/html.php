<?php
// Reading web pages: a parsed page with the pieces the search checks look at (title, headings, links, JSON-LD,
// visible text). Plays the part BeautifulSoup plays in the desktop app.

declare(strict_types=1);

final class Page
{
    public string $url;
    public string $html;
    private ?DOMDocument $dom = null;
    private ?DOMXPath $xpath = null;
    private ?array $ld = null;

    public function __construct(string $url, string $html)
    {
        $this->url = $url;
        $this->html = $html;
    }

    public function dom(): DOMDocument
    {
        if ($this->dom === null) {
            $html = $this->html;
            if (!mb_check_encoding($html, 'UTF-8')) {
                $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
            }
            $this->dom = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $this->dom->loadHTML('<?xml encoding="UTF-8">' . ($html === '' ? '<html></html>' : $html),
                LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $this->dom;
    }

    public function xpath(): DOMXPath
    {
        return $this->xpath ??= new DOMXPath($this->dom());
    }

    /** @return DOMElement[] */
    public function all(string $query, ?DOMNode $context = null): array
    {
        $found = $this->xpath()->query($query, $context);
        return $found === false ? [] : iterator_to_array($found);
    }

    public function first(string $query): ?DOMElement
    {
        $found = $this->xpath()->query($query);
        $node = $found && $found->length ? $found->item(0) : null;
        return $node instanceof DOMElement ? $node : null;
    }

    public function title(): string
    {
        $title = $this->first('//title');
        return $title ? node_text($title) : '';
    }

    public function h1(): string
    {
        $heading = $this->first('//h1');
        return $heading ? node_text($heading) : '';
    }

    /** Text of the h1, h2 (and h3) headings, joined. */
    public function headings(array $levels = ['h1', 'h2', 'h3'], int $limit = 0): string
    {
        $query = implode(' | ', array_map(fn($h) => "//$h", $levels));
        $nodes = $this->all($query);
        if ($limit) {
            $nodes = array_slice($nodes, 0, $limit);
        }
        return implode(' ', array_map('node_text', $nodes));
    }

    public function meta(string $attribute, string $value): ?string
    {
        $node = $this->first("//meta[@$attribute='$value']");
        return $node ? $node->getAttribute('content') : null;
    }

    /** [[absolute href, link text, raw href, element], ...] for every <a href>. */
    public function links(): array
    {
        $links = [];
        foreach ($this->all('//a[@href]') as $a) {
            $raw = $a->getAttribute('href');
            $links[] = [url_join($this->url, $raw), node_text($a), $raw, $a];
        }
        return $links;
    }

    /** Every decoded JSON-LD block on the page. */
    public function json_ld(): array
    {
        if ($this->ld === null) {
            $this->ld = [];
            foreach ($this->all("//script[@type='application/ld+json']") as $script) {
                $data = json_decode(trim($script->textContent), true);
                if ($data !== null) {
                    $this->ld[] = $data;
                }
            }
        }
        return $this->ld;
    }

    /** All visible text (scripts and styles left out). */
    public function text(): string
    {
        return node_text($this->dom()->documentElement ?? $this->dom(), true);
    }

    /** Visible text of the main content: no drop-downs, menus, footers, sidebars or "related jobs" lists. */
    public function body_text(): string
    {
        $copy = new Page($this->url, $this->html);
        $noise = '/related|similar|recommended|sidebar|cookie|consent|breadcrumb|newsletter|footer|more[-_ ]jobs|other[-_ ]jobs/i';
        foreach ($copy->all('//select | //option | //datalist | //footer | //nav | //aside | //*[@class] | //*[@id]') as $node) {
            if (!$node->parentNode) {
                continue;
            }
            $name = strtolower($node->nodeName);
            if (in_array($name, ['select', 'option', 'datalist', 'footer', 'nav', 'aside'], true)
                || preg_match($noise, $node->getAttribute('class')) || preg_match($noise, $node->getAttribute('id'))) {
                $node->parentNode->removeChild($node);
            }
        }
        return $copy->text();
    }
}

/** An element's text with runs of whitespace collapsed, like get_text(" ", strip=True). */
function node_text(DOMNode $node, bool $skip_scripts = true): string
{
    $parts = [];
    $walk = function (DOMNode $n) use (&$walk, &$parts, $skip_scripts) {
        foreach ($n->childNodes ?? [] as $child) {
            if ($child instanceof DOMText) {
                $parts[] = $child->nodeValue;
            } elseif ($child instanceof DOMElement) {
                $name = strtolower($child->nodeName);
                if ($skip_scripts && in_array($name, ['script', 'style', 'noscript', 'template'], true)) {
                    continue;
                }
                $walk($child);
            }
        }
    };
    $walk($node);
    return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
}

/** Plain text from an HTML fragment (a feed description or a JSON-LD description). */
function html_to_text(?string $html): string
{
    $html = (string) $html;
    if ($html === '') {
        return '';
    }
    if (!str_contains($html, '<')) {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
    return (new Page('', "<html><body>$html</body></html>"))->text();
}

/** Every dict inside a decoded JSON value, the value itself first (like the desktop's _walk / iter_objects). */
function json_nodes($value): array
{
    $out = [];
    $walk = function ($v) use (&$walk, &$out) {
        if (is_array($v)) {
            if (!array_is_list($v)) {
                $out[] = $v;
            }
            foreach ($v as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        }
    };
    $walk($value);
    return $out;
}

function ld_has_type(array $node, array $kinds): bool
{
    $types = $node['@type'] ?? [];
    $types = is_array($types) ? $types : [$types];
    foreach ($types as $type) {
        $parts = explode('/', (string) $type);
        if (in_array(strtolower(end($parts)), $kinds, true)) {
            return true;
        }
    }
    return false;
}

// --- Addresses ----------------------------------------------------------------------------------------------

/** A link resolved against the page it was on (like urllib's urljoin). */
function url_join(string $base, string $href): string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '') {
        return $base;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
        return $href;
    }
    $parts = parse_url($base);
    if (!$parts || empty($parts['host'])) {
        return $href;
    }
    $scheme = $parts['scheme'] ?? 'https';
    $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if (str_starts_with($href, '//')) {
        return "$scheme:$href";
    }
    if ($href[0] === '#') {
        return preg_replace('/#.*$/', '', $base) . $href;
    }
    if ($href[0] === '?') {
        return "$scheme://$authority" . ($parts['path'] ?? '/') . $href;
    }
    if ($href[0] === '/') {
        $path = $href;
    } else {
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');
        $path = $dir . $href;
    }
    // Resolve ./ and ../ in the path.
    [$path_only, $rest] = array_pad(preg_split('/(?=[?#])/', $path, 2), 2, '');
    $segments = [];
    foreach (explode('/', $path_only) as $segment) {
        if ($segment === '..') {
            array_pop($segments);
        } elseif ($segment !== '.') {
            $segments[] = $segment;
        }
    }
    $resolved = implode('/', $segments);
    if (!str_starts_with($resolved, '/')) {
        $resolved = "/$resolved";
    }
    return "$scheme://$authority$resolved$rest";
}

function get_domain(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

function is_valid_url(string $url): bool
{
    return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
}

const SKIP_QUERY_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'fbclid',
    'gclid', 'applyrequired', 'trk', 'trackingid', 'refid', 'mc_cid', 'mc_eid', 'gh_src', 'lever-source'];

/** One spelling for a page's address: tracking codes removed, query sorted, no trailing slash or #fragment. */
function canonical_url(?string $url): string
{
    $parts = parse_url((string) $url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
        return '';
    }
    $pairs = [];
    foreach (explode('&', $parts['query'] ?? '') as $pair) {
        if ($pair === '') {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $key = urldecode($key);
        if (!in_array(strtolower($key), SKIP_QUERY_KEYS, true) && $value !== '') {
            $pairs[] = [$key, urldecode($value)];
        }
    }
    sort($pairs);
    $query = $pairs ? '?' . implode('&', array_map(fn($p) => rawurlencode($p[0]) . '=' . urlencode($p[1]), $pairs)) : '';
    $path = rtrim($parts['path'] ?? '', '/') ?: '/';
    $netloc = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return strtolower($parts['scheme']) . "://$netloc$path$query";
}
