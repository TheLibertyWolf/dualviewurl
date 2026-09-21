<?php
declare(strict_types=1);

use Duoviewurl\HtmlRewriter;
use Duoviewurl\HttpProxy;
use Duoviewurl\ProxyException;
use Duoviewurl\RateLimiter;
use Duoviewurl\RemoteSession;
use Duoviewurl\SsrfGuard;

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Access-Control-Allow-Origin: *');
header('Cross-Origin-Resource-Policy: cross-origin');
header('Access-Control-Expose-Headers: Content-Range, Accept-Ranges, Content-Length');
header("Content-Security-Policy: default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: https://challenges.cloudflare.com https://www.google.com https://www.gstatic.com https://js.hcaptcha.com https://newassets.hcaptcha.com; style-src 'self' 'unsafe-inline' https://challenges.cloudflare.com https://www.gstatic.com https://newassets.hcaptcha.com; img-src 'self' data: blob: https://challenges.cloudflare.com https://www.gstatic.com https://www.google.com https://*.hcaptcha.com; font-src 'self' data: https://www.gstatic.com; media-src 'self' data: blob:; connect-src 'self' https://challenges.cloudflare.com https://www.google.com https://recaptcha.google.com https://*.hcaptcha.com; frame-src 'self' https://challenges.cloudflare.com https://www.google.com https://recaptcha.google.com https://*.hcaptcha.com; frame-ancestors https://dualviewurl.jessysystem.com; object-src 'none'; base-uri 'none'; form-action 'self'");

try {
    $limit = max(10, (int) (getenv('DUOVIEW_RATE_LIMIT') ?: 1200));
    (new RateLimiter($limit))->consume($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    $url = (string) ($_GET['url'] ?? '');
    $ua = in_array($_GET['ua'] ?? '', ['desktop', 'iphone', 'ipad', 'android', 'native'], true) ? (string) $_GET['ua'] : 'desktop';
    $theme = in_array($_GET['theme'] ?? '', ['system', 'light', 'dark'], true) ? (string) $_GET['theme'] : 'system';
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $body = null;
    $contentType = null;
    if ($method === 'POST') {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > 2097152) {
            throw new ProxyException('Le formulaire dépasse la taille maximale autorisée.', 413);
        }
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if (!in_array($contentType, ['application/x-www-form-urlencoded', 'application/json', 'text/plain'], true)) {
            throw new ProxyException('Ce type de formulaire POST n’est pas pris en charge.', 415);
        }
        $body = file_get_contents('php://input');
        if (!is_string($body)) {
            throw new ProxyException('Le formulaire transmis est illisible.');
        }
    } else {
        $method = 'GET';
    }
    $proxy = new HttpProxy(
        new SsrfGuard(),
        new HtmlRewriter(),
        max(1048576, (int) (getenv('DUOVIEW_MAX_BYTES') ?: 10485760)),
        max(1, (int) (getenv('DUOVIEW_CONNECT_TIMEOUT') ?: 5)),
        max(2, (int) (getenv('DUOVIEW_TOTAL_TIMEOUT') ?: 15)),
        5,
        RemoteSession::cookieFile(),
    );
    $response = $proxy->fetch(
        $url,
        $ua,
        $theme,
        isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : null,
        $method,
        $body,
        $contentType,
    );
    http_response_code($response['status']);
    header('Content-Type: ' . $response['mime'] . (str_starts_with($response['mime'], 'text/') ? '; charset=utf-8' : ''));
    header('X-Duoviewurl-Url: ' . rawurlencode($response['url']));
    if ($response['contentRange'] !== null) {
        header('Content-Range: ' . $response['contentRange']);
    }
    if ($response['acceptRanges'] !== null) {
        header('Accept-Ranges: ' . $response['acceptRanges']);
    }
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
