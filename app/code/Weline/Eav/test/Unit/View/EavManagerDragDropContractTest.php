<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EavManagerDragDropContractTest extends TestCase
{
    public function testManagerExposesMoveEndpointAndDragDropUi(): void
    {
        $controller = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Manager.php',
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-eav-manager.js',
        );
        $css = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-eav-manager.css',
        );

        self::assertStringContainsString('postMove', $controller);
        self::assertStringContainsString('moveGroupToSet', $controller);
        self::assertStringContainsString('moveAttributeToGroup', $controller);
        self::assertStringContainsString("request('move', 'POST'", $js);
        self::assertStringContainsString('data-w-eav-drag', $js);
        self::assertStringContainsString('data-w-eav-drop', $js);
        self::assertStringContainsString('group: \'set\'', $js);
        self::assertStringContainsString('attribute: \'group\'', $js);
        self::assertStringContainsString('.is-drop-target', $css);
    }
}
