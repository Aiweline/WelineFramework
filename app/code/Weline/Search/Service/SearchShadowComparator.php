<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\ProductDirectCatalogReaderInterface;

/**
 * Compare Search index projection vs Product direct read（shadow window）.
 */
final class SearchShadowComparator
{
    public function __construct(
        private readonly SearchQueryService $query,
        private readonly ProductDirectCatalogReaderInterface $direct,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $queries
     * @return array<string, mixed>
     */
    public function observe(array $queries): array
    {
        $diffs = [];
        $queryReports = [];
        $maxDrift = 0;
        foreach ($queries as $index => $query) {
            // Shadow never changes storefront serving. Compare through the
            // explicit preview entry instead of making shadow serve the index.
            $indexResult = $this->query->previewIndexForShadow($query);
            $directRead = $this->direct->searchPublished($query);
            $indexIds = $this->ids($indexResult['hits']);
            $directIds = $this->ids($directRead->hits);
            sort($indexIds);
            sort($directIds);
            $indexHash = $this->hitHash($indexResult['hits']);
            $directHash = $this->hitHash($directRead->hits);
            $queryReport = [
                'query_index' => $index,
                'website_id' => (int)($query['website_id'] ?? -1),
                'store_id' => (int)($query['store_id'] ?? 0),
                'channel_id' => (int)($query['channel_id'] ?? 0),
                'index_hit_count' => \count($indexIds),
                'direct_hit_count' => \count($directIds),
                'index_hit_hash' => $indexHash,
                'direct_hit_hash' => $directHash,
            ];
            $queryReports[] = $queryReport;
            if ($indexIds !== $directIds || !\hash_equals($indexHash, $directHash)) {
                $diffs[] = [
                    'code' => $indexIds !== $directIds
                        ? 'hit_set_mismatch'
                        : 'hit_payload_mismatch',
                    'index' => $index,
                    'index_ids' => $indexIds,
                    'direct_ids' => $directIds,
                    'index_hit_hash' => $indexHash,
                    'direct_hit_hash' => $directHash,
                ];
            }
            $drift = abs(count($indexIds) - count($directIds));
            $maxDrift = max($maxDrift, $drift);
        }

        $report = [
            'ok' => $diffs === [],
            'query_count' => count($queries),
            'unclassified_diff_count' => count($diffs),
            'diffs' => $diffs,
            'queries' => $queryReports,
            'max_hit_count_drift' => $maxDrift,
            'conserved' => $diffs === [],
        ];
        $report['report_hash'] = $this->hash($report);

        return $report;
    }

    /**
     * @param list<array<string, mixed>> $hits
     * @return list<string>
     */
    private function ids(array $hits): array
    {
        $out = [];
        foreach ($hits as $hit) {
            $out[] = (string) ($hit['entity_type'] ?? 'product') . ':' . (string) ($hit['entity_id'] ?? '');
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $hits */
    private function hitHash(array $hits): string
    {
        $canonical = [];
        foreach ($hits as $hit) {
            $row = \array_intersect_key($hit, \array_flip([
                'entity_type',
                'entity_id',
                'website_id',
                'website_code',
                'store_id',
                'store_code',
                'channel_id',
                'channel_code',
                'locale',
                'currency',
                'document_version',
                'title',
                'sku',
                'status',
            ]));
            \ksort($row);
            $canonical[] = $row;
        }
        \usort(
            $canonical,
            static fn(array $left, array $right): int => [
                (string)($left['entity_type'] ?? ''),
                (string)($left['entity_id'] ?? ''),
                (int)($left['store_id'] ?? 0),
                (int)($left['channel_id'] ?? 0),
                (string)($left['locale'] ?? ''),
                (string)($left['currency'] ?? ''),
            ] <=> [
                (string)($right['entity_type'] ?? ''),
                (string)($right['entity_id'] ?? ''),
                (int)($right['store_id'] ?? 0),
                (int)($right['channel_id'] ?? 0),
                (string)($right['locale'] ?? ''),
                (string)($right['currency'] ?? ''),
            ],
        );

        return $this->hash($canonical);
    }

    private function hash(mixed $value): string
    {
        return \hash('sha256', (string)\json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }
}
