<?php
declare(strict_types=1);

use Duoviewurl\Auth;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$ticket = (string) ($_GET['ticket'] ?? '');
try {
    $accepted = Auth::acceptProxyTicket($ticket);
} catch (Throwable) {
    $accepted = false;
}
if (!$accepted) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Session expirée</title><style>body{background:#080d1c;color:#dce6fa;font:16px system-ui;padding:2rem}</style><h1>Session expirée</h1><p>Reconnectez-vous à Duoviewurl.</p></html>';
    exit;
}
$url = (string) ($_GET['url'] ?? '');
$ua = (string) ($_GET['ua'] ?? 'desktop');
$theme = (string) ($_GET['theme'] ?? 'system');
$query = http_build_query(['url' => $url, 'ua' => $ua, 'theme' => $theme], '', '&', PHP_QUERY_RFC3986);
header('Cache-Control: no-store, private');
header('Location: /proxy.php?' . $query, true, 302);
