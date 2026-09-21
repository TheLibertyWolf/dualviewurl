<?php
declare(strict_types=1);

use Duoviewurl\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    Database::connection()->query('SELECT 1')->fetchColumn();
    echo json_encode(['status' => 'ok', 'service' => 'duoviewurl', 'database' => 'ok'], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'service' => 'duoviewurl', 'database' => 'unavailable'], JSON_THROW_ON_ERROR);
}
