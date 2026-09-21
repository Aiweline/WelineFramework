<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** 多语言弹窗须提供语言搜索并定位到匹配行。 */
final class ThemeEditorI18nLocaleSearchContractTest extends TestCase
{
    public function testThemeEditorBindsLocaleSearchAndLocate(): void
    {
        $file = dirname(__DIR__, 3) . '/view/statics/js/theme-editor.js';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('data-i18n-locale-search', $src);
        self::assertStringContainsString('function bindI18nLocaleSearch', $src);
        self::assertStringContainsString('function applyI18nLocaleSearch', $src);
        self::assertStringContainsString("window.__('搜索语言或代码...')", $src);
        self::assertStringContainsString("window.__('没有匹配的语言')", $src);
        self::assertStringContainsString('data-locate', $src);
        self::assertStringContainsString('scrollIntoView', $src);
        self::assertStringContainsString('bindI18nLocaleSearch(panel, { reset: true, focus: true })', $src);

        $css = (string)file_get_contents(dirname(__DIR__, 4) . '/Widget/view/statics/css/widget-param-types.css');
        self::assertStringContainsString('.w-param-i18n-toolbar', $css);
        self::assertStringContainsString('.w-param-i18n-locale-search', $css);
        self::assertStringContainsString('[data-locate="1"]', $css);
    }
}
