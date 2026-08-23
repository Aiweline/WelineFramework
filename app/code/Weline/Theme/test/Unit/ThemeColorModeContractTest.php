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
        // Empty [data-w-close] keeps the × fallback; icon-bearing buttons must not double-paint.
        self::assertStringContainsString('.w-button[data-w-close]:empty::before', $foundation);
        self::assertStringContainsString('.w-button[data-w-close]:has(.w-icon, w-icon, svg, img)::before { content: none; }', $foundation);
        self::assertStringNotContainsString('.w-button[data-w-close]::before { content: "\00d7"', $foundation);
        self::assertStringContainsString('.w-alert { display: grid; grid-template-columns: 1fr auto;', $foundation);
        self::assertStringContainsString('.w-alert:has(> .w-icon)', $foundation);
        self::assertStringContainsString('.w-alert > .w-button[data-w-close]', $foundation);

        self::assertStringContainsString('--backend-theme-sidebar-width:', $foundation);
        $backend = $this->read('app/code/Weline/Theme/view/ui/css/backend.css');
        self::assertStringContainsString('var(--backend-theme-sidebar-width)', $backend);
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
            "if (event.key === 'Tab') { close(false, 'tab'); return; }",
            'function floatingPortalContains(record, target, visited = new Set())',
            'function topmostDismissableFloatingPortal()',
            'function logicalUnmountScopes(root)',
            'isTopmost() { return topmostDismissableFloatingPortal() === record; }',
            'if (topOverlay() !== element) return;',
            'const dismissOnEscape = (event, immediate = false) => {',
            "record.name === 'tooltip'",
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
        self::assertStringContainsString("window.screen?.orientation?.addEventListener('change'", $runtime);

        $advanced = $this->read('app/code/Weline/Theme/view/ui/js/components/advanced.js');
        self::assertStringContainsString("floating.portal(panel, 'combobox')", $advanced);
        self::assertStringContainsString("floating.portal(panel, 'icon-picker')", $advanced);
        self::assertStringContainsString('portal.contains(event.target)', $advanced);
        self::assertStringContainsString("floating.capture(trigger, event, 'element')", $advanced);
        self::assertStringContainsString("close('escape', true)", $advanced);
        self::assertStringContainsString("close('pagehide', false, true)", $advanced);
        self::assertStringContainsString('[data-w-icon-custom]', $advanced);
        self::assertStringContainsString('[data-w-icon-apply]', $advanced);

        $aiModelSelect = $this->read('app/code/Weline/Ai/view/statics/js/ai-model-select.js');
        self::assertStringContainsString("floating.portal(panel, 'ai-model-select')", $aiModelSelect);

        $foundation = $this->read('app/code/Weline/Theme/view/ui/css/foundation.css');
        self::assertStringContainsString('.w-dialog:has(> [data-w-floating-portal])', $foundation);
        self::assertStringNotContainsString('.w-drawer:has(> [data-w-floating-portal])', $foundation);
        self::assertStringContainsString('[data-w-floating-portal][data-w-floating-positioned]', $foundation);
        self::assertStringContainsString('min-inline-size: min(12rem, var(--w-floating-max-inline-size', $foundation);
        self::assertStringContainsString('.w-button[data-size="sm"], .w-menu__item, .w-combobox__option { min-block-size: 2.75rem; }', $foundation);
        self::assertStringContainsString('.w-menu__item[data-tone="primary"]', $foundation);
        self::assertStringContainsString('.w-menu__item[data-tone="danger"]', $foundation);

        $toolbarOverflow = $this->read('app/code/Weline/Theme/view/statics/js/theme-editor-toolbar-overflow.js');
        self::assertStringContainsString('portal.isTopmost()', $toolbarOverflow);
    }

    public function testFrontendMobileNavigationUsesSharedResponsivePopoverContract(): void
    {
        $header = $this->read('app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml');
        foreach ([
            'data-w-component="popover"',
            'data-w-placement="bottom-end"',
            'data-w-anchor-mode="element"',
            'data-w-popover-trigger aria-expanded="false"',
            'data-w-popover-panel data-w-viewport-padding="12" data-state="closed"',
            'aria-hidden="true" hidden',
            'data-w-popover-close',
        ] as $contract) {
            self::assertStringContainsString($contract, $header);
        }
        self::assertStringNotContainsString('<details class="w-frontend-mobile-nav"', $header);

        $frontend = $this->read('app/code/Weline/Theme/view/ui/css/frontend.css');
        self::assertStringContainsString('inline-size: min(22rem, var(--w-floating-max-inline-size', $frontend);
        self::assertStringContainsString('.w-frontend-mobile-nav__body { display: grid;', $frontend);
        self::assertStringNotContainsString('.w-frontend-mobile-nav[open]', $frontend);
    }

    public function testNotificationMenuAlwaysStartsClosedAndUsesTheSharedElementAnchor(): void
    {
        $notification = $this->read('app/code/Weline/Backend/view/blocks/system/notification.phtml');
        self::assertStringContainsString('data-w-component="menu"', $notification);
        self::assertStringContainsString('data-w-anchor-mode="element"', $notification);
        self::assertStringContainsString('data-w-menu-panel data-state="closed"', $notification);
        self::assertStringContainsString('aria-hidden="true" hidden', $notification);
        self::assertStringContainsString('data-w-menu-close', $notification);

        $runtime = $this->read('app/code/Weline/Theme/view/ui/js/weline-ui.js');
        self::assertStringContainsString("open(event.detail === 0, recentPointer)", $runtime);
        self::assertStringContainsString("close(true, 'dismiss')", $runtime);
        self::assertStringContainsString("window.addEventListener('pagehide'", $runtime);
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
            self::assertSame(1, substr_count($frontend, 'colors/_light.css'));
            self::assertSame(1, substr_count($frontend, 'colors/_dark.css'));
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
        self::assertStringContainsString('.w-stat-tiles', $foundation);
        self::assertStringContainsString('.w-auto-grid', $foundation);
        self::assertStringContainsString('minmax(min(100%, var(--w-auto-grid-min, var(--w-stat-tile-min, 8rem))), 1fr)', $foundation);
        self::assertStringContainsString('.w-stat-tiles.w-grid > * { grid-column: auto; }', $foundation);
        self::assertStringContainsString('max-width: 100%', $foundation);
        self::assertMatchesRegularExpression('/\.w-container\s*\{[^}]*min-width:\s*0/s', $foundation);
    }

    public function testManifestOwnsCoreAndRouteBundlesExplicitly(): void
    {
        $manifest = $this->read('app/code/Weline/Theme/etc/weline-ui-assets.json');
        self::assertStringContainsString('"/Theme/view/statics/ui/"', $manifest);
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
        self::assertStringContainsString('"datatable-form-js"', $manifest);
        self::assertMatchesRegularExpression('/"datatable-js"\s*:\s*\["data-table"\]/', $manifest);
        self::assertMatchesRegularExpression('/"datatable-form-js"\s*:\s*\["data-table-form"\]/', $manifest);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
