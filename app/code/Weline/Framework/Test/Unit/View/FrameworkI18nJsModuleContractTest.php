<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 核心 i18n JS 由 Framework 登记；Theme declare 加载；禁止写进 weline.js / Weline_I18n。
 */
final class FrameworkI18nJsModuleContractTest extends TestCase
{
    public function testFrameworkRegistersCoreI18nModule(): void
    {
        $path = dirname(__DIR__, 3) . '/View/statics/frontend/weline.modules.js';
        self::assertFileExists($path);
        $js = (string) file_get_contents($path);

        self::assertStringContainsString('i18n:', $js);
        self::assertStringContainsString('Weline_Framework::js/i18n.js', $js);
        self::assertStringContainsString('globalVar: "WelineI18n"', $js);
        self::assertStringContainsString('load: "defer"', $js);
        self::assertStringContainsString('language: "i18n"', $js);
    }

    public function testFrameworkI18nScriptInstallsFacade(): void
    {
        $path = dirname(__DIR__, 3) . '/View/statics/js/i18n.js';
        self::assertFileExists($path);
        $js = (string) file_get_contents($path);

        self::assertStringContainsString('installWelineI18nFacade', $js);
        self::assertStringContainsString('window.Weline.i18n', $js);
        self::assertStringContainsString('window.WelineI18n', $js);
        self::assertStringContainsString('Weline_Framework', $js);
    }

    public function testFrontendWelineJsDoesNotEmbedI18nObject(): void
    {
        $path = dirname(__DIR__, 4) . '/Frontend/view/statics/js/weline.js';
        self::assertFileExists($path);
        $js = (string) file_get_contents($path);

        self::assertStringNotContainsString('persistLangPreference', $js);
        self::assertStringNotContainsString('i18n: {', $js);
        self::assertStringContainsString('WelineI18n', $js);
        self::assertStringContainsString('Weline_Framework::js/i18n.js', $js);
    }

    public function testI18nEnhancementModuleDoesNotRegisterCoreI18n(): void
    {
        $path = dirname(__DIR__, 4) . '/I18n/view/statics/frontend/weline.modules.js';
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist(dirname(__DIR__, 4) . '/I18n/view/statics/js/i18n.js');
    }
}
