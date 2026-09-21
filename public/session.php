<?php
declare(strict_types=1);

use Duoviewurl\RemoteSession;

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
    || ($_SERVER['HTTP_X_DUOVIEWURL_ACTION'] ?? '') !== 'clear-session'
) {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Méthode non autorisée.'], JSON_THROW_ON_ERROR);
    exit;
}

RemoteSession::clear();
echo json_encode(['status' => 'cleared'], JSON_THROW_ON_ERROR);
