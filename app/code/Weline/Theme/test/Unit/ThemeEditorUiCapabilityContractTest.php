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
            'function requestSaveWidgetConfig(',
            'function finalizeWidgetConfigSave(',
            'function openComponentPreviewModal(',
            'function fetchInstalledLocales(',
            'function getScopeWebsiteId(',
            'installed-locales?website_id=',
            'function loadI18nValues(',
            'function openThemeComponentAiDialog(',
            'function handleWidgetAiAction(',
            'function getThemeWidgetAiContext(',
            'function loadVersions(',
            'function ensureSuggestedVersionName(',
            'function resolveSuggestedVersionName(',
            'suggested_version_name',
            'await ensureSuggestedVersionName()',
            'function handleRestoreLayout(',
            'function handleClearThemeCache(',
            'function openResetDraftModal(',
            'function executeResetDraftResources(',
            'function publishTheme(',
            'function initializeEditorLock(',
        ] as $capability) {
            self::assertStringContainsString($capability, $editor, $capability);
        }

        self::assertStringContainsString('skip full preview reload', $editor);
        self::assertStringContainsString("weline:form:prepare-submit", $editor);
        self::assertStringContainsString('autosave preview refresh got empty preview_html', $editor);
        self::assertStringContainsString('function buildWidgetCodeDisplayHtml(', $editor);
        self::assertStringContainsString('normalizeWidgetIdentityToken(', $editor);
        self::assertStringContainsString('class="widget-code"', $editor);
        self::assertStringContainsString('widget-code-row', $editor);
        self::assertStringContainsString('data-widget-display-name', $editor);
        self::assertStringContainsString('data-widget-code-row', $editor);
        self::assertStringContainsString('widget-code-k', $editor);
        self::assertStringContainsString('syncLayoutWorkspaceAfterServerMutation(saveResult)', $editor);
        self::assertStringContainsString('wThemeEditorSubmitDelegated', $editor);
        self::assertStringNotContainsString('Existing widget ${layoutId} not found, triggering full refresh', $editor);
        self::assertStringContainsString('async function translateI18nValues(', $editor);
        self::assertStringContainsString('TE-CAP-020: template widgets may have empty layout_id', $editor);
        self::assertStringContainsString("showToast(translateUiText('无法定位多语言字段'), 'warning');", $editor);
        self::assertStringNotContainsString('if (panel && fieldKey && layoutId) {\n                        await translateI18nValues', $editor);

        $editorCss = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.css');
        self::assertStringContainsString('TE-CAP side-panel-push', $editorCss);
        self::assertStringContainsString('panel-widget-open .editor-widget-panel', $editorCss);
        self::assertMatchesRegularExpression(
            '/\.editor-floating-panel-actions\s*\{[^}]*z-index:\s*var\(--weline-z-overlay\);/s',
            $editorCss,
        );
        self::assertStringNotContainsString('editor-compact-mode .editor-main {\n        grid-template-columns: minmax(0, 1fr);', $editorCss);
        self::assertStringNotContainsString('editor-compact-mode .editor-config-panel,\n    .theme-editor-container.editor-compact-mode .editor-widget-panel {\n        position: absolute;', $editorCss);
        $editorTemplate = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');
        self::assertStringContainsString('<css>Weline_Theme::ui/pages/weline-theme-editor.css</css>', $editorTemplate);
        self::assertDoesNotMatchRegularExpression(
            '/@static\(Weline_Theme::[^)]+\)\?v=/',
            $editorTemplate,
            'Editor template must not hardcode @static ?v=; framework theme.static_version applies at render.',
        );
        self::assertStringContainsString('.panel-header.widget-library-panel-header', $editorCss);
        self::assertStringContainsString('.widget-library-type-chip.is-active', $editorCss);
        self::assertStringContainsString('id="wAiWidgetButton"', $editorTemplate);
        self::assertStringContainsString('rel="modulepreload"', $editorTemplate);
        $this->assertEditorBundleVersionedLinksMatch($editorTemplate);
        self::assertStringContainsString('data-library-type="applications"', $editorTemplate);
        self::assertStringContainsString('data-library-type="app_default">@lang{默认安装}', $editorTemplate);
        self::assertStringContainsString('data-api-theme-ai-publish=', $editorTemplate);
        self::assertStringContainsString('data-api-theme-ai-prepare-refine=', $editorTemplate);
        self::assertStringContainsString('id="widgetLibraryTypeFilters"', $editorTemplate);
        self::assertStringContainsString('widget-library-panel-header__row--filters', $editorTemplate);
        self::assertStringContainsString('type="module" async src="@static(Weline_Theme::ui/pages/weline-theme-editor-widget-param.js)', $editorTemplate);
        $mainPreloadPos = strpos($editorTemplate, 'rel="modulepreload" href="@static(Weline_Theme::ui/pages/weline-theme-editor.js)');
        $paramScriptPos = strpos($editorTemplate, 'type="module" async src="@static(Weline_Theme::ui/pages/weline-theme-editor-widget-param.js)');
        self::assertNotFalse($mainPreloadPos);
        self::assertNotFalse($paramScriptPos);
        // Main editor modulepreload must appear before the async widget-param script tag.
        self::assertLessThan($paramScriptPos, $mainPreloadPos);

        $widgetParamJs = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor-widget-param.js');
        $widgetParamCss = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor-widget-param.css');
        self::assertStringContainsString('function showAiWidgetToast(', $widgetParamJs);
        self::assertStringContainsString('function formatAiWidgetErrorMessage(', $widgetParamJs);
        self::assertStringContainsString('function buildAiWidgetPanelMarkup(', $widgetParamJs);
        self::assertStringContainsString('w-ai-widget-footer', $widgetParamJs);
        self::assertStringContainsString('.w-ai-widget-status-wrap', $widgetParamCss);
        self::assertStringContainsString('function renderTreeNode(node, level, parentAnchor, pathSeen)', $widgetParamJs);
        self::assertStringContainsString('w-ai-context-option__content', $widgetParamJs);
        self::assertStringContainsString('data-ai-preview', $widgetParamJs);
        self::assertStringContainsString('setAiPreviewGenerating', $widgetParamJs);
        self::assertStringContainsString('.w-ai-widget-preview__overlay', $widgetParamCss);

        self::assertStringContainsString('function resolveAnchorTreeKey(', $editor);
        self::assertStringContainsString('function isPlacementTreeDescendant(', $editor);
        self::assertStringContainsString('weline-widget-ai-context-api-ready', $widgetParamJs);
        self::assertStringContainsString('weline-widget-ai-context-api-ready', $editor);
        self::assertStringContainsString('placeWidgetFromProvider,', $editor);
        self::assertStringContainsString('publishEmbeddedLayout,', $editor);
        self::assertStringContainsString('apiThemeAiPublish', $editor);
        self::assertStringContainsString('config.apiThemeAiPublish', $editor);
        // TE-CAP-021 must not call VirtualTheme block-action for hover AI.
        $hoverAiStart = strpos($editor, 'async function handleWidgetAiAction(');
        self::assertNotFalse($hoverAiStart);
        $hoverAiEnd = strpos($editor, "\n    function ", $hoverAiStart + 10);
        self::assertNotFalse($hoverAiEnd);
        $hoverAiBody = substr($editor, $hoverAiStart, $hoverAiEnd - $hoverAiStart);
        self::assertStringNotContainsString('apiVirtualThemeBlockAction', $hoverAiBody);
        self::assertStringContainsString('placeWidgetFromProvider', $hoverAiBody);
        self::assertStringContainsString('apiThemeAiPublish', $hoverAiBody);
        self::assertStringNotContainsString('window.scrollToSlot =', $editor);
        self::assertStringNotContainsString('window.WidgetParamTypesInit', $editor);
        self::assertStringNotContainsString("handle.addEventListener('mousedown'", $editor);
    }

    public function testEmbeddedEditorReusesOnlyTheSameOriginParentBackendApi(): void
    {
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');

        self::assertStringContainsString('function resolveThemeEditorApiHost()', $editor);
        self::assertStringContainsString(
            'window.parent.location.origin === window.location.origin',
            $editor,
        );
        self::assertStringContainsString('const apiHost = resolveThemeEditorApiHost();', $editor);
        self::assertStringContainsString("apiHost.Weline.load('api')", $editor);
        self::assertStringContainsString('Promise.resolve(apiHost.Weline.Api)', $editor);
    }

    public function testIframeEmbeddedEditorLockUsesNativeFetchBypassingWorkerBootstrap(): void
    {
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');

        self::assertStringContainsString('function initializeEditorLock(', $editor);
        self::assertStringContainsString('config.apiCheckLock', $editor);
        self::assertStringContainsString("lockSource !== 'cms'", $editor);
        self::assertStringContainsString('function buildCmsEditorLockPayload(', $editor);
        self::assertStringContainsString('function applyEditorLockHeldState(', $editor);
        self::assertStringContainsString('function renderEditorLockOverlay(', $editor);
        self::assertStringContainsString('w-theme-editor-lock--pending', $editor);
        self::assertStringContainsString('pendingDelayMs', $editor);
        self::assertStringContainsString('正在准备编辑', $editor);
        $lockKickoff = strpos($editor, 'initializeEditorLock();');
        $libraryKickoff = strpos($editor, 'deferWidgetLibraryLoad();');
        self::assertNotFalse($lockKickoff);
        self::assertNotFalse($libraryKickoff);
        self::assertLessThan($libraryKickoff, $lockKickoff, 'Editor lock must start before secondary widget-library bootstrap');
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
            'data-api-chrome-mode=',
            'data-api-detach-chrome=',
            'data-api-restore-chrome=',
            'data-api-default-injections=',
            'data-api-reconcile-required-defaults=',
            'data-api-apply-required-defaults=',
            'data-api-layout-config=',
            'data-api-versions=',
            'data-api-restore-original=',
            'data-api-clear-theme-cache=',
            'data-api-reset-draft-resources=',
            'data-api-publish=',
            'data-api-check-lock=',
            'data-api-theme-tokens=',
            'data-api-theme-disk-tokens=',
            'data-api-disk-save=',
            'id="btnResetDraftResources"',
            'id="btnClearThemeCache"',
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
        self::assertStringContainsString('Pack back the most recently overflowed items', $overflow);
        // 禁止每次 layout 先 remove 再 add borrow class（会触发 ResizeObserver 死循环闪烁）
        self::assertStringContainsString('function unborrowedMiddleWidth(', $overflow);
        self::assertStringContainsString('layoutPassDepth', $overflow);
        self::assertStringContainsString("toolbar.classList.toggle('preview-toolbar--borrow-gutters', shouldBorrow)", $overflow);
        self::assertStringNotContainsString("toolbar.classList.remove('preview-toolbar--borrow-gutters')", $overflow);
        // 中间不够即借两侧；禁止旧门槛「整条够用才借」（否则两侧空着却已「更多」）
        self::assertStringContainsString('? (need > middleWidth - slack)', $overflow);
        self::assertStringContainsString(': (need > middleWidth + slack);', $overflow);
        self::assertStringNotContainsString('need <= fullWidth + 1', $overflow);
        self::assertStringNotContainsString('window.WelineThemeEditorToolbarOverflow', $overflow);
        self::assertFileDoesNotExist(BP . '/app/code/Weline/Theme/view/statics/css/theme-editor-toolbar-overflow.css');
    }

    public function testSelectionTargetModesDefaultSlotWidget(): void
    {
        $template = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
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

        $uiBundle = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        foreach ([$editor, $uiBundle] as $parent) {
            self::assertStringContainsString("normalizeSelectionTarget(state.selectionTarget) === 'slot'", $parent);
            self::assertStringContainsString("normalizeSelectionTarget(state.selectionTarget) === 'widget'", $parent);
            self::assertStringContainsString('插槽模式只激活插槽，不点选/打开部件配置。', $parent);
            self::assertStringContainsString('部件模式只触发部件，忽略插槽选中。', $parent);
            self::assertStringContainsString('部件模式不激活插槽工具条选择。', $parent);
            self::assertStringContainsString('部件模式只命中部件，父页委托不得再选中插槽。', $parent);
            self::assertStringContainsString('部件模式只触发部件，忽略旧版插槽点击消息。', $parent);
            self::assertStringContainsString('默认模式：点在「父插槽包裹的部件本体」上时交给部件选中，避免同一次点击再 toast 插槽。', $parent);
        }
        self::assertStringContainsString('部件模式只触发部件，不激活插槽。', $engine);
    }

    public function testWidgetHoverActionsReuseAnchoredFloatBaseComponent(): void
    {
        $ui = $this->read('app/code/Weline/Theme/view/ui/js/weline-ui.js');
        $uiBundle = $this->read('app/code/Weline/Theme/view/statics/ui/weline-ui.js');
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
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
        self::assertStringContainsString('function readSlotSelectionFromToolbar(', $editor);
        self::assertStringContainsString('data-slot-selection', $engine);
        self::assertStringContainsString('function buildSlotSelectionPayload(', $engine);
        self::assertStringContainsString('function stampSlotToolbarSelection(', $engine);
        self::assertStringContainsString('function resolveSlotElementForToolbar(', $editor);
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
        self::assertStringContainsString('.widget-wrapper.show-actions > .widget-hover-actions', $overlay);
        self::assertStringNotContainsString('.widget-wrapper.show-actions .widget-hover-actions,', $overlay);
        self::assertStringNotContainsString('.widget-wrapper:hover > .widget-hover-actions', $overlay);
        self::assertStringNotContainsString('html[data-w-editor-interaction="edit"] .widget-wrapper:hover', $overlay);
        self::assertStringNotContainsString('body.editor-mode .widget-wrapper:hover,', $overlay);
        self::assertStringContainsString('pending.length ? pending.length - 1 : 0', $editor);
        self::assertStringContainsString('stack.length ? stack.length - 1 : 0', $editor);
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

    public function testPreviewMutationObserversCoalesceToAvoidDeliveryStorm(): void
    {
        $engine = $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js');
        $previewPage = $this->read('app/code/Weline/Theme/view/ui/js/pages/theme-preview.js');
        $editor = $this->read('app/code/Weline/Theme/view/statics/js/theme-editor.js');
        $ui = $this->read('app/code/Weline/Theme/view/ui/js/weline-ui.js');

        foreach ([$engine, $previewPage] as $source) {
            self::assertStringContainsString('delivery_storm', $source);
            self::assertStringContainsString('observer.disconnect()', $source);
            self::assertStringContainsString('requestAnimationFrame', $source);
        }
        self::assertStringContainsString('pendingSlots', $engine);
        self::assertStringContainsString('pendingMountRoots', $previewPage);
        self::assertStringContainsString("attributeFilter: ['data-w-slot-hover-target']", $editor);
        self::assertStringNotContainsString("attributeFilter: ['data-w-slot-hover-target', 'class']", $editor);
        self::assertStringContainsString('syncingFloat', $editor);
        self::assertStringContainsString('pendingElevateHosts', $ui);
        self::assertStringContainsString('pendingUiMount', $ui);
        self::assertStringContainsString('uiMountScheduled', $ui);
    }

    public function testSlotInitDefaultsUsesEditorContextIdentityMirrors(): void
    {
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');

        self::assertStringContainsString('async function initSlotDefaultsFromPreview(', $editor);
        self::assertStringContainsString('function getLayoutLockVirtualPayload(', $editor);
        self::assertStringContainsString('function buildTypedEditorContext(', $editor);
        self::assertStringContainsString('init-slot-defaults', $editor);
        self::assertStringContainsString('slot_id: slotId,', $editor);
        self::assertStringContainsString('editor_context:', $editor);
    }

    public function testWidgetMutationPreviewPrefersSurgicalUpdate(): void
    {
        $legacy = $this->read('app/code/Weline/Theme/view/statics/js/theme-editor.js');
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        $controller = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');

        foreach ([$legacy, $editor] as $js) {
            self::assertStringContainsString('function applyWidgetMutationPreview(', $js);
            self::assertStringContainsString('function queueLazyWidgetLibraryPreview(', $js);
            self::assertStringContainsString('function buildWidgetLibraryLocalPlaceholder(', $js);
            self::assertStringContainsString('function isWidgetLibraryPreviewSettled(', $js);
            self::assertStringContainsString('function initWidgetLibraryPreviewLayoutWatcher(', $js);
            self::assertStringContainsString('function isWidgetLibraryCanvasInPanelView(', $js);
            self::assertStringContainsString('function bindWidgetPreviewMediaRefit(', $js);
            self::assertStringContainsString('function mountLibraryCardThumbnail(', $js);
            self::assertStringContainsString('function buildLibraryFallbackThumbHtml(', $js);
            self::assertStringContainsString('te-library-thumb te-library-thumb--fallback', $js);
            self::assertStringContainsString('data-pending-thumb', $js);
            self::assertStringContainsString('Library strip is 110px: use cover thumbnail', $js);
            self::assertStringContainsString('Width-first cover: fill the thumbnail strip', $js);
            self::assertStringContainsString('Never promote local/pending placeholders to settled', $js);
            self::assertStringContainsString('Prefer surgical iframe patch', $js);
            self::assertStringContainsString('Allow reset to supersede an in-flight page fetch', $js);
            self::assertDoesNotMatchRegularExpression(
                '/if \(typeof loadLayoutPreview === \'function\'\) \{\s*loadLayoutPreview\(\);\s*\} else if \(result\.preview_html/',
                $js
            );
        }

        self::assertStringContainsString('列表响应一律不批量渲染 preview_html', $controller);
        self::assertStringNotContainsString(
            "\$widget['preview_html'] = \$this->buildWidgetPreviewHtml(\$widget, \$theme, \$editorArea);\n            }\n            unset(\$widget);\n        }",
            $controller
        );
    }

    public function testWidgetLibrarySlotFullLoadDisablesPagination(): void
    {
        $controller = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        $legacy = $this->read('app/code/Weline/Theme/view/statics/js/theme-editor.js');
        $template = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');

        self::assertStringContainsString('$slotFull = $slotId !== \'\';', $controller);
        self::assertStringContainsString("'slot_full' => \$slotFull ? 1 : 0,", $controller);
        self::assertStringContainsString('$hasMore = false;', $controller);

        foreach ([$editor, $legacy] as $js) {
            self::assertStringContainsString('if (lib.slot) return;', $js);
            self::assertStringContainsString('} else if (!lib.hasMore || lib.slot) {', $js);
            self::assertStringContainsString("Number(result.slot_full) === 1", $js);
            self::assertStringContainsString('// slot 全量模式不展示分页提示', $js);
            self::assertMatchesRegularExpression(
                '/function handleSlotSelected\\([\\s\\S]*?setWidgetSlotFilter\\(/',
                $js
            );
        }

        self::assertStringContainsString(
            '@static(Weline_Theme::ui/pages/weline-theme-editor.js)',
            $template,
        );
        self::assertDoesNotMatchRegularExpression(
            '/@static\(Weline_Theme::ui\/pages\/weline-theme-editor\.js\)\?v=/',
            $template,
        );
    }

    public function testVisualPreviewDragDropKeepsInsideBeforeAndAfterFeedback(): void
    {
        $engine = $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js');
        $styles = $this->read('app/code/Weline/Theme/view/statics/css/editor-mode.css');

        foreach ([
            'function readDragWidgetData(',
            'function showIframeDropFeedback(',
            'function clearIframeDropFeedback(',
            'function dropCandidateIdentityKey(',
            'function publishDropCandidate(',
            'DROP_CANDIDATE_PUBLISH_MS',
            'lastRenderedDropCandidateKey',
            'lastPublishedDropCandidateKey',
            'data-w-drop-position',
            "postPreviewMessage('widget-dropped'",
            "window.addEventListener('message'",
            'event.origin !== window.location.origin',
            'let activeDropSlot = null',
            "slot.dataset.wslotMultiple !== 'false'",
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

        $editorSource = $this->read('app/code/Weline/Theme/view/statics/js/theme-editor.js');
        self::assertStringContainsString('resolvePreviewDropViaBridge(e.clientX, e.clientY)', $editorSource);
        self::assertStringContainsString("data.type !== 'drop-candidate'", $editorSource);
        self::assertStringNotContainsString('previewDropBridgeRaf', $editorSource);
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
        self::assertStringContainsString('appearanceTokenPrefix', $appearance);
        self::assertStringContainsString('APPEARANCE_TOKEN_LEAF', $appearance);
        self::assertMatchesRegularExpression(
            '/primary\\|accent\\|secondary\\|success\\|warning\\|danger\\|error\\|info\\|link\\|text\\|surface\\|border\\|canvas\\|overlay\\|on-\\|bg-/',
            $appearance,
            'Color panel inherit filter must expose neutral text/surface/border tokens for storefront follow-through.'
        );
        self::assertStringContainsString('groupAppearanceTokenEntries', $appearance);
        self::assertStringContainsString('resolveAppearanceTokenGroup', $appearance);
        self::assertStringContainsString('tokenMeta', $appearance);
        self::assertStringContainsString('category_label', $appearance);
        self::assertStringContainsString('data-w-appearance-token-group', $appearance);
        self::assertStringContainsString('w-theme-disk-token-group', $appearance);
        self::assertStringContainsString('label: `--${prefix}`', $appearance);
        self::assertStringNotContainsString('APPEARANCE_TOKEN_GROUPS_BY_PANEL', $appearance);
        self::assertStringContainsString('.w-theme-disk-token-group', $styles);
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
        self::assertStringContainsString('LocaleCatalogScopeResolver::class', $controller);
        self::assertStringContainsString('resolveEditorWebsiteId(', $controller);
        self::assertStringContainsString('getInstalledLocalesPayload($scopeIdentityForLocales)', $controller);
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
        $this->assertEditorBundleVersionedLinksMatch($template);
        self::assertStringContainsString('INTERACTION_MODE_STORAGE_KEY', $editor);
        self::assertStringContainsString('resolveInitialInteractionMode', $editor);
        self::assertStringContainsString("'interaction_mode'", $editor);
        self::assertStringContainsString('refreshPreview,', $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js'));
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
        self::assertStringContainsString('function navigateSameOriginEditorUrl(targetUrl)', $editor);
        self::assertStringContainsString('window.location.href = resolvedUrl', $editor);
        $navigationOffset = strpos($editor, 'function navigateSameOriginEditorUrl');
        self::assertNotFalse($navigationOffset);
        $navigation = substr($editor, $navigationOffset, 500);
        self::assertStringContainsString('releaseCurrentEditorLock({keepalive: true})', $navigation);
        self::assertStringContainsString('window.location.href = resolvedUrl', $navigation);
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
            'async function flushDirtyEditorConfigForms(',
            'await flushDirtyEditorConfigForms();',
            'await flushPendingEditorMutations();',
            'state.pendingScopedMutation = queued.catch(() => undefined);',
            '`widget-config:${layoutId}`',
            'function collectWidgetConfigChanges(',
            'async function patchWidgetConfigFields(',
            'async function autosaveWidgetConfigForm(',
            'function bindWidgetFieldDeepAutosaveWatchers(',
            'function resolveWidgetConfigAutoSaveDelay(',
            'config.autoSaveDelay',
            'weline:param:valuechange',
            'widget_config_autosave',
            'blankAsInherit',
            'deferPreviewMs',
            'dispatchEvent(new Event(\'input\', { bubbles: true }))',
        ] as $autoSaveContract) {
            self::assertStringContainsString($autoSaveContract, $editor, $autoSaveContract);
        }
        self::assertDoesNotMatchRegularExpression(
            '/function scheduleEditorAutoSave\([^)]*delay\s*=\s*400\)/',
            $editor,
            'widget config autosave must not default to 400ms (typing lag)',
        );
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
        self::assertStringContainsString('resource.editorRequest(params)', $apiRequest);
        self::assertStringContainsString('keepBusinessResult', $apiRequest);
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
        self::assertStringContainsString('ThemePreviewRenderCache::class', $content);
        self::assertStringContainsString('$previewRenderCache->remember(', $content);
        self::assertStringContainsString('assign(\'editor_mode\', $isEditorMode)', $content);
        $header = $this->read('app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml');
        self::assertStringContainsString('$headerFlattenNavForEditor', $header);
        self::assertStringContainsString('editor-preview-light', $header);
        // Full-page preview must keep mega/flyout children (storefront parity).
        self::assertStringNotContainsString(
            '$sidebarNavItems = $headerFlattenNavForEditor($sidebarNavItems);',
            $header
        );
        self::assertStringContainsString('private function assertBackendScopePreviewAllowed()', $content);
        self::assertStringContainsString('BackendUserContextProviderInterface::class', $content);
        self::assertStringContainsString('ResourceAuthorizationServiceInterface::class', $content);
        self::assertStringContainsString("'Weline_Theme::theme_visual_editor_scope_read'", $content);
        self::assertStringContainsString('$this->assertBackendScopePreviewAllowed();', $content);
        self::assertStringContainsString('async function buildAuthorizedLayoutPreviewUrl(', $editor);
        self::assertStringContainsString("url.searchParams.delete('weline_preview_token')", $editor);
        self::assertStringContainsString('async function openFrontendPreview()', $editor);
        self::assertMatchesRegularExpression(
            '/async function openFrontendPreview\(\)\s*\{[\s\S]*?await apiJson\(config\.apiStartPreview/s',
            $editor,
            'Only #btnFrontendPreview may call start-preview for live storefront preview.',
        );
        self::assertMatchesRegularExpression(
            '/async function openFrontendPreview\(\)\s*\{[\s\S]*?await flushPendingEditorMutations\(\);/s',
            $editor,
            'Frontend preview must flush dirty editor forms before start-preview.',
        );
        self::assertStringContainsString(
            "const previewStatus = state.previewStatus === 'published' ? 'published' : 'draft';",
            $editor,
        );
        $authorizedStart = strpos($editor, 'async function buildAuthorizedLayoutPreviewUrl(');
        $authorizedEnd = strpos($editor, 'function resolveThemePreviewGatewayUrl(', $authorizedStart ?: 0);
        self::assertNotFalse($authorizedStart);
        self::assertNotFalse($authorizedEnd);
        $authorizedBlock = substr($editor, (int)$authorizedStart, (int)$authorizedEnd - (int)$authorizedStart);
        self::assertStringNotContainsString('apiStartPreview', $authorizedBlock);
        self::assertStringNotContainsString("url.searchParams.set('weline_preview_token'", $authorizedBlock);
        self::assertStringContainsString('handlePreviewExitFromIframe', $editor);
        self::assertStringContainsString('setLayoutPreviewSource({ _t: Date.now() })', $editor);
        self::assertStringContainsString('editor_context: editorContext,', $editor);
        self::assertStringContainsString('async function setLayoutPreviewSource(', $editor);
        self::assertStringContainsString("data-initial-preview-token=", $template);
        self::assertStringContainsString('theme/frontend/theme-preview/gateway', $template);
        self::assertStringNotContainsString(
            "data-api-frontend-layout-preview=\"@frontend-url{'theme/frontend/theme-preview/content'}\"",
            $template
        );
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
        // Language-switcher global capture must defer to editor preview postMessage (no leave-preview assign).
        $languageSwitcher = $this->read('app/code/Weline/Theme/view/statics/ui/components/weline-language-switcher.js');
        self::assertStringContainsString('function isThemeEditorPreviewFrame', $languageSwitcher);
        self::assertStringContainsString('postThemeEditorPreviewLocaleChange', $languageSwitcher);
        self::assertStringContainsString("type: 'locale-change'", $languageSwitcher);
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
        // 插入后直接取 lastElementChild；禁止对空 layoutId 做 querySelector
        self::assertStringContainsString('targetContainer.lastElementChild', $editor);
        self::assertStringNotContainsString('targetContainer.querySelector(dataLayoutIdSelector(layoutId))', $editor);
    }

    public function testSlotWidgetAccordionDisclosurePanelAndUniqueCollapseIds(): void
    {
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        $template = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');

        self::assertStringContainsString('data-w-disclosure-panel', $editor);
        self::assertStringContainsString('widgetConfig_${i}_${identityKey}', $editor);
        self::assertStringContainsString('loadWidgetConfigForAccordion(identity, widgetElement = null, configBodyEl = null)', $editor);
        self::assertStringContainsString('configBodyEl instanceof HTMLElement', $editor);
        $this->assertEditorBundleVersionedLinksMatch($template);
    }

    public function testPreviewDropBridgeKeepsLibraryDragEventsInParentDocument(): void
    {
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        $preview = $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js');
        $previewBundle = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-preview.js');
        $css = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.css');

        self::assertStringContainsString('function ensurePreviewDropBridge()', $editor);
        self::assertStringContainsString('function resolvePreviewDropViaBridge(', $editor);
        self::assertStringContainsString("setPreviewDropBridgeActive(true)", $editor);
        self::assertStringContainsString('previewDropBridge', $editor);
        foreach ([$preview, $previewBundle] as $source) {
            self::assertStringContainsString('function resolveDropAtPoint(', $source);
            self::assertStringContainsString('function collectSlotsAtPoint(', $source);
            self::assertStringContainsString('function findAcceptingDropSlotAtPoint(', $source);
            self::assertStringContainsString('function orderSlotsForDrop(', $source);
            self::assertStringContainsString('selected_slot_id', $source);
            self::assertStringContainsString('Weline.Theme.Preview', $source);
            self::assertStringContainsString('clearDropFeedback:', $source);
        }
        self::assertStringContainsString('selected_slot_id:', $editor);
        self::assertStringContainsString('tryCommitToSelectedRecommendationSlot', $editor);
        self::assertStringContainsString('schedulePendingPreviewFocus', $editor);
        self::assertStringContainsString('flushPendingPreviewFocus', $editor);
        self::assertStringContainsString('pendingPreviewFocus', $editor);
        self::assertStringContainsString('不得静默丢弃', $editor);
        self::assertStringContainsString('无法放入当前位置，请拖到可接收的插槽', $editor);
        self::assertStringContainsString('.preview-drop-bridge.is-active', $css);
    }

    public function testEditorLinkInterceptionKeepsShopperRuntimeInteractive(): void
    {
        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        $engine = $this->read('app/code/Weline/Theme/view/statics/js/editor-mode.js');

        foreach ([$editor, $engine] as $source) {
            self::assertStringContainsString('function isShopperRuntimeEventTarget(', $source);
            self::assertStringContainsString('[data-mini-cart-trigger]', $source);
        }
        self::assertStringContainsString("typeof target.closest !== 'function'", $editor);
        self::assertStringContainsString('[data-action="add"]', $editor);
        self::assertStringContainsString('isShopperRuntimeEventTarget(e.target)', $editor);
        self::assertStringContainsString('bindWidgetActionEvents', $editor);
        self::assertStringContainsString('isShopperRuntimeEventTarget(link)', $engine);
    }

    public function testDefaultInjectionAppliedCardsSupportUninstallAndPreviewLocate(): void
    {
        foreach ([
            'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js',
        ] as $path) {
            $editor = $this->read($path);
            self::assertStringContainsString('function defaultInjectionCanRemove(', $editor, $path);
            self::assertStringContainsString('function locateDefaultInjectionInPreview(', $editor, $path);
            self::assertStringContainsString('function removeDefaultInjection(', $editor, $path);
            self::assertStringContainsString('w-theme-editor-remove-default-injection', $editor, $path);
            self::assertStringContainsString('widget-default-injection-locate-target', $editor, $path);
            self::assertStringContainsString('widget-locate-flash', $editor, $path);
        }

        $editorCss = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.css');
        self::assertStringContainsString('.widget-default-injection-locate-target', $editorCss);

        $previewCss = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-preview.css');
        self::assertStringContainsString('.widget-locate-flash', $previewCss);

        $service = $this->read('app/code/Weline/Theme/Service/WidgetDefaultInjectionService.php');
        self::assertStringContainsString('function resolveAppliedLayoutNode(', $service);
        self::assertStringContainsString("'removable'] = true", $service);
    }

    private function assertEditorBundleVersionedLinksMatch(string $template): void
    {
        $preload = substr_count(
            $template,
            'rel="modulepreload" href="@static(Weline_Theme::ui/pages/weline-theme-editor.js)"',
        );
        $script = substr_count(
            $template,
            'src="@static(Weline_Theme::ui/pages/weline-theme-editor.js)"',
        );
        self::assertGreaterThanOrEqual(1, $preload, 'Editor modulepreload must use bare @static path.');
        self::assertGreaterThanOrEqual(1, $script, 'Editor script must use bare @static path.');
        self::assertDoesNotMatchRegularExpression(
            '/Weline_Theme::ui\/pages\/weline-theme-editor\.js\)\?v=/',
            $template,
            'Hardcoded ?v= on editor bundle is forbidden; publish bumps theme.static_version.',
        );
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
