<?php

declare(strict_types=1);

namespace Weline\Cdn\Extends\Module\Weline_Framework\Changed\Capability;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\CachePurger;
use Weline\Cdn\Service\EdgePurgeUrlPlanner;
use Weline\Framework\Event\Changed\ChangedCapabilityInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN purge（原 Cdn ResourceChanged Observer）。
 */
final class CdnCapability implements ChangedCapabilityInterface
{
    public function code(): string
    {
        return 'cdn';
    }

    public function description(): string
    {
        return 'CDN URL/hosts purge';
    }

    public function supportedEffects(): array
    {
        return [InvalidationEffect::CODE_CDN_PURGE];
    }

    public function execute(InvalidationEffect $effect, ResourceChange $change): void
    {
        if ($effect->code !== InvalidationEffect::CODE_CDN_PURGE) {
            return;
        }
        /** @var CachePurger $cachePurger */
        $cachePurger = ObjectManager::getInstance(CachePurger::class);

        $impact = $change->toArray()['impact'] ?? [];
        $rawUrls = $this->stringList(array_merge(
            is_array($effect->payload['urls'] ?? null) ? $effect->payload['urls'] : [],
            is_array($impact['urls'] ?? null) ? $impact['urls'] : [],
            is_array($impact['previous_urls'] ?? null) ? $impact['previous_urls'] : [],
        ));

        // Website 保存：统一走 purgeWebsiteScope（无绑定域名时本机仍 triggered）
        if ($change->resourceType() === 'website') {
            $result = $cachePurger->purgeWebsiteScope((int)$change->websiteId(), $this->normalizeUrls($rawUrls));
            if (($result['success'] ?? false) !== true) {
                throw new \RuntimeException(__('CDN 资源变更清理失败：%{message}', [
                    'message' => (string)($result['message'] ?? ''),
                ]));
            }

            return;
        }

        $siteIds = $this->siteIdsForChange($change);
        if ($siteIds === []) {
            return;
        }

        /** @var Domain $domainModel */
        $domainModel = ObjectManager::getInstance(Domain::class);
        // Product cross-site identity may attach absolute URLs for every member website; load CDN
        // domains for impact.website_ids ∪ change.websiteId (not mutation site alone).
        $domains = (clone $domainModel)->reset()
            ->where(Domain::schema_fields_SITE_ID, $siteIds, 'in')
            ->where(Domain::schema_fields_ENABLED, 1)
            ->select()
            ->fetch()
            ->getItems();
        if ($domains === []) {
            return;
        }

        // 已绑定主机名的绝对 URL 原样清；相对路径与本机/预览主机名的 URL 由规划器投影到
        // 变更站点的公网入口 × 语言变体（货币不进 URL，不做矩阵膨胀）。
        $slugs = $this->stringList(is_array($impact['slugs'] ?? null) ? $impact['slugs'] : []);
        $intentSiteIds = $change->hasWebsiteContext() && (int)$change->websiteId() >= 0
            ? [(int)$change->websiteId()]
            : $siteIds;
        /** @var EdgePurgeUrlPlanner $planner */
        $planner = ObjectManager::getInstance(EdgePurgeUrlPlanner::class);
        $planned = $planner->plan(array_values($domains), $rawUrls, $slugs, $intentSiteIds);
        $hasUrlIntent = $rawUrls !== [] || $slugs !== [];

        foreach ($domains as $domain) {
            if (!$domain instanceof Domain) {
                continue;
            }
            $domainId = (int)$domain->getData(Domain::schema_fields_DOMAIN_ID);
            if ($domainId < 1) {
                continue;
            }
            $domainUrls = $planned[$domainId] ?? [];
            if ($hasUrlIntent && $domainUrls === []) {
                continue;
            }
            // 无 URL 意图（全局主题发布等）：清该域名下本站全部公网入口，而不是只清绑定根域。
            $results = $domainUrls === []
                ? $cachePurger->purgeDomainPublicScope($domain)
                : [$cachePurger->purge($domainId, 'urls', ['urls' => $domainUrls])];
            foreach ($results as $result) {
                if (($result['success'] ?? false) !== true) {
                    throw new \RuntimeException(__('CDN 资源变更清理失败：%{domain} %{message}', [
                        'domain' => (string)$domain->getData(Domain::schema_fields_DOMAIN_NAME),
                        'message' => (string)($result['message'] ?? ''),
                    ]));
                }
            }
        }
    }

    /**
     * @return list<int>
     */
    private function siteIdsForChange(ResourceChange $change): array
    {
        $impact = $change->toArray()['impact'] ?? [];
        $ids = [];
        if (is_array($impact['website_ids'] ?? null)) {
            foreach ($impact['website_ids'] as $id) {
                $id = (int)$id;
                if ($id >= 0) {
                    $ids[$id] = $id;
                }
            }
        }
        if ($change->hasWebsiteContext()) {
            $wid = (int)$change->websiteId();
            if ($wid >= 0) {
                $ids[$wid] = $wid;
            }
        }

        return array_values($ids);
    }

    /** @param array<int,mixed> $values @return list<string> */
    private function stringList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $out[$value] = $value;
            }
        }

        return array_values($out);
    }

    /** @param array<int,mixed> $urls @return list<string> */
    private function normalizeUrls(array $urls): array
    {
        $normalized = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                // 去掉矩阵标记查询参数，CDN 用原始 URL
                $parts = parse_url($url);
                if ($parts === false) {
                    $normalized[$url] = $url;
                    continue;
                }
                $query = [];
                if (!empty($parts['query'])) {
                    parse_str((string)$parts['query'], $query);
                    unset($query['_wl'], $query['_wc']);
                }
                $path = $parts['path'] ?? '/';
                $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
                $host = strtolower((string)($parts['host'] ?? ''));
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                $q = $query !== [] ? '?' . http_build_query($query) : '';
                $clean = $scheme . '://' . $host . $port . $path . $q;
                $normalized[$clean] = $clean;
            }
        }
        return array_values($normalized);
    }
}
