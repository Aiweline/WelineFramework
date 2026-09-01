<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EavManagerAttributeEditLockContractTest extends TestCase
{
    public function testAttributeEditLockSurfacesAcrossBackendAndUi(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-eav-manager.js',
        );
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Manager/surface.phtml',
        );
        $controller = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Manager.php',
        );

        self::assertStringContainsString('assertAttributeEditable', $controller);
        self::assertStringContainsString('enrichAttributeDetail', $controller);
        self::assertStringContainsString("'can_edit'", $controller);
        self::assertStringContainsString("'can_edit_options'", $controller);
        self::assertStringContainsString("'edit_lock_reason'", $controller);
        self::assertStringContainsString('系统属性不可修改', $controller);
        self::assertStringContainsString('属性已有实例数据，不可修改', $controller);
        self::assertStringContainsString('属性选项已更新', $controller);

        self::assertStringContainsString('editLockedSystem', $tpl);
        self::assertStringContainsString('editLockedInUse', $tpl);

        self::assertStringContainsString('appendEditLockNotice', $js);
        self::assertStringContainsString('w-eav-manager__edit-lock', $js);
        self::assertStringContainsString('values.can_edit === false', $js);
        self::assertStringContainsString('can_edit_options', $js);
        self::assertStringContainsString('optionsEditable', $js);
        self::assertStringContainsString('dataset.wEavReadonly', $js);
        self::assertStringContainsString('attributeReadOnly', $js);
    }
}
