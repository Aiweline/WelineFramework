<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Taglib\AttributeCodeCompiler;

class AttributeCodeCompilerTest extends TestCase
{
    public function testCompilesPhpShortEchoInAttributes(): void
    {
        $attributes = [
            'name' => '<?= $esc($icon) ?>',
            'size' => 'md',
        ];

        $code = AttributeCodeCompiler::attributes($attributes);

        self::assertStringContainsString('$Taglib__name = (string)($esc($icon));', $code);
        self::assertStringNotContainsString("Weline_Taglib_resolve('<?=", $code);
    }

    public function testKeepsDeclarativeResolveForPlainPaths(): void
    {
        $attributes = [
            'name' => 'circle',
            'size' => 'sm',
        ];

        $code = AttributeCodeCompiler::attributes($attributes);

        self::assertStringContainsString("Weline_Taglib_resolve('circle', get_defined_vars())", $code);
    }
}
