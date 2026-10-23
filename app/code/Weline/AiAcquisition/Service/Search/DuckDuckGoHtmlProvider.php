<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Search;

/**
 * DuckDuckGo HTML 版搜索提供者。
 *
 * 抓取 html.duckduckgo.com 的静态结果页，解析 `result__a` / `result__snippet`。
 * 远端失败、超时或页面结构变化一律返回空数组，由 SearchService 判断是否需要换源。
 */
class DuckDuckGoHtmlProvider implements SearchProviderInterface
{
    public const SOURCE = 'duckduckgo';

    /** HTML 结果页端点，q 参数由调用方 rawurlencode */
    private const ENDPOINT = 'https://html.duckduckgo.com/html/?q=';

    /** 单次请求超时（秒） */
    private const TIMEOUT = 8;

    /** 响应体上限，防止异常页面占用内存 */
    private const MAX_BYTES = 1048576;

    /** 桌面 Chrome UA，默认 UA 会被 HTML 端点拒绝 */
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        $limit = max(1, $limit);
        if ($query === '') {
            return [];
        }

        $html = $this->httpGet(self::ENDPOINT . rawurlencode($query));
        if ($html === null || trim($html) === '') {
            return [];
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            w_log_warning('DuckDuckGo HTML 解析失败', ['query' => $query]);
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " result__a ")]');
        if ($nodes === false) {
            return [];
        }

        $results = [];
        $rank = 1;
        foreach ($nodes as $node) {
            if (count($results) >= $limit) {
                break;
            }
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $url = $this->resolveUrl($node->getAttribute('href'));
            if ($url === '') {
                continue;
            }

            $title = $this->plainText($node->textContent);
            $results[] = [
                'title' => $title !== '' ? $title : $url,
                'url' => $url,
                'snippet' => $this->snippetFor($xpath, $node),
                'source' => self::SOURCE,
                'rank' => $rank++,
            ];
        }

        return $results;
    }

    /**
     * 取同一条结果容器内的摘要文本；容器缺失时返回空串。
     */
    private function snippetFor(\DOMXPath $xpath, \DOMElement $anchor): string
    {
        $container = $this->resultContainer($anchor);
        if ($container === null) {
            return '';
        }
        $nodes = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " result__snippet ")]',
            $container
        );
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return $this->plainText($nodes->item(0)?->textContent ?? '');
    }

    /**
     * 向上查找最近的 `.result` 容器（最多 6 层，避免抓错整页）。
     */
    private function resultContainer(\DOMNode $node): ?\DOMElement
    {
        $current = $node->parentNode;
        for ($depth = 0; $depth < 6 && $current instanceof \DOMElement; $depth++) {
            $class = ' ' . preg_replace('/\s+/', ' ', $current->getAttribute('class')) . ' ';
            if (str_contains($class, ' result ')) {
                return $current;
            }
            $current = $current->parentNode;
        }

        return null;
    }

    /**
     * 还原 DuckDuckGo 跳转链为真实目标地址；非 http(s) 结果丢弃。
     */
    private function resolveUrl(string $href): string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($href === '') {
            return '';
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }
        if (str_starts_with($href, '/l/?') || str_contains($href, 'duckduckgo.com/l/')) {
            $query = (string)parse_url($href, PHP_URL_QUERY);
            parse_str($query, $params);
            $target = isset($params['uddg']) ? urldecode((string)$params['uddg']) : '';
            if ($target !== '') {
                $href = $target;
            }
        }

        return preg_match('#^https?://#i', $href) ? $href : '';
    }

    /**
     * 拉取 HTML；任何网络/HTTP 异常都记警告并返回 null。
     */
    private function httpGet(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            w_log_warning('DuckDuckGo HTML 请求失败：curl 扩展不可用');
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn($handle, int $downloadTotal, int $downloadNow): int
                => $downloadNow > self::MAX_BYTES ? 1 : 0,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $error !== '' || $status !== 200) {
            w_log_warning('DuckDuckGo HTML 请求失败', ['status' => $status, 'error' => $error]);
            return null;
        }

        return is_string($body) ? $body : null;
    }

    /**
     * 还原实体并折叠空白。
     */
    private function plainText(string $raw): string
    {
        $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
