<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Ui\IconRegistry;

final class SearchTypeDropdownSvgSpriteContractTest extends TestCase
{
    public function testTypeDropdownUsesPageLocalSpriteAndUseRefs(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/partials/search/type-dropdown.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('localSprite', $src);
        self::assertStringContainsString('renderUse', $src);
        self::assertStringContainsString("['check', 'chevron-right', 'chevron-down']", $src);
        self::assertStringContainsString("\$iconUse('check'", $src);
        self::assertStringContainsString("\$iconUse('chevron-right'", $src);
        self::assertStringContainsString("\$iconUse('chevron-down'", $src);
        self::assertStringNotContainsString('<w:icon name="check"', $src);
        self::assertStringNotContainsString('<w:icon name="chevron-right"', $src);
        self::assertStringNotContainsString('<w:icon name="chevron-down"', $src);
        self::assertStringNotContainsString('weline-icons.svg', $src);
    }

    public function testIconRegistryLocalSpriteAndRenderUse(): void
    {
        $registry = new IconRegistry();
        $sprite = $registry->localSprite('header-search-type-menu-ico', ['check', 'chevron-right']);
        self::assertStringContainsString('id="header-search-type-menu-ico-check"', $sprite);
        self::assertStringContainsString('id="header-search-type-menu-ico-chevron-right"', $sprite);
        self::assertStringContainsString('w-icon-local-sprite', $sprite);
        self::assertStringContainsString('<path d="m4 12 5 5L20 6"/>', $sprite);

        $use = $registry->renderUse('header-search-type-menu-ico-check', 'sm', '', '', 'check');
        self::assertStringContainsString('href="#header-search-type-menu-ico-check"', $use);
        self::assertStringContainsString('<use ', $use);
        self::assertStringNotContainsString('<path d="m4 12 5 5L20 6"/>', $use);
    }
}
