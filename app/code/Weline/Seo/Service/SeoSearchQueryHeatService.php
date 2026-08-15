<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Model\SeoSearchQueryStat;

/** Persists GSC query rows and scores heat for word-cloud / block evolution. */
final class SeoSearchQueryHeatService
{
    public function __construct(private readonly SeoSearchQueryStat $statModel)
    {
    }

    public static function heat(int $clicks, int $impressions, float $position, float $ctr): float
    {
        $clicks = \max(0, $clicks);
        $impressions = \max(0, $impressions);
        $position = \max(0.0, $position);
        $ctr = \max(0.0, \min(1.0, $ctr > 1.0 ? $ctr / 100.0 : $ctr));
        $impScore = \min(1.0, \log1p($impressions) / \log1p(10000));
        $clickScore = \min(1.0, \log1p($clicks) / \log1p(1000));
        $rankScore = $position <= 0.0 ? 0.0 : \max(0.0, \min(1.0, (21.0 - $position) / 20.0));

        return \round(100.0 * ((0.35 * $impScore) + (0.35 * $clickScore) + (0.20 * $rankScore) + (0.10 * $ctr)), 2);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function upsertQueries(
        int $websiteId,
        int $accountId,
        string $platform,
        array $rows,
        string $windowStart,
        string $windowEnd,
    ): int {
        if ($websiteId < 0 || $accountId <= 0 || $rows === []) {
            return 0;
        }
        $platform = \strtolower(\trim($platform)) ?: 'google';
        $windowStart = \substr($windowStart, 0, 10);
        $windowEnd = \substr($windowEnd, 0, 10);
        $now = \date('Y-m-d H:i:s');
        $written = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $query = \trim((string)($row['query'] ?? $row['keys'][0] ?? ''));
            if ($query === '') {
                continue;
            }
            $clicks = \max(0, (int)($row['clicks'] ?? 0));
            $impressions = \max(0, (int)($row['impressions'] ?? 0));
            $position = \max(0.0, (float)($row['position'] ?? $row['average_position'] ?? 0));
            $ctr = (float)($row['ctr'] ?? 0);
            if ($ctr > 1.0) {
                $ctr = $ctr / 100.0;
            }
            $hash = \hash('sha256', \strtolower($query));
            $item = (clone $this->statModel)->clearData()->clearQuery()
                ->where(SeoSearchQueryStat::schema_fields_WEBSITE_ID, $websiteId)
                ->where(SeoSearchQueryStat::schema_fields_ACCOUNT_ID, $accountId)
                ->where(SeoSearchQueryStat::schema_fields_PLATFORM, $platform)
                ->where(SeoSearchQueryStat::schema_fields_QUERY_HASH, $hash)
                ->where(SeoSearchQueryStat::schema_fields_WINDOW_END, $windowEnd)
                ->find()
                ->fetch();
            if (!$item->getId()) {
                $item->setData(SeoSearchQueryStat::schema_fields_CREATED_AT, $now);
            }
            $item->setData(SeoSearchQueryStat::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(SeoSearchQueryStat::schema_fields_ACCOUNT_ID, $accountId)
                ->setData(SeoSearchQueryStat::schema_fields_PLATFORM, $platform)
                ->setData(SeoSearchQueryStat::schema_fields_QUERY, \mb_substr($query, 0, 512))
                ->setData(SeoSearchQueryStat::schema_fields_QUERY_HASH, $hash)
                ->setData(SeoSearchQueryStat::schema_fields_CLICKS, $clicks)
                ->setData(SeoSearchQueryStat::schema_fields_IMPRESSIONS, $impressions)
                ->setData(SeoSearchQueryStat::schema_fields_CTR, \round($ctr, 6))
                ->setData(SeoSearchQueryStat::schema_fields_AVERAGE_POSITION, \round($position, 2))
                ->setData(SeoSearchQueryStat::schema_fields_HEAT, self::heat($clicks, $impressions, $position, $ctr))
                ->setData(SeoSearchQueryStat::schema_fields_WINDOW_START, $windowStart)
                ->setData(SeoSearchQueryStat::schema_fields_WINDOW_END, $windowEnd)
                ->setData(SeoSearchQueryStat::schema_fields_LAST_SYNC_AT, $now)
                ->setData(SeoSearchQueryStat::schema_fields_UPDATED_AT, $now)
                ->save();
            $written++;
        }

        return $written;
    }

    /**
     * @return array{contract:string,website_id:int,window:array{start:string,end:string},items:list<array<string,mixed>>,gsc_bound:bool}
     */
    public function cloud(int $websiteId, int $limit = 80): array
    {
        $limit = \max(1, \min(200, $limit));
        $rows = (clone $this->statModel)->clearData()->clearQuery()
            ->where(SeoSearchQueryStat::schema_fields_WEBSITE_ID, $websiteId)
            ->order(SeoSearchQueryStat::schema_fields_HEAT, 'DESC')
            ->order(SeoSearchQueryStat::schema_fields_IMPRESSIONS, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        $items = [];
        $windowStart = '';
        $windowEnd = '';
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $items[] = $this->publicRow($row);
            $windowStart = $windowStart !== '' ? $windowStart : (string)($row[SeoSearchQueryStat::schema_fields_WINDOW_START] ?? '');
            $windowEnd = (string)($row[SeoSearchQueryStat::schema_fields_WINDOW_END] ?? $windowEnd);
        }

        return [
            'contract' => 'seo.search_query_cloud.v1',
            'website_id' => $websiteId,
            'window' => ['start' => $windowStart, 'end' => $windowEnd],
            'gsc_bound' => $items !== [],
            'items' => $items,
        ];
    }

    /** @return list<int> */
    public function listWebsiteIds(): array
    {
        $rows = (clone $this->statModel)->clearData()->clearQuery()
            ->limit(2000)
            ->select()
            ->fetchArray();
        $ids = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row[SeoSearchQueryStat::schema_fields_WEBSITE_ID] ?? -1);
            if ($id >= 0) {
                $ids[$id] = $id;
            }
        }
        \ksort($ids, \SORT_NUMERIC);

        return \array_values($ids);
    }

    /**
     * @param list<array<string,mixed>> $targets
     * @param list<array<string,mixed>> $queries
     * @return list<array{target:array<string,mixed>,heat:float,query:string}>
     */
    public function rankTargetsByQueryHeat(array $targets, array $queries, int $limit = 8): array
    {
        $ranked = [];
        foreach ($targets as $target) {
            if (!\is_array($target)) {
                continue;
            }
            $values = \is_array($target['current_values'] ?? null) ? $target['current_values'] : [];
            $hits = $this->matchQueries($queries, $values, 1);
            if ($hits === []) {
                continue;
            }
            $ranked[] = [
                'target' => $target,
                'heat' => (float)($hits[0]['heat'] ?? 0),
                'query' => (string)($hits[0]['query'] ?? ''),
            ];
        }
        \usort(
            $ranked,
            static function (array $left, array $right): int {
                $heat = $right['heat'] <=> $left['heat'];
                if ($heat !== 0) {
                    return $heat;
                }
                $leftBlock = \trim((string)($left['target']['block_key'] ?? ''));
                $rightBlock = \trim((string)($right['target']['block_key'] ?? ''));
                if (($leftBlock === '') !== ($rightBlock === '')) {
                    return $leftBlock === '' ? 1 : -1;
                }
                $leftPage = (string)($left['target']['page_type'] ?? '');
                $rightPage = (string)($right['target']['page_type'] ?? '');
                if ($leftPage === 'home_page' && $rightPage !== 'home_page') {
                    return -1;
                }
                if ($rightPage === 'home_page' && $leftPage !== 'home_page') {
                    return 1;
                }
                if ($leftBlock === 'hero' && $rightBlock !== 'hero') {
                    return -1;
                }
                if ($rightBlock === 'hero' && $leftBlock !== 'hero') {
                    return 1;
                }

                return 0;
            },
        );

        return \array_slice($ranked, 0, \max(1, \min(40, $limit)));
    }

    /**
     * @param array<string,mixed> $ownerValues
     * @return list<array<string,mixed>>
     */
    public function matchingOwner(int $websiteId, array $ownerValues, int $limit = 20): array
    {
        return $this->matchQueries($this->cloud($websiteId, 200)['items'], $ownerValues, $limit);
    }

    /**
     * @param list<array<string,mixed>> $queries
     * @param array<string,mixed> $ownerValues
     * @return list<array<string,mixed>>
     */
    public function matchQueries(array $queries, array $ownerValues, int $limit = 20): array
    {
        $haystack = $this->normalizeMatchText($this->flattenValues($ownerValues));
        $haystackCompact = $this->compactMatchText($haystack);
        if ($haystack === '' && $haystackCompact === '') {
            return [];
        }
        $matched = [];
        foreach ($queries as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $query = \trim((string)($item['query'] ?? ''));
            if (\mb_strlen($query) < 2 || !$this->queryHitsOwner($query, $haystack, $haystackCompact)) {
                continue;
            }
            $matched[] = $item;
            if (\count($matched) >= $limit) {
                break;
            }
        }

        return $matched;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicRow(array $row): array
    {
        return [
            'query' => (string)($row[SeoSearchQueryStat::schema_fields_QUERY] ?? ''),
            'clicks' => \max(0, (int)($row[SeoSearchQueryStat::schema_fields_CLICKS] ?? 0)),
            'impressions' => \max(0, (int)($row[SeoSearchQueryStat::schema_fields_IMPRESSIONS] ?? 0)),
            'ctr' => (float)($row[SeoSearchQueryStat::schema_fields_CTR] ?? 0),
            'average_position' => (float)($row[SeoSearchQueryStat::schema_fields_AVERAGE_POSITION] ?? 0),
            'heat' => (float)($row[SeoSearchQueryStat::schema_fields_HEAT] ?? 0),
            'platform' => (string)($row[SeoSearchQueryStat::schema_fields_PLATFORM] ?? ''),
            'window_start' => (string)($row[SeoSearchQueryStat::schema_fields_WINDOW_START] ?? ''),
            'window_end' => (string)($row[SeoSearchQueryStat::schema_fields_WINDOW_END] ?? ''),
        ];
    }

    /** @param array<string,mixed> $values */
    private function flattenValues(array $values): string
    {
        $parts = [];
        \array_walk_recursive($values, static function (mixed $value) use (&$parts): void {
            if (\is_scalar($value)) {
                $text = \trim((string)$value);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        });

        return \implode(' ', $parts);
    }

    private function queryHitsOwner(string $query, string $haystack, string $haystackCompact): bool
    {
        $normalized = $this->normalizeMatchText($query);
        if ($normalized !== '' && \str_contains($haystack, $normalized)) {
            return true;
        }
        $compact = $this->compactMatchText($query);
        if (\mb_strlen($compact) >= 4 && $haystackCompact !== '' && \str_contains($haystackCompact, $compact)) {
            return true;
        }

        return false;
    }

    private function normalizeMatchText(string $text): string
    {
        $text = \strtolower(\trim($text));
        if ($text === '') {
            return '';
        }
        $text = \preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return \trim(\preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function compactMatchText(string $text): string
    {
        return \strtolower((string)\preg_replace('/[^\p{L}\p{N}]+/u', '', $text));
    }
}
