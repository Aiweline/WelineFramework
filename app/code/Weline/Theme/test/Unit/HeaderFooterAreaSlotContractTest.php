<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 默认 header/footer partial 通过内嵌 w:slot 容器支持整段部件替换。
 */
final class HeaderFooterAreaSlotContractTest extends TestCase
{
    public function testHeaderPartialUsesNestedExclusiveAreaSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('<w:slot id="header"', $src);
        self::assertStringContainsString('accept="layout-global-header,header-container"', $src);
        self::assertStringContainsString('exclusive="true"', $src);
        self::assertStringContainsString('class="weline-header-slot"', $src);
        self::assertStringNotContainsString('data-wslot="header"', $src);
    }

    public function testFooterPartialUsesNestedExclusiveAreaSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/footer/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('<w:slot id="footer"', $src);
        self::assertStringContainsString('accept="layout-global-footer,footer-container"', $src);
        self::assertStringContainsString('exclusive="true"', $src);
        self::assertStringContainsString('class="weline-footer-slot"', $src);
        self::assertStringNotContainsString('data-wslot="footer"', $src);
    }

    public function testExclusiveHeaderFooterSlotsReplaceInsteadOfAppend(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/SlotRendererService.php'
        );

        self::assertStringContainsString(
            "\$isAreaContainerSlot = (\$slotId === 'footer' || \$slotId === 'header') && !\$isExclusive;",
            $src
        );
    }

    public function testContainerWidgetsDeclareAreaSlotSupports(): void
    {
        $header = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/header/default.phtml'
        );
        $footer = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/footer/default.phtml'
        );

        self::assertStringContainsString('@widget.supports {["layout-global-header","header-container"]}', $header);
        self::assertStringContainsString('@widget.supports {["layout-global-footer","footer-container"]}', $footer);
    }

    public function testEditorModeStylesTargetHeaderFooterSlotWrappers(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/css/editor-mode.css'
        );

        self::assertStringContainsString('.weline-header-slot[data-wslot="header"]', $css);
        self::assertStringContainsString('.weline-footer-slot[data-wslot="footer"]', $css);
        self::assertStringContainsString('[data-w-slot-hover-target="true"]', $css);
        self::assertStringNotContainsString('header[data-wslot="header"]:hover', $css);
    }
}
