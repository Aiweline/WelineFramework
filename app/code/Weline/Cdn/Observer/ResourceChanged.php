<?php

declare(strict_types=1);

namespace Weline\Cdn\Observer;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\CachePurger;
use Weline\Cdn\Service\UrlSiteResolver;
use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/**
 * CDN side effect for ResourceChange v1; purge operations are idempotent.
 * The current Event dispatcher invokes this observer inline. Async interface/XML
 * metadata alone do not provide durable delivery or retries on that runtime path.
 */
final class ResourceChanged implements AsyncObserverInterface
{
    public function __construct(
        private readonly Domain $domain,
        private readonly CachePurger $cachePurger,
    ) {
    }

    public function supportsAsyncEvent(string $eventName, int $schemaVersion): bool
    {
        return $eventName === ResourceChange::EVENT_NAME
            && $schemaVersion === ResourceChange::SCHEMA_VERSION;
    }

    public function execute(Event &$event): void
    {
        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            throw new NonRetryableAsyncEventException(
                'resource_change_contract_mismatch',
                __('CDN ResourceChange Observer 只接受 v1 契约')
            );
        }
        if (!in_array($change->resourceType(), ['website', 'cms_page', 'url_rewrite', 'theme', 'theme_layout', 'product_search_projection'], true)) {
            return;
        }

        $domains = (clone $this->domain)->reset()
            ->where(Domain::schema_fields_SITE_ID, $change->websiteId())
            ->where(Domain::schema_fields_ENABLED, 1)
            ->select()
            ->fetch()
            ->getItems();
        if ($domains === []) {
            return;
        }

        $payload = $change->toArray();
        $impact = is_array($payload['impact'] ?? null) ? $payload['impact'] : [];
        $urls = $this->normalizeUrls(array_merge(
            is_array($impact['urls'] ?? null) ? $impact['urls'] : [],
            is_array($impact['previous_urls'] ?? null) ? $impact['previous_urls'] : [],
        ));
        if ($urls === [] && $change->resourceType() === 'product_search_projection') {
            return;
        }

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
                    $result = $this->cachePurger->purgePublicScope($domainId, $baseUrl);
                    if (($result['success'] ?? false) !== true) {
                        throw new \RuntimeException(__('CDN 资源变更清理失败'));
                    }
                }
                continue;
            }
            $result = $domainUrls === []
                ? $this->cachePurger->purge($domainId, 'hosts', ['hosts' => [(string)$domain->getData(Domain::schema_fields_DOMAIN_NAME)]])
                : $this->cachePurger->purge($domainId, 'urls', ['urls' => $domainUrls]);
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
                $normalized[$url] = $url;
            }
        }
        return array_values($normalized);
    }

}
