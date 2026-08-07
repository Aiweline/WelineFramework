<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\PassthroughCore;

final class PassthroughCoreMonotonicRuntimeTimingTest extends TestCase
{
    public function testRuntimeDeadlinesAndLeaseAgesUseOnlyTheMonotonicClock(): void
    {
        $source = (string)\file_get_contents(
            (new \ReflectionClass(PassthroughCore::class))->getFileName(),
        );

        self::assertStringNotContainsString('\\microtime(true)', $source);
        self::assertStringContainsString('\\hrtime(true)', $source);
    }
}
