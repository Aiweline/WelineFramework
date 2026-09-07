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
        self::assertStringContainsString('function restoreNonCarrierLayouts(', $src);
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
        self::assertStringContainsString('restoreNonCarrierLayouts(', $src);
        self::assertStringContainsString('all_non_carrier', $src);
        self::assertStringContainsString('function redirectChromeWritePayload(', $src);
        self::assertStringContainsString('resolveWriteLayoutType(', $src);
        self::assertStringContainsString('withLayoutType(ThemeLayout::PAGE_TYPE_HOME)', $src);
        self::assertStringContainsString('function resolveScopedDraftNode(', $src);

        $provider = (string)file_get_contents(
            dirname(__DIR__, 2) . '/extends/module/Weline_Framework/Query/ThemeQueryProvider.php'
        );
        self::assertStringContainsString("'/theme/backend/theme-editor/chrome-mode'", $provider);
        self::assertStringContainsString("'/theme/backend/theme-editor/detach-chrome'", $provider);
        self::assertStringContainsString("'/theme/backend/theme-editor/restore-chrome'", $provider);
        self::assertStringContainsString('postChromeMode()', $provider);
        self::assertStringContainsString('postDetachChrome()', $provider);
        self::assertStringContainsString('postRestoreChrome()', $provider);
    }

    public function testThemeEditorContextSupportsWithLayoutType(): void
    {
        $path = dirname(__DIR__, 2) . '/Api/Scoped/ThemeEditorContext.php';
        $src = file_get_contents($path);
        self::assertIsString($src);
        self::assertStringContainsString('function withLayoutType(string $layoutType): self', $src);
    }

    public function testUpgradePurgeUsesTypedGlobalScopeContext(): void
    {
        $path = dirname(__DIR__, 2) . '/Setup/Upgrade.php';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("VERSION = '2.2.189'", $src);
        self::assertStringContainsString('ScopeIdentity::global()', $src);
        self::assertStringContainsString('ScopeHierarchyInterface', $src);
        self::assertStringContainsString('new ThemeEditorContext(', $src);
        self::assertStringContainsString('restoreNonCarrierLayouts(', $src);
        self::assertStringNotContainsString('ThemeEditorContextFactory', $src);
        self::assertStringNotContainsString('theme_editor_typed_scope_required', $src);
    }

    public function testPublishFlushesSharedChromeCarrierAlongsideBusinessLayout(): void
    {
        $path = dirname(__DIR__, 2) . '/Service/Scoped/ThemeScopedWorkspaceRequestService.php';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('function publishSharedChromeCarrierIfPending(', $src);
        self::assertStringContainsString('isChromeCarrierPageType(', $src);
        self::assertStringContainsString('PAGE_TYPE_HOME', $src);
        self::assertStringContainsString("shared_chrome_carrier", $src);
        self::assertStringContainsString('publishSharedChromeCarrierIfPending(', $src);
        self::assertGreaterThan(
            1,
            substr_count($src, 'publishSharedChromeCarrierIfPending('),
            'publish and publishBatch must both flush the chrome carrier',
        );
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
        self::assertStringContainsString("translateUiText('清空其它布局本地 chrome')", $js);
        self::assertStringContainsString("translateUiText('恢复全部布局继承')", $js);
        self::assertStringContainsString('all_non_carrier', $js);
        self::assertStringContainsString('SHARED_CHROME_CARRIER_PAGE_TYPE', $js);
    }

    public function testChromeDefaultInjectionsTargetHomepageCarrierOnly(): void
    {
        $footer = (string)file_get_contents(dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/footer/default.phtml');
        $category = (string)file_get_contents(dirname(__DIR__, 2) . '/view/theme/frontend/widgets/navigation/category-menu/default.phtml');
        $help = (string)file_get_contents(dirname(__DIR__, 2) . '/view/theme/frontend/widgets/footer/footer-help-center-link/default.phtml');
        $integrity = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/WidgetDefaultInjectionService.php');

        self::assertStringContainsString('"layout_type":"homepage"', $footer);
        self::assertStringNotContainsString('"layout_type":"*"', $footer);
        self::assertStringContainsString('"layout_type":"homepage"', $category);
        self::assertStringContainsString('"layout_type":"homepage"', $help);
        self::assertStringContainsString('ThemeLayout::PAGE_TYPE_HOME', $integrity);
        self::assertStringContainsString('非载体布局不得补齐本地 footer-container', $integrity);
    }
}
