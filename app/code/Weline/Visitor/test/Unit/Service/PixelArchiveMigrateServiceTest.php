<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Console\Pixel\ArchiveMigrate;
use Weline\Visitor\Model\PixelArchive;
use Weline\Visitor\Service\PixelArchiveMigrateService;

/**
 * G07：冷归档迁移纯逻辑（不查库；永不删热）。
 */
final class PixelArchiveMigrateServiceTest extends TestCase
{
    private PixelArchiveMigrateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelArchiveMigrateService();
    }

    public function testNormalizeOptionsDefaultsBeforeAndClampsLimit(): void
    {
        $opts = $this->service->normalizeOptions([
            'website_id' => 3,
            'limit' => 99999,
            'before' => '2025-01-15',
        ]);
        self::assertSame(3, $opts['website_id']);
        self::assertSame(PixelArchiveMigrateService::MAX_LIMIT, $opts['limit']);
        self::assertSame('2025-01-15 23:59:59', $opts['before']);
        self::assertNull($opts['after']);
    }

    public function testMapHotRowToArchivePreservesPixelIdAndSetsArchivedAt(): void
    {
        $mapped = $this->service->mapHotRowToArchive([
            'pixel_id' => 42,
            'event' => 'page_view',
            'website_id' => 1,
            'value' => 12.5,
            'channel_code' => 'summer',
            'created_at' => '2024-01-01 00:00:00',
        ], '2026-07-26 10:00:00');

        self::assertSame(42, $mapped['pixel_id']);
        self::assertSame('page_view', $mapped['event']);
        self::assertSame('summer', $mapped['channel_code']);
        self::assertEqualsWithDelta(12.5, $mapped['value'], 0.0001);
        self::assertSame('2026-07-26 10:00:00', $mapped['archived_at']);
        self::assertContains('pixel_id', PixelArchive::HOT_MIRROR_FIELDS);
        self::assertNotContains('archived_at', PixelArchive::HOT_MIRROR_FIELDS);
    }

    public function testPlanInsertsSkipsAlreadyArchivedAndInvalid(): void
    {
        $plan = $this->service->planInserts(
            [
                ['pixel_id' => 1, 'event' => 'page_view', 'website_id' => 0],
                ['pixel_id' => 2, 'event' => 'purchase', 'website_id' => 0, 'value' => 9],
                ['pixel_id' => 0, 'event' => 'x'],
                ['pixel_id' => 2, 'event' => 'dup'], // already in existing after first? no - existing has 1
            ],
            [1],
            '2026-07-26 11:00:00'
        );

        self::assertSame(1, $plan['already_archived']);
        self::assertSame(2, $plan['skipped']); // pixel_id=0 + duplicate pixel_id=2
        self::assertCount(1, $plan['to_insert']);
        self::assertSame([2], $plan['sample_pixel_ids']);
    }

    public function testDryRunNeverWritesAndNeverDeletesHot(): void
    {
        $inserted = 0;
        $report = $this->service->dryRun(
            ['before' => '2025-01-01', 'limit' => 10],
            static fn (): array => [
                ['pixel_id' => 10, 'event' => 'page_view', 'website_id' => 1, 'created_at' => '2024-01-01 00:00:00'],
            ],
            static fn (): array => []
        );

        self::assertTrue($report['dry_run']);
        self::assertFalse($report['deletes_hot']);
        self::assertSame(1, $report['candidates']);
        self::assertSame(1, $report['would_insert']);
        self::assertSame(0, $report['inserted']);
        self::assertSame(0, $inserted);
        self::assertStringContainsString('NOT deleted', $report['message']);
    }

    public function testMigrateInsertsViaInserterWithoutDeletingHot(): void
    {
        $batches = [];
        $report = $this->service->migrate(
            ['before' => '2025-01-01', 'website_id' => 1, 'limit' => 10],
            static fn (): array => [
                ['pixel_id' => 10, 'event' => 'page_view', 'website_id' => 1, 'created_at' => '2024-01-01 00:00:00'],
                ['pixel_id' => 11, 'event' => 'purchase', 'website_id' => 1, 'value' => 3, 'created_at' => '2024-01-02 00:00:00'],
            ],
            static fn (): array => [11],
            static function (array $rows) use (&$batches): int {
                $batches[] = $rows;

                return \count($rows);
            }
        );

        self::assertFalse($report['dry_run']);
        self::assertFalse($report['deletes_hot']);
        self::assertSame(1, $report['already_archived']);
        self::assertSame(1, $report['would_insert']);
        self::assertSame(1, $report['inserted']);
        self::assertCount(1, $batches);
        self::assertSame(10, $batches[0][0]['pixel_id']);
        self::assertStringContainsString('NOT deleted', $report['message']);
    }

    public function testBuildCandidateSqlRequiresBeforeBound(): void
    {
        [$sql, $params] = $this->service->buildCandidateSql(2, '2025-01-01 00:00:00', '2024-01-01 00:00:00', 100, 5);
        self::assertStringContainsString('< :before', $sql);
        self::assertStringContainsString('>= :after', $sql);
        self::assertStringContainsString('website_id', $sql);
        self::assertStringContainsString('LIMIT 100 OFFSET 5', $sql);
        self::assertSame('2025-01-01 00:00:00', $params['before']);
        self::assertSame(2, $params['website_id']);
    }

    public function testConsoleHelpContractMentionsNoDelete(): void
    {
        $cmd = new ArchiveMigrate();
        $help = $cmd->help();
        self::assertIsArray($help);
        self::assertSame('pixel:archive-migrate', $help['command']);
        $notes = implode(' ', $help['notes']);
        self::assertStringContainsString('NEVER deletes', $notes);
        self::assertStringContainsString('G08', $notes);
        self::assertStringContainsString('archive', strtolower($cmd->tip()));
    }
}
