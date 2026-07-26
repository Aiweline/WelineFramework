<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Console\Pixel\HotRetention;
use Weline\Visitor\Model\PixelStatsJobLog;
use Weline\Visitor\Service\PixelHotRetentionService;

/**
 * G08：Retention 门禁纯逻辑（不查库；失败日不删）。
 */
final class PixelHotRetentionServiceTest extends TestCase
{
    private PixelHotRetentionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelHotRetentionService(null, 365);
    }

    public function testOnlyDailySuccessIsEligible(): void
    {
        self::assertTrue($this->service->isEligibleJobLog([
            'job_type' => PixelStatsJobLog::JOB_DAILY,
            'status' => PixelStatsJobLog::STATUS_SUCCESS,
        ]));
        self::assertFalse($this->service->isEligibleJobLog([
            'job_type' => PixelStatsJobLog::JOB_DAILY,
            'status' => PixelStatsJobLog::STATUS_FAILED,
        ]));
        self::assertFalse($this->service->isEligibleJobLog([
            'job_type' => PixelStatsJobLog::JOB_HOURLY,
            'status' => PixelStatsJobLog::STATUS_SUCCESS,
        ]));
        self::assertFalse($this->service->isEligibleJobLog([
            'job_type' => PixelStatsJobLog::JOB_DAILY,
            'status' => '',
        ]));
    }

    public function testPartitionSkipsFailedAndDaysAfterCutoff(): void
    {
        $cutoff = '2025-07-01 00:00:00';
        $part = $this->service->partitionJobLogs([
            [
                'job_type' => 'daily',
                'status' => 'success',
                'bucket' => '2025-06-01 00:00:00',
                'website_id' => 1,
                'tz' => 'UTC',
            ],
            [
                'job_type' => 'daily',
                'status' => 'failed',
                'bucket' => '2025-06-02 00:00:00',
                'website_id' => 1,
                'tz' => 'UTC',
            ],
            [
                'job_type' => 'daily',
                'status' => 'success',
                'bucket' => '2025-07-01 00:00:00', // day end 2025-07-02 > cutoff
                'website_id' => 1,
                'tz' => 'UTC',
            ],
        ], $cutoff);

        self::assertCount(1, $part['eligible']);
        self::assertSame('2025-06-01', $part['eligible'][0]['day_bucket']);
        self::assertCount(2, $part['skipped']);
        $reasons = array_column($part['skipped'], 'reason');
        self::assertContains('job_log_not_success', $reasons);
        self::assertContains('day_not_before_cutoff', $reasons);
    }

    public function testDayWindowUsesSiteTimezone(): void
    {
        $window = $this->service->dayWindowUtc('2025-06-01', 'Asia/Shanghai');
        self::assertSame('2025-06-01', $window['day_bucket']);
        self::assertSame('2025-05-31 16:00:00', $window['start_utc']);
        self::assertSame('2025-06-01 16:00:00', $window['end_utc']);
    }

    public function testDryRunNeverArchivesOrDeletes(): void
    {
        $deleted = 0;
        $archived = 0;
        $now = new DateTimeImmutable('2026-07-26 12:00:00', new DateTimeZone('UTC'));
        $report = $this->service->dryRun(
            ['now' => $now, 'hot_days' => 365, 'website_id' => 1, 'limit' => 100],
            static fn (): array => [
                [
                    'job_type' => 'daily',
                    'status' => 'success',
                    'bucket' => '2025-01-01 00:00:00',
                    'website_id' => 1,
                    'tz' => 'UTC',
                ],
                [
                    'job_type' => 'daily',
                    'status' => 'failed',
                    'bucket' => '2025-01-02 00:00:00',
                    'website_id' => 1,
                    'tz' => 'UTC',
                ],
            ],
            static function (array $day): array {
                TestCase::assertSame('2025-01-01', $day['day_bucket']);

                return [
                    ['pixel_id' => 101, 'website_id' => 1, 'created_at' => '2025-01-01 10:00:00', 'event' => 'page_view'],
                    ['pixel_id' => 102, 'website_id' => 1, 'created_at' => '2025-01-01 11:00:00', 'event' => 'purchase'],
                ];
            }
        );

        self::assertTrue($report['dry_run']);
        self::assertSame(1, $report['eligible_days']);
        self::assertSame(1, $report['skipped_days']);
        self::assertSame(2, $report['candidate_rows']);
        self::assertSame(2, $report['would_delete']);
        self::assertSame(0, $report['deleted']);
        self::assertSame(0, $report['archived']);
        self::assertSame(0, $deleted);
        self::assertSame(0, $archived);
        self::assertStringContainsString('no hot deletes', $report['message']);
    }

    public function testApplyArchivesThenDeletesOnlyEligibleDay(): void
    {
        $archiveCalls = [];
        $deleteCalls = [];
        $now = new DateTimeImmutable('2026-07-26 12:00:00', new DateTimeZone('UTC'));

        $report = $this->service->apply(
            ['now' => $now, 'hot_days' => 365, 'limit' => 50],
            static fn (): array => [
                [
                    'job_type' => 'daily',
                    'status' => 'success',
                    'bucket' => '2025-01-01 00:00:00',
                    'website_id' => 2,
                    'tz' => 'UTC',
                ],
                [
                    'job_type' => 'daily',
                    'status' => 'failed',
                    'bucket' => '2025-01-02 00:00:00',
                    'website_id' => 2,
                    'tz' => 'UTC',
                ],
            ],
            static function (array $day): array {
                if ($day['day_bucket'] !== '2025-01-01') {
                    TestCase::fail('failed day must not load hot rows');
                }

                return [
                    ['pixel_id' => 201, 'website_id' => 2, 'event' => 'page_view', 'created_at' => '2025-01-01 01:00:00'],
                ];
            },
            static function (array $rows, array $day) use (&$archiveCalls): array {
                $archiveCalls[] = ['day' => $day['day_bucket'], 'rows' => \count($rows)];

                return ['inserted' => \count($rows), 'already_archived' => 0];
            },
            static function (array $pixelIds, array $day) use (&$deleteCalls): int {
                $deleteCalls[] = ['day' => $day['day_bucket'], 'ids' => $pixelIds];

                return \count($pixelIds);
            }
        );

        self::assertFalse($report['dry_run']);
        self::assertSame(1, $report['eligible_days']);
        self::assertSame(1, $report['deleted']);
        self::assertSame(1, $report['archived']);
        self::assertCount(1, $archiveCalls);
        self::assertCount(1, $deleteCalls);
        self::assertSame('2025-01-01', $archiveCalls[0]['day']);
        self::assertSame([201], $deleteCalls[0]['ids']);
        // failed day must appear as skipped with zero deletes
        $failed = null;
        foreach ($report['days'] as $day) {
            if (($day['day_bucket'] ?? '') === '2025-01-02') {
                $failed = $day;
            }
        }
        self::assertNotNull($failed);
        self::assertSame('job_log_not_success', $failed['reason']);
        self::assertSame(0, $failed['deleted']);
    }

    public function testMissingJobLogMeansNoDeleteCandidate(): void
    {
        $now = new DateTimeImmutable('2026-07-26 12:00:00', new DateTimeZone('UTC'));
        $hotLoaderCalled = false;
        $report = $this->service->dryRun(
            ['now' => $now, 'hot_days' => 365],
            static fn (): array => [], // 无任何 job_log
            static function () use (&$hotLoaderCalled): array {
                $hotLoaderCalled = true;

                return [['pixel_id' => 1]];
            }
        );
        self::assertFalse($hotLoaderCalled);
        self::assertSame(0, $report['eligible_days']);
        self::assertSame(0, $report['would_delete']);
    }

    public function testConsoleRequiresDualFlagsForApply(): void
    {
        $cmd = new HotRetention();
        $help = $cmd->help();
        self::assertIsArray($help);
        self::assertSame('pixel:hot-retention', $help['command']);
        $notes = implode(' ', $help['notes']);
        self::assertStringContainsString('status=success', $notes);
        self::assertStringContainsString('Failed or missing', $notes);
        self::assertStringContainsString('job_log', strtolower($cmd->tip()));
    }
}
