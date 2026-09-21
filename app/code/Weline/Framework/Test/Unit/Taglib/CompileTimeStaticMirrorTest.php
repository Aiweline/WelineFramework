<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Taglib\CompileTimeStaticMirror;

class CompileTimeStaticMirrorTest extends TestCase
{
    public function testLiteralMarkupRejectsPhpEmbeds(): void
    {
        self::assertTrue(CompileTimeStaticMirror::isLiteralMarkup('Weline_Theme::frontend/css/a.css'));
        self::assertTrue(CompileTimeStaticMirror::isLiteralMarkup(''));
        self::assertFalse(CompileTimeStaticMirror::isLiteralMarkup('prefix<?= $x ?>suffix'));
        self::assertFalse(CompileTimeStaticMirror::isLiteralMarkup('<?php echo $x; ?>'));
    }

    public function testLiteralAttributeValueRejectsVariables(): void
    {
        self::assertTrue(CompileTimeStaticMirror::isLiteralAttributeValue('settings'));
        self::assertTrue(CompileTimeStaticMirror::isLiteralAttributeValue(true));
        self::assertTrue(CompileTimeStaticMirror::isLiteralAttributeValue(16));
        self::assertFalse(CompileTimeStaticMirror::isLiteralAttributeValue('$name'));
        self::assertFalse(CompileTimeStaticMirror::isLiteralAttributeValue('<?= $name ?>'));
    }

    public function testAttributesAreLiteralScansKeys(): void
    {
        $attrs = [
            'name' => 'settings',
            'size' => 'sm',
            'dynamic' => '<?= $x ?>',
        ];
        self::assertTrue(CompileTimeStaticMirror::attributesAreLiteral($attrs, ['name', 'size']));
        self::assertFalse(CompileTimeStaticMirror::attributesAreLiteral($attrs));
    }
}
