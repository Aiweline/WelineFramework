<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service\Report;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\Report\PixelMetricRegistry;

/**
 * D02：MetricRegistry 单测（不查库）。
 */
final class PixelMetricRegistryTest extends TestCase
{
    public function testHourlyMetricsMatchPlanSection25(): void
    {
        $reg = new PixelMetricRegistry();

        self::assertSame(PixelMetricRegistry::HOURLY_METRIC_IDS, $reg->hourlyIds());
        self::assertSame([
            'events',
            'value_sum',
            'valued_events',
            'session_starts',
            'purchases',
            'add_to_carts',
        ], $reg->hourlyIds());

        foreach ($reg->hourlyIds() as $id) {
            $meta = $reg->get($id);
            self::assertNotNull($meta);
            self::assertTrue($meta['on_hourly']);
            self::assertTrue($meta['on_daily']);
            self::assertTrue($reg->supportsGrain($id, PixelMetricRegistry::GRAIN_HOURLY));
            self::assertTrue($meta['summable_across_hours']);
        }
    }

    public function testDailyExtraMetricsNotOnHourly(): void
    {
        $reg = new PixelMetricRegistry();

        self::assertSame(PixelMetricRegistry::DAILY_EXTRA_METRIC_IDS, $reg->dailyExtraIds());
        foreach (['sessions', 'engaged_sessions', 'bounce_sessions', 'conversions', 'funnel_json'] as $id) {
            self::assertTrue($reg->has($id));
            self::assertFalse($reg->get($id)['on_hourly']);
            self::assertTrue($reg->get($id)['on_daily']);
            self::assertFalse($reg->supportsGrain($id, PixelMetricRegistry::GRAIN_HOURLY));
            self::assertTrue($reg->supportsGrain($id, PixelMetricRegistry::GRAIN_DAILY));
            self::assertNotContains($id, $reg->hourlyIds());
        }

        self::assertContains('sessions', $reg->dailyIds());
        self::assertContains('events', $reg->dailyIds());
        self::assertSame(
            array_merge(
                PixelMetricRegistry::HOURLY_METRIC_IDS,
                PixelMetricRegistry::DAILY_EXTRA_METRIC_IDS
            ),
            $reg->dailyIds()
        );
    }

    public function testSessionsNotSummableAcrossHours(): void
    {
        $reg = new PixelMetricRegistry();

        self::assertFalse($reg->get('sessions')['summable_across_hours']);
        self::assertFalse($reg->get('funnel_json')['summable_across_hours']);

        $reg->assertSummableAcrossHours(['events', 'value_sum', 'session_starts']);

        $this->expectException(\InvalidArgumentException::class);
        $reg->assertSummableAcrossHours(['sessions']);
    }

    public function testRegisterAndAssertKnown(): void
    {
        $reg = new PixelMetricRegistry(false);
        $reg->register('custom_metric', [
            'label' => 'Custom',
            'aggregation' => PixelMetricRegistry::AGG_SUM,
            'on_hourly' => true,
            'on_daily' => true,
        ]);
        self::assertTrue($reg->has('custom_metric'));
        $reg->assertKnown(['custom_metric']);

        $this->expectException(\InvalidArgumentException::class);
        $reg->assertKnown(['no_such_metric']);
    }

    public function testRejectsInvalidAggregation(): void
    {
        $reg = new PixelMetricRegistry(false);
        $this->expectException(\InvalidArgumentException::class);
        $reg->register('bad', ['aggregation' => 'avg']);
    }

    public function testRejectsMetricWithNoGrain(): void
    {
        $reg = new PixelMetricRegistry(false);
        $this->expectException(\InvalidArgumentException::class);
        $reg->register('orphan', [
            'on_hourly' => false,
            'on_daily' => false,
        ]);
    }

    public function testFunnelJsonAggregationIsJson(): void
    {
        $reg = new PixelMetricRegistry();
        self::assertSame(PixelMetricRegistry::AGG_JSON, $reg->get('funnel_json')['aggregation']);
        self::assertSame('json', $reg->get('funnel_json')['value_type']);
        self::assertSame(PixelMetricRegistry::AGG_DISTINCT, $reg->get('sessions')['aggregation']);
    }
}
