<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 页头/页脚默认继承全局 chrome；显式脱离后本布局独立；可恢复继承。
 */
final class SharedChromeInheritContractTest extends TestCase
{
    public function testSharedChromeServiceDefinesInheritDetachRestoreContract(): void
    {
        $path = dirname(__DIR__, 2) . '/Service/SharedChromeService.php';
        $src = file_get_contents($path);
        self::assertIsString($src);

        self::assertStringContainsString('final class SharedChromeService', $src);
        self::assertStringContainsString("MODE_INHERIT = 'inherit'", $src);
        self::assertStringContainsString("MODE_LOCAL = 'local'", $src);
        self::assertStringContainsString("CHROME_AREAS = ['header', 'footer']", $src);
        self::assertStringContainsString('function resolveModes(', $src);
        self::assertStringContainsString('function resolveWriteLayoutType(', $src);
        self::assertStringContainsString('function detach(', $src);
        self::assertStringContainsString('function restore(', $src);
        self::assertStringContainsString('ThemeLayout::PAGE_TYPE_HOME', $src);
        self::assertStringContainsString('withLayoutType(ThemeLayout::PAGE_TYPE_HOME)', $src);
    }

    public function testThemeEditorExposesChromeModeDetachRestoreEndpoints(): void
    {
        $path = dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php';
        $src = file_get_contents($path);
        self::assertIsString($src);

        self::assertStringContainsString('use Weline\\Theme\\Service\\SharedChromeService;', $src);
        self::assertStringContainsString('function getChromeMode(', $src);
        self::assertStringContainsString('function postChromeMode(', $src);
        self::assertStringContainsString('function postDetachChrome(', $src);
        self::assertStringContainsString('function postRestoreChrome(', $src);
        self::assertStringContainsString('function redirectChromeWritePayload(', $src);
        self::assertStringContainsString('resolveWriteLayoutType(', $src);
        self::assertStringContainsString('withLayoutType(ThemeLayout::PAGE_TYPE_HOME)', $src);
        self::assertStringContainsString('function resolveScopedDraftNode(', $src);
    }

    public function testThemeEditorContextSupportsWithLayoutType(): void
    {
        $path = dirname(__DIR__, 2) . '/Api/Scoped/ThemeEditorContext.php';
        $src = file_get_contents($path);
        self::assertIsString($src);
        self::assertStringContainsString('function withLayoutType(string $layoutType): self', $src);
    }

    public function testThemeEditorUiWiresSharedChromeInheritControls(): void
    {
        $js = file_get_contents(dirname(__DIR__, 2) . '/view/statics/ui/pages/weline-theme-editor.js');
        $tpl = file_get_contents(dirname(__DIR__, 2) . '/view/templates/backend/ThemeEditor/index.phtml');
        self::assertIsString($js);
        self::assertIsString($tpl);

        self::assertStringContainsString('data-api-chrome-mode=', $tpl);
        self::assertStringContainsString('data-api-detach-chrome=', $tpl);
        self::assertStringContainsString('data-api-restore-chrome=', $tpl);
        self::assertStringContainsString('function fetchSharedChromeMode(', $js);
        self::assertStringContainsString('function resolveChromeWriteLayoutType(', $js);
        self::assertStringContainsString('function handleSharedChromeAction(', $js);
        self::assertStringContainsString('ensureSharedChromePanel(', $js);
        self::assertStringContainsString("translateUiText('改为本布局独立')", $js);
        self::assertStringContainsString("translateUiText('恢复全局继承')", $js);
        self::assertStringContainsString('SHARED_CHROME_CARRIER_PAGE_TYPE', $js);
    }
}
