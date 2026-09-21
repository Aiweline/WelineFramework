<?php

declare(strict_types=1);

namespace Weline\Cdn\Extends\Module\Weline_Framework\Changed\Capability;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\CachePurger;
use Weline\Cdn\Service\UrlSiteResolver;
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
        /** @var Domain $domainModel */
        $domainModel = ObjectManager::getInstance(Domain::class);
        /** @var CachePurger $cachePurger */
        $cachePurger = ObjectManager::getInstance(CachePurger::class);

        $domains = (clone $domainModel)->reset()
            ->where(Domain::schema_fields_SITE_ID, $change->websiteId())
            ->where(Domain::schema_fields_ENABLED, 1)
            ->select()
            ->fetch()
            ->getItems();
        if ($domains === []) {
            return;
        }

        $impact = $change->toArray()['impact'] ?? [];
        $urls = $this->normalizeUrls(array_merge(
            is_array($effect->payload['urls'] ?? null) ? $effect->payload['urls'] : [],
            is_array($impact['urls'] ?? null) ? $impact['urls'] : [],
            is_array($impact['previous_urls'] ?? null) ? $impact['previous_urls'] : [],
        ));
        // CDN 用原始 urls，不做 locale×currency 矩阵膨胀；若 payload 带矩阵则仍 purge

        foreach ($domains as $domain) {
            if (!$domain instanceof Domain) {
                continue;
            }
            $domainId = (int)$domain->getData(Domain::schema_fields_DOMAIN_ID);
            if ($domainId < 1) {
                continue;
            }
            $domainUrls = array_values(array_filter($urls, static function (string $url) use ($domains, $domainId): bool {
                $match = UrlSiteResolver::matchDomainByHost($domains, (string)(parse_url($url, PHP_URL_HOST) ?: ''));
                return $match !== null && (int)$match->getData(Domain::schema_fields_DOMAIN_ID) === $domainId;
            }));
            if ($urls !== [] && $domainUrls === []) {
                continue;
            }
            if ($change->resourceType() === 'website' && $domainUrls !== []) {
                foreach ($domainUrls as $baseUrl) {
                    $result = $cachePurger->purgePublicScope($domainId, $baseUrl);
                    if (($result['success'] ?? false) !== true) {
                        throw new \RuntimeException(__('CDN 资源变更清理失败'));
                    }
                }
                continue;
            }
            $result = $domainUrls === []
                ? $cachePurger->purge($domainId, 'hosts', ['hosts' => [(string)$domain->getData(Domain::schema_fields_DOMAIN_NAME)]])
                : $cachePurger->purge($domainId, 'urls', ['urls' => $domainUrls]);
            if (($result['success'] ?? false) !== true) {
                throw new \RuntimeException(__('CDN 资源变更清理失败'));
            }
        }
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
