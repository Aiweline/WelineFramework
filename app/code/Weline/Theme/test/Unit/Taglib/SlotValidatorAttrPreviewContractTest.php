<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

final class SlotValidatorAttrPreviewContractTest extends TestCase
{
    public function testPositionAndIdErrorsUseAttrPreviewOutsideTranslate(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Taglib/SlotValidator.php');
        self::assertStringContainsString('function attrPreview', $src);
        self::assertStringContainsString('attrPreview($position)', $src);
        self::assertStringContainsString('attrPreview($id)', $src);
        self::assertStringNotContainsString(
            'position 属性值无效: "%{value}"',
            $src,
        );
    }
}
