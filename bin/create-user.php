<?php
declare(strict_types=1);

use Duoviewurl\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$username = trim((string) ($argv[1] ?? ''));
$password = (string) ($argv[2] ?? '');
$admin = ($argv[3] ?? 'admin') === 'admin' ? 1 : 0;
if (!preg_match('/^[\pL\pN._@-]{3,80}$/u', $username) || strlen($password) < 10) {
    fwrite(STDERR, "Usage: php bin/create-user.php <identifiant> <mot-de-passe-10-caracteres-minimum> [admin|user]\n");
    exit(2);
}
$now = time();
$statement = Database::connection()->prepare(
    'INSERT INTO users(username, password_hash, is_admin, is_active, created_at, updated_at) VALUES(?, ?, ?, 1, ?, ?) '
    . 'ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash, is_admin = excluded.is_admin, is_active = 1, updated_at = excluded.updated_at'
);
$statement->execute([$username, password_hash($password, PASSWORD_DEFAULT), $admin, $now, $now]);
echo "Utilisateur {$username} enregistré.\n";
