<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * pixel.phtml is compiled by Taglib; TypeScript-style JSDoc object types like
 * `@returns {{drop:boolean}}` become PHP `$drop:boolean` and ParseError.
 */
final class PixelJsdocTemplateSafetyContractTest extends TestCase
{
    public function testPixelPhtmlForbidsTypescriptObjectJsdocThatBreaksTaglib(): void
    {
        $path = dirname(__DIR__, 3) . '/view/taglib/js/pixel.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringNotContainsString('@returns {{', $src);
        self::assertStringNotContainsString('drop:boolean', $src);
        self::assertStringContainsString('function __evaluateRequiredParamGate(', $src);
    }
}
