<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;

/**
 * C-RB-1 (B1): setup success path must hard-fail when schema_diff operation_id release throws.
 * Locks source contract so "success + warning only" cannot regress silently.
 */
final class SetupSucceededReleaseHardFailContractTest extends TestCase
{
    public function testUpgradeMarksNonZeroExitWhenSucceededReleaseThrows(): void
    {
        // Test/Unit/Setup → Framework root (3 levels), then Setup console.
        $path = dirname(__DIR__, 3) . '/Setup/Console/Setup/Upgrade.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('setup 成功释放 schema_diff operation_id 绑定失败', $source);
        self::assertStringContainsString('$setupReleaseFailed', $source);
        self::assertStringContainsString('$exitCode = $exitCode === 0 ? 1 : $exitCode', $source);
        self::assertStringContainsString('B1: release failure must not look like a green upgrade', $source);

        // Must not treat release failure as a soft warning-only success path.
        $warningOnly = preg_match(
            '/catch\s*\(\s*\\\\Throwable\s+\$releaseError\s*\)\s*\{[^}]*printing->warning\([^;]*释放 schema_diff operation_id/',
            $source,
        );
        self::assertSame(0, $warningOnly);
        self::assertStringContainsString('printing->error', $source);
    }
}
