<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

final class ThemeEditorUiCapabilityContractTest extends TestCase
{
    public function testUiMigrationKeepsTheFullThemeEditorCapabilitySurface(): void
    {
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');

        foreach ([
            'function initSidePanels(',
            'function switchPreviewView(',
            'function loadLayoutPreview(',
            'function fetchLayoutSlots(',
            'function loadLayoutConfig(',
            'function saveLayoutConfig(',
            'function deferWidgetLibraryLoad(',
            'function loadWidgetLibrary(',
            'function applyDefaultInjection(',
            'function handleDragStart(',
            'function handleDrop(',
            'function resolveSelectedWidgetInnerSlot(',
            'function initWidgetSortable(',
            'function persistSlotSortOrder(',
            'function generateWidgetConfigForm(',
            'function renderConfigFormWithBackend(',
            'function initWidgetParamPickers(',
            'data-w-component="reorder-list"',
            "'weline:ui:reorder-list:change'",
            'function saveWidgetConfig(',
            'function openComponentPreviewModal(',
            'function fetchInstalledLocales(',
            'function loadI18nValues(',
            'function loadVirtualThemeAiCatalog(',
            'function getThemeWidgetAiContext(',
            'function loadVersions(',
            'function handleRestoreLayout(',
            'function publishTheme(',
            'function initializeEditorLock(',
        ] as $capability) {
            self::assertStringContainsString($capability, $editor, $capability);
        }

        self::assertStringContainsString('const EditorApi = Weline.Theme.Editor', $editor);
        self::assertStringContainsString('placeWidgetFromProvider,', $editor);
        self::assertStringContainsString('publishEmbeddedLayout,', $editor);
        self::assertStringNotContainsString('window.scrollToSlot =', $editor);
        self::assertStringNotContainsString('window.WidgetParamTypesInit', $editor);
        self::assertStringNotContainsString("handle.addEventListener('mousedown'", $editor);
    }

    public function testTemplateRetainsEveryProductWorkspaceAndEndpoint(): void
    {
        $template = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');

        foreach ([
            'id="themeEditor"',
            'id="configPanel"',
            'id="previewFrame"',
            'id="widgetPanel"',
            'id="versionPanel"',
            'id="widgetConfigModal"',
            'id="componentPreviewModal"',
            'id="themeDiskAppearanceModal"',
            'data-api-save-widget=',
            'data-api-update-config=',
            'data-api-default-injections=',
            'data-api-layout-config=',
            'data-api-versions=',
            'data-api-restore-original=',
            'data-api-publish=',
            'data-api-check-lock=',
            'data-api-theme-tokens=',
            'data-api-disk-save=',
        ] as $contract) {
            self::assertStringContainsString($contract, $template, $contract);
        }

        self::assertStringContainsString('data-w-component="dialog"', $template);
        self::assertStringContainsString('data-w-component="drawer"', $template);
        self::assertSame(3, substr_count($template, 'data-w-component="toolbar-overflow"'));
        self::assertStringContainsString('data-w-component="popover"', $template);
        self::assertStringContainsString('data-w-popover-panel data-state="closed"', $template);
        self::assertStringContainsString('data-w-popover-close', $template);
        self::assertStringNotContainsString('theme-editor-toolbar-overflow.css', $template);
        self::assertDoesNotMatchRegularExpression('/<script[^>]+theme-editor-toolbar-overflow\.js/', $template);
        self::assertStringNotContainsString('data-bs-', $template);
        self::assertStringNotContainsString('$this->fetchTagHtml(', $template);
        self::assertDoesNotMatchRegularExpression(
            '/<\?php\s+(?:if|elseif|else|endif|foreach|endforeach|for|endfor|while|endwhile)\b/',
            $template
        );

        $widgetItem = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/widget-item.phtml');
        self::assertStringContainsString('IconRegistry::class', $widgetItem);
        self::assertDoesNotMatchRegularExpression('/<(?:i|span)\s+class="(?:mdi|ri-|fa)/', $widgetItem);
        self::assertDoesNotMatchRegularExpression(
            '/<\?php\s+(?:if|elseif|else|endif|foreach|endforeach|for|endfor|while|endwhile)\b/',
            $widgetItem
        );
    }

    public function testToolbarOverflowUsesTheSharedFloatingLifecycleWithoutAGlobalAlias(): void
    {
        $overflow = $this->read('app/code/Weline/Theme/view/statics/js/theme-editor-toolbar-overflow.js');

        self::assertStringContainsString("UI.define('toolbar-overflow'", $overflow);
        self::assertStringContainsString("floating.portal(menu, 'toolbar-overflow')", $overflow);
        self::assertStringContainsString("floating.capture(trigger, event, 'element')", $overflow);
        self::assertStringContainsString("close('escape', true)", $overflow);
        self::assertStringContainsString("close('pagehide', false, true)", $overflow);
        self::assertStringContainsString('Math.abs(root.offsetTop - left.offsetTop) > 1', $overflow);
        self::assertStringContainsString('return Math.max(96, Math.floor(parentWidth));', $overflow);
        self::assertStringNotContainsString('window.WelineThemeEditorToolbarOverflow', $overflow);
        self::assertFileDoesNotExist(BP . '/app/code/Weline/Theme/view/statics/css/theme-editor-toolbar-overflow.css');
    }

    public function testVisualPreviewDragDropKeepsInsideBeforeAndAfterFeedback(): void
    {
        $engine = $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js');
        $styles = $this->read('app/code/Weline/Theme/view/statics/css/editor-mode.css');

        foreach ([
            'function readDragWidgetData(',
            'function showIframeDropFeedback(',
            'function clearIframeDropFeedback(',
            'data-w-drop-position',
            "postPreviewMessage('widget-dropped'",
            "window.addEventListener('message'",
            'event.origin !== window.location.origin',
            'let activeDropSlot = null',
            "this.dataset.wslotMultiple !== 'false'",
            '--w-theme-preview-drop-feedback-left',
            '--w-theme-preview-drop-feedback-top',
        ] as $contract) {
            self::assertStringContainsString($contract, $engine, $contract);
        }

        foreach ([
            '.w-theme-preview-drop-feedback',
            '.w-theme-preview-drop-target',
            '.w-theme-preview-drop-before',
            '.w-theme-preview-drop-after',
            '@media (prefers-reduced-motion: reduce)',
        ] as $contract) {
            self::assertStringContainsString($contract, $styles, $contract);
        }

        self::assertStringNotContainsString("postMessage({\n                        type: 'widget-dropped'", $engine);

        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        self::assertStringContainsString("data.source !== 'weline-theme-preview'", $editor);
        self::assertStringContainsString("slot.multiple === false || slot.multiple === 'false'", $editor);
    }

    public function testWidgetAndAppearanceExtensionsUseNamespacedApisWithoutDroppingFeatures(): void
    {
        $params = $this->read('app/code/Weline/Widget/view/statics/js/widget-param-types.js');
        self::assertStringContainsString('window.Weline.Widget.Params', $params);
        self::assertStringContainsString('window.Weline.Widget.AI', $params);
        self::assertStringContainsString('openMediaManagerDialog(', $params);
        self::assertStringContainsString('generateWidget()', $params);
        self::assertStringNotContainsString('window.WidgetParamTypesInit =', $params);
        self::assertStringNotContainsString('window.WelineWidgetAiContextProviders', $params);

        $appearance = $this->read('app/code/Weline/Theme/view/statics/js/theme-disk-appearance.js');
        foreach ([
            'startInheritEdit',
            'startCustomEdit',
            'openActiveEditor',
            'loadAppearance',
            'saveAppearance',
            'root.dataset.apiDiskSelect',
            'root.dataset.apiDiskDelete',
            "ui().dialog.confirm",
        ] as $capability) {
            self::assertStringContainsString($capability, $appearance, $capability);
        }
        self::assertStringNotContainsString('window.confirm(', $appearance);
    }

    public function testEditorScopeSelectorUsesCanonicalCatalogAndTrustedNavigation(): void
    {
        $controller = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        $provider = $this->read('app/code/Weline/Theme/extends/module/Weline_Framework/Query/ThemeQueryProvider.php');
        $template = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        $styles = $this->read('app/code/Weline/Theme/view/ui/css/pages/theme-editor.css');

        self::assertStringContainsString('ScopeSelectorCatalogInterface::class', $controller);
        self::assertStringNotContainsString('ThemeEditorScopeCatalogService::class', $controller);
        self::assertStringContainsString('$requestedFrontendThemeId = 0;', $controller);
        self::assertStringContainsString('$requestedBackendThemeId = 0;', $controller);
        self::assertStringNotContainsString('if ($requestedFrontendThemeId <= 0)', $controller);
        self::assertStringNotContainsString('if ($requestedBackendThemeId <= 0)', $controller);
        self::assertStringNotContainsString("'scope_options_html'", $controller);
        self::assertStringContainsString('<w:scope', $template);
        self::assertStringContainsString('id="scopeSelect"', $template);
        self::assertStringContainsString('placeholder="@lang(选择网站、店铺或渠道作用域)"', $template);
        self::assertStringContainsString('search-placeholder="@lang(搜索网站、店铺或渠道)"', $template);
        self::assertStringNotContainsString('<w:websites:', $template);
        self::assertStringNotContainsString('data-scope-catalog=', $template);
        self::assertStringContainsString('Weline_Theme::ui/pages/weline-theme-editor.js)?v=20260823-appearance-auto-edit', $template);
        foreach ([
            "'/theme/backend/theme-editor/layout-preview'",
            "'/theme/backend/theme-editor/scoped-workspace'",
            "'/theme/backend/theme-editor/publish-scoped-workspace'",
        ] as $endpoint) {
            self::assertStringContainsString($endpoint, $provider, $endpoint);
        }
        self::assertStringContainsString("scopeSelect: document.getElementById('scopeSelect')", $editor);
        self::assertStringContainsString('elements.scopeSelect,', $editor);
        self::assertStringContainsString('navigateEditorShell({', $editor);
        self::assertStringContainsString('scope: nextScope', $editor);
        self::assertStringContainsString('version_id: null', $editor);
        self::assertStringContainsString("url.searchParams.set('_t', String(overrides._t || Date.now()))", $editor);
        self::assertStringContainsString('window.location.href = targetUrl', $editor);
        $navigationOffset = strpos($editor, 'function navigateEditorShell');
        self::assertNotFalse($navigationOffset);
        $navigation = substr($editor, $navigationOffset, 500);
        self::assertStringContainsString('releaseCurrentEditorLock({keepalive: true})', $navigation);
        self::assertStringContainsString('window.location.href = targetUrl', $navigation);
        self::assertStringNotContainsString('const finalize', $navigation);
        self::assertStringNotContainsString('.finally(finalize)', $navigation);
        self::assertStringContainsString('.toolbar-select-field-scope .w-scope-select', $styles);
        self::assertStringNotContainsString('scopeCatalogWebsite(', $editor);
        self::assertStringNotContainsString('WelineThemeScopeControlChange', $editor);
        self::assertStringContainsString('if (state.lockHeld && !(await releaseCurrentEditorLock()))', $editor);
        self::assertStringNotContainsString(" || ''))).catch", $editor);
        $scopePosition = strpos($template, '<w:scope');
        $themePosition = strpos($template, 'id="themeSelect"');
        $areaPosition = strpos($template, 'id="editorAreaSelect"');
        self::assertIsInt($scopePosition);
        self::assertIsInt($themePosition);
        self::assertIsInt($areaPosition);
        self::assertLessThan($areaPosition, $scopePosition);
        self::assertLessThan($themePosition, $areaPosition);

        foreach ([
            'function scheduleEditorAutoSave(',
            'async function flushPendingEditorMutations(',
            'await flushPendingEditorMutations();',
            'state.pendingScopedMutation = queued.catch(() => undefined);',
            '`widget-config:${layoutId}`',
            '`widget-config-modal:${layoutId}`',
        ] as $autoSaveContract) {
            self::assertStringContainsString($autoSaveContract, $editor, $autoSaveContract);
        }
        self::assertStringNotContainsString('layoutConfigAutoSaveTimer', $editor);
        self::assertStringNotContainsString('let autoSaveTimer = null', $editor);

        self::assertStringNotContainsString('_theme_scope_reload', $editor);
        self::assertStringNotContainsString('scopeReloadRecovery', $editor);
        self::assertStringNotContainsString('weline:backend-bootstrap-failed', $editor);

        foreach ([
            "const editorContext = buildTypedEditorContext('layout');",
            "const editorLocale = editorContext.locale === 'default' ? '' : editorContext.locale;",
            'locale: editorLocale,',
            'locale_code: editorLocale,',
            'editor_context: editorContext,',
        ] as $versionIdentityContract) {
            self::assertStringContainsString($versionIdentityContract, $editor, $versionIdentityContract);
        }

        $apiRequestOffset = strpos($editor, 'async function apiRequest');
        self::assertNotFalse($apiRequestOffset);
        $apiRequest = substr($editor, $apiRequestOffset, 3200);
        self::assertStringContainsString('const resource = await resolveThemeEditorResource()', $apiRequest);
        self::assertStringContainsString("headers['X-Weline-Editor-Context'] = JSON.stringify(defaultContext)", $apiRequest);
        self::assertStringNotContainsString('params.editor_context = defaultContext', $apiRequest);
        self::assertStringContainsString('return resource.editorRequest(params)', $apiRequest);
        self::assertStringContainsString('editorContextFromHeaders($headers)', $provider);
        self::assertStringContainsString('scopedEditorRequestAclSourceId($path, $method)', $provider);
        self::assertStringNotContainsString('scheduleScopeReload()', $apiRequest);
        self::assertStringNotContainsString('window.location.reload()', $apiRequest);

        $scopeHandlerOffset = strpos(
            $editor,
            '// Scope is the root selector. Any change performs a typed, full-context reload.'
        );
        self::assertNotFalse($scopeHandlerOffset);
        $scopeHandler = substr($editor, $scopeHandlerOffset, 900);
        self::assertStringContainsString('switchScope(this.value)', $scopeHandler);
        self::assertStringContainsString('restoreScopeSelector(', $scopeHandler);
        self::assertStringNotContainsString('fetch(', $scopeHandler);
        self::assertStringNotContainsString('window.location.reload()', $scopeHandler);

        foreach ([
            'function sourceScopeForScopedPath(',
            'workspace?.inherited_source_rules',
            'function canRestoreScopedPath(',
            "translateUiText('本级修改')",
            "translateUiText('恢复继承')",
        ] as $ownershipContract) {
            self::assertStringContainsString($ownershipContract, $editor, $ownershipContract);
        }
        self::assertStringContainsString(
            "'scope' => \$selectedScope !== '' ? \$selectedScope : PreviewContextService::DEFAULT_SCOPE",
            $controller,
        );
        self::assertStringContainsString('function hasValidTypedEditorContextParam(url)', $editor);
        self::assertStringContainsString('!hasValidTypedEditorContextParam(resolvedUrl)', $editor);
        self::assertStringContainsString(
            "typeof value === 'object' ? JSON.stringify(value) : String(value)",
            $editor,
        );
        self::assertStringNotContainsString('editor_context=%5Bobject+Object%5D', $editor);
    }

    public function testThemeInputsAndFloatingSurfacesRespectTheirNearestContainer(): void
    {
        $foundation = $this->read('app/code/Weline/Theme/view/ui/css/foundation.css');
        $editorStyles = $this->read('app/code/Weline/Theme/view/ui/css/pages/theme-editor.css');

        foreach ([
            'input:not([type="checkbox"]):not([type="radio"]):not([type="color"]):not([type="range"])',
            'inline-size: 100%;',
            'min-inline-size: 0;',
            'max-inline-size: 100%;',
            'inline-size: min(42rem, calc(100dvw - 2rem));',
            'inline-size: min(28rem, 92dvw);',
            'var(--w-floating-max-inline-size, calc(100dvw - 1rem))',
        ] as $contract) {
            self::assertStringContainsString($contract, $foundation, $contract);
        }

        self::assertStringContainsString(
            '.theme-editor-container :where(',
            $editorStyles,
        );
        self::assertStringContainsString(
            '.toolbar-select-field, .config-field, .form-group, .w-field, .w-grid, .w-cluster',
            $editorStyles,
        );
        self::assertStringContainsString('max-inline-size: min(28rem, calc(100dvw - 1rem));', $editorStyles);
        self::assertStringContainsString('.theme-editor-container .editor-toolbar {', $editorStyles);
        self::assertStringContainsString('flex-wrap: wrap;', $editorStyles);
        self::assertStringContainsString('.theme-editor-container .toolbar-left > .toolbar-selects {', $editorStyles);
        self::assertStringContainsString('inline-size: 100%;', $editorStyles);
        self::assertStringContainsString('grid-template-rows: minmax(9rem, 44%) minmax(0, 1fr);', $editorStyles);
        self::assertStringContainsString('.w-theme-disk-panel-select {', $editorStyles);
        self::assertMatchesRegularExpression(
            '/\\.w-theme-disk-token__controls\\s*\\{[^}]*flex-wrap:\\s*wrap;[^}]*min-inline-size:\\s*0;[^}]*max-inline-size:\\s*100%;/s',
            $editorStyles,
        );
        self::assertMatchesRegularExpression(
            '/\\.w-theme-disk-token__value\\s*\\{[^}]*flex:\\s*1 1 8rem;[^}]*inline-size:\\s*100%;[^}]*min-inline-size:\\s*0;[^}]*max-inline-size:\\s*100%;/s',
            $editorStyles,
        );
    }

    public function testCompileLayoutRequestKeepsPreviewAreaAlignedWithEditorArea(): void
    {
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        $offset = strpos($editor, 'async function fetchLayoutSlots');
        self::assertNotFalse($offset);

        $requestBuilder = substr($editor, $offset, 1800);
        self::assertStringContainsString(
            'const editorArea = overrides.editor_area || getEffectiveEditorArea();',
            $requestBuilder
        );
        self::assertStringContainsString("url.searchParams.set('editor_area', editorArea)", $requestBuilder);
        self::assertStringContainsString("url.searchParams.set('preview_area', editorArea)", $requestBuilder);
    }

    public function testRawTypedFrontendPreviewRequiresExactScopeReadAcl(): void
    {
        $content = $this->read('app/code/Weline/Theme/Controller/Frontend/ThemePreview/Content.php');
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        $template = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');

        self::assertStringContainsString('private function assertBackendScopePreviewAllowed()', $content);
        self::assertStringContainsString('BackendUserContextProviderInterface::class', $content);
        self::assertStringContainsString('ResourceAuthorizationServiceInterface::class', $content);
        self::assertStringContainsString("'Weline_Theme::theme_visual_editor_scope_read'", $content);
        self::assertStringContainsString('$this->assertBackendScopePreviewAllowed();', $content);
        self::assertStringContainsString('async function buildAuthorizedLayoutPreviewUrl(', $editor);
        self::assertStringContainsString('await apiJson(config.apiStartPreview', $editor);
        self::assertStringContainsString("url.searchParams.set('weline_preview_token', token)", $editor);
        self::assertStringContainsString('editor_context: editorContext,', $editor);
        self::assertStringContainsString('async function setLayoutPreviewSource(', $editor);
        self::assertStringContainsString("? 'about:blank'", $template);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
