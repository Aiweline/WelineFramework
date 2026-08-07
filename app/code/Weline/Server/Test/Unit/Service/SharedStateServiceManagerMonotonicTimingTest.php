<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SharedStateServiceManager;

final class SharedStateServiceManagerMonotonicTimingTest extends TestCase
{
    public function testSidecarStartupAndShutdownBudgetsIgnoreWallClockJumps(): void
    {
        $source = (string)\file_get_contents(
            (new \ReflectionClass(SharedStateServiceManager::class))->getFileName(),
        );

        self::assertStringNotContainsString('\\microtime(true)', $source);
        self::assertStringContainsString('\\hrtime(true)', $source);
    }
}
