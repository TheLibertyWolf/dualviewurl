<?php
declare(strict_types=1);

namespace Duoviewurl;

final class SsrfGuard
{
    /** @var callable(string): array<int, string> */
    private $resolver;

    /** @param null|callable(string): array<int, string> $resolver */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolveHost(...);
    }

    /** @return array{url:string,host:string,port:int,ip:string} */
    public function validate(string $rawUrl): array
    {
        $rawUrl = trim($rawUrl);
        if ($rawUrl === '' || strlen($rawUrl) > 4096) {
            throw new ProxyException('URL absente ou trop longue.');
        }

        $parts = parse_url($rawUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ProxyException('URL invalide.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ProxyException('Seuls les protocoles HTTP et HTTPS sont autorisés.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ProxyException('Les identifiants dans une URL sont interdits.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            throw new ProxyException('Seuls les ports 80 et 443 sont autorisés.');
        }

        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        $blockedNames = ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'];
        if ($host === '' || in_array($host, $blockedNames, true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')) {
            throw new ProxyException('Les hôtes locaux sont interdits.');
        }
        if (!filter_var($host, FILTER_VALIDATE_IP) && preg_match('/^(?:0x[0-9a-f]+|[0-9.]+)$/i', $host)) {
            throw new ProxyException('Les notations IP non standard sont interdites.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        $ips = array_values(array_unique($ips));
        if ($ips === []) {
            throw new ProxyException('Le nom de domaine ne peut pas être résolu.', 502);
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new ProxyException('La destination pointe vers un réseau privé ou réservé.');
            }
        }

        return ['url' => $rawUrl, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** @return array<int, string> */
    private static function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        return $ips;
    }
}
