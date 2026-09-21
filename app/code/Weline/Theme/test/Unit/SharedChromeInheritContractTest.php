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
        self::assertStringContainsString('shared_chrome_detach_removed', $src);
        self::assertStringContainsString('function restore(', $src);
        self::assertStringContainsString('function restoreNonCarrierLayouts(', $src);
        self::assertStringContainsString('function forceInheritAndPublishNonCarriers(', $src);
        self::assertStringContainsString('ThemeLayout::PAGE_TYPE_HOME', $src);
        self::assertStringContainsString(
            "'overall_mode' => \$isCarrier ? self::MODE_LOCAL : self::MODE_INHERIT",
            $src,
        );
        self::assertStringContainsString("workspace->publish(", $src);
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

        self::assertStringContainsString("VERSION = '2.2.480'", $src);
        self::assertStringContainsString('getFromSetupVersion()', $src);
        self::assertStringContainsString("version_compare(\$from, self::VERSION, '>=')", $src);
        self::assertStringContainsString('ScopeIdentity::global()', $src);
        self::assertStringContainsString('migrateThemeLayoutEntitiesCutover', $src);
        self::assertStringContainsString('ScopeHierarchyInterface', $src);
        self::assertStringContainsString('new ThemeEditorContext(', $src);
        self::assertStringContainsString('forceInheritAndPublishNonCarriers(', $src);
        self::assertStringNotContainsString('ThemeEditorContextFactory', $src);
        self::assertStringNotContainsString('theme_editor_typed_scope_required', $src);
    }

    public function testPublishFlushesSharedChromeCarrierAlongsideBusinessLayout(): void
    {
        $path = dirname(__DIR__, 2) . '/Service/Scoped/ThemeScopedWorkspaceRequestService.php';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('function publishSharedChromeCarrierIfPending(', $src);
        self::assertStringContainsString('function overwriteNonCarrierChromeAfterCarrierPublish(', $src);
        self::assertStringContainsString('forceInheritAndPublishNonCarriers(', $src);
        self::assertStringContainsString('isChromeCarrierPageType(', $src);
        self::assertStringContainsString('PAGE_TYPE_HOME', $src);
        self::assertStringContainsString("shared_chrome_carrier", $src);
        self::assertStringContainsString('publishSharedChromeCarrierIfPending(', $src);
        self::assertGreaterThan(
            1,
            substr_count($src, 'publishSharedChromeCarrierIfPending('),
            'publish and publishBatch must both flush the chrome carrier',
        );
        self::assertGreaterThan(
            1,
            substr_count($src, 'overwriteNonCarrierChromeAfterCarrierPublish('),
            'publish and publishBatch must both overwrite non-carrier chrome',
        );
        self::assertStringContainsString('function publishPendingSiblingI18nLocales(', $src);
        self::assertStringContainsString('publishPendingSiblingI18nLocales(', $src);
        self::assertStringContainsString('sibling_i18n', $src);
    }

    public function testThemeEditorUiWiresSharedChromeInheritControls(): void
    {
        $js = file_get_contents(dirname(__DIR__, 2) . '/view/statics/ui/pages/weline-theme-editor.js');
        $tpl = file_get_contents(dirname(__DIR__, 2) . '/view/templates/backend/ThemeEditor/index.phtml');
        $editor = file_get_contents(dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php');
        $legacy = file_get_contents(dirname(__DIR__, 2) . '/view/statics/js/theme-editor.js');
        self::assertIsString($js);
        self::assertIsString($tpl);
        self::assertIsString($editor);
        self::assertIsString($legacy);

        self::assertStringContainsString('data-api-chrome-mode=', $tpl);
        self::assertStringContainsString('data-api-detach-chrome=', $tpl);
        self::assertStringContainsString('data-api-restore-chrome=', $tpl);
        // Hard cutover: chrome-mode returns theme_scope_version; detach is fail-closed.
        self::assertStringContainsString('theme_scope_version', $editor);
        self::assertStringContainsString('theme_version_id', $editor);
        self::assertStringContainsString('shared_chrome_detach_removed', $editor);
        self::assertStringContainsString('chrome_authority', $editor);
        self::assertStringContainsString("'status' => 'shared_chrome_detach_removed'", $editor);
        self::assertStringContainsString('$chrome->detach(', $editor);
        // Loaded UI bundle — always inherit, no detach CTA.
        self::assertStringContainsString('function fetchSharedChromeMode(', $js);
        self::assertStringContainsString('function resolveChromeWriteLayoutType(', $js);
        self::assertStringContainsString('function handleSharedChromeAction(', $js);
        self::assertStringContainsString('ensureSharedChromePanel(', $js);
        self::assertStringContainsString('SHARED_CHROME_CARRIER_PAGE_TYPE', $js);
        self::assertStringContainsString("window.__('恢复全局继承')", $js);
        self::assertStringContainsString("window.__('清空其它布局本地 chrome')", $js);
        self::assertStringContainsString("window.__('恢复全部布局继承')", $js);
        self::assertStringContainsString('all_non_carrier', $js);
        self::assertStringNotContainsString("window.__('改为本布局独立')", $js);
        self::assertStringNotContainsString('translateUiText', $js);
        self::assertStringNotContainsString('translateUiText', $legacy);
        self::assertStringContainsString('@static(Weline_Theme::ui/pages/weline-theme-editor.js)', $tpl);
        self::assertStringNotContainsString('@static(Weline_Theme::js/theme-editor.js)', $tpl);
        // Authority source remains js/theme-editor.js (compiled into the UI bundle).
        // Do not replace it with a deprecation stub — that empties the loaded bundle.
        self::assertStringContainsString('function fetchSharedChromeMode(', $legacy);
        self::assertStringContainsString('function loadCanvas(', $legacy);
        self::assertGreaterThan(1000, substr_count($legacy, "\n") + 1, 'theme-editor.js must keep editor behavior');
    }

    public function testChromeDefaultInjectionsTargetHomepageCarrierOnly(): void
    {
        $footer = (string)file_get_contents(dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/footer/default.phtml');
        $category = (string)file_get_contents(dirname(__DIR__, 2) . '/view/theme/frontend/widgets/navigation/category-menu/default.phtml');
        $help = (string)file_get_contents(dirname(__DIR__, 2) . '/view/theme/frontend/widgets/footer/footer-faq-link/default.phtml');
        $integrity = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/WidgetDefaultInjectionService.php');

        self::assertStringContainsString('"layout_type":"homepage"', $footer);
        self::assertStringNotContainsString('"layout_type":"*"', $footer);
        self::assertStringContainsString('"layout_type":"homepage"', $category);
        self::assertStringContainsString('"layout_type":"homepage"', $help);
        self::assertStringContainsString('ThemeLayout::PAGE_TYPE_HOME', $integrity);
        self::assertStringContainsString('非载体布局不得补齐本地 footer-container', $integrity);
    }
}
