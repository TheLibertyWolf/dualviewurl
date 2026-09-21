<?php
declare(strict_types=1);

namespace Duoviewurl;

final class Url
{
    public static function resolve(string $base, string $relative): string
    {
        $relative = trim($relative);
        if ($relative === '' || str_starts_with($relative, '#') || preg_match('~^(?:data|blob|javascript|mailto|tel):~i', $relative)) {
            return $relative;
        }
        if (preg_match('~^https?://~i', $relative)) {
            return $relative;
        }

        $baseParts = parse_url($base);
        if ($baseParts === false || !isset($baseParts['scheme'], $baseParts['host'])) {
            return $relative;
        }
        if (str_starts_with($relative, '//')) {
            return $baseParts['scheme'] . ':' . $relative;
        }

        $baseHost = (string) $baseParts['host'];
        if (str_contains($baseHost, ':') && !str_starts_with($baseHost, '[')) {
            $baseHost = '[' . $baseHost . ']';
        }
        $authority = $baseParts['scheme'] . '://' . $baseHost;
        if (isset($baseParts['port'])) {
            $authority .= ':' . $baseParts['port'];
        }
        $path = str_starts_with($relative, '/')
            ? $relative
            : preg_replace('~/[^/]*$~', '/', $baseParts['path'] ?? '/') . $relative;

        $segments = [];
        foreach (explode('/', (string) $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        return $authority . implode('/', $segments);
    }
}
