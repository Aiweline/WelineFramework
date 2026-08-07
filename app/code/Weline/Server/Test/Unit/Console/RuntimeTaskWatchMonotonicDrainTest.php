<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

final class RuntimeTaskWatchMonotonicDrainTest extends TestCase
{
    public function testShutdownDrainNeverUsesWallClockDuration(): void
    {
        $path = \dirname(__DIR__, 3) . '/Console/Runtime/Task/Watch.php';
        $source = \file_get_contents($path);
        self::assertIsString($source);

        self::assertStringContainsString('\\hrtime(true)', $source);
        self::assertStringNotContainsString('microtime(true)', $source);
    }
}
