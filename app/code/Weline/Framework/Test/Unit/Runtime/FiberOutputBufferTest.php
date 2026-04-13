<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\FiberOutputBuffer;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class FiberOutputBufferTest extends TestCase
{
    protected function setUp(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        FiberOutputBuffer::install();
    }

    protected function tearDown(): void
    {
        FiberOutputBuffer::uninstall();
        Runtime::resetModeCache();
    }

    public function testCapturesOutputPerFiber(): void
    {
        $fiberA = new \Fiber(static function (): string {
            FiberOutputBuffer::beginCapture();
            echo 'fiber-a-1';
            \Fiber::suspend();
            echo 'fiber-a-2';
            return FiberOutputBuffer::endCapture();
        });

        $fiberB = new \Fiber(static function (): string {
            FiberOutputBuffer::beginCapture();
            echo 'fiber-b';
            return FiberOutputBuffer::endCapture();
        });

        self::assertNull($fiberA->start());
        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        self::assertSame('fiber-b', $fiberB->getReturn());

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame('fiber-a-1fiber-a-2', $fiberA->getReturn());
    }
}
