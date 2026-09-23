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

    public function testRealMemoryHeadroomOverflowsBeforeAppend(): void
    {
        $limitProp = new \ReflectionProperty(FiberOutputBuffer::class, 'memoryLimitBytes');
        $limitProp->setAccessible(true);
        $previousLimit = $limitProp->getValue();
        // Fake a tight limit relative to real arena usage so emalloc-only checks would pass.
        $limitProp->setValue(null, \memory_get_usage(true) + (2 * 1024 * 1024));

        try {
            $fiber = new \Fiber(static function (): void {
                FiberOutputBuffer::beginCapture();
                echo 'x';
                FiberOutputBuffer::endCapture();
            });

            $this->expectException(\OverflowException::class);
            $this->expectExceptionMessage('WLS output capture exceeded safe memory limits');
            $fiber->start();
        } finally {
            $limitProp->setValue(null, $previousLimit);
        }
    }

    public function testReentrancyDuringHandlerMarksOverflowWithoutFatal(): void
    {
        FiberOutputBuffer::beginCapture();

        $depthProp = new \ReflectionProperty(FiberOutputBuffer::class, 'inHandlerDepth');
        $depthProp->setAccessible(true);
        $depthProp->setValue(null, 1);

        try {
            self::assertSame('', FiberOutputBuffer::handleOutputChunk('reentrant-chunk'));
        } finally {
            $depthProp->setValue(null, 0);
        }

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('WLS output capture exceeded safe memory limits');
        FiberOutputBuffer::endCapture();
    }

    public function testFlushSkippedWhileInsideOutputHandler(): void
    {
        $depthProp = new \ReflectionProperty(FiberOutputBuffer::class, 'inHandlerDepth');
        $depthProp->setAccessible(true);
        $depthProp->setValue(null, 1);

        try {
            $flush = new \ReflectionMethod(FiberOutputBuffer::class, 'flushInstalledBufferIntoCurrentFrame');
            $flush->setAccessible(true);
            self::assertFalse($flush->invoke(null));
        } finally {
            $depthProp->setValue(null, 0);
        }
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

    public function testDiscardCaptureNotifiesRequestContextListeners(): void
    {
        FiberOutputBuffer::uninstall();
        Runtime::setMode(RuntimeInterface::MODE_FPM);
        $notified = false;
        \Weline\Framework\Runtime\RequestContext::onCaptureDiscard(static function () use (&$notified): void {
            $notified = true;
        }, 'fiber-output-buffer-test');
        $baseline = \ob_get_level();
        \ob_start();
        try {
            FiberOutputBuffer::beginCapture();
            echo 'x';
            FiberOutputBuffer::discardCapture();
            self::assertTrue($notified);
        } finally {
            \Weline\Framework\Runtime\RequestContext::removeCaptureDiscard('fiber-output-buffer-test');
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

    public function testPersistentNestedCaptureUnderNativeFormBufferReturnsHtml(): void
    {
        // Mirrors `<w:form>` compiled `ob_start` wrapping a nested Template::fetch.
        FiberOutputBuffer::beginCapture();
        echo 'outer-before;';
        \ob_start();
        FiberOutputBuffer::beginCapture();
        echo 'DROPDOWN';
        $nested = FiberOutputBuffer::endCapture();
        echo $nested;
        echo 'after-nested;';
        $formBody = (string)\ob_get_clean();
        echo 'FORM[' . $formBody . ']';
        $outer = FiberOutputBuffer::endCapture();

        self::assertSame('DROPDOWN', $nested);
        self::assertSame('DROPDOWNafter-nested;', $formBody);
        self::assertSame('outer-before;FORM[DROPDOWNafter-nested;]', $outer);
    }

    public function testNativeNestedCapturesPairIndependentlyOfFiberFrames(): void
    {
        FiberOutputBuffer::beginCapture();
        echo 'page;';
        \ob_start();
        FiberOutputBuffer::beginCapture();
        echo 'header-native;';
        FiberOutputBuffer::beginCapture();
        echo 'child';
        $child = FiberOutputBuffer::endCapture();
        echo $child;
        $header = FiberOutputBuffer::endCapture();
        $legacy = (string)\ob_get_clean();
        echo 'LEGACY[' . $legacy . ']';
        $page = FiberOutputBuffer::endCapture();

        self::assertSame('child', $child);
        self::assertSame('header-native;child', $header);
        // Nested native capture owns the bytes; the legacy caller buffer stays empty.
        self::assertSame('', $legacy);
        self::assertSame('page;LEGACY[]', $page);
    }
}
