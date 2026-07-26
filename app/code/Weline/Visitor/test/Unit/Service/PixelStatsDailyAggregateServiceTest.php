<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Cron\PixelStatsDaily as PixelStatsDailyCron;
use Weline\Visitor\Model\PixelStatsDaily;
use Weline\Visitor\Model\PixelStatsJobLog;
use Weline\Visitor\Service\PixelChannelFunnelService;
use Weline\Visitor\Service\PixelLandingDeviceDerivation;
use Weline\Visitor\Service\PixelStatsDailyAggregateService;
use Weline\Visitor\Service\PixelStatsHourlyAggregateService;

/**
 * G05：日聚合纯逻辑（不查库）。
 */
final class PixelStatsDailyAggregateServiceTest extends TestCase
{
    private PixelStatsDailyAggregateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelStatsDailyAggregateService(
            new PixelStatsHourlyAggregateService(new PixelLandingDeviceDerivation()),
            new PixelChannelFunnelService()
        );
    }

    public function testResolvePreviousDayBucketUsesSiteTimezone(): void
    {
        $now = new DateTimeImmutable('2026-07-26 10:12:00', new DateTimeZone('Asia/Shanghai'));
        $window = $this->service->resolvePreviousDayBucket($now, 'Asia/Shanghai');

        self::assertSame('2026-07-25', $window['day_bucket']);
        self::assertSame('Asia/Shanghai', $window['tz']);
        self::assertSame('2026-07-24 16:00:00', $window['start_utc']);
        self::assertSame('2026-07-25 16:00:00', $window['end_utc']);
    }

    public function testValidateEventsPassesWithinTolerance(): void
    {
        $okAbs = $this->service->validateEvents(100, 104);
        self::assertTrue($okAbs['ok']);
        self::assertSame(4, $okAbs['abs_diff']);

        $okRel = $this->service->validateEvents(1000, 1015);
        self::assertTrue($okRel['ok']);
        self::assertLessThanOrEqual(0.02, $okRel['rel_error']);

        $fail = $this->service->validateEvents(100, 120);
        self::assertFalse($fail['ok']);
        self::assertSame(20, $fail['abs_diff']);
    }

    public function testAggregateFromRowsComputesDailyMetricsAndFunnel(): void
    {
        $rows = [
            [
                'session_id' => 'bounce',
                'event' => 'page_view',
                'traffic_type' => 'direct',
                'device_category' => 'desktop',
                'created_at' => '2026-07-25 01:00:00',
            ],
            [
                'session_id' => 'engaged',
                'event' => 'page_view',
                'traffic_type' => 'paid',
                'channel_code' => 'summer',
                'device_category' => 'mobile',
                'created_at' => '2026-07-25 02:00:00',
            ],
            [
                'session_id' => 'engaged',
                'event' => 'add_to_cart',
                'traffic_type' => 'paid',
                'channel_code' => 'summer',
                'device_category' => 'mobile',
                'value' => 10,
                'created_at' => '2026-07-25 02:05:00',
            ],
            [
                'session_id' => 'engaged',
                'event' => 'purchase',
                'traffic_type' => 'paid',
                'channel_code' => 'summer',
                'device_category' => 'mobile',
                'value' => 99,
                'created_at' => '2026-07-25 02:10:00',
            ],
        ];

        $out = $this->service->aggregateFromRows(
            $rows,
            1,
            '2026-07-25',
            'UTC',
            [$rows[0], $rows[1]]
        );

        self::assertSame(4, $out['events_total']);
        self::assertGreaterThan(0, $out['funnel']['step1_sessions']);

        $byKey = [];
        foreach ($out['rows'] as $row) {
            $byKey[$row['event_name'] . '|' . $row['traffic_type']] = $row;
            self::assertSame(
                PixelStatsDaily::dimHash([
                    'traffic_type' => $row['traffic_type'],
                    'channel_code' => $row['channel_code'],
                    'utm_source' => $row['utm_source'],
                    'utm_medium' => $row['utm_medium'],
                    'utm_campaign' => $row['utm_campaign'],
                    'event_name' => $row['event_name'],
                    'device_category' => $row['device_category'],
                ]),
                $row['dim_hash']
            );
            self::assertSame('2026-07-25', $row['day_bucket']);
            self::assertNotSame('', $row['funnel_json']);
        }

        self::assertSame(1, $byKey['page_view|direct']['sessions']);
        self::assertSame(1, $byKey['page_view|direct']['bounce_sessions']);
        self::assertSame(0, $byKey['page_view|direct']['engaged_sessions']);
        self::assertSame(1, $byKey['page_view|paid']['sessions']);
        self::assertSame(1, $byKey['page_view|paid']['engaged_sessions']);
        self::assertSame(0, $byKey['page_view|paid']['bounce_sessions']);
        self::assertSame(1, $byKey['add_to_cart|paid']['add_to_carts']);
        self::assertSame(1, $byKey['purchase|paid']['purchases']);
        self::assertSame(1, $byKey['purchase|paid']['conversions']);
        // 首事件分别是 bounce 的 page_view 与 engaged 的 page_view
        self::assertSame(1, $byKey['page_view|direct']['session_starts']);
        self::assertSame(1, $byKey['page_view|paid']['session_starts']);
        self::assertSame(0, $byKey['add_to_cart|paid']['session_starts']);
        self::assertSame(0, $byKey['purchase|paid']['session_starts']);
    }

    public function testRunForWebsiteMarksFailedWhenEventsCheckFails(): void
    {
        $jobLogs = [];
        $rows = [
            [
                'session_id' => 'a',
                'event' => 'page_view',
                'device_category' => 'mobile',
                'created_at' => '2026-07-25 01:00:00',
            ],
        ];

        $result = $this->service->runForWebsite(
            1,
            '2026-07-25',
            'UTC',
            static fn (): array => $rows,
            static fn (): array => [$rows[0]],
            static fn (): int => 50, // 热表多很多 → 校验失败
            static fn (int $websiteId, string $day, array $agg): int => \count($agg),
            static function (array $payload) use (&$jobLogs): void {
                $jobLogs[] = $payload;
            }
        );

        self::assertSame(PixelStatsJobLog::STATUS_FAILED, $result['status']);
        self::assertFalse($result['check']['ok']);
        self::assertSame(PixelStatsJobLog::JOB_DAILY, $jobLogs[array_key_last($jobLogs)]['job_type']);
        self::assertSame(PixelStatsJobLog::STATUS_FAILED, $jobLogs[array_key_last($jobLogs)]['status']);
        self::assertStringContainsString('events check failed', (string)$jobLogs[array_key_last($jobLogs)]['message']);
        $check = json_decode((string)$jobLogs[array_key_last($jobLogs)]['check_json'], true);
        self::assertIsArray($check);
        self::assertSame(50, $check['expected']);
        self::assertSame(1, $check['actual']);
        self::assertFalse($check['ok']);
    }

    public function testRunForWebsiteSuccessWhenCheckPasses(): void
    {
        $jobLogs = [];
        $rows = [
            [
                'session_id' => 'a',
                'event' => 'page_view',
                'device_category' => 'mobile',
                'created_at' => '2026-07-25 01:00:00',
            ],
            [
                'session_id' => 'a',
                'event' => 'cta_click',
                'device_category' => 'mobile',
                'created_at' => '2026-07-25 01:01:00',
            ],
        ];

        $result = $this->service->runForWebsite(
            0,
            '2026-07-25',
            'UTC',
            static fn (): array => $rows,
            static fn (): array => [$rows[0]],
            static fn (): int => 2,
            static fn (int $websiteId, string $day, array $agg): int => \count($agg),
            static function (array $payload) use (&$jobLogs): void {
                $jobLogs[] = $payload;
            }
        );

        self::assertSame(PixelStatsJobLog::STATUS_SUCCESS, $result['status']);
        self::assertTrue($result['check']['ok']);
        self::assertSame(2, $result['events']);
        self::assertSame('2026-07-25 00:00:00', $jobLogs[array_key_last($jobLogs)]['bucket']);
    }

    public function testCronContract(): void
    {
        $cron = new PixelStatsDailyCron();
        self::assertSame('pixel_stats_daily', $cron->execute_name());
        self::assertSame('15 1 * * *', $cron->cron_time());
        self::assertGreaterThanOrEqual(60, $cron->unlock_timeout());
        self::assertStringContainsString('pixel_stats_daily', $cron->tip());
        self::assertStringContainsString('job_log', $cron->tip());
    }
}
