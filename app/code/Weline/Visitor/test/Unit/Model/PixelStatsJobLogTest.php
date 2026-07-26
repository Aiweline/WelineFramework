<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Visitor\Model\PixelStatsJobLog;

/**
 * G03：聚合任务日志 schema 契约（纯反射，不查库）。
 */
final class PixelStatsJobLogTest extends TestCase
{
    public function testTableAndUniqueKeyOnJobTypeBucketWebsite(): void
    {
        self::assertSame('pixel_stats_job_log', PixelStatsJobLog::schema_table);
        self::assertSame('pixel_stats_job_log_id', PixelStatsJobLog::schema_primary_key);

        $reflection = new ReflectionClass(PixelStatsJobLog::class);
        self::assertNotEmpty($reflection->getAttributes(Table::class));

        $uniqueColumns = [];
        foreach ($reflection->getAttributes(Index::class) as $attr) {
            $args = $attr->getArguments();
            if (strtoupper((string)($args['type'] ?? '')) === 'UNIQUE') {
                $uniqueColumns = $args['columns'] ?? [];
            }
        }
        self::assertSame(['job_type', 'bucket', 'website_id'], $uniqueColumns);
    }

    public function testStatusAndJobTypeConstantsForRetentionGate(): void
    {
        self::assertSame(['hourly', 'daily'], PixelStatsJobLog::JOB_TYPES);
        self::assertContains(PixelStatsJobLog::STATUS_SUCCESS, PixelStatsJobLog::STATUSES);
        self::assertContains(PixelStatsJobLog::STATUS_FAILED, PixelStatsJobLog::STATUSES);
        self::assertSame('daily', PixelStatsJobLog::JOB_DAILY);
        self::assertSame('success', PixelStatsJobLog::STATUS_SUCCESS);
    }

    public function testSchemaHasCheckJsonAndGateFields(): void
    {
        $reflection = new ReflectionClass(PixelStatsJobLog::class);
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

        foreach (['job_type', 'bucket', 'website_id', 'tz', 'status', 'attempts', 'check_json', 'message'] as $field) {
            self::assertContains($field, $declared, "job_log 缺少 {$field}");
        }
    }
}
