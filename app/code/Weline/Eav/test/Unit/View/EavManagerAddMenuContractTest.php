<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EavManagerAddMenuContractTest extends TestCase
{
    public function testAddButtonIsContextualSingleAction(): void
    {
        $surface = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Manager/surface.phtml',
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-eav-manager.js',
        );
        $css = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-eav-manager.css',
        );

        self::assertStringContainsString('data-w-eav-add', $surface);
        self::assertStringContainsString('data-w-eav-add-label', $surface);
        self::assertStringContainsString('data-w-eav-detail-header', $surface);
        self::assertStringContainsString('选中实体添加属性集', $surface);
        self::assertStringNotContainsString('data-w-eav-add-menu', $surface);
        self::assertStringNotContainsString('data-w-eav-attribute-dialog', $surface);
        self::assertStringContainsString('appendQuickAddPanel', $js);
        self::assertStringNotContainsString('data-w-eav-add hidden', $surface);

        self::assertStringContainsString('addChildTypeForSelection', $js);
        self::assertStringContainsString('resolveAddContext', $js);
        self::assertStringContainsString('updateAddButton', $js);
        self::assertStringNotContainsString('updateAddMenu', $js);
        self::assertStringNotContainsString('openAttributeAddDialog', $js);

        self::assertStringContainsString('w-eav-manager__detail-actions', $css);
    }
}
