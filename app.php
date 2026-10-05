<?php
// Every request comes here (see .htaccess; run-local.ps1 does the same on this PC). The web addresses match the
// desktop Job Finder's, so its pages and scripts work unchanged.

declare(strict_types=1);

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// On this PC (php -S) real files such as /static/css/style.css are sent as they are.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    if (preg_match('#^/(static|assets)/#', $path)) {
        return false;
    }
    http_response_code(404);
    exit;
}

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/listings.php';

// With 'cloudflare_only' on, visits that skip Cloudflare (straight to the host's address) are turned away, so its
// protections can't be bypassed. The cron job runs from the command line and isn't affected.
if (PHP_SAPI !== 'cli-server' && !empty(config()['cloudflare_only']) && !from_cloudflare()) {
    http_response_code(403);
    exit('Open this site through its domain name.');
}

// On the web: always https, and pages can't be shown inside other sites or guessed as other file types.
if (PHP_SAPI !== 'cli-server' && !is_https() && (config()['force_https'] ?? true)) {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}
send_security_headers();
header('Cache-Control: private, no-store');  // pages are yours: no copies kept by Cloudflare or the browser
if ($path === '/robots.txt') {
    header('Content-Type: text/plain');
    exit("User-agent: *\nDisallow: /\n");
}

$method = $_SERVER['REQUEST_METHOD'];

// Signing in and out are the only pages open without signing in.
if ($path === '/login') {
    require __DIR__ . '/pages/login.php';
    exit;
}
if ($path === '/logout') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {  // a form button with the page's token, so other sites can't sign you out
        check_csrf();
        sign_out();
    }
    redirect('/login');
}

// The Résumé Builder connector: Claude calls these itself and signs in with OAuth, not the site's session.
if (preg_match('#^/(\.well-known/(oauth-authorization-server|oauth-protected-resource|openid-configuration)(/.*)?|oauth/(register|token)|mcp)$#', $path)) {
    require __DIR__ . '/actions/connector.php';
    exit;
}
if ($path === '/oauth/authorize') {
    require __DIR__ . '/pages/authorize.php';
    exit;
}

require_login();
if ($method === 'POST') {
    check_csrf();
}

$routes = [
    'GET /' => 'pages/search.php',
    'GET /dashboard' => 'pages/dashboard.php',
    'GET /rejected-listings' => 'pages/settings.php',
    'GET /tuning' => 'pages/tuning.php',
    'GET /credibility-scores' => 'pages/scores.php',
    'GET /resume-builder' => 'pages/resume.php',
    'GET /extension-profile' => 'actions/extension.php',
    'POST /tuning/settings' => 'actions/tuning.php',
    'POST /save-profile' => 'actions/profile.php',
    'POST /profile/save-title' => 'actions/profile.php',
    'POST /profile/parse-resume' => 'actions/resume.php',
    'GET /job-title-matches' => 'actions/lookups.php',
    'GET /city-matches' => 'actions/lookups.php',
    'GET /job-title-suggestions' => 'actions/lookups.php',
    'GET /skill-related' => 'actions/lookups.php',
    'POST /save-kept' => 'actions/listings.php',
    'POST /settings/password' => 'actions/password.php',
    'POST /settings/blocked-domains' => 'actions/blocklists.php',
    'POST /settings/blocked-companies' => 'actions/blocklists.php',
    'POST /start-search' => 'actions/search.php',
    'POST /refresh-search' => 'actions/search.php',
    'POST /update-existing' => 'actions/search.php',
    'POST /replace-result' => 'actions/search.php',
    'POST /stop-search' => 'actions/search.php',
    'GET /search-status' => 'actions/search.php',
    'GET /captures/pending' => 'actions/captures.php',
    'POST /captures/import' => 'actions/captures.php',
];

$handler = $routes["$method $path"] ?? null;
$route_id = null;
// Buttons on one listing: /reject-listing/12 and the like.
if ($handler === null && $method === 'POST'
    && preg_match('#^/(reject-listing|restore-rejected|block-domain|block-company|unsave-kept|update-kept|delete-kept)/(\d+)$#', $path, $m)) {
    $handler = 'actions/listings.php';
    $path = '/' . $m[1];
    $route_id = (int) $m[2];
}

// Résumé Builder's own addresses: /resume-builder/build/<file> is a page; the rest are its buttons and files.
if ($handler === null && preg_match('#^/resume-builder(/.+)$#', $path, $m)) {
    $rb = $m[1];
    if ($method === 'GET' && preg_match('#^/build/([^/]+)$#', $rb, $file)) {
        $rb_file = $file[1];
        $handler = 'pages/resume_build.php';
    } else {
        $handler = 'actions/resume_builder.php';
    }
}

if ($handler === null) {
    http_response_code(404);
    if (wants_json()) {
        api_error('E2002', 'Not found.', 404);
    }
    exit('Page not found. <a href="/">Back to Job Finder</a>');
}

try {
    require __DIR__ . '/' . $handler;
} catch (Throwable $error) {
    error_log("[E9001] Unhandled error on $path: " . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine());
    if (wants_json() || $method === 'POST') {
        json_out(['status' => 'error', 'error_code' => 'E9001', 'message' => 'The dashboard hit an unexpected server error.'], 500);
    }
    http_response_code(500);
    // Only a signed-in user gets here, so the details are shown to help fix it (they're in the error log too).
    echo 'Internal server error [E9001]: ' . h($error->getMessage() . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')');
}
