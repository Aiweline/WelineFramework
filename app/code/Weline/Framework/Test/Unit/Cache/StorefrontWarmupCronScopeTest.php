<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cron\StorefrontWarmup;

/** cron 汇总串必须带 scope_count 与逐站合计，且不虚报。 */
final class StorefrontWarmupCronScopeTest extends TestCase
{
    public function testFormatIncludesScopeCountAndSums(): void
    {
        $report = [
            'scope_count' => 3,
            'per_scope_wall_ms' => 10000,
            'warmed' => 6,
            'skipped' => 1,
            'failed' => 2,
            'sites' => [
                ['code' => 'default', 'reason' => 'slice', 'warmed' => 2],
                ['code' => 'grocery', 'reason' => 'slice', 'warmed' => 2],
                ['code' => 'amayum', 'reason' => 'slice', 'warmed' => 2],
            ],
        ];

        $line = StorefrontWarmup::formatReport($report, 1.25);

        self::assertStringContainsString('source=cron', $line);
        self::assertStringContainsString('scopes=3', $line);
        self::assertStringContainsString('warmed=6', $line);
        self::assertStringContainsString('skipped=1', $line);
        self::assertStringContainsString('failed=2', $line);
        self::assertStringContainsString('per_scope_wall_ms=10000', $line);
        self::assertStringContainsString('elapsed=1.25s', $line);
    }

    public function testFormatMarksPerSiteError(): void
    {
        $report = [
            'scope_count' => 2,
            'per_scope_wall_ms' => 10000,
            'warmed' => 1,
            'skipped' => 0,
            'failed' => 1,
            'sites' => [
                ['code' => 'default', 'reason' => 'slice', 'warmed' => 1],
                ['code' => 'grocery', 'reason' => 'error:boom', 'warmed' => 0],
            ],
        ];

        $line = StorefrontWarmup::formatReport($report, 0.5);

        self::assertStringContainsString('site_errors=1', $line);
        self::assertStringContainsString('grocery:error:boom', $line);
    }

    public function testFormatToleratesEmptyReport(): void
    {
        $line = StorefrontWarmup::formatReport([], 0.0);

        self::assertStringContainsString('scopes=0', $line);
        self::assertStringContainsString('warmed=0', $line);
    }
}
