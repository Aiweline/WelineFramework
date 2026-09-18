<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib\Generator\ClassGenerator;

/**
 * Generated compiled-template classes must not use process-global ob_*.
 */
final class ClassGeneratorFiberCaptureContractTest extends TestCase
{
    public function testGeneratedRenderUsesFiberOutputBuffer(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/View/Taglib/Generator/ClassGenerator.php'
        );
        $pos = \strpos($source, 'public function generate(');
        self::assertNotFalse($pos);
        $chunk = \substr($source, (int)$pos, 2500);

        self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $chunk);
        self::assertStringContainsString('FiberOutputBuffer::endCapture()', $chunk);
        self::assertStringContainsString('FiberOutputBuffer::discardCapture()', $chunk);
        self::assertStringNotContainsString('ob_start();', $chunk);
        self::assertStringNotContainsString('ob_get_clean()', $chunk);
        self::assertTrue(\class_exists(ClassGenerator::class));
    }
}
