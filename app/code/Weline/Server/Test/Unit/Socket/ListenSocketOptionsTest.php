<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Socket;

use PHPUnit\Framework\TestCase;
use Weline\Server\Socket\ListenSocketOptions;

final class ListenSocketOptionsTest extends TestCase
{
    public function testStreamContextOptionsKeepsReuseAddrOnUnix(): void
    {
        $options = ListenSocketOptions::streamContextOptions([
            'backlog' => 1024,
        ], false);

        self::assertSame(1024, $options['backlog']);
        self::assertTrue($options['so_reuseaddr']);
    }

    public function testStreamContextOptionsDropsReuseAddrOnWindows(): void
    {
        $options = ListenSocketOptions::streamContextOptions([
            'backlog' => 1024,
            'so_reuseaddr' => true,
        ], true);

        self::assertSame(1024, $options['backlog']);
        self::assertArrayNotHasKey('so_reuseaddr', $options);
    }

    public function testWindowsDetectionCanBeOverridden(): void
    {
        self::assertTrue(ListenSocketOptions::isWindows(true));
        self::assertFalse(ListenSocketOptions::isWindows(false));
    }
}
