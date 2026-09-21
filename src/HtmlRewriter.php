<?php
declare(strict_types=1);

namespace Duoviewurl;

final class HtmlRewriter
{
    public function rewrite(string $html, string $baseUrl, string $ua, string $theme): string
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new ProxyException('Le document HTML distant est illisible.', 502);
        }

        $xpath = new \DOMXPath($dom);
        $effectiveBase = $baseUrl;
        $baseNode = $xpath->query('//base[@href]')->item(0);
        if ($baseNode instanceof \DOMElement) {
            $effectiveBase = Url::resolve($baseUrl, $baseNode->getAttribute('href'));
        }
        foreach ($xpath->query('//base') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach ([
            'a' => ['href'], 'area' => ['href'], 'link' => ['href'], 'script' => ['src'],
            'img' => ['src'], 'source' => ['src'], 'video' => ['src', 'poster'], 'audio' => ['src'],
            'iframe' => ['src'], 'embed' => ['src'], 'object' => ['data'], 'form' => ['action'],
        ] as $tag => $attributes) {
            foreach ($xpath->query('//' . $tag) ?: [] as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                foreach ($attributes as $attribute) {
                    if (!$node->hasAttribute($attribute)) {
                        continue;
                    }
                    $absolute = Url::resolve($effectiveBase, $node->getAttribute($attribute));
                    if ($absolute === '' || str_starts_with($absolute, '#') || !preg_match('~^https?://~i', $absolute)) {
                        continue;
                    }
                    if (($tag === 'a' || $tag === 'area') && $attribute === 'href') {
                        $node->setAttribute('data-duoviewurl-target', $absolute);
                    }
                    if ($tag === 'form' && $attribute === 'action') {
                        $node->setAttribute('data-duoviewurl-action', $absolute);
                    }
                    $node->setAttribute($attribute, $this->proxyUrl($absolute, $ua, $theme));
                    if ($node->hasAttribute('integrity')) {
                        $node->removeAttribute('integrity');
                    }
                    if ($node->hasAttribute('crossorigin')) {
                        $node->removeAttribute('crossorigin');
                    }
                }
            }
        }

        foreach ($xpath->query('//*[@srcset]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $items = [];
            foreach (explode(',', $node->getAttribute('srcset')) as $candidate) {
                $parts = preg_split('/\s+/', trim($candidate), 2);
                $absolute = Url::resolve($effectiveBase, $parts[0] ?? '');
                $items[] = $this->proxyUrl($absolute, $ua, $theme) . (isset($parts[1]) ? ' ' . $parts[1] : '');
            }
            $node->setAttribute('srcset', implode(', ', $items));
        }

        foreach ($xpath->query('//*[@style]') ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $node->setAttribute('style', $this->rewriteCss($node->getAttribute('style'), $effectiveBase, $ua, $theme));
            }
        }
        foreach ($xpath->query('//style') ?: [] as $node) {
            $node->nodeValue = $this->rewriteCss($node->textContent, $effectiveBase, $ua, $theme);
        }

        $captchaQuery = '//*[contains(concat(" ", normalize-space(@class), " "), " cf-turnstile ") or contains(concat(" ", normalize-space(@class), " "), " g-recaptcha ") or contains(concat(" ", normalize-space(@class), " "), " h-captcha ")]';
        foreach ($xpath->query($captchaQuery) ?: [] as $node) {
            if (!$node instanceof \DOMElement || $node->getAttribute('data-duoviewurl-captcha') === 'notice') {
                continue;
            }
            $node->setAttribute('data-duoviewurl-captcha', 'notice');
            while ($node->firstChild) {
                $node->removeChild($node->firstChild);
            }
            $notice = $dom->createElement('div');
            $notice->setAttribute('class', 'duoviewurl-captcha-notice');
            $title = $dom->createElement('strong', 'CAPTCHA détecté');
            $text = $dom->createElement('span', 'La vérification est liée au domaine original et ne peut pas être validée dans un aperçu proxy.');
            $link = $dom->createElement('a', 'Ouvrir la page originale');
            $link->setAttribute('href', $baseUrl);
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', 'noopener noreferrer');
            $notice->appendChild($title);
            $notice->appendChild($text);
            $notice->appendChild($link);
            $node->appendChild($notice);
        }

        $head = $xpath->query('//head')->item(0);
        if (!$head) {
            $head = $dom->createElement('head');
            $dom->documentElement?->insertBefore($head, $dom->documentElement->firstChild);
        }
        $style = $dom->createElement('style', ':root{color-scheme:' . ($theme === 'dark' ? 'dark' : ($theme === 'light' ? 'light' : 'light dark')) . '}.duoviewurl-captcha-notice{display:grid;gap:.45rem;padding:1rem;border:1px solid #f59e0b;border-radius:.6rem;background:#fffbeb;color:#78350f;font:14px/1.45 system-ui,sans-serif}.duoviewurl-captcha-notice strong{font-size:15px}.duoviewurl-captcha-notice a{color:#92400e;text-decoration:underline;font-weight:700}');
        $head->appendChild($style);

        $bridge = <<<'JS'
(function(){
  const original=%s;
  parent.postMessage({source:'duoviewurl',type:'ready',url:original},'*');
  document.addEventListener('click',function(event){
    const link=event.target.closest&&event.target.closest('a[data-duoviewurl-target]');
    if(!link)return;
    const target=link.getAttribute('data-duoviewurl-target');
    if(target)parent.postMessage({source:'duoviewurl',type:'navigate',url:target},'*');
  },true);
  document.addEventListener('submit',function(event){
    const form=event.target;
    if(!(form instanceof HTMLFormElement))return;
    if(form.method.toLowerCase()==='post'){
      parent.postMessage({source:'duoviewurl',type:'session-submit'},'*');
      return;
    }
    if(form.method.toLowerCase()!=='get')return;
    event.preventDefault();
    const target=form.getAttribute('data-duoviewurl-action')||original;
    const url=new URL(target,original);
    new FormData(form).forEach((value,key)=>url.searchParams.set(key,String(value)));
    parent.postMessage({source:'duoviewurl',type:'navigate',url:url.href},'*');
  },true);
})();
JS;
        $script = $dom->createElement('script');
        $script->appendChild($dom->createTextNode(sprintf($bridge, json_encode($baseUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG))));
        $head->appendChild($script);

        $output = $dom->saveHTML();
        return is_string($output) ? str_replace('<?xml encoding="utf-8" ?>', '', $output) : $html;
    }

    public function rewriteCss(string $css, string $baseUrl, string $ua, string $theme): string
    {
        $rewritten = preg_replace_callback(
            '~url\(\s*(["\']?)(?!data:|#)([^)"\']+)\1\s*\)~i',
            fn(array $match): string => 'url("' . $this->proxyUrl(Url::resolve($baseUrl, trim($match[2])), $ua, $theme) . '")',
            $css
        ) ?? $css;
        return preg_replace_callback(
            '~@import\s+(["\'])(?!data:)([^"\']+)\1~i',
            fn(array $match): string => '@import "' . $this->proxyUrl(Url::resolve($baseUrl, trim($match[2])), $ua, $theme) . '"',
            $rewritten
        ) ?? $rewritten;
    }

    private function proxyUrl(string $url, string $ua, string $theme): string
    {
        return '/proxy.php?' . http_build_query(['url' => $url, 'ua' => $ua, 'theme' => $theme], '', '&', PHP_QUERY_RFC3986);
    }
}
