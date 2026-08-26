<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Http\Url;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\I18n\Api\Localization\LocaleCatalogInterface;
use Weline\Theme\Service\EditorLockService;
use Weline\Theme\Service\LayoutValueHydrationRegistry;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewNavigationResolver;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Theme\Service\ThemeMetaIdentityService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Service\ThemePreviewContentRenderer;
use Weline\Theme\Service\ThemeResourceCatalog;
use Weline\Theme\Service\ThemeEditorDraftResetService;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;
use Weline\Theme\Service\ThemeSlotContractService;
use Weline\Theme\Service\TemplateInlineWidgetMerger;
use Weline\Theme\Service\WidgetDefaultInjectionService;
use Weline\Theme\Service\WidgetPositionResolver;
use Weline\Widget\Api\Param\ParamFormRendererInterface;
use Weline\Widget\Api\WidgetRegistryInterface;
use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Meta\Api\ParamDefinitionNormalizerInterface;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\PreviewManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\ThemePathResolver;
use Weline\Theme\Observer\ControllerFetchFileBefore;
use Weline\Theme\Service\PreviewThemeScopeService;
use Weline\Theme\Service\ThemeTargetIdentityResolver;
use Weline\Theme\Service\ThemeTargetTypeRegistry;
use Weline\Theme\Service\Ui\ThemeEditorMarkupRenderer;
use Weline\SystemConfig\Api\Scope\ScopeSelectorCatalogInterface;
use Weline\Theme\Service\Scoped\ThemeScopedWorkspaceRequestService;
use Weline\Theme\Service\Scoped\ThemeEditorContextFactory;
use Weline\Theme\Service\Scoped\ThemeLayoutSnapshotNormalizer;
use Weline\Theme\Service\Scoped\ThemeScopedPreviewResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;

/**
 * 主题编辑器控制器
 */
#[Acl('Weline_Theme::theme_visual_editor', '可视化编辑', 'palette', '编辑网站、店铺与渠道主题')]
class ThemeEditor extends BackendController
{
    private const EVENT_THEME_EDITOR_RESULT_AFTER = 'Weline_Theme::theme_editor::result_after';

    private WelineTheme $welineTheme;
    private ThemeLayoutService $layoutService;
    private ThemeLayoutVersionService $versionService;
    private ThemeCacheGenerator $cacheGenerator;
    private WidgetPositionResolver $positionResolver;
    private WidgetRegistryInterface $widgetRegistry;
    private ThemeLayout $themeLayout;
    private PreviewTokenService $previewTokenService;
    private EditorLockService $editorLockService;
    private ParamFormRendererInterface $paramFormRenderer;
    private ThemeEditorMarkupRenderer $editorMarkupRenderer;

    private function useFullscreenEditorLayout(): void
    {
        $this->layoutType = 'fullscreen.default';

        $meta = $this->getTemplate()->getData('meta');
        $meta = is_array($meta) ? $meta : [];
        $meta['showHeader'] = false;
        $meta['showSidebar'] = false;
        $meta['showFooter'] = false;
        $meta['showRightSidebar'] = false;
        $meta['showPageHeader'] = false;

        $this->assign('meta', $meta);
        $this->assign('layoutShowPageHeader', false);
    }

    public function __construct(
        WelineTheme $welineTheme,
        ThemeLayoutService $layoutService,
        ThemeLayoutVersionService $versionService,
        ThemeCacheGenerator $cacheGenerator,
        WidgetPositionResolver $positionResolver,
        WidgetRegistryInterface $widgetRegistry,
        ThemeLayout $themeLayout,
        mixed $meta = null,
        ?PreviewTokenService $previewTokenService = null,
        ?EditorLockService $editorLockService = null,
        ?ParamFormRendererInterface $paramFormRenderer = null,
        ?ThemeEditorMarkupRenderer $editorMarkupRenderer = null,
    ) {
        $this->welineTheme = $welineTheme;
        $this->layoutService = $layoutService;
        $this->versionService = $versionService;
        $this->cacheGenerator = $cacheGenerator;
        $this->positionResolver = $positionResolver;
        $this->widgetRegistry = $widgetRegistry;
        $this->themeLayout = $themeLayout;
        $this->previewTokenService = $previewTokenService
            ?? ObjectManager::getInstance(PreviewTokenService::class);
        $this->editorLockService = $editorLockService
            ?? ObjectManager::getInstance(EditorLockService::class);
        $this->paramFormRenderer = $paramFormRenderer
            ?? ObjectManager::getInstance(ParamFormRendererInterface::class);
        $this->editorMarkupRenderer = $editorMarkupRenderer
            ?? ObjectManager::getInstance(ThemeEditorMarkupRenderer::class);
    }

    private function dispatchThemeEditorResultAfter(string $result, string $action): string
    {
        $eventData = new \Weline\Framework\DataObject\DataObject([
            'action' => $action,
            'result' => $result,
            'controller' => $this,
            'request' => $this->request,
        ]);

        $this->getEventManager()->dispatch(self::EVENT_THEME_EDITOR_RESULT_AFTER, $eventData);

        return (string)$eventData->getData('result');
    }

    /**
     * 判断主题目录是否包含后端（backend）区域
     * 无 backend 目录时可视化编辑仅用前端
     */
    private function themeHasBackendDir(WelineTheme $theme): bool
    {
        $themePath = $theme->getPath();
        if ($themePath === '' || !is_dir($themePath)) {
            return false;
        }
        $base = rtrim($themePath, \DIRECTORY_SEPARATOR);
        $ds = \DIRECTORY_SEPARATOR;
        return is_dir($base . $ds . 'view' . $ds . 'theme' . $ds . 'backend')
            || is_dir($base . $ds . 'theme' . $ds . 'backend')
            || is_dir($base . $ds . 'backend');
    }

    /**
     * 可视化编辑发布主题后清理缓存：清空非全局（非 permanent）缓存池，并失效 FPC/路由/主题运行时缓存。
     * 发布后不调用会导致前台仍显示旧 HTML 或重复部件。
     */
    private function flushFullPageCache(): void
    {
        try {
            ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class)
                ->clearNonGlobalCaches(null, 'theme_editor_publish');
        } catch (\Throwable $e) {
            // A successful publish must not be rolled back only because a
            // best-effort runtime cache invalidation step failed.
        }
    }

    /**
     * 编辑器主页
     */
    public function index()
    {
        $this->useFullscreenEditorLayout();

        $previewContextService = $this->getPreviewContextService();
        $themeContextService = $this->getThemeContextService();
        $requestedThemeId = (int)$this->request->getParam('theme_id', 0);
        $requestedFrontendThemeId = (int)$this->request->getParam(
            'frontend_theme_id',
            (int)$this->request->getParam('preview_theme', $requestedThemeId)
        );
        $requestedBackendThemeId = (int)$this->request->getParam('backend_theme_id', 0);
        $pageType = (string)$this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);
        $previewAreaParam = $this->request->getParam('preview_area', $this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND));
        $editorArea = $previewContextService->normalizeArea(
            (string)$previewAreaParam
        );
        $scopeCatalog = ObjectManager::getInstance(ScopeSelectorCatalogInterface::class)->build(
            (string)$this->request->getParam('scope', PreviewContextService::DEFAULT_SCOPE),
            null,
            $this->themeScopeClaimsFromRequest(),
        );
        $scopeLegacyReadonly = !empty($scopeCatalog['legacy_readonly']);
        $selectedScope = $scopeLegacyReadonly
            ? (string)($scopeCatalog['legacy_scope'] ?? '')
            : (string)$scopeCatalog['selected_scope'];
        $scopeContext = null;
        if (!$scopeLegacyReadonly) {
            // For canonical scopes the binding is authoritative. Request theme
            // ids are legacy navigation hints only and must not survive a
            // failed/partial scoped-workspace read.
            $requestedFrontendThemeId = 0;
            $requestedBackendThemeId = 0;
            try {
                $selectedIdentity = ScopeIdentity::fromArray((array)$scopeCatalog['selected_identity']);
                $scopeContext = ObjectManager::getInstance(ScopeHierarchyInterface::class)
                    ->contextFromIdentity($selectedIdentity);
                /** @var ThemeScopedWorkspaceInterface $scopedWorkspace */
                $scopedWorkspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
                $frontendBinding = $scopedWorkspace->load(new ThemeEditorContext(
                    scope: $scopeContext,
                    area: PreviewContextService::AREA_FRONTEND,
                    resourceType: ThemeEditorContext::RESOURCE_THEME_BINDING,
                ));
                $requestedFrontendThemeId = (int)($frontendBinding['draft_payload']['theme_id'] ?? 0);

                $backendBinding = $scopedWorkspace->load(new ThemeEditorContext(
                    scope: $scopeContext,
                    area: PreviewContextService::AREA_BACKEND,
                    resourceType: ThemeEditorContext::RESOURCE_THEME_BINDING,
                ));
                $requestedBackendThemeId = (int)($backendBinding['draft_payload']['theme_id'] ?? 0);
            } catch (\Throwable) {
                // Setup may still be upgrading; legacy active-theme lookup remains the read fallback.
            }
        }
        $themeListUrl = $this->_url->getBackendUrl('theme/backend');

        $frontendTheme = $requestedFrontendThemeId > 0
            ? $this->loadThemeModel($requestedFrontendThemeId)
            : $themeContextService->resolveTheme(PreviewContextService::AREA_FRONTEND);
        if (!$frontendTheme?->getId()) {
            /*
            $this->getMessageManager()->addError(__('绯荤粺娌℃湁鍙敤鐨勫墠绔富棰橈紝璇峰厛婵€娲绘垨閫夋嫨涓€涓墠绔富棰樸€?));
            */
            $this->getMessageManager()->addError(__('No available frontend theme. Please activate or select one first.'));
            return $this->redirect($themeListUrl);
        }

        $frontendHasBackend = $this->themeHasBackendDir($frontendTheme);
        $backendTheme = null;
        if ($requestedBackendThemeId > 0) {
            $candidateBackendTheme = $this->loadThemeModel($requestedBackendThemeId);
            if ($candidateBackendTheme?->getId() && $themeContextService->themeSupportsArea($candidateBackendTheme, PreviewContextService::AREA_BACKEND)) {
                $backendTheme = $candidateBackendTheme;
            } else {
                /*
                $this->getMessageManager()->addWarning(__('鎵€閫夊悗鍙颁富棰樹笉鍙敤锛屽凡鑷姩鍥為€€鍒板綋鍓嶅惎鐢ㄧ殑鍚庡彴涓婚銆?));
                */
                $this->getMessageManager()->addWarning(__('Selected backend theme is unavailable, fallback to the active backend theme.'));
            }
        }
        if (!$backendTheme?->getId() && $frontendHasBackend) {
            $backendTheme = $this->loadThemeModel((int)$frontendTheme->getId());
        }
        if (!$backendTheme?->getId()) {
            $backendTheme = $themeContextService->resolveTheme(PreviewContextService::AREA_BACKEND);
        }
        if (!$backendTheme?->getId() && $frontendHasBackend) {
            $backendTheme = $this->loadThemeModel((int)$frontendTheme->getId());
        }

        $context = $previewContextService->buildContext([
            'frontend_theme_id' => (int)$frontendTheme->getId(),
            'backend_theme_id' => (int)($backendTheme?->getId() ?: 0),
            'editor_area' => $editorArea,
            'shell' => PreviewContextService::SHELL_THEME_EDITOR,
            'preview_mode' => (string)$this->request->getParam('preview_mode', PreviewContextService::DEFAULT_PREVIEW_MODE),
            'status' => (string)$this->request->getParam('status', PreviewContextService::DEFAULT_STATUS),
            'version_id' => (int)$this->request->getParam('version_id', 0) ?: null,
            // The catalog is the authoritative Scope boundary. Keeping a raw
            // request string here would let a stale/forged Scope leak into the
            // preview token and legacy projection while the editor itself is
            // already using a different canonical typed identity.
            'scope' => $selectedScope !== '' ? $selectedScope : PreviewContextService::DEFAULT_SCOPE,
            'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
            'target_value' => $pageType,
        ]);
        $context = $previewContextService->ensureThemeIds($context, true, true);
        if (!$this->hasExplicitThemeLayoutRuntimeTargetRequest()) {
            $context = $this->clearThemeLayoutRuntimeTarget($context);
        }
        if ($editorArea === PreviewContextService::AREA_BACKEND
            && $previewContextService->getThemeIdForArea(PreviewContextService::AREA_BACKEND, $context, false) <= 0) {
            $context['editor_area'] = PreviewContextService::AREA_FRONTEND;
        }
        $context = $previewContextService->persistContext($context);
        $editorArea = (string)$context['editor_area'];
        $frontendThemeId = $previewContextService->getThemeIdForArea(PreviewContextService::AREA_FRONTEND, $context, true);
        $backendThemeId = $previewContextService->getThemeIdForArea(PreviewContextService::AREA_BACKEND, $context, true);
        $currentThemeId = $previewContextService->getThemeIdForArea($editorArea, $context, true);
        $currentTheme = $editorArea === PreviewContextService::AREA_BACKEND
            ? ($backendTheme ?: $this->loadThemeModel($currentThemeId))
            : ($frontendTheme ?: $this->loadThemeModel($currentThemeId));
        $layoutOptionsByType = $currentTheme?->getId()
            ? $this->getEditorLayoutOptionsByType($currentTheme, $editorArea)
            : [];
        $requestedLayoutOption = (string)$this->request->getParam('layout_option', '');
        $layoutOption = $currentTheme?->getId()
            ? $this->resolveSelectedLayoutOption(
                $currentTheme,
                $editorArea,
                $pageType,
                $layoutOptionsByType,
                $requestedLayoutOption,
                (string)($context['scope'] ?? PreviewContextService::DEFAULT_SCOPE)
            )
            : ($requestedLayoutOption !== '' ? $requestedLayoutOption : 'default');
        $pageTypes = $this->mergeLayoutTypesWithEditorOptions(ThemeLayout::getPageTypes(), $layoutOptionsByType, $pageType);
        $layoutEditorLock = $this->buildLayoutEditorLock($currentThemeId, $editorArea, $pageType, $layoutOption, $requestedLayoutOption);
        if (!empty($layoutEditorLock['enabled'])) {
            $lockedLayoutOption = $this->normalizeLayoutOption((string)($layoutEditorLock['layout_option'] ?? ''));
            if ($lockedLayoutOption !== '') {
                $layoutOption = $lockedLayoutOption;
                $hasLockedOption = false;
                foreach (($layoutOptionsByType[$pageType] ?? []) as $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    if ($this->normalizeLayoutOption((string)($option['value'] ?? '')) === $lockedLayoutOption) {
                        $hasLockedOption = true;
                        break;
                    }
                }
                if (!$hasLockedOption) {
                    $layoutOptionsByType[$pageType] ??= [];
                    $layoutOptionsByType[$pageType][] = [
                        'value' => $lockedLayoutOption,
                        'label' => $lockedLayoutOption,
                        'description' => 'Virtual layout locked option',
                        'file' => '',
                    ];
                }
            }
        }

        $themesCollection = $this->welineTheme->reset()->select()->fetch()->getItems();
        $themesById = [];
        foreach ($themesCollection as $themeItem) {
            $data = is_object($themeItem) ? $themeItem->getData() : (is_array($themeItem) ? $themeItem : []);
            $tid = (int)($data['id'] ?? 0);
            if ($tid && !isset($themesById[$tid])) {
                $themesById[$tid] = $data + [
                    'has_backend_area' => $this->themeRecordHasBackendArea($data),
                ];
            }
        }
        $themes = array_values($themesById);

        $layout = [];
        $hasDraft = false;
        $layoutIdentity = $this->resolveVersionLayoutIdentity([
            'layout_option' => $layoutOption,
        ]);
        if (!empty($layoutEditorLock['enabled'])) {
            $layoutIdentity = [
                'layout_option' => (string)($layoutEditorLock['layout_option'] ?? 'default'),
                'scope' => (string)($layoutEditorLock['scope'] ?? PreviewContextService::DEFAULT_SCOPE),
                'locale_code' => (string)($layoutEditorLock['locale_code'] ?? $this->request->getParam('locale', '')),
                'target_type' => (string)($layoutEditorLock['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL),
                'target_id' => (int)($layoutEditorLock['target_id'] ?? 0),
            ];
        }
        if ($currentThemeId) {
            $scopedLayoutMaterialized = false;
            if ($scopeContext !== null) {
                try {
                    $typedLayoutContext = new ThemeEditorContext(
                        scope: $scopeContext,
                        area: $editorArea,
                        resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
                        themeId: $currentThemeId,
                        layoutType: $pageType,
                        layoutOption: $layoutOption,
                        locale: (string)($layoutIdentity['locale_code'] ?? '') !== ''
                            ? (string)$layoutIdentity['locale_code']
                            : 'default',
                        targetType: (string)($layoutIdentity['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL),
                        targetId: (int)($layoutIdentity['target_id'] ?? 0),
                    );
                    /** @var ThemeScopedWorkspaceInterface $scopedWorkspace */
                    $scopedWorkspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
                    $scopedState = $scopedWorkspace->load($typedLayoutContext, true);
                    /** @var ThemeScopedResourceAdapterInterface $adapter */
                    $adapter = ObjectManager::getInstance(ThemeScopedResourceAdapterInterface::class);
                    $adapter->projectDraft(
                        $typedLayoutContext,
                        \is_array($scopedState['draft_payload'] ?? null) ? $scopedState['draft_payload'] : [],
                    );
                    $layoutIdentity = $this->layoutIdentityFromEditorContext($typedLayoutContext);
                    $scopedLayoutMaterialized = true;
                } catch (\Throwable) {
                    // Setup upgrades retain the old draft initialization path.
                }
            }
            $hasDraft = $this->layoutService->hasDraft($currentThemeId, $pageType, $layoutIdentity);
            if (!$scopeLegacyReadonly
                && !$scopedLayoutMaterialized
                && !$hasDraft
                && !$this->hasEmptyCurrentRestoreVersion($currentThemeId, $pageType, $layoutIdentity)
            ) {
                $this->layoutService->initDraftFromPublished($currentThemeId, $pageType, $layoutIdentity);
            }
            $layout = $this->layoutService->getFullDraftLayout($currentThemeId, $pageType, $layoutIdentity);
            // 打开/刷新编辑器不得按 default_injections 自动回填空 slot。
            // 默认部件仅在主题初始化、草稿重置、「应用」tab / 显式 slot 初始化、部件首次入库或 Dashboard view ready 时写入。
        }

        // 部件库改为前端异步加载（页面与主预览就绪后再拉取），首屏不再同步渲染全部部件预览，
        // 避免阻塞编辑器首屏与主预览加载。前端通过 theme-editor/widgets 接口按当前主题获取。
        $availableWidgets = [];
        $structureWidgetsHtml = [
            'header' => '',
            'content' => '',
            'footer' => '',
        ];
        foreach (array_keys($structureWidgetsHtml) as $areaCode) {
            $areaWidgets = $layout[$areaCode]['widgets'] ?? [];
            if (!is_array($areaWidgets)) {
                $areaWidgets = [];
            }
            foreach ($areaWidgets as $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                $structureWidgetsHtml[$areaCode] .= (string)$this->getTemplate()->fetchTagHtml(
                    'templates',
                    'Weline_Theme::backend/ThemeEditor/widget-item.phtml',
                    ['widget' => $widget]
                );
            }
            if ($structureWidgetsHtml[$areaCode] === '') {
                $structureWidgetsHtml[$areaCode] = $this->editorMarkupRenderer->renderStructurePlaceholder($areaCode);
            }
        }
        $compactLayoutOptions = $this->compactEditorLayoutOptions($layoutOptionsByType);
        $currentLayoutOptions = $compactLayoutOptions[$pageType] ?? [];
        if ($currentLayoutOptions === []) {
            $currentLayoutOptions = [[
                'value' => $layoutOption,
                'label' => $layoutOption === 'default' ? (string)__('Default') : $layoutOption,
                'description' => '',
                'file' => '',
            ]];
        }
        $installedLocales = $this->getInstalledLocalesPayload();
        $themeOptionsHtml = $this->editorMarkupRenderer->renderThemeOptions($themes, $currentThemeId);
        $pageTypeOptionsHtml = $this->editorMarkupRenderer->renderPageTypeOptions($pageTypes, $pageType);
        $layoutOptionsHtml = $this->editorMarkupRenderer->renderLayoutOptions($currentLayoutOptions, $layoutOption);
        $editorAreaOptionsHtml = $this->editorMarkupRenderer->renderEditorAreaOptions(
            $frontendHasBackend || $backendThemeId > 0,
            $editorArea
        );
        $localeOptionsHtml = $this->editorMarkupRenderer->renderLocaleOptions($installedLocales);
        $widgetLibraryHtml = $this->editorMarkupRenderer->renderWidgetLibrary($availableWidgets, $editorArea);
        $this->assign('theme_id', $currentThemeId);
        $this->assign('theme', $currentTheme);
        $this->assign('current_theme', $currentTheme);
        $this->assign('frontend_theme', $frontendTheme);
        $this->assign('backend_theme', $backendTheme);
        $this->assign('frontend_theme_id', $frontendThemeId);
        $this->assign('backend_theme_id', $backendThemeId);
        $this->assign('themes', $themes);
        $this->assign('page_type', $pageType);
        $this->assign('layout_option', $layoutOption);
        $this->assign('layout_identity', $layoutIdentity);
        $this->assign('layout_options_by_type', $compactLayoutOptions);
        $this->assign('page_types', $pageTypes);
        $this->assign('areas', ThemeLayout::getAreas());
        $this->assign('editor_area', $editorArea);
        $this->assign('theme_has_backend', $frontendHasBackend || $backendThemeId > 0);
        $this->assign('preview_context', $context);
        $this->assign('layout_editor_lock', $layoutEditorLock);
        $this->assign('layout', $layout);
        $this->assign('structure_widgets_html', $structureWidgetsHtml);
        $this->assign('available_widgets', $availableWidgets);
        $this->assign('installed_locales', $installedLocales);
        $this->assign('theme_options_html', $themeOptionsHtml);
        $this->assign('scope_identity', $scopeLegacyReadonly ? [] : $scopeCatalog['selected_identity']);
        $this->assign('selected_scope', $selectedScope);
        $this->assign('scope_legacy_readonly', $scopeLegacyReadonly);
        $this->assign('page_type_options_html', $pageTypeOptionsHtml);
        $this->assign('layout_options_html', $layoutOptionsHtml);
        $this->assign('editor_area_options_html', $editorAreaOptionsHtml);
        $this->assign('locale_options_html', $localeOptionsHtml);
        $this->assign('widget_library_html', $widgetLibraryHtml);
        $this->assign('has_draft', $hasDraft);

        return $this->fetch('Weline_Theme::templates/backend/ThemeEditor/index.phtml');
    }

    /** Load the canonical draft/effective state for one typed Theme resource. */
    #[Acl(
        'Weline_Theme::theme_visual_editor_scope_read',
        '查看主题 Scope 工作区',
        'eye',
        '读取主题 Scope 草稿、继承来源与冲突',
        'Weline_Theme::theme_visual_editor',
        accessMode: Acl::ACCESS_MODE_READ,
    )]
    public function getScopedWorkspace()
    {
        return $this->fetchJson($this->scopedWorkspacePayload('load'));
    }

    /** Apply incremental per-path changes; legacy scope strings are never accepted here. */
    #[Acl(
        'Weline_Theme::theme_visual_editor_scope_edit',
        '编辑主题 Scope 草稿',
        'edit',
        '写入主题 Scope 逐路径覆盖或恢复继承',
        'Weline_Theme::theme_visual_editor',
        accessMode: Acl::ACCESS_MODE_EDIT,
    )]
    public function postScopedWorkspace()
    {
        return $this->fetchJson($this->scopedWorkspacePayload('apply'));
    }

    /** Publish one scoped resource and rebase existing descendant workspaces. */
    #[Acl(
        'Weline_Theme::theme_visual_editor_scope_publish',
        '发布主题 Scope',
        'upload',
        '发布主题 Scope 并重算后代有效版本',
        'Weline_Theme::theme_visual_editor',
        accessMode: Acl::ACCESS_MODE_EDIT,
    )]
    public function postPublishScopedWorkspace()
    {
        return $this->fetchJson($this->scopedWorkspacePayload('publish'));
    }

    /** @return array<string,mixed> */
    private function scopedWorkspacePayload(string $operation): array
    {
        try {
            $input = $this->getEditorJsonPayload();
            /** @var ThemeScopedWorkspaceRequestService $service */
            $service = ObjectManager::getInstance(ThemeScopedWorkspaceRequestService::class);
            $data = match ($operation) {
                'load' => $service->load($input),
                'apply' => $service->apply(
                    $input,
                    'backend-user:' . (string)($this->session->getUserId() ?? 0),
                    (string)($this->session->getUsername() ?? ''),
                ),
                'publish' => $service->publish(
                    $input,
                    'backend-user:' . (string)($this->session->getUserId() ?? 0),
                    (string)($this->session->getUsername() ?? ''),
                ),
                default => throw new \InvalidArgumentException('theme_scope_operation_invalid'),
            };

            return ['success' => true, 'data' => $data];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Publish every dirty resource represented by the current typed preview context.
     *
     * @return array<string,array<string,mixed>>
     */
    private function publishPendingScopedResources(ThemeEditorContext $baseContext, string $reason): array
    {
        /** @var ThemeScopedWorkspaceInterface $workspace */
        $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
        /** @var ThemeScopedWorkspaceRequestService $requests */
        $requests = ObjectManager::getInstance(ThemeScopedWorkspaceRequestService::class);
        $results = [];
        foreach (ThemeEditorContext::RESOURCES as $resourceType) {
            $context = $baseContext->withResource($resourceType);
            $state = $workspace->load($context, true);
            $draftRevisionId = (int)($state['draft_revision_id'] ?? 0);
            $publishedRevisionId = (int)($state['published_revision_id'] ?? 0);
            if ((int)($state['revision'] ?? 0) <= 0
                || $draftRevisionId <= 0
                || $draftRevisionId === $publishedRevisionId
            ) {
                continue;
            }
            $results[$resourceType] = $requests->publish([
                'editor_context' => $context->toArray(),
                'expected_revision' => (int)$state['revision'],
                'expected_parent_release_id' => $state['expected_parent_release_id'] ?? null,
                'reason' => $reason,
            ], 'backend-user:' . (string)($this->session->getUserId() ?? 0), (string)($this->session->getUsername() ?? ''));
        }

        return $results;
    }

    /** @return array<string,mixed> */
    private function replaceScopedLayoutDraftFromSnapshot(
        ThemeEditorContext $context,
        array $snapshot,
        string $summary,
    ): array {
        /** @var ThemeScopedWorkspaceInterface $workspace */
        $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
        /** @var ThemeLayoutSnapshotNormalizer $normalizer */
        $normalizer = ObjectManager::getInstance(ThemeLayoutSnapshotNormalizer::class);
        $scopedContext = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $state = $workspace->load($scopedContext, true);

        try {
            return $workspace->replaceEffectivePayload(
                context: $scopedContext,
                expectedRevision: (int)($state['revision'] ?? 0),
                expectedParentReleaseId: isset($state['expected_parent_release_id'])
                    ? (int)$state['expected_parent_release_id']
                    : null,
                effectivePayload: $normalizer->normalize($context, $snapshot),
                actorId: 'backend-user:' . (string)($this->session->getUserId() ?? 0),
                actorName: (string)($this->session->getUsername() ?? ''),
                summary: $summary,
            );
        } catch (\Throwable $e) {
            // Legacy routes may already have updated their rebuildable draft
            // projection. Restore it from the unchanged canonical workspace when
            // semantic conversion or optimistic validation fails.
            try {
                $previousPayload = $state['draft_payload'] ?? null;
                if (is_array($previousPayload)) {
                    ObjectManager::getInstance(ThemeScopedResourceAdapterInterface::class)
                        ->projectDraft($scopedContext, $previousPayload);
                }
            } catch (\Throwable $projectionError) {
                \Weline\Framework\App\Env::log_error(
                    'theme_scope_projection',
                    'Theme layout compatibility compensation failed: ' . $projectionError->getMessage(),
                );
            }
            throw $e;
        }
    }

    private function saveScopedLayoutVersion(
        ThemeEditorContext $context,
        ?string $name,
        ?string $description,
    ): ThemeLayoutVersion {
        $snapshot = $this->scopedLayoutSnapshot($context);

        return $this->versionService->saveSnapshotVersion(
            themeId: $context->themeId,
            pageType: $context->layoutType,
            snapshotData: $snapshot,
            name: $name,
            description: $description,
            userId: (int)($this->session->getUserId() ?? 0) ?: null,
            identity: $this->layoutIdentityFromEditorContext($context),
        );
    }

    /** @return array<string,mixed> */
    private function scopedLayoutSnapshot(ThemeEditorContext $context): array
    {
        /** @var ThemeScopedWorkspaceInterface $workspace */
        $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
        /** @var ThemeLayoutSnapshotNormalizer $normalizer */
        $normalizer = ObjectManager::getInstance(ThemeLayoutSnapshotNormalizer::class);
        $state = $workspace->load($context->withResource(ThemeEditorContext::RESOURCE_LAYOUT), true);
        if ((int)($state['draft_revision_id'] ?? 0) <= 0
            && (int)($state['published_release_id'] ?? 0) <= 0
        ) {
            throw new \RuntimeException('theme_scoped_layout_workspace_missing');
        }
        $payload = is_array($state['draft_payload'] ?? null) ? $state['draft_payload'] : [];

        return $normalizer->denormalize($context, $payload);
    }

    /** @return array<string,mixed> */
    private function normalizeLegacyLayoutSnapshot(ThemeEditorContext $context, array $snapshot): array
    {
        /** @var ThemeLayoutSnapshotNormalizer $normalizer */
        $normalizer = ObjectManager::getInstance(ThemeLayoutSnapshotNormalizer::class);

        return $normalizer->denormalize($context, $normalizer->normalize($context, $snapshot));
    }

    /** @return array<string,mixed> */
    private function themeScopeClaimsFromRequest(): array
    {
        if (trim((string)$this->request->getParam('scope_kind', '')) === '') {
            return [];
        }

        return [
            'scope_kind' => (string)$this->request->getParam('scope_kind'),
            'website_id' => $this->nullableRequestInt('website_id'),
            'website_code' => $this->nullableRequestString('website_code'),
            'store_code' => $this->nullableRequestString('store_code'),
            'channel_code' => $this->nullableRequestString('channel_code'),
            'store_mode' => $this->nullableRequestString('store_mode'),
            'context_version' => (string)$this->request->getParam('context_version', 'v1'),
        ];
    }

    private function nullableRequestInt(string $key): ?int
    {
        $value = $this->request->getParam($key, null);

        return $value === null || $value === '' ? null : (int)$value;
    }

    private function nullableRequestString(string $key): ?string
    {
        $value = trim((string)$this->request->getParam($key, ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLayoutEditorLock(
        int $themeId,
        string $area,
        string $pageType,
        string $layoutOption,
        string $requestedLayoutOption = ''
    ): array
    {
        $enabled = $this->isTruthyParam('lock_layout')
            || $this->isTruthyParam('layout_locked')
            || $this->isTruthyParam('lock_layout_context');
        if (!$enabled) {
            return ['enabled' => false];
        }

        $lockLayoutOption = $this->normalizeLayoutOption($requestedLayoutOption);
        if ($lockLayoutOption === '') {
            $lockLayoutOption = $this->normalizeLayoutOption($layoutOption);
        }
        $lockTargetType = (string)$this->request->getParam(
            'virtual_target_type',
            (string)$this->request->getParam(
                'layout_lock_target_type',
                (string)$this->request->getParam(
                    'theme_layout_target_type',
                    (string)$this->request->getParam('theme_layout_source_target_type', ThemeVirtualLayout::TARGET_GLOBAL)
                )
            )
        );
        $lockTargetId = (int)$this->request->getParam(
            'virtual_target_id',
            (int)$this->request->getParam(
                'layout_lock_target_id',
                (int)$this->request->getParam(
                    'theme_layout_target_id',
                    (int)$this->request->getParam(
                        'theme_layout_source_target_id',
                        (int)$this->request->getParam('target_id', 0)
                    )
                )
            )
        );

        return [
            'enabled' => true,
            'theme_id' => $themeId,
            'area' => $area === PreviewContextService::AREA_BACKEND ? PreviewContextService::AREA_BACKEND : PreviewContextService::AREA_FRONTEND,
            'page_type' => $pageType,
            'layout_type' => $pageType,
            'layout_option' => $lockLayoutOption !== '' ? $lockLayoutOption : 'default',
            'scope' => (string)$this->request->getParam('scope', PreviewContextService::DEFAULT_SCOPE),
            'locale_code' => (string)$this->request->getParam('locale_code', $this->request->getParam('locale', '')),
            'store_mode' => (string)$this->request->getParam('store_mode', \Weline\Framework\Runtime\ScopeIdentity::MODE_NORMAL),
            'website_id' => max(0, (int)$this->request->getParam('website_id', 0)),
            'website_code' => (string)$this->request->getParam('website_code', ''),
            'store_id' => max(0, (int)$this->request->getParam('store_id', 0)),
            'store_code' => (string)$this->request->getParam('store_code', ''),
            'target_type' => $this->normalizeVirtualLayoutTargetType($lockTargetType),
            'target_id' => max(0, $lockTargetId),
            'source' => (string)$this->request->getParam('lock_source', 'external'),
            'lock_source' => (string)$this->request->getParam('lock_source', 'external'),
        ];
    }

    private function isTruthyParam(string $key): bool
    {
        $value = $this->request->getParam($key, '');
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeVirtualLayoutTargetType(string $targetType): string
    {
        $targetType = strtolower(trim($targetType));
        if ($targetType === '') {
            return ThemeVirtualLayout::TARGET_GLOBAL;
        }

        /** @var ThemeTargetTypeRegistry $targetTypeRegistry */
        $targetTypeRegistry = ObjectManager::getInstance(ThemeTargetTypeRegistry::class);
        if (!$targetTypeRegistry->has($targetType)) {
            throw new \InvalidArgumentException((string)__('未注册的主题目标类型：%{1}', [$targetType]));
        }

        return $targetType;
    }

    /**
     * Theme layout runtime targets are more specific than preview context target_type.
     * A plain layout editor URL must not inherit an older cms_page/product/category target.
     */
    private function hasExplicitThemeLayoutRuntimeTargetRequest(): bool
    {
        foreach ([
            'theme_layout_target_type',
            'theme_layout_target_id',
            'theme_layout_source_target_type',
            'theme_layout_source_target_id',
            'virtual_target_type',
            'virtual_target_id',
            'layout_lock_target_type',
            'layout_lock_target_id',
        ] as $key) {
            if (trim((string)$this->request->getParam($key, '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function clearThemeLayoutRuntimeTarget(array $context): array
    {
        foreach ([
            'theme_layout_target_type',
            'theme_layout_target_id',
            'theme_layout_source_target_type',
            'theme_layout_source_target_id',
            'layout_lock_target_type',
            'layout_lock_target_id',
            'virtual_target_type',
            'virtual_target_id',
            'target_id',
        ] as $key) {
            unset($context[$key]);
        }

        return $context;
    }

    /**
     * 获取布局数据 (Query) - 读取草稿数据
     */
    public function getLayout()
    {
        $themeId = (int)$this->request->getParam('theme_id');
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请选择主题'),
            ]);
        }
        
        // 验证主题是否存在
        $this->welineTheme->reset()->load($themeId);
        if (!$this->welineTheme->getId()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('主题 ID %{1} 不存在', $themeId),
            ]);
        }

        // GET 仅读取现有兼容投影；草稿初始化由 typed Scope 工作区负责。
        $identity = $this->resolveVersionLayoutIdentity();

        // 读取草稿布局（必须带 Dashboard view identity，否则会落到 default.default.default 空布局）
        $layout = $this->layoutService->getFullDraftLayout($themeId, $pageType, $identity);

        return $this->fetchJson([
            'success' => true,
            'data' => $layout,
            'has_draft' => $this->layoutService->hasDraft($themeId, $pageType, $identity),
            'identity' => $identity,
        ]);
    }

    /**
     * 获取部件列表 (Query)
     * 
     * 参数：
     * - page_type: 页面类型（可选），用于过滤部件
     */
    public function getWidgets()
    {
        $pageType = $this->request->getParam('page_type', null);
        $editorArea = (string)$this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND);
        $editorArea = $editorArea === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;
        $theme = $this->resolveEditorThemeFromRequest();
        $filterOptions = $editorArea === PreviewContextService::AREA_BACKEND
            ? ['editor_area' => PreviewContextService::AREA_BACKEND]
            : [];
        $filterOptions = array_merge($filterOptions, $this->getWidgetLibraryFilterOptionsFromRequest());

        $limit = (int)$this->request->getParam('limit', 0);
        $offset = max(0, (int)$this->request->getParam('offset', 0));
        $slotId = trim((string)$this->request->getParam('slot_id', ''));
        $keyword = trim((string)$this->request->getParam('keyword', ''));

        // 获取分组元数据（不渲染预览，开销低）。带 slot_id 时按插槽过滤。
        if ($slotId !== '') {
            $slotArea = $this->request->getParam('area', null);
            $acceptCodes = $this->normalizeSlotCodeParam($this->request->getParam('accept', []));
            $rejectCodes = $this->normalizeSlotCodeParam($this->request->getParam('reject', []));
            $slotResult = $this->layoutService->getWidgetsForSlot(
                $slotId,
                $slotArea,
                $pageType,
                $acceptCodes,
                $rejectCodes,
                $theme,
                $editorArea,
                $filterOptions
            );
            $groups = $this->buildSlotWidgetGroups($slotResult);
        } else {
            $groups = $this->layoutService->getAvailableWidgets($pageType, $filterOptions, $editorArea, $theme);
        }

        // 未指定 limit：返回完整分组元数据（用于配置查找等，不渲染预览，保持轻量）
        if ($limit <= 0) {
            return $this->fetchJson([
                'success' => true,
                'data' => $groups,
                'page_type' => $pageType,
                'theme_id' => $theme?->getId() ?: 0,
                'editor_area' => $editorArea,
            ]);
        }

        // 分页模式：拍平 -> 关键词过滤 -> 切片 -> 仅对切片渲染预览
        $flat = [];
        foreach ($groups as $type => $group) {
            if (!is_array($group)) {
                continue;
            }
            $groupLabel = (string)($group['label'] ?? $type);
            foreach (($group['widgets'] ?? []) as $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                $widget['group_type'] = (string)$type;
                $widget['group_label'] = $groupLabel;
                $flat[] = $widget;
            }
        }

        if ($keyword !== '') {
            $kw = mb_strtolower($keyword);
            $flat = array_values(array_filter($flat, static function (array $w) use ($kw): bool {
                $haystack = mb_strtolower(
                    (string)($w['name'] ?? '') . ' '
                    . (string)($w['code'] ?? '') . ' '
                    . (string)($w['description'] ?? '')
                );
                return mb_strpos($haystack, $kw) !== false;
            }));
        }

        $total = count($flat);
        $slice = array_slice($flat, $offset, $limit);
        foreach ($slice as &$widget) {
            $widget['preview_html'] = $this->buildWidgetPreviewHtml($widget, $theme, $editorArea);
        }
        unset($widget);

        return $this->fetchJson([
            'success' => true,
            'items' => $slice,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => ($offset + $limit) < $total,
            'slot_id' => $slotId,
            'keyword' => $keyword,
            'page_type' => $pageType,
            'theme_id' => $theme?->getId() ?: 0,
            'editor_area' => $editorArea,
        ]);
    }

    /**
     * 获取当前布局缺失的默认注入部件 (Query)
     */
    public function getDefaultInjections()
    {
        $themeId = (int)$this->request->getParam('theme_id', 0);
        $pageType = (string)$this->request->getParam(
            'page_type',
            $this->request->getParam('layout_type', ThemeLayout::PAGE_TYPE_HOME)
        );
        $editorArea = (string)$this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND);
        $editorArea = $editorArea === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;
        $keyword = trim((string)$this->request->getParam('keyword', ''));
        $identity = $this->resolveVersionLayoutIdentity();

        if ($themeId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请选择主题'),
                'items' => [],
                'total' => 0,
            ]);
        }

        try {
            /** @var WidgetDefaultInjectionService $service */
            $service = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
            // 「应用」Tab 需要看到全部声明（含已应用的万能评论等），不能只返回缺失项。
            $items = $service->getDeclaredForLayout($themeId, $pageType, $identity, $editorArea, $keyword);
            $pendingTotal = 0;
            foreach ($items as $item) {
                if (($item['injection_status'] ?? '') === 'missing') {
                    $pendingTotal++;
                }
            }

            return $this->fetchJson([
                'success' => true,
                'items' => $items,
                'total' => count($items),
                'pending_total' => $pendingTotal,
                'theme_id' => $themeId,
                'page_type' => $pageType,
                'layout_option' => $identity['layout_option'],
                'editor_area' => $editorArea,
            ]);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
                'items' => [],
                'total' => 0,
            ]);
        }
    }

    /**
     * 按 default_injections 声明位置重新应用部件 (Query)
     */
    public function postApplyDefaultInjection()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            $data = is_array($decoded) ? $decoded : [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)(
            $data['page_type']
            ?? $data['layout_type']
            ?? $this->request->getParam('page_type', $this->request->getParam('layout_type', ThemeLayout::PAGE_TYPE_HOME))
        );
        $injectionKey = trim((string)($data['injection_key'] ?? $this->request->getParam('injection_key', '')));
        $editorArea = (string)($data['editor_area'] ?? $this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND));
        $editorArea = $editorArea === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;
        $applyScope = strtolower(trim((string)($data['apply_scope'] ?? $this->request->getParam('apply_scope', 'current'))));
        $applyScope = in_array($applyScope, ['all', 'all_identities', 'layout'], true) ? 'all' : 'current';

        if ($themeId <= 0 || $pageType === '' || $injectionKey === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $themeId = $context->themeId;
            $pageType = $context->layoutType;
            $editorArea = $context->area;
            $identity = $this->layoutIdentityFromEditorContext($context);
            if ($applyScope === 'all') {
                throw new \InvalidArgumentException('theme_editor_bulk_identity_write_forbidden');
            }
            /** @var WidgetDefaultInjectionService $service */
            $service = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
            $item = $service->applyInjectionByKey(
                $themeId,
                $pageType,
                $injectionKey,
                $identity,
                ThemeLayout::STATUS_DRAFT,
                $editorArea
            );
            $appliedCount = $item && !empty($item['layout_id']) ? 1 : 0;
            $skippedCount = $appliedCount > 0 ? 0 : 1;
            $totalIdentities = 1;

            if ($appliedCount <= 0 || !$item || empty($item['layout_id'])) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $applyScope === 'all'
                        ? __('该推荐部件已在所有布局身份中')
                        : __('该推荐部件已在当前布局中'),
                ]);
            }

            ObjectManager::getInstance(SlotRendererService::class)->clearCache();
            $previewHtml = $this->buildPreviewHtmlForLayoutId((int)$item['layout_id'], $item['config'] ?? []);
            $savedLayout = clone $this->themeLayout;
            $savedLayout->clearData()->clearQuery()->load((int)$item['layout_id']);
            $item['node_uid'] = $savedLayout->getNodeUid();
            $snapshot = $this->layoutService->getLayout(
                $themeId,
                $pageType,
                ThemeLayout::STATUS_DRAFT,
                $identity,
            );
            $scopedDraft = $this->replaceScopedLayoutDraftFromSnapshot(
                $context,
                $snapshot,
                'Apply declared default widget injection',
            );

            $item['apply_scope'] = $applyScope;
            $item['applied_count'] = $appliedCount;
            $item['skipped_count'] = $skippedCount;
            $item['total_identities'] = $totalIdentities;
            $item['scoped_workspace'] = $scopedDraft;
            $response = [
                'success' => true,
                'message' => $applyScope === 'all' ? __('已应用到所有布局身份') : __('已应用推荐部件'),
                'data' => $item,
                'apply_scope' => $applyScope,
                'applied_count' => $appliedCount,
                'skipped_count' => $skippedCount,
                'total_identities' => $totalIdentities,
            ];
            if ($previewHtml !== null) {
                $response['preview_html'] = $previewHtml;
            }

            return $this->fetchJson($response);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 插槽工具条「初始化」：按 default_injections 显式回填指定 slot (Query)
     */
    public function postInitSlotDefaults()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            $data = is_array($decoded) ? $decoded : [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)(
            $data['page_type']
            ?? $data['layout_type']
            ?? $this->request->getParam('page_type', $this->request->getParam('layout_type', ThemeLayout::PAGE_TYPE_HOME))
        );
        $slotId = trim((string)($data['slot_id'] ?? $this->request->getParam('slot_id', '')));
        $editorArea = (string)($data['editor_area'] ?? $this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND));
        $editorArea = $editorArea === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;

        if ($themeId <= 0 || $pageType === '' || $slotId === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $themeId = $context->themeId;
            $pageType = $context->layoutType;
            $editorArea = $context->area;
            $identity = $this->layoutIdentityFromEditorContext($context);

            /** @var WidgetDefaultInjectionService $service */
            $service = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
            $result = $service->initSlotDefaultInjections(
                $themeId,
                $pageType,
                $identity,
                $slotId,
                $editorArea,
                ThemeLayout::STATUS_DRAFT
            );

            ObjectManager::getInstance(SlotRendererService::class)->clearCache();
            $snapshot = $this->layoutService->getLayout(
                $themeId,
                $pageType,
                ThemeLayout::STATUS_DRAFT,
                $identity,
            );
            $scopedDraft = $this->replaceScopedLayoutDraftFromSnapshot(
                $context,
                $snapshot,
                'Init slot default widget injections',
            );

            return $this->fetchJson([
                'success' => true,
                'message' => ($result['applied_defaults'] ?? 0) > 0
                    ? __('已初始化插槽默认部件')
                    : __('该插槽暂无缺失的默认部件'),
                'data' => [
                    'slot_id' => $slotId,
                    'cleared_user_deleted' => (int)($result['cleared_user_deleted'] ?? 0),
                    'applied_defaults' => (int)($result['applied_defaults'] ?? 0),
                    'items' => $result['items'] ?? [],
                    'scoped_workspace' => $scopedDraft,
                ],
            ]);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 将 getWidgetsForSlot 的结果（独占/普通/精确匹配）合并去重为「按部件类型分组」结构，
     * 供分页拍平复用。去重以 module + code 为准。
     *
     * @param array $slotResult
     * @return array<string, array{label:string, widgets:array}>
     */
    private function buildSlotWidgetGroups(array $slotResult): array
    {
        $merged = array_merge(
            $slotResult['exclusive_widgets'] ?? [],
            $slotResult['matched_widgets'] ?? [],
            $slotResult['regular_widgets'] ?? []
        );

        $groups = [];
        $seen = [];
        foreach ($merged as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $dedupeKey = (string)($widget['module'] ?? '') . '::' . (string)($widget['code'] ?? '');
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $type = (string)($widget['type'] ?? 'other');
            if (!isset($groups[$type])) {
                $groups[$type] = [
                    'label' => $type,
                    'widgets' => [],
                ];
            }
            $groups[$type]['widgets'][] = $widget;
        }

        return $groups;
    }
    
    /**
     * 获取指定 slot 的推荐部件 (Query)
     * 
     * 精细筛选逻辑：
     * - 顶层独占区域（header/footer）：返回独占大部件
     * - 子 slot（logo/search 等）：返回匹配该 slot 的小部件
     * - content 区域：返回所有适用的部件（非独占）
     * 
     * 参数：
     * - slot_id: slot ID（必填）
     * - area: 区域代码（可选，如 header/content/footer）
     * - page_type: 页面类型（可选）
     */
    public function getWidgetsForSlot()
    {
        $slotId = $this->request->getParam('slot_id', '');
        $area = $this->request->getParam('area', null);
        $pageType = $this->request->getParam('page_type', null);
        $acceptCodes = $this->normalizeSlotCodeParam($this->request->getParam('accept', []));
        $rejectCodes = $this->normalizeSlotCodeParam($this->request->getParam('reject', []));
        
        if (empty($slotId)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少 slot_id 参数'),
            ]);
        }
        
        $editorArea = (string)$this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND);
        $editorArea = $editorArea === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;
        $theme = $this->resolveEditorThemeFromRequest();
        $filterOptions = $editorArea === PreviewContextService::AREA_BACKEND
            ? ['editor_area' => PreviewContextService::AREA_BACKEND]
            : [];
        $filterOptions = array_merge($filterOptions, $this->getWidgetLibraryFilterOptionsFromRequest());

        // 获取精细筛选的部件
        $result = $this->layoutService->getWidgetsForSlot(
            $slotId,
            $area,
            $pageType,
            $acceptCodes,
            $rejectCodes,
            $theme,
            $editorArea,
            $filterOptions
        );
        
        // 预编译预览 HTML
        if (!empty($result['exclusive_widgets'])) {
            foreach ($result['exclusive_widgets'] as &$widget) {
                $widget['preview_html'] = $this->buildWidgetPreviewHtml($widget, $theme, $editorArea);
            }
        }
        if (!empty($result['regular_widgets'])) {
            foreach ($result['regular_widgets'] as &$widget) {
                $widget['preview_html'] = $this->buildWidgetPreviewHtml($widget, $theme, $editorArea);
            }
        }
        if (!empty($result['matched_widgets'])) {
            foreach ($result['matched_widgets'] as &$widget) {
                $widget['preview_html'] = $this->buildWidgetPreviewHtml($widget, $theme, $editorArea);
            }
        }
        
        return $this->fetchJson([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * 保存部件 (Query)
     */
    public function postSaveWidget()
    {
        // 优先从请求体获取 JSON 数据
        $bodyParams = $this->request->getBodyParams();
        
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
        } elseif (is_array($bodyParams) && !empty($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        // 缺失时从 getParam 补全
        $keys = [
            'theme_id',
            'area',
            'widget_code',
            'widget_module',
            'widget_type',
            'page_type',
            'slot_id',
            'config',
            'layout_option',
            'scope',
            'target_type',
            'target_id',
            'status',
        ];
        foreach ($keys as $key) {
            $empty = !isset($data[$key]) || $data[$key] === '' || $data[$key] === null;
            if ($key === 'theme_id') {
                $empty = $empty || (int)($data[$key] ?? 0) === 0;
            }
            if ($empty) {
                $v = $this->request->getParam($key);
                if ($v !== '' && $v !== null && ($key !== 'theme_id' || (int)$v > 0)) {
                    $data[$key] = $key === 'theme_id' ? (int)$v : $v;
                }
            }
        }

        if (empty($data['theme_id']) || empty($data['area']) || empty($data['widget_code'])) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }

        // 如果 area 不是标准区域，则视为自定义插槽，根据插槽名推断实际所属区域
        $area = $data['area'];
        if (!array_key_exists($area, ThemeLayout::getAreas())) {
            $data['slot_id'] = $data['slot_id'] ?? $area;
            // 根据插槽名或部件类型推断实际区域
            $data['area'] = $this->inferAreaFromSlot($area, $data['widget_type'] ?? '', $data['widget_code']);
        }

        // 检查位置是否允许（对于已通过前端插槽验证的部件，跳过后端区域限制检查）
        // 前端已根据 slot accept/reject 规则验证过，后端只做基本校验
        $slotId = $data['slot_id'] ?? null;
        $skipAreaCheck = !empty($slotId); // 有明确插槽时跳过区域检查
        if (!$skipAreaCheck && !$this->positionResolver->canPlaceInArea($data['widget_module'] ?? '', $data['widget_code'], $data['area'])) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('该部件不能放置在此区域'),
            ]);
        }

        // 处理独占插槽参数
        // slot_id: 插槽ID（如 logo, search, user-area 等）
        // exclusive: 是否独占（true 表示替换现有部件）
        $data['slot_id'] = $data['slot_id'] ?? null;
        
        // 后端兜底：如果 exclusive 未传或为 null，根据 slot_id 自动判断是否独占
        // 与模板 exclusive="true" / data-wslot-exclusive="true" 保持一致
        // 注意：user-area（多个图标）和 footer-links（多个链接组）是 multiple，不是 exclusive
        $exclusiveSlots = [
            // Header 区域
            'header', 'logo', 'search', 'navigation', 'category-menu',
            // Footer 区域
            'footer', 'footer-social', 'footer-copyright',
            // Content 容器
            'widget-hero',
            // 产品列表页
            'list-grid', 'list-pagination',
        ];
        $slotId = $data['slot_id'];
        $passedExclusive = $data['exclusive'] ?? null;
        if ($passedExclusive === null || $passedExclusive === '') {
            // 未传递 exclusive，根据 slot_id 自动判断
            $data['exclusive'] = $slotId && in_array($slotId, $exclusiveSlots, true);
        } else {
            $data['exclusive'] = (bool)$passedExclusive;
        }

        try {
            $context = $this->requireLayoutWriteContext(
                $data,
                (int)$data['theme_id'],
                (string)($data['page_type'] ?? $data['layout_type'] ?? ''),
            );
            $identity = $this->layoutIdentityFromEditorContext($context);
            $data['theme_id'] = $context->themeId;
            $data['page_type'] = $context->layoutType;
            $data['layout_option'] = $identity['layout_option'];
            $data['scope'] = $identity['scope'];
            $data['locale_code'] = $identity['locale_code'];
            $data['target_type'] = $identity['target_type'];
            $data['target_id'] = $identity['target_id'];
            $data['editor_area'] = $context->area;
            // Theme Editor 始终编辑草稿；已发布投影只能由 Scoped Release 产生。
            $data['status'] = ThemeLayout::STATUS_DRAFT;
            $layoutId = $this->layoutService->saveWidget($data);
            $savedLayout = clone $this->themeLayout;
            $savedLayout->clearData()->clearQuery()->load($layoutId);

            $response = [
                'success' => true,
                'message' => __('保存成功'),
                'data' => [
                    'layout_id' => $layoutId,
                    'node_uid' => $savedLayout->getNodeUid(),
                ],
            ];

            // T010: 保存成功后返回 preview_html
            $previewHtml = $this->buildPreviewHtmlForLayoutId($layoutId, $data['config'] ?? []);
            if ($previewHtml !== null) {
                $response['preview_html'] = $previewHtml;
            }

            return $this->fetchJson($response);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 更新部件配置 (Query)
     */
    public function postUpdateConfig()
    {
        // 优先从请求体获取 JSON 数据
        $bodyParams = $this->request->getBodyParams();
        
        // 如果 bodyParams 是字符串，尝试解析为 JSON
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            if ($decoded !== null && is_array($decoded)) {
                $data = $decoded;
            } else {
                $data = $this->request->getParams();
            }
        } elseif (is_array($bodyParams) && !empty($bodyParams)) {
            // 如果 bodyParams 是数组且不为空，使用它
            $data = $bodyParams;
        } else {
            // 回退到 getParams
            $data = $this->request->getParams();
        }

        $layoutId = (int)($data['layout_id'] ?? $this->request->getParam('layout_id', 0));
        $config = $data['config'] ?? $this->request->getParam('config', []);

        if (!$layoutId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少布局ID'),
            ]);
        }

        if (!is_array($config)) {
            $config = is_string($config) ? (json_decode($config, true) ?: []) : [];
        }

        $this->themeLayout->reset()->load($layoutId);
        if (!$this->themeLayout->getLayoutId()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('部件不存在'),
            ]);
        }

        $config = $this->normalizeWidgetConfigForLayout($this->themeLayout, $config);

        try {
            $context = $this->requireLayoutWriteContext($data);
            $this->assertLayoutBelongsToEditorContext($this->themeLayout, $context);
            $this->assertDraftLayout($this->themeLayout);
            $result = $this->layoutService->updateWidgetConfig($layoutId, $config);

            $response = [
                'success' => $result,
                'message' => $result ? __('配置已保存') : __('保存失败'),
                'config' => $config,
                'node_uid' => $this->themeLayout->getNodeUid(),
            ];

            // T009: 配置保存成功后返回 preview_html
            if ($result) {
                $previewHtml = $this->buildPreviewHtmlForLayoutId($layoutId, $config);
                if ($previewHtml !== null) {
                    $response['preview_html'] = $previewHtml;
                }
            }

            return $this->fetchJson($response);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 删除部件 (Query)
     * 路由: /backend/theme-editor/remove-widget (POST)
     */
    public function postRemoveWidget()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = [];
        }
        
        $rawLayoutId = $data['layout_id'] ?? $this->request->getParam('layout_id', 0);
        $layoutId = is_numeric($rawLayoutId) ? (int)$rawLayoutId : 0;
        $templateRef = trim((string)($data['template_ref'] ?? ''));
        if ($templateRef === '' && is_string($rawLayoutId) && str_starts_with(trim($rawLayoutId), 'tpl:')) {
            $templateRef = trim($rawLayoutId);
        }
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));

        if (!$layoutId && $templateRef !== '') {
            try {
                return $this->fetchJson($this->removeTemplateInlineWidget($data, $themeId, $templateRef));
            } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
                throw $e;
            } catch (\Throwable $e) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (!$layoutId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少布局ID'),
            ]);
        }

        try {
            // 获取要删除的部件信息（在删除前）。不对 clearQuery() 链式调用 load()，避免 clearQuery() 返回 Query 时在 Query 上调用 load() 导致致命错误。
            $widget = clone $this->themeLayout;
            $widget->clearQuery()->clearData()->load($layoutId);
            $slotId = $widget->getData('slot_id');
            $pageType = $widget->getData('page_type');
            $area = $widget->getData('area');
            $widgetModule = (string)$widget->getData('widget_module');
            $widgetType = (string)$widget->getData('widget_type');
            $widgetCode = (string)$widget->getData('widget_code');
            $widgetSortOrder = (int)$widget->getData('sort_order');
            $nodeUid = $widget->getNodeUid();
            $layoutIdentity = [
                'layout_option' => (string)($widget->getData('layout_option') ?: 'default'),
                'scope' => (string)($widget->getData('scope') ?: 'default'),
                'locale_code' => (string)$widget->getData(ThemeLayout::schema_fields_LOCALE_CODE),
                'target_type' => (string)($widget->getData('target_type') ?: ThemeVirtualLayout::TARGET_GLOBAL),
                'target_id' => (int)$widget->getData('target_id'),
            ];
            
            // 如果 DB 记录不存在，使用前端提供的 fallback 数据
            $recordExists = !empty($widget->getLayoutId());
            if ($recordExists && $nodeUid === '') {
                $widget->save();
                $nodeUid = $widget->getNodeUid();
            }
            $context = $this->requireLayoutWriteContext(
                $data,
                $themeId > 0 ? $themeId : null,
                $recordExists ? (string)$widget->getData(ThemeLayout::schema_fields_PAGE_TYPE) : null,
            );
            $themeId = $context->themeId;
            if (!$recordExists) {
                $slotId = $data['slot_id'] ?? null;
                $area = $data['area'] ?? 'content';
                $pageType = $data['layout_type'] ?? 'homepage';
                $widgetModule = (string)($data['widget_module'] ?? $widgetModule);
                $widgetType = (string)($data['widget_type'] ?? $widgetType);
                $widgetCode = (string)($data['widget_code'] ?? $widgetCode);
                $layoutIdentity = $this->resolveVersionLayoutIdentity($data);
            } else {
                $this->assertLayoutBelongsToEditorContext($widget, $context);
                $this->assertDraftLayout($widget);
                $layoutIdentity = $this->layoutIdentityFromEditorContext($context);
            }
            
            // 尝试删除部件（如果记录存在）
            $result = $recordExists ? $this->layoutService->deleteWidget($layoutId) : true;
            
            // 删除后清除插槽渲染缓存，否则 getOriginalSlotContent 会读到旧 layout 缓存，返回仍含已删部件的内容
            if ($result) {
                ObjectManager::getInstance(SlotRendererService::class)->clearCache();
                if ($themeId > 0 && $widgetModule !== '' && $widgetType !== '' && $widgetCode !== '') {
                    $editorArea = ObjectManager::getInstance(PreviewContextService::class)->normalizeArea(
                        (string)($data['editor_area'] ?? $this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND)),
                        PreviewContextService::AREA_FRONTEND
                    );
                    /** @var WidgetDefaultInjectionService $injectionService */
                    $injectionService = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
                    $injectionService->markUserDeleted(
                        $themeId,
                        (string)($pageType ?: ($data['layout_type'] ?? $data['page_type'] ?? '')),
                        $layoutIdentity,
                        [
                            'module' => $widgetModule,
                            'type' => $widgetType,
                            'code' => $widgetCode,
                            'slot_id' => $slotId,
                            'area' => $area,
                            'sort_order' => $widgetSortOrder,
                        ],
                        $editorArea
                    );
                }
            }
            
            $response = [
                'success' => $result,
                'message' => $result ? __('删除成功') : __('删除失败'),
                'slot_id' => $slotId,
                'node_uid' => $nodeUid,
            ];
            
            // 获取插槽的原始内容（无论记录是否存在，只要有足够信息就尝试恢复）
            if ($result && $themeId && $slotId) {
                $layoutType = $data['layout_type'] ?? $this->request->getParam('layout_type', 'homepage');
                $layoutOption = $data['layout_option'] ?? $this->request->getParam('layout_option', 'default');
                $identity = $this->resolveVersionLayoutIdentity($data);
                $originalHtml = $this->getOriginalSlotContent($themeId, $pageType, $slotId, $area, $layoutType, $layoutOption, $identity);
                $response['original_html'] = $originalHtml;
                $response['has_original'] = !empty($originalHtml);
            }
            
            return $this->fetchJson($response);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 删除模板内嵌部件（无 layout_id）：写入 template_deleted 墓碑行。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function removeTemplateInlineWidget(array $data, int $themeId, string $templateRef): array
    {
        $widgetModule = trim((string)($data['widget_module'] ?? ''));
        $widgetType = trim((string)($data['widget_type'] ?? ''));
        $widgetCode = trim((string)($data['widget_code'] ?? ''));
        $slotId = trim((string)($data['slot_id'] ?? ''));
        $area = trim((string)($data['area'] ?? ThemeLayout::AREA_CONTENT));
        if ($area === '') {
            $area = ThemeLayout::AREA_CONTENT;
        }

        if ($widgetModule === '' || $widgetType === '' || $widgetCode === '' || $slotId === '') {
            throw new \InvalidArgumentException((string)__('缺少部件身份信息'));
        }

        $pageType = (string)($data['layout_type'] ?? $data['page_type'] ?? 'homepage');
        $context = $this->requireLayoutWriteContext($data, $themeId > 0 ? $themeId : null, $pageType !== '' ? $pageType : null);
        $themeId = $context->themeId;
        $layoutIdentity = $this->resolveVersionLayoutIdentity($data);

        $newLayoutId = $this->layoutService->saveWidget([
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'layout_option' => $layoutIdentity['layout_option'] ?? 'default',
            'scope' => $layoutIdentity['scope'] ?? 'default',
            'locale_code' => $layoutIdentity['locale_code'] ?? '',
            'target_type' => $layoutIdentity['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL,
            'target_id' => (int)($layoutIdentity['target_id'] ?? 0),
            'area' => $area,
            'slot_id' => $slotId,
            'widget_module' => $widgetModule,
            'widget_type' => $widgetType,
            'widget_code' => $widgetCode,
            'config' => [
                TemplateInlineWidgetMerger::CONFIG_TEMPLATE_REF => $templateRef,
                TemplateInlineWidgetMerger::CONFIG_TEMPLATE_DELETED => true,
            ],
            'sort_order' => max(0, (int)($data['sort_order'] ?? 0)),
            'exclusive' => false,
            'status' => ThemeLayout::STATUS_DRAFT,
        ]);

        $saved = clone $this->themeLayout;
        $saved->clearQuery()->clearData()->load($newLayoutId);
        $nodeUid = $saved->getNodeUid();
        if ($nodeUid === '') {
            $saved->save();
            $nodeUid = $saved->getNodeUid();
        }

        ObjectManager::getInstance(SlotRendererService::class)->clearCache();

        $editorArea = ObjectManager::getInstance(PreviewContextService::class)->normalizeArea(
            (string)($data['editor_area'] ?? $this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND)),
            PreviewContextService::AREA_FRONTEND
        );
        ObjectManager::getInstance(WidgetDefaultInjectionService::class)->markUserDeleted(
            $themeId,
            $pageType,
            $layoutIdentity,
            [
                'module' => $widgetModule,
                'type' => $widgetType,
                'code' => $widgetCode,
                'slot_id' => $slotId,
                'area' => $area,
                'sort_order' => max(0, (int)($data['sort_order'] ?? 0)),
            ],
            $editorArea
        );

        $response = [
            'success' => true,
            'message' => __('删除成功'),
            'layout_id' => $newLayoutId,
            'slot_id' => $slotId,
            'node_uid' => $nodeUid,
        ];

        if ($themeId > 0 && $slotId !== '') {
            $layoutType = (string)($data['layout_type'] ?? $this->request->getParam('layout_type', 'homepage'));
            $layoutOption = (string)($data['layout_option'] ?? $this->request->getParam('layout_option', 'default'));
            $originalHtml = $this->getOriginalSlotContent(
                $themeId,
                $pageType,
                $slotId,
                $area,
                $layoutType,
                $layoutOption,
                $layoutIdentity
            );
            $response['original_html'] = $originalHtml;
            $response['has_original'] = $originalHtml !== '';
        }

        return $response;
    }

    /**
     * 批量删除孤儿部件（找不到插槽的部件）
     * 路由: /backend/theme-editor/remove-orphan-widgets (POST)
     */
    public function postRemoveOrphanWidgets()
    {
        // 获取 JSON 请求体
        $bodyParams = $this->request->getBodyParams();
        
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
        } elseif (is_array($bodyParams) && !empty($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }
        
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $slotIds = $data['slot_ids'] ?? $this->request->getParam('slot_ids', []);
        $pageType = (string)($data['page_type']
            ?? $this->request->getParam('page_type', $this->request->getParam('layout_type', ThemeLayout::PAGE_TYPE_HOME)));
        $identity = [];
        // 删除孤儿也是编辑操作，不允许客户端指定 published。
        $status = ThemeLayout::STATUS_DRAFT;
        
        if (!$themeId || empty($slotIds)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }
        
        try {
            $context = $this->requireLayoutWriteContext(
                $data,
                $themeId > 0 ? $themeId : null,
                $pageType,
            );
            $themeId = $context->themeId;
            $pageType = $context->layoutType;
            $identity = $this->layoutIdentityFromEditorContext($context);
            $deletedCount = 0;
            
            // 批量删除指定插槽的所有部件（包括 draft 和 published）
            foreach ($slotIds as $slotId) {

                // 先验证目标数据存在
                $existsBefore = $this->themeLayout->clearQuery()
                    ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                    ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                    ->where(ThemeLayout::schema_fields_STATUS, $status)
                    ->where(ThemeLayout::schema_fields_SLOT_ID, $slotId)
                    ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                    ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
                    ->where(ThemeLayout::schema_fields_LOCALE_CODE, $identity['locale_code'])
                    ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                    ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id'])
                    ->select()
                    ->fetchArray();


                $this->themeLayout->clearQuery()
                    ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                    ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                    ->where(ThemeLayout::schema_fields_STATUS, $status)
                    ->where(ThemeLayout::schema_fields_SLOT_ID, $slotId)
                    ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                    ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
                    ->where(ThemeLayout::schema_fields_LOCALE_CODE, $identity['locale_code'])
                    ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                    ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id'])
                    ->delete()
                    ->fetch();

                // 验证删除后是否还存在
                $existsAfter = $this->themeLayout->clearQuery()
                    ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                    ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                    ->where(ThemeLayout::schema_fields_STATUS, $status)
                    ->where(ThemeLayout::schema_fields_SLOT_ID, $slotId)
                    ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                    ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
                    ->where(ThemeLayout::schema_fields_LOCALE_CODE, $identity['locale_code'])
                    ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                    ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id'])
                    ->select()
                    ->fetchArray();

                    
                $deletedCount += \count($existsBefore);
            }
            
            if ($deletedCount > 0) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('已删除 %{count} 个孤儿部件', ['count' => $deletedCount]),
                    'deleted_count' => $deletedCount,
                ]);
            } else {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('未找到需要删除的孤儿部件（可能已被删除）'),
                    'deleted_count' => 0,
                ]);
            }
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 移动部件 (Query)
     */
    public function postMoveWidget()
    {
        $data = $this->getEditorJsonPayload();
        $layoutId = (int)($data['layout_id'] ?? 0);
        $newArea = $data['area'] ?? null;
        $sortOrder = (int)($data['sort_order'] ?? 0);

        if (!$layoutId || !$newArea) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($data);
            $layout = clone $this->themeLayout;
            $layout->clearData()->clearQuery()->load($layoutId);
            if (!$layout->getLayoutId()) {
                throw new \RuntimeException((string)__('部件不存在'));
            }
            $this->assertLayoutBelongsToEditorContext($layout, $context);
            $this->assertDraftLayout($layout);
            $result = $this->layoutService->moveWidget($layoutId, $newArea, $sortOrder);

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('移动成功') : __('移动失败'),
                'data' => $result ? [
                    'nodes' => [[
                        'layout_id' => $layoutId,
                        'node_uid' => $layout->getNodeUid(),
                        'area' => (string)$newArea,
                        'sort_order' => $sortOrder,
                    ]],
                ] : null,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 更新排序 (Query)
     */
    public function postUpdateSort()
    {
        // 尝试从 JSON body 获取参数
        $bodyParams = $this->request->getBodyParams();
        $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);
        $body = is_array($body) ? $body : $this->getEditorJsonPayload();
        $sortData = $body['sort_data'] ?? $this->request->getParam('sort_data', []);

        if (empty($sortData)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('排序数据为空'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($body);
            $nodes = [];
            foreach (array_keys($sortData) as $layoutId) {
                $layout = clone $this->themeLayout;
                $layout->clearData()->clearQuery()->load((int)$layoutId);
                if (!$layout->getLayoutId()) {
                    throw new \RuntimeException((string)__('部件不存在'));
                }
                $this->assertLayoutBelongsToEditorContext($layout, $context);
                $this->assertDraftLayout($layout);
                $nodes[] = [
                    'layout_id' => (int)$layoutId,
                    'node_uid' => $layout->getNodeUid(),
                    'sort_order' => (int)$sortData[$layoutId],
                ];
            }
            $result = $this->layoutService->updateSortOrder($sortData);
            usort($nodes, static fn(array $left, array $right): int =>
                ((int)$left['sort_order']) <=> ((int)$right['sort_order']));

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('排序已更新') : __('更新失败'),
                'data' => $result ? ['nodes' => $nodes] : null,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 交换两个部件的排序 (Query)
     */
    public function postSwapWidgetOrder()
    {
        // 尝试从 JSON body 获取参数
        $bodyParams = $this->request->getBodyParams();
        $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);
        $body = is_array($body) ? $body : $this->getEditorJsonPayload();
        $themeId = (int)($body['theme_id'] ?? $this->request->getParam('theme_id'));
        $layoutId1 = (int)($body['layout_id_1'] ?? $this->request->getParam('layout_id_1'));
        $layoutId2 = (int)($body['layout_id_2'] ?? $this->request->getParam('layout_id_2'));

        if (!$layoutId1 || !$layoutId2) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($body, $themeId > 0 ? $themeId : null);
            $layouts = [];
            foreach ([$layoutId1, $layoutId2] as $layoutId) {
                $layout = clone $this->themeLayout;
                $layout->clearData()->clearQuery()->load($layoutId);
                if (!$layout->getLayoutId()) {
                    throw new \RuntimeException((string)__('部件不存在'));
                }
                $this->assertLayoutBelongsToEditorContext($layout, $context);
                $this->assertDraftLayout($layout);
                $layouts[] = $layout;
            }
            $result = $this->layoutService->swapWidgetOrder($layoutId1, $layoutId2);
            $nodes = [];
            if ($result && count($layouts) === 2) {
                $nodes = [
                    [
                        'layout_id' => $layoutId1,
                        'node_uid' => $layouts[0]->getNodeUid(),
                        'sort_order' => $layouts[1]->getSortOrder(),
                    ],
                    [
                        'layout_id' => $layoutId2,
                        'node_uid' => $layouts[1]->getNodeUid(),
                        'sort_order' => $layouts[0]->getSortOrder(),
                    ],
                ];
            }

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('位置已交换') : __('交换失败'),
                'data' => $result ? ['nodes' => $nodes] : null,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 保存完整布局 (Query)
     */
    public function postSaveLayout()
    {
        $data = $this->getEditorJsonPayload();
        $themeId = (int)($data['theme_id'] ?? 0);
        $pageType = (string)($data['page_type'] ?? ThemeLayout::PAGE_TYPE_HOME);
        $layoutData = $data['layout_data'] ?? [];

        if (!$themeId) {
            return $this->dispatchThemeEditorResultAfter($this->fetchJson([
                'success' => false,
                'message' => __('请选择主题'),
            ]), 'save_layout');
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $identity = $this->layoutIdentityFromEditorContext($context);
            $result = $this->layoutService->saveLayout($themeId, $pageType, $layoutData, ThemeLayout::STATUS_DRAFT, $identity);
            $scopedDraft = null;
            if ($result) {
                $snapshot = $this->layoutService->getLayout(
                    $themeId,
                    $pageType,
                    ThemeLayout::STATUS_DRAFT,
                    $identity,
                );
                $scopedDraft = $this->replaceScopedLayoutDraftFromSnapshot(
                    $context,
                    $snapshot,
                    'Convert legacy full layout form to semantic patch',
                );
            }

            return $this->dispatchThemeEditorResultAfter($this->fetchJson([
                'success' => $result,
                'message' => $result ? __('布局已保存') : __('保存失败'),
                'data' => $result ? ['scoped_workspace' => $scopedDraft] : null,
            ]), 'save_layout');
        } catch (\Exception $e) {
            return $this->dispatchThemeEditorResultAfter($this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]), 'save_layout');
        }
    }

    /**
     * 获取部件位置信息 (Query)
     */
    public function getPlacementInfo()
    {
        $widgetModule = $this->request->getParam('widget_module');
        $widgetCode = $this->request->getParam('widget_code');

        if (!$widgetModule || !$widgetCode) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }

        $info = $this->positionResolver->getPlacementInfo($widgetModule, $widgetCode);

        return $this->fetchJson([
            'success' => true,
            'data' => $info,
        ]);
    }

    public function postPublish()
    {
        $data = $this->getEditorJsonPayload();
        $themeId = (int)($data['theme_id'] ?? 0);
        $pageType = isset($data['page_type']) ? (string)$data['page_type'] : null;

        if (!$themeId) {
            return $this->dispatchThemeEditorResultAfter($this->fetchJson([
                'success' => false,
                'message' => __('请选择主题'),
            ]), 'publish_layout');
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $themeId = $context->themeId;
            $pageType = $context->layoutType;
            $identity = $this->layoutIdentityFromEditorContext($context);
            $scopedReleasePublished = $this->hasScopedReleasePublishedClaim($data);
            if ($scopedReleasePublished) {
                // Scoped Release 已编译并投影有效快照，不得再用旧 draft 整表覆盖。
                $this->assertCurrentScopedLayoutPublished($context);
            } else {
                // 保留旧路由，但发布必须收口到 typed Scope Release。
                $this->publishPendingScopedResources($context, 'theme_editor_compat_publish');
                $this->assertCurrentScopedLayoutPublished($context);
            }
            $this->publishEditorPreviewScope($themeId, (string)($identity['scope'] ?? PreviewContextService::DEFAULT_SCOPE));

            // 清除旧缓存（主题生成缓存）
            $this->cacheGenerator->clearCache($themeId);

            // 清除全页面缓存（FPC）— 布局变更后旧的缓存 HTML 必须失效
            $this->flushFullPageCache();

            // 生成新缓存
            $cacheResult = $this->cacheGenerator->generate($themeId);

            $message = $cacheResult ? __('主题已发布') : __('生成缓存失败，但布局已发布');

            return $this->dispatchThemeEditorResultAfter($this->fetchJson([
                'success' => $cacheResult,
                'message' => $message,
            ]), 'publish_layout');
        } catch (\Exception $e) {
            return $this->dispatchThemeEditorResultAfter($this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]), 'publish_layout');
        }
    }

    /**
     * 撤销草稿 (Query) - 放弃所有未发布的修改
     */
    public function postDiscardDraft()
    {
        $data = $this->getEditorJsonPayload();
        $themeId = (int)($data['theme_id'] ?? 0);
        $pageType = isset($data['page_type']) ? (string)$data['page_type'] : null;

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请选择主题'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $themeId = $context->themeId;
            $pageType = $context->layoutType;
            $identity = $this->layoutIdentityFromEditorContext($context);
            $result = $this->layoutService->discardDraft($themeId, $pageType, $identity);

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('草稿已撤销') : __('撤销失败'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 渲染单个部件 (Query) - 用于实时预览
     * 
     * 支持两种调用方式：
     * 1. 通过 widget_module + widget_code 查找部件定义并渲染
     * 2. 通过 layout_id 获取已保存部件的配置并渲染
     */
    public function postRenderWidget()
    {
        // 获取请求参数
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $data = $this->request->getParams();
            }
        } elseif (is_array($bodyParams) && !empty($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $layoutId = (int)($data['layout_id'] ?? 0);
        $widgetModule = $data['widget_module'] ?? '';
        $widgetCode = $data['widget_code'] ?? '';
        $config = $data['config'] ?? [];
        $area = (string)($data['area'] ?? $data['editor_area'] ?? $this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND));
        $area = $area === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;

        // 如果提供了 layout_id，从数据库获取配置
        if ($layoutId) {
            $layoutData = $this->layoutService->getWidgetByLayoutId($layoutId);
            if ($layoutData) {
                $widgetModule = $layoutData['widget_module'] ?? $widgetModule;
                $widgetCode = $layoutData['widget_code'] ?? $widgetCode;
                $layoutArea = (string)($layoutData['area'] ?? '');
                if ($layoutArea === PreviewContextService::AREA_BACKEND || ($layoutData['target_type'] ?? '') === 'website') {
                    $area = PreviewContextService::AREA_BACKEND;
                }
                // 合并配置（传入的配置优先，用于预览配置变更）
                $savedConfig = $layoutData['config'] ?? [];
                $config = array_merge($savedConfig, $config);
            }
        }

        if (!$widgetModule || !$widgetCode) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少部件模块或代码'),
            ]);
        }

        $eventData = [
            'data' => [
                'operation' => 'preview',
                'params' => [
                    'widget_module' => $widgetModule,
                    'widget_code' => $widgetCode,
                    'config' => $config,
                    'area' => $area,
                ],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_Widget::query', $eventData);
        $html = $eventData['data']['result'] ?? '';
        $err = $eventData['data']['error'] ?? null;
        if ($err !== null && $err !== '') {
            return $this->fetchJson([
                'success' => false,
                'message' => $err,
            ]);
        }
        $widgetMeta = $this->findWidgetMetaByModuleAndCode($widgetModule, $widgetCode, $area);
        return $this->fetchJson([
            'success' => true,
            'html' => is_string($html) ? $html : '',
            'widget' => [
                'code' => $widgetCode,
                'module' => $widgetModule,
                'name' => $widgetMeta['name'] ?? $widgetCode,
                'slot' => $widgetMeta['slot'] ?? null,
                'is_container' => $widgetMeta['is_container'] ?? false,
            ],
        ]);
    }

    /**
     * 获取部件默认预览 (GET) - 用于拖拽时的预览
     */
    public function getWidgetPreview()
    {
        $widgetModule = $this->request->getParam('widget_module', '');
        $widgetCode = $this->request->getParam('widget_code', '');
        $area = (string)$this->request->getParam('area', $this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND));
        $area = $area === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;

        if (!$widgetModule || !$widgetCode) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少部件参数'),
            ]);
        }

        $widgetMeta = $this->findWidgetMetaByModuleAndCode($widgetModule, $widgetCode, $area);
        if (!$widgetMeta) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('部件不存在'),
            ]);
        }

        $eventData = [
            'data' => [
                'operation' => 'preview',
                'params' => [
                    'widget_module' => $widgetModule,
                    'widget_code' => $widgetCode,
                    'config' => [],
                    'area' => $area,
                ],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_Widget::query', $eventData);
        $html = $eventData['data']['result'] ?? '';
        $err = $eventData['data']['error'] ?? null;
        $html = is_string($html) ? trim($html) : '';
        if ($html === ''
            || str_contains($html, 'widget-preview-placeholder')
            || str_contains($html, 'widget-preview-error')
            || ($err !== null && $err !== '')
        ) {
            $theme = $this->resolveEditorThemeFromRequest();
            $html = $this->buildWidgetPreviewHtml($widgetMeta, $theme, $area);
        }

        return $this->fetchJson([
            'success' => true,
            'html' => $html !== '' ? $html : '<div class="widget-preview-placeholder">' . htmlspecialchars((string)($widgetMeta['name'] ?? $widgetCode ?? '')) . '</div>',
            'widget' => $widgetMeta,
        ]);
    }

    /**
     * 获取已安装语言列表（含 SVG 国旗）
     *
     * 通过 Weline_I18n 公共 LocaleCatalog 契约获取。
     *
     * @return array JSON 响应 { success, locales: [ { code, name, flag }, ... ] }
     */
    public function getInstalledLocales()
    {
        try {
            $locales = $this->localeCatalog()->installed(
                \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN',
            );
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
                'locales' => [],
            ]);
        }

        return $this->fetchJson([
            'success' => true,
            'locales' => $locales,
        ]);
    }

    /**
     * 批量读取部件某一字段在各已安装语言下的译文（草稿优先，已发布回退）。
     */
    public function getWidgetFieldI18n()
    {
        return $this->fetchJson($this->getWidgetFieldI18nPayload());
    }

    /** @return array<string,mixed> */
    public function getWidgetFieldI18nPayload(): array
    {
        $layoutId = (int)$this->request->getParam('layout_id', 0);
        $fieldKey = trim((string)$this->request->getParam('field', ''));
        if ($layoutId <= 0 || $fieldKey === '') {
            return [
                'success' => false,
                'message' => __('缺少布局ID或字段'),
            ];
        }

        $widgetLayout = $this->themeLayout->reset()->load($layoutId);
        if (!$widgetLayout->getLayoutId()) {
            return [
                'success' => false,
                'message' => __('部件不存在'),
            ];
        }

        $widgetModule = $widgetLayout->getData('widget_module');
        $widgetCode = $widgetLayout->getData('widget_code');
        $widgetType = $widgetLayout->getData('widget_type') ?: '';
        $slotArea = (string)($widgetLayout->getData('area') ?: ThemeLayout::AREA_CONTENT);
        $area = $this->normalizeThemeConfigArea($slotArea);
        $params = $this->getWidgetParamDefinitions($widgetModule, $widgetCode, $area, $widgetType);
        if ($params === []) {
            return [
                'success' => false,
                'message' => __('该部件没有配置项'),
            ];
        }

        $baseConfig = $this->ensureWidgetI18nInstance($widgetLayout);
        if (!is_array($baseConfig)) {
            $baseConfig = [];
        }
        $identify = $this->resolveThemeConfigIdentifyForLayout(
            $widgetLayout,
            $widgetModule,
            $widgetType,
            $widgetCode,
            $area,
            $baseConfig,
        );

        $sourceConfig = $this->composeWidgetConfigForLocale(
            $widgetLayout,
            $params,
            $identify,
            $slotArea,
            $area,
            $baseConfig,
            null,
        );
        $sourceValue = $this->readConfigPathScalar($sourceConfig, $fieldKey);

        $translations = [];
        foreach ($this->getInstalledLocalesPayload() as $localeRow) {
            if (!is_array($localeRow)) {
                continue;
            }
            $locale = trim((string)($localeRow['code'] ?? ''));
            if ($locale === '') {
                continue;
            }
            $localeConfig = $this->composeWidgetConfigForLocale(
                $widgetLayout,
                $params,
                $identify,
                $slotArea,
                $area,
                $baseConfig,
                $locale,
            );
            $translations[$locale] = $this->readConfigPathScalar($localeConfig, $fieldKey);
        }

        return [
            'success' => true,
            'data' => [
                'layout_id' => $layoutId,
                'node_uid' => $widgetLayout->getNodeUid(),
                'field' => $fieldKey,
                'source_value' => $sourceValue,
                'translations' => $translations,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $baseConfig
     * @return array<string,mixed>
     */
    private function composeWidgetConfigForLocale(
        ThemeLayout $widgetLayout,
        array $params,
        string $identify,
        string $slotArea,
        string $area,
        array $baseConfig,
        ?string $locale,
    ): array {
        $config = $baseConfig;
        if ($locale !== null && $locale !== '') {
            $config = $this->mergeTranslatedPathsForLayout($config, $params, $identify, $locale, $slotArea, $area);
        }

        $editorPayload = $this->overlayEditorContextTranslationLocale(
            $this->getEditorJsonPayload(),
            $locale,
        );
        if (!array_key_exists('editor_context', $editorPayload)) {
            return $this->materializeWidgetConfigPaths($config, []);
        }

        try {
            /** @var ThemeEditorContextFactory $factory */
            $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
            $typedContext = $factory->fromInput(
                $editorPayload,
                $locale === null || $locale === ''
                    ? ThemeEditorContext::RESOURCE_LAYOUT
                    : ThemeEditorContext::RESOURCE_I18N,
            );
            $this->assertRawLayoutContextMatches($editorPayload, $typedContext);
            $this->assertLayoutBelongsToEditorContext($widgetLayout, $typedContext);
            /** @var ThemeScopedWorkspaceInterface $scopedWorkspace */
            $scopedWorkspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
            $scopedState = $scopedWorkspace->load($typedContext, true);
            $draftPayload = is_array($scopedState['draft_payload'] ?? null)
                ? $scopedState['draft_payload']
                : [];
            $nodeUid = strtolower((string)$widgetLayout->getNodeUid());
            $draftConfig = ($locale === null || $locale === '')
                ? ($draftPayload['nodes'][$nodeUid]['config'] ?? null)
                : ($draftPayload['translations'][$nodeUid] ?? null);
            if (is_array($draftConfig)) {
                $config = ($locale === null || $locale === '')
                    ? $draftConfig
                    : $this->materializeWidgetConfigPaths($draftConfig, $config);
            }
        } catch (\Throwable) {
            // Keep published/base merge when draft workspace is unavailable.
        }

        return $this->materializeWidgetConfigPaths($config, []);
    }

    /** @param array<string,mixed> $config */
    private function readConfigPathScalar(array $config, string $path): string
    {
        if ($path !== '' && array_key_exists($path, $config)) {
            $direct = $config[$path];
            if (is_string($direct) || is_numeric($direct) || is_bool($direct)) {
                return (string)$direct;
            }

            return '';
        }

        $cursor = $config;
        foreach (explode('.', $path) as $segment) {
            if ($segment === '' || !is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return '';
            }
            $cursor = $cursor[$segment];
        }
        if (is_string($cursor) || is_numeric($cursor) || is_bool($cursor)) {
            return (string)$cursor;
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function overlayEditorContextTranslationLocale(array $payload, ?string $locale): array
    {
        $locale = is_string($locale) ? trim($locale) : '';
        if ($locale === '' || !array_key_exists('editor_context', $payload)) {
            return $payload;
        }

        $raw = $payload['editor_context'];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return $payload;
        }

        $raw['locale'] = $locale;
        $raw['resource_type'] = ThemeEditorContext::RESOURCE_I18N;
        $payload['editor_context'] = $raw;

        return $payload;
    }

    /**
     * 获取部件配置信息 (GET)
     * 
     * 支持多语言：传递 locale 参数获取特定语言的配置值
     * 
     * @return array JSON响应
     */
    public function getWidgetConfig()
    {
        $layoutId = (int)$this->request->getParam('layout_id', 0);
        $locale = $this->request->getParam('locale', null); // null表示默认语言
        $locale = is_string($locale) ? trim($locale) : $locale;
        if ($locale === '') {
            $locale = null;
        }
        
        if (!$layoutId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少布局ID'),
            ]);
        }
        
        // 查询部件信息
        $widgetLayout = $this->themeLayout->reset()->load($layoutId);
        
        if (!$widgetLayout->getLayoutId()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('部件不存在'),
            ]);
        }
        
        $widgetModule = $widgetLayout->getData('widget_module');
        $widgetCode = $widgetLayout->getData('widget_code');
        $widgetType = $widgetLayout->getData('widget_type') ?: '';
        $slotArea = (string)($widgetLayout->getData('area') ?: ThemeLayout::AREA_CONTENT);
        $area = $this->normalizeThemeConfigArea($slotArea);
        
        $params = $this->getWidgetParamDefinitions($widgetModule, $widgetCode, $area, $widgetType);
        if (empty($params)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('该部件没有配置项'),
            ]);
        }
        
        // 以已发布 layout 配置为 base（保证选择器等非翻译字段刷新后回填正确）
        $config = $this->ensureWidgetI18nInstance($widgetLayout);
        if (!is_array($config)) {
            $config = [];
        }
        $identify = $this->resolveThemeConfigIdentifyForLayout($widgetLayout, $widgetModule, $widgetType, $widgetCode, $area, $config);

        // 仅在明确选择语言时合并翻译；默认（全语言）必须展示基础配置，不能被 Cookie 语言污染。
        if ($locale !== null) {
            $config = $this->mergeTranslatedPathsForLayout($config, $params, $identify, $locale, $slotArea, $area);
        }
        $editorPayload = $this->overlayEditorContextTranslationLocale(
            $this->getEditorJsonPayload(),
            $locale,
        );
        if (array_key_exists('editor_context', $editorPayload)) {
            try {
                /** @var ThemeEditorContextFactory $factory */
                $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
                $typedContext = $factory->fromInput(
                    $editorPayload,
                    $locale === null ? ThemeEditorContext::RESOURCE_LAYOUT : ThemeEditorContext::RESOURCE_I18N,
                );
                $this->assertRawLayoutContextMatches($editorPayload, $typedContext);
                $this->assertLayoutBelongsToEditorContext($widgetLayout, $typedContext);
                /** @var ThemeScopedWorkspaceInterface $scopedWorkspace */
                $scopedWorkspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
                $scopedState = $scopedWorkspace->load($typedContext, true);
                $draftPayload = is_array($scopedState['draft_payload'] ?? null)
                    ? $scopedState['draft_payload']
                    : [];
                $nodeUid = strtolower((string)$widgetLayout->getNodeUid());
                $draftConfig = $locale === null
                    ? ($draftPayload['nodes'][$nodeUid]['config'] ?? null)
                    : ($draftPayload['translations'][$nodeUid] ?? $draftPayload[$nodeUid] ?? null);
                if (is_array($draftConfig)) {
                    $config = $locale === null
                        ? $draftConfig
                        : $this->materializeWidgetConfigPaths($draftConfig, $config);
                }
            } catch (\Throwable) {
                // Keep published/base merge when draft workspace is unavailable.
            }
        }

        $config = $this->materializeWidgetConfigPaths($config, []);

        $previewHtml = $this->buildPreviewHtmlForLayoutId(
            $layoutId,
            $config,
            $locale === null || $locale === '' ? null : (string)$locale
        );

        return $this->fetchJson([
            'success' => true,
            'data' => [
                'layout_id' => $layoutId,
                'node_uid' => $widgetLayout->getNodeUid(),
                'widget_module' => $widgetModule,
                'widget_type' => $widgetType,
                'widget_code' => $widgetCode,
                'params' => $params,
                'config' => $config,
                'locale' => $locale,
                'preview_html' => $previewHtml,
            ],
            'preview_html' => $previewHtml,
        ]);
    }
    
    /**
     * 保存部件配置 (POST)
     * 
     * 支持多语言：传递 locale 参数保存特定语言的配置值
     * 
     * @return array JSON响应
     */
    public function getLayoutOptions()
    {
        return $this->fetchJson($this->getLayoutOptionsPayload());
    }

    public function getLayoutOptionsPayload(): array
    {
        try {
            $requestData = $this->getEditorJsonPayload();
            $typedContext = null;
            if (array_key_exists('editor_context', $requestData)) {
                /** @var ThemeEditorContextFactory $factory */
                $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
                $typedContext = $factory->fromInput($requestData, ThemeEditorContext::RESOURCE_LAYOUT);
            }
            $editorArea = $this->resolveRequestedEditorArea(PreviewContextService::AREA_FRONTEND);
            $layoutType = $this->normalizeLayoutType((string)$this->request->getParam(
                'layout_type',
                $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME)
            ));
            $requestedLayoutOption = (string)$this->request->getParam('layout_option', '');
            $scope = (string)$this->request->getParam('scope', PreviewContextService::DEFAULT_SCOPE);
            if ($typedContext instanceof ThemeEditorContext) {
                $editorArea = $typedContext->area;
                $layoutType = $typedContext->layoutType;
                $requestedLayoutOption = $typedContext->layoutOption;
                $scope = $this->legacyScopeForEditorContext($typedContext);
            }
            $theme = $this->resolveEditorRequestTheme(
                $editorArea,
                $typedContext instanceof ThemeEditorContext ? $typedContext->themeId : 0,
            );
            $layoutOptionsByType = $this->getEditorLayoutOptionsByType($theme, $editorArea);
            $layoutOption = $this->resolveSelectedLayoutOption(
                $theme,
                $editorArea,
                $layoutType,
                $layoutOptionsByType,
                $requestedLayoutOption,
                $scope
            );

            return [
                'success' => true,
                'data' => [
                    'theme_id' => (int)$theme->getId(),
                    'area' => $editorArea,
                    'scope' => $scope,
                    'layout_type' => $layoutType,
                    'layout_option' => $layoutOption,
                    'layout_options_by_type' => $this->compactEditorLayoutOptions($layoutOptionsByType),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function postSaveLayoutSelection()
    {
        return $this->fetchJson($this->saveLayoutSelectionPayload());
    }

    public function saveLayoutSelectionPayload(): array
    {
        try {
            $payload = $this->getEditorJsonPayload();
            $typedContext = $this->requireLayoutWriteContext($payload);
            $editorArea = $this->getPreviewContextService()->normalizeArea(
                (string)($payload['editor_area'] ?? $payload['preview_area'] ?? PreviewContextService::AREA_FRONTEND),
                PreviewContextService::AREA_FRONTEND
            );
            $layoutType = $this->normalizeLayoutType((string)(
                $payload['layout_type']
                ?? $payload['page_type']
                ?? $this->request->getParam('layout_type', ThemeLayout::PAGE_TYPE_HOME)
            ));
            $layoutOption = $this->normalizeLayoutOption((string)($payload['layout_option'] ?? 'default'));
            $scope = (string)($payload['scope'] ?? $this->request->getParam('scope', PreviewContextService::DEFAULT_SCOPE));
            $editorArea = $typedContext->area;
            $layoutType = $typedContext->layoutType;
            $layoutOption = $typedContext->layoutOption;
            $scope = $this->legacyScopeForEditorContext($typedContext);
            $theme = $this->resolveEditorRequestTheme(
                $editorArea,
                $typedContext instanceof ThemeEditorContext
                    ? $typedContext->themeId
                    : (int)($payload['theme_id'] ?? 0),
            );
            $layoutOptionsByType = $this->getEditorLayoutOptionsByType($theme, $editorArea);

            if (!$this->editorLayoutOptionExists($layoutOptionsByType, $layoutType, $layoutOption)) {
                throw new \RuntimeException((string)__('Selected layout option is unavailable.'));
            }

            // Compatibility endpoint only validates the selection. The scoped
            // workspace is the draft authority; legacy projection happens only
            // after an immutable Release is published.
            $effectiveScope = $scope;

            return [
                'success' => true,
                'message' => __('Layout option draft validated.'),
                'data' => [
                    'theme_id' => (int)$theme->getId(),
                    'area' => $editorArea,
                    'scope' => $scope,
                    'effective_scope' => $effectiveScope,
                    'layout_type' => $layoutType,
                    'layout_option' => $layoutOption,
                    'layout_options_by_type' => $this->compactEditorLayoutOptions($layoutOptionsByType),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function getLayoutConfig()
    {
        return $this->fetchJson($this->getLayoutConfigPayload());
    }

    public function getLayoutConfigPayload(): array
    {
        try {
            $writeInput = $this->getEditorJsonPayload();
            $this->requireLayoutWriteContext($writeInput);
            [$theme, $editorArea, $layoutType, $layoutOption, $scope, $locale] = $this->resolveLayoutConfigContext();
            $layoutIdentify = $this->buildLayoutConfigIdentify($layoutType, $layoutOption);
            $targetIdentify = $this->buildTargetLayoutConfigIdentify($editorArea, $layoutType, $layoutOption);
            $identify = $targetIdentify !== '' ? $targetIdentify : $layoutIdentify;
            $definitions = $this->loadLayoutParamDefinitions($theme, $editorArea, $layoutType, $layoutOption, $layoutIdentify);
            $config = $this->getLayoutConfigValues($theme, $layoutIdentify, $scope, $locale, $definitions, $targetIdentify);
            if (array_key_exists('editor_context', $writeInput)) {
                /** @var ThemeEditorContextFactory $factory */
                $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
                $typedContext = $factory->fromInput(
                    $writeInput,
                    $locale === null ? ThemeEditorContext::RESOURCE_META : ThemeEditorContext::RESOURCE_I18N,
                );
                /** @var ThemeScopedWorkspaceInterface $scopedWorkspace */
                $scopedWorkspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
                $scopedState = $scopedWorkspace->load($typedContext, true);
                $draftPayload = is_array($scopedState['draft_payload'] ?? null)
                    ? $scopedState['draft_payload']
                    : [];
                $draftConfig = $locale === null
                    ? ($draftPayload['values'] ?? null)
                    : ($draftPayload['translations']['layout'] ?? null);
                if (is_array($draftConfig)) {
                    $config = $draftConfig;
                }
            }

            $formHtml = $this->paramFormRenderer->renderForm($identify, $definitions, $config, [
                'class' => 'w-param-form layout-config-form',
                'auto_save' => false,
                'delete_button' => false,
                'empty_message' => (string)__('No configurable layout fields.'),
                'actions_html' => '<button type="submit" class="w-button btn-save-layout-config" data-tone="primary">' . __('Save layout config') . '</button>',
            ]);

            return [
                'success' => true,
                'data' => [
                    'theme_id' => (int)$theme->getId(),
                    'area' => $editorArea,
                    'scope' => $scope,
                    'locale' => $locale,
                    'layout_type' => $layoutType,
                    'layout_option' => $layoutOption,
                    'identify' => $identify,
                    'layout_identify' => $layoutIdentify,
                    'target_identify' => $targetIdentify,
                    'params' => $definitions,
                    'config' => $config,
                    'locales' => $this->getInstalledLocalesPayload(),
                    'form_html' => $formHtml,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function postSaveLayoutConfig()
    {
        return $this->fetchJson($this->saveLayoutConfigPayload());
    }

    public function saveLayoutConfigPayload(): array
    {
        try {
            $writeInput = $this->getEditorJsonPayload();
            if (!array_key_exists('editor_context', $writeInput)) {
                throw new \InvalidArgumentException('theme_editor_typed_context_required');
            }
            [$theme, $editorArea, $layoutType, $layoutOption, $scope, $locale] = $this->resolveLayoutConfigContext();
            $layoutIdentify = $this->buildLayoutConfigIdentify($layoutType, $layoutOption);
            $targetIdentify = $this->buildTargetLayoutConfigIdentify($editorArea, $layoutType, $layoutOption);
            $identify = $targetIdentify !== '' ? $targetIdentify : $layoutIdentify;
            $definitions = $this->loadLayoutParamDefinitions($theme, $editorArea, $layoutType, $layoutOption, $layoutIdentify);
            $configData = $this->request->getParam('config', []);
            if (!is_array($configData)) {
                $configData = [];
            }
            if (empty($configData)) {
                $rawBody = file_get_contents('php://input');
                $payload = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : null;
                if (is_array($payload) && isset($payload['config']) && is_array($payload['config'])) {
                    $configData = $payload['config'];
                }
            }

            $validatedConfig = [];
            foreach ($configData as $paramName => $value) {
                $paramName = trim((string)$paramName);
                if ($paramName === '' || !isset($definitions[$paramName])) {
                    continue;
                }
                $validatedConfig[$paramName] = $value;
            }

            return [
                'success' => true,
                'message' => __('Layout config draft validated.'),
                'data' => [
                    'theme_id' => (int)$theme->getId(),
                    'area' => $editorArea,
                    'scope' => $scope,
                    'locale' => $locale,
                    'layout_type' => $layoutType,
                    'layout_option' => $layoutOption,
                    'identify' => $identify,
                    'layout_identify' => $layoutIdentify,
                    'target_identify' => $targetIdentify,
                    'config' => $validatedConfig,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function postAiTranslateConfig()
    {
        if ($this->wantsAiTranslateConfigStream()) {
            $this->streamAiTranslateConfig();

            return;
        }

        return $this->fetchJson($this->aiTranslateConfigPayload());
    }

    private function wantsAiTranslateConfigStream(): bool
    {
        $accept = strtolower(trim((string)($this->request->getHeader('Accept') ?? '')));
        if ($accept !== '' && (str_starts_with($accept, 'text/event-stream') || str_contains($accept, 'text/event-stream'))) {
            return true;
        }

        $payload = $this->getThemeAiRequestPayload();
        $streamFlag = $payload['stream'] ?? $this->request->getParam('stream', null);
        if (is_bool($streamFlag)) {
            return $streamFlag;
        }
        if (is_int($streamFlag) || is_float($streamFlag)) {
            return ((int)$streamFlag) === 1;
        }

        $normalized = strtolower(trim((string)$streamFlag));

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'stream'], true);
    }

    private function streamAiTranslateConfig(): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->setHeartbeatInterval(15)->start();

        try {
            $payload = $this->getThemeAiRequestPayload();
            $sourceText = trim((string)($payload['source_text'] ?? ''));
            if ($sourceText === '') {
                $sse->sendError((string)__('缺少源文案。'), 400);
                $sse->close();

                return;
            }

            $sourceLocale = trim((string)($payload['source_locale'] ?? 'zh_Hans_CN')) ?: 'zh_Hans_CN';
            $targetLocales = $this->normalizeThemeAiLocaleList($payload['target_locales'] ?? []);
            $targetLocales = array_values(array_filter(
                $targetLocales,
                static fn(string $locale): bool => $locale !== '' && $locale !== $sourceLocale
            ));
            if ($targetLocales === []) {
                $sse->sendError((string)__('缺少目标语言。'), 400);
                $sse->close();

                return;
            }

            $fieldKey = trim((string)($payload['field_key'] ?? ''));
            $total = count($targetLocales);
            $translations = [];
            $failed = [];

            $sse->sendEvent('start', [
                'message' => (string)__('开始 AI 翻译'),
                'source_locale' => $sourceLocale,
                'target_locales' => $targetLocales,
                'total' => $total,
                'current' => 0,
                'progress' => 0,
                'field_key' => $fieldKey,
            ]);

            foreach ($targetLocales as $index => $locale) {
                if (!$sse->isAlive()) {
                    return;
                }

                $current = $index + 1;
                $sse->sendEvent('progress', [
                    'message' => (string)__('正在翻译 %{1}…', [$locale]),
                    'locale' => $locale,
                    'current' => $current,
                    'total' => $total,
                    'progress' => (int)floor((($current - 1) / max(1, $total)) * 100),
                    'status' => 'translating',
                ]);

                try {
                    $text = $this->translateThemeConfigTextViaAdapter($sourceText, $locale, $sourceLocale);
                    if ($text === '') {
                        throw new \RuntimeException((string)__('AI 翻译未返回目标语言结果。'));
                    }
                    $translations[$locale] = $text;
                    $sse->sendEvent('locale', [
                        'message' => (string)__('已完成 %{1}', [$locale]),
                        'locale' => $locale,
                        'text' => $text,
                        'current' => $current,
                        'total' => $total,
                        'progress' => (int)floor(($current / max(1, $total)) * 100),
                        'status' => 'done',
                    ]);
                } catch (\Throwable $localeError) {
                    $errorMessage = trim(strip_tags($localeError->getMessage()));
                    $failed[] = [
                        'locale' => $locale,
                        'message' => $errorMessage,
                    ];
                    $sse->sendEvent('locale_error', [
                        'message' => (string)__('翻译 %{1} 失败：%{2}', [$locale, $errorMessage]),
                        'locale' => $locale,
                        'current' => $current,
                        'total' => $total,
                        'progress' => (int)floor(($current / max(1, $total)) * 100),
                        'status' => 'error',
                    ]);
                }
            }

            $sse->complete([
                'success' => $translations !== [],
                'message' => $translations === []
                    ? (string)__('AI翻译未返回目标语言结果')
                    : (string)__('AI翻译已回填'),
                'data' => [
                    'source_locale' => $sourceLocale,
                    'target_locales' => $targetLocales,
                    'translations' => $translations,
                    'failed' => $failed,
                    'translated_count' => count($translations),
                    'failed_count' => count($failed),
                ],
            ]);
        } catch (\Throwable $e) {
            try {
                if (!$sse->isStarted()) {
                    $sse->start();
                }
                $sse->sendError((string)__('主题配置 AI 翻译失败：%{1}', [trim(strip_tags($e->getMessage()))]), 500);
                $sse->close();
            } catch (\Throwable) {
                // ignore secondary stream failures
            }
        }
    }

    public function aiTranslateConfigPayload(): array
    {
        try {
            $payload = $this->getThemeAiRequestPayload();
            $sourceText = trim((string)($payload['source_text'] ?? ''));
            if ($sourceText === '') {
                return [
                    'success' => false,
                    'message' => __('缺少源文案。'),
                ];
            }

            $sourceLocale = trim((string)($payload['source_locale'] ?? 'zh_Hans_CN')) ?: 'zh_Hans_CN';
            $targetLocales = $this->normalizeThemeAiLocaleList($payload['target_locales'] ?? []);
            $targetLocales = array_values(array_filter(
                $targetLocales,
                static fn(string $locale): bool => $locale !== '' && $locale !== $sourceLocale
            ));
            if ($targetLocales === []) {
                return [
                    'success' => false,
                    'message' => __('缺少目标语言。'),
                ];
            }

            $translations = [];
            foreach ($targetLocales as $locale) {
                $text = $this->translateThemeConfigTextViaAdapter($sourceText, $locale, $sourceLocale);
                if ($text === '') {
                    throw new \RuntimeException((string)__('AI 翻译未返回目标语言结果。'));
                }
                $translations[$locale] = $text;
            }

            return [
                'success' => true,
                'message' => __('AI翻译已回填'),
                'data' => [
                    'source_locale' => $sourceLocale,
                    'target_locales' => $targetLocales,
                    'translations' => $translations,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('主题配置 AI 翻译失败：%{1}', [trim(strip_tags($e->getMessage()))]),
            ];
        }
    }

    private function translateThemeConfigTextViaAdapter(string $sourceText, string $targetLocale, string $sourceLocale): string
    {
        if (!class_exists(\Weline\Ai\Api\TranslationService::class)) {
            throw new \RuntimeException((string)__('AI翻译服务未找到，请确保AI模块已安装'));
        }

        /** @var \Weline\Ai\Api\TranslationService $translationService */
        $translationService = ObjectManager::getInstance(\Weline\Ai\Api\TranslationService::class);
        $text = trim((string)$translationService->translate(
            $sourceText,
            $targetLocale,
            $sourceLocale !== '' ? $sourceLocale : 'auto',
            \Weline\Ai\Api\TranslationService::STRATEGY_LIGHT
        ));

        return $text;
    }

    public function postSaveWidgetConfig()
    {
        $data = $this->getEditorJsonPayload();
        $layoutId = (int)($data['layout_id'] ?? 0);
        $configData = $data['config'] ?? [];
        $locale = $data['locale'] ?? null; // null表示保存为默认值
        $locale = is_string($locale) ? trim($locale) : $locale;
        if ($locale === '') {
            $locale = null;
        }
        
        if (!$layoutId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少布局ID'),
            ]);
        }
        
        if (!is_array($configData)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('配置数据格式错误'),
            ]);
        }
        
        // 获取部件信息
        $widgetLayout = $this->themeLayout->reset()->load($layoutId);
        
        if (!$widgetLayout->getLayoutId()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('部件不存在'),
            ]);
        }
        
        $widgetModule = $widgetLayout->getData('widget_module');
        $widgetCode = $widgetLayout->getData('widget_code');
        $widgetType = $widgetLayout->getData('widget_type') ?: '';
        $slotArea = (string)($widgetLayout->getData('area') ?: ThemeLayout::AREA_CONTENT);
        $area = $this->normalizeThemeConfigArea($slotArea);
        
        try {
            $context = $this->requireLayoutWriteContext($data);
            $this->assertLayoutBelongsToEditorContext($widgetLayout, $context);
            $this->assertDraftLayout($widgetLayout);
            // 获取参数定义以识别可翻译路径
            $paramDefs = $this->getWidgetParamDefinitions($widgetModule, $widgetCode, $area, $widgetType);

            $configData = $this->normalizeWidgetConfigValues($configData, $paramDefs);
            $existingConfig = $this->ensureWidgetI18nInstance($widgetLayout);
            $normalConfig = $locale === null
                ? $this->materializeWidgetConfigPaths($configData, $existingConfig)
                : $configData;
            if ($locale === null) {
                $normalConfig = $this->preserveWidgetI18nInstance($normalConfig, $existingConfig);
            }

            $previewHtml = $this->buildPreviewHtmlForLayoutId(
                $layoutId,
                $normalConfig,
                null,
            );

            return $this->fetchJson([
                'success' => true,
                'message' => $locale
                    ? __('已校验 %{locale} 语言的 Scope 草稿', ['locale' => $locale])
                    : __('Scope 草稿已校验'),
                'config' => $normalConfig,
                'locale' => $locale,
                'node_uid' => $widgetLayout->getNodeUid(),
                'preview_html' => $previewHtml,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 编译布局模板 (GET) - 返回编译后的带插槽标记的 HTML
     * 
     * 用于可视化编辑器加载编译后的页面
     */
    public function getCompileLayout()
    {
        return $this->fetchJson($this->getCompileLayoutPayload());
    }

    public function getCompileLayoutPayload(): array
    {
        $previewContextService = $this->getPreviewContextService();
        $editorArea = $this->resolveRequestedEditorArea(PreviewContextService::AREA_BACKEND);
        $layoutType = (string)$this->request->getParam('layout_type', 'homepage');
        $layoutOption = (string)$this->request->getParam('layout_option', 'default');
        $includeHtml = !in_array(
            strtolower((string)$this->request->getParam('include_html', '1')),
            ['0', 'false', 'no'],
            true
        );
        $context = $this->persistEditorContext([
            'frontend_theme_id' => (int)$this->request->getParam('frontend_theme_id', 0),
            'backend_theme_id' => (int)$this->request->getParam('backend_theme_id', 0),
            'editor_area' => $editorArea,
            'shell' => PreviewContextService::SHELL_THEME_EDITOR,
            'preview_mode' => (string)$this->request->getParam('preview_mode', PreviewContextService::DEFAULT_PREVIEW_MODE),
            'status' => (string)$this->request->getParam('status', PreviewContextService::DEFAULT_STATUS),
            'version_id' => (int)$this->request->getParam('version_id', 0) ?: null,
            'scope' => (string)$this->request->getParam('scope', PreviewContextService::DEFAULT_SCOPE),
            'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
            'target_value' => $layoutType,
        ]);
        $themeId = $previewContextService->getThemeIdForArea($editorArea, $context, true);

        if (!$themeId) {
            return [
                'success' => false,
                'message' => __('Missing theme ID'),
            ];
        }

        $session = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
        $session->setData('preview_theme_id', $themeId);
        $session->setData('preview_theme_area', $editorArea);
        $this->assign('preview_exit_url', $this->buildEditorShellUrl($context, $layoutType));

        try {
            $this->welineTheme->load($themeId);
            if (!$this->welineTheme->getId()) {
                return [
                    'success' => false,
                    'message' => __('Theme not found'),
                ];
            }

            $html = $this->renderUnifiedLayoutPreview($themeId, $layoutType, $layoutOption, $editorArea, $context);
            if ($editorArea === PreviewContextService::AREA_BACKEND && !$this->isDashboardPreviewLayout($layoutType)) {
                $html = $this->injectBackendStructuralSlots($html);
            }
            $slots = $this->extractSlots($html);
            $missingSlotWarnings = $this->collectMissingSlotWarningsForEditor($editorArea, $layoutType, $layoutOption);
            $layoutIdentify = $this->buildLayoutConfigIdentify($layoutType, $layoutOption);
            $targetIdentify = $this->buildTargetLayoutConfigIdentify($editorArea, $layoutType, $layoutOption);
            $meta = $this->getLayoutConfigValues(
                $this->welineTheme,
                $layoutIdentify,
                (string)($context['scope'] ?? 'default'),
                (string)$this->request->getParam('locale', ''),
                [],
                $targetIdentify
            );

            return [
                'success' => true,
                'html' => $includeHtml ? $html : '',
                'slots' => $slots,
                'meta' => $meta,
                'missing_slot_warnings' => $missingSlotWarnings,
                'missing' => $missingSlotWarnings,
                'warnings' => $missingSlotWarnings,
                'layout' => [
                    'type' => $layoutType,
                    'option' => $layoutOption,
                    'identify' => $layoutIdentify,
                    'target_identify' => $targetIdentify,
                ],
                'context' => $context,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'html' => '',
                'slots' => [],
                'meta' => [],
                'missing' => [],
                'warnings' => [$e->getMessage()],
            ];
        }
    }

    /** 从 HTML 中提取 Weline 插槽信息。 */
    private function extractSlots(string $html): array
    {
        $slots = [];

        if (strpos($html, 'data-wslot') !== false) {
            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            $htmlForDom = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
            @$doc->loadHTML($htmlForDom, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            $xpath = new \DOMXPath($doc);
            foreach ($xpath->query("//*[@data-wslot]") ?: [] as $element) {
                if (!$element instanceof \DOMElement) {
                    continue;
                }
                $slotId = $element->getAttribute('data-wslot');
                if ($slotId === '') {
                    continue;
                }
                $acceptAttr = $element->getAttribute('data-wslot-accept');
                $slots[$slotId] = [
                    'id' => $slotId,
                    'name' => $element->getAttribute('data-wslot-name') ?: $slotId,
                    'accept' => array_values(array_filter(array_map('trim', explode(',', $acceptAttr)))),
                    'exclusive' => $element->getAttribute('data-wslot-exclusive') === 'true',
                    'multiple' => $element->getAttribute('data-wslot-multiple') !== 'false',
                    'position' => $element->getAttribute('data-wslot-position'),
                ];
            }
        }

        return $slots;
    }

    /**
     * 解析编辑器当前选中的主题（优先请求参数 theme_id）
     */
    private function resolveEditorThemeFromRequest(): ?WelineTheme
    {
        $themeId = (int)$this->request->getParam('theme_id', 0);
        if ($themeId <= 0) {
            return null;
        }

        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->reset()->load($themeId);

        return $theme->getId() ? $theme : null;
    }

    /**
     * 为部件库预编译预览 HTML
     */
    private function attachWidgetPreviewHtml(
        array $availableWidgets,
        ?WelineTheme $theme = null,
        string $editorArea = PreviewContextService::AREA_FRONTEND
    ): array
    {
        foreach ($availableWidgets as $type => &$group) {
            if (empty($group['widgets']) || !is_array($group['widgets'])) {
                continue;
            }
            foreach ($group['widgets'] as &$widget) {
                if (!is_array($widget)) {
                    continue;
                }
                $widget['preview_html'] = $this->buildWidgetPreviewHtml($widget, $theme, $editorArea);
            }
        }

        return $availableWidgets;
    }

    /**
     * 使用默认参数渲染部件预览 HTML
     */
    private function buildWidgetPreviewHtml(
        array $widget,
        ?WelineTheme $theme = null,
        string $editorArea = PreviewContextService::AREA_FRONTEND
    ): string
    {
        $previewArea = $editorArea === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;
        $widgetModule = (string)($widget['module'] ?? $widget['widget_module'] ?? '');
        $widgetType = (string)($widget['type'] ?? $widget['widget_type'] ?? '');
        $widgetCode = (string)($widget['code'] ?? $widget['widget_code'] ?? '');

        // 在所选主题上下文中渲染，确保部件库预览反映当前编辑主题的模板覆盖，
        // 而不是回退到全局激活主题。
        $previousThemeData = ThemeData::getCurrentTheme();
        $previousArea = ThemeData::getCurrentArea();
        $shouldSwitchThemeData = $theme?->getId()
            && (int)($previousThemeData?->getId() ?? 0) !== (int)$theme->getId();
        if ($shouldSwitchThemeData) {
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($previewArea);
        }

        try {
            if ($widgetModule === 'Weline_Theme' && $widgetCode !== '') {
                /** @var \Weline\Theme\Service\ThemePlaceableRegistry $placeableRegistry */
                $placeableRegistry = ObjectManager::getInstance(\Weline\Theme\Service\ThemePlaceableRegistry::class);
                $registryType = $widgetType === 'theme_component' || str_contains($widgetCode, '/')
                    ? 'theme_component'
                    : ($widgetType !== '' ? $widgetType : 'theme_component');
                $html = $placeableRegistry->renderPreview(
                    $widgetModule,
                    $registryType,
                    $widgetCode,
                    array_merge($this->buildWidgetPreviewDefaultConfig($widgetCode), ['preview_mode' => true]),
                    $theme,
                    $previewArea
                );
                $html = $this->sanitizeWidgetPreviewHtml($html);
                if (!$this->isWidgetPreviewFallbackHtml($html)) {
                    return $html;
                }
                $fallbackHtml = $this->buildWidgetPreviewFallbackHtml($widgetCode, (string)($widget['name'] ?? $widgetCode));
                if ($fallbackHtml !== '') {
                    return $fallbackHtml;
                }
            }

            $template = $widget['template'] ?? '';
            if (!$template) {
                $fallbackHtml = $this->buildWidgetPreviewFallbackHtml($widgetCode, (string)($widget['name'] ?? $widgetCode));
                if ($fallbackHtml !== '') {
                    return $fallbackHtml;
                }
                return '<div class="widget-preview-placeholder">' . htmlspecialchars((string)($widget['name'] ?? $widgetCode)) . '</div>';
            }

            $defaultConfig = $this->buildWidgetPreviewDefaultConfig($widgetCode);
            foreach ($widget['params'] ?? [] as $key => $param) {
                if (!is_array($param)) {
                    continue;
                }
                if (array_key_exists((string)$key, $defaultConfig)) {
                    continue;
                }
                $defaultValue = $param['default'] ?? '';
                if (($key === 'end_date' || $key === 'countdown_end') && empty($defaultValue)) {
                    $defaultValue = date('Y-m-d H:i:s', time() + 86400);
                }
                $defaultConfig[$key] = $defaultValue;
            }
            $defaultConfig['preview_mode'] = true;

            try {
                $templateObj = $this->getTemplate();
                // WLS 下 Template 单例 _data 会跨请求残留，渲染前清空，避免上一部件数据污染当前预览
                $templateObj->unsetData();
                $html = $templateObj->fetchHtml($template, $defaultConfig);
                $html = $this->sanitizeWidgetPreviewHtml(is_string($html) ? $html : '');
                if (!$this->isWidgetPreviewFallbackHtml($html)) {
                    return $html;
                }
                $fallbackHtml = $this->buildWidgetPreviewFallbackHtml($widgetCode, (string)($widget['name'] ?? $widgetCode));
                if ($fallbackHtml !== '') {
                    return $fallbackHtml;
                }
                return $html;
            } catch (\Exception $e) {
                return '<div class="widget-preview-error">' . htmlspecialchars((string)$e->getMessage()) . '</div>';
            }
        } finally {
            if ($shouldSwitchThemeData) {
                ThemeData::setCurrentTheme($previousThemeData);
                ThemeData::setCurrentArea($previousArea);
            }
        }
    }

    /**
     * 根据插槽名或部件类型推断实际所属区域
     * 
     * @param string $slotOrArea 插槽名或区域名
     * @param string $widgetType 部件类型
     * @param string $widgetCode 部件代码
     * @return string 推断的区域代码
     */
    private function inferAreaFromSlot(string $slotOrArea, string $widgetType = '', string $widgetCode = ''): string
    {
        // Header 相关的插槽名
        $headerSlots = [
            'logo', 'delivery', 'search', 'main-nav', 'category-menu', 'user-area', 'cart', 'language', 'currency',
            'header-left', 'header-center', 'header-right', 'header-container',
            'top-bar', 'top-bar-left', 'top-bar-right', 'navigation',
        ];
        
        // Footer 相关的插槽名
        $footerSlots = [
            'footer-left', 'footer-center', 'footer-right', 'footer-container',
            'footer-links', 'footer-social', 'copyright', 'footer-newsletter',
        ];
        
        // 检查插槽名是否匹配 header 区域
        $slotLower = strtolower($slotOrArea);
        foreach ($headerSlots as $hs) {
            if ($slotLower === $hs || str_contains($slotLower, 'header') || str_starts_with($slotLower, 'top-')) {
                return ThemeLayout::AREA_HEADER;
            }
        }
        if (in_array($slotLower, $headerSlots, true)) {
            return ThemeLayout::AREA_HEADER;
        }
        
        // 检查插槽名是否匹配 footer 区域
        foreach ($footerSlots as $fs) {
            if ($slotLower === $fs || str_contains($slotLower, 'footer') || $slotLower === 'copyright') {
                return ThemeLayout::AREA_FOOTER;
            }
        }
        if (in_array($slotLower, $footerSlots, true)) {
            return ThemeLayout::AREA_FOOTER;
        }
        
        // 根据部件类型推断
        $headerTypes = ['header', 'navigation', 'search', 'logo', 'cart', 'language', 'currency'];
        $footerTypes = ['footer', 'social', 'newsletter', 'copyright'];
        
        $typeLower = strtolower($widgetType);
        $codeLower = strtolower($widgetCode);
        
        foreach ($headerTypes as $ht) {
            if (str_contains($typeLower, $ht) || str_contains($codeLower, $ht)) {
                return ThemeLayout::AREA_HEADER;
            }
        }
        
        foreach ($footerTypes as $ft) {
            if (str_contains($typeLower, $ft) || str_contains($codeLower, $ft)) {
                return ThemeLayout::AREA_FOOTER;
            }
        }
        
        // 默认归到 content 区域
        return ThemeLayout::AREA_CONTENT;
    }

    /**
     * 根据布局ID和配置构建预览 HTML（用于配置保存后返回）
     *
     * @param int $layoutId 布局ID
     * @param array $config 部件配置（可选，未提供则从数据库获取）
     * @param string|null $locale 预览语言，null 表示默认语言
     * @return string|null 预览 HTML，失败返回 null
     */
    private function buildPreviewHtmlForLayoutId(int $layoutId, array $config = [], ?string $locale = null): ?string
    {
        // 获取部件信息
        $layoutData = $this->layoutService->getWidgetByLayoutId($layoutId);
        if (!$layoutData) {
            return null;
        }

        $widgetModule = $layoutData['widget_module'] ?? '';
        $widgetType = (string)($layoutData['widget_type'] ?? '');
        $widgetCode = $layoutData['widget_code'] ?? '';

        if (!$widgetModule || !$widgetCode) {
            return null;
        }

        $previewConfig = array_merge($layoutData['config'] ?? [], $config);
        $previewConfig['layout_id'] = (string)$layoutId;
        $previewConfig['_layout_id'] = (string)$layoutId;
        $previewConfig['slot_id'] = (string)($layoutData['slot_id'] ?? '');
        $previewConfig['_slot_id'] = (string)($layoutData['slot_id'] ?? '');
        $previewConfig['editor_mode'] = true;
        $slotArea = (string)($layoutData['area'] ?? ThemeLayout::AREA_CONTENT) ?: ThemeLayout::AREA_CONTENT;
        $area = $this->normalizeThemeConfigArea($slotArea);
        $locale = trim((string)($locale ?? ''));
        if ($locale !== '') {
            $params = $this->getWidgetParamDefinitions($widgetModule, $widgetCode, $area, $widgetType);
            if (!empty($params)) {
                $instanceId = trim((string)($previewConfig[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? ''));
                $identify = $instanceId !== ''
                    ? ThemeData::getWidgetInstanceIdentify($instanceId, $area)
                    : $this->resolveThemeConfigIdentify($widgetModule, $widgetType, $widgetCode, $area);
                $previewConfig = $this->mergeTranslatedPathsForLayout($previewConfig, $params, $identify, $locale, $slotArea, $area);
            }
        }

        $resolvedLocale = $this->resolvePreviewHydrationLocale($locale, $layoutData);
        $scopeIdentity = null;
        try {
            $scopeIdentity = ObjectManager::getInstance(ThemeLayoutScopeNormalizer::class)
                ->identityFromEncodedScope((string)($layoutData['scope'] ?? 'default'));
        } catch (\Throwable) {
            $scopeIdentity = RequestContext::scopeIdentity();
        }
        if (!$scopeIdentity instanceof ScopeIdentity) {
            throw new \RuntimeException((string)__('主题预览缺少冻结的 ScopeIdentity。'));
        }
        $previewConfig = ObjectManager::getInstance(LayoutValueHydrationRegistry::class)->hydrate(
            $previewConfig,
            [
                'scope_identity' => $scopeIdentity,
                'locale_code' => $resolvedLocale,
                'purpose' => 'preview',
            ],
        );

        if ($widgetModule === 'Weline_Theme' && ($widgetType === 'theme_component' || str_contains($widgetCode, '/'))) {
            /** @var \Weline\Theme\Service\ThemePlaceableRegistry $placeableRegistry */
            $placeableRegistry = ObjectManager::getInstance(\Weline\Theme\Service\ThemePlaceableRegistry::class);
            $html = $placeableRegistry->renderPreview('Weline_Theme', 'theme_component', (string)$widgetCode, $previewConfig, null, $area);
            if ($html !== '') {
                return $this->sanitizeWidgetPreviewHtml($html);
            }

            return '<div class="widget-preview-placeholder">' . htmlspecialchars((string)$widgetCode) . '</div>';
        }

        $eventData = [
            'data' => [
                'operation' => 'preview',
                'params' => [
                    'widget_module' => $widgetModule,
                    'widget_code' => $widgetCode,
                    'config' => $previewConfig,
                    'area' => $area,
                ],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_Widget::query', $eventData);
        $html = $eventData['data']['result'] ?? null;
        $err = $eventData['data']['error'] ?? null;
        if ($err !== null && $err !== '') {
            return '<div class="widget-preview-error">' . htmlspecialchars((string)$err) . '</div>';
        }
        return is_string($html) ? $html : '<div class="widget-preview-placeholder">' . htmlspecialchars((string)$widgetCode) . '</div>';
    }

    /**
     * Resolve the concrete locale for typed layout hydration during editor preview.
     *
     * Default/all-language layouts and the media picker both stamp file-image usage
     * with the site default locale (see ThemeEditor shell data-default-locale).
     */
    private function resolvePreviewHydrationLocale(string $explicitLocale, array $layoutData): string
    {
        $resolvedLocale = trim($explicitLocale);
        if ($resolvedLocale === '') {
            $resolvedLocale = trim((string)($layoutData['locale_code'] ?? ''));
        }
        if ($resolvedLocale === '' || strcasecmp($resolvedLocale, 'default') === 0) {
            $resolvedLocale = trim((string)Env::default_LANGUAGE_CODE);
        }
        if ($resolvedLocale === '' || strcasecmp($resolvedLocale, 'default') === 0) {
            $resolvedLocale = trim((string)RequestContext::getWelineUserLang());
        }
        if ($resolvedLocale === '' || strcasecmp($resolvedLocale, 'default') === 0) {
            throw new \RuntimeException((string)__('主题预览缺少可用的布局语言。'));
        }

        return $resolvedLocale;
    }

    /**
     * 按 module + code 从注册表查找部件元数据
     */
    private function findWidgetMetaByModuleAndCode(
        string $widgetModule,
        string $widgetCode,
        string $area = PreviewContextService::AREA_FRONTEND
    ): ?array
    {
        $area = $area === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;
        $registry = $this->widgetRegistry->getRegistry();
        foreach ($registry as $type => $typeWidgets) {
            if (!is_array($typeWidgets)) {
                continue;
            }
            foreach ($typeWidgets as $code => $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                if (($widget['module'] ?? '') === $widgetModule && ($widget['code'] ?? '') === $widgetCode) {
                    $widgetArea = (string)($widget['area'] ?? PreviewContextService::AREA_FRONTEND);
                    if ($widgetArea !== '' && $widgetArea !== $area) {
                        continue;
                    }
                    return $widget;
                }
            }
        }

        if ($widgetModule === 'Weline_Theme' && str_contains($widgetCode, '/')) {
            /** @var \Weline\Theme\Service\ThemePlaceableRegistry $placeableRegistry */
            $placeableRegistry = ObjectManager::getInstance(\Weline\Theme\Service\ThemePlaceableRegistry::class);
            $definition = $placeableRegistry->find($widgetModule, 'theme_component', $widgetCode, null, $area);
            if ($definition) {
                return $definition->toWidgetArray();
            }
        }

        return null;
    }

    private function getWidgetParamDefinitions(string $widgetModule, string $widgetCode, string $area, string $widgetType = ''): array
    {
        $paramDefs = $this->queryWidgetParamDefinitions($widgetModule, $widgetCode, $area);
        if (!empty($paramDefs)) {
            return $paramDefs;
        }

        $definition = $this->findThemePlaceableDefinition($widgetModule, $widgetType, $widgetCode, $area);
        if ($definition) {
            return $definition->params ?: $definition->configSchema;
        }

        if ($area !== 'frontend') {
            $paramDefs = $this->queryWidgetParamDefinitions($widgetModule, $widgetCode, 'frontend');
            if (!empty($paramDefs)) {
                return $paramDefs;
            }
        }

        return [];
    }

    private function queryWidgetParamDefinitions(string $widgetModule, string $widgetCode, string $area): array
    {
        $eventData = [
            'data' => [
                'operation' => 'getParamDefinitions',
                'params' => [
                    'widget_module' => $widgetModule,
                    'widget_code' => $widgetCode,
                    'area' => $area,
                ],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_Widget::query', $eventData);
        $paramDefs = $eventData['data']['result'] ?? [];
        return is_array($paramDefs) ? $paramDefs : [];
    }

    private function findThemePlaceableDefinition(string $widgetModule, string $widgetType, string $widgetCode, string $area): ?\Weline\Theme\Dto\ThemeComponentDefinition
    {
        if ($widgetModule !== 'Weline_Theme') {
            return null;
        }

        /** @var ThemePlaceableRegistry $placeableRegistry */
        $placeableRegistry = ObjectManager::getInstance(ThemePlaceableRegistry::class);
        $candidateTypes = [];
        $widgetType = trim($widgetType);
        if ($widgetType !== '') {
            $candidateTypes[] = $widgetType;
        }
        if (str_contains($widgetCode, '/')) {
            $candidateTypes[] = 'theme_component';
        }
        $candidateTypes = array_values(array_unique($candidateTypes));
        if ($candidateTypes === []) {
            return null;
        }

        $candidateAreas = array_values(array_unique(array_filter([$area, 'frontend'])));
        foreach ($candidateAreas as $candidateArea) {
            foreach ($candidateTypes as $candidateType) {
                $definition = $placeableRegistry->find($widgetModule, $candidateType, $widgetCode, null, $candidateArea);
                if ($definition) {
                    return $definition;
                }
            }
        }

        return null;
    }

    private function normalizeWidgetConfigValues(array $configData, array $paramDefs): array
    {
        if (empty($configData) || empty($paramDefs)) {
            return $configData;
        }

        $pathConfig = [];
        $normalConfig = [];
        foreach ($configData as $key => $value) {
            if (is_string($key) && str_contains($key, '.')) {
                $pathConfig[$key] = $value;
            } else {
                $normalConfig[$key] = $value;
            }
        }

        if (empty($normalConfig)) {
            return $configData;
        }

        $eventData = [
            'data' => [
                'operation' => 'processConfig',
                'params' => [
                    'params' => $paramDefs,
                    'values' => $normalConfig,
                ],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_Widget::query', $eventData);
        $processed = $eventData['data']['result'] ?? null;
        if (!is_array($processed)) {
            return $configData;
        }

        return array_merge($processed, $pathConfig);
    }

    private function normalizeWidgetConfigForLayout(ThemeLayout $widgetLayout, array $configData): array
    {
        $widgetModule = (string)$widgetLayout->getData('widget_module');
        $widgetCode = (string)$widgetLayout->getData('widget_code');
        $area = $this->normalizeThemeConfigArea((string)($widgetLayout->getData('area') ?: ThemeLayout::AREA_CONTENT));
        if ($widgetModule === '' || $widgetCode === '') {
            return $configData;
        }

        $widgetType = (string)($widgetLayout->getData('widget_type') ?: '');
        $paramDefs = $this->getWidgetParamDefinitions($widgetModule, $widgetCode, $area, $widgetType);
        return $this->normalizeWidgetConfigValues($configData, $paramDefs);
    }

    private function resolveThemeConfigIdentify(string $widgetModule, string $widgetType, string $widgetCode, string $area): string
    {
        $area = $this->normalizeThemeConfigArea($area);
        if ($widgetModule === 'Weline_Theme' && ($widgetType === 'theme_component' || str_contains($widgetCode, '/'))) {
            /** @var ThemePlaceableRegistry $placeableRegistry */
            $placeableRegistry = ObjectManager::getInstance(ThemePlaceableRegistry::class);
            foreach (array_values(array_unique(array_filter([$area, 'frontend']))) as $candidateArea) {
                $definition = $placeableRegistry->find($widgetModule, 'theme_component', $widgetCode, null, $candidateArea);
                if ($definition) {
                    return $definition->getMetaIdentify();
                }
            }
        }

        return ThemeData::getWidgetIdentify($widgetModule, $widgetCode, $area);
    }

    private function normalizeThemeConfigArea(string $area): string
    {
        $area = strtolower(trim($area));
        if ($area === PreviewContextService::AREA_BACKEND) {
            return PreviewContextService::AREA_BACKEND;
        }
        if ($area === PreviewContextService::AREA_FRONTEND) {
            return PreviewContextService::AREA_FRONTEND;
        }

        $requestedArea = strtolower(trim((string)$this->request->getParam(
            'editor_area',
            $this->request->getParam('preview_area', PreviewContextService::AREA_FRONTEND)
        )));

        return $requestedArea === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;
    }

    private function mergeTranslatedPathsForLayout(
        array $config,
        array $params,
        string $identify,
        ?string $locale,
        string $slotArea,
        string $themeArea
    ): array {
        $locale = is_string($locale) ? trim($locale) : $locale;
        if ($locale === null || $locale === '' || empty($params)) {
            return $config;
        }

        // Backend theme.editorRequest has no storefront ScopeIdentity. Seed the
        // authoritative editor storage scope so ThemeData translation dual-read
        // does not call ThemeContextService::resolveCurrentScope(frontend).
        $translationScope = $this->resolveEditorTranslationStorageScope($themeArea);
        if ($translationScope !== '') {
            ThemeData::seedRequestedScope($themeArea, $translationScope);
        }

        return ThemeData::mergeTranslatedPaths($config, $params, $identify, $locale);
    }

    private function resolveEditorTranslationStorageScope(string $themeArea): string
    {
        try {
            $payload = $this->getEditorJsonPayload();
            if (array_key_exists('editor_context', $payload)) {
                /** @var ThemeEditorContextFactory $factory */
                $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
                $typedContext = $factory->fromInput($payload, ThemeEditorContext::RESOURCE_I18N);

                return $this->legacyScopeForEditorContext($typedContext);
            }
        } catch (\Throwable) {
        }

        try {
            if (RequestContext::scopeIdentity() instanceof ScopeIdentity) {
                return ObjectManager::getInstance(ThemeContextService::class)
                    ->resolveCurrentScope($themeArea);
            }
        } catch (\Throwable) {
        }

        // Editor-only fallback: keep translation reads on the default website
        // scope without installing a synthetic RequestContext ScopeIdentity.
        return ThemeContextService::DEFAULT_SCOPE;
    }

    private function buildWidgetPreviewDefaultConfig(string $widgetCode): array
    {
        return match ($this->normalizeWidgetPreviewCode($widgetCode)) {
            'alert' => [
                'type' => 'info',
                'title' => __('系统提示'),
                'message' => __('这是一条状态提示信息。'),
                'icon' => 'info',
                'dismissible' => false,
            ],
            'badge' => [
                'text' => __('已启用'),
                'type' => 'success',
            ],
            'button' => [
                'text' => __('主要操作'),
                'type' => 'primary',
                'size' => 'md',
                'icon' => 'bolt',
            ],
            'card' => [
                'title' => __('数据卡片'),
                'subtitle' => __('用于组织内容'),
                'content' => __('这里展示核心信息、摘要或操作入口。'),
                'footer' => __('更新于刚刚'),
            ],
            'dropdown' => [
                'id' => 'component-preview-dropdown',
                'trigger' => __('选择操作'),
                'items' => [
                    ['text' => __('查看详情'), 'icon' => 'eye', 'url' => '#'],
                    ['text' => __('复制视图'), 'icon' => 'copy', 'url' => '#'],
                    ['divider' => true],
                    ['text' => __('归档'), 'icon' => 'archive', 'url' => '#'],
                ],
            ],
            'form-group' => [
                'label' => __('字段名称'),
                'value' => __('管理员'),
                'placeholder' => __('请输入内容'),
                'required' => true,
            ],
            'loading' => [
                'text' => __('正在同步数据'),
                'type' => 'spinner',
            ],
            'message' => [
                'type' => 'success',
                'title' => __('操作完成'),
                'message' => __('数据已保存，可以继续编辑。'),
            ],
            'modal' => [
                'title' => __('确认发布'),
                'content' => __('公开后同站点后台用户可见。'),
                'confirm_text' => __('确认'),
                'cancel_text' => __('取消'),
            ],
            'pagination' => [
                'current' => 2,
                'total' => 5,
                'prev_text' => __('上一页'),
                'next_text' => __('下一页'),
            ],
            'table' => [
                'headers' => [__('指标'), __('状态'), __('趋势')],
                'rows' => [
                    [__('订单'), __('正常'), '+12%'],
                    [__('支付'), __('稳定'), '+4%'],
                    [__('库存'), __('关注'), '-2%'],
                ],
                'striped' => true,
                'hover' => true,
            ],
            'tabs' => [
                'tabs' => [
                    ['label' => __('概览'), 'content' => __('这里展示核心信息、摘要或操作入口。'), 'active' => true],
                    ['label' => __('设置'), 'content' => __('用于组织内容'), 'active' => false],
                ],
            ],
            default => [],
        };
    }

    private function buildWidgetPreviewFallbackHtml(string $widgetCode, string $widgetName = ''): string
    {
        $code = $this->normalizeWidgetPreviewCode($widgetCode);
        $name = htmlspecialchars($widgetName !== '' ? $widgetName : $widgetCode, ENT_QUOTES, 'UTF-8');
        $icons = ObjectManager::getInstance(\Weline\Theme\Service\Ui\IconRegistry::class);

        return match ($code) {
            'alert' => '<div class="te-component-preview te-component-preview-alert">'
                . '<div class="w-alert" data-tone="info" role="alert">'
                . $icons->render('info', 'sm')
                . '<strong>' . htmlspecialchars((string)__('系统提示'), ENT_QUOTES, 'UTF-8') . '</strong>'
                . '<span>' . htmlspecialchars((string)__('这是一条状态提示信息。'), ENT_QUOTES, 'UTF-8') . '</span>'
                . '</div></div>',
            'badge' => '<div class="te-component-preview te-component-preview-badge">'
                . '<span class="w-badge" data-tone="success">' . htmlspecialchars((string)__('已启用'), ENT_QUOTES, 'UTF-8') . '</span>'
                . '<span class="w-badge" data-tone="info">' . htmlspecialchars((string)__('后台'), ENT_QUOTES, 'UTF-8') . '</span>'
                . '</div>',
            'button' => '<div class="te-component-preview te-component-preview-button">'
                . '<button type="button" class="w-button">' . $icons->render('bolt', 'sm') . ' '
                . htmlspecialchars((string)__('主要操作'), ENT_QUOTES, 'UTF-8') . '</button>'
                . '<button type="button" class="w-button" data-tone="neutral" data-size="sm">' . htmlspecialchars((string)__('次要'), ENT_QUOTES, 'UTF-8') . '</button>'
                . '</div>',
            'card' => '<div class="te-component-preview te-component-preview-card">'
                . '<article class="w-card"><header class="w-card__header"><h3 class="w-card__title">' . htmlspecialchars((string)__('数据卡片'), ENT_QUOTES, 'UTF-8') . '</h3>'
                . '<small>' . htmlspecialchars((string)__('用于组织内容'), ENT_QUOTES, 'UTF-8') . '</small></header>'
                . '<div class="w-card__body">' . htmlspecialchars((string)__('这里展示核心信息、摘要或操作入口。'), ENT_QUOTES, 'UTF-8') . '</div></article>'
                . '</div>',
            'dropdown' => '<div class="te-component-preview te-component-preview-dropdown">'
                . '<div class="w-menu-root" data-w-component="menu"><button type="button" class="w-button" data-tone="neutral" data-w-menu-trigger aria-haspopup="menu" aria-expanded="true">'
                . htmlspecialchars((string)__('选择操作'), ENT_QUOTES, 'UTF-8') . '</button>'
                . '<div class="w-menu" data-w-menu-panel role="menu">'
                . '<a class="w-menu__item" role="menuitem" href="#">' . htmlspecialchars((string)__('查看详情'), ENT_QUOTES, 'UTF-8') . '</a>'
                . '<a class="w-menu__item" role="menuitem" href="#">' . htmlspecialchars((string)__('复制视图'), ENT_QUOTES, 'UTF-8') . '</a>'
                . '</div></div></div>',
            'form-group' => '<div class="te-component-preview te-component-preview-form-group">'
                . '<label class="w-field__label">' . htmlspecialchars((string)__('字段名称'), ENT_QUOTES, 'UTF-8') . '</label>'
                . '<input class="w-input" type="text" value="' . htmlspecialchars((string)__('管理员'), ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars((string)__('请输入内容'), ENT_QUOTES, 'UTF-8') . '">'
                . '</div>',
            'loading' => '<div class="te-component-preview te-component-preview-loading">'
                . '<span class="w-spinner" aria-hidden="true"></span><span>' . htmlspecialchars((string)__('正在同步数据'), ENT_QUOTES, 'UTF-8') . '</span>'
                . '</div>',
            'message' => '<div class="te-component-preview te-component-preview-message">'
                . '<div class="w-alert" data-tone="success">' . $icons->render('check', 'sm') . '<div><strong>' . htmlspecialchars((string)__('操作完成'), ENT_QUOTES, 'UTF-8') . '</strong>'
                . '<span>' . htmlspecialchars((string)__('数据已保存，可以继续编辑。'), ENT_QUOTES, 'UTF-8') . '</span></div></div>'
                . '</div>',
            'modal' => '<div class="te-component-preview te-component-preview-modal">'
                . '<div class="w-card"><header class="w-card__header"><strong>' . htmlspecialchars((string)__('确认发布'), ENT_QUOTES, 'UTF-8') . '</strong></header>'
                . '<div class="w-card__body">' . htmlspecialchars((string)__('公开后同站点后台用户可见。'), ENT_QUOTES, 'UTF-8') . '</div>'
                . '<footer class="w-card__footer"><button type="button" class="w-button" data-tone="neutral" data-size="sm">' . htmlspecialchars((string)__('取消'), ENT_QUOTES, 'UTF-8') . '</button>'
                . '<button type="button" class="w-button" data-size="sm">' . htmlspecialchars((string)__('确认'), ENT_QUOTES, 'UTF-8') . '</button></footer></div>'
                . '</div>',
            'pagination' => '<div class="te-component-preview te-component-preview-pagination">'
                . '<nav aria-label="' . htmlspecialchars((string)__('分页'), ENT_QUOTES, 'UTF-8') . '"><ul class="w-pagination"><li class="w-pagination__item"><a class="w-pagination__link" href="#">' . htmlspecialchars((string)__('上一页'), ENT_QUOTES, 'UTF-8') . '</a></li><li class="w-pagination__item"><a class="w-pagination__link" href="#">1</a></li><li class="w-pagination__item"><a class="w-pagination__link" aria-current="page" href="#">2</a></li><li class="w-pagination__item"><a class="w-pagination__link" href="#">3</a></li><li class="w-pagination__item"><a class="w-pagination__link" href="#">' . htmlspecialchars((string)__('下一页'), ENT_QUOTES, 'UTF-8') . '</a></li></ul></nav>'
                . '</div>',
            'table' => '<div class="te-component-preview te-component-preview-table">'
                . '<table class="w-table"><thead><tr><th>' . htmlspecialchars((string)__('指标'), ENT_QUOTES, 'UTF-8') . '</th><th>' . htmlspecialchars((string)__('状态'), ENT_QUOTES, 'UTF-8') . '</th><th>' . htmlspecialchars((string)__('趋势'), ENT_QUOTES, 'UTF-8') . '</th></tr></thead>'
                . '<tbody><tr><td>' . htmlspecialchars((string)__('订单'), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)__('正常'), ENT_QUOTES, 'UTF-8') . '</td><td>+12%</td></tr>'
                . '<tr><td>' . htmlspecialchars((string)__('支付'), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)__('稳定'), ENT_QUOTES, 'UTF-8') . '</td><td>+4%</td></tr></tbody></table></div>',
            'tabs' => '<div class="te-component-preview te-component-preview-tabs">'
                . '<div class="w-tabs__list" role="tablist"><button type="button" class="w-tabs__tab" role="tab" aria-selected="true">' . htmlspecialchars((string)__('概览'), ENT_QUOTES, 'UTF-8') . '</button><button type="button" class="w-tabs__tab" role="tab" aria-selected="false">' . htmlspecialchars((string)__('设置'), ENT_QUOTES, 'UTF-8') . '</button></div>'
                . '<div class="w-tabs__panel" role="tabpanel">' . htmlspecialchars((string)__('这里展示核心信息、摘要或操作入口。'), ENT_QUOTES, 'UTF-8') . '</div>'
                . '</div>',
            default => '<div class="te-component-preview te-component-preview-generic"><strong>' . $name . '</strong><span>' . htmlspecialchars($widgetCode, ENT_QUOTES, 'UTF-8') . '</span></div>',
        };
    }

    private function normalizeWidgetPreviewCode(string $widgetCode): string
    {
        $normalized = strtolower(trim(str_replace('\\', '/', $widgetCode)));
        if ($normalized === '') {
            return '';
        }
        $parts = array_values(array_filter(explode('/', $normalized), static fn(string $part): bool => $part !== ''));
        return (string)end($parts);
    }

    private function isWidgetPreviewFallbackHtml(string $html): bool
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return true;
        }
        if (str_contains($trimmed, 'widget-preview-placeholder') || str_contains($trimmed, 'widget-preview-error')) {
            return true;
        }

        $text = trim(html_entity_decode(strip_tags($trimmed), ENT_QUOTES, 'UTF-8'));
        return $text === '';
    }

    private function resolveThemeConfigIdentifyForLayout(
        ThemeLayout $widgetLayout,
        string $widgetModule,
        string $widgetType,
        string $widgetCode,
        string $area,
        array $config = []
    ): string {
        $config = $config ?: $widgetLayout->getWidgetConfig();
        $instanceId = trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? ''));
        if ($instanceId !== '') {
            return ThemeData::getWidgetInstanceIdentify($instanceId, $area);
        }

        return $this->resolveThemeConfigIdentify($widgetModule, $widgetType, $widgetCode, $area);
    }

    private function ensureWidgetI18nInstance(ThemeLayout $widgetLayout): array
    {
        $config = $widgetLayout->getWidgetConfig();
        $key = ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY;
        $instanceId = trim((string)($config[$key] ?? ''));
        if ($instanceId !== '') {
            return $config;
        }

        $nodeUid = strtolower(trim((string)$widgetLayout->getNodeUid()));
        if (preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) {
            $nodeUid = substr(hash('sha256', 'legacy-layout:' . (string)$widgetLayout->getLayoutId()), 0, 32);
        }
        $config[$key] = 'wi_' . $nodeUid;

        return $config;
    }

    /**
     * Materialize legacy dotted field names (for example slides.0.title)
     * into the visual schema without writing translation or layout storage.
     */
    private function materializeWidgetConfigPaths(array $configData, array $base = []): array
    {
        foreach ($configData as $key => $value) {
            $key = (string)$key;
            if ($key === '' || !str_contains($key, '.')) {
                if ($key !== '') {
                    $base[$key] = $value;
                }
                continue;
            }
            $segments = explode('.', $key);
            if (in_array('', $segments, true)) {
                continue;
            }
            $cursor =& $base;
            $last = array_pop($segments);
            foreach ($segments as $segment) {
                $segment = preg_match('/^(?:0|[1-9][0-9]*)$/D', $segment) === 1
                    ? (int)$segment
                    : $segment;
                if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                    $cursor[$segment] = [];
                }
                $cursor =& $cursor[$segment];
            }
            $last = preg_match('/^(?:0|[1-9][0-9]*)$/D', (string)$last) === 1
                ? (int)$last
                : (string)$last;
            $cursor[$last] = $value;
            unset($cursor);
        }

        return $base;
    }

    private function preserveWidgetI18nInstance(array $config, array $existingConfig): array
    {
        $key = ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY;
        if (!isset($config[$key]) && isset($existingConfig[$key])) {
            $config[$key] = $existingConfig[$key];
        }

        return $config;
    }

    /**
     * 预览用 HTML 清理：移除脚本与内联事件，防止弹窗等副作用
     */
    private function sanitizeWidgetPreviewHtml(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);

        $wrappedHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        if (function_exists('mb_encode_numericentity')) {
            $wrappedHtml = mb_encode_numericentity(
                $wrappedHtml,
                [0x80, 0x10FFFF, 0, 0xFFFF],
                'UTF-8'
            );
        }
        $doc->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new \DOMXPath($doc);

        // 移除脚本、嵌入内容和可提交表单控件；保留安全 CSS，部件预览需要模板内样式才能接近真实展示。
        $blockedNodes = [];
        $blockedQuery = '//script | //link | //meta | //object | //embed | //iframe | //frame | //frameset | //base | //form | //input | //textarea | //select | //option';
        foreach ($xpath->query($blockedQuery) as $node) {
            $blockedNodes[] = $node;
        }
        foreach ($blockedNodes as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach ($xpath->query('//style') as $node) {
            if (!$this->isSafeWidgetPreviewCss((string)$node->textContent)) {
                $node->parentNode?->removeChild($node);
            }
        }

        // 移除所有指向 layout-preview 的链接和图片
        foreach ($xpath->query('//a[@href] | //img[@src]') as $element) {
            $href = $element->getAttribute('href');
            $src = $element->getAttribute('src');
            if (($href && strpos($href, 'layout-preview') !== false) ||
                ($src && strpos($src, 'layout-preview') !== false)) {
                $element->parentNode?->removeChild($element);
            }
        }

        // 移除内联事件、危险 URL 和危险 CSS。
        $uriAttributes = ['href', 'src', 'xlink:href', 'action', 'formaction', 'poster'];
        foreach ($xpath->query('//*') as $node) {
            if (!$node->hasAttributes()) {
                continue;
            }
            $toRemove = [];
            foreach ($node->attributes as $attr) {
                $name = strtolower($attr->name);
                $value = trim((string)$attr->value);
                $compactValue = strtolower((string)preg_replace('/[\x00-\x1F\x7F\s]+/', '', $value));

                if (str_starts_with($name, 'on') || $name === 'srcdoc' || $name === 'form') {
                    $toRemove[] = $attr->name;
                    continue;
                }

                if (in_array($name, $uriAttributes, true)) {
                    $hasScheme = preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) === 1;
                    $allowedScheme = preg_match('/^(https?:|mailto:|tel:|ftp:)/i', $value) === 1;
                    $allowedDataImage = $this->isAllowedWidgetPreviewDataImageUri($value);
                    $blockedScheme = str_starts_with($compactValue, 'javascript:')
                        || str_starts_with($compactValue, 'vbscript:')
                        || (str_starts_with($compactValue, 'data:') && !$allowedDataImage);

                    if ($blockedScheme || ($hasScheme && !$allowedScheme && !$allowedDataImage)) {
                        $toRemove[] = $attr->name;
                    }

                    continue;
                }

                if ($name === 'style' && !$this->isSafeWidgetPreviewCss($value)) {
                    $toRemove[] = $attr->name;
                }
            }
            foreach ($toRemove as $attrName) {
                $node->removeAttribute($attrName);
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $cleanHtml = '';
        if ($body) {
            foreach ($body->childNodes as $child) {
                $cleanHtml .= $doc->saveHTML($child);
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $cleanHtml;
    }

    private function isAllowedWidgetPreviewDataImageUri(string $value): bool
    {
        if (preg_match('/^data:image\/(?:png|gif|jpe?g|webp|bmp);base64,/i', $value) === 1) {
            return true;
        }

        // ThemeDemoCatalog demo placeholders use percent-encoded SVG data URIs.
        return preg_match('/^data:image\/svg\+xml(?:;charset=[^,]+)?,/i', $value) === 1;
    }

    private function isSafeWidgetPreviewCss(string $css): bool
    {
        $css = trim($css);
        if ($css === '') {
            return true;
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $css) === 1) {
            return false;
        }

        return preg_match('/@import\b|expression\s*\(|behavior\s*:|-moz-binding\s*:|url\s*\(\s*[\'"]?\s*(?:javascript|vbscript):|url\s*\(\s*[\'"]?\s*data:(?!image\/(?:png|gif|jpe?g|webp|bmp|svg\+xml);base64,)/i', $css) !== 1;
    }

    /**
     * 保存编译后的布局 (POST) - 将部件内容填充到插槽生成最终页面
     */
    public function postSaveCompiledLayout()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $data = $this->request->getParams();
            }
        } elseif (is_array($bodyParams) && !empty($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }
        $data = is_array($data) ? $data : [];

        $themeId = (int)($data['theme_id'] ?? 0);
        $layoutType = $data['layout_type'] ?? 'homepage';
        $layoutOption = $data['layout_option'] ?? 'default';
        $slotContents = $data['slot_contents'] ?? []; // 各插槽的部件内容
        $editorArea = PreviewContextService::AREA_FRONTEND;

        if (!$themeId) {
            return $this->dispatchThemeEditorResultAfter($this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]), 'save_compiled_layout');
        }

        try {
            $typedContext = $this->requireLayoutWriteContext($data, $themeId, (string)$layoutType);
            $themeId = $typedContext->themeId;
            $layoutType = $typedContext->layoutType;
            $layoutOption = $typedContext->layoutOption;
            $editorArea = $typedContext->area;

            // Session 只用于受控预览渲染，不作为发布目标或运行时选择。
            $session = ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
            $session->setData('preview_theme_id', $themeId);
            $session->setData('preview_theme_area', $editorArea);

            // 获取原始编译后的 HTML
            $templatePath = "Weline_Theme::theme/frontend/layouts/{$layoutType}/{$layoutOption}.phtml";
            
            $this->assign('editor_mode', false);
            $this->assign('theme_id', $themeId);
            $this->assign('layout_type', $layoutType);
            
            // 设置 meta 数据，包含插槽内容
            $meta = [
                'showHeader' => true,
                'showFooter' => true,
            ];
            
            // 将插槽内容注入 meta
            foreach ($slotContents as $slotId => $content) {
                $meta[$slotId] = $content;
            }
            
            $this->assign('meta', $meta);
            
            $this->welineTheme->load($themeId);
            $html = $this->renderUnifiedLayoutPreview($themeId, (string)$layoutType, (string)$layoutOption, $editorArea);

            return $this->dispatchThemeEditorResultAfter($this->fetchJson([
                'success' => true,
                'message' => __('Scope 草稿已编译预览，发布产物由 Release 生成'),
                'preview_html' => $html,
            ]), 'save_compiled_layout');
        } catch (\Exception $e) {
            return $this->dispatchThemeEditorResultAfter($this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]), 'save_compiled_layout');
        }
    }

    /**
     * 获取布局预览 (iframe) - 编译后的页面带编辑模式
     *
     * 禁用后端布局包装，直接输出前端布局 HTML（与 MediaManager iframe 一致）。
     * 若主题目录无 backend 则默认用 frontend 布局。
     *
     * 预览模式会读取草稿数据
     */
    public function getLayoutPreview()
    {
        $previewContextService = $this->getPreviewContextService();
        $layoutType = (string)$this->request->getParam('layout_type', 'homepage');
        if ($layoutType === '') {
            $layoutType = 'homepage';
        }
        $layoutOption = (string)$this->request->getParam('layout_option', 'default');
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }
        $editorArea = $this->resolveRequestedEditorArea(PreviewContextService::AREA_BACKEND);
        $context = $this->persistEditorContext([
            'frontend_theme_id' => (int)$this->request->getParam('frontend_theme_id', 0),
            'backend_theme_id' => (int)$this->request->getParam('backend_theme_id', 0),
            'editor_area' => $editorArea,
            'shell' => PreviewContextService::SHELL_THEME_EDITOR,
            'preview_mode' => (string)$this->request->getParam('preview_mode', PreviewContextService::DEFAULT_PREVIEW_MODE),
            'status' => (string)$this->request->getParam('status', PreviewContextService::DEFAULT_STATUS),
            'version_id' => (int)$this->request->getParam('version_id', 0) ?: null,
            'scope' => (string)$this->request->getParam('scope', PreviewContextService::DEFAULT_SCOPE),
            'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
            'target_value' => $layoutType,
        ]);
        $themeId = $previewContextService->getThemeIdForArea($editorArea, $context, true);
        if ($editorArea === PreviewContextService::AREA_BACKEND
            && !$this->resolveThemeLayoutExists($themeId, $editorArea, $layoutType, $layoutOption)
        ) {
            // backend 预览请求可能沿用 frontend 的 layout_type（如 homepage），
            // 优先回退到可视化 Dashboard 画布；仅当 Dashboard 布局也不存在时才使用通用默认壳层。
            $layoutType = $this->resolveThemeLayoutExists(
                $themeId,
                $editorArea,
                ThemeLayout::PAGE_TYPE_DASHBOARD,
                'default'
            ) ? ThemeLayout::PAGE_TYPE_DASHBOARD : ThemeLayout::PAGE_TYPE_DEFAULT;
            $layoutOption = 'default';
            $context['target_value'] = $layoutType;
        }
        $session = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
        $session->setData('preview_theme_id', $themeId);
        $session->setData('preview_theme_area', $editorArea);

        $this->request->setData('skip_view_file_cache', true);

        try {
            w_cache('view')->clear();
            ObjectManager::getInstance(SlotRendererService::class)->clearCache();
            \Weline\Theme\Helper\ThemeData::clearCache();
        } catch (\Throwable $e) {
        }

        $html = $this->renderUnifiedLayoutPreview(
            $themeId,
            $layoutType,
            $layoutOption,
            $editorArea,
            $context
        );
        if ($html === '') {
            return $this->dispatchThemeEditorResultAfter(
                $this->renderLayoutNotFoundError($layoutType, $layoutOption),
                'layout_preview'
            );
        }

        if ($editorArea === PreviewContextService::AREA_BACKEND && !$this->isDashboardPreviewLayout($layoutType)) {
            $html = $this->injectBackendStructuralSlots($html);
        }

        // ControllerFetchFileAfter 在控制器返回后读取 layoutType 并套用真实后台布局。
        // renderUnifiedLayoutPreview 会恢复临时状态，因此此处为最终响应重新声明一次。
        $this->layoutType = $layoutType;

        return $this->dispatchThemeEditorResultAfter(
            $this->injectEditorModeAssets($html),
            'layout_preview'
        );
    }

    /**
     * 注入编辑模式的 CSS 和 JS 到 HTML 中
     */
    private function injectEditorModeAssets(string $html): string
    {
        $injector = ObjectManager::getInstance(\Weline\Theme\Service\EditorModeAssetInjector::class);
        return $injector->inject(
            $html,
            (string)($this->getTemplate()->getData('preview_exit_url') ?? ''),
        );
    }

    private function injectBackendStructuralSlots(string $html): string
    {
        if ($html === ''
            || (stripos($html, 'data-w-area="backend"') === false
                && stripos($html, "data-w-area='backend'") === false)
        ) {
            return $html;
        }
        if ($this->isDashboardPreviewLayout((string)$this->request->getParam('layout_type', ''))
            || stripos($html, 'data-dashboard-layout=') !== false
            || stripos($html, 'data-dashboard-layout-slots') !== false
        ) {
            return $html;
        }

        $definitions = [
            [
                'pattern' => '/<header\b(?=[^>]*\bid=(["\'])page-topbar\1)(?![^>]*\bdata-wslot=)[^>]*>/i',
                'attrs' => ['backend-topbar', 'Backend Topbar', 'header'],
            ],
            [
                'pattern' => '/<div\b(?=[^>]*\bclass=(["\'])[^"\']*\btopnav\b[^"\']*\1)(?![^>]*\bdata-wslot=)[^>]*>/i',
                'attrs' => ['backend-topnav', 'Backend Topnav', 'header'],
            ],
            [
                'pattern' => '/<div\b(?=[^>]*\bclass=(["\'])[^"\']*\bvertical-menu\b[^"\']*\1)(?![^>]*\bdata-wslot=)[^>]*>/i',
                'attrs' => ['backend-sidebar', 'Backend Sidebar', 'sidebar'],
            ],
            [
                'pattern' => '/<main\b(?=[^>]*\bid=(["\'])main-content\1)(?![^>]*\bdata-wslot=)[^>]*>/i',
                'attrs' => ['backend-content', 'Backend Content', 'content'],
            ],
            [
                'pattern' => '/<footer\b(?=[^>]*\bclass=(["\'])[^"\']*\bfooter\b[^"\']*\1)(?![^>]*\bdata-wslot=)[^>]*>/i',
                'attrs' => ['backend-footer', 'Backend Footer', 'footer'],
            ],
            [
                'pattern' => '/<div\b(?=[^>]*\bclass=(["\'])[^"\']*\bright-bar\b[^"\']*\1)(?![^>]*\bdata-wslot=)[^>]*>/i',
                'attrs' => ['backend-right-sidebar', 'Backend Right Sidebar', 'right-sidebar'],
            ],
        ];

        foreach ($definitions as $definition) {
            $html = $this->injectSlotAttributesIntoFirstTag(
                $html,
                $definition['pattern'],
                $definition['attrs'][0],
                $definition['attrs'][1],
                $definition['attrs'][2]
            );
        }

        return $html;
    }

    private function isDashboardPreviewLayout(string $layoutType): bool
    {
        $layoutType = strtolower(trim($layoutType));
        $pageType = strtolower(trim((string)$this->request->getParam('page_type', '')));

        return $layoutType === ThemeLayout::PAGE_TYPE_DASHBOARD
            || $pageType === ThemeLayout::PAGE_TYPE_DASHBOARD;
    }

    private function renderDashboardEditorPreviewShell(string $content): string
    {
        if (trim($content) === '') {
            return '';
        }

        $themePreference = $this->resolveBackendPreviewThemePreference();
        $resolvedThemeMode = $themePreference === 'dark' ? 'dark' : 'light';
        $foundationUrl = $this->resolveUiAssetUrl('weline-foundation.css');
        $backendUrl = $this->resolveUiAssetUrl('weline-backend.css');
        $previewCssUrl = $this->resolveUiAssetUrl('pages/weline-theme-preview.css');
        $uiUrl = $this->resolveUiAssetUrl('weline-ui.js');
        $previewJsUrl = $this->resolveUiAssetUrl('pages/weline-theme-preview.js');
        $title = htmlspecialchars((string)__('后台仪表盘预览'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN" data-w-area="backend" data-theme-preference="{$themePreference}" data-theme="{$resolvedThemeMode}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link rel="stylesheet" href="{$foundationUrl}">
    <link rel="stylesheet" href="{$backendUrl}">
    <link rel="stylesheet" href="{$previewCssUrl}" data-w-editor-preview-asset="style">
    <script type="module" src="{$uiUrl}"></script>
    <script type="module" src="{$previewJsUrl}" data-w-editor-preview-asset="script"></script>
</head>
<body>
    <main class="w-dashboard-preview">{$content}</main>
</body>
</html>
HTML;
    }

    /** Resolve the same persisted three-state preference used by backend shells. */
    private function resolveBackendPreviewThemePreference(): string
    {
        try {
            $themeConfig = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Backend\Api\View\BackendThemeConfigInterface::class);
            $preference = $themeConfig->getOriginThemeConfig('theme-mode-switch');
            if (is_string($preference) && in_array($preference, ['system', 'light', 'dark'], true)) {
                return $preference;
            }
        } catch (\Throwable) {
            // The editor shell retains the safe system fallback during bootstrap failures.
        }

        return 'system';
    }

    private function injectSlotAttributesIntoFirstTag(
        string $html,
        string $pattern,
        string $slotId,
        string $slotName,
        string $position
    ): string {
        $attrs = sprintf(
            ' data-wslot="%s" data-wslot-name="%s" data-wslot-accept="*" data-wslot-multiple="true" data-wslot-position="%s"',
            htmlspecialchars($slotId, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($slotName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($position, ENT_QUOTES, 'UTF-8')
        );

        $updated = preg_replace_callback(
            $pattern,
            static function (array $matches) use ($attrs): string {
                return rtrim($matches[0], '>') . $attrs . '>';
            },
            $html,
            1
        );

        return is_string($updated) ? $updated : $html;
    }

    /**
     * 判断当前预览主题下是否存在指定布局文件（用于两阶段渲染时是否套 base）
     */
    private function getEditorJsonPayload(): array
    {
        // ThemeQueryProvider dispatches an inner HTTP request inside the outer
        // QueryBin request. For an inner GET, Request::getBodyParams() would
        // otherwise fall back to the outer QueryBin JSON body and mix transport
        // context (for example backend editor_area) into the typed editor input.
        $syntheticParams = $this->request->getData('__theme_editor_request_params');
        if (is_array($syntheticParams)) {
            return $syntheticParams;
        }

        $payload = [];
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams) && trim($bodyParams) !== '') {
            $decoded = json_decode($bodyParams, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        } elseif (is_array($bodyParams)) {
            $payload = $bodyParams;
        }

        $requestParams = $this->request->getParams();
        foreach ($requestParams as $key => $value) {
            if (!array_key_exists((string)$key, $payload)) {
                $payload[(string)$key] = $value;
            }
        }

        return $payload;
    }

    private function resolveEditorRequestTheme(string $editorArea, int $explicitThemeId = 0): WelineTheme
    {
        $context = $this->persistEditorContext([
            'frontend_theme_id' => (int)$this->request->getParam('frontend_theme_id', 0),
            'backend_theme_id' => (int)$this->request->getParam('backend_theme_id', 0),
            'editor_area' => $editorArea,
            'shell' => PreviewContextService::SHELL_THEME_EDITOR,
        ]);
        $themeId = $explicitThemeId > 0 ? $explicitThemeId : (int)$this->request->getParam('theme_id', 0);
        if (!$themeId) {
            $themeId = $this->getPreviewContextService()->getThemeIdForArea($editorArea, $context, true);
        }
        if (!$themeId) {
            throw new \RuntimeException((string)__('Missing theme ID'));
        }

        $theme = $this->loadThemeModel($themeId);
        if (!$theme?->getId()) {
            throw new \RuntimeException((string)__('Theme not found'));
        }

        return $theme;
    }

    private function getEditorLayoutOptionsByType(WelineTheme $theme, string $editorArea): array
    {
        /** @var ThemeResourceCatalog $catalog */
        $catalog = ObjectManager::getInstance(ThemeResourceCatalog::class);
        return $catalog->getLayouts($editorArea, $theme);
    }

    private function compactEditorLayoutOptions(array $layoutOptionsByType): array
    {
        $payload = [];
        foreach ($layoutOptionsByType as $layoutType => $options) {
            $layoutType = $this->normalizeLayoutType((string)$layoutType);
            foreach ((array)$options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $value = $this->normalizeLayoutOption((string)($option['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $meta = is_array($option['meta'] ?? null) ? $option['meta'] : [];
                $label = trim((string)($meta['name'] ?? $meta['title'] ?? ''));
                if ($label === '') {
                    $label = $this->humanizeLayoutOption($value);
                }
                $description = trim((string)($meta['description'] ?? ''));
                $payload[$layoutType][] = [
                    'value' => $value,
                    'label' => $label,
                    'description' => $description,
                    'file' => (string)($option['file'] ?? ''),
                ];
            }

            if (!empty($payload[$layoutType])) {
                usort($payload[$layoutType], static fn(array $left, array $right): int => strcmp(
                    (string)($left['label'] ?? $left['value'] ?? ''),
                    (string)($right['label'] ?? $right['value'] ?? '')
                ));
            }
        }

        return $payload;
    }

    private function mergeLayoutTypesWithEditorOptions(array $pageTypes, array $layoutOptionsByType, string $currentPageType): array
    {
        foreach ($layoutOptionsByType as $layoutType => $options) {
            $layoutType = $this->normalizeLayoutType((string)$layoutType);
            if ($layoutType === '' || isset($pageTypes[$layoutType])) {
                continue;
            }

            $pageTypes[$layoutType] = $this->humanizeLayoutType($layoutType);
        }

        if (!isset($pageTypes[$currentPageType])) {
            $pageTypes[$currentPageType] = $this->humanizeLayoutType($currentPageType);
        }

        return $pageTypes;
    }

    private function resolveSelectedLayoutOption(
        WelineTheme $theme,
        string $editorArea,
        string $layoutType,
        array $layoutOptionsByType,
        string $requestedLayoutOption = '',
        string $scope = PreviewContextService::DEFAULT_SCOPE
    ): string {
        $layoutType = $this->normalizeLayoutType($layoutType);
        $requestedLayoutOption = $this->normalizeLayoutOption($requestedLayoutOption);
        if ($requestedLayoutOption !== '' && $this->editorLayoutOptionExists($layoutOptionsByType, $layoutType, $requestedLayoutOption)) {
            return $requestedLayoutOption;
        }

        $savedLayoutOption = $this->getSavedLayoutOption($theme, $editorArea, $layoutType, $scope);
        if ($savedLayoutOption !== '' && $this->editorLayoutOptionExists($layoutOptionsByType, $layoutType, $savedLayoutOption)) {
            return $savedLayoutOption;
        }

        if ($this->editorLayoutOptionExists($layoutOptionsByType, $layoutType, 'default')) {
            return 'default';
        }

        $options = $layoutOptionsByType[$layoutType] ?? [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = $this->normalizeLayoutOption((string)($option['value'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $requestedLayoutOption !== '' ? $requestedLayoutOption : 'default';
    }

    private function editorLayoutOptionExists(array $layoutOptionsByType, string $layoutType, string $layoutOption): bool
    {
        $layoutType = $this->normalizeLayoutType($layoutType);
        $layoutOption = $this->normalizeLayoutOption($layoutOption);
        if ($layoutType === '' || $layoutOption === '') {
            return false;
        }

        foreach (($layoutOptionsByType[$layoutType] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            if ($this->normalizeLayoutOption((string)($option['value'] ?? '')) === $layoutOption) {
                return true;
            }
        }

        return false;
    }

    private function getSavedLayoutOption(WelineTheme $theme, string $editorArea, string $layoutType, string $scope): string
    {
        $value = $this->readEditorLayoutOption($theme, $editorArea, $layoutType, $scope);
        return is_scalar($value) ? $this->normalizeLayoutOption((string)$value) : '';
    }

    private function readEditorLayoutOption(
        WelineTheme $theme,
        string $editorArea,
        string $layoutType,
        string $scope
    ): ?string {
        $editorArea = $this->getPreviewContextService()->normalizeArea($editorArea, PreviewContextService::AREA_FRONTEND);
        $layoutType = $this->normalizeLayoutType($layoutType);
        $effectiveScope = $this->resolveEditorEffectiveScope($theme, $editorArea, $scope);

        return $this->metaConfigRepository()->resolve(new MetaConfigIdentity(
            namespace: 'theme.' . $editorArea,
            configKey: 'layouts.' . $layoutType . '.value',
            scope: $effectiveScope,
            locale: Cookie::getLang() ?? 'zh_Hans_CN',
            identifyId: (string)$theme->getId(),
        ))?->value;
    }

    private function metaConfigRepository(): MetaConfigRepositoryInterface
    {
        $repository = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(MetaConfigRepositoryInterface::class);
        if (!$repository instanceof MetaConfigRepositoryInterface) {
            throw new \RuntimeException('Weline_Meta config repository provider is unavailable.');
        }

        return $repository;
    }

    private function resolveEditorEffectiveScope(WelineTheme $theme, string $editorArea, string $scope): string
    {
        $scope = trim($scope) !== '' ? trim($scope) : PreviewContextService::DEFAULT_SCOPE;

        try {
            /** @var PreviewThemeScopeService $previewThemeScopeService */
            $previewThemeScopeService = ObjectManager::getInstance(PreviewThemeScopeService::class);
            return $previewThemeScopeService->resolveEffectiveScope((int)$theme->getId(), $editorArea, $scope);
        } catch (\Throwable) {
            return $scope;
        }
    }

    private function normalizeLayoutType(string $layoutType): string
    {
        $layoutType = trim(str_replace('\\', '/', $layoutType), '/ ');
        return $layoutType !== '' ? $layoutType : ThemeLayout::PAGE_TYPE_HOME;
    }

    private function normalizeLayoutOption(string $layoutOption): string
    {
        return trim(str_replace('\\', '/', $layoutOption), '/ ');
    }

    private function humanizeLayoutType(string $layoutType): string
    {
        return ucwords(str_replace(['_', '-', '/'], ' ', $layoutType));
    }

    private function humanizeLayoutOption(string $layoutOption): string
    {
        if ($layoutOption === 'default') {
            return (string)__('Default');
        }

        return ucwords(str_replace(['_', '-', '/'], ' ', $layoutOption));
    }

    private function resolveLayoutConfigContext(): array
    {
        $previewContextService = $this->getPreviewContextService();
        $editorArea = $this->resolveRequestedEditorArea(PreviewContextService::AREA_FRONTEND);
        $layoutType = (string)$this->request->getParam('layout_type', $this->request->getParam('page_type', 'homepage'));
        $layoutOption = (string)$this->request->getParam('layout_option', 'default');
        $scope = (string)$this->request->getParam('scope', PreviewContextService::DEFAULT_SCOPE);
        $localeParam = $this->request->getParam('locale', null);
        $locale = $localeParam === null || $localeParam === '' ? null : (string)$localeParam;
        $typedPayload = $this->getEditorJsonPayload();
        $typedContext = null;
        if (array_key_exists('editor_context', $typedPayload)) {
            /** @var ThemeEditorContextFactory $factory */
            $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
            $typedContext = $factory->fromInput(
                $typedPayload,
                $locale === null ? ThemeEditorContext::RESOURCE_META : ThemeEditorContext::RESOURCE_I18N,
            );
            $editorArea = $typedContext->area;
            $layoutType = $typedContext->layoutType;
            $layoutOption = $typedContext->layoutOption;
            $scope = $this->legacyScopeForEditorContext($typedContext);
            $locale = $typedContext->locale === 'default' ? null : $typedContext->locale;
        }

        $context = $this->persistEditorContext([
            'frontend_theme_id' => (int)$this->request->getParam('frontend_theme_id', 0),
            'backend_theme_id' => (int)$this->request->getParam('backend_theme_id', 0),
            'editor_area' => $editorArea,
            'scope' => $scope,
            'shell' => PreviewContextService::SHELL_THEME_EDITOR,
        ]);
        $themeId = $typedContext instanceof ThemeEditorContext
            ? $typedContext->themeId
            : (int)$this->request->getParam('theme_id', 0);
        if (!$themeId) {
            $themeId = $previewContextService->getThemeIdForArea($editorArea, $context, true);
        }
        if (!$themeId) {
            throw new \RuntimeException((string)__('Missing theme ID'));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        if (!$theme->getId()) {
            throw new \RuntimeException((string)__('Theme not found'));
        }

        return [$theme, $editorArea, $layoutType, $layoutOption, $scope, $locale];
    }

    private function buildLayoutConfigIdentify(string $layoutType, string $layoutOption): string
    {
        $layoutType = trim($layoutType) ?: 'homepage';
        $layoutOption = trim($layoutOption) ?: 'default';
        return 'layouts.' . $layoutType . '.' . str_replace(['/', '\\'], '.', $layoutOption);
    }

    private function buildTargetLayoutConfigIdentify(string $editorArea, string $layoutType, string $layoutOption): string
    {
        $payload = $this->getEditorJsonPayload();
        if (array_key_exists('editor_context', $payload)) {
            /** @var ThemeEditorContextFactory $factory */
            $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
            $context = $factory->fromInput($payload, ThemeEditorContext::RESOURCE_META);
            /** @var ThemeMetaIdentityService $metaIdentityService */
            $metaIdentityService = ObjectManager::getInstance(ThemeMetaIdentityService::class);

            return $metaIdentityService->targetIdentify(
                $context->area,
                $context->targetType,
                $context->targetId,
                $context->layoutType,
                $context->layoutOption,
            );
        }
        $candidates = [
            ['target_type' => $payload['theme_layout_target_type'] ?? null, 'target_id' => $payload['theme_layout_target_id'] ?? null],
            ['target_type' => $payload['theme_layout_source_target_type'] ?? null, 'target_id' => $payload['theme_layout_source_target_id'] ?? null],
            ['target_type' => $payload['target_type'] ?? null, 'target_id' => $payload['target_id'] ?? null],
            ['target_type' => $payload['layout_lock_target_type'] ?? null, 'target_id' => $payload['layout_lock_target_id'] ?? null],
            ['target_type' => $payload['virtual_target_type'] ?? null, 'target_id' => $payload['virtual_target_id'] ?? null],
        ];
        /** @var ThemeTargetIdentityResolver $identityResolver */
        $identityResolver = ObjectManager::getInstance(ThemeTargetIdentityResolver::class);
        [$targetType, $targetId] = $identityResolver->resolveFirst($candidates);
        if ($targetType === '') {
            foreach ($candidates as $candidate) {
                $providedType = strtolower(trim((string)($candidate['target_type'] ?? '')));
                if ($providedType === '') {
                    continue;
                }
                if ($providedType === ThemeVirtualLayout::TARGET_GLOBAL) {
                    return '';
                }
                throw new \InvalidArgumentException((string)__('主题目标类型或 ID 无效。'));
            }
            return '';
        }

        /** @var ThemeMetaIdentityService $metaIdentityService */
        $metaIdentityService = ObjectManager::getInstance(ThemeMetaIdentityService::class);
        return $metaIdentityService->targetIdentify($editorArea, $targetType, $targetId, $layoutType, $layoutOption);
    }

    private function loadLayoutParamDefinitions(
        WelineTheme $theme,
        string $editorArea,
        string $layoutType,
        string $layoutOption,
        string $identify
    ): array {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($editorArea);

        $definitions = ThemeData::getParamDefinitions($identify);
        if (!empty($definitions)) {
            return $definitions;
        }

        $filePath = $this->resolveThemeLayoutFilePath((int)$theme->getId(), $editorArea, $layoutType, $layoutOption);
        if ($filePath === '') {
            return [];
        }

        $parsed = ComponentMetaParser::parse($filePath);
        if (empty($parsed['params']) || !is_array($parsed['params'])) {
            return [];
        }

        /** @var ParamDefinitionNormalizerInterface $normalizer */
        $normalizer = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(ParamDefinitionNormalizerInterface::class);
        if (!$normalizer instanceof ParamDefinitionNormalizerInterface) {
            throw new \RuntimeException('Weline_Meta param normalizer provider is unavailable.');
        }
        return $normalizer->normalizeParsedParamList($parsed['params']);
    }

    private function getLayoutConfigValues(
        WelineTheme $theme,
        string $identify,
        string $scope = 'default',
        ?string $locale = null,
        array $definitions = [],
        string $targetIdentify = ''
    ): array {
        ThemeData::setCurrentTheme($theme);
        $editorArea = $this->resolveRequestedEditorArea(PreviewContextService::AREA_FRONTEND);
        ThemeData::setCurrentArea($editorArea);

        if (empty($definitions)) {
            $definitions = ThemeData::getParamDefinitions($identify);
        }

        $stored = ThemeData::getFileParams($identify, $scope, $locale);
        $values = [];
        foreach ($definitions as $name => $definition) {
            $default = $definition['default'] ?? null;
            if (array_key_exists($name, $stored)) {
                $values[$name] = $stored[$name];
                continue;
            }

            $isTranslatable = !empty($definition['i18n']) || !empty($definition['translate']) || !empty($definition['translatable']);
            if ($isTranslatable && $locale !== null && $locale !== '') {
                $values[$name] = ThemeData::getParamTranslation($identify, (string)$name, $scope, $locale, is_scalar($default) ? (string)$default : null);
                continue;
            }

            $values[$name] = ThemeData::get($identify . '.param.' . $name . '.value', $default);
        }

        if ($targetIdentify !== '') {
            /** @var ThemeMetaIdentityService $metaIdentityService */
            $metaIdentityService = ObjectManager::getInstance(ThemeMetaIdentityService::class);
            $values = $metaIdentityService->mergeTargetOverrides($theme, $editorArea, $values, $targetIdentify, $definitions, $scope, $locale);
        }

        return $values;
    }

    private function getInstalledLocalesPayload(): array
    {
        try {
            return $this->localeCatalog()->installed(
                \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN',
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function localeCatalog(): LocaleCatalogInterface
    {
        /** @var LocaleCatalogInterface $catalog */
        $catalog = ObjectManager::getInstance(LocaleCatalogInterface::class);
        return $catalog;
    }

    private function getThemeAiRequestPayload(): array
    {
        $keys = [
            'source_text',
            'source_locale',
            'target_locales',
            'field_key',
            'layout_id',
            'layout_type',
            'layout_option',
            'context',
            'stream',
        ];
        $payload = [];
        foreach ($keys as $key) {
            $payload[$key] = $this->request->getParam($key, null);
        }

        $rawBody = file_get_contents('php://input');
        if (is_string($rawBody) && trim($rawBody) !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (!array_key_exists((string)$key, $payload) || $payload[(string)$key] === null) {
                        $payload[(string)$key] = $value;
                    }
                }
            }
        }

        return $payload;
    }

    private function normalizeThemeAiLocaleList(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/\s*,\s*/', $trimmed) ?: [];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $locales = [];
        foreach ($value as $locale) {
            if (!is_scalar($locale)) {
                continue;
            }
            $locale = trim((string)$locale);
            if ($locale !== '') {
                $locales[$locale] = $locale;
            }
        }

        return array_values($locales);
    }

    private function buildThemeConfigTranslationPrompt(
        string $sourceText,
        string $sourceLocale,
        array $targetLocales,
        string $fieldKey,
        string $layoutType,
        string $layoutOption,
        string $context
    ): string {
        $targetJson = json_encode(array_values($targetLocales), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $fieldLine = $fieldKey !== '' ? "字段路径：{$fieldKey}\n" : '';
        $layoutLine = $layoutType !== '' ? "布局类型：{$layoutType}\n" : '';
        $optionLine = $layoutOption !== '' ? "布局选项：{$layoutOption}\n" : '';
        $contextLine = $context !== '' ? "业务上下文：{$context}\n" : '';

        return "你是 Weline 主题可视化编辑器的配置翻译助手。\n"
            . "请把源文案翻译成目标语言，并且只返回一个 JSON 对象。\n"
            . "源语言：{$sourceLocale}\n"
            . "目标语言：{$targetJson}\n"
            . $fieldLine
            . $layoutLine
            . $optionLine
            . $contextLine
            . "规则：\n"
            . "1. JSON key 必须严格等于每个目标语言 code。\n"
            . "2. JSON value 必须是翻译后的字符串。\n"
            . "3. 保留 HTML 标签、属性、URL、id、class、数字、变量占位符、模板表达式和配置 token。\n"
            . "4. 只翻译用户可读文本，不输出解释、Markdown 或代码块。\n"
            . "源文案：\n<<<SOURCE\n{$sourceText}\nSOURCE\n";
    }

    private function parseThemeConfigTranslationResponse(string $response, array $targetLocales): array
    {
        $json = $this->extractThemeConfigTranslationJson($response);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException((string)__('AI 翻译未返回有效 JSON。'));
        }

        $translations = [];
        foreach ($targetLocales as $locale) {
            if (!array_key_exists($locale, $decoded) || !is_scalar($decoded[$locale])) {
                continue;
            }
            $translations[$locale] = (string)$decoded[$locale];
        }

        if ($translations === []) {
            throw new \RuntimeException((string)__('AI 翻译未返回目标语言结果。'));
        }

        return $translations;
    }

    private function extractThemeConfigTranslationJson(string $response): string
    {
        $text = trim($response);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }

    private function resolveThemeLayoutFilePath(int $themeId, string $editorArea, string $layoutType, string $layoutOption = 'default'): string
    {
        if ($themeId <= 0) {
            return '';
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        if (!$theme->getId()) {
            return '';
        }

        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
        if (!isset($modules['Weline_Theme'])) {
            return '';
        }
        $ds = DS;
        $modulePath = rtrim($modules['Weline_Theme']['base_path'], $ds) . $ds . 'view' . $ds . 'theme' . $ds
            . $editorArea . $ds . 'layouts' . $ds . $layoutType . $ds . $layoutOption . '.phtml';
        /** @var ThemePathResolver $resolver */
        $resolver = ObjectManager::getInstance(ThemePathResolver::class);
        $resolved = $resolver->resolveThemeFile($modulePath, $theme);

        return $resolved !== '' && is_file($resolved) ? $resolved : '';
    }

    public function getVersionsPayload(): array
    {
        $data = $this->getEditorJsonPayload();
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id'));
        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));
        $limit = (int)$this->request->getParam('limit', 20);

        if (!$themeId) {
            return [
                'success' => false,
                'message' => __('Missing theme ID'),
            ];
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $themeId = $context->themeId;
            $pageType = $context->layoutType;
            $identity = $this->layoutIdentityFromEditorContext($context);
            $this->versionService->initializeVersionIfNeeded($themeId, $pageType, null, $identity);
            $versions = $this->versionService->getVersions($themeId, $pageType, $limit, $identity);
            $currentVersion = $this->versionService->getCurrentVersion($themeId, $pageType, $identity);
            $publishedVersion = $this->versionService->getPublishedVersion($themeId, $pageType, $identity);

            return [
                'success' => true,
                'data' => [
                    'versions' => $versions,
                    'current_version_id' => $currentVersion?->getVersionId(),
                    'published_version_id' => $publishedVersion?->getVersionId(),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function saveVersionPayload(): array
    {
        $data = $this->getVersionRequestData();
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));
        $versionName = $data['version_name'] ?? $this->request->getParam('version_name');
        $description = $data['description'] ?? $this->request->getParam('description');

        if (!$themeId) {
            return [
                'success' => false,
                'message' => __('Missing theme ID'),
            ];
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $version = $this->saveScopedLayoutVersion(
                $context,
                $versionName !== null ? (string)$versionName : null,
                $description !== null ? (string)$description : null,
            );
            $this->clearVersionPreviewCaches($themeId);

            return [
                'success' => true,
                'message' => __('Version saved: %{name}', ['name' => $version->getDisplayName()]),
                'data' => $version->toArray(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function switchVersionPayload(): array
    {
        $data = $this->getVersionRequestData();
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));
        $versionId = (int)($data['version_id'] ?? $this->request->getParam('version_id', 0));

        if (!$themeId || !$versionId) {
            return [
                'success' => false,
                'message' => __('Missing required parameters'),
            ];
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $identity = $this->layoutIdentityFromEditorContext($context);
            $targetVersion = $this->versionService->getVersion($themeId, $pageType, $versionId, $identity);
            if (!$targetVersion instanceof ThemeLayoutVersion) {
                throw new \RuntimeException('theme_layout_version_not_found');
            }
            $targetSnapshot = $this->normalizeLegacyLayoutSnapshot($context, $targetVersion->getSnapshotData());
            $result = $this->versionService->switchToVersion(
                $themeId,
                $pageType,
                $versionId,
                $identity,
                $targetSnapshot,
            );
            if (!$result) {
                return [
                    'success' => false,
                    'message' => __('Switch version failed'),
                ];
            }

            $this->clearVersionPreviewCaches($themeId);
            $currentVersion = $this->versionService->getCurrentVersion($themeId, $pageType, $identity);
            if (!$currentVersion instanceof ThemeLayoutVersion) {
                throw new \RuntimeException('theme_layout_current_version_missing');
            }
            $scopedDraft = $this->replaceScopedLayoutDraftFromSnapshot(
                $context,
                $targetSnapshot,
                'Restore selected legacy layout version',
            );

            return [
                'success' => true,
                'message' => __('Restored selected version'),
                'data' => [
                    'current_version_id' => $currentVersion?->getVersionId(),
                    'version' => $currentVersion?->toArray(),
                    'scoped_workspace' => $scopedDraft,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function restoreOriginalPayload(): array
    {
        $data = $this->getVersionRequestData();
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));

        if (!$themeId) {
            return [
                'success' => false,
                'message' => __('Missing theme ID'),
            ];
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $identity = $this->layoutIdentityFromEditorContext($context);
            $result = $this->versionService->restoreOriginal(
                $themeId,
                $pageType,
                null,
                $identity,
                $this->scopedLayoutSnapshot($context),
            );
            $this->clearVersionPreviewCaches($themeId);

            $backupVersion = $result['backup_version'];
            $newVersion = $result['new_version'];
            $scopedDraft = $this->replaceScopedLayoutDraftFromSnapshot(
                $context,
                $newVersion->getSnapshotData(),
                'Restore original layout snapshot',
            );

            return [
                'success' => true,
                'message' => __('Restored original layout'),
                'data' => [
                    'backup_version' => $backupVersion?->toArray(),
                    'new_version' => $newVersion->toArray(),
                    'scoped_workspace' => $scopedDraft,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reset current editing draft materialization for selected resources.
     * Does not delete version history or call restoreOriginal().
     */
    public function postResetDraftResources()
    {
        return $this->fetchJson($this->resetDraftResourcesPayload());
    }

    public function resetDraftResourcesPayload(): array
    {
        $data = $this->getVersionRequestData();
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));
        $resources = $data['resources'] ?? [];
        if (!\is_array($resources)) {
            $resources = [];
        }
        $layoutScope = (string)($data['layout_scope'] ?? ThemeEditorDraftResetService::LAYOUT_SCOPE_CURRENT);

        if (!$themeId) {
            return [
                'success' => false,
                'message' => __('Missing theme ID'),
            ];
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            /** @var ThemeEditorDraftResetService $resetService */
            $resetService = ObjectManager::getInstance(ThemeEditorDraftResetService::class);
            $result = $resetService->reset($context, $resources, $layoutScope);
            $this->clearVersionPreviewCaches($themeId);

            return [
                'success' => true,
                'message' => __('Current draft resources reset'),
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function hasEmptyCurrentRestoreVersion(int $themeId, string $pageType, array $identity = []): bool
    {
        $currentVersion = $this->versionService->getCurrentVersion($themeId, $pageType, $identity);
        if (!$currentVersion?->isRestoreType()) {
            return false;
        }

        return !$this->layoutSnapshotHasWidgets($currentVersion->getSnapshotData());
    }

    private function layoutSnapshotHasWidgets(array $layout): bool
    {
        foreach ($layout as $areaData) {
            if (is_array($areaData) && !empty($areaData['widgets'])) {
                return true;
            }
        }

        return false;
    }

    public function publishVersionPayload(): array
    {
        $data = $this->getVersionRequestData();
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));
        $versionId = isset($data['version_id']) ? (int)$data['version_id'] : null;

        if (!$themeId) {
            return [
                'success' => false,
                'message' => __('Missing theme ID'),
            ];
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $identity = $this->layoutIdentityFromEditorContext($context);
            $scopedReleasePublished = $this->hasScopedReleasePublishedClaim($data);
            if (!$scopedReleasePublished) {
                $this->publishPendingScopedResources($context, 'theme_editor_compat_version_publish');
            }
            $this->assertCurrentScopedLayoutPublished($context);
            $result = $this->versionService->markVersionPublished($themeId, $pageType, $versionId, $identity);
            if (!$result) {
                return [
                    'success' => false,
                    'message' => __('Publish failed'),
                ];
            }

            $this->publishEditorPreviewScope($themeId, (string)($identity['scope'] ?? PreviewContextService::DEFAULT_SCOPE));
            $this->clearVersionPreviewCaches($themeId, true);
            $this->cacheGenerator->clearCache($themeId);
            $this->cacheGenerator->generate($themeId);
            $this->flushFullPageCache();

            return [
                'success' => true,
                'message' => __('Version published'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function deleteVersionPayload(): array
    {
        $data = $this->getVersionRequestData();
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));
        $versionId = (int)($data['version_id'] ?? $this->request->getParam('version_id', 0));
        if (!$versionId || !$themeId) {
            return [
                'success' => false,
                'message' => __('Missing version ID'),
            ];
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $identity = $this->layoutIdentityFromEditorContext($context);
            $result = $this->versionService->deleteVersion(
                $versionId,
                $themeId > 0 ? $themeId : null,
                $themeId > 0 ? $pageType : null,
                $identity
            );

            return [
                'success' => $result,
                'message' => $result ? __('Version deleted') : __('Cannot delete current or published version'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function renameVersionPayload(): array
    {
        $data = $this->getVersionRequestData();
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));
        $versionId = (int)($data['version_id'] ?? $this->request->getParam('version_id', 0));
        $newName = \trim((string)($data['version_name'] ?? $this->request->getParam('version_name', '')));

        if (!$versionId || !$themeId || $newName === '') {
            return [
                'success' => false,
                'message' => __('Missing required parameters'),
            ];
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $identity = $this->layoutIdentityFromEditorContext($context);
            $result = $this->versionService->renameVersion(
                $versionId,
                $newName,
                $themeId > 0 ? $themeId : null,
                $themeId > 0 ? $pageType : null,
                $identity
            );

            return [
                'success' => $result,
                'message' => $result ? __('Version renamed') : __('Rename failed'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function getVersionRequestData(): array
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($bodyParams) && $bodyParams !== []) {
            return $bodyParams;
        }

        $params = $this->request->getParams();
        return is_array($params) ? $params : [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string}
     */
    private function resolveVersionLayoutIdentity(array $data = []): array
    {
        $typedInput = $data;
        if (!array_key_exists('editor_context', $typedInput)) {
            $requestContext = $this->request->getParam('editor_context', null);
            if ($requestContext !== null && $requestContext !== '') {
                $typedInput['editor_context'] = $requestContext;
            }
        }
        if (array_key_exists('editor_context', $typedInput)) {
            /** @var ThemeEditorContextFactory $factory */
            $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
            $context = $factory->fromInput($typedInput, ThemeEditorContext::RESOURCE_LAYOUT);
            $this->assertRawLayoutContextMatches($typedInput, $context);

            return $this->layoutIdentityFromEditorContext($context);
        }

        $layoutOption = trim((string)($data['layout_option'] ?? $this->request->getParam('layout_option', 'default')));
        $scope = trim((string)($data['scope'] ?? $this->request->getParam('scope', PreviewContextService::DEFAULT_SCOPE)));
        $localeCode = trim((string)(
            $data['locale_code']
            ?? $data['locale']
            ?? $this->request->getParam('locale_code', $this->request->getParam('locale', ''))
        ));
        $targetType = trim((string)(
            $data['theme_layout_target_type']
            ?? $data['theme_layout_source_target_type']
            ?? $data['virtual_target_type']
            ?? $data['layout_lock_target_type']
            ?? $this->request->getParam(
                'theme_layout_target_type',
                $this->request->getParam(
                    'theme_layout_source_target_type',
                    $this->request->getParam(
                        'virtual_target_type',
                        $this->request->getParam(
                            'layout_lock_target_type',
                            ThemeVirtualLayout::TARGET_GLOBAL
                        )
                    )
                )
            )
        ));
        $targetId = (int)(
            $data['theme_layout_target_id']
            ?? $data['theme_layout_source_target_id']
            ?? $data['virtual_target_id']
            ?? $this->request->getParam(
                'theme_layout_target_id',
                $this->request->getParam(
                    'theme_layout_source_target_id',
                    $this->request->getParam(
                        'virtual_target_id',
                        $this->request->getParam('layout_lock_target_id', 0)
                    )
                )
            )
        );

        return [
            'layout_option' => $layoutOption !== '' ? $layoutOption : 'default',
            'scope' => $scope !== '' ? $scope : PreviewContextService::DEFAULT_SCOPE,
            'target_type' => $targetType !== '' ? $this->normalizeVirtualLayoutTargetType($targetType) : ThemeVirtualLayout::TARGET_GLOBAL,
            'target_id' => max(0, $targetId),
            'locale_code' => $localeCode,
        ];
    }

    /**
     * @param array{layout_option:string,scope:string,target_type:string,target_id:int} $identity
     * @return array<string,mixed>
     */
    private function buildThemeLayoutRuntimeParams(array $identity): array
    {
        $scope = trim((string)($identity['scope'] ?? PreviewContextService::DEFAULT_SCOPE));
        $targetType = trim((string)($identity['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL));
        $targetId = max(0, (int)($identity['target_id'] ?? 0));
        $params = [
            'scope' => $scope !== '' ? $scope : PreviewContextService::DEFAULT_SCOPE,
            'locale' => trim((string)($identity['locale_code'] ?? '')),
            'locale_code' => trim((string)($identity['locale_code'] ?? '')),
        ];

        if (!$this->hasConcreteThemeLayoutTarget($targetType, $targetId)) {
            return $params;
        }

        return $params + [
            'theme_layout_target_type' => $targetType,
            'theme_layout_target_id' => $targetId,
            'theme_layout_source_target_type' => $targetType,
            'theme_layout_source_target_id' => $targetId,
        ];
    }

    /**
     * @param array{layout_option:string,scope:string,target_type:string,target_id:int} $identity
     * @return array<string,mixed>
     */
    private function buildThemeLayoutEditorLockParams(array $identity): array
    {
        $params = $this->buildThemeLayoutRuntimeParams($identity);
        $targetType = trim((string)($identity['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL));
        $targetId = max(0, (int)($identity['target_id'] ?? 0));
        if (!$this->hasConcreteThemeLayoutTarget($targetType, $targetId)) {
            return $params;
        }

        return $params + [
            'lock_layout' => 1,
            'lock_layout_context' => 1,
            'layout_lock_target_type' => $targetType,
            'target_id' => $targetId,
            'virtual_target_type' => $targetType,
            'virtual_target_id' => $targetId,
            'lock_source' => 'theme_preview',
        ];
    }

    private function clearVersionPreviewCaches(int $themeId = 0, bool $regenerateThemeCache = false): void
    {
        try {
            ObjectManager::getInstance(SlotRendererService::class)->clearCache();
        } catch (\Throwable) {
        }

        try {
            ControllerFetchFileBefore::clearRuntimeCache();
        } catch (\Throwable) {
        }

        ThemeData::clearCache();

        if ($themeId > 0) {
            try {
                $this->cacheGenerator->clearCache($themeId);
                if ($regenerateThemeCache) {
                    $this->cacheGenerator->generate($themeId);
                }
            } catch (\Throwable) {
            }
        }
    }

    private function hasConcreteThemeLayoutTarget(string $targetType, int $targetId): bool
    {
        $targetType = strtolower(trim($targetType));
        if ($targetType === '' || $targetType === ThemeVirtualLayout::TARGET_GLOBAL) {
            return false;
        }

        try {
            /** @var ThemeTargetTypeRegistry $targetTypeRegistry */
            $targetTypeRegistry = ObjectManager::getInstance(ThemeTargetTypeRegistry::class);
            $provider = $targetTypeRegistry->get($targetType);
            return $provider !== null && $provider->validate($targetId);
        } catch (\Throwable) {
            return $targetId > 0;
        }
    }

    /**
     * Publish editor-only theme config buckets alongside layout publication.
     *
     * Layout rows live in theme_layout, while translatable widget config is stored
     * in preview-scoped meta/i18n buckets. Both need to be promoted together.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function publishEditorPreviewScope(
        int $themeId,
        string $baseScope = PreviewContextService::DEFAULT_SCOPE,
        array $context = []
    ): array {
        $baseScope = trim($baseScope) !== '' ? trim($baseScope) : PreviewContextService::DEFAULT_SCOPE;
        $editorArea = $this->resolvePreviewScopeEditorArea($context);

        try {
            /** @var PreviewThemeScopeService $previewThemeScopeService */
            $previewThemeScopeService = ObjectManager::getInstance(PreviewThemeScopeService::class);
            $results = [];
            foreach ([
                $previewThemeScopeService->publishPreviewScope($themeId, $editorArea, $baseScope),
                $previewThemeScopeService->publishSessionPreviewScope($themeId, $editorArea, $baseScope),
            ] as $result) {
                $previewScope = (string)($result['preview_scope'] ?? '');
                if ($previewScope !== '') {
                    $results[$previewScope] = $result;
                }
            }

            return $this->mergePreviewScopePublishResults($results);
        } catch (\Throwable $throwable) {
            w_log_error('Theme editor preview scope publish failed: ' . $throwable->getMessage(), [], 'theme');
            return [
                'published_configs' => 0,
                'published_translations' => 0,
                'discarded_preview_configs' => 0,
                'discarded_preview_translations' => 0,
                'preview_scopes' => [],
                'error' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function resolvePreviewScopeEditorArea(array $context = []): string
    {
        $area = $this->request->getParam(
            'editor_area',
            $this->request->getParam(
                'preview_area',
                $context['editor_area'] ?? PreviewContextService::AREA_FRONTEND
            )
        );

        return $this->getPreviewContextService()->normalizeArea((string)$area, PreviewContextService::AREA_FRONTEND);
    }

    /**
     * @param array<string,array<string,mixed>> $results
     * @return array<string,mixed>
     */
    private function mergePreviewScopePublishResults(array $results): array
    {
        $merged = [
            'published_configs' => 0,
            'published_translations' => 0,
            'discarded_preview_configs' => 0,
            'discarded_preview_translations' => 0,
            'preview_scopes' => [],
        ];

        foreach ($results as $previewScope => $result) {
            $merged['preview_scopes'][] = $previewScope;
            foreach ([
                'published_configs',
                'published_translations',
                'discarded_preview_configs',
                'discarded_preview_translations',
            ] as $key) {
                $merged[$key] += (int)($result[$key] ?? 0);
            }
        }

        return $merged;
    }

    /**
     * Render preview layout via shared fetch lifecycle.
     */
    private function renderUnifiedLayoutPreview(
        int $themeId,
        string $layoutType,
        string $layoutOption,
        string $editorArea,
        array $context = []
    ): string {
        $previousLayoutType = $this->layoutType;

        try {
            $this->layoutType = $layoutType;
            $this->request->setGet('layout_type', $layoutType);
            $this->request->setGet('layout_option', $layoutOption);
            $this->request->setGet('editor_area', $editorArea);
            $this->request->setGet('theme_id', (string)$themeId);
            $this->applyThemeLayoutRuntimeContextToRequest($context);
            if ((string)$this->request->getParam('status', '') === '') {
                $this->request->setGet('status', ThemeLayout::STATUS_DRAFT);
            }
            $versionId = (int)($context['version_id'] ?? $this->request->getParam('version_id', 0));
            if ($versionId > 0) {
                $this->request->setGet('version_id', (string)$versionId);
            }
            $this->request->setData('skip_view_file_cache', true);
            $typedEditorContext = $this->resolveControlledPreviewEditorContext(
                $context,
                $themeId,
                $layoutType,
                $layoutOption,
                $editorArea,
            );

            $this->assign('editor_mode', true);
            $this->assign('preview_mode', true);
            $this->assign('theme_id', $themeId);
            $this->assign('preview_context', $context);
            $this->assign('layout_type', $layoutType);
            $this->assign('layout_option', $layoutOption);
            if ($editorArea === PreviewContextService::AREA_BACKEND && $this->isDashboardPreviewLayout($layoutType)) {
                $this->assign('meta', [
                    'showHeader' => true,
                    'showSidebar' => true,
                    'showFooter' => true,
                    'showRightSidebar' => true,
                ]);

                // fetchModuleThemeHtml 不走 Controller::fetch_file_after，LayoutSlotRenderer 不会自动填槽。
                // 这里显式保留 Dashboard 布局 identity，并手动 processSlots，否则编辑器预览会变成空槽占位。
                $layoutIdentity = $this->resolveVersionLayoutIdentity($context);
                $this->applyThemeLayoutRuntimeContextToRequest(array_merge(
                    $context,
                    $this->buildThemeLayoutRuntimeParams($layoutIdentity)
                ));
                $this->request->setGet('page_type', ThemeLayout::PAGE_TYPE_DASHBOARD);
                $this->request->setGet('layout_type', ThemeLayout::PAGE_TYPE_DASHBOARD);
                $this->request->setGet('layout_option', $layoutOption !== '' ? $layoutOption : 'default');
                $status = (string)$this->request->getParam('status', ThemeLayout::STATUS_DRAFT);
                if ($status !== ThemeLayout::STATUS_DRAFT && $status !== ThemeLayout::STATUS_PUBLISHED) {
                    $status = ThemeLayout::STATUS_DRAFT;
                }
                $this->request->setGet('status', $status);

                // 直接编译框架提供的完整后台 Dashboard 外壳；当前预览主题仍通过 session
                // 提供颜色、静态资源与局部部件，避免依赖主题 6 自身复制布局文件。
                $this->layoutType = null;
                $html = (string)$this->getTemplate()->fetchModuleThemeHtml(
                    'Weline_Theme::theme/backend/layouts/dashboard/default.phtml'
                );

                /** @var SlotRendererService $slotRenderer */
                $slotRenderer = ObjectManager::getInstance(SlotRendererService::class);
                $slotRenderer->clearCache();

                if ($typedEditorContext instanceof ThemeEditorContext) {
                    /** @var ThemeScopedPreviewResolver $scopedPreview */
                    $scopedPreview = ObjectManager::getInstance(ThemeScopedPreviewResolver::class);
                    $rendered = $slotRenderer->processSlotsWithLayout(
                        $html,
                        $scopedPreview->resolveLayout($typedEditorContext, $status),
                        $status === ThemeLayout::STATUS_PUBLISHED,
                        $themeId,
                        PreviewContextService::AREA_BACKEND,
                    );
                    return $this->injectScopedPreviewAppearance(
                        $rendered,
                        $typedEditorContext,
                        $status,
                    );
                }

                return $slotRenderer->processSlots(
                    $html,
                    $themeId,
                    ThemeLayout::PAGE_TYPE_DASHBOARD,
                    $status,
                    PreviewContextService::AREA_BACKEND
                );
            }
            $layoutIdentity = $this->resolveVersionLayoutIdentity($context);
            /** @var ThemePreviewContentRenderer $previewContentRenderer */
            $previewContentRenderer = ObjectManager::getInstance(ThemePreviewContentRenderer::class);
            $previewPayload = $previewContentRenderer->build(
                $themeId,
                $layoutType,
                (string)$this->request->getParam('status', ThemeLayout::STATUS_DRAFT),
                $versionId > 0 ? $versionId : null,
                $layoutIdentity,
                $typedEditorContext,
            );
            $this->assign('content', $previewPayload['content']);

            $layoutIdentify = $this->buildLayoutConfigIdentify($layoutType, $layoutOption);
            $targetIdentify = $this->buildTargetLayoutConfigIdentify($editorArea, $layoutType, $layoutOption);
            $layoutDefinitions = $this->loadLayoutParamDefinitions($this->welineTheme, $editorArea, $layoutType, $layoutOption, $layoutIdentify);
            $layoutMeta = $this->getLayoutConfigValues(
                $this->welineTheme,
                $layoutIdentify,
                (string)($context['scope'] ?? PreviewContextService::DEFAULT_SCOPE),
                (string)$this->request->getParam('locale', '') ?: null,
                $layoutDefinitions,
                $targetIdentify
            );
            if ($typedEditorContext instanceof ThemeEditorContext) {
                /** @var ThemeScopedPreviewResolver $scopedPreview */
                $scopedPreview = ObjectManager::getInstance(ThemeScopedPreviewResolver::class);
                $layoutMeta = $scopedPreview->resolveLayoutMeta(
                    $typedEditorContext,
                    (string)$this->request->getParam('status', ThemeLayout::STATUS_DRAFT),
                );
            }
            $layoutMeta = array_merge([
                'showHeader' => true,
                'showSidebar' => true,
                'showFooter' => true,
                'showRightSidebar' => true,
                'showStatistics' => true,
                'showFeatures' => true,
                'showProducts' => true,
                'showTestimonials' => true,
                'showNews' => true,
                'showPartners' => true,
            ], $previewPayload['meta'], $layoutMeta);
            $this->assign('meta', $layoutMeta);

            $previewContentTemplate = $editorArea === PreviewContextService::AREA_BACKEND
                ? 'Weline_Theme::templates/backend/theme-preview/content.phtml'
                : 'Weline_Theme::templates/frontend/theme-preview/content.phtml';

            $html = (string)$this->fetch($previewContentTemplate);
            return $typedEditorContext instanceof ThemeEditorContext
                ? $this->injectScopedPreviewAppearance(
                    $html,
                    $typedEditorContext,
                    (string)$this->request->getParam('status', ThemeLayout::STATUS_DRAFT),
                )
                : $html;
        } catch (\Throwable $throwable) {
            \Weline\Framework\App\Env::getInstance()->getLogger()?->error('[Theme Editor Layout Preview Failed]', [
                'theme_id' => $themeId,
                'editor_area' => $editorArea,
                'layout_type' => $layoutType,
                'layout_option' => $layoutOption,
                'exception' => $throwable,
            ]);
            return '';
        } finally {
            $this->layoutType = $previousLayoutType;
        }
    }

    /**
     * Resolve a server-validated typed context for controlled editor rendering.
     * Legacy preview requests return null and keep their compatibility path.
     */
    private function resolveControlledPreviewEditorContext(
        array $previewContext,
        int $themeId,
        string $layoutType,
        string $layoutOption,
        string $editorArea,
    ): ?ThemeEditorContext {
        $raw = $previewContext['editor_context'] ?? $this->request->getParam('editor_context', null);
        if ($raw === null || $raw === '') {
            return null;
        }
        /** @var ThemeEditorContextFactory $factory */
        $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
        $context = $factory->fromInput(
            ['editor_context' => $raw],
            ThemeEditorContext::RESOURCE_LAYOUT,
        );
        if ($context->themeId !== $themeId
            || $context->layoutType !== $layoutType
            || $context->layoutOption !== $layoutOption
            || $context->area !== $editorArea
        ) {
            throw new \InvalidArgumentException('theme_scoped_preview_context_mismatch');
        }

        return $context;
    }

    private function injectScopedPreviewAppearance(
        string $html,
        ThemeEditorContext $context,
        string $status,
    ): string {
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($context->themeId);
        if ((int)$theme->getId() !== $context->themeId) {
            return $html;
        }
        /** @var ThemeScopedPreviewResolver $resolver */
        $resolver = ObjectManager::getInstance(ThemeScopedPreviewResolver::class);
        $style = $resolver->renderAppearanceStyle($context, $theme, $status);
        if ($style === '') {
            return $html;
        }
        $headEnd = \stripos($html, '</head>');
        if ($headEnd === false) {
            return $style . $html;
        }

        return \substr($html, 0, $headEnd) . $style . \substr($html, $headEnd);
    }

    private function applyThemeLayoutRuntimeContextToRequest(array $context): void
    {
        foreach ([
            'scope',
            'locale',
            'locale_code',
            'theme_layout_target_type',
            'theme_layout_target_id',
            'theme_layout_source_target_type',
            'theme_layout_source_target_id',
        ] as $key) {
            if (array_key_exists($key, $context) && $context[$key] !== '' && $context[$key] !== null) {
                $this->request->setGet($key, (string)$context[$key]);
            }
        }
    }

    private function resolveThemeLayoutExists(int $themeId, string $editorArea, string $layoutType, string $layoutOption = 'default'): bool
    {
        return $this->resolveThemeLayoutFilePath($themeId, $editorArea, $layoutType, $layoutOption) !== '';
    }

    /**
     * 渲染布局文件不存在的错误页面
     */
    private function renderLayoutNotFoundError(string $layoutType, string $layoutOption): string
    {
        $themePreference = $this->resolveBackendPreviewThemePreference();
        $resolvedThemeMode = $themePreference === 'dark' ? 'dark' : 'light';
        $safeLayoutType = htmlspecialchars($layoutType, ENT_QUOTES, 'UTF-8');
        $safeLayoutOption = htmlspecialchars($layoutOption, ENT_QUOTES, 'UTF-8');
        $foundationUrl = $this->resolveUiAssetUrl('weline-foundation.css');
        $backendUrl = $this->resolveUiAssetUrl('weline-backend.css');
        $previewCssUrl = $this->resolveUiAssetUrl('pages/weline-theme-preview.css');
        $uiUrl = $this->resolveUiAssetUrl('weline-ui.js');
        $icons = ObjectManager::getInstance(\Weline\Theme\Service\Ui\IconRegistry::class);
        $warningIcon = $icons->render('warning', 'xl', (string)__('警告'));
        $title = htmlspecialchars((string)__('布局不存在'), ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars((string)__('请求的布局模板文件未找到，请选择其他布局类型。'), ENT_QUOTES, 'UTF-8');
        $typeLabel = htmlspecialchars((string)__('布局类型'), ENT_QUOTES, 'UTF-8');
        $optionLabel = htmlspecialchars((string)__('布局选项'), ENT_QUOTES, 'UTF-8');
        $hint = htmlspecialchars((string)__('请在主题编辑器中选择有效的布局。'), ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN" data-w-area="backend" data-theme-preference="{$themePreference}" data-theme="{$resolvedThemeMode}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link rel="stylesheet" href="{$foundationUrl}">
    <link rel="stylesheet" href="{$backendUrl}">
    <link rel="stylesheet" href="{$previewCssUrl}">
    <script type="module" src="{$uiUrl}"></script>
</head>
<body>
    <main class="w-theme-preview-error-shell">
        <article class="w-card w-theme-preview-error" role="alert">
            <div class="w-card__body">
                <span class="w-theme-preview-error__icon">{$warningIcon}</span>
                <h1 class="w-card__title">{$title}</h1>
                <p>{$description}</p>
                <div class="w-theme-preview-error__identity">
                    <strong>{$typeLabel}:</strong> {$safeLayoutType}<br>
                    <strong>{$optionLabel}:</strong> {$safeLayoutOption}
                </div>
                <p>{$hint}</p>
            </div>
        </article>
    </main>
</body>
</html>
HTML;
        return $html;
    }

    private function resolveUiAssetUrl(string $relative): string
    {
        if (preg_match('#^[a-z0-9][a-z0-9/.-]+$#', $relative) !== 1 || str_contains($relative, '..')) {
            throw new \InvalidArgumentException(__('Weline UI 资源路径无效'));
        }

        return htmlspecialchars(
            $this->getTemplate()->fetchTagSource('statics', 'Weline_Theme::ui/' . $relative),
            ENT_QUOTES,
            'UTF-8',
        );
    }

    /**
     * 获取插槽的原始内容（从published版本）
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param string $slotId 插槽ID
     * @param string $area 区域
     * @return string 原始HTML内容
     */
    private function getOriginalSlotContent(
        int $themeId,
        string $pageType,
        string $slotId,
        string $area,
        string $layoutType = 'homepage',
        string $layoutOption = 'default',
        array $identity = []
    ): string
    {
        try {
            $context = $this->buildThemeLayoutRuntimeParams($identity);
            // 根据 area 决定渲染哪个模板
            $fullHtml = '';
            if ($area === 'header') {
                $fullHtml = $this->renderPartialPreviewHtml($themeId, $pageType, 'header', $layoutOption, $context);
            } elseif ($area === 'footer') {
                $fullHtml = $this->renderPartialPreviewHtml($themeId, $pageType, 'footer', $layoutOption, $context);
            } else {
                $fullHtml = $this->renderLayoutPreviewHtml($themeId, $pageType, $layoutType, $layoutOption, $context);
            }
            
            if (empty($fullHtml)) {
                return '';
            }
            
            // 从渲染的HTML中提取指定插槽的内容
            return $this->extractSlotContentFromHtml($fullHtml, $slotId);
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * 渲染布局预览HTML（用于提取插槽内容）
     */
    private function renderLayoutPreviewHtml(int $themeId, string $pageType, string $layoutType, string $layoutOption, array $context = []): string
    {
        try {
            $session = ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
            $session->setData('preview_theme_id', $themeId);
            $session->setData('preview_theme_area', PreviewContextService::AREA_FRONTEND);
            $this->request->setGet('status', ThemeLayout::STATUS_DRAFT);
            $this->request->setGet('editor_area', PreviewContextService::AREA_FRONTEND);
            $this->applyThemeLayoutRuntimeContextToRequest($context);

            return $this->renderUnifiedLayoutPreview(
                $themeId,
                $layoutType,
                $layoutOption,
                PreviewContextService::AREA_FRONTEND,
                $context
            );

            $templatePath = "Weline_Theme::theme/frontend/layouts/{$layoutType}/{$layoutOption}.phtml";
            
            // 设置渲染参数（与getLayoutPreview()相同）
            $this->assign('editor_mode', true);
            $this->assign('preview_mode', true); // 读取草稿数据
            $this->assign('theme_id', $themeId);
            $this->assign('page_type', $pageType);
            $this->assign('layout_type', $layoutType);
            $this->assign('meta', [
                'showHeader' => true,
                'showFooter' => true,
                'showStatistics' => true,
                'showFeatures' => true,
                'showProducts' => true,
                'showTestimonials' => true,
                'showNews' => true,
                'showPartners' => true,
            ]);
            
            // 渲染模板（会触发插槽渲染事件，应用draft配置）
            $html = $this->fetch($templatePath);
            
            return $html ?: '';
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * 渲染 partial 预览 HTML（header/footer 等独立区域）
     */
    private function renderPartialPreviewHtml(int $themeId, string $pageType, string $partialType, string $layoutOption, array $context = []): string
    {
        try {
            $templatePath = "Weline_Theme::theme/frontend/partials/{$partialType}/{$layoutOption}.phtml";
            $this->applyThemeLayoutRuntimeContextToRequest($context);
            
            $this->assign('editor_mode', true);
            $this->assign('preview_mode', true);
            $this->assign('theme_id', $themeId);
            $this->assign('page_type', $pageType);
            
            $html = $this->fetch($templatePath);
            
            return $html ?: '';
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * 从HTML中提取指定插槽的内容
     */
    private function extractSlotContentFromHtml(string $html, string $slotId): string
    {
        try {
            // 使用DOMDocument解析HTML
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            
            // 添加UTF-8声明并加载HTML
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            
            // 查找插槽元素
            $xpath = new \DOMXPath($dom);
            $slotNodes = $xpath->query("//*[@data-wslot='{$slotId}']");
            
            if ($slotNodes->length === 0) {
                return '';
            }
            
            // 获取插槽的innerHTML
            $slotNode = $slotNodes->item(0);
            $innerHTML = '';
            foreach ($slotNode->childNodes as $child) {
                $innerHTML .= $dom->saveHTML($child);
            }
            
            // 去除UTF-8声明
            $innerHTML = str_replace('<?xml encoding="UTF-8">', '', $innerHTML);
            
            return $innerHTML;
        } catch (\Exception $e) {
            return '';
        }
    }

    // ==================== 版本控制 API ====================

    /**
     * 获取版本列表 (Query)
     * 路由: /backend/theme-editor/versions (GET)
     */
    public function getVersions()
    {
        $data = $this->getEditorJsonPayload();
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id'));
        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));
        $limit = (int)$this->request->getParam('limit', 20);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $themeId = $context->themeId;
            $pageType = $context->layoutType;
            $identity = $this->layoutIdentityFromEditorContext($context);
            $this->versionService->initializeVersionIfNeeded($themeId, $pageType, null, $identity);
            $versions = $this->versionService->getVersions($themeId, $pageType, $limit, $identity);
            $currentVersion = $this->versionService->getCurrentVersion($themeId, $pageType, $identity);
            $publishedVersion = $this->versionService->getPublishedVersion($themeId, $pageType, $identity);

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'versions' => $versions,
                    'current_version_id' => $currentVersion?->getVersionId(),
                    'published_version_id' => $publishedVersion?->getVersionId(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 保存为新版本 (Query)
     * 路由: /backend/theme-editor/save-version (POST)
     */
    public function postSaveVersion()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = $data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);
        $versionName = $data['version_name'] ?? $this->request->getParam('version_name');
        $description = $data['description'] ?? $this->request->getParam('description');

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, (string)$pageType);
            $version = $this->saveScopedLayoutVersion(
                $context,
                $versionName !== null ? (string)$versionName : null,
                $description !== null ? (string)$description : null,
            );

            return $this->fetchJson([
                'success' => true,
                'message' => __('已保存为 %{name}', ['name' => $version->getDisplayName()]),
                'data' => $version->toArray(),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 切换到指定版本 (Query)
     * 路由: /backend/theme-editor/switch-version (POST)
     */
    public function postSwitchVersion()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = $data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);
        $versionId = (int)($data['version_id'] ?? $this->request->getParam('version_id', 0));

        if (!$themeId || !$versionId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, (string)$pageType);
            $identity = $this->layoutIdentityFromEditorContext($context);
            $targetVersion = $this->versionService->getVersion(
                $themeId,
                (string)$pageType,
                $versionId,
                $identity,
            );
            if (!$targetVersion instanceof ThemeLayoutVersion) {
                throw new \RuntimeException('theme_layout_version_not_found');
            }
            $targetSnapshot = $this->normalizeLegacyLayoutSnapshot($context, $targetVersion->getSnapshotData());
            $result = $this->versionService->switchToVersion(
                $themeId,
                (string)$pageType,
                $versionId,
                $identity,
                $targetSnapshot,
            );

            if ($result) {
                // 获取更新后的布局数据
                $layout = $this->layoutService->getFullDraftLayout($themeId, $pageType, $identity);
                $currentVersion = $this->versionService->getCurrentVersion($themeId, (string)$pageType, $identity);
                if (!$currentVersion instanceof ThemeLayoutVersion) {
                    throw new \RuntimeException('theme_layout_current_version_missing');
                }
                $scopedDraft = $this->replaceScopedLayoutDraftFromSnapshot(
                    $context,
                    $targetSnapshot,
                    'Restore selected legacy layout version',
                );

                return $this->fetchJson([
                    'success' => true,
                    'message' => __('已切换到选定版本'),
                    'data' => [
                        'layout' => $layout,
                        'scoped_workspace' => $scopedDraft,
                    ],
                ]);
            }

            return $this->fetchJson([
                'success' => false,
                'message' => __('切换版本失败'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 恢复原始布局 (Query) - 重构版本
     * 路由: /backend/theme-editor/restore-original (POST)
     * 
     * 新行为：
     * 1. 自动创建当前状态的备份版本
     * 2. 清空工作区恢复到主题模板原始状态（不添加任何部件）
     * 3. 创建新的"原始布局"版本
     */
    public function postRestoreOriginal()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = $data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, (string)$pageType);
            $identity = $this->layoutIdentityFromEditorContext($context);
            $result = $this->versionService->restoreOriginal(
                $themeId,
                (string)$pageType,
                null,
                $identity,
                $this->scopedLayoutSnapshot($context),
            );

            // 清除插槽渲染服务的布局缓存，否则 WLS 常驻进程会继续返回旧 draft 缓存，预览无法恢复为空白
            ObjectManager::getInstance(SlotRendererService::class)->clearCache();

            $backupVersion = $result['backup_version'];
            $newVersion = $result['new_version'];
            $scopedDraft = $this->replaceScopedLayoutDraftFromSnapshot(
                $context,
                $newVersion->getSnapshotData(),
                'Restore original layout snapshot',
            );

            $message = __('已恢复到原始布局');
            if ($backupVersion) {
                $message .= ' (' . __('已备份为 %{name}', ['name' => $backupVersion->getDisplayName()]) . ')';
            }

            return $this->fetchJson([
                'success' => true,
                'message' => $message,
                'data' => [
                    'backup_version' => $backupVersion?->toArray(),
                    'new_version' => $newVersion->toArray(),
                    'scoped_workspace' => $scopedDraft,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 发布版本 (Query)
     * 路由: /backend/theme-editor/publish-version (POST)
     */
    public function postPublishVersion()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = $data['page_type'] ?? $this->request->getParam('page_type');
        $versionId = isset($data['version_id']) ? (int)$data['version_id'] : null;

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, (string)$pageType);
            $identity = $this->layoutIdentityFromEditorContext($context);
            $scopedReleasePublished = $this->hasScopedReleasePublishedClaim($data);
            if (!$scopedReleasePublished) {
                $this->publishPendingScopedResources($context, 'theme_editor_compat_version_publish');
            }
            $this->assertCurrentScopedLayoutPublished($context);
            $result = $this->versionService->markVersionPublished(
                $themeId,
                (string)$pageType,
                $versionId,
                $identity,
            );

            if ($result) {
                $this->publishEditorPreviewScope($themeId, (string)($identity['scope'] ?? PreviewContextService::DEFAULT_SCOPE));

                // 清除并重建缓存
                $this->cacheGenerator->clearCache($themeId);
                $this->cacheGenerator->generate($themeId);
                
                $this->flushFullPageCache();

                return $this->fetchJson([
                    'success' => true,
                    'message' => __('版本已发布'),
                ]);
            }

            return $this->fetchJson([
                'success' => false,
                'message' => __('发布失败'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 删除版本 (Query)
     * 路由: /backend/theme-editor/delete-version (POST)
     */
    public function postDeleteVersion()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $versionId = (int)($data['version_id'] ?? $this->request->getParam('version_id', 0));
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));

        if (!$versionId || !$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少版本ID'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $identity = $this->layoutIdentityFromEditorContext($context);
            $result = $this->versionService->deleteVersion(
                $versionId,
                $themeId > 0 ? $themeId : null,
                $themeId > 0 ? $pageType : null,
                $identity
            );

            if ($result) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('版本已删除'),
                ]);
            }

            return $this->fetchJson([
                'success' => false,
                'message' => __('无法删除当前版本或已发布版本'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 重命名版本 (Query)
     * 路由: /backend/theme-editor/rename-version (POST)
     */
    public function postRenameVersion()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $versionId = (int)($data['version_id'] ?? $this->request->getParam('version_id', 0));
        $newName = $data['version_name'] ?? $this->request->getParam('version_name', '');
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));

        if (!$versionId || !$themeId || empty($newName)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }

        try {
            $context = $this->requireLayoutWriteContext($data, $themeId, $pageType);
            $identity = $this->layoutIdentityFromEditorContext($context);
            $result = $this->versionService->renameVersion(
                $versionId,
                (string)$newName,
                $themeId > 0 ? $themeId : null,
                $themeId > 0 ? $pageType : null,
                $identity
            );

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('版本已重命名') : __('重命名失败'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ==================== 前端预览 API ====================

    /**
     * 启动前端预览 (Query)
     * 路由: /backend/theme-editor/start-preview (POST)
     * 
     * 生成预览 Token 并返回前端预览 URL
     */
    public function postStartPreview()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $pageType = (string)($data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));
        $layoutOption = (string)($data['layout_option'] ?? $this->request->getParam('layout_option', 'default'));
        $frontendThemeId = (int)($data['frontend_theme_id'] ?? $data['theme_id'] ?? $this->request->getParam('frontend_theme_id', $this->request->getParam('theme_id', 0)));
        $identity = $this->resolveVersionLayoutIdentity($data);
        if (!$frontendThemeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少前端主题ID'),
            ]);
        }

        try {
            $typedEditorContext = null;
            if (array_key_exists('editor_context', $data)) {
                $typedEditorContext = $this->requireLayoutWriteContext($data, $frontendThemeId, $pageType);
                $frontendThemeId = $typedEditorContext->themeId;
                $pageType = $typedEditorContext->layoutType;
                $layoutOption = $typedEditorContext->layoutOption;
            }
            $context = $this->getPreviewContextService()->buildContext(array_replace([
                'frontend_theme_id' => $frontendThemeId,
                'backend_theme_id' => (int)($data['backend_theme_id'] ?? $this->request->getParam('backend_theme_id', 0)),
                'editor_area' => PreviewContextService::AREA_FRONTEND,
                'shell' => PreviewContextService::SHELL_PREVIEW,
                'preview_mode' => (string)($data['preview_mode'] ?? $this->request->getParam('preview_mode', PreviewContextService::DEFAULT_PREVIEW_MODE)),
                'status' => (string)($data['status'] ?? $this->request->getParam('status', PreviewContextService::DEFAULT_STATUS)),
                'version_id' => isset($data['version_id']) ? (int)$data['version_id'] : ((int)$this->request->getParam('version_id', 0) ?: null),
                'scope' => (string)($data['scope'] ?? $this->request->getParam('scope', PreviewContextService::DEFAULT_SCOPE)),
                'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
                'target_value' => $pageType,
                'layout_option' => $layoutOption,
                'editor_context' => $typedEditorContext?->toArray(),
            ], $this->buildThemeLayoutRuntimeParams($identity)));
            $context = $this->getPreviewContextService()->ensureThemeIds($context, true, true);
            $token = $this->previewTokenService->generateToken(
                $frontendThemeId,
                $pageType,
                $context['version_id'] ?? null,
                $context
            );
            $this->previewTokenService->setPreviewCookie($token);
            $context = $this->getPreviewContextService()->withPreviewToken($context, $token);
            $this->getPreviewContextService()->persistContext($context);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Preview started'),
                'data' => [
                    'token' => $token,
                    'preview_url' => $this->buildFrontendPreviewUrl($context, $pageType, $layoutOption),
                    'context' => $context,
                    'expires_in' => 3600,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function postResolveNavigation()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $href = \trim((string)($data['href'] ?? ''));
        if ($href === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Missing navigation target'),
            ]);
        }

        $context = isset($data['context']) && \is_array($data['context']) ? $data['context'] : [];
        $context = $this->getPreviewContextService()->buildContext($context);
        $result = $this->getPreviewNavigationResolver()->resolve($context, $href);

        if (($result['kind'] ?? '') !== 'external') {
            $resolvedContext = $this->getPreviewContextService()->persistContext((array)($result['context'] ?? []));
            if (!empty($resolvedContext['preview_token'])) {
                $this->previewTokenService->setPreviewCookie((string)$resolvedContext['preview_token']);
            }
            $result['context'] = $resolvedContext;
        }

        return $this->fetchJson([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * 退出前端预览 (Query)
     * 路由: /backend/theme-editor/exit-preview (POST)
     * 
     * 删除预览 Token
     */
    public function postExitPreview()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $token = $data['token'] ?? $this->request->getParam('token', '');
        if (empty($token)) {
            $token = $this->previewTokenService->getTokenFromRequest();
        }
        $token = is_scalar($token) ? trim((string)$token) : '';

        try {
            $tokenData = $token !== '' ? $this->previewTokenService->validateToken($token) : null;
            $tokenData = is_array($tokenData) ? $tokenData : [];
            $context = is_array($tokenData['context'] ?? null) ? $tokenData['context'] : [];
            $pageType = (string)($tokenData['page_type'] ?? ($context['target_value'] ?? ThemeLayout::PAGE_TYPE_HOME));
            if ($pageType === '') {
                $pageType = ThemeLayout::PAGE_TYPE_HOME;
            }

            // 退出预览是幂等的：token 已失效/缺失时仍清 Cookie 与会话态，避免前端卡在预览浮窗。
            if ($token !== '') {
                $this->previewTokenService->deleteToken($token);
            }
            $this->previewTokenService->clearPreviewCookie();
            $this->getPreviewContextService()->clearContext();
            PreviewManager::clearPreviewConfig();
            $this->session->delete('preview_auto_login');
            $requestedEditorArea = $this->getPreviewContextService()->normalizeArea(
                (string)$this->request->getParam(
                    'editor_area',
                    (string)($this->request->getParam('preview_area', (string)($context['editor_area'] ?? PreviewContextService::AREA_BACKEND)))
                ),
                PreviewContextService::AREA_BACKEND
            );

            $editorContext = $this->getPreviewContextService()->buildContext(\array_replace($context, [
                'editor_area' => $requestedEditorArea,
                'shell' => PreviewContextService::SHELL_THEME_EDITOR,
                'preview_token' => '',
                'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
                'target_value' => $pageType,
            ]), false);
            try {
                $editorContext = $this->getPreviewContextService()->ensureThemeIds($editorContext, true, true);
            } catch (\Throwable) {
                // theme_id 缺失时仍返回可跳转的编辑器壳 URL
            }

            return $this->fetchJson([
                'success' => true,
                'message' => __('Preview exited'),
                'data' => [
                    'editor_url' => $this->buildEditorShellUrl($editorContext, $pageType),
                    'context' => $editorContext,
                ],
            ]);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            // fetchJson 通过 Error 终止响应；不可被下方 Throwable 当成失败吞掉
            throw $e;
        } catch (\Throwable $e) {
            // 即便服务端清理失败，也尽量清 Cookie，让前端能离开预览态
            try {
                $this->previewTokenService->clearPreviewCookie();
            } catch (\Throwable) {
            }

            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : (string)__('Failed to exit preview'),
            ]);
        }
    }

    /**
     * 发布并退出预览 (Query)
     * 路由: /backend/theme-editor/publish-and-exit (POST)
     * 
     * 发布当前预览内容并退出预览模式
     */
    public function postPublishAndExit()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $token = $data['token'] ?? $this->request->getParam('token', '');
        if (empty($token)) {
            $token = $this->previewTokenService->getTokenFromRequest();
        }

        if (empty($token)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Missing preview token'),
            ]);
        }

        try {
            $tokenData = $this->previewTokenService->validateToken($token);
            if (!$tokenData) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Preview token is invalid or expired'),
                ]);
            }

            $themeId = (int)($tokenData['theme_id'] ?? 0);
            $pageType = (string)($tokenData['page_type'] ?? ThemeLayout::PAGE_TYPE_HOME);
            $previewContext = \is_array($tokenData['context'] ?? null) ? $tokenData['context'] : [];
            $identity = $this->resolveVersionLayoutIdentity($previewContext);
            if (!$themeId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Preview token is missing theme information'),
                ]);
            }

            $typedClaims = $previewContext['editor_context'] ?? null;
            if (!is_array($typedClaims)) {
                throw new \InvalidArgumentException('theme_preview_typed_context_required');
            }
            /** @var ThemeEditorContextFactory $factory */
            $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
            $typedContext = $factory->fromInput(
                ['editor_context' => $typedClaims],
                ThemeEditorContext::RESOURCE_LAYOUT,
            );
            if ($typedContext->themeId !== $themeId || $typedContext->layoutType !== $pageType) {
                throw new \InvalidArgumentException('theme_preview_typed_context_mismatch');
            }
            $this->publishPendingScopedResources($typedContext, 'theme_preview_publish_and_exit');
            $this->assertCurrentScopedLayoutPublished($typedContext);
            $identity = $this->layoutIdentityFromEditorContext($typedContext);
            $this->publishEditorPreviewScope(
                $themeId,
                (string)($identity['scope'] ?? PreviewContextService::DEFAULT_SCOPE),
                $previewContext
            );

            $this->cacheGenerator->clearCache($themeId);
            $this->cacheGenerator->generate($themeId);
            $this->flushFullPageCache();

            $this->previewTokenService->deleteToken($token);
            $this->previewTokenService->clearPreviewCookie();
            $this->getPreviewContextService()->clearContext();
            PreviewManager::clearPreviewConfig();
            $this->session->delete('preview_auto_login');
            $requestedEditorArea = $this->getPreviewContextService()->normalizeArea(
                (string)$this->request->getParam(
                    'editor_area',
                    (string)($this->request->getParam('preview_area', (string)($previewContext['editor_area'] ?? PreviewContextService::AREA_BACKEND)))
                ),
                PreviewContextService::AREA_BACKEND
            );

            $editorContext = $this->getPreviewContextService()->buildContext(\array_replace($previewContext, [
                'editor_area' => $requestedEditorArea,
                'shell' => PreviewContextService::SHELL_THEME_EDITOR,
                'preview_token' => '',
                'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
                'target_value' => $pageType,
            ]), false);
            $editorContext = $this->getPreviewContextService()->ensureThemeIds($editorContext, true, true);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Theme published'),
                'data' => [
                    'redirect_url' => $this->_url->getFrontendUrl(
                        $this->getThemePageTypeResolver()->getPreviewRouteByPageType($pageType),
                        [
                            'page_type' => $pageType,
                            'layout_type' => $pageType,
                            'layout_option' => 'default',
                            'status' => 'published',
                            'editor_area' => PreviewContextService::AREA_FRONTEND,
                            'preview_area' => PreviewContextService::AREA_FRONTEND,
                        ]
                    ),
                    'editor_url' => $this->buildEditorShellUrl($editorContext, $pageType),
                    'context' => $editorContext,
                ],
            ]);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : (string)__('发布失败'),
            ]);
        }
    }

    /**
     * 根据页面类型获取预览路径
     * 
     * @param string $pageType 页面类型
     * @return string 预览路径
     */
    private function getPreviewContextService(): PreviewContextService
    {
        /** @var PreviewContextService $service */
        $service = ObjectManager::getInstance(PreviewContextService::class);
        return $service;
    }

    private function getThemeSlotContractService(): ThemeSlotContractService
    {
        /** @var ThemeSlotContractService $service */
        $service = ObjectManager::getInstance(ThemeSlotContractService::class);
        return $service;
    }

    private function collectMissingSlotWarningsForEditor(string $editorArea, string $layoutType = '', string $layoutOption = ''): array
    {
        try {
            $warnings = $this->getThemeSlotContractService()->collectMissingDefaultSlots($editorArea, $this->welineTheme);
            $logicalKey = $this->normalizeCurrentLayoutLogicalKey($layoutType, $layoutOption);
            if ($logicalKey !== '') {
                $warnings = array_values(array_filter(
                    $warnings,
                    static fn(array $warning): bool => (string)($warning['logical_key'] ?? '') === $logicalKey
                ));
            }
            $this->getThemeSlotContractService()->notifyMissingDefaultSlots($warnings, $editorArea);
            return $warnings;
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV && function_exists('w_log_warning')) {
                \w_log_warning('[Theme Slot Missing] editor scan failed: ' . $e->getMessage());
            }
            return [];
        }
    }

    private function normalizeCurrentLayoutLogicalKey(string $layoutType, string $layoutOption): string
    {
        $layoutType = trim(str_replace('\\', '/', $layoutType), '/ ');
        $layoutOption = trim(str_replace('\\', '/', $layoutOption), '/ ');
        if ($layoutType === '') {
            return '';
        }
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }

        return 'layouts/' . $layoutType . '/' . $layoutOption;
    }

    private function getThemeContextService(): ThemeContextService
    {
        /** @var ThemeContextService $service */
        $service = ObjectManager::getInstance(ThemeContextService::class);
        return $service;
    }

    private function getPreviewNavigationResolver(): PreviewNavigationResolver
    {
        /** @var PreviewNavigationResolver $resolver */
        $resolver = ObjectManager::getInstance(PreviewNavigationResolver::class);
        return $resolver;
    }

    private function getThemePageTypeResolver(): ThemePageTypeResolver
    {
        /** @var ThemePageTypeResolver $resolver */
        $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);
        return $resolver;
    }

    private function loadThemeModel(int $themeId): ?WelineTheme
    {
        if ($themeId <= 0) {
            return null;
        }

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        return $theme->getId() ? $theme : null;
    }

    private function persistEditorContext(array $overrides = []): array
    {
        // Don't let explicit "0" IDs wipe out request-derived theme selection (e.g. theme_id).
        foreach (['frontend_theme_id', 'backend_theme_id'] as $themeIdKey) {
            if (\array_key_exists($themeIdKey, $overrides) && (int)$overrides[$themeIdKey] <= 0) {
                unset($overrides[$themeIdKey]);
            }
        }

        $themeId = (int)$this->request->getParam('theme_id', 0);
        $editorArea = $this->resolveRequestedEditorArea(
            (string)($overrides['editor_area'] ?? PreviewContextService::AREA_BACKEND)
        );
        $previewArea = $this->resolveRequestedPreviewArea($editorArea);
        $overrides['editor_area'] = $previewArea;

        // If caller only passed theme_id (common in editor iframe refresh), map it to selected area.
        if ($themeId > 0
            && !isset($overrides['frontend_theme_id'])
            && !isset($overrides['backend_theme_id'])
        ) {
            if ($previewArea === PreviewContextService::AREA_BACKEND) {
                $overrides['backend_theme_id'] = $themeId;
            } else {
                $overrides['frontend_theme_id'] = $themeId;
            }
        }

        $context = $this->getPreviewContextService()->buildContext($overrides);
        $context = $this->getPreviewContextService()->ensureThemeIds($context, true, true);
        return $this->getPreviewContextService()->persistContext($context);
    }

    private function resolveRequestedEditorArea(string $default = PreviewContextService::AREA_FRONTEND): string
    {
        $rawEditorArea = (string)$this->request->getParam('editor_area', '');
        if ($rawEditorArea === '') {
            $rawEditorArea = (string)$this->request->getParam('preview_area', $default);
        }

        return $this->normalizeRequestedArea($rawEditorArea, $default);
    }

    private function resolveRequestedPreviewArea(string $default = PreviewContextService::AREA_FRONTEND): string
    {
        $rawPreviewArea = (string)$this->request->getParam('preview_area', '');
        if ($rawPreviewArea === '') {
            $rawPreviewArea = (string)$this->request->getParam('editor_area', $default);
        }

        return $this->normalizeRequestedArea($rawPreviewArea, $default);
    }

    private function normalizeRequestedArea(string $rawArea, string $default): string
    {
        $area = \strtolower(\trim($rawArea));
        if (\in_array($area, [PreviewContextService::AREA_FRONTEND, PreviewContextService::AREA_BACKEND], true)) {
            return $area;
        }

        return $this->getPreviewContextService()->normalizeArea($default);
    }

    private function buildFrontendPreviewUrl(array $context, string $pageType, string $layoutOption = 'default'): string
    {
        $context = $this->getPreviewContextService()->buildContext(\array_replace($context, [
            'editor_area' => PreviewContextService::AREA_FRONTEND,
            'shell' => PreviewContextService::SHELL_PREVIEW,
            'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
            'target_value' => $pageType,
        ]), false);
        $params = $this->getPreviewContextService()->toQueryParams($context);
        $params['page_type'] = $pageType;
        $params['layout_type'] = $pageType;
        $params['layout_option'] = $this->normalizeLayoutOption($layoutOption) ?: 'default';
        $params = array_replace($params, $this->buildThemeLayoutRuntimeParams($this->resolveVersionLayoutIdentity(
            $this->layoutIdentityInputForNavigationContext($context)
        )));
        $params['_t'] = \time();

        return $this->_url->getFrontendUrl(
            'theme/frontend/theme-preview/content',
            $params
        );
    }

    private function buildEditorShellUrl(array $context, string $pageType): string
    {
        $context = $this->getPreviewContextService()->buildContext(\array_replace($context, [
            'shell' => PreviewContextService::SHELL_THEME_EDITOR,
            'preview_token' => '',
            'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
            'target_value' => $pageType,
        ]), false);
        $context = $this->getPreviewContextService()->ensureThemeIds($context, true, true);

        $editorArea = $this->getPreviewContextService()->normalizeArea(
            (string)($context['editor_area'] ?? PreviewContextService::AREA_FRONTEND)
        );
        $themeId = $this->getPreviewContextService()->getThemeIdForArea($editorArea, $context, true);
        $params = $this->getPreviewContextService()->toQueryParams($context);
        $params['theme_id'] = $themeId;
        $params['page_type'] = $pageType;
        $params['layout_type'] = $pageType;
        $params = array_replace($params, $this->buildThemeLayoutEditorLockParams($this->resolveVersionLayoutIdentity(
            $this->layoutIdentityInputForNavigationContext($context)
        )));
        $params['editor_area'] = $editorArea;
        $params['_t'] = \time();

        return $this->_url->getBackendUrl('theme/backend/theme-editor', $params);
    }

    /** @return array{editor_context:mixed}|array{} */
    private function layoutIdentityInputForNavigationContext(array $context): array
    {
        $editorContext = $context['editor_context'] ?? null;
        if ($editorContext === null || $editorContext === '') {
            return [];
        }

        return ['editor_context' => $editorContext];
    }

    private function themeRecordHasBackendArea(array $themeData): bool
    {
        $themeId = (int)($themeData['id'] ?? 0);
        $theme = $this->loadThemeModel($themeId);
        if (!$theme) {
            return false;
        }

        return $this->themeHasBackendDir($theme);
    }

    private function getPreviewPathByPageType(string $pageType): string
    {
        return $this->getThemePageTypeResolver()->getPreviewPathByPageType($pageType);
    }

    // ==================== 编辑锁定 API ====================

    /**
     * 检查编辑锁定状态 (Query)
     * 路由: /backend/theme-editor/check-lock (GET)
     * 
     * 返回当前锁定状态，如果被其他用户锁定，返回锁定信息
     */
    public function getCheckLock()
    {
        return $this->respondEditorLockAcquire($this->getEditorJsonPayload());
    }

    /**
     * Acquire/check editor lock via JSON body (preferred; avoids GET query truncation).
     * Route: /backend/theme-editor/check-lock (POST)
     */
    public function postCheckLock()
    {
        return $this->respondEditorLockAcquire($this->getEditorJsonPayload());
    }

    /** @param array<string,mixed> $payload */
    private function respondEditorLockAcquire(array $payload)
    {
        $themeId = (int)($payload['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = (string)($payload['page_type']
            ?? $payload['layout_type']
            ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME));

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        // 获取当前用户信息
        $userId = $this->session->getLoginUserID() ?: 0;
        $userName = $this->session->getLoginUsername() ?: '';
        $lockContextKey = $this->resolveEditorLockContextKey(
            $payload,
            $themeId,
            $pageType,
        );

        // 尝试获取锁定
        $result = $this->editorLockService->acquireLock(
            $themeId,
            $pageType,
            $userId,
            $userName,
            $lockContextKey,
        );

        return $this->fetchJson([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => [
                'lock_info' => $result['lock_info'] ?? null,
                'is_locked_by_other' => !$result['success'],
            ],
        ]);
    }

    /**
     * 释放编辑锁定 (Query)
     * 路由: /backend/theme-editor/release-lock (POST)
     */
    public function postReleaseLock()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = $data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        $userId = $this->session->getLoginUserID() ?: 0;
        $lockContextKey = $this->resolveEditorLockContextKey($data, $themeId, (string)$pageType);
        $result = $this->editorLockService->releaseLock($themeId, (string)$pageType, $userId, $lockContextKey);

        return $this->fetchJson([
            'success' => $result,
            'message' => $result ? __('已释放编辑锁定') : __('释放锁定失败'),
        ]);
    }

    /**
     * 更新编辑活动时间 (Query)
     * 路由: /backend/theme-editor/update-activity (POST)
     * 
     * 用于保持锁定活跃，防止自动过期
     */
    public function postUpdateActivity()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = $data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        $userId = $this->session->getLoginUserID() ?: 0;
        $lockContextKey = $this->resolveEditorLockContextKey($data, $themeId, (string)$pageType);
        $result = $this->editorLockService->updateActivity($themeId, (string)$pageType, $userId, $lockContextKey);

        return $this->fetchJson([
            'success' => $result,
        ]);
    }

    /**
     * 请求接管编辑 (Query)
     * 路由: /backend/theme-editor/request-takeover (POST)
     */
    public function postRequestTakeover()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = $data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        $userId = $this->session->getLoginUserID() ?: 0;
        $userName = $this->session->getLoginUsername() ?: '';
        $lockContextKey = $this->resolveEditorLockContextKey($data, $themeId, (string)$pageType);

        $result = $this->editorLockService->requestTakeover(
            $themeId,
            (string)$pageType,
            $userId,
            $userName,
            $lockContextKey,
        );

        return $this->fetchJson($result);
    }

    /**
     * 检查是否有接管请求 (Query)
     * 路由: /backend/theme-editor/check-takeover-request (GET)
     * 
     * 当前锁定者调用此接口检查是否有人请求接管
     */
    public function getCheckTakeoverRequest()
    {
        $themeId = (int)$this->request->getParam('theme_id', 0);
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        $lockContextKey = $this->resolveEditorLockContextKey(
            $this->getEditorJsonPayload(),
            $themeId,
            (string)$pageType,
        );
        $takeoverRequest = $this->editorLockService->getTakeoverRequest(
            $themeId,
            (string)$pageType,
            $lockContextKey,
        );

        return $this->fetchJson([
            'success' => true,
            'data' => [
                'has_takeover_request' => $takeoverRequest !== null,
                'takeover_request' => $takeoverRequest,
            ],
        ]);
    }

    /**
     * 强制接管编辑 (Query)
     * 路由: /backend/theme-editor/force-takeover (POST)
     */
    public function postForceTakeover()
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $data = json_decode($bodyParams, true) ?: [];
        } elseif (is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
        $pageType = $data['page_type'] ?? $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        $userId = $this->session->getLoginUserID() ?: 0;
        $userName = $this->session->getLoginUsername() ?: '';
        $lockContextKey = $this->resolveEditorLockContextKey($data, $themeId, (string)$pageType);

        $result = $this->editorLockService->forceTakeover(
            $themeId,
            (string)$pageType,
            $userId,
            $userName,
            $lockContextKey,
        );

        return $this->fetchJson($result);
    }

    private function normalizeSlotCodeParam(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = explode(',', $value);
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $codes = [];
        foreach ($value as $item) {
            $code = strtolower(trim((string)$item));
            if ($code === '') {
                continue;
            }
            $codes[$code] = $code;
        }

        return array_values($codes);
    }

    /**
     * URL-level widget library filters. These are intentionally separate from
     * slot accept/reject so a visual editor URL can shape the whole right panel.
     */
    private function getWidgetLibraryFilterOptionsFromRequest(): array
    {
        $keys = [
            'widget_allow_groups',
            'widget_reject_groups',
            'widget_allow_codes',
            'widget_reject_codes',
            'widget_allow_widgets',
            'widget_reject_widgets',
            'widget_allow_supports',
            'widget_reject_supports',
            'widget_allow_protocols',
            'widget_reject_protocols',
        ];

        $options = [];
        foreach ($keys as $key) {
            $codes = $this->normalizeSlotCodeParam($this->request->getParam($key, []));
            if ($codes !== []) {
                $options[$key] = $codes;
            }
        }

        return $options;
    }

    /**
     * Theme-level disk catalog + active state (整盘).
     */
    public function getThemeTokens()
    {
        try {
            $theme = $this->resolveEditorThemeFromRequest();
            if (!$theme || !(int)$theme->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('请选择主题')]);
            }
            $input = $this->getEditorJsonPayload();
            $area = $this->resolveRequestedEditorArea((string)($input['editor_area'] ?? 'frontend'));
            $context = $this->validateLegacyEditorContext(
                $input,
                ThemeEditorContext::RESOURCE_APPEARANCE,
                (int)$theme->getId(),
                $area,
            );
            $scope = $this->legacyScopeForEditorContext($context);
            /** @var \Weline\Theme\Service\Disk\ThemeDiskEditorService $editor */
            $editor = ObjectManager::getInstance(\Weline\Theme\Service\Disk\ThemeDiskEditorService::class);
            $payload = $editor->getTokensPayload($theme, $area, $scope);

            return $this->fetchJson(['success' => true, 'data' => $payload]);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getThemeDiskTokens()
    {
        try {
            $theme = $this->resolveEditorThemeFromRequest();
            if (!$theme || !(int)$theme->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('请选择主题')]);
            }
            $input = $this->getEditorJsonPayload();
            $area = $this->resolveRequestedEditorArea((string)($input['editor_area'] ?? 'frontend'));
            $context = $this->validateLegacyEditorContext(
                $input,
                ThemeEditorContext::RESOURCE_APPEARANCE,
                (int)$theme->getId(),
                $area,
            );
            $panel = (string)($input['panel'] ?? '');
            $ref = (string)($input['ref'] ?? '');
            /** @var \Weline\Theme\Service\Disk\ThemeTokenCatalogService $catalog */
            $catalog = ObjectManager::getInstance(\Weline\Theme\Service\Disk\ThemeTokenCatalogService::class);
            $tokens = $catalog->getDiskTokensForRef($area, $theme, $panel, $ref);

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'panel' => $panel,
                    'ref' => $ref,
                    'tokens_json' => \json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                ],
            ]);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function postDiskSave()
    {
        return $this->handleDiskSave(false);
    }

    public function postDiskSaveAs()
    {
        return $this->handleDiskSave(true);
    }

    public function postDiskSelect()
    {
        try {
            $theme = $this->resolveEditorThemeFromRequest();
            if (!$theme || !(int)$theme->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('请选择主题')]);
            }
            $data = $this->readJsonBody();
            $area = $this->resolveRequestedEditorArea((string)($data['editor_area'] ?? $this->request->getParam('editor_area', 'frontend')));
            $context = $this->validateLegacyEditorContext(
                $data,
                ThemeEditorContext::RESOURCE_APPEARANCE,
                (int)$theme->getId(),
                $area,
            );
            $panel = (string)($data['panel'] ?? 'color');
            $ref = (string)($data['ref'] ?? '');
            /** @var \Weline\Theme\Service\Disk\ThemeDiskEditorService $editor */
            $editor = ObjectManager::getInstance(\Weline\Theme\Service\Disk\ThemeDiskEditorService::class);
            $result = $editor->validateSelection($theme, $area, $panel, $ref);

            return $this->fetchJson(['success' => true, 'data' => $result, 'message' => __('色盘 Scope 草稿已校验')]);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function postDiskDelete()
    {
        try {
            $theme = $this->resolveEditorThemeFromRequest();
            if (!$theme || !(int)$theme->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('请选择主题')]);
            }
            $data = $this->readJsonBody();
            $area = $this->resolveRequestedEditorArea((string)($data['editor_area'] ?? $this->request->getParam('editor_area', 'frontend')));
            $context = $this->validateLegacyEditorContext(
                $data,
                ThemeEditorContext::RESOURCE_APPEARANCE,
                (int)$theme->getId(),
                $area,
            );
            $panel = (string)($data['panel'] ?? 'color');
            $diskKey = (string)($data['disk_key'] ?? '');
            /** @var \Weline\Theme\Service\Disk\ThemeDiskEditorService $editor */
            $editor = ObjectManager::getInstance(\Weline\Theme\Service\Disk\ThemeDiskEditorService::class);
            $result = $editor->validateDelete($area, $panel, $diskKey);

            return $this->fetchJson(['success' => true, 'data' => $result, 'message' => __('删除 Scope 草稿已校验')]);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function handleDiskSave(bool $forceNewKey)
    {
        try {
            $theme = $this->resolveEditorThemeFromRequest();
            if (!$theme || !(int)$theme->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('请选择主题')]);
            }
            $data = $this->readJsonBody();
            $area = $this->resolveRequestedEditorArea((string)($data['editor_area'] ?? $this->request->getParam('editor_area', 'frontend')));
            $context = $this->validateLegacyEditorContext(
                $data,
                ThemeEditorContext::RESOURCE_APPEARANCE,
                (int)$theme->getId(),
                $area,
            );
            $panel = (string)($data['panel'] ?? 'color');
            $name = trim((string)($data['name'] ?? ''));
            $baseFile = (string)($data['base_file'] ?? '');
            $tokens = is_array($data['tokens'] ?? null) ? $data['tokens'] : [];
            $diskKey = trim((string)($data['disk_key'] ?? ''));
            if ($forceNewKey || $diskKey === '') {
                $diskKey = 'disk-' . substr(bin2hex(random_bytes(4)), 0, 8);
            }
            if ($name === '') {
                $name = $diskKey;
            }
            /** @var \Weline\Theme\Service\Disk\ThemeDiskEditorService $editor */
            $editor = ObjectManager::getInstance(\Weline\Theme\Service\Disk\ThemeDiskEditorService::class);
            $result = $editor->prepareCustom($theme, $area, $panel, $diskKey, $name, $baseFile, $tokens);

            return $this->fetchJson([
                'success' => true,
                'data' => $result,
                'message' => $forceNewKey ? __('另存 Scope 草稿已校验') : __('主题盘 Scope 草稿已校验'),
            ]);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /** @param array<string,mixed> $input */
    private function validateLegacyEditorContext(
        array $input,
        string $resourceType,
        int $themeId,
        string $area,
    ): ThemeEditorContext {
        /** @var ThemeEditorContextFactory $factory */
        $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
        $context = $factory->fromInput($input, $resourceType);
        if ($context->themeId !== $themeId || $context->area !== $area) {
            throw new \InvalidArgumentException('theme_editor_legacy_context_mismatch');
        }

        return $context;
    }

    /** @param array<string,mixed> $input */
    private function requireLayoutWriteContext(
        array $input,
        ?int $expectedThemeId = null,
        ?string $expectedPageType = null,
    ): ThemeEditorContext {
        if (!array_key_exists('editor_context', $input)) {
            $requestContext = $this->request->getParam('editor_context', null);
            if ($requestContext !== null && $requestContext !== '') {
                $input['editor_context'] = $requestContext;
            }
        }
        /** @var ThemeEditorContextFactory $factory */
        $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
        $context = $factory->fromInput($input, ThemeEditorContext::RESOURCE_LAYOUT);
        $this->assertRawLayoutContextMatches($input, $context);
        if (($expectedThemeId !== null && $expectedThemeId > 0 && $context->themeId !== $expectedThemeId)
            || ($expectedPageType !== null && $expectedPageType !== '' && $context->layoutType !== $expectedPageType)
        ) {
            throw new \InvalidArgumentException('theme_editor_write_context_mismatch');
        }

        return $context;
    }

    /** @param array<string,mixed> $input */
    private function assertRawLayoutContextMatches(array $input, ThemeEditorContext $context): void
    {
        $checks = [
            'theme_id' => $context->themeId,
            'page_type' => $context->layoutType,
            'layout_type' => $context->layoutType,
            'layout_option' => $context->layoutOption,
            'editor_area' => $context->area,
            'preview_area' => $context->area,
            'target_type' => $context->targetType,
            'target_id' => $context->targetId,
            'theme_layout_target_type' => $context->targetType,
            'theme_layout_target_id' => $context->targetId,
            'theme_layout_source_target_type' => $context->targetType,
            'theme_layout_source_target_id' => $context->targetId,
            'virtual_target_type' => $context->targetType,
            'virtual_target_id' => $context->targetId,
            'layout_lock_target_type' => $context->targetType,
            'layout_lock_target_id' => $context->targetId,
        ];
        foreach ($checks as $field => $expected) {
            if (!array_key_exists($field, $input) || $input[$field] === '' || $input[$field] === null) {
                continue;
            }
            $actual = is_int($expected) ? (int)$input[$field] : trim((string)$input[$field]);
            if ($actual !== $expected) {
                throw new \InvalidArgumentException('theme_editor_raw_context_mismatch:' . $field);
            }
        }
        // Top-level locale/locale_code on widget-config / layout-config is the
        // translation target, not the editor identity. Identity locale lives in
        // editor_context only, so do not compare these fields here.
        if (array_key_exists('scope', $input)
            && trim((string)$input['scope']) !== ''
            && trim((string)$input['scope']) !== $this->legacyScopeForEditorContext($context)
        ) {
            throw new \InvalidArgumentException('theme_editor_raw_context_mismatch:scope');
        }
    }

    /** @return array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string} */
    private function layoutIdentityFromEditorContext(ThemeEditorContext $context): array
    {
        return [
            'layout_option' => $context->layoutOption,
            'scope' => $this->legacyScopeForEditorContext($context),
            'target_type' => $context->targetType,
            'target_id' => $context->targetId,
            'locale_code' => $context->locale === 'default' ? '' : $context->locale,
        ];
    }

    private function assertLayoutBelongsToEditorContext(
        ThemeLayout $layout,
        ThemeEditorContext $context,
    ): void {
        $identity = $this->layoutIdentityFromEditorContext($context);
        // Layout rows are stored under default locale identity; i18n overlays
        // live in scoped workspaces keyed by translation locale.
        if ($context->resourceType === ThemeEditorContext::RESOURCE_I18N) {
            $identity['locale_code'] = '';
        }
        $matches = (int)$layout->getData(ThemeLayout::schema_fields_THEME_ID) === $context->themeId
            && (string)$layout->getData(ThemeLayout::schema_fields_PAGE_TYPE) === $context->layoutType
            && (string)$layout->getData(ThemeLayout::schema_fields_LAYOUT_OPTION) === $identity['layout_option']
            && (string)$layout->getData(ThemeLayout::schema_fields_SCOPE) === $identity['scope']
            && (string)$layout->getData(ThemeLayout::schema_fields_LOCALE_CODE) === $identity['locale_code']
            && (string)$layout->getData(ThemeLayout::schema_fields_TARGET_TYPE) === $identity['target_type']
            && (int)$layout->getData(ThemeLayout::schema_fields_TARGET_ID) === $identity['target_id'];
        if (!$matches) {
            throw new \InvalidArgumentException('theme_editor_layout_context_mismatch');
        }
    }

    private function assertDraftLayout(ThemeLayout $layout): void
    {
        if ($layout->getStatus() !== ThemeLayout::STATUS_DRAFT) {
            throw new \InvalidArgumentException('theme_editor_published_layout_write_forbidden');
        }
    }

    /** @param array<string,mixed> $input */
    private function hasScopedReleasePublishedClaim(array $input): bool
    {
        $value = $input['scoped_release_published'] ?? null;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function assertCurrentScopedLayoutPublished(ThemeEditorContext $context): void
    {
        /** @var ThemeScopedWorkspaceInterface $workspace */
        $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
        $state = $workspace->load($context->withResource(ThemeEditorContext::RESOURCE_LAYOUT), true);
        $publishedReleaseId = (int)($state['published_release_id'] ?? 0);
        $draftRevisionId = (int)($state['draft_revision_id'] ?? 0);
        $publishedRevisionId = (int)($state['published_revision_id'] ?? 0);
        if ($draftRevisionId <= 0) {
            return;
        }
        if ($publishedReleaseId <= 0
            || $draftRevisionId !== $publishedRevisionId
            || (string)($state['status'] ?? '') !== 'active'
        ) {
            throw new \RuntimeException('theme_scoped_layout_publish_receipt_invalid');
        }
    }

    /** @param array<string,mixed> $input */
    private function resolveEditorLockContextKey(array $input, int $themeId, string $pageType): string
    {
        /** @var ThemeEditorContextFactory $factory */
        $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
        $context = $factory->fromInput($input, ThemeEditorContext::RESOURCE_LAYOUT);
        if ($context->themeId !== $themeId || $context->layoutType !== $pageType) {
            throw new \InvalidArgumentException('theme_editor_lock_context_mismatch');
        }

        return $context->canonicalKey();
    }

    private function legacyScopeForEditorContext(ThemeEditorContext $context): string
    {
        /** @var ThemeLayoutScopeNormalizer $normalizer */
        $normalizer = ObjectManager::getInstance(ThemeLayoutScopeNormalizer::class);

        return $normalizer->encodeStorageScope(
            $context->scope->storageScope,
            $context->scope->storeMode,
        );
    }

    private function readJsonBody(): array
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_array($bodyParams) && $bodyParams !== []) {
            return $bodyParams;
        }
        $params = $this->request->getParams();

        return is_array($params) ? $params : [];
    }
}
