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

        self::assertSame(64, SharedStatePoolDefaults::MEMORY_POOL_SIZE);
        self::assertSame(4, SharedStatePoolDefaults::MEMORY_MIN_IDLE);
        self::assertSame(0.3, $options['connect_timeout']);
        self::assertSame(1.0, $options['timeout']);
        self::assertSame(64, $options['pool_size']);
        self::assertSame(4, $options['pool_min_idle']);
        self::assertSame(0.5, $options['acquire_timeout']);
        self::assertTrue($options['fail_fast_on_cooldown']);
    }
}
