<?php
// The connector's addresses that Claude calls itself (no browser session): the OAuth discovery documents,
// app registration, the token endpoint, and /mcp. See includes/connector.php.

declare(strict_types=1);
require_once APP_ROOT . '/includes/connector.php';

// Claude's web app reads the discovery documents from the browser; they hold nothing private.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version, Mcp-Session-Id');
header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');
header('Cache-Control: no-store');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$body = function (): array {
    $raw = (string) file_get_contents('php://input');
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    parse_str($raw, $data);
    return $data;
};

if (str_starts_with($path, '/.well-known/oauth-authorization-server') || str_starts_with($path, '/.well-known/openid-configuration')) {
    json_out(oauth_server_metadata());
}
if (str_starts_with($path, '/.well-known/oauth-protected-resource')) {
    json_out(oauth_resource_metadata());
}
if ($path === '/oauth/register') {
    [$status, $result] = oauth_register($body());
    json_out($result, $status);
}
if ($path === '/oauth/token') {
    [$status, $result] = oauth_token($body());
    header('Pragma: no-cache');
    json_out($result, $status);
}

// --- /mcp ---
if ($method !== 'POST') {
    header('Allow: POST');
    json_out(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'Use POST.']], 405);
}
if (!connector_enabled()) {
    json_out(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'The Résumé Builder connector is turned off.']], 403);
}
if (bearer_client() === null) {
    header('WWW-Authenticate: Bearer resource_metadata="' . site_url() . '/.well-known/oauth-protected-resource"');
    json_out(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Sign in required.']], 401);
}
$request = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($request)) {
    json_out(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']], 400);
}
// One message, or a batch (older protocol versions).
if (array_is_list($request)) {
    $replies = array_values(array_filter(array_map(fn($m) => is_array($m) ? mcp_message($m) : null, $request)));
} else {
    $replies = mcp_message($request);
}
if ($replies === null || $replies === []) {
    http_response_code(202);
    exit;
}
json_out($replies);
