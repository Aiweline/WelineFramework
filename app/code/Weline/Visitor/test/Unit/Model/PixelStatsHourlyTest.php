<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Visitor\Model\PixelStatsHourly;

/**
 * G01：小时聚合温表 schema 契约与 dim_hash 幂等（纯反射，不查库）。
 */
final class PixelStatsHourlyTest extends TestCase
{
    public function testTableNameAndUniqueKeyOnBucketWebsiteDimHash(): void
    {
        self::assertSame('pixel_stats_hourly', PixelStatsHourly::schema_table);
        self::assertSame('pixel_stats_hourly_id', PixelStatsHourly::schema_primary_key);

        $reflection = new ReflectionClass(PixelStatsHourly::class);

        $tableAttrs = $reflection->getAttributes(Table::class);
        self::assertNotEmpty($tableAttrs, 'Model 必须声明 #[Table] 以便框架建表');

        $uniqueColumns = [];
        foreach ($reflection->getAttributes(Index::class) as $attr) {
            $index = $attr->newInstance();
            if (strtoupper((string)($index->type ?? '')) === 'UNIQUE') {
                $uniqueColumns = $index->columns;
            }
        }
        self::assertSame(['hour_bucket', 'website_id', 'dim_hash'], $uniqueColumns);
    }

    public function testSchemaHasHourlyMetricsAndDims(): void
    {
        $expected = [
            'hour_bucket', 'website_id', 'dim_hash', 'tz',
            'traffic_type', 'channel_code', 'utm_source', 'utm_medium', 'utm_campaign',
            'event_name', 'device_category',
            'events', 'value_sum', 'valued_events', 'session_starts', 'purchases', 'add_to_carts',
        ];
        $reflection = new ReflectionClass(PixelStatsHourly::class);
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

        foreach ($expected as $field) {
            self::assertContains($field, $declared, "缺少列 {$field}");
        }
    }

    public function testDoesNotStoreHighCardinalityPagePath(): void
    {
        // §3.3：禁止把高基 page_path 打进默认全维
        $reflection = new ReflectionClass(PixelStatsHourly::class);
        $declared = [];
        foreach ($reflection->getReflectionConstants() as $const) {
            if (str_starts_with($const->getName(), 'schema_fields_')) {
                $declared[] = (string)$const->getValue();
            }
        }
        self::assertNotContains('page_path', $declared);
        self::assertNotContains('url', $declared);
    }

    public function testDimFieldsOrderIsFrozen(): void
    {
        // dim_hash 依赖此顺序，改动即破坏历史幂等，必须显式锁定
        self::assertSame(
            ['traffic_type', 'channel_code', 'utm_source', 'utm_medium', 'utm_campaign', 'event_name', 'device_category'],
            PixelStatsHourly::DIM_FIELDS
        );
    }

    public function testDimHashIsCaseInsensitiveAndTrimAndOrderStable(): void
    {
        $a = PixelStatsHourly::dimHash([
            'traffic_type' => 'Paid',
            'channel_code' => ' Summer_Sale ',
            'utm_source' => 'GOOGLE',
            'event_name' => 'Purchase',
        ]);
        $b = PixelStatsHourly::dimHash([
            'channel_code' => 'summer_sale',
            'traffic_type' => 'paid',
            'utm_source' => 'google',
            'event_name' => 'purchase',
        ]);

        self::assertSame($a, $b, '大小写/空白/键序不影响 hash');
        self::assertSame(40, strlen($a), 'sha1 定长 40');
    }

    public function testDimHashSeparatesDifferentDimsAndAvoidsCollision(): void
    {
        $ab = PixelStatsHourly::dimHash(['traffic_type' => 'ab', 'channel_code' => 'c']);
        $aBc = PixelStatsHourly::dimHash(['traffic_type' => 'a', 'channel_code' => 'bc']);
        self::assertNotSame($ab, $aBc, '维分隔符须防止 a|bc 与 ab|c 撞车');

        $empty = PixelStatsHourly::dimHash([]);
        self::assertSame($empty, PixelStatsHourly::dimHash(['unknown_dim' => 'x']), '白名单外维不参与 hash');
        self::assertNotSame($empty, PixelStatsHourly::dimHash(['traffic_type' => 'paid']));
    }

    public function testNormalizeDimsKeepsWhitelistOnly(): void
    {
        $normalized = PixelStatsHourly::normalizeDims([
            'traffic_type' => ' Paid ',
            'page_path' => '/high/cardinality',
            'utm_source' => 'Google',
        ]);

        self::assertSame('paid', $normalized['traffic_type']);
        self::assertSame('google', $normalized['utm_source']);
        self::assertSame('', $normalized['channel_code']);
        self::assertArrayNotHasKey('page_path', $normalized);
        self::assertSame(PixelStatsHourly::DIM_FIELDS, array_keys($normalized));
    }
}
