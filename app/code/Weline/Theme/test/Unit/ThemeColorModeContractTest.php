<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeColorModeContractTest extends TestCase
{
    public function testGlobalRuntimeAndAssetOrderUseTheThreeStateContract(): void
    {
        $frontendRuntime = $this->read('app/code/Weline/Theme/view/theme/frontend/assets/js/theme.js');
        self::assertStringContainsString("storageKey: 'weline-theme'", $frontendRuntime);
        self::assertStringContainsString("return theme === 'system' || theme === 'light' || theme === 'dark';", $frontendRuntime);
        self::assertStringContainsString("detail: { theme: resolved, preference: preference }", $frontendRuntime);
        self::assertStringContainsString('getPreference: function ()', $frontendRuntime);
        self::assertStringContainsString('isBackendDocument: function ()', $frontendRuntime);
        self::assertStringContainsString('function show(options, legacyType, durationOrOptions)', $frontendRuntime);
        self::assertStringContainsString("return show(message, 'success', durationOrOptions);", $frontendRuntime);
        self::assertStringContainsString("closeButton.setAttribute('aria-label', closeText);", $frontendRuntime);
        self::assertStringNotContainsString("aria-label=\"' + closeText", $frontendRuntime);
        self::assertStringContainsString('_initialized: false', $frontendRuntime);
        self::assertStringContainsString("[data-weline-theme-mode]", $frontendRuntime);
        self::assertStringContainsString('function readPrepaintThemeState()', $frontendRuntime);
        self::assertStringContainsString('const prepaintThemeState = readPrepaintThemeState();', $frontendRuntime);
        self::assertStringContainsString('current: prepaintThemeState.current', $frontendRuntime);
        self::assertStringContainsString('preference: prepaintThemeState.preference', $frontendRuntime);

        $frontendCss = $this->read('app/code/Weline/Theme/view/theme/frontend/assets/css/theme.css');
        self::assertStringContainsString('--bs-card-bg: var(--weline-theme-surface);', $frontendCss);
        self::assertStringContainsString('.badge', $frontendCss);
        self::assertStringContainsString('.bs-popover-top > .popover-arrow', $frontendCss);
        self::assertStringContainsString('.offcanvas', $frontendCss);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $frontendCss);
        self::assertSame(1, substr_count($frontendCss, 'Global Bootstrap adapter (the only component layer'));
        self::assertSame(0, substr_count($frontendCss, '--weline-theme-text-primary'));
        self::assertSame(0, substr_count($frontendCss, '--weline-theme-primary-contrast'));
        self::assertStringContainsString('--weline-theme-success-text-emphasis:', $frontendCss);
        self::assertStringContainsString('--weline-theme-secondary-subtle: var(--color-secondary-bg-subtle, var(--weline-theme-surface-subtle));', $frontendCss);
        self::assertStringContainsString('--weline-theme-secondary-border-subtle: var(--color-secondary-border-subtle, var(--weline-theme-border-color-subtle));', $frontendCss);
        self::assertStringContainsString('--weline-theme-secondary-text-emphasis: var(--color-secondary-text-emphasis, var(--weline-theme-secondary));', $frontendCss);
        self::assertStringContainsString('--weline-theme-secondary-rgb: var(--color-secondary-rgb, 71, 85, 105);', $frontendCss);
        self::assertStringContainsString('--bs-secondary: var(--weline-theme-secondary);', $frontendCss);
        self::assertStringContainsString('--bs-secondary-rgb: var(--weline-theme-secondary-rgb);', $frontendCss);
        self::assertStringNotContainsString('--bs-secondary-rgb: var(--weline-theme-secondary-rgb,', $frontendCss);
        self::assertStringContainsString('--bs-secondary-text-emphasis: var(--weline-theme-secondary-text-emphasis);', $frontendCss);
        self::assertStringContainsString('--bs-secondary-bg-subtle: var(--weline-theme-secondary-subtle);', $frontendCss);
        self::assertStringContainsString('--bs-secondary-border-subtle: var(--weline-theme-secondary-border-subtle);', $frontendCss);
        self::assertStringContainsString('.alert { --bs-alert-color: var(--weline-theme-primary-text-emphasis); --bs-alert-bg: var(--weline-theme-primary-subtle); --bs-alert-border-color: var(--weline-theme-primary); }', $frontendCss);
        foreach (['primary', 'secondary', 'success', 'warning', 'danger', 'info'] as $variant) {
            self::assertStringContainsString('.alert-' . $variant . ', .w-alert-' . $variant . ' { color: var(--weline-theme-' . $variant . '-text-emphasis); background-color: var(--weline-theme-' . $variant . '-subtle); border-color: var(--weline-theme-' . $variant . '); }', $frontendCss);
        }
        self::assertStringContainsString('.alert .alert-link, .w-alert .alert-link { color: inherit !important; }', $frontendCss);
        self::assertStringContainsString('--bs-success-border-subtle:', $frontendCss);
        self::assertStringContainsString('--bs-heading-color: var(--weline-theme-text);', $frontendCss);
        self::assertStringContainsString('--bs-body-color-rgb: var(--weline-theme-text-rgb);', $frontendCss);
        self::assertStringContainsString('--bs-body-bg-rgb: var(--weline-theme-surface-canvas-rgb);', $frontendCss);
        self::assertStringContainsString('--bs-emphasis-color-rgb: var(--weline-theme-emphasis-rgb);', $frontendCss);
        self::assertStringContainsString('--bs-border-color-translucent: var(--weline-theme-border-color-subtle);', $frontendCss);
        self::assertStringContainsString('--bs-form-valid-color: var(--weline-theme-success-text-emphasis);', $frontendCss);
        self::assertStringContainsString('.form-control.is-valid', $frontendCss);
        self::assertStringContainsString('.valid-tooltip', $frontendCss);
        self::assertStringContainsString('color: var(--weline-theme-on-success);', $frontendCss);
        self::assertStringContainsString('background-color: var(--weline-theme-success);', $frontendCss);
        self::assertStringContainsString('box-shadow: var(--weline-theme-success-focus-ring);', $frontendCss);
        self::assertStringContainsString('.invalid-tooltip', $frontendCss);
        self::assertStringContainsString('color: var(--weline-theme-on-danger);', $frontendCss);
        self::assertStringContainsString('background-color: var(--weline-theme-danger);', $frontendCss);
        self::assertStringContainsString('--bs-danger-rgb: var(--weline-theme-danger-rgb);', $frontendCss);
        self::assertStringContainsString('--weline-theme-success-focus-ring: var(--success-focus-ring, 0 0 0 var(--weline-theme-focus-ring-width) var(--weline-theme-success-focus-ring-color));', $frontendCss);
        self::assertStringContainsString('--weline-theme-danger-focus-ring: var(--danger-focus-ring, 0 0 0 var(--weline-theme-focus-ring-width) var(--weline-theme-danger-focus-ring-color));', $frontendCss);
        self::assertDoesNotMatchRegularExpression('/--weline-theme-(?:success|danger)-focus-ring:\s*[^;]*color-mix/', $frontendCss);
        self::assertMatchesRegularExpression('/\.w-badge-secondary,.*?color: var\(--weline-theme-secondary-text-emphasis\);/s', $frontendCss);
        self::assertStringContainsString('html[data-theme-preference="system"] { color-scheme: dark; }', $frontendCss);
        self::assertStringContainsString('--bs-warning-border-subtle:', $frontendCss);
        self::assertStringContainsString('--bs-danger-border-subtle:', $frontendCss);
        self::assertStringContainsString('--bs-info-border-subtle:', $frontendCss);
        $frontendAdapter = substr($frontendCss, (int)strpos($frontendCss, 'Global Bootstrap adapter (the only component layer'));
        self::assertStringNotContainsString('var(--color-', $frontendAdapter);
        foreach ([
            'primary' => ['--weline-theme-primary-text-emphasis', '--weline-theme-primary-subtle'],
            'secondary' => ['--weline-theme-secondary-text-emphasis', '--weline-theme-secondary-subtle'],
            'success' => ['--weline-theme-success-text-emphasis', '--weline-theme-success-subtle'],
            'warning' => ['--weline-theme-warning-text-emphasis', '--weline-theme-warning-subtle'],
            'danger' => ['--weline-theme-danger-text-emphasis', '--weline-theme-danger-subtle'],
            'info' => ['--weline-theme-info-text-emphasis', '--weline-theme-info-subtle'],
        ] as $variant => [$text, $background]) {
        self::assertStringContainsString('.badge.badge-' . $variant . ' { color: var(' . $text . ') !important; background-color: var(' . $background . ') !important; }', $frontendAdapter);
        }
        self::assertStringContainsString('/* Weline button variant state adapter:', $frontendCss);
        self::assertStringContainsString(':is(.w-btn-success, .w-button-success, .button-success):hover', $frontendCss);
        self::assertStringContainsString('background-color: var(--weline-theme-success-hover);', $frontendCss);
        self::assertStringContainsString(':is(.w-btn-success, .w-button-success, .button-success):active', $frontendCss);
        self::assertStringContainsString('background-color: var(--weline-theme-success-active);', $frontendCss);
        self::assertStringContainsString('--weline-component-scrollbar-thumb:', $frontendCss);
        self::assertStringContainsString('background: var(--weline-component-scrollbar-thumb);', $frontendCss);
        self::assertStringContainsString('box-shadow: var(--weline-component-shadow-lg);', $frontendCss);
        self::assertStringNotContainsString('--weline-component-shadow-lg,', $frontendCss);

        $backendCss = $this->read('app/code/Weline/Theme/view/theme/backend/assets/css/theme.css');
        self::assertSame(1, substr_count($backendCss, 'Global Bootstrap 5.1 adapter (Backend)'));
        self::assertStringContainsString('--backend-topbar-bg:', $backendCss);
        self::assertStringContainsString('--backend-theme-secondary-subtle: var(--backend-color-secondary-bg-subtle, var(--backend-theme-surface-subtle));', $backendCss);
        self::assertStringContainsString('--backend-theme-secondary-border-subtle: var(--backend-color-secondary-border-subtle, var(--backend-theme-border-color-subtle));', $backendCss);
        self::assertStringContainsString('--backend-theme-secondary-text-emphasis: var(--backend-color-secondary-text-emphasis, var(--backend-theme-secondary));', $backendCss);
        self::assertStringContainsString('--backend-theme-secondary-rgb: var(--backend-color-secondary-rgb, 71, 85, 105);', $backendCss);
        self::assertStringContainsString('--bs-secondary: var(--backend-theme-secondary);', $backendCss);
        self::assertStringContainsString('--bs-secondary-rgb: var(--backend-theme-secondary-rgb);', $backendCss);
        self::assertStringNotContainsString('--bs-secondary-rgb: var(--backend-theme-secondary-rgb,', $backendCss);
        self::assertStringContainsString('--bs-secondary-text-emphasis: var(--backend-theme-secondary-text-emphasis);', $backendCss);
        self::assertStringContainsString('--bs-secondary-bg-subtle: var(--backend-theme-secondary-subtle);', $backendCss);
        self::assertStringContainsString('--bs-secondary-border-subtle: var(--backend-theme-secondary-border-subtle);', $backendCss);
        self::assertStringContainsString('.alert { --bs-alert-color: var(--backend-theme-primary-text-emphasis); --bs-alert-bg: var(--backend-theme-primary-subtle); --bs-alert-border-color: var(--backend-theme-primary); }', $backendCss);
        foreach (['primary', 'secondary', 'success', 'warning', 'danger', 'info'] as $variant) {
            self::assertStringContainsString('.w-alert-' . $variant, $backendCss);
            self::assertStringContainsString('.alert-' . $variant . ', .w-alert-' . $variant . ' { color: var(--backend-theme-' . $variant . '-text-emphasis); background-color: var(--backend-theme-' . $variant . '-subtle); border-color: var(--backend-theme-' . $variant . '); }', $backendCss);
        }
        self::assertStringContainsString('.alert .alert-link, .w-alert .alert-link { color: inherit !important; }', $backendCss);
        self::assertStringContainsString('--bs-success-border-subtle:', $backendCss);
        self::assertStringContainsString('--bs-heading-color: var(--backend-theme-text);', $backendCss);
        self::assertStringContainsString('--bs-body-color-rgb: var(--backend-theme-text-rgb);', $backendCss);
        self::assertStringContainsString('--bs-body-bg-rgb: var(--backend-theme-surface-canvas-rgb);', $backendCss);
        self::assertStringContainsString('--bs-emphasis-color-rgb: var(--backend-theme-emphasis-rgb);', $backendCss);
        self::assertStringContainsString('--bs-border-color-translucent: var(--backend-theme-border-color-subtle);', $backendCss);
        self::assertStringContainsString('--bs-form-invalid-color: var(--backend-theme-danger-text-emphasis);', $backendCss);
        self::assertStringContainsString('.form-control.is-valid', $backendCss);
        self::assertStringContainsString('.form-control.is-invalid', $backendCss);
        self::assertStringContainsString('color: var(--backend-theme-on-success);', $backendCss);
        self::assertStringContainsString('background-color: var(--backend-theme-success);', $backendCss);
        self::assertStringContainsString('color: var(--backend-theme-on-danger);', $backendCss);
        self::assertStringContainsString('background-color: var(--backend-theme-danger);', $backendCss);
        self::assertStringContainsString('box-shadow: var(--backend-theme-success-focus-ring);', $backendCss);
        self::assertStringContainsString('box-shadow: var(--backend-theme-danger-focus-ring);', $backendCss);
        self::assertStringContainsString('--bs-success-rgb: var(--backend-theme-success-rgb);', $backendCss);
        self::assertStringContainsString('html[data-theme-preference="system"] { color-scheme: dark; }', $backendCss);
        $backendAdapter = substr($backendCss, (int)strpos($backendCss, 'Global Bootstrap 5.1 adapter (Backend)'));
        self::assertStringNotContainsString('var(--backend-color-', $backendAdapter);
        foreach ([
            'primary' => ['--backend-theme-primary-text-emphasis', '--backend-theme-primary-subtle'],
            'secondary' => ['--backend-theme-secondary-text-emphasis', '--backend-theme-secondary-subtle'],
            'success' => ['--backend-theme-success-text-emphasis', '--backend-theme-success-subtle'],
            'warning' => ['--backend-theme-warning-text-emphasis', '--backend-theme-warning-subtle'],
            'danger' => ['--backend-theme-danger-text-emphasis', '--backend-theme-danger-subtle'],
            'info' => ['--backend-theme-info-text-emphasis', '--backend-theme-info-subtle'],
        ] as $variant => [$text, $background]) {
            self::assertStringContainsString('.badge.badge-' . $variant . ' { color: var(' . $text . ') !important; background-color: var(' . $background . ') !important; }', $backendAdapter);
        }
        $backendComponentOffset = strpos($backendCss, '/* 后端组件层：只消费');
        self::assertNotFalse($backendComponentOffset);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}|\brgba?\(/', substr($backendCss, (int)$backendComponentOffset));
        self::assertStringContainsString('--backend-theme-primary-gradient: linear-gradient(135deg, var(--backend-theme-primary) 0%, var(--backend-theme-primary-hover) 100%);', $backendCss);
        self::assertStringContainsString('/* Weline/Admin button variant state adapter:', $backendCss);
        self::assertStringContainsString(':is(.w-btn-primary, .w-admin-btn-primary, .w-button-primary, .button-primary, .admin-btn-primary, .btn-primary)', $backendCss);
        self::assertStringContainsString('background-image: var(--backend-theme-primary-hover-gradient);', $backendCss);
        self::assertStringContainsString('background-image: var(--backend-theme-primary-active-gradient);', $backendCss);
        self::assertStringContainsString('.btn-primary:disabled, .btn-primary.disabled { color: var(--backend-theme-text-muted); background-color: var(--backend-theme-surface-muted); background-image: none;', $backendCss);
        self::assertStringContainsString(':is(.w-btn-success, .w-admin-btn-success, .w-button-success, .button-success, .admin-btn-success):hover', $backendCss);
        self::assertStringContainsString('background-color: var(--backend-theme-success-hover);', $backendCss);

        $frontendHead = $this->read('app/code/Weline/Theme/view/theme/frontend/partials/head/default.phtml');
        self::assertStringContainsString("data-theme-preference', preference", $frontendHead);
        self::assertStringContainsString("frontend/colors/_light.css", $frontendHead);
        self::assertStringContainsString("frontend/assets/css/theme.css", $frontendHead);
        self::assertStringContainsString('<theme:css>Weline_Theme::theme/frontend/assets/css/theme.css</theme:css>', $frontendHead);
        self::assertStringContainsString('<theme:js>Weline_Theme::theme/frontend/assets/js/theme.js</theme:js>', $frontendHead);
        self::assertStringNotContainsString('theme/{{area}}/assets/css/theme.css', $frontendHead);
        self::assertLessThan(
            strpos($frontendHead, "data-theme-preference', preference"),
            strpos($frontendHead, 'frontend/colors/_light.css')
        );
        self::assertLessThan(
            strpos($frontendHead, 'Weline_Theme::theme/frontend/colors/_light.css'),
            strpos($frontendHead, 'Weline_Theme::frontend::partials::head::styles-after')
        );
        self::assertLessThan(
            strpos($frontendHead, 'Weline_Theme::frontend::partials::head::styles-after'),
            strpos($frontendHead, 'Weline_Theme::theme/frontend/colors/_dark.css')
        );
        self::assertLessThan(
            strpos($frontendHead, 'Weline_Theme::theme/frontend/colors/_dark.css'),
            strpos($frontendHead, 'frontend/assets/css/theme.css')
        );

        $minimalHead = $this->read('app/code/Weline/Theme/view/theme/frontend/partials/head/minimal.phtml');
        self::assertStringContainsString("data-theme-preference', preference", $minimalHead);
        self::assertStringContainsString("frontend/assets/js/theme.js", $minimalHead);
        self::assertStringContainsString('<theme:js>Weline_Theme::theme/frontend/assets/js/theme.js</theme:js>', $minimalHead);
        self::assertStringContainsString('window.Toast = ThemeNotice;', $frontendRuntime);
        self::assertStringContainsString('typeof window.Toast.success !== \'function\'', $frontendRuntime);
        self::assertStringContainsString('Weline.Theme.apply(themeColor, false);', $frontendRuntime);
        self::assertStringContainsString('window.Weline.UI.toast = Weline.UI.toast;', $this->read('app/code/Weline/Theme/view/theme/backend/assets/js/backend-components.js'));
        self::assertStringContainsString('typeof window.Weline.UI.toast.success !== \'function\'', $this->read('app/code/Weline/Theme/view/theme/backend/assets/js/backend-components.js'));
        $backendComponents = $this->read('app/code/Weline/Theme/view/theme/backend/assets/js/backend-components.js');
        self::assertStringContainsString('window.__WelineBackendComponentsRuntime', $backendComponents);
        self::assertStringNotContainsString('var(--backend-color-', $backendComponents);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}|rgba\\(/', $backendComponents);
        $backendRuntime = $this->read('app/code/Weline/Theme/view/theme/backend/assets/js/theme.js');
        self::assertStringContainsString('window.__WelineBackendThemeRuntime', $backendRuntime);
        self::assertStringContainsString("root.getAttribute('data-theme-preference') === 'system'", $backendRuntime);
        self::assertDoesNotMatchRegularExpression('/var\\(--backend-color-|#[0-9a-fA-F]{3,8}|rgba\\(/', $backendRuntime);
        self::assertStringNotContainsString('var(--color-', $frontendRuntime);

        $visualEditor = $this->read('app/code/Weline/Theme/view/templates/backend/config/visual-editor.phtml');
        self::assertStringContainsString('data-theme-area="backend"', $visualEditor);
        self::assertStringContainsString('BackendThemeConfigInterface::class', $visualEditor);
        self::assertStringContainsString('getThemeHtmlAttributes()', $visualEditor);

        $visualEditorCss = $this->read('app/code/Weline/Theme/view/statics/css/visual-editor.css');
        self::assertStringContainsString('--ve-bg-color: var(--backend-theme-surface-canvas);', $visualEditorCss);
        self::assertStringNotContainsString('[data-theme="dark"]', $visualEditorCss);
        self::assertStringContainsString('Device/content preview simulation', $visualEditorCss);

        $themeEditorTemplate = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');
        self::assertStringContainsString('--te-bg-dark: var(--backend-theme-surface-canvas);', $themeEditorTemplate);
        self::assertStringNotContainsString('body[data-topbar="dark"]', $themeEditorTemplate);
        self::assertStringNotContainsString('body[data-sidebar="dark"]', $themeEditorTemplate);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}|\\brgba\\(/', $themeEditorTemplate);
        preg_match('/<style>.*?:root\\s*\\{(?<tokens>.*?)\\n\\}/s', $themeEditorTemplate, $themeEditorRoot);
        self::assertNotEmpty($themeEditorRoot['tokens'] ?? '');
        preg_match_all('/^\\s*(--te-[a-z0-9-]+)\\s*:/m', $themeEditorTemplate, $themeEditorDefinitions);
        preg_match_all('/var\\((--te-[a-z0-9-]+)\\b/', $themeEditorTemplate, $themeEditorReferences);
        foreach (array_unique($themeEditorReferences[1]) as $themeEditorToken) {
            self::assertContains($themeEditorToken, $themeEditorDefinitions[1], $themeEditorToken . ' must be defined by the Theme Editor template');
        }

        $widgetParamCss = $this->read('app/code/Weline/Widget/view/statics/css/widget-param-types.css');
        self::assertStringContainsString('--w-param-surface: var(--backend-theme-surface, var(--weline-theme-surface));', $widgetParamCss);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}|\brgba?\(/', $widgetParamCss);
        foreach (['.w-param-form .w-param-field-header { margin-bottom: .5rem; }', '.w-param-form .w-param-input-group { align-items: stretch; }', '.w-param-form .w-param-form-check input[type="checkbox"] { width: 1.125rem; height: 1.125rem; }', '.w-param-form .w-param-image-hint { margin-top: .25rem; }', '.w-param-form .w-param-array-item { transition: background-color .15s ease; }', '.w-param-form .w-param-array-actions { padding: .75rem; border-top: 1px solid var(--w-param-border); }', '.w-param-form .w-param-i18n-row { gap: .75rem; }'] as $widgetBehavior) {
            self::assertStringContainsString($widgetBehavior, $widgetParamCss);
        }
        self::assertStringContainsString("'1.0.1'", $this->read('app/code/Weline/Widget/register.php'));
        self::assertStringContainsString("'1.0.1'", $this->read('app/code/Weline/Widget/etc/module.php'));
        self::assertStringContainsString('同步 `etc/module.php` 至 `1.0.1`', $this->read('app/code/Weline/Widget/doc/开发/task.md'));

        $frontendE2e = $this->read('app/code/Weline/Theme/test/e2e/frontend/theme-color-mode.spec.js');
        $backendE2e = $this->read('app/code/Weline/Theme/test/e2e/backend/theme-color-mode.spec.js');
        foreach ([$frontendE2e, $backendE2e] as $e2e) {
            self::assertStringContainsString('data-state="valid-input"', $e2e);
            self::assertStringContainsString('data-state="invalid-input"', $e2e);
            self::assertStringContainsString('data-state="valid-feedback"', $e2e);
            self::assertStringContainsString('data-state="invalid-feedback"', $e2e);
            self::assertStringContainsString('data-state="valid-tooltip"', $e2e);
            self::assertStringContainsString('data-state="invalid-tooltip"', $e2e);
            self::assertStringContainsString("for (const state of ['valid', 'invalid'])", $e2e);
            self::assertStringContainsString("document.addEventListener('themechange'", $e2e);
            self::assertStringNotContainsString("window.addEventListener('themechange'", $e2e);
            self::assertStringContainsString("toHaveCSS('box-shadow', /rgba?\\(/)", $e2e);
            self::assertStringContainsString('tooltipTextContrast(state)', $e2e);
            self::assertStringContainsString('themechangeCount', $e2e);
            self::assertStringContainsString(".nav-tabs .nav-link.active', '.pagination .page-link", $e2e);
            self::assertStringContainsString('ComponentColors', $e2e);
            self::assertStringContainsString('legacy-success-badge', $e2e);
            self::assertStringContainsString('legacy-secondary-badge', $e2e);
            self::assertStringContainsString('weline-success-button', $e2e);
            self::assertStringContainsString('data-state="alert-primary"', $e2e);
            self::assertStringContainsString('data-state="alert-secondary"', $e2e);
            self::assertStringContainsString('data-state="alert-success"', $e2e);
            self::assertStringContainsString('data-state="alert-warning"', $e2e);
            self::assertStringContainsString('data-state="alert-danger"', $e2e);
            self::assertStringContainsString('data-state="alert-info"', $e2e);
            self::assertStringContainsString("const alertStates = ['primary', 'secondary', 'success', 'warning', 'danger', 'info'];", $e2e);
            self::assertStringContainsString('const alertLink = alert.locator(\'.alert-link\');', $e2e);
            self::assertStringContainsString("legacyBadgeTextContrast('success')", $e2e);
            self::assertStringContainsString("legacyBadgeTextContrast('secondary')", $e2e);
            self::assertStringContainsString("'.badge.badge-success', '.badge.badge-secondary'", $e2e);
            self::assertStringContainsString('const ancestorBackgrounds = [];', $e2e);
            self::assertStringContainsString('const composite = (foreground, background)', $e2e);
            self::assertStringContainsString('const badgeBackground = composite(parse(style.backgroundColor), surface);', $e2e);
            self::assertStringContainsString('const badgeText = composite(parse(style.color), badgeBackground);', $e2e);
            self::assertStringContainsString('const alertTextAndBorderContrast = async (state)', $e2e);
            self::assertStringContainsString('const alertBackground = composite(parse(style.backgroundColor), surface);', $e2e);
            self::assertStringContainsString('return { text: ratio(style.color), border: ratio(style.borderTopColor) };', $e2e);
            self::assertStringContainsString('const buttonActiveContrast = async (button)', $e2e);
            self::assertStringContainsString("style.backgroundImage.includes('linear-gradient')", $e2e);
            self::assertStringContainsString('style.backgroundImage.match(/rgba?\\([^)]*\\)/g)', $e2e);
            self::assertStringContainsString('const visibleBackgrounds = gradientStops.length > 0', $e2e);
            self::assertStringContainsString('return Math.min(...contrastRatios);', $e2e);
            self::assertStringContainsString('const matrixFocusOutlineContrast = async ()', $e2e);
            self::assertStringContainsString('const visibleOutline = composite(parse(getComputedStyle(element).outlineColor), surface);', $e2e);
            self::assertStringContainsString('await matrixFocusControl.focus();', $e2e);
            self::assertStringContainsString("toHaveCSS('outline-color', /rgba?\\(/)", $e2e);
            self::assertStringContainsString('expect(await matrixFocusOutlineContrast()).toBeGreaterThanOrEqual(3);', $e2e);
            self::assertStringContainsString("return style.backgroundImage !== 'none' ? style.backgroundImage : style.backgroundColor;", $e2e);
            self::assertStringContainsString('const activeBackground = await visibleButtonBackground();', $e2e);
            self::assertStringContainsString('expect(activeBackground).not.toBe(buttonBackground);', $e2e);
            self::assertGreaterThanOrEqual(4, substr_count($e2e, 'legacyBadgeTextContrast('));
            self::assertGreaterThanOrEqual(2, substr_count($e2e, 'for (const state of alertStates)'));
            self::assertGreaterThanOrEqual(2, substr_count($e2e, 'alertTextAndBorderContrast(state)'));
            self::assertGreaterThanOrEqual(2, substr_count($e2e, 'expect(alertContrast.text).toBeGreaterThanOrEqual(4.5);'));
            self::assertGreaterThanOrEqual(2, substr_count($e2e, 'expect(alertContrast.border).toBeGreaterThanOrEqual(3);'));
            self::assertGreaterThanOrEqual(2, substr_count($e2e, 'expect(await matrixFocusOutlineContrast()).toBeGreaterThanOrEqual(3);'));
            self::assertStringContainsString('/rgba?\\(/', $e2e);
        }
        self::assertGreaterThanOrEqual(2, substr_count($backendE2e, 'await installThemechangeCounter();'));
        self::assertGreaterThanOrEqual(2, substr_count($frontendE2e, 'for (const selector of coreSelectors)'));
        self::assertGreaterThanOrEqual(2, substr_count($backendE2e, 'for (const selector of coreSelectors)'));
        self::assertStringContainsString("const resetKey = '__weline_theme_e2e_storage_reset';", $frontendE2e);
        self::assertStringContainsString("sessionStorage.setItem(resetKey, '1');", $frontendE2e);
        self::assertGreaterThanOrEqual(3, substr_count($frontendE2e, "await page.reload({ waitUntil: 'domcontentloaded' });"));
        self::assertStringContainsString("await expect(page.locator('html')).toHaveAttribute('data-theme-preference', 'dark');", $frontendE2e);
        self::assertStringContainsString("await expect(page.locator('html')).toHaveAttribute('data-theme-preference', 'light');", $frontendE2e);
        self::assertStringContainsString("await expect(page.locator('html')).toHaveAttribute('data-theme-preference', 'system');", $frontendE2e);
        self::assertStringContainsString("resolver.style.backgroundColor = 'var(--backend-theme-surface)';", $backendE2e);
        self::assertStringContainsString('const realLightCard = await moduleCardSurface();', $backendE2e);
        self::assertStringContainsString('const realDarkCard = await moduleCardSurface();', $backendE2e);
        self::assertStringContainsString('expect(realDarkCard.card).not.toBe(realLightCard.card);', $backendE2e);

        $editorModeCss = $this->read('app/code/Weline/Theme/view/statics/css/editor-mode.css');
        self::assertStringContainsString('--editor-mode-surface: var(--weline-theme-surface);', $editorModeCss);
        self::assertStringNotContainsString('[data-theme="dark"]', $editorModeCss);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}|\brgba\(/', $editorModeCss);
    }

    public function testDefaultThemeIsTokenOnlyAndCoreAssetsAreWarmed(): void
    {
        self::assertFileDoesNotExist(BP . '/app/design/Weline/default/frontend/assets/css/theme.css');
        self::assertFileDoesNotExist(BP . '/app/design/Weline/default/frontend/assets/js/theme.js');
        self::assertFileDoesNotExist(BP . '/app/design/Weline/default/backend/assets/css/theme.css');
        self::assertFileDoesNotExist(BP . '/app/design/Weline/default/backend/assets/js/theme.js');
        self::assertFileExists(BP . '/app/design/Weline/default/frontend/colors/_light.css');
        self::assertFileExists(BP . '/app/design/Weline/default/frontend/colors/_dark.css');
        self::assertFileExists(BP . '/app/design/Weline/default/backend/colors/_light.css');
        self::assertFileExists(BP . '/app/design/Weline/default/backend/colors/_dark.css');

        foreach ([
            'app/design/Weline/default/frontend/colors/_light.css',
            'app/design/Weline/default/frontend/colors/_dark.css',
            'app/design/Weline/default/backend/colors/_light.css',
            'app/design/Weline/default/backend/colors/_dark.css',
        ] as $palette) {
            $content = $this->read($palette);
            self::assertStringNotContainsString('.card', $content);
            self::assertStringNotContainsString('.modal', $content);
            self::assertDoesNotMatchRegularExpression('/(^|\n)\s*(body|\.btn|\.form-control|@keyframes)\b/m', $content);
        }

        $warmup = $this->read('app/code/Weline/Theme/Observer/WorkerBootstrapWarmup.php');
        self::assertStringContainsString("frontend/assets/css/theme.css", $warmup);
        foreach (['frontend/variables/_colors.css', 'frontend/variables/_typography.css', 'frontend/variables/_spacing.css', 'frontend/variables/_borders.css', 'frontend/variables/_shadows.css', 'frontend/variables/_auth.css'] as $asset) {
            self::assertStringContainsString($asset, $warmup);
        }
        self::assertStringContainsString("frontend/colors/_light.css", $warmup);
        self::assertStringContainsString("frontend/colors/_dark.css", $warmup);
        self::assertStringContainsString("backend/variables/_borders.css", $warmup);
        self::assertStringContainsString("backend/variables/_shadows.css", $warmup);

        $resolver = $this->read('app/code/Weline/Theme/Helper/ThemePathResolver.php');
        self::assertStringContainsString('isCoreRuntimeAsset', $resolver);
        self::assertStringContainsString('dirname(__DIR__)', $resolver);
        foreach (["'frontend' . DS . 'assets' . DS . 'css' . DS . 'theme.css'", "'frontend' . DS . 'assets' . DS . 'js' . DS . 'theme.js'", "'backend' . DS . 'assets' . DS . 'css' . DS . 'theme.css'", "'backend' . DS . 'assets' . DS . 'js' . DS . 'theme.js'"] as $asset) {
            self::assertStringContainsString($asset, $resolver);
        }

        $loginHead = $this->read('app/code/Weline/Admin/view/templates/Login/head.phtml');
        self::assertStringContainsString("data-theme-preference', preference", $loginHead);
        self::assertStringContainsString("backend/assets/css/theme.css?v=", $loginHead);
        self::assertStringNotContainsString('<theme:css>Weline_Theme::theme/backend/assets/css/theme.css</theme:css>', $loginHead);
        self::assertLessThan(
            strpos($loginHead, "backend/assets/css/theme.css?v="),
            strpos($loginHead, '<theme:css>Weline_Theme::theme/backend/colors/_dark.css</theme:css>')
        );

        $backendHead = $this->read('app/code/Weline/Theme/view/theme/backend/partials/head/default.phtml');
        self::assertStringNotContainsString('__welineBackendThemeSystemListener', $backendHead);
        self::assertTrue(
            strpos($backendHead, 'backend/assets/js/theme.js') < strpos($backendHead, 'backend/assets/js/backend-components.js'),
            'Backend runtime must initialize before canonical components.'
        );
        self::assertTrue(
            strpos($backendHead, 'backend/colors/_light.css') < strpos($backendHead, 'Weline_Theme::theme/backend/colors/_light.css')
            && strpos($backendHead, 'Weline_Theme::theme/backend/colors/_light.css') < strpos($backendHead, 'backend/colors/_dark.css')
            && strpos($backendHead, 'backend/colors/_dark.css') < strpos($backendHead, 'Weline_Theme::theme/backend/colors/_dark.css'),
            'Palette loading must be canonical light → active light → canonical dark → active dark.'
        );
        self::assertLessThan(
            strpos($backendHead, "backend/assets/css/theme.css?v=' . \$themeAssetVersion"),
            strpos($backendHead, 'htmlspecialchars((string)$cssUrl')
        );

        $injector = $this->read('app/code/Weline/Theme/Helper/CssVariableInjector.php');
        self::assertStringContainsString('Weline Theme variables v2', $injector);
        self::assertStringContainsString('isLateSafeExplicitToken', $injector);
        self::assertStringContainsString("'--color-error'", $injector);
        self::assertStringContainsString("'--backend-color-gradient-'", $injector);
        self::assertStringContainsString("'--backend-color-btn-'", $injector);
        self::assertStringNotContainsString('getVariablesFromFiles($area, $theme)', substr($injector, (int)strpos($injector, 'private function collectVariables')));
        self::assertStringContainsString('replaceGeneratedVariablePreamble', $this->read('app/code/Weline/Theme/Helper/LayoutAssetsManager.php'));

        $frontendLight = $this->read('app/code/Weline/Theme/view/theme/frontend/colors/_light.css');
        $backendLight = $this->read('app/code/Weline/Theme/view/theme/backend/colors/_light.css');
        foreach (['--color-secondary-rgb', '--color-secondary-bg-subtle', '--color-secondary-border-subtle', '--color-secondary-text-emphasis'] as $token) {
            self::assertStringContainsString($token . ':', $frontendLight);
        }
        foreach (['--backend-color-secondary-rgb', '--backend-color-secondary-bg-subtle', '--backend-color-secondary-border-subtle', '--backend-color-secondary-text-emphasis'] as $token) {
            self::assertStringContainsString($token . ':', $backendLight);
        }

        $frontendDark = $this->read('app/code/Weline/Theme/view/theme/frontend/colors/_dark.css');
        [$frontendExplicitDark, $frontendSystemDark] = explode('/* JavaScript-free system fallback:', $frontendDark, 2);
        foreach (['--color-text-dark', '--color-text-light', '--color-text-light-secondary', '--color-bg-dark', '--color-bg-dark-secondary', '--color-bg-dark-tertiary', '--color-border-emphasis', '--color-border-light', '--color-accent', '--color-accent-hover', '--color-accent-light', '--color-accent-medium', '--color-accent-dark', '--color-error-hover', '--color-error-light', '--scrollbar-thumb', '--scrollbar-thumb-hover', '--scrollbar-track', '--color-text-rgb', '--color-bg-canvas-rgb', '--color-emphasis-rgb', '--color-secondary-rgb', '--color-secondary-bg-subtle', '--color-secondary-border-subtle', '--color-secondary-text-emphasis'] as $legacyToken) {
            self::assertSame($this->lastCustomPropertyValue($frontendExplicitDark, $legacyToken), $this->lastCustomPropertyValue($frontendSystemDark, $legacyToken), $legacyToken . ' must match explicit dark in the system fallback');
        }

        $backendDark = $this->read('app/code/Weline/Theme/view/theme/backend/colors/_dark.css');
        [$backendExplicitDark, $backendSystemDark] = explode('/* JavaScript-free system fallback:', $backendDark, 2);
        foreach (['--backend-color-border-focus', '--backend-color-input-focus-border', '--backend-color-sidebar-text', '--backend-color-header-text', '--backend-card-shadow', '--backend-dropdown-shadow', '--backend-modal-shadow', '--backend-header-shadow', '--backend-sidebar-shadow', '--backend-focus-ring', '--backend-color-text-rgb', '--backend-color-bg-canvas-rgb', '--backend-color-emphasis-rgb', '--backend-color-secondary-rgb', '--backend-color-secondary-bg-subtle', '--backend-color-secondary-border-subtle', '--backend-color-secondary-text-emphasis'] as $token) {
            self::assertSame($this->lastCustomPropertyValue($backendExplicitDark, $token), $this->lastCustomPropertyValue($backendSystemDark, $token), $token . ' must match explicit dark in the system fallback');
        }
        foreach (['--color-secondary-rgb', '--color-secondary-bg-subtle', '--color-secondary-border-subtle', '--color-secondary-text-emphasis'] as $token) {
            self::assertStringContainsString($token . ':', $frontendDark);
            self::assertNotSame($this->lastCustomPropertyValue($frontendLight, $token), $this->lastCustomPropertyValue($frontendExplicitDark, $token), $token . ' must change between light and dark');
        }
        foreach (['--backend-color-secondary-rgb', '--backend-color-secondary-bg-subtle', '--backend-color-secondary-border-subtle', '--backend-color-secondary-text-emphasis'] as $token) {
            self::assertStringContainsString($token . ':', $backendDark);
            self::assertNotSame($this->lastCustomPropertyValue($backendLight, $token), $this->lastCustomPropertyValue($backendExplicitDark, $token), $token . ' must change between light and dark');
        }

        $backendCss = $this->read('app/code/Weline/Theme/view/theme/backend/assets/css/theme.css');
        $componentOffset = strpos($backendCss, '/* 后端组件层：只消费');
        self::assertNotFalse($componentOffset);
        self::assertStringNotContainsString('var(--backend-color-', substr($backendCss, (int)$componentOffset));
    }

    public function testBackendDocumentRootsUseResolvedThemeAndSeparateAreaMarker(): void
    {
        foreach ([
            'login/default.phtml', 'dashboard/default.phtml', 'fullscreen/default.phtml', 'minimal/default.phtml', 'print/default.phtml',
            'default/1280.phtml', 'default/1440.phtml', 'default/blank.phtml', 'default/default.phtml',
        ] as $layout) {
            $content = $this->read('app/code/Weline/Theme/view/theme/backend/layouts/' . $layout);
            self::assertStringContainsString('data-theme-area="backend"', $content, $layout);
            self::assertStringContainsString('getThemeHtmlAttributes()', $content, $layout);
            self::assertStringNotContainsString('data-theme="backend"', $content, $layout);
        }

        $editor = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        self::assertStringContainsString('data-theme-area="backend"', $editor);
        self::assertStringContainsString('backend/assets/css/theme.css', $editor);
        self::assertStringContainsString('<script defer src="{$themeAssetBaseUrl}backend/assets/js/theme.js?v={$themeAssetVersion}"></script>', $editor);
        $previewShell = substr($editor, (int)strpos($editor, 'private function renderDashboardEditorPreviewShell'));
        self::assertLessThan(
            strpos($previewShell, '<script defer src="{$themeAssetBaseUrl}backend/assets/js/theme.js?v={$themeAssetVersion}"></script>'),
            strpos($previewShell, '<link rel="stylesheet" href="{$themeAssetBaseUrl}backend/assets/css/theme.css?v={$themeAssetVersion}">')
        );
        self::assertStringContainsString('window.__WelineBackendThemeRuntime', $this->read('app/code/Weline/Theme/view/theme/backend/assets/js/theme.js'));
        self::assertStringNotContainsString('background: #f5f7fb', $editor);

        $mediaTemplate = $this->read('app/code/Weline/MediaManager/view/templates/Backend/Manager/manager.phtml');
        self::assertStringContainsString('themePreference: \'{{$theme_preference', $mediaTemplate);
        self::assertStringNotContainsString("setAttribute('data-theme','backend')", $mediaTemplate);
        $mediaController = $this->read('app/code/Weline/MediaManager/Controller/Backend/Manager.php');
        self::assertStringContainsString("assign('theme_preference'", $mediaController);
        self::assertStringContainsString('resolveIframeThemeState', $mediaController);
        $mediaManagerRuntime = $this->read('app/code/Weline/MediaManager/view/statics/js/manager.js');
        self::assertStringContainsString('var backendThemeRuntime = window.__WelineBackendThemeRuntime;', $mediaManagerRuntime);
        self::assertStringContainsString('backendThemeRuntime.apply(themePreference);', $mediaManagerRuntime);
        self::assertLessThan(
            strpos($mediaManagerRuntime, "document.documentElement.setAttribute('data-theme-area', 'backend');"),
            strpos($mediaManagerRuntime, 'backendThemeRuntime.apply(themePreference);')
        );

        $docs = $this->read('app/code/Weline/DeveloperWorkspace/view/templates/Docs/index.phtml');
        self::assertStringContainsString('data-theme-area="backend"', $docs);
        self::assertStringContainsString("'area' => 'backend'", $docs);
        self::assertStringContainsString("backend/colors/_light.css?v=' . \$documentThemeAssetVersion", $docs);
        self::assertStringNotContainsString('--bg-color: var(--backend-color-', $docs);
        $docsGeneratedLayoutOffset = strpos($docs, 'htmlspecialchars($documentThemeCssUrl');
        $docsAdapterOffset = strpos($docs, "backend/assets/css/theme.css?v=' . \$documentThemeAssetVersion");
        self::assertTrue(
            $docsGeneratedLayoutOffset !== false
            && $docsAdapterOffset !== false
            && $docsGeneratedLayoutOffset < $docsAdapterOffset,
            'DeveloperWorkspace generated/layout overlays must load before the canonical backend adapter.'
        );

        $mediaThemeVars = $this->read('app/code/Weline/MediaManager/view/statics/css/backend-theme-vars.css');
        self::assertStringNotContainsString('[data-theme="dark"]', $mediaThemeVars);
        self::assertStringNotContainsString('--backend-color-primary:', $mediaThemeVars);

        $polish = $this->read('app/code/Weline/Admin/view/statics/css/modern-admin-polish.css');
        self::assertStringNotContainsString('[data-theme-mode="dark"]', $polish);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}|\brgba?\(/', $polish);
    }

    public function testLateVariableAndGeneratedPreambleBoundariesAreBehavioral(): void
    {
        $injectorReflection = new \ReflectionClass(\Weline\Theme\Helper\CssVariableInjector::class);
        $injector = $injectorReflection->newInstanceWithoutConstructor();
        $isSafe = $injectorReflection->getMethod('isLateSafeExplicitToken');
        $isSafe->setAccessible(true);
        self::assertTrue($isSafe->invoke($injector, '--color-error-hover'));
        self::assertTrue($isSafe->invoke($injector, '--backend-color-gradient-start'));
        self::assertTrue($isSafe->invoke($injector, '--backend-color-btn-primary'));
        self::assertFalse($isSafe->invoke($injector, '--color-bg-primary'));
        self::assertFalse($isSafe->invoke($injector, '--backend-color-text-primary'));

        $managerReflection = new \ReflectionClass(\Weline\Theme\Helper\LayoutAssetsManager::class);
        $manager = $managerReflection->newInstanceWithoutConstructor();
        $replace = $managerReflection->getMethod('replaceGeneratedVariablePreamble');
        $replace->setAccessible(true);
        $v2 = "/* Weline Theme variables v2: explicit non-palette tokens only */\n:root {\n/* ========== 颜色 ========== */\n--color-primary: value;\n}\n";
        $layoutCss = '.layout { display: grid; }';
        self::assertSame($v2 . $layoutCss, $replace->invoke($manager, ":root {\n/* ========== 颜色 ========== */\n--color-primary: old;\n}\n" . $layoutCss, $v2));
        self::assertSame($v2 . ':root { --authored: keep; }' . $layoutCss, $replace->invoke($manager, ':root { --authored: keep; }' . $layoutCss, $v2));
        self::assertSame($v2 . ':root { --authored: keep; }' . $layoutCss, $replace->invoke($manager, "/* Weline Theme variables v2: explicit non-palette tokens only */\n:root { --authored: keep; }" . $layoutCss, $v2));
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }

    private function lastCustomPropertyValue(string $css, string $property): string
    {
        preg_match_all('/' . preg_quote($property, '/') . '\\s*:\\s*([^;]+);/', $css, $matches);
        self::assertNotEmpty($matches[1] ?? [], $property . ' must be declared');

        return trim($matches[1][array_key_last($matches[1])]);
    }
}
