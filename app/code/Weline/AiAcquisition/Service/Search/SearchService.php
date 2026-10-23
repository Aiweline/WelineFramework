<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Search;

use Weline\Framework\Manager\ObjectManager;

/**
 * 网页搜索服务。
 *
 * 按内置链（Bing RSS → DuckDuckGo HTML）依次尝试，任一提供者返回非空结果即停止；
 * 统一做 URL 去重（host + path）、按 rank 排序、限制条数并重排 rank。
 * 检索词原样传递，允许 `site:`、引号等原生搜索语法。
 */
class SearchService
{
    /** 单次检索硬上限，防止提示词/工具参数把结果撑爆 */
    public const MAX_RESULTS = 20;

    /** @var list<SearchProviderInterface> */
    private array $providers;

    /**
     * @param list<SearchProviderInterface> $providers 显式注入的提供者；为空时使用内置链
     */
    public function __construct(array $providers = [])
    {
        $this->providers = [];
        foreach ($providers as $provider) {
            if ($provider instanceof SearchProviderInterface) {
                $this->providers[] = $provider;
            }
        }
    }

    /**
     * 检索网页线索。
     *
     * @param string $query 原始检索词
     * @param int $limit 期望条数，最终不超过 MAX_RESULTS
     * @param string|null $site 站点限定，未在 query 中写 `site:` 时追加
     * @return list<array{title:string,url:string,snippet:string,source:string,rank:int}>
     */
    public function search(string $query, int $limit = 10, ?string $site = null): array
    {
        $query = $this->buildQuery($query, $site);
        if ($query === '') {
            return [];
        }
        $limit = max(1, min($limit, self::MAX_RESULTS));

        foreach ($this->providers() as $provider) {
            try {
                $raw = $provider->search($query, $limit);
            } catch (\Throwable $e) {
                if (\function_exists('w_log_warning')) {
                    w_log_warning('搜索提供者执行失败', ['error' => $e->getMessage()]);
                }
                continue;
            }

            if (!is_array($raw) || $raw === []) {
                continue;
            }
            $items = $this->collect($raw);
            if ($items === []) {
                continue;
            }

            return $this->finalize($items, $limit);
        }

        return [];
    }

    /**
     * 构造检索词：站点限定仅在未显式给出时追加，其余语法（引号等）保持原样。
     */
    private function buildQuery(string $query, ?string $site): string
    {
        $query = trim($query);
        $site = $this->normalizeSite($site);
        if ($site !== '' && !preg_match('/\bsite\s*:/i', $query)) {
            $query .= ' site:' . $site;
        }

        return trim($query);
    }

    /**
     * 站点限定只保留 host，避免把完整 URL 拼进检索词。
     */
    private function normalizeSite(?string $site): string
    {
        $site = trim((string)$site);
        if ($site === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $site)) {
            $host = (string)parse_url($site, PHP_URL_HOST);
            $site = $host !== '' ? $host : $site;
        }

        return trim(ltrim($site, '/'));
    }

    /**
     * 过滤非法条目并写入统一结构；rank 缺省按出现顺序补齐。
     *
     * @param list<mixed> $raw
     * @return list<array{title:string,url:string,snippet:string,source:string,rank:int,_order:int}>
     */
    private function collect(array $raw): array
    {
        $items = [];
        $position = 0;
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = trim((string)($row['url'] ?? ''));
            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }
            $position++;
            $rank = (int)($row['rank'] ?? 0);
            $items[] = [
                'title' => $this->cleanText($row['title'] ?? ''),
                'url' => $url,
                'snippet' => $this->cleanText($row['snippet'] ?? ''),
                'source' => trim((string)($row['source'] ?? '')),
                'rank' => $rank > 0 ? $rank : $position,
                '_order' => count($items),
            ];
        }

        return $items;
    }

    /**
     * 去重、排序、截断并重排 rank。
     *
     * @param list<array{title:string,url:string,snippet:string,source:string,rank:int,_order:int}> $items
     * @return list<array{title:string,url:string,snippet:string,source:string,rank:int}>
     */
    private function finalize(array $items, int $limit): array
    {
        $seen = [];
        $unique = [];
        foreach ($items as $item) {
            $key = $this->dedupeKey($item['url']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $item;
        }

        usort($unique, static fn(array $left, array $right): int
            => [$left['rank'], $left['_order']] <=> [$right['rank'], $right['_order']]);

        $results = [];
        $rank = 1;
        foreach (array_slice($unique, 0, $limit) as $item) {
            unset($item['_order']);
            $item['rank'] = $rank++;
            $results[] = $item;
        }

        return $results;
    }

    /**
     * 去重键：小写 host + 去尾斜杠 path，忽略 scheme、查询串与锚点。
     */
    private function dedupeKey(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $host = strtolower(trim((string)($parts['host'] ?? '')));
        if ($host === '') {
            return '';
        }
        $path = rtrim((string)($parts['path'] ?? ''), '/');

        return $host . ($path === '' ? '/' : $path);
    }

    /**
     * 文本归一：去标签、还原实体、折叠空白。
     */
    private function cleanText(mixed $value): string
    {
        $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * 惰性构建内置提供者链，显式注入时直接使用注入值。
     *
     * @return list<SearchProviderInterface>
     */
    private function providers(): array
    {
        if ($this->providers !== []) {
            return $this->providers;
        }
        $this->providers = [
            ObjectManager::getInstance(BingRssProvider::class),
            ObjectManager::getInstance(DuckDuckGoHtmlProvider::class),
        ];

        return $this->providers;
    }
}
