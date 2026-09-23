<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Console\Theme;

use PHPUnit\Framework\TestCase;

final class LayoutBindingMigrationCommandTest extends TestCase
{
    public function testMigrationCallsAuthoritativeRebakeAndReportsRetainedLegacyArtifacts(): void
    {
        $command = dirname(__DIR__, 4) . '/Console/Theme/Layout/MigrateBindings.php';
        self::assertFileExists($command, '迁移命令尚未实现');
        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/layout-binding-migration.php') . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([null, []], $result['call']);
        self::assertStringContainsString('3', implode(' ', $result['messages']));
        self::assertTrue($result['legacy_retained']);
        self::assertStringContainsString('r99', implode(' ', $result['messages']));
    }
}
