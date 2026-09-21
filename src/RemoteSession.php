<?php
declare(strict_types=1);

namespace Duoviewurl;

final class RemoteSession
{
    private const COOKIE = '__Host-duoview_session';

    public static function cookieFile(): string
    {
        $id = isset($_COOKIE[self::COOKIE]) ? (string) $_COOKIE[self::COOKIE] : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            $id = bin2hex(random_bytes(32));
            setcookie(self::COOKIE, $id, [
                'expires' => 0,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE[self::COOKIE] = $id;
        }
        self::collectExpiredFiles();
        return sys_get_temp_dir() . '/duoview-cookies-' . $id . '.txt';
    }

    public static function clear(): void
    {
        $id = isset($_COOKIE[self::COOKIE]) ? (string) $_COOKIE[self::COOKIE] : '';
        if (preg_match('/^[a-f0-9]{64}$/', $id)) {
            $path = sys_get_temp_dir() . '/duoview-cookies-' . $id . '.txt';
            if (is_file($path)) {
                unlink($path);
            }
        }
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function collectExpiredFiles(): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }
        foreach (glob(sys_get_temp_dir() . '/duoview-cookies-*.txt') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < time() - 7200) {
                unlink($path);
            }
        }
    }
}
