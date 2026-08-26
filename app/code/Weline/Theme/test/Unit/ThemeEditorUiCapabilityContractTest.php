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

        self::assertStringContainsString('function initSidePanels(', $editor);
        self::assertStringContainsString('function setInteractionMode(', $editor);
        self::assertStringContainsString("type: 'interaction-mode'", $editor);
        self::assertStringContainsString('interaction-preview-mode', $editor);
        self::assertStringContainsString("data-theme-editor-action=\"set-interaction-mode\"", $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml'));
        self::assertStringContainsString('function applyInteractionMode(', $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js'));
        self::assertStringContainsString('dataset.wEditorInteraction', $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js'));
        self::assertStringContainsString('readBootInteractionMode', $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js'));
        self::assertStringContainsString('function toggleSlotSelectTree(', $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js'));
        self::assertStringContainsString('function getDirectChildSlots(', $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js'));
        self::assertStringContainsString('slot-select-tree', $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js'));
        self::assertStringContainsString('.slot-select-tree', $this->read('app/code/Weline/Theme/view/statics/css/editor-mode.css'));
        self::assertStringContainsString('INTERACTION_MODE_STORAGE_KEY', $editor);
        self::assertStringContainsString('resolveInitialInteractionMode', $editor);
        self::assertStringContainsString("'interaction_mode'", $editor);

        foreach ([
            'function initSidePanels(',
            'function switchPreviewView(',
            'function loadLayoutPreview(',
            'function fetchLayoutSlots(',
            'function loadLayoutConfig(',
            'function saveLayoutConfig(',
            'function deferWidgetLibraryLoad(',
            'function kickoffLayoutPreview(',
            'function scheduleSecondaryEditorBootstrap(',
            'function hasServerSeededPreviewSrc(',
            'function prefetchWidgetsCatalog(',
            'function resolveWidgetContextFromIframe(',
            'function buildWidgetDeletePayload(',
            'function resolveRemovedLayoutNodeUid(',
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
            'function openResetDraftModal(',
            'function executeResetDraftResources(',
            'function publishTheme(',
            'function initializeEditorLock(',
        ] as $capability) {
            self::assertStringContainsString($capability, $editor, $capability);
        }

        self::assertStringContainsString('skip full preview reload', $editor);
        self::assertStringContainsString("weline:form:prepare-submit", $editor);
        self::assertStringContainsString('save-widget-config missing preview_html for locale; skip full preview reload', $editor);
        self::assertStringNotContainsString('Existing widget ${layoutId} not found, triggering full refresh', $editor);
        self::assertStringContainsString('async function translateI18nValues(', $editor);
        self::assertStringContainsString('TE-CAP-020: template widgets may have empty layout_id', $editor);
        self::assertStringContainsString("showToast(translateUiText('无法定位多语言字段'), 'warning');", $editor);
        self::assertStringNotContainsString('if (panel && fieldKey && layoutId) {\n                        await translateI18nValues', $editor);

        $editorCss = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.css');
        self::assertStringContainsString('TE-CAP side-panel-push', $editorCss);
        self::assertStringNotContainsString('editor-compact-mode .editor-main {\n        grid-template-columns: minmax(0, 1fr);', $editorCss);
        self::assertStringNotContainsString('editor-compact-mode .editor-config-panel,\n    .theme-editor-container.editor-compact-mode .editor-widget-panel {\n        position: absolute;', $editorCss);
        $editorTemplate = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');
        self::assertStringContainsString('weline-theme-editor.css?v=20260825-syntax-fix-v1', $editorTemplate);
        self::assertStringContainsString('rel="modulepreload"', $editorTemplate);
        self::assertStringContainsString('weline-theme-editor.js)?v=20260826-slot-toolbar-resolve-v1', $editorTemplate);
        self::assertStringContainsString('type="module" async src="@static(Weline_Theme::ui/pages/weline-theme-editor-widget-param.js)', $editorTemplate);
        $mainPreloadPos = strpos($editorTemplate, 'rel="modulepreload" href="@static(Weline_Theme::ui/pages/weline-theme-editor.js)');
        $paramScriptPos = strpos($editorTemplate, 'type="module" async src="@static(Weline_Theme::ui/pages/weline-theme-editor-widget-param.js)');
        self::assertNotFalse($mainPreloadPos);
        self::assertNotFalse($paramScriptPos);
        // Main editor modulepreload must appear before the async widget-param script tag.
        self::assertLessThan($paramScriptPos, $mainPreloadPos);

        self::assertStringContainsString('const EditorApi = Weline.Theme.Editor', $editor);
        self::assertStringContainsString('placeWidgetFromProvider,', $editor);
        self::assertStringContainsString('publishEmbeddedLayout,', $editor);
        self::assertStringNotContainsString('window.scrollToSlot =', $editor);
        self::assertStringNotContainsString('window.WidgetParamTypesInit', $editor);
        self::assertStringNotContainsString("handle.addEventListener('mousedown'", $editor);
    }

    public function testEmbeddedEditorReusesOnlyTheSameOriginParentBackendApi(): void
    {
        $editor = $this->read('app/code/Weline/Theme/view/statics/js/theme-editor.js');

        self::assertStringContainsString('function resolveThemeEditorApiHost()', $editor);
        self::assertStringContainsString(
            'window.parent.location.origin === window.location.origin',
            $editor,
        );
        self::assertStringContainsString('const apiHost = resolveThemeEditorApiHost();', $editor);
        self::assertStringContainsString("apiHost.Weline.load('api')", $editor);
        self::assertStringContainsString('Promise.resolve(apiHost.Weline.Api)', $editor);
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
            'data-api-reset-draft-resources=',
            'data-api-publish=',
            'data-api-check-lock=',
            'data-api-theme-tokens=',
            'data-api-theme-disk-tokens=',
            'data-api-disk-save=',
            'id="btnResetDraftResources"',
            'id="themeEditorResetDraftModal"',
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

    public function testSelectionTargetModesDefaultSlotWidget(): void
    {
        $template = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');
        $editor = $this->read('app/code/Weline/Theme/view/statics/js/theme-editor.js');
        $engine = $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js');
        $styles = $this->read('app/code/Weline/Theme/view/statics/css/editor-mode.css');

        self::assertStringContainsString('preview-selection-target', $template);
        self::assertStringContainsString('data-selection-target="default"', $template);
        self::assertStringContainsString('data-selection-target="slot"', $template);
        self::assertStringContainsString('data-selection-target="widget"', $template);
        self::assertStringNotContainsString('data-selection-target="nolink"', $template);
        self::assertStringContainsString('preview-link-block', $template);
        self::assertStringContainsString('toggle-link-block', $template);
        self::assertStringContainsString('set-selection-target', $template);

        self::assertStringContainsString("selectionTarget: 'default'", $editor);
        self::assertStringContainsString('linkBlockEnabled: false', $editor);
        self::assertStringContainsString('function setSelectionTarget(', $editor);
        self::assertStringContainsString('function setLinkBlockEnabled(', $editor);
        self::assertStringContainsString("type: 'selection-target'", $editor);
        self::assertStringContainsString("type: 'link-block'", $editor);
        self::assertStringContainsString('normalizeSelectionTarget(state.selectionTarget)', $editor);
        self::assertStringContainsString('state.linkBlockEnabled === true', $editor);

        self::assertStringContainsString('function applySelectionTarget(', $engine);
        self::assertStringContainsString('function applyLinkBlock(', $engine);
        self::assertStringContainsString('function preferredSlotHoverIndex(', $engine);
        self::assertStringContainsString('slot-mode-hit-area', $engine);
        self::assertStringContainsString("data.type === 'selection-target'", $engine);
        self::assertStringContainsString("data.type === 'link-block'", $engine);
        self::assertStringContainsString('bindNolinkClickGuard', $engine);
        self::assertStringContainsString('isLinkBlockEnabled', $engine);

        self::assertStringContainsString('data-w-editor-selection-target="slot"', $styles);
        self::assertStringContainsString('data-w-editor-selection-target="widget"', $styles);
        self::assertStringContainsString('data-w-editor-link-block="1"', $styles);
        self::assertStringContainsString('.slot-mode-hit-area', $styles);
    }

    public function testWidgetHoverActionsReuseAnchoredFloatBaseComponent(): void
    {
        $ui = $this->read('app/code/Weline/Theme/view/ui/js/weline-ui.js');
        $uiBundle = $this->read('app/code/Weline/Theme/view/statics/ui/weline-ui.js');
        $editor = $this->read('app/code/Weline/Theme/view/statics/js/theme-editor.js');
        $engine = $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js');
        $overlay = $this->read('app/code/Weline/Theme/view/ui/css/pages/theme-editor-overlay.css');
        $foundation = $this->read('app/code/Weline/Theme/view/ui/css/foundation.css');

        foreach ([$ui, $uiBundle] as $runtime) {
            self::assertStringContainsString("define('anchored-float'", $runtime);
            self::assertStringContainsString('function registerAnchoredFloat()', $runtime);
            self::assertStringContainsString('function resolveFloatingDocument(', $runtime);
            self::assertStringContainsString('attach(target, options = {})', $runtime);
            self::assertStringContainsString("ensureMounted(element, 'anchored-float')", $runtime);
        }

        self::assertStringContainsString('data-w-component="anchored-float"', $editor);
        self::assertStringContainsString('data-w-float-self="1"', $editor);
        self::assertStringContainsString('data-w-placement="top-end"', $editor);
        self::assertStringContainsString('data-w-portal="0"', $editor);
        self::assertStringContainsString('Weline.UI.floating.attach(actionsEl', $editor);
        self::assertStringContainsString("UI?.get?.(bar, 'anchored-float')", $editor);
        self::assertStringContainsString('NEST_HOVER_STICKY_MS', $editor);
        self::assertStringContainsString('keepNestHoverFromActionsBar', $editor);
        self::assertStringContainsString('nestHoverPendingKey', $editor);
        self::assertStringContainsString('nestHoverPendingKey === pendingKey', $editor);
        self::assertStringContainsString('function bindSlotToolbarActionEvents(', $editor);
        self::assertStringContainsString('bindSlotToolbarActionEvents(iframeDoc)', $editor);
        self::assertStringContainsString('function resolveSlotElementForToolbar(', $editor);
        self::assertStringContainsString('toolbar.dataset.slotId = slotIdForLabel', $engine);
        self::assertStringContainsString('syncSlotToolbarOwnerMeta(toolbar, ownerSlot)', $editor);
        self::assertStringContainsString("data-action', 'slot-select'", $engine);
        self::assertStringContainsString("data-action', 'slot-init-defaults'", $engine);
        self::assertStringContainsString('.widget-hover-actions[data-slot-hover-actions="1"]', $editor);
        self::assertStringContainsString('function initSlotToolbarFloats(', $editor);
        self::assertStringContainsString('function syncActiveSlotToolbarFloat(', $editor);
        self::assertStringContainsString("case 'slot-hover-sync':", $editor);
        self::assertStringContainsString('function initSlotDefaultsFromPreview(', $editor);
        self::assertStringContainsString("if (data.type === 'slot-selected')", $editor);
        self::assertStringContainsString("if (data.type === 'slot-init-defaults')", $editor);
        self::assertStringNotContainsString("case 'slot-init-defaults':", $editor);
        self::assertStringNotContainsString('初始化插槽默认部件？', $editor);
        self::assertStringContainsString('function hideSlotToolbarFloatFromParent(', $editor);
        self::assertStringContainsString('hideSlotToolbarFloatFromParent(toolbar)', $editor);
        self::assertStringContainsString('apiInitSlotDefaults', $editor);
        self::assertStringContainsString('initSlotToolbarFloats(iframeDoc)', $editor);
        self::assertStringContainsString("target.closest('.widget-hover-actions')", $editor);

        self::assertStringContainsString('data-action="config"', $editor);
        self::assertStringContainsString('w-theme-editor-widget-config', $editor);
        self::assertStringContainsString('title="配置"', $editor);
        self::assertStringContainsString('data-action="ai-edit"', $editor);
        self::assertStringContainsString('WIDGET_ACTION_ICONS.config', $editor);
        self::assertStringContainsString('m12 3 1.2 3.8L17 8l-3.8 1.2L12 13', $editor);

        self::assertStringContainsString('[data-w-component~="anchored-float"][data-w-floating-positioned]', $foundation);
        self::assertStringContainsString('.widget-hover-actions[data-w-floating-positioned]', $overlay);
        self::assertStringContainsString(':not([data-slot-hover-actions="1"]) button', $overlay);
        self::assertStringContainsString('var(--w-floating-left, 0px)', $overlay);
    }

    public function testSlotHoverToolbarKeepsStickyOpenForInteraction(): void
    {
        $engine = $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js');
        $styles = $this->read('app/code/Weline/Theme/view/statics/css/editor-mode.css');

        self::assertStringContainsString('SLOT_HOVER_STICKY_MS', $engine);
        self::assertStringContainsString('keepSlotHoverFromChrome', $engine);
        self::assertStringContainsString('scheduleSlotHoverTransition', $engine);
        self::assertStringContainsString('slotHoverPendingKey', $engine);
        self::assertStringContainsString('slotHoverPendingKey === pendingKey', $engine);
        self::assertStringContainsString('.widget-hover-actions, .slot-toolbar, .slot-select-tree, .slot-info-card', $engine);
        self::assertStringContainsString('currentTarget.contains(e.target)', $engine);
        self::assertStringContainsString('deepestSlotHoverIndex', $engine);
        self::assertStringContainsString('slotHoverPinned', $engine);
        self::assertStringContainsString('preferredSlotHoverIndex', $engine);
        self::assertStringContainsString('return deepestSlotHoverIndex(chain);', $engine);
        self::assertStringNotContainsString('// 插槽模式：默认最外层，便于选中 header 等大容器。', $engine);
        self::assertStringContainsString('isSlotSelectionTarget', $engine);
        self::assertStringContainsString("className = 'widget-hover-actions slot-toolbar'", $engine);
        self::assertStringContainsString('slot-toolbar-kind', $engine);
        self::assertStringContainsString("textContent = '插槽'", $engine);
        self::assertStringContainsString('data-slot-hover-actions', $engine);
        self::assertStringContainsString('slot-init-btn', $engine);
        self::assertStringContainsString('closeSlotSelectTrees();', $engine);
        self::assertStringContainsString('selectSlot(slot);', $engine);
        self::assertStringContainsString('function hideSlotToolbarChrome(', $engine);
        self::assertStringContainsString('weline:anchored-float:hide', $engine);
        self::assertStringContainsString('function syncSlotToolbarFloat(', $engine);
        self::assertStringContainsString("data-w-component', 'anchored-float'", $engine);
        self::assertStringNotContainsString('function calculateButtonPosition(', $engine);
        self::assertStringNotContainsString('function updateButtonPosition(', $engine);
        self::assertStringContainsString('[data-wslot][data-w-slot-hover-target="true"]', $styles);
        self::assertStringContainsString('.slot-toolbar[data-w-floating-positioned]', $styles);
        self::assertStringContainsString('var(--w-floating-left, 0px)', $styles);
        self::assertStringContainsString('opacity: 0 !important;', $styles);
        self::assertStringContainsString('postPreviewMessage(\'slot-hover-sync\'', $engine);
        self::assertStringContainsString('prev === target', $engine);
        self::assertStringContainsString('visibility: visible !important;', $styles);
        self::assertStringContainsString(':not([data-slot-hover-actions="1"])', $styles);
        self::assertStringContainsString('--editor-mode-slot-toolbar-gradient', $styles);
        self::assertStringContainsString('.slot-toolbar-kind', $styles);
        self::assertStringContainsString('#22c55e', $styles);
        self::assertStringContainsString('var(--editor-mode-slot-toolbar-gradient', $styles);
        self::assertStringContainsString('.slot-init-btn', $styles);
        self::assertStringContainsString(':not([data-w-slot-hover-target="true"])', $styles);
        self::assertStringNotContainsString('[data-wslot]:hover > .slot-toolbar', $styles);
        self::assertStringNotContainsString('[data-wslot]:hover > .widget-hover-actions[data-slot-hover-actions="1"]', $styles);
        self::assertStringNotContainsString('body.editor-mode [data-wslot]:hover {', $styles);
        $thin = $this->read('app/code/Weline/Theme/view/ui/css/pages/theme-preview.css');
        self::assertStringContainsString(':not([data-w-editor-preview-engine="full"])', $thin);
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
            'root.dataset.apiThemeDiskTokens',
            'loadDiskTokens',
            'tokens_json',
            'root.dataset.apiDiskDelete',
            "ui().dialog.confirm",
        ] as $capability) {
            self::assertStringContainsString($capability, $appearance, $capability);
        }
        self::assertStringNotContainsString('window.confirm(', $appearance);
    }

    public function testAppearanceTokenEditorSupportsSearchAndDirectEdit(): void
    {
        $template = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');
        $appearance = $this->read('app/code/Weline/Theme/view/statics/js/theme-disk-appearance.js');
        $styles = $this->read('app/code/Weline/Theme/view/ui/css/pages/theme-editor.css');

        self::assertStringContainsString('data-w-appearance-token-search', $template);
        self::assertStringContainsString('id="themeDiskAppearanceTokenSearch"', $template);
        self::assertStringContainsString('@lang{搜索变量名或色值}', $template);
        self::assertStringContainsString('data-w-appearance-token-count', $template);
        self::assertStringContainsString('matchesTokenSearch', $appearance);
        self::assertStringContainsString('tokenSearchQuery', $appearance);
        self::assertStringContainsString('没有匹配的变量', $appearance);
        self::assertStringContainsString("draft.tokens[name] = textInput.value", $appearance);
        self::assertStringContainsString("draft.tokens[name] = colorInput.value", $appearance);
        self::assertStringContainsString('w-theme-disk-token__color', $appearance);
        self::assertStringContainsString('applyAppearancePreviewTokens', $appearance);
        self::assertStringContainsString('scheduleAppearancePreviewTokens', $appearance);
        self::assertStringContainsString('refreshLayoutPreview', $appearance);
        self::assertStringContainsString("data-theme-scoped-preview-appearance", $appearance);
        self::assertStringContainsString('editor.refreshPreview', $appearance);
        self::assertMatchesRegularExpression(
            '/\.w-theme-disk-appearance-token-search\s*\{[^}]*inline-size:\s*100%;[^}]*min-inline-size:\s*0;[^}]*max-inline-size:\s*100%;/s',
            $styles,
        );
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
        self::assertStringContainsString('Weline_Theme::ui/pages/weline-theme-editor.js)?v=20260826-slot-toolbar-resolve-v1', $template);
        self::assertStringContainsString('INTERACTION_MODE_STORAGE_KEY', $editor);
        self::assertStringContainsString('resolveInitialInteractionMode', $editor);
        self::assertStringContainsString("'interaction_mode'", $editor);
        self::assertStringContainsString('refreshPreview,', $this->read('app/code/Weline/Theme/view/statics/js/theme-editor.js'));
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
        self::assertStringContainsString('grid-template-rows: minmax(9rem, 36%) minmax(0, 1fr);', $editorStyles);
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

        self::assertStringNotContainsString("setData('skip_view_file_cache', true)", $content);
        self::assertStringContainsString('assign(\'editor_mode\', $isEditorMode)', $content);
        $header = $this->read('app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml');
        self::assertStringContainsString('editor-preview-light: skip mega-menu panel trees', $header);
        self::assertStringContainsString('$headerFlattenNavForEditor', $header);
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

    public function testEditorPreviewLocaleOverrideDoesNotWriteLanguageCookie(): void
    {
        $content = $this->read('app/code/Weline/Theme/Controller/Frontend/ThemePreview/Content.php');
        $previewContext = $this->read('app/code/Weline/Theme/Service/PreviewContextService.php');
        $state = $this->read('app/code/Weline/Framework/App/State.php');
        $fileImage = $this->read('app/code/Weline/FileManager/extends/module/Weline_Theme/Integration/FileImageLayoutValueHydrator.php');
        $engine = $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js');
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');

        self::assertStringContainsString('State::setRequestLanguageOverride($locale)', $content);
        self::assertStringContainsString("State::setRequestLanguageOverride('')", $content);
        self::assertStringContainsString('never write WELINE_USER_LANG', $content);
        self::assertStringContainsString("strcasecmp(\$rawLocaleParam, 'default') === 0", $previewContext);
        self::assertStringContainsString("\$context['locale'] = ''", $previewContext);
        self::assertStringContainsString('REQUEST_LANGUAGE_OVERRIDE', $state);
        self::assertStringContainsString('function setRequestLanguageOverride', $state);
        self::assertStringContainsString("\$purpose === 'preview'", $fileImage);
        self::assertStringContainsString('$locale = $usage->localeCode;', $fileImage);
        // Preview-frame language UI must post locale-change to parent (no cookie / no leave-preview).
        self::assertStringContainsString("postPreviewMessage('locale-change'", $engine);
        self::assertStringContainsString('function isEditorLanguageOption(', $engine);
        self::assertStringContainsString('case \'locale-change\':', $editor);
        self::assertStringContainsString('setActiveConfigLocale(nextLocale', $editor);
        self::assertStringContainsString('forceReload: true', $editor);
        self::assertStringContainsString('options.forceReload', $editor);
        self::assertStringContainsString('data-w-toolbar-overflow-pin', $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml'));
        self::assertStringContainsString("querySelector('.editor-main')", $editor);
        self::assertStringContainsString('function postCheckLock', $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php'));
        self::assertStringContainsString('.w-language-switcher__option[data-lang]', $engine);
    }

    public function testWidgetConfigLocaleMergeSeedsEditorStorageScopeWithoutFrozenIdentity(): void
    {
        $editor = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        $themeData = $this->read('app/code/Weline/Theme/Helper/ThemeData.php');

        self::assertStringContainsString('function seedRequestedScope(', $themeData);
        self::assertStringContainsString('ThemeData::seedRequestedScope($themeArea, $translationScope)', $editor);
        self::assertStringContainsString('function resolveEditorTranslationStorageScope(', $editor);
        self::assertStringContainsString('ThemeContextService::DEFAULT_SCOPE', $editor);
        self::assertStringContainsString('does not call ThemeContextService::resolveCurrentScope(frontend)', $editor);
    }

    public function testThemeEditorRecognizesTemplateInlineSlotWidgets(): void
    {
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');

        self::assertStringContainsString('function syncTemplateWidgetsToStructureView(', $editor);
        self::assertStringContainsString('function readWidgetIdentityFromElement(', $editor);
        self::assertStringContainsString('.weline-template-widget[data-template-ref]', $editor);
        self::assertStringContainsString('materializeTemplateWidgetIfNeeded', $editor);
        self::assertStringContainsString('data-template-ref', $editor);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
