<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 多部件槽有模板直嵌时不应整槽拦截其它 default_injections（如 user-area + wishlist-icon）。
 */
final class WidgetDefaultInjectionTemplateInlineContractTest extends TestCase
{
    private function serviceSource(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WidgetDefaultInjectionService.php'
        );
    }

    public function testMultipleSlotUsesPerWidgetTemplateInlineGate(): void
    {
        $src = $this->serviceSource();
        self::assertStringContainsString('function slotBlocksInjectionDueToTemplateInline(', $src);
        self::assertStringContainsString('widgetExistsAsTemplateInline($themeId, $item, $componentArea)', $src);
        self::assertStringContainsString("if (!empty(\$slotMeta['multiple']))", $src);
    }

    public function testResolveInstallBlockerDoesNotCallWholeSlotGateDirectly(): void
    {
        $src = $this->serviceSource();
        $pos = strpos($src, 'function resolveInstallBlocker(');
        self::assertNotFalse($pos);
        $snippet = substr($src, $pos, 1200);
        self::assertStringContainsString('slotBlocksInjectionDueToTemplateInline', $snippet);
        self::assertStringNotContainsString('slotHasTemplateInlineWidgets($themeId, $slotId, $componentArea)', $snippet);
    }
}
