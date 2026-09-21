<?php
declare(strict_types=1);

namespace Duoviewurl;

use PDO;

final class Auth
{
    private const REMEMBER_COOKIE = '__Host-duoview_remember';
    private const PROXY_COOKIE = '__Host-duoview_access';
    private const REMEMBER_SECONDS = 2592000;
    private const PROXY_SECONDS = 43200;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('duoview_auth');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        Database::cleanup();
    }

    /** @return array{id:int,username:string,is_admin:int,is_active:int}|null */
    public static function user(): ?array
    {
        self::startSession();
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id > 0) {
            $user = self::findActiveUser($id);
            if ($user !== null) {
                return $user;
            }
            unset($_SESSION['user_id']);
        }
        return self::loginFromRememberCookie();
    }

    /** @return array{id:int,username:string,is_admin:int,is_active:int} */
    public static function requirePage(): array
    {
        $user = self::user();
        if ($user === null) {
            $destination = rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/'));
            header('Location: /login.php?next=' . $destination, true, 302);
            exit;
        }
        return $user;
    }

    /** @return array{id:int,username:string,is_admin:int,is_active:int} */
    public static function requireApi(bool $admin = false): array
    {
        $user = self::user();
        if ($user === null) {
            throw new AuthException('Authentification requise.', 401);
        }
        if ($admin && (int) $user['is_admin'] !== 1) {
            throw new AuthException('Droits administrateur requis.', 403);
        }
        return $user;
    }

    public static function login(string $username, string $password, bool $remember): bool
    {
        self::startSession();
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE username = ? COLLATE NOCASE LIMIT 1');
        $statement->execute([trim($username)]);
        $user = $statement->fetch();
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, (string) $user['password_hash'])) {
            password_hash('invalid-password', PASSWORD_DEFAULT);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Database::connection()->prepare('UPDATE users SET last_login_at = ?, updated_at = ? WHERE id = ?')
            ->execute([time(), time(), $user['id']]);
        if ($remember) {
            self::issueRememberToken((int) $user['id']);
        } else {
            self::clearRememberCookie();
        }
        return true;
    }

    public static function logout(): void
    {
        self::startSession();
        $remember = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        $selector = explode(':', $remember, 2)[0] ?? '';
        if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
            Database::connection()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
        }
        $proxyToken = (string) ($_SESSION['proxy_ticket'] ?? '');
        if ($proxyToken !== '') {
            Database::connection()->prepare('DELETE FROM proxy_tokens WHERE token_hash = ?')
                ->execute([hash('sha256', $proxyToken)]);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', self::expiredCookieOptions());
        }
        self::clearRememberCookie();
        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(?string $token): void
    {
        if (!is_string($token) || !hash_equals(self::csrfToken(), $token)) {
            throw new AuthException('Jeton de sécurité invalide.', 403);
        }
    }

    public static function proxyTicket(int $userId): string
    {
        self::startSession();
        $token = (string) ($_SESSION['proxy_ticket'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $statement = Database::connection()->prepare('SELECT 1 FROM proxy_tokens WHERE token_hash = ? AND user_id = ? AND expires_at > ?');
            $statement->execute([hash('sha256', $token), $userId, time()]);
            if ($statement->fetchColumn()) {
                return $token;
            }
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['proxy_ticket'] = $token;
        Database::connection()->prepare(
            'INSERT OR REPLACE INTO proxy_tokens(token_hash, user_id, expires_at, last_used_at) VALUES(?, ?, ?, ?)'
        )->execute([hash('sha256', $token), $userId, time() + self::PROXY_SECONDS, time()]);
        return $token;
    }

    public static function acceptProxyTicket(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || !self::validateProxyToken($token)) {
            return false;
        }
        setcookie(self::PROXY_COOKIE, $token, [
            'expires' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return true;
    }

    public static function requireProxy(): int
    {
        $token = (string) ($_COOKIE[self::PROXY_COOKIE] ?? '');
        if (!self::validateProxyToken($token)) {
            throw new AuthException('Session Duoviewurl expirée.', 401);
        }
        $statement = Database::connection()->prepare(
            'SELECT user_id FROM proxy_tokens WHERE token_hash = ? AND expires_at > ?'
        );
        $statement->execute([hash('sha256', $token), time()]);
        return (int) $statement->fetchColumn();
    }

    private static function validateProxyToken(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        $statement = Database::connection()->prepare(
            'SELECT p.user_id FROM proxy_tokens p JOIN users u ON u.id = p.user_id WHERE p.token_hash = ? AND p.expires_at > ? AND u.is_active = 1'
        );
        $statement->execute([hash('sha256', $token), time()]);
        return $statement->fetchColumn() !== false;
    }

    /** @return array{id:int,username:string,is_admin:int,is_active:int}|null */
    private static function findActiveUser(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT id, username, is_admin, is_active FROM users WHERE id = ? AND is_active = 1');
        $statement->execute([$id]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    /** @return array{id:int,username:string,is_admin:int,is_active:int}|null */
    private static function loginFromRememberCookie(): ?array
    {
        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        [$selector, $validator] = array_pad(explode(':', $raw, 2), 2, '');
        if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            return null;
        }
        $statement = Database::connection()->prepare(
            'SELECT r.validator_hash, r.user_id FROM remember_tokens r JOIN users u ON u.id = r.user_id WHERE r.selector = ? AND r.expires_at > ? AND u.is_active = 1'
        );
        $statement->execute([$selector, time()]);
        $row = $statement->fetch();
        if (!$row || !hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            self::clearRememberCookie();
            return null;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['user_id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Database::connection()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
        self::issueRememberToken((int) $row['user_id']);
        return self::findActiveUser((int) $row['user_id']);
    }

    private static function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        Database::connection()->prepare(
            'INSERT INTO remember_tokens(selector, validator_hash, user_id, expires_at) VALUES(?, ?, ?, ?)'
        )->execute([$selector, hash('sha256', $validator), $userId, time() + self::REMEMBER_SECONDS]);
        setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires' => time() + self::REMEMBER_SECONDS,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', self::expiredCookieOptions());
    }

    /** @return array{expires:int,path:string,secure:bool,httponly:bool,samesite:string} */
    private static function expiredCookieOptions(): array
    {
        return ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
    }
}

final class AuthException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 401)
    {
        parent::__construct($message);
    }
}
