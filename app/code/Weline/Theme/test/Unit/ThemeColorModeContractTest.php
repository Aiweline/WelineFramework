<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

final class ThemeColorModeContractTest extends TestCase
{
    public function testUiTwoUsesOneNativeThemeRuntime(): void
    {
        $runtime = $this->read('app/code/Weline/Theme/view/ui/js/weline-ui.js');
        self::assertStringContainsString("const normalize = (value) => ['system', 'light', 'dark'].includes(value)", $runtime);
        self::assertStringContainsString('root.dataset.themePreference = preference;', $runtime);
        self::assertStringContainsString('root.dataset.theme = theme;', $runtime);
        self::assertStringContainsString("media?.addEventListener('change', onSystemChange);", $runtime);
        self::assertStringContainsString('Weline.Theme = Object.assign', $runtime);
        self::assertStringNotContainsString('window.Toast', $runtime);
        self::assertStringNotContainsString('window.bootstrap', $runtime);
        self::assertStringNotContainsString('window.jQuery', $runtime);

        $prepaint = $this->read('app/code/Weline/Theme/view/ui/js/theme-prepaint.js');
        self::assertStringContainsString("localStorage.getItem('weline_theme_preference')", $prepaint);
        self::assertStringContainsString("['system', 'light', 'dark'].includes(preference)", $prepaint);
        self::assertStringContainsString('root.dataset.theme = theme;', $prepaint);
    }

    public function testFoundationIsLayeredTokenOwnedAndHasNoFrameworkAdapter(): void
    {
        $foundation = $this->read('app/code/Weline/Theme/view/ui/css/foundation.css');
        self::assertStringContainsString('@layer reset, tokens, base, layout, components, utilities, page;', $foundation);
        self::assertStringContainsString('--weline-theme-canvas:', $foundation);
        self::assertStringContainsString('--weline-theme-surface:', $foundation);
        self::assertStringContainsString('--weline-theme-text:', $foundation);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $foundation);
        self::assertStringNotContainsString('--bs-', $foundation);
        self::assertDoesNotMatchRegularExpression('/(^|[,\s])\.(?:btn|card|modal|dropdown|offcanvas|form-control)(?:[\s,:.{#]|$)/m', $foundation);

        $backend = $this->read('app/code/Weline/Theme/view/ui/css/backend.css');
        self::assertStringContainsString('--backend-theme-sidebar-width:', $backend);
        self::assertStringNotContainsString('--bs-', $backend);
        self::assertStringNotContainsString('.dropdown-menu', $backend);
    }

    public function testFloatingUiPreservesClickReferencesAndClampsToTheVisualViewport(): void
    {
        $runtime = $this->read('app/code/Weline/Theme/view/ui/js/weline-ui.js');
        foreach ([
            'window.visualViewport',
            'event.clientX',
            'event.clientY',
            "mode: mode === 'pointer' && pointer ? 'pointer' : 'element'",
            "reference?.mode !== 'pointer'",
            'Math.max(viewport.left, Math.min(left, viewport.right - floatingRect.width))',
            'Math.max(viewport.top, Math.min(top, viewport.bottom - floatingRect.height))',
            "createFloatingPortal(panel, 'menu')",
            "close(false, 'anchor-hidden', true)",
            'function resolveFloatingHost(from)',
            'function applyFloatingStackElevation(floating, host)',
            'function floatingLayerFloor(floating)',
            'function effectiveStackZ(element)',
            'let peak = base + 1',
            "floating.dataset.wFloatingPortal = 'true'",
            'resolveFloatingHost(marker.parentElement || marker)',
            "createFloatingPortal(tooltip, 'tooltip')",
        ] as $contract) {
            self::assertStringContainsString($contract, $runtime);
        }
        self::assertStringContainsString("window.visualViewport?.addEventListener('resize'", $runtime);
        self::assertStringContainsString("document.addEventListener('scroll', scheduleFloatingViewportUpdate", $runtime);

        $advanced = $this->read('app/code/Weline/Theme/view/ui/js/components/advanced.js');
        self::assertStringContainsString("floating.portal(panel, 'combobox')", $advanced);
        self::assertStringContainsString("floating.portal(panel, 'icon-picker')", $advanced);
        self::assertStringContainsString('portal.contains(event.target)', $advanced);

        $aiModelSelect = $this->read('app/code/Weline/Ai/view/statics/js/ai-model-select.js');
        self::assertStringContainsString("floating.portal(panel, 'ai-model-select')", $aiModelSelect);

        $foundation = $this->read('app/code/Weline/Theme/view/ui/css/foundation.css');
        self::assertStringContainsString('.w-dialog:has(> [data-w-floating-portal])', $foundation);
        self::assertStringContainsString('.w-drawer:has(> [data-w-floating-portal])', $foundation);
        self::assertStringContainsString('[data-w-floating-portal][data-w-floating-positioned]', $foundation);
    }

    public function testHeadsLoadOnlyExplicitUiTwoAssetsAndNoInlineRuntime(): void
    {
        $backend = $this->read('app/code/Weline/Theme/view/theme/backend/partials/head/default.phtml');
        self::assertStringContainsString('Weline_Theme::ui/weline-foundation.css', $backend);
        self::assertStringContainsString('Weline_Theme::ui/weline-backend.css', $backend);
        self::assertStringContainsString('Weline_Theme::ui/weline-ui.js', $backend);
        self::assertStringContainsString('ThemeDiskHeadService', $backend);

        foreach (['default.phtml', 'minimal.phtml'] as $head) {
            $frontend = $this->read('app/code/Weline/Theme/view/theme/frontend/partials/head/' . $head);
            self::assertStringContainsString('Weline_Theme::ui/weline-theme-prepaint.js', $frontend);
            self::assertStringContainsString('Weline_Theme::ui/weline-foundation.css', $frontend);
            self::assertStringContainsString('Weline_Theme::ui/weline-frontend.css', $frontend);
            self::assertStringContainsString('Weline_Theme::ui/weline-ui.js', $frontend);
            self::assertDoesNotMatchRegularExpression('/<script(?:\s[^>]*)?>\s*\(function/s', $frontend);
            self::assertStringNotContainsString('assets/js/theme.js', $frontend);
            self::assertStringNotContainsString('assets/css/theme.css', $frontend);
        }
    }

    public function testToastRegionUsesPopoverTopLayerAboveModalDialogs(): void
    {
        $runtime = $this->read('app/code/Weline/Theme/view/ui/js/weline-ui.js');
        self::assertStringContainsString('function resolveToastHost()', $runtime);
        self::assertStringContainsString('function detachToastRegionPopover(region)', $runtime);
        self::assertStringContainsString("document.querySelector('dialog:modal')", $runtime);
        self::assertStringContainsString('host.append(toastRegion)', $runtime);
        self::assertStringContainsString('region.showPopover()', $runtime);
        self::assertStringContainsString('element.showModal()', $runtime);

        $foundation = $this->read('app/code/Weline/Theme/view/ui/css/foundation.css');
        self::assertStringContainsString('.w-toast-region[popover]', $foundation);
        self::assertStringContainsString('.w-dialog:has(> .w-toast-region)', $foundation);
        self::assertStringContainsString('--weline-z-toast: 1100;', $foundation);
        self::assertStringContainsString('--weline-z-overlay: 900;', $foundation);
    }

    public function testManifestOwnsCoreAndRouteBundlesExplicitly(): void
    {
        $manifest = $this->read('app/code/Weline/Theme/etc/weline-ui-assets.json');
        foreach ([
            '"foundation"',
            '"backend"',
            '"frontend"',
            '"ui-core"',
            '"theme-prepaint"',
            '"theme.editor"',
            '"theme.editor.preview"',
        ] as $entry) {
            self::assertStringContainsString($entry, $manifest);
        }
        self::assertStringContainsString('"global_requests": 3', $manifest);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
