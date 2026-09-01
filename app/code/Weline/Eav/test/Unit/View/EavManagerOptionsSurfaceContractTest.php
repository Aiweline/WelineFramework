<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EavManagerOptionsSurfaceContractTest extends TestCase
{
    public function testManagerUiListsOptionsAndStructureBadges(): void
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

        self::assertStringContainsString('optionsPanel', $js);
        self::assertStringContainsString('structureLabels', $js);
        self::assertStringContainsString('w-eav-manager__options-list', $js);
        self::assertStringContainsString("'structures'", $tpl);
        self::assertStringContainsString('optionsTitle', $tpl);
        self::assertStringContainsString('optionStructureStats', $controller);
        self::assertStringContainsString("'structures'", $controller);
        self::assertStringContainsString('swatch_color', $controller);
    }
}
