<?php
declare(strict_types=1);

use Duoviewurl\Auth;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$user = Auth::requirePage();
$template = file_get_contents(__DIR__ . '/index.html');
if (!is_string($template)) {
    http_response_code(500);
    exit('Interface indisponible.');
}
$values = [
    '{{CSRF_TOKEN}}' => htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'),
    '{{PROXY_TICKET}}' => htmlspecialchars(Auth::proxyTicket((int) $user['id']), ENT_QUOTES, 'UTF-8'),
    '{{USERNAME}}' => htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8'),
    '{{IS_ADMIN}}' => (int) $user['is_admin'] === 1 ? '1' : '0',
];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Vary: Cookie');
echo strtr($template, $values);
