<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\EventLoop;

use PHPUnit\Framework\TestCase;
use Weline\Server\EventLoop\EventExtLoop;

final class EventExtLoopTest extends TestCase
{
    public function testBackendIsEventWhenExtensionLoaded(): void
    {
        if (!\extension_loaded('event')) {
            $this->markTestSkipped('event extension is not loaded');
        }

        $loop = new EventExtLoop();
        self::assertSame('event', $loop->backend());
    }

    public function testWaitReturnsZeroOnTimeoutWithoutWatchers(): void
    {
        if (!\extension_loaded('event')) {
            $this->markTestSkipped('event extension is not loaded');
        }

        $loop = new EventExtLoop();
        $read = [];
        $write = [];
        $except = [];
        $changed = $loop->wait($read, $write, $except, 0, 1000);

        self::assertSame(0, $changed);
        self::assertSame([], $read);
        self::assertSame([], $write);
        self::assertSame([], $except);
    }
}
