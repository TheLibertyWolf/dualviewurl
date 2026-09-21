<?php
declare(strict_types=1);

use Duoviewurl\HtmlRewriter;
use Duoviewurl\HttpProxy;
use Duoviewurl\ProxyException;
use Duoviewurl\RateLimiter;
use Duoviewurl\SsrfGuard;

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Access-Control-Allow-Origin: *');
header('Cross-Origin-Resource-Policy: cross-origin');
header("Content-Security-Policy: default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob:; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; media-src 'self' data: blob:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");

try {
    $limit = max(10, (int) (getenv('DUOVIEW_RATE_LIMIT') ?: 1200));
    (new RateLimiter($limit))->consume($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    $url = (string) ($_GET['url'] ?? '');
    $ua = in_array($_GET['ua'] ?? '', ['desktop', 'iphone', 'ipad', 'android', 'native'], true) ? (string) $_GET['ua'] : 'desktop';
    $theme = in_array($_GET['theme'] ?? '', ['system', 'light', 'dark'], true) ? (string) $_GET['theme'] : 'system';
    $proxy = new HttpProxy(
        new SsrfGuard(),
        new HtmlRewriter(),
        max(1048576, (int) (getenv('DUOVIEW_MAX_BYTES') ?: 10485760)),
        max(1, (int) (getenv('DUOVIEW_CONNECT_TIMEOUT') ?: 5)),
        max(2, (int) (getenv('DUOVIEW_TOTAL_TIMEOUT') ?: 15)),
    );
    $response = $proxy->fetch($url, $ua, $theme);
    http_response_code($response['status']);
    header('Content-Type: ' . $response['mime'] . (str_starts_with($response['mime'], 'text/') ? '; charset=utf-8' : ''));
    header('X-Duoviewurl-Url: ' . rawurlencode($response['url']));
    echo $response['body'];
} catch (ProxyException $exception) {
    http_response_code($exception->httpStatus);
    header('Content-Type: text/html; charset=utf-8');
    $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="color-scheme" content="dark"><title>Erreur Duoviewurl</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#080d1c;color:#dbe6ff;font:16px system-ui}.box{max-width:34rem;padding:2rem;border:1px solid #293454;border-radius:16px;background:#10172b}h1{font-size:1.25rem;color:#67e8f9}</style><div class="box"><h1>Impossible d’afficher ce site</h1><p>' . $message . '</p></div></html>';
} catch (Throwable) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="color-scheme" content="dark"><title>Erreur</title><p>Une erreur interne est survenue.</p></html>';
}
