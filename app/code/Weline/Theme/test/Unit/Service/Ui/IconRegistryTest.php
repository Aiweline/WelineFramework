<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Ui;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Ui\IconRegistry;

final class IconRegistryTest extends TestCase
{
    public function testSpriteUsesNativeHiddenStateWithoutInlineStyle(): void
    {
        $sprite = (new IconRegistry())->sprite();

        self::assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg" hidden aria-hidden="true">', $sprite);
        self::assertStringNotContainsString('style=', $sprite);
        self::assertStringContainsString('<symbol id="w-icon-', $sprite);
    }
}
