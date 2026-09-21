<?php
declare(strict_types=1);

use Duoviewurl\Auth;
use Duoviewurl\ProxyException;
use Duoviewurl\RateLimiter;
use Duoviewurl\Turnstile;

require_once dirname(__DIR__) . '/src/bootstrap.php';

if (Auth::user() !== null) {
    header('Location: /', true, 302);
    exit;
}

$turnstile = Turnstile::publicConfig();
$error = '';
$username = '';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '/');
if (!str_starts_with($next, '/') || str_starts_with($next, '//')) {
    $next = '/';
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        (new RateLimiter(10, 300))->consume('login:' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        Auth::verifyCsrf($_POST['csrf'] ?? null);
        $username = trim((string) ($_POST['username'] ?? ''));
        $captchaValid = !$turnstile['enabled'] || Turnstile::verify(
            (string) ($_POST['cf-turnstile-response'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );
        if (!$captchaValid) {
            $error = 'La vérification anti-robot a échoué. Réessayez.';
        } elseif (!Auth::login($username, (string) ($_POST['password'] ?? ''), isset($_POST['remember']))) {
            $error = 'Identifiant ou mot de passe incorrect.';
        } else {
            header('Location: ' . $next, true, 303);
            exit;
        }
    } catch (ProxyException $exception) {
        $error = $exception->httpStatus === 429
            ? 'Trop de tentatives. Réessayez dans quelques minutes.'
            : 'La connexion est temporairement indisponible.';
    } catch (Throwable) {
        $error = 'La connexion n’a pas pu être validée.';
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com; object-src 'none'; base-uri 'self'; form-action 'self'");
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#080d1c">
  <meta name="description" content="Connexion à Duoviewurl">
  <title>Connexion — Duoviewurl</title>
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="stylesheet" href="/assets/app.css?v=2.0.0">
  <?php if ($turnstile['enabled']): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
</head>
<body class="auth-shell">
  <main class="login-card">
    <a class="brand login-brand" href="/" aria-label="Duoviewurl, accueil">
      <svg aria-hidden="true" viewBox="0 0 42 32"><rect x="2" y="2" width="25" height="19" rx="3"/><rect x="16" y="11" width="24" height="19" rx="3"/><path d="M11 26h18"/></svg>
      <span>DUO<span>VIEW</span>URL</span>
    </a>
    <p class="login-kicker">ESPACE PROTÉGÉ</p>
    <h1>Se connecter</h1>
    <p class="login-intro">Accédez à vos vues et à votre historique personnel.</p>
    <?php if ($error !== ''): ?><div class="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" class="login-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
      <label>Identifiant
        <input name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required autofocus>
      </label>
      <label>Mot de passe
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <label class="remember-line"><input type="checkbox" name="remember" value="1"> Se souvenir de moi pendant 30 jours</label>
      <?php if ($turnstile['enabled']): ?><div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile['site_key'], ENT_QUOTES, 'UTF-8') ?>" data-theme="dark"></div><?php endif; ?>
      <button class="primary login-submit" type="submit">Connexion</button>
    </form>
  </main>
  <footer class="login-footer">© 2026 Jessy System · <a href="https://github.com/TheLibertyWolf/dualviewurl">GitHub</a></footer>
  <script src="/register-sw.js?v=2.0.0" defer></script>
</body>
</html>
