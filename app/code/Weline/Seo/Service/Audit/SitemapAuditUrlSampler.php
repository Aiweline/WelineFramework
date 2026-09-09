<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Audit;

/**
 * After sitemap discovery, keep every singleton URL and only one representative
 * per repeating storefront structure (product/blog/category/help/…).
 */
final class SitemapAuditUrlSampler
{
    /**
     * @param list<string> $urls
     * @return array{
     *   urls: list<string>,
     *   discovered: int,
     *   sampled: int,
     *   collapsed: int,
     *   structures: array<string, array{pattern:string, kept:string, skipped:int}>
     * }
     */
    public function sample(array $urls): array
    {
        $selected = [];
        $structures = [];
        $seenExact = [];

        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url === '' || isset($seenExact[$url])) {
                continue;
            }
            $seenExact[$url] = true;

            $pattern = $this->repeatingStructurePattern($url);
            if ($pattern === null) {
                $selected[] = $url;
                continue;
            }

            if (!isset($structures[$pattern])) {
                $structures[$pattern] = [
                    'pattern' => $pattern,
                    'kept' => $url,
                    'skipped' => 0,
                ];
                $selected[] = $url;
                continue;
            }

            $structures[$pattern]['skipped']++;
        }

        $collapsed = 0;
        foreach ($structures as $row) {
            $collapsed += (int)$row['skipped'];
        }

        return [
            'urls' => \array_values($selected),
            'discovered' => \count($seenExact),
            'sampled' => \count($selected),
            'collapsed' => $collapsed,
            'structures' => $structures,
        ];
    }

    /**
     * @return string|null Pattern key when this URL belongs to a repeating family; null = audit always.
     */
    public function repeatingStructurePattern(string $url): ?string
    {
        $path = (string)(\parse_url($url, PHP_URL_PATH) ?: '');
        $path = \strtolower(\trim(\str_replace('\\', '/', $path), '/'));
        if ($path === '') {
            return null;
        }

        $path = $this->stripLocalePrefix($path);
        if ($path === '') {
            return null;
        }

        // product/{id|slug}
        if (\preg_match('#^product/([1-9][0-9]*|[a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $path) === 1) {
            return 'product/*';
        }

        // blog/category/{slug}
        if (\preg_match('#^blog/category/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $path) === 1) {
            return 'blog/category/*';
        }

        // blog/{slug} (not hub)
        if (\preg_match('#^blog/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $path) === 1) {
            return 'blog/*';
        }

        // category/{...} leaf/list paths
        if (\preg_match('#^category/.+#D', $path) === 1) {
            return 'category/*';
        }

        // help/{slug}
        if (\preg_match('#^help/([a-z0-9][a-z0-9_-]*)$#D', $path) === 1) {
            return 'help/*';
        }

        // promotion/{slug} (hub /promotion stays singleton)
        if (\preg_match('#^promotion/([a-z0-9][a-z0-9_-]*)$#D', $path) === 1) {
            return 'promotion/*';
        }

        // cms-ish page/{slug}
        if (\preg_match('#^page/([a-z0-9][a-z0-9_-]*)$#D', $path) === 1) {
            return 'page/*';
        }

        return null;
    }

    /**
     * Classify a URL into an audit group so panel can show product vs blog vs singleton hubs.
     *
     * @return array{
     *   key: string,
     *   label: string,
     *   parentPath: string,
     *   kind: 'structure'|'singleton'
     * }
     */
    public function classify(string $url): array
    {
        $path = (string)(\parse_url($url, PHP_URL_PATH) ?: '');
        $path = \strtolower(\trim(\str_replace('\\', '/', $path), '/'));
        $path = $this->stripLocalePrefix($path);

        $pattern = $this->repeatingStructurePattern($url);
        if ($pattern !== null) {
            return match ($pattern) {
                'product/*' => [
                    'key' => $pattern,
                    'label' => '商品详情',
                    'parentPath' => '/product',
                    'kind' => 'structure',
                ],
                'blog/*' => [
                    'key' => $pattern,
                    'label' => '博客文章',
                    'parentPath' => '/blog',
                    'kind' => 'structure',
                ],
                'blog/category/*' => [
                    'key' => $pattern,
                    'label' => '博客分类',
                    'parentPath' => '/blog/category',
                    'kind' => 'structure',
                ],
                'category/*' => [
                    'key' => $pattern,
                    'label' => '商品分类',
                    'parentPath' => '/category',
                    'kind' => 'structure',
                ],
                'help/*' => [
                    'key' => $pattern,
                    'label' => '帮助文档',
                    'parentPath' => '/help',
                    'kind' => 'structure',
                ],
                'promotion/*' => [
                    'key' => $pattern,
                    'label' => '促销详情',
                    'parentPath' => '/promotion',
                    'kind' => 'structure',
                ],
                'page/*' => [
                    'key' => $pattern,
                    'label' => 'CMS 页面',
                    'parentPath' => '/page',
                    'kind' => 'structure',
                ],
                default => [
                    'key' => $pattern,
                    'label' => $pattern,
                    'parentPath' => '/' . \explode('/', $pattern)[0],
                    'kind' => 'structure',
                ],
            };
        }

        if ($path === '') {
            return [
                'key' => 'singleton:/',
                'label' => '首页',
                'parentPath' => '/',
                'kind' => 'singleton',
            ];
        }

        $parentPath = '/' . $path;
        $label = $this->singletonLabel($path);

        return [
            'key' => 'singleton:' . $parentPath,
            'label' => $label,
            'parentPath' => $parentPath,
            'kind' => 'singleton',
        ];
    }

    /**
     * @param list<string> $urls
     * @return list<array{
     *   key: string,
     *   label: string,
     *   parentPath: string,
     *   kind: string,
     *   count: int,
     *   urls: list<string>
     * }>
     */
    public function groupUrls(array $urls): array
    {
        $groups = [];
        $seen = [];
        foreach ($urls as $url) {
            $url = \trim((string)$url);
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $meta = $this->classify($url);
            $key = $meta['key'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $meta['label'],
                    'parentPath' => $meta['parentPath'],
                    'kind' => $meta['kind'],
                    'count' => 0,
                    'urls' => [],
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['urls'][] = $url;
        }

        $list = \array_values($groups);
        \usort($list, static function (array $a, array $b): int {
            $kind = (($a['kind'] ?? '') === 'structure' ? 0 : 1) <=> (($b['kind'] ?? '') === 'structure' ? 0 : 1);
            if ($kind !== 0) {
                return $kind;
            }
            $byCount = (int)($b['count'] ?? 0) <=> (int)($a['count'] ?? 0);
            if ($byCount !== 0) {
                return $byCount;
            }

            return \strcmp((string)($a['parentPath'] ?? ''), (string)($b['parentPath'] ?? ''));
        });

        return $list;
    }

    private function singletonLabel(string $path): string
    {
        $hubs = [
            'products' => '商品列表',
            'blog' => '博客首页',
            'help' => '帮助中心',
            'promotion' => '促销列表',
            'search' => '搜索页',
            'cart' => '购物车',
            'checkout' => '结账',
            'best-sellers' => '畅销榜',
            'new-arrivals' => '新品',
            'about' => '关于我们',
            'contact' => '联系我们',
            'account' => '账户',
            'login' => '登录',
            'register' => '注册',
        ];
        $first = \explode('/', $path)[0] ?? $path;

        return $hubs[$first] ?? ('单页 ' . $first);
    }

    private function stripLocalePrefix(string $path): string
    {
        $segments = \array_values(\array_filter(\explode('/', $path), static fn(string $s): bool => $s !== ''));
        if ($segments === []) {
            return '';
        }

        $first = $segments[0];
        // en_US, zh_Hans_CN, ar_SA, …
        if (\preg_match('/^[a-z]{2}(?:_[a-z0-9]+)+$/i', $first) === 1) {
            \array_shift($segments);
        }

        return \implode('/', $segments);
    }
}
