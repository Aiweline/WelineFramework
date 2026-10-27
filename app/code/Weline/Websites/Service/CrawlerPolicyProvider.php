<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Api\Crawler\CrawlerPolicyEntry;
use Weline\Websites\Api\Crawler\CrawlerPolicyProviderInterface;

/**
 * Canonical read model for website crawler allow/block entries.
 */
final class CrawlerPolicyProvider implements CrawlerPolicyProviderInterface
{
    public function __construct(
        private readonly WebsiteCrawlerPolicyService $policyService,
    ) {
    }

    public function isEnabled(int $websiteId): bool
    {
        return (bool)($this->rule($websiteId)['enabled'] ?? false);
    }

    /**
     * @return list<CrawlerPolicyEntry>
     */
    public function entriesForWebsite(int $websiteId): array
    {
        $entries = [];
        foreach ((array)($this->rule($websiteId)['entries'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $entries[] = CrawlerPolicyEntry::fromArray($row);
        }

        return $entries;
    }

    public function revisionForWebsite(int $websiteId): string
    {
        $rows = \array_map(
            static fn(CrawlerPolicyEntry $entry): array => $entry->toArray(),
            $this->entriesForWebsite($websiteId),
        );
        \usort(
            $rows,
            static fn(array $left, array $right): int => \strcmp(
                (string)($left['id'] ?? ''),
                (string)($right['id'] ?? ''),
            ),
        );

        return \hash(
            'sha256',
            (string)\json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(int $websiteId): array
    {
        // Never turn a read failure into "empty policy" — edge publishers would
        // otherwise delete the site's WAF rules on a transient DB error.
        return $this->policyService->loadRule($websiteId);
    }
}
