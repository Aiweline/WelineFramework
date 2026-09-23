<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SharedSidecarWireContract;

final class SharedSidecarWireContractTest extends TestCase
{
    public function testMissingWireGenerationOnLiveRuntimeNeedsRotation(): void
    {
        self::assertTrue(SharedSidecarWireContract::runtimeNeedsRotation([
            'pid' => 100,
            'port' => 9502,
        ]));
        self::assertTrue(SharedSidecarWireContract::runtimeNeedsRotation([
            'pid' => 100,
            'port' => 9502,
            'wire_generation' => 1,
        ]));
        self::assertFalse(SharedSidecarWireContract::runtimeNeedsRotation([
            'pid' => 100,
            'port' => 9502,
            'wire_generation' => SharedSidecarWireContract::WIRE_GENERATION,
        ]));
        self::assertFalse(SharedSidecarWireContract::runtimeNeedsRotation([]));
    }

    public function testCurrentGenerationIncludesMdel(): void
    {
        self::assertSame(2, SharedSidecarWireContract::WIRE_GENERATION);
    }
}
