<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Cron\PixelStatsHourly as PixelStatsHourlyCron;
use Weline\Visitor\Model\PixelStatsHourly;
use Weline\Visitor\Model\PixelStatsJobLog;
use Weline\Visitor\Service\PixelLandingDeviceDerivation;
use Weline\Visitor\Service\PixelStatsHourlyAggregateService;

/**
 * G04：小时聚合纯逻辑（不查库）。
 */
final class PixelStatsHourlyAggregateServiceTest extends TestCase
{
    private PixelStatsHourlyAggregateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelStatsHourlyAggregateService(new PixelLandingDeviceDerivation());
    }

    public function testNormalizeTimezoneFallsBackToUtc(): void
    {
        self::assertSame('UTC', $this->service->normalizeTimezone(''));
        self::assertSame('UTC', $this->service->normalizeTimezone('Not/AZone'));
        self::assertSame('Asia/Shanghai', $this->service->normalizeTimezone('Asia/Shanghai'));
    }

    public function testResolvePreviousHourBucketUsesSiteTimezone(): void
    {
        $now = new DateTimeImmutable('2026-07-26 10:12:00', new DateTimeZone('Asia/Shanghai'));
        $window = $this->service->resolvePreviousHourBucket($now, 'Asia/Shanghai');

        self::assertSame('2026-07-26 09:00:00', $window['hour_bucket']);
        self::assertSame('Asia/Shanghai', $window['tz']);
        // 上海 09:00 = UTC 01:00
        self::assertSame('2026-07-26 01:00:00', $window['start_utc']);
        self::assertSame('2026-07-26 02:00:00', $window['end_utc']);
    }

    public function testAggregateFromRowsGroupsByDimHashAndCountsMetrics(): void
    {
        $rows = [
            [
                'session_id' => 's1',
                'event' => 'page_view',
                'value' => 0,
                'traffic_type' => 'paid',
                'channel_code' => 'summer',
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'summer',
                'device_category' => 'mobile',
                'created_at' => '2026-07-26 01:05:00',
            ],
            [
                'session_id' => 's1',
                'event' => 'add_to_cart',
                'value' => 12.5,
                'traffic_type' => 'paid',
                'channel_code' => 'summer',
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'summer',
                'device_category' => 'mobile',
                'created_at' => '2026-07-26 01:10:00',
            ],
            [
                'session_id' => 's2',
                'event' => 'purchase',
                'value' => 99,
                'traffic_type' => 'organic',
                'channel_code' => '',
                'utm_source' => '',
                'utm_medium' => '',
                'utm_campaign' => '',
                'device_category' => 'desktop',
                'created_at' => '2026-07-26 01:20:00',
            ],
            [
                'session_id' => 's2',
                'event' => 'checkout_success',
                'value' => 1,
                'traffic_type' => 'organic',
                'channel_code' => '',
                'utm_source' => '',
                'utm_medium' => '',
                'utm_campaign' => '',
                'device_category' => 'desktop',
                'created_at' => '2026-07-26 01:21:00',
            ],
        ];

        $sessionFirst = [
            $rows[0], // s1 starts this hour
            $rows[2], // s2 starts this hour
        ];

        $out = $this->service->aggregateFromRows(
            $rows,
            3,
            '2026-07-26 09:00:00',
            'Asia/Shanghai',
            $sessionFirst
        );

        self::assertCount(4, $out);
        $byEvent = [];
        foreach ($out as $row) {
            $byEvent[$row['event_name']] = $row;
            self::assertSame(
                PixelStatsHourly::dimHash([
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
            self::assertSame(3, $row['website_id']);
            self::assertSame('2026-07-26 09:00:00', $row['hour_bucket']);
            self::assertSame('Asia/Shanghai', $row['tz']);
        }

        self::assertSame(1, $byEvent['page_view']['events']);
        self::assertSame(1, $byEvent['page_view']['session_starts']);
        self::assertSame(0, $byEvent['page_view']['purchases']);

        self::assertSame(1, $byEvent['add_to_cart']['events']);
        self::assertSame(1, $byEvent['add_to_cart']['add_to_carts']);
        self::assertSame(1, $byEvent['add_to_cart']['valued_events']);
        self::assertEqualsWithDelta(12.5, $byEvent['add_to_cart']['value_sum'], 0.0001);
        self::assertSame(0, $byEvent['add_to_cart']['session_starts']);

        self::assertSame(1, $byEvent['purchase']['purchases']);
        self::assertSame(1, $byEvent['purchase']['session_starts']);
        self::assertSame(1, $byEvent['checkout_success']['purchases']);
        self::assertSame(0, $byEvent['checkout_success']['session_starts']);
    }

    public function testSessionStartsAttributedOnlyToFirstEventDims(): void
    {
        $hourRows = [
            [
                'session_id' => 'keep',
                'event' => 'view_item',
                'traffic_type' => 'direct',
                'device_category' => 'tablet',
                'created_at' => '2026-07-26 01:30:00',
            ],
            [
                'session_id' => 'keep',
                'event' => 'purchase',
                'traffic_type' => 'direct',
                'device_category' => 'tablet',
                'value' => 10,
                'created_at' => '2026-07-26 01:40:00',
            ],
        ];
        // 全局首事件在上一小时 → 本小时不计 session_starts
        $sessionFirst = [];

        $out = $this->service->aggregateFromRows(
            $hourRows,
            0,
            '2026-07-26 01:00:00',
            'UTC',
            $sessionFirst
        );

        $starts = 0;
        $events = 0;
        $purchases = 0;
        foreach ($out as $row) {
            $starts += (int)$row['session_starts'];
            $events += (int)$row['events'];
            $purchases += (int)$row['purchases'];
        }
        self::assertSame(0, $starts);
        self::assertSame(2, $events);
        self::assertSame(1, $purchases);
    }

    public function testRunForWebsiteWritesSuccessJobLogAndIsIdempotentOnRerun(): void
    {
        $jobLogs = [];
        $writes = [];
        $hourRows = [
            [
                'session_id' => 'a',
                'event' => 'page_view',
                'traffic_type' => 'paid',
                'channel_code' => 'x',
                'device_category' => 'mobile',
                'created_at' => '2026-07-26 01:01:00',
            ],
        ];

        $runner = function () use (&$jobLogs, &$writes, $hourRows) {
            return $this->service->runForWebsite(
                1,
                '2026-07-26 09:00:00',
                'Asia/Shanghai',
                static fn (): array => $hourRows,
                static fn (): array => [$hourRows[0]],
                static function (int $websiteId, string $bucket, array $rows) use (&$writes): int {
                    $writes[] = ['website_id' => $websiteId, 'bucket' => $bucket, 'rows' => $rows];

                    return \count($rows);
                },
                static function (array $payload) use (&$jobLogs): void {
                    $jobLogs[] = $payload;
                }
            );
        };

        $first = $runner();
        $second = $runner();

        self::assertSame(PixelStatsJobLog::STATUS_SUCCESS, $first['status']);
        self::assertSame(PixelStatsJobLog::STATUS_SUCCESS, $second['status']);
        self::assertSame(1, $first['rows']);
        self::assertSame(1, $second['rows']);
        self::assertCount(2, $writes);
        self::assertSame($writes[0]['rows'][0]['dim_hash'], $writes[1]['rows'][0]['dim_hash']);

        // running + success，各跑两次
        self::assertCount(4, $jobLogs);
        self::assertSame(PixelStatsJobLog::STATUS_RUNNING, $jobLogs[0]['status']);
        self::assertSame(PixelStatsJobLog::STATUS_SUCCESS, $jobLogs[1]['status']);
        self::assertSame(PixelStatsJobLog::JOB_HOURLY, $jobLogs[1]['job_type']);
        self::assertSame('Asia/Shanghai', $jobLogs[1]['tz']);
        self::assertSame('2026-07-26 09:00:00', $jobLogs[1]['bucket']);
    }

    public function testRunForWebsiteMarksFailedOnWriterException(): void
    {
        $jobLogs = [];
        $result = $this->service->runForWebsite(
            2,
            '2026-07-26 01:00:00',
            'UTC',
            static fn (): array => [['session_id' => 'a', 'event' => 'page_view', 'created_at' => '2026-07-26 01:00:01']],
            static fn (): array => [],
            static function (): int {
                throw new \RuntimeException('disk full');
            },
            static function (array $payload) use (&$jobLogs): void {
                $jobLogs[] = $payload;
            }
        );

        self::assertSame(PixelStatsJobLog::STATUS_FAILED, $result['status']);
        self::assertSame('disk full', $result['message']);
        self::assertSame(PixelStatsJobLog::STATUS_FAILED, $jobLogs[array_key_last($jobLogs)]['status']);
    }

    public function testListWebsiteTargetsAlwaysIncludesWebsiteZero(): void
    {
        $targets = $this->service->normalizeWebsiteTargets([
            ['website_id' => 5, 'tz' => 'Asia/Shanghai'],
            ['website_id' => 5, 'tz' => 'UTC'],
        ]);
        self::assertSame([
            ['website_id' => 0, 'tz' => 'UTC'],
            ['website_id' => 5, 'tz' => 'UTC'],
        ], $targets);
    }

    public function testCronContract(): void
    {
        $cron = new PixelStatsHourlyCron();
        self::assertSame('pixel_stats_hourly', $cron->execute_name());
        self::assertSame('5 * * * *', $cron->cron_time());
        self::assertGreaterThanOrEqual(30, $cron->unlock_timeout());
        self::assertStringContainsString('pixel_stats_hourly', $cron->tip());
        self::assertStringContainsString('job_log', $cron->tip());
    }

    public function testDeviceCategoryDerivedFromUaWhenMissing(): void
    {
        $dims = $this->service->dimsFromRow([
            'event' => 'page_view',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
            'browser_info' => '',
        ]);
        self::assertSame('mobile', $dims['device_category']);
        self::assertSame('page_view', $dims['event_name']);
    }
}
