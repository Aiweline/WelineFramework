<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Search;

/**
 * Bing RSS 搜索提供者。
 *
 * 使用 Bing 的 RSS 输出做通用网页检索，无需 API Key；适合低频、量小的 B2B 线索发现。
 * 远端失败、超时或解析失败一律返回空数组，由 SearchService 继续尝试下一个提供者。
 */
class BingRssProvider implements SearchProviderInterface
{
    public const SOURCE = 'bing';

    /** RSS 输出端点，q 参数由调用方 rawurlencode */
    private const ENDPOINT = 'https://www.bing.com/search?format=rss&q=';

    /** 单次请求超时（秒） */
    private const TIMEOUT = 8;

    /** 响应体上限，防止异常页面占用内存 */
    private const MAX_BYTES = 1048576;

    /** 桌面 Chrome UA，默认 UA 常被搜索端点拒绝 */
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        $limit = max(1, $limit);
        if ($query === '') {
            return [];
        }

        $xml = $this->httpGet(self::ENDPOINT . rawurlencode($query));
        if ($xml === null || trim($xml) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $feed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($feed === false || !isset($feed->channel->item)) {
            w_log_warning('Bing RSS 解析失败', ['query' => $query]);
            return [];
        }

        $results = [];
        $rank = 1;
        foreach ($feed->channel->item as $item) {
            if (count($results) >= $limit) {
                break;
            }
            $url = trim((string)($item->link ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $title = $this->plainText((string)($item->title ?? ''));
            $results[] = [
                'title' => $title !== '' ? $title : $url,
                'url' => $url,
                'snippet' => $this->plainText((string)($item->description ?? '')),
                'source' => self::SOURCE,
                'rank' => $rank++,
            ];
        }

        return $results;
    }

    /**
     * 拉取 RSS 文本；任何网络/HTTP 异常都记警告并返回 null。
     */
    private function httpGet(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            w_log_warning('Bing RSS 请求失败：curl 扩展不可用');
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
                'Accept: application/rss+xml, application/xml;q=0.9, text/html;q=0.8',
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
            w_log_warning('Bing RSS 请求失败', ['status' => $status, 'error' => $error]);
            return null;
        }

        return is_string($body) ? $body : null;
    }

    /**
     * 去标签、还原实体并折叠空白。
     */
    private function plainText(string $raw): string
    {
        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
