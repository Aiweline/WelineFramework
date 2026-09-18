<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib;

/**
 * High-concurrency WLS: form/children body capture must not use process-global ob_*.
 */
final class FormFiberCaptureContractTest extends TestCase
{
    public function testFormTaglibCallbackUsesFiberOutputBufferNotNativeOb(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/View/Taglib.php'
        );

        self::assertSame('20260918-fiber-form-ob-v2', Taglib::COMPILER_GENERATION);
        self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $source);
        self::assertStringContainsString('FiberOutputBuffer::endCapture()', $source);
        self::assertStringContainsString('Taglib__form_body', $source);
        self::assertStringNotContainsString("Taglib__form_body = \\\\ob_get_clean()", $source);
        self::assertMatchesRegularExpression(
            '/FormRenderer::open\\([\\s\\S]{0,400}?FiberOutputBuffer::beginCapture\\(\\)/',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/FormRenderer::open\\([\\s\\S]{0,200}?\\\\ob_start\\(\\)/',
            $source
        );
    }

    public function testAstCodeGeneratorRuntimeChildrenUsesFiberOutputBuffer(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/View/Taglib/Generator/CodeGenerator.php'
        );
        $pos = \strpos($source, 'function generateRuntimeTag');
        self::assertNotFalse($pos);
        $chunk = \substr($source, (int)$pos, 3500);
        self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $chunk);
        self::assertStringContainsString('FiberOutputBuffer::endCapture()', $chunk);
        self::assertStringContainsString('FiberOutputBuffer::discardCapture()', $chunk);
        self::assertStringNotContainsString('wrapPhp("ob_start();")', $chunk);
        self::assertStringNotContainsString('ob_get_clean()', $chunk);
    }
}
