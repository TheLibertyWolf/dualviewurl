<?php
declare(strict_types=1);

use Duoviewurl\Auth;
use Duoviewurl\HtmlRewriter;
use Duoviewurl\HttpProxy;
use Duoviewurl\SsrfGuard;

require_once dirname(__DIR__) . '/src/bootstrap.php';

function fallbackIcon(): never
{
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#18233c"/><path d="M9 16h14M16 9v14" stroke="#67e8f9" stroke-width="2" stroke-linecap="round"/></svg>';
    exit;
}

try {
    Auth::requireApi();
    $raw = trim((string) ($_GET['url'] ?? ''));
    $parts = parse_url($raw);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
        fallbackIcon();
    }
    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }
    $proxy = new HttpProxy(new SsrfGuard(), new HtmlRewriter(), 1048576, 3, 6, 3);
    $response = $proxy->fetch($origin . '/favicon.ico', 'native', 'system');
    if ($response['status'] >= 400 || !str_starts_with($response['mime'], 'image/')) {
        $page = $proxy->fetch($raw, 'native', 'system');
        if ($page['status'] >= 400 || $page['mime'] !== 'text/html') {
            fallbackIcon();
        }
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($page['body'], LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        $node = $xpath->query('//link[contains(concat(" ", translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " "), " icon ")][@href]')->item(0);
        $rewrittenHref = $node instanceof DOMElement ? $node->getAttribute('href') : '';
        parse_str((string) parse_url($rewrittenHref, PHP_URL_QUERY), $iconQuery);
        $iconUrl = is_string($iconQuery['url'] ?? null) ? $iconQuery['url'] : '';
        if ($iconUrl === '') {
            fallbackIcon();
        }
        $response = $proxy->fetch($iconUrl, 'native', 'system');
        if ($response['status'] >= 400 || !str_starts_with($response['mime'], 'image/')) {
            fallbackIcon();
        }
    }
    header('Content-Type: ' . $response['mime']);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $response['body'];
} catch (Throwable) {
    fallbackIcon();
}
