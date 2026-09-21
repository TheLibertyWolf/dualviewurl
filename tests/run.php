<?php
declare(strict_types=1);

use Duoviewurl\HtmlRewriter;
use Duoviewurl\HttpProxy;
use Duoviewurl\ProxyException;
use Duoviewurl\SsrfGuard;
use Duoviewurl\Url;
use Duoviewurl\Database;

$testDatabase = '/tmp/dualviewurl-tests-' . getmypid() . '.sqlite';
putenv('DUOVIEW_DB_PATH=' . $testDatabase);
register_shutdown_function(static function () use ($testDatabase): void {
    foreach ([$testDatabase, $testDatabase . '-wal', $testDatabase . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});
require_once dirname(__DIR__) . '/src/bootstrap.php';

$passed = 0;
$failed = 0;
function test(string $name, callable $callback): void
{
    global $passed, $failed;
    try {
        $callback();
        echo "✓ {$name}\n";
        $passed++;
    } catch (Throwable $error) {
        echo "✗ {$name}: {$error->getMessage()}\n";
        $failed++;
    }
}
function assertTrue(bool $condition, string $message = 'Assertion échouée'): void
{
    if (!$condition) throw new RuntimeException($message);
}
function assertBlocked(string $url, array $resolved = ['93.184.216.34']): void
{
    $guard = new SsrfGuard(static fn(string $host): array => $resolved);
    try {
        $guard->validate($url);
    } catch (ProxyException) {
        return;
    }
    throw new RuntimeException("URL non bloquée : {$url}");
}

test('accepte HTTPS public', function (): void {
    $result = (new SsrfGuard(static fn(string $host): array => ['93.184.216.34']))->validate('https://example.com/path');
    assertTrue($result['port'] === 443 && $result['ip'] === '93.184.216.34');
});
test('accepte HTTP public', function (): void {
    $result = (new SsrfGuard(static fn(string $host): array => ['93.184.216.34']))->validate('http://example.com/');
    assertTrue($result['port'] === 80);
});
test('refuse les schémas non HTTP', fn() => assertBlocked('file:///etc/passwd'));
test('refuse les identifiants', fn() => assertBlocked('https://user:secret@example.com/'));
test('refuse les ports arbitraires', fn() => assertBlocked('https://example.com:8080/'));
test('refuse localhost', fn() => assertBlocked('http://localhost/'));
test('refuse les noms locaux', fn() => assertBlocked('http://printer.local/'));
test('refuse IPv4 loopback', fn() => assertBlocked('http://127.0.0.1/'));
test('refuse IPv4 décimale alternative', fn() => assertBlocked('http://2130706433/'));
test('refuse IPv4 hexadécimale alternative', fn() => assertBlocked('http://0x7f000001/'));
test('refuse IPv4 abrégée', fn() => assertBlocked('http://127.1/'));
test('refuse IPv4 octale alternative', fn() => assertBlocked('http://0177.0.0.1/'));
test('refuse IPv4 privée 10/8', fn() => assertBlocked('http://10.2.3.4/'));
test('refuse IPv4 privée 172.16/12', fn() => assertBlocked('http://172.20.0.2/'));
test('refuse IPv4 privée 192.168/16', fn() => assertBlocked('http://192.168.1.1/'));
test('refuse link-local', fn() => assertBlocked('http://169.254.169.254/'));
test('refuse IPv6 loopback', fn() => assertBlocked('http://[::1]/'));
test('refuse IPv6 locale', fn() => assertBlocked('http://[fc00::1]/'));
test('refuse IPv6 link-local', fn() => assertBlocked('http://[fe80::1]/'));
test('refuse un domaine résolu en privé', fn() => assertBlocked('https://public.example/', ['192.168.1.4']));
test('refuse si une réponse DNS est privée', fn() => assertBlocked('https://mixed.example/', ['93.184.216.34', '127.0.0.1']));
test('résout les URL relatives', function (): void {
    assertTrue(Url::resolve('https://example.com/a/b/page.html', '../img/a.png') === 'https://example.com/a/img/a.png');
    assertTrue(Url::resolve('https://example.com/a/page.html', '/style.css') === 'https://example.com/style.css');
});
test('réécrit HTML et conserve la destination des liens', function (): void {
    $html = (new HtmlRewriter())->rewrite('<html><head><link rel="stylesheet" href="/app.css" integrity="sha384-test" crossorigin="anonymous"></head><body><a href="/suite">Suite</a><img src="img/a.png"></body></html>', 'https://example.com/path/', 'desktop', 'dark');
    assertTrue(str_contains($html, 'data-duoviewurl-target="https://example.com/suite"'));
    assertTrue(str_contains($html, 'url=https%3A%2F%2Fexample.com%2Fpath%2Fimg%2Fa.png'));
    assertTrue(str_contains($html, "color-scheme:dark"));
    assertTrue(!str_contains($html, 'integrity='), 'Le hash SRI doit être retiré après réécriture.');
    assertTrue(!str_contains($html, 'crossorigin='), 'Le mode crossorigin distant doit être retiré.');
});
test('renvoie les formulaires sans action vers la page distante finale', function (): void {
    $html = (new HtmlRewriter())->rewrite(
        '<html><body><form method="post" action=""><input name="otp"></form><form method="post"><input name="code"></form></body></html>',
        'https://example.com/auth/verify-2fa.php',
        'desktop',
        'light',
    );
    assertTrue(substr_count($html, 'data-duoviewurl-action="https://example.com/auth/verify-2fa.php"') === 2);
    assertTrue(substr_count($html, 'url=https%3A%2F%2Fexample.com%2Fauth%2Fverify-2fa.php') === 2);
});
test('réécrit les url CSS', function (): void {
    $css = (new HtmlRewriter())->rewriteCss('@import "theme.css";body{background:url(../bg.png)}', 'https://example.com/css/main.css', 'iphone', 'light');
    assertTrue(str_contains($css, 'url=https%3A%2F%2Fexample.com%2Fbg.png'));
    assertTrue(str_contains($css, 'url=https%3A%2F%2Fexample.com%2Fcss%2Ftheme.css'));
});
test('conserve les scripts CAPTCHA sur leur origine officielle', function (): void {
    $html = (new HtmlRewriter())->rewrite('<html><head><script src="https://challenges.cloudflare.com/turnstile/v0/api.js"></script></head><body><div class="cf-turnstile" data-sitekey="public-key"></div></body></html>', 'https://example.com/login', 'desktop', 'light');
    assertTrue(str_contains($html, 'src="https://challenges.cloudflare.com/turnstile/v0/api.js"'));
    assertTrue(str_contains($html, 'class="cf-turnstile"'));
    assertTrue(!str_contains($html, 'CAPTCHA détecté'));
});
test('borne les requêtes média à des segments de 4 Mio', function (): void {
    assertTrue(HttpProxy::normalizeRange('bytes=0-') === 'bytes=0-4194303');
    assertTrue(HttpProxy::normalizeRange('bytes=1000-9999999') === 'bytes=1000-4195303');
    assertTrue(HttpProxy::normalizeRange('bytes=500-100') === null);
    assertTrue(HttpProxy::normalizeRange('bytes=0-10,20-30') === null);
});
test('initialise la base SQLite et ses tables applicatives', function (): void {
    $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['users', 'settings', 'remember_tokens', 'proxy_tokens', 'history'] as $table) {
        assertTrue(in_array($table, $tables, true), "Table manquante : {$table}");
    }
});
test('enregistre les réglages persistants', function (): void {
    Database::setSetting('test_key', 'test_value');
    assertTrue(Database::setting('test_key') === 'test_value');
    Database::setSetting('test_key', 'updated');
    assertTrue(Database::setting('test_key') === 'updated');
});
test('isole l historique par utilisateur', function (): void {
    $pdo = Database::connection();
    $now = time();
    $create = $pdo->prepare('INSERT INTO users(username, password_hash, is_admin, is_active, created_at, updated_at) VALUES(?, ?, 0, 1, ?, ?)');
    $create->execute(['alice', password_hash('testing-password', PASSWORD_DEFAULT), $now, $now]);
    $alice = (int) $pdo->lastInsertId();
    $create->execute(['bob', password_hash('testing-password', PASSWORD_DEFAULT), $now, $now]);
    $bob = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare('INSERT INTO history(user_id, url, visit_count, last_visited_at) VALUES(?, ?, 1, ?)');
    $insert->execute([$alice, 'https://alice.example/', $now]);
    $insert->execute([$bob, 'https://bob.example/', $now]);
    $query = $pdo->prepare('SELECT url FROM history WHERE user_id = ?');
    $query->execute([$alice]);
    assertTrue($query->fetchAll(PDO::FETCH_COLUMN) === ['https://alice.example/']);
});

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";
exit($failed === 0 ? 0 : 1);
