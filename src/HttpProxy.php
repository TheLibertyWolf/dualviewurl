<?php
declare(strict_types=1);

namespace Duoviewurl;

final class HttpProxy
{
    private const ALLOWED_MIME = [
        'text/html', 'text/css', 'text/plain', 'text/javascript', 'application/javascript',
        'application/json', 'application/xml', 'text/xml', 'image/jpeg', 'image/png', 'image/gif',
        'image/webp', 'image/svg+xml', 'image/avif', 'image/x-icon', 'font/woff', 'font/woff2',
        'application/font-woff', 'application/octet-stream', 'application/pdf',
    ];

    public function __construct(
        private readonly SsrfGuard $guard,
        private readonly HtmlRewriter $rewriter,
        private readonly int $maxBytes = 10485760,
        private readonly int $connectTimeout = 5,
        private readonly int $totalTimeout = 15,
        private readonly int $maxRedirects = 5,
    ) {
    }

    /** @return array{status:int,mime:string,body:string,url:string} */
    public function fetch(string $url, string $uaKey, string $theme): array
    {
        $userAgent = $this->userAgent($uaKey);
        for ($redirects = 0; $redirects <= $this->maxRedirects; $redirects++) {
            $target = $this->guard->validate($url);
            $response = $this->request($target, $userAgent);
            if ($response['location'] !== null && in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                if ($redirects === $this->maxRedirects) {
                    throw new ProxyException('Trop de redirections.', 502);
                }
                $url = Url::resolve($url, $response['location']);
                continue;
            }

            $mime = strtolower(trim(explode(';', $response['contentType'])[0]));
            if (!in_array($mime, self::ALLOWED_MIME, true)) {
                throw new ProxyException('Type de contenu distant non autorisé : ' . ($mime ?: 'inconnu') . '.', 415);
            }
            $body = $response['body'];
            if ($mime === 'text/html') {
                $body = $this->rewriter->rewrite($body, $url, $uaKey, $theme);
            } elseif ($mime === 'text/css') {
                $body = $this->rewriter->rewriteCss($body, $url, $uaKey, $theme);
            }
            return ['status' => $response['status'], 'mime' => $mime, 'body' => $body, 'url' => $url];
        }
        throw new ProxyException('Redirection impossible.', 502);
    }

    /** @param array{url:string,host:string,port:int,ip:string} $target
     *  @return array{status:int,contentType:string,location:?string,body:string}
     */
    private function request(array $target, string $userAgent): array
    {
        $body = '';
        $headers = [];
        $tooLarge = false;
        $ch = curl_init($target['url']);
        $pinnedIp = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->totalTimeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/css,image/avif,image/webp,image/*,*/*;q=0.8', 'Accept-Language: fr,en;q=0.8', 'DNT: 1'],
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $pinnedIp],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                $position = strpos($line, ':');
                if ($position !== false) {
                    $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > $this->maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($tooLarge) {
            throw new ProxyException('La réponse distante dépasse la taille maximale autorisée.', 413);
        }
        if ($ok === false) {
            throw new ProxyException('Le site distant ne répond pas dans les conditions autorisées' . ($error !== '' ? ' (' . $error . ')' : '') . '.', 504);
        }
        return ['status' => $status ?: 502, 'contentType' => $contentType, 'location' => $headers['location'] ?? null, 'body' => $body];
    }

    private function userAgent(string $key): string
    {
        return match ($key) {
            'iphone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
            'native' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'Duoviewurl/1.0', 0, 512),
            default => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        };
    }
}
