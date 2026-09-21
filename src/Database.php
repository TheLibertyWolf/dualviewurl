<?php
declare(strict_types=1);

namespace Duoviewurl;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }
        $path = getenv('DUOVIEW_DB_PATH') ?: '/var/lib/dualviewurl/dualviewurl.sqlite';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer le dossier de données.');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        @chmod($path, 0600);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $journalMode = strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn());
        if ($journalMode !== 'wal') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 1) {
            self::migrate($pdo);
            $pdo->exec('PRAGMA user_version = 1');
        }
        self::$connection = $pdo;
        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    last_login_at INTEGER
);
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS remember_tokens (
    selector TEXT PRIMARY KEY,
    validator_hash TEXT NOT NULL,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS proxy_tokens (
    token_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at INTEGER NOT NULL,
    last_used_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    url TEXT NOT NULL,
    visit_count INTEGER NOT NULL DEFAULT 1,
    last_visited_at INTEGER NOT NULL,
    UNIQUE(user_id, url)
);
CREATE INDEX IF NOT EXISTS idx_history_user_recent ON history(user_id, last_visited_at DESC);
CREATE INDEX IF NOT EXISTS idx_remember_expiry ON remember_tokens(expires_at);
CREATE INDEX IF NOT EXISTS idx_proxy_expiry ON proxy_tokens(expires_at);
SQL);
    }

    public static function setting(string $key): string
    {
        $statement = self::connection()->prepare('SELECT value FROM settings WHERE key = ?');
        $statement->execute([$key]);
        return (string) ($statement->fetchColumn() ?: '');
    }

    public static function setSetting(string $key, string $value): void
    {
        $statement = self::connection()->prepare(
            'INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $statement->execute([$key, $value]);
    }

    public static function cleanup(): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }
        $now = time();
        self::connection()->prepare('DELETE FROM remember_tokens WHERE expires_at < ?')->execute([$now]);
        self::connection()->prepare('DELETE FROM proxy_tokens WHERE expires_at < ?')->execute([$now]);
    }
}
