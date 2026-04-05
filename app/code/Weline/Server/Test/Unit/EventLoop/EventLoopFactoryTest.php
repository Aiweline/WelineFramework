<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\EventLoop;

use PHPUnit\Framework\TestCase;
use Weline\Server\EventLoop\EventLoopFactory;

final class EventLoopFactoryTest extends TestCase
{
    public function testNormalizeDriverFallsBackToAuto(): void
    {
        self::assertSame('auto', EventLoopFactory::normalizeDriver(''));
        self::assertSame('auto', EventLoopFactory::normalizeDriver('unexpected'));
    }

    public function testCreateSelectLoopByDriver(): void
    {
        $result = EventLoopFactory::create('select');
        self::assertSame('select', $result['requested']);
        self::assertSame('select', $result['resolved']);
        self::assertSame('select', $result['loop']->backend());
    }

    public function testCreateAutoLoopResolvesByExtension(): void
    {
        $result = EventLoopFactory::create('auto');
        self::assertSame('select', $result['resolved']);
        self::assertSame('select', $result['loop']->backend());
    }

    public function testCreateEventLoopRequiresExtension(): void
    {
        if (\extension_loaded('event')) {
            $result = EventLoopFactory::create('event');
            self::assertSame('event', $result['resolved']);
            self::assertSame('event', $result['loop']->backend());
            return;
        }
        $this->expectException(\RuntimeException::class);
        EventLoopFactory::create('event');
    }
}

