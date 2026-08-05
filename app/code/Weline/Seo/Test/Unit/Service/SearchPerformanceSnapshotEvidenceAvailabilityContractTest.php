<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SearchPerformanceSnapshotEvidenceAvailabilityContractTest extends TestCase
{
    public function testEmptySearchWindowIsExplicitlyUnavailableToTheEvidenceBoundary(): void
    {
        $root = \dirname(__DIR__, 3);
        $snapshot = (string)\file_get_contents($root . '/Service/SearchPerformanceSnapshotService.php');
        $evidence = (string)\file_get_contents($root . '/Service/OptimizationEvidenceService.php');

        self::assertStringContainsString("'complete' => \$hasRows", $snapshot);
        self::assertStringContainsString("'reasons' => \$hasRows ? [] : ['evidence_unavailable']", $snapshot);
        self::assertStringContainsString('search_evidence_unavailable', $evidence);
    }

    public function testAcceptanceFixtureIsDualGatedBackendOnlyAndUsesCanonicalStatsModel(): void
    {
        $root = \dirname(__DIR__, 3);
        $model = (string)\file_get_contents($root . '/Model/SeoWebsiteStats.php');
        $provider = (string)\file_get_contents($root . '/extends/module/Weline_Framework/Query/SeoAdminQueryProvider.php');
        $sync = (string)\file_get_contents($root . '/Cron/StatsSync.php');

        self::assertStringContainsString("getenv('WELINE_SEO_ACCEPTANCE_MODE') !== '1'", $model);
        self::assertStringContainsString("getenv('WELINE_SEO_ACCEPTANCE_FIXTURES') !== '1'", $model);
        self::assertStringContainsString('SEO_SEARCH_ACCEPTANCE_FIXTURE_DISABLED', $model);
        self::assertStringContainsString('seo.search_acceptance_fixture_receipt.v1', $model);
        self::assertStringContainsString('SEO_SEARCH_ACCEPTANCE_FIXTURE_REQUEST_CONFLICT', $model);
        self::assertStringContainsString("setData(self::schema_fields_STATS_DATE, \$row['date'])", $model);
        self::assertStringContainsString("'row_count' => \$rowCount", $model);
        self::assertStringContainsString("'receipt_digest' => \$receiptDigest", $model);
        self::assertStringContainsString("'frontend' => \$frontend", $provider);
        self::assertStringContainsString("], 'write', 1, false)", $provider);
        self::assertStringContainsString("'external' => false", $provider);
        self::assertStringContainsString('private readonly SeoWebsiteStats $statsModel', $provider);
        self::assertStringContainsString('ObjectManager::getInstance(SeoWebsiteStats::class)', $sync);
        self::assertStringNotContainsString('INSERT INTO', $model);
        self::assertStringNotContainsString('DELETE FROM', $model);
    }

    public function testAcceptanceFixtureContractKeepsWebsiteZeroAndExactCleanupSemantics(): void
    {
        $root = \dirname(__DIR__, 3);
        $model = (string)\file_get_contents($root . '/Model/SeoWebsiteStats.php');

        self::assertStringContainsString('website_id must be a non-negative integer.', $model);
        self::assertStringContainsString("'acceptance_payload_hash' => \$payloadHash", $model);
        self::assertStringContainsString("'acceptance_row_index' => \$index", $model);
        self::assertStringContainsString("'deleted_rows' => \$deleted", $model);
        self::assertStringContainsString('if ($item === null)', $model);
        self::assertStringContainsString('$itemsToDelete[] = $item;', $model);
        self::assertStringContainsString('$item->delete();', $model);
        self::assertStringContainsString('hash_equals($expectedReceiptDigest, $receiptDigest)', $model);
        self::assertStringContainsString("(int)\$item->getId() !== \$receiptRow['id']", $model);
        self::assertStringContainsString("(\$meta['date'] ?? '') !== \$receiptRow['date']", $model);
        self::assertStringContainsString('clicks must not exceed impressions.', $model);
        self::assertStringContainsString('duplicate dated query/page row.', $model);
    }

    public function testCurrentAndPreviousSearchWindowFixtureMathIsExact(): void
    {
        $aggregate = static function (array $rows): array {
            $impressions = array_sum(array_column($rows, 'impressions'));
            $clicks = array_sum(array_column($rows, 'clicks'));
            $weightedPosition = 0.0;
            foreach ($rows as $row) {
                $weightedPosition += $row['average_position'] * $row['impressions'];
            }
            return [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0,
                'average_position' => $impressions > 0 ? $weightedPosition / $impressions : 0.0,
                'indexed_pages' => max(array_column($rows, 'indexed_pages')),
                'error_count' => array_sum(array_column($rows, 'error_count')),
            ];
        };

        $previous = $aggregate([
            ['impressions' => 600, 'clicks' => 120, 'average_position' => 12.0, 'indexed_pages' => 8, 'error_count' => 2],
            ['impressions' => 600, 'clicks' => 120, 'average_position' => 8.0, 'indexed_pages' => 8, 'error_count' => 1],
        ]);
        $current = $aggregate([
            ['impressions' => 600, 'clicks' => 144, 'average_position' => 9.0, 'indexed_pages' => 8, 'error_count' => 1],
            ['impressions' => 600, 'clicks' => 144, 'average_position' => 7.0, 'indexed_pages' => 8, 'error_count' => 0],
        ]);

        self::assertSame(1200, $previous['impressions']);
        self::assertSame(240, $previous['clicks']);
        self::assertEqualsWithDelta(0.20, $previous['ctr'], 0.000001);
        self::assertEqualsWithDelta(10.0, $previous['average_position'], 0.000001);
        self::assertSame(8, $previous['indexed_pages']);
        self::assertSame(3, $previous['error_count']);
        self::assertSame(1200, $current['impressions']);
        self::assertSame(288, $current['clicks']);
        self::assertEqualsWithDelta(0.24, $current['ctr'], 0.000001);
        self::assertEqualsWithDelta(8.0, $current['average_position'], 0.000001);
        self::assertSame(8, $current['indexed_pages']);
        self::assertSame(1, $current['error_count']);

        $snapshot = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/SearchPerformanceSnapshotService.php');
        foreach (['impressions', 'clicks', 'ctr', 'average_position', 'indexed_pages', 'error_count', 'daily'] as $field) {
            self::assertStringContainsString("'{$field}' =>", $snapshot);
        }
        self::assertStringContainsString('->where(SeoWebsiteStats::schema_fields_STATS_DATE', $snapshot);
        self::assertStringContainsString("->order(SeoWebsiteStats::schema_fields_STATS_DATE, 'ASC')", $snapshot);
    }
}
