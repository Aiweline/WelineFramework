<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared\Connection;

use PHPUnit\Framework\TestCase;
use Weline\Server\Shared\Connection\SharedStatePoolDefaults;

final class SharedStatePoolDefaultsTest extends TestCase
{
    public function testWlsMemoryClientOptionsSizedForHighConcurrencyContention(): void
    {
        $options = SharedStatePoolDefaults::memoryClientOptions(true);

        self::assertSame(32, SharedStatePoolDefaults::MEMORY_POOL_SIZE);
        self::assertSame(2, SharedStatePoolDefaults::MEMORY_MIN_IDLE);
        self::assertSame(0.15, $options['connect_timeout']);
        self::assertSame(0.25, $options['timeout']);
        self::assertSame(32, $options['pool_size']);
        self::assertSame(2, $options['pool_min_idle']);
        self::assertSame(0.1, $options['acquire_timeout']);
        self::assertTrue($options['fail_fast_on_cooldown']);
    }
}
