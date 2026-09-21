<?php
declare(strict_types=1);

use Duoviewurl\Auth;
use Duoviewurl\AuthException;
use Duoviewurl\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function input(): array
{
    $raw = file_get_contents('php://input');
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : $_POST;
}

function requirePost(array $data): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new AuthException('Méthode non autorisée.', 405);
    }
    Auth::verifyCsrf(isset($data['csrf']) ? (string) $data['csrf'] : ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null));
}

function validUsername(string $username): bool
{
    return preg_match('/^[\pL\pN._@-]{3,80}$/u', $username) === 1;
}

try {
    $action = (string) ($_GET['action'] ?? 'me');
    $data = input();
    $pdo = Database::connection();

    if ($action === 'logout') {
        requirePost($data);
        Auth::requireApi();
        Auth::logout();
        respond(['status' => 'ok']);
    }

    $user = Auth::requireApi(str_starts_with($action, 'admin_'));
    $userId = (int) $user['id'];

    if ($action === 'me') {
        respond(['user' => ['id' => $userId, 'username' => $user['username'], 'is_admin' => (bool) $user['is_admin']]]);
    }
    if ($action === 'history') {
        $query = trim((string) ($_GET['q'] ?? ''));
        $statement = $pdo->prepare(
            "SELECT url, visit_count, last_visited_at FROM history WHERE user_id = ? AND url LIKE ? ESCAPE '\\' ORDER BY last_visited_at DESC LIMIT 12"
        );
        $statement->execute([$userId, '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%']);
        respond(['history' => $statement->fetchAll()]);
    }
    if ($action === 'history_add') {
        requirePost($data);
        $url = trim((string) ($data['url'] ?? ''));
        $parts = parse_url($url);
        if (strlen($url) > 2048 || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new AuthException('URL invalide.', 422);
        }
        $statement = $pdo->prepare(
            'INSERT INTO history(user_id, url, visit_count, last_visited_at) VALUES(?, ?, 1, ?) '
            . 'ON CONFLICT(user_id, url) DO UPDATE SET visit_count = visit_count + 1, last_visited_at = excluded.last_visited_at'
        );
        $statement->execute([$userId, $url, time()]);
        respond(['status' => 'saved']);
    }
    if ($action === 'history_clear') {
        requirePost($data);
        $pdo->prepare('DELETE FROM history WHERE user_id = ?')->execute([$userId]);
        respond(['status' => 'cleared']);
    }
    if ($action === 'admin_users') {
        $rows = $pdo->query('SELECT id, username, is_admin, is_active, created_at, last_login_at FROM users ORDER BY username COLLATE NOCASE')->fetchAll();
        respond(['users' => $rows]);
    }
    if ($action === 'admin_user_save') {
        requirePost($data);
        $id = (int) ($data['id'] ?? 0);
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $isAdmin = !empty($data['is_admin']) ? 1 : 0;
        $isActive = !empty($data['is_active']) ? 1 : 0;
        if (!validUsername($username)) {
            throw new AuthException('Identifiant invalide (3 à 80 caractères).', 422);
        }
        if ($id === 0 && strlen($password) < 10) {
            throw new AuthException('Le mot de passe doit contenir au moins 10 caractères.', 422);
        }
        if ($id === $userId && (!$isActive || !$isAdmin)) {
            throw new AuthException('Vous ne pouvez pas désactiver votre propre compte administrateur.', 422);
        }
        if ($id > 0) {
            if ($password !== '' && strlen($password) < 10) {
                throw new AuthException('Le mot de passe doit contenir au moins 10 caractères.', 422);
            }
            $sql = 'UPDATE users SET username = ?, is_admin = ?, is_active = ?, updated_at = ?';
            $parameters = [$username, $isAdmin, $isActive, time()];
            if ($password !== '') {
                $sql .= ', password_hash = ?';
                $parameters[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = ?';
            $parameters[] = $id;
            $pdo->prepare($sql)->execute($parameters);
        } else {
            $pdo->prepare('INSERT INTO users(username, password_hash, is_admin, is_active, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $isAdmin, $isActive, time(), time()]);
        }
        respond(['status' => 'saved']);
    }
    if ($action === 'admin_user_delete') {
        requirePost($data);
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0 || $id === $userId) {
            throw new AuthException('Ce compte ne peut pas être supprimé.', 422);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        respond(['status' => 'deleted']);
    }
    if ($action === 'admin_settings') {
        respond(['turnstile' => [
            'enabled' => Database::setting('turnstile_site_key') !== '' && Database::setting('turnstile_secret_key') !== '',
            'site_key' => Database::setting('turnstile_site_key'),
            'secret_configured' => Database::setting('turnstile_secret_key') !== '',
        ]]);
    }
    if ($action === 'admin_settings_save') {
        requirePost($data);
        $enabled = !empty($data['enabled']);
        if (!$enabled) {
            Database::setSetting('turnstile_site_key', '');
            Database::setSetting('turnstile_secret_key', '');
        } else {
            $siteKey = trim((string) ($data['site_key'] ?? ''));
            $secretKey = trim((string) ($data['secret_key'] ?? ''));
            if ($siteKey === '' || ($secretKey === '' && Database::setting('turnstile_secret_key') === '')) {
                throw new AuthException('La clé de site et la clé secrète sont requises.', 422);
            }
            Database::setSetting('turnstile_site_key', $siteKey);
            if ($secretKey !== '') {
                Database::setSetting('turnstile_secret_key', $secretKey);
            }
        }
        respond(['status' => 'saved']);
    }
    respond(['error' => 'Action inconnue.'], 404);
} catch (AuthException $error) {
    respond(['error' => $error->getMessage()], $error->httpStatus);
} catch (PDOException $error) {
    $message = str_contains(strtolower($error->getMessage()), 'unique') ? 'Cet identifiant existe déjà.' : 'Erreur de base de données.';
    respond(['error' => $message], 422);
} catch (Throwable) {
    respond(['error' => 'Erreur interne.'], 500);
}
