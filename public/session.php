<?php
declare(strict_types=1);

use Duoviewurl\RemoteSession;
use Duoviewurl\Auth;
use Duoviewurl\AuthException;

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin === 'https://dualviewurl.jessysystem.com') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: X-Duoviewurl-Action');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code($origin === 'https://dualviewurl.jessysystem.com' ? 204 : 403);
    exit;
}

try {
    Auth::requireProxy();
} catch (AuthException $error) {
    http_response_code($error->httpStatus);
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
    exit;
}

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
