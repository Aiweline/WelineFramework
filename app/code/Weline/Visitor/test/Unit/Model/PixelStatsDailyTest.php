<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Visitor\Model\PixelStatsDaily;
use Weline\Visitor\Model\PixelStatsHourly;

/**
 * G02：日聚合温表 schema 契约（纯反射，不查库）。
 */
final class PixelStatsDailyTest extends TestCase
{
    public function testTableAndUniqueKey(): void
    {
        self::assertSame('pixel_stats_daily', PixelStatsDaily::schema_table);
        self::assertSame('pixel_stats_daily_id', PixelStatsDaily::schema_primary_key);

        $reflection = new ReflectionClass(PixelStatsDaily::class);
        self::assertNotEmpty($reflection->getAttributes(Table::class));

        $uniqueColumns = [];
        foreach ($reflection->getAttributes(Index::class) as $attr) {
            $args = $attr->getArguments();
            if (strtoupper((string)($args['type'] ?? '')) === 'UNIQUE') {
                $uniqueColumns = $args['columns'] ?? [];
            }
        }
        self::assertSame(['day_bucket', 'website_id', 'dim_hash'], $uniqueColumns);
    }

    public function testSharesDimFieldsWithHourlyAndHasAuthoritySessionMetrics(): void
    {
        self::assertSame(PixelStatsHourly::DIM_FIELDS, PixelStatsDaily::DIM_FIELDS);

        $reflection = new ReflectionClass(PixelStatsDaily::class);
        $declared = [];
        foreach ($reflection->getReflectionConstants() as $const) {
            if (!str_starts_with($const->getName(), 'schema_fields_')) {
                continue;
            }
            if ($const->getAttributes(Col::class) === []) {
                continue;
            }
            $declared[] = (string)$const->getValue();
        }

        foreach (['sessions', 'engaged_sessions', 'bounce_sessions', 'conversions', 'funnel_json'] as $field) {
            self::assertContains($field, $declared, "日表缺少权威列 {$field}");
        }
        self::assertNotContains('page_path', $declared);
    }

    public function testDimHashDelegatesToHourlyContract(): void
    {
        $dims = ['traffic_type' => 'Paid', 'channel_code' => ' X '];
        self::assertSame(
            PixelStatsHourly::dimHash($dims),
            PixelStatsDaily::dimHash($dims)
        );
    }
}
