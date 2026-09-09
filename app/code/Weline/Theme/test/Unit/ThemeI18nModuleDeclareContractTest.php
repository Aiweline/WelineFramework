<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Theme declares i18n module load; Weline_I18n owns the JS registration.
 */
final class ThemeI18nModuleDeclareContractTest extends TestCase
{
    public function testLanguageSwitcherWidgetDeclaresI18nLoad(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/language-switcher/default.phtml';
        self::assertFileExists($path);
        $html = (string) file_get_contents($path);

        self::assertStringContainsString('data-weline-load="i18n"', $html);
        self::assertStringNotContainsString('@static(Weline_I18n::js/i18n.js)', $html);
        self::assertStringNotContainsString('<script src=', $html);
    }

    public function testHeadModuleDeclarationsDeclareI18n(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/partials/head/module-declarations.phtml';
        self::assertFileExists($path);
        $html = (string) file_get_contents($path);

        self::assertStringContainsString("Weline.declare('i18n'", $html);
        self::assertStringNotContainsString('Weline_I18n::js/i18n.js', $html);

        $headPath = dirname(__DIR__, 3) . '/view/theme/frontend/partials/head/default.phtml';
        self::assertFileExists($headPath);
        $head = (string) file_get_contents($headPath);
        self::assertStringContainsString(
            'Weline_Theme::frontend::partials::head::module-declarations',
            $head
        );
    }
}
