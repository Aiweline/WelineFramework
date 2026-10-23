<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\Domain;

/**
 * 资源变更 → 各 CDN 域名要清的公网 URL。
 *
 * 公网主机名只由 CDN 域名绑定决定：调用方给出的相对路径、或主机名不属于任何绑定域名的
 * 绝对 URL（安装本机 / 预览基址，例如 `http://localhost/guide`），都只是「路径意图」，
 * 必须投影到所属站点每个绑定域名的公开入口 × 站点语言变体，边缘缓存才会真的被清掉。
 */
final class EdgePurgeUrlPlanner
{
    public function __construct(
        private readonly FpcPolicyTargetService $targets,
        private readonly WarmupLocaleUrlExpander $locales,
    ) {
    }

    /**
     * @param list<Domain> $domains 已按变更站点范围加载的启用域名
     * @param list<string> $urls impact.urls / previous_urls / effect payload（绝对或站内相对）
     * @param list<string> $slugs impact.slugs（不含前导斜杠）
     * @param list<int> $intentSiteIds 路径意图归属的站点
     * @return array<int, list<string>> domain_id → 公网 URL
     */
    public function plan(array $domains, array $urls, array $slugs, array $intentSiteIds): array
    {
        foreach ($slugs as $slug) {
            $slug = trim((string)$slug);
            if ($slug !== '') {
                $urls[] = '/' . ltrim($slug, '/');
            }
        }

        return self::project(
            $domains,
            $urls,
            $intentSiteIds,
            fn(Domain $domain): array => $this->targets->publicEndpoints($domain),
            fn(int $siteId, string $baseUrl, string $path): array => array_map(
                static fn(array $row): string => (string)$row['url'],
                $this->locales->expandRoute($siteId, $baseUrl, $path),
            ),
        );
    }

    /**
     * @param list<Domain> $domains
     * @param list<string> $urls
     * @param list<int> $intentSiteIds
     * @param callable(Domain): list<array{host:string,base_url:string}> $endpointsFor
     * @param callable(int, string, string): list<string> $localize siteId, 公开 base, 站内路径 → 语言变体绝对 URL
     * @return array<int, list<string>>
     */
    public static function project(
        array $domains,
        array $urls,
        array $intentSiteIds,
        callable $endpointsFor,
        callable $localize,
    ): array {
        $planned = [];
        $paths = [];
        foreach ($urls as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') {
                continue;
            }
            $parts = parse_url($raw);
            if ($parts === false) {
                continue;
            }
            $path = self::pathOf($parts);
            if ($path === null) {
                continue;
            }
            $host = strtolower((string)($parts['host'] ?? ''));
            if ($host !== '' && preg_match('#^https?://#i', $raw) === 1) {
                $owner = UrlSiteResolver::matchDomainByHost($domains, $host);
                if ($owner !== null) {
                    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
                    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                    $url = $scheme . '://' . $host . $port . $path;
                    $planned[(int)$owner->getData(Domain::schema_fields_DOMAIN_ID)][$url] = $url;
                    continue;
                }
            }
            $paths[$path] = $path;
        }

        if ($paths !== []) {
            $intent = array_fill_keys(array_map('intval', $intentSiteIds), true);
            foreach ($domains as $domain) {
                if (!$domain instanceof Domain) {
                    continue;
                }
                $siteId = (int)$domain->getData(Domain::schema_fields_SITE_ID);
                if (!isset($intent[$siteId])) {
                    continue;
                }
                $domainId = (int)$domain->getData(Domain::schema_fields_DOMAIN_ID);
                foreach ($endpointsFor($domain) as $endpoint) {
                    $base = rtrim((string)($endpoint['base_url'] ?? ''), '/');
                    $endpointHost = strtolower((string)($endpoint['host'] ?? ''));
                    if ($base === '' || $endpointHost === '') {
                        continue;
                    }
                    foreach ($paths as $path) {
                        $variants = $localize($siteId, $base, $path);
                        if ($variants === []) {
                            $variants = [$base . $path];
                        }
                        foreach ($variants as $url) {
                            $url = (string)$url;
                            if (strtolower((string)parse_url($url, PHP_URL_HOST)) !== $endpointHost) {
                                continue;
                            }
                            $planned[$domainId][$url] = $url;
                        }
                    }
                }
            }
        }

        $out = [];
        foreach ($planned as $domainId => $set) {
            $out[$domainId] = array_values($set);
        }

        return $out;
    }

    /**
     * 站内路径 + 去掉 FPC 矩阵标记后的查询串；越界路径返回 null。
     *
     * @param array<string, mixed> $parts
     */
    private static function pathOf(array $parts): ?string
    {
        $path = '/' . ltrim((string)($parts['path'] ?? '/'), '/');
        if (str_contains($path, '/../') || str_ends_with($path, '/..')) {
            return null;
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
            unset($query['_wl'], $query['_wc']);
        }

        return $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }
}
