<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EavManagerEntityScopeContractTest extends TestCase
{
    public function testManagerSupportsEntityCodeScopedMode(): void
    {
        $controller = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Manager.php',
        );
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Manager/surface.phtml',
        );
        $index = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Manager/index.phtml',
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-eav-manager.js',
        );

        self::assertStringContainsString('resolveEntityScopeFromRequest', $controller);
        self::assertStringContainsString('entity_code', $controller);
        self::assertStringContainsString('getEntityChildren($scope[\'entityId\'])', $controller);
        self::assertStringContainsString("'entityScope'", $index);
        self::assertStringContainsString('templates/Backend/Manager/surface.phtml', $index);
        self::assertStringContainsString('w-eav-manager--scoped', $tpl);
        self::assertStringContainsString('entityScope', $js);
        self::assertStringContainsString('entity_code', $js);
        self::assertStringContainsString('entityScope?.entityId', $js);
    }
}
