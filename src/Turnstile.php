<?php
declare(strict_types=1);

namespace Duoviewurl;

final class Turnstile
{
    /** @return array{enabled:bool,site_key:string} */
    public static function publicConfig(): array
    {
        $siteKey = Database::setting('turnstile_site_key');
        $secret = Database::setting('turnstile_secret_key');
        return ['enabled' => $siteKey !== '' && $secret !== '', 'site_key' => $siteKey];
    }

    public static function verify(string $token, string $remoteIp): bool
    {
        $secret = Database::setting('turnstile_secret_key');
        if ($secret === '') {
            return true;
        }
        if ($token === '') {
            return false;
        }
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $remoteIp]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($response) || $status !== 200) {
            return false;
        }
        $payload = json_decode($response, true);
        if (!is_array($payload) || ($payload['success'] ?? false) !== true) {
            return false;
        }
        $hostname = strtolower((string) ($payload['hostname'] ?? ''));
        return $hostname === '' || $hostname === 'dualviewurl.jessysystem.com';
    }
}
