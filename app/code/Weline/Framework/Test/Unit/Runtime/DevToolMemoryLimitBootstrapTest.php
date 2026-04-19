<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\DevToolMemoryLimitBootstrap;

class DevToolMemoryLimitBootstrapTest extends TestCase
{
    public function testParseIniMemoryToBytesHandlesMegabytes(): void
    {
        self::assertSame(268435456, DevToolMemoryLimitBootstrap::parseIniMemoryToBytes('256M'));
    }

    public function testFormatBytesAsIniMemoryUsesMegabytesWhenAligned(): void
    {
        self::assertSame('512M', DevToolMemoryLimitBootstrap::formatBytesAsIniMemory(512 * 1024 * 1024));
    }
}
