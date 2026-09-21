<?php
declare(strict_types=1);

namespace Duoviewurl;

final class RateLimiter
{
    public function __construct(private readonly int $limit = 90, private readonly int $window = 60)
    {
    }

    public function consume(string $clientIp): void
    {
        $key = hash('sha256', $clientIp);
        $path = sys_get_temp_dir() . '/duoview-rate-' . $key;
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new ProxyException('Service temporairement indisponible.', 503);
        }

        $now = time();
        $raw = stream_get_contents($handle);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $start = is_array($data) && isset($data['start']) ? (int) $data['start'] : $now;
        $count = is_array($data) && isset($data['count']) ? (int) $data['count'] : 0;
        if ($now - $start >= $this->window) {
            $start = $now;
            $count = 0;
        }
        $count++;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['start' => $start, 'count' => $count], JSON_THROW_ON_ERROR));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        header('X-RateLimit-Limit: ' . $this->limit);
        header('X-RateLimit-Remaining: ' . max(0, $this->limit - $count));
        if ($count > $this->limit) {
            header('Retry-After: ' . max(1, $this->window - ($now - $start)));
            throw new ProxyException('Trop de requêtes. Réessayez dans quelques instants.', 429);
        }
    }
}
