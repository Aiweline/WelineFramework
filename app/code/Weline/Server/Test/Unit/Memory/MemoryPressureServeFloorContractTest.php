<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Memory\MemoryPressureController;

final class MemoryPressureServeFloorContractTest extends TestCase
{
    public function testStartupExplicitCountAndEmergencyFlagDefaults(): void
    {
        $controller = new MemoryPressureController();
        self::assertSame(0, $controller->getStartupExplicitCount());
        $controller->setStartupExplicitCount(2);
        self::assertSame(2, $controller->getStartupExplicitCount());
        // Default: must not break serve floor without explicit opt-in.
        self::assertFalse($controller->allowsEmergencyScaleBelowStartupExplicit());
    }
}
