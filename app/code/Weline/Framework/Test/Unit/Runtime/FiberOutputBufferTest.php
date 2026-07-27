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

    public function testNestedCapturesDoNotConsumeParentOutput(): void
    {
        $fiber = new \Fiber(static function (): array {
            FiberOutputBuffer::beginCapture();
            echo 'outer-before';

            FiberOutputBuffer::beginCapture();
            echo 'inner';
            $inner = FiberOutputBuffer::endCapture();

            echo 'outer-after';
            $outer = FiberOutputBuffer::endCapture();

            return [$inner, $outer];
        });

        $fiber->start();

        self::assertTrue($fiber->isTerminated());
        self::assertSame(['inner', 'outer-beforeouter-after'], $fiber->getReturn());
    }

    public function testReinstallsWhenGlobalBufferWasExternallyRemoved(): void
    {
        \ob_end_clean();

        FiberOutputBuffer::beginCapture();
        echo 'after-reinstall';

        self::assertSame('after-reinstall', FiberOutputBuffer::endCapture());
    }

    public function testEnsureInstalledRestoresAtRequestBoundary(): void
    {
        self::assertTrue(FiberOutputBuffer::isActive());

        \ob_end_clean();
        self::assertFalse(FiberOutputBuffer::isActive());

        FiberOutputBuffer::ensureInstalled('unit_request_start');

        self::assertTrue(FiberOutputBuffer::isActive());
        FiberOutputBuffer::beginCapture();
        echo 'request-boundary';

        self::assertSame('request-boundary', FiberOutputBuffer::endCapture());
    }

    public function testOversizedCaptureThrowsBeforeOutputHandlerExhaustsMemory(): void
    {
        $fiber = new \Fiber(static function (): void {
            FiberOutputBuffer::beginCapture();
            for ($i = 0; $i < 18; $i++) {
                echo \str_repeat('x', 1024 * 1024);
            }
            FiberOutputBuffer::endCapture();
        });

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('WLS output capture exceeded safe memory limits');

        $fiber->start();
    }

    public function testNonPersistentDiscardPreservesCallerOutputBuffer(): void
    {
        FiberOutputBuffer::uninstall();
        Runtime::setMode(RuntimeInterface::MODE_FPM);
        $baseline = \ob_get_level();
        \ob_start();
        $callerLevel = \ob_get_level();
        try {
            FiberOutputBuffer::beginCapture();
            echo 'discarded-template-output';
            FiberOutputBuffer::discardCapture();

            self::assertSame($callerLevel, \ob_get_level());
            echo 'caller-output';
            self::assertSame('caller-output', (string)\ob_get_contents());
        } finally {
            while (\ob_get_level() > $baseline) {
                \ob_end_clean();
            }
        }
    }

    public function testNonPersistentEndCapturePreservesCallerOutputBuffer(): void
    {
        FiberOutputBuffer::uninstall();
        Runtime::setMode(RuntimeInterface::MODE_FPM);
        $baseline = \ob_get_level();
        \ob_start();
        $callerLevel = \ob_get_level();
        try {
            FiberOutputBuffer::beginCapture();
            echo 'template-output';

            self::assertSame('template-output', FiberOutputBuffer::endCapture());
            self::assertSame($callerLevel, \ob_get_level());
        } finally {
            while (\ob_get_level() > $baseline) {
                \ob_end_clean();
            }
        }
    }
}
