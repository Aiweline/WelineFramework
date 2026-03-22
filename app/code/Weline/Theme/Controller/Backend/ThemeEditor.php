<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Url;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\EditorLockService;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewNavigationResolver;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Service\WidgetPositionResolver;
use Weline\Widget\Service\WidgetRegistry;
use Weline\Theme\Helper\PreviewManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Meta\Model\Meta;

/**
 * 主题编辑器控制器
 */
class ThemeEditor extends BackendController
{
    private WelineTheme $welineTheme;
    private ThemeLayoutService $layoutService;
    private ThemeLayoutVersionService $versionService;
    private ThemeCacheGenerator $cacheGenerator;
    private WidgetPositionResolver $positionResolver;
    private WidgetRegistry $widgetRegistry;
    private ThemeLayout $themeLayout;
    private Meta $meta;
    private PreviewTokenService $previewTokenService;
    private EditorLockService $editorLockService;

    public function __construct(
        WelineTheme $welineTheme,
        ThemeLayoutService $layoutService,
        ThemeLayoutVersionService $versionService,
        ThemeCacheGenerator $cacheGenerator,
        WidgetPositionResolver $positionResolver,
        WidgetRegistry $widgetRegistry,
        ThemeLayout $themeLayout,
        Meta $meta,
        PreviewTokenService $previewTokenService,
        EditorLockService $editorLockService
    ) {
        $this->welineTheme = $welineTheme;
        $this->layoutService = $layoutService;
        $this->versionService = $versionService;
        $this->cacheGenerator = $cacheGenerator;
        $this->positionResolver = $positionResolver;
        $this->widgetRegistry = $widgetRegistry;
        $this->themeLayout = $themeLayout;
        $this->meta = $meta;
        $this->previewTokenService = $previewTokenService;
        $this->editorLockService = $editorLockService;
    }

    /**
     * 清除全页面缓存（FPC）
     * 发布主题后必须调用，否则前端仍然显示旧的缓存 HTML。
     * 
     * flush() 会自动触发 Weline_Framework_Cache::integration::cache_flushed 事件，
     * Server 模块监听该事件并通知 WLS Worker 重载内存缓存，无需手动处理 WLS 通知。
     */
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

    private function flushFullPageCache(): void
    {
        try {
            $routerCache = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Router\Cache\RouterCache::class . 'Factory'
            );
            $routerCache->flush();
        } catch (\Throwable $e) {
            // FPC 清理失败不阻塞发布流程
        }
    }

    /**
     * 编辑器主页
     */
    public function index()
    {
        $previewContextService = $this->getPreviewContextService();
        $themeContextService = $this->getThemeContextService();
        $requestedThemeId = (int)$this->request->getParam('theme_id', 0);
        $requestedFrontendThemeId = (int)$this->request->getParam(
            'frontend_theme_id',
            (int)$this->request->getParam('preview_theme', $requestedThemeId)
        );
        $requestedBackendThemeId = (int)$this->request->getParam('backend_theme_id', 0);
        $pageType = (string)$this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);
        $editorArea = $previewContextService->normalizeArea(
            (string)$this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND)
        );
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
            'scope' => (string)$this->request->getParam('scope', PreviewContextService::DEFAULT_SCOPE),
            'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
            'target_value' => $pageType,
        ]);
        $context = $previewContextService->ensureThemeIds($context, true, true);
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
        if ($currentThemeId) {
            $hasDraft = $this->layoutService->hasDraft($currentThemeId, $pageType);
            if (!$hasDraft) {
                $this->layoutService->initDraftFromPublished($currentThemeId, $pageType);
            }
            $layout = $this->layoutService->getFullDraftLayout($currentThemeId, $pageType);
        }

        $availableWidgets = $this->layoutService->getAvailableWidgets(
            $pageType,
            $editorArea === PreviewContextService::AREA_BACKEND ? ['area' => PreviewContextService::AREA_BACKEND] : []
        );
        $availableWidgets = $this->attachWidgetPreviewHtml($availableWidgets);

        $this->assign('theme_id', $currentThemeId);
        $this->assign('theme', $currentTheme);
        $this->assign('current_theme', $currentTheme);
        $this->assign('frontend_theme', $frontendTheme);
        $this->assign('backend_theme', $backendTheme);
        $this->assign('frontend_theme_id', $frontendThemeId);
        $this->assign('backend_theme_id', $backendThemeId);
        $this->assign('themes', $themes);
        $this->assign('page_type', $pageType);
        $this->assign('page_types', ThemeLayout::getPageTypes());
        $this->assign('areas', ThemeLayout::getAreas());
        $this->assign('editor_area', $editorArea);
        $this->assign('theme_has_backend', $frontendHasBackend || $backendThemeId > 0);
        $this->assign('preview_context', $context);
        $this->assign('layout', $layout);
        $this->assign('available_widgets', $availableWidgets);
        $this->assign('has_draft', $hasDraft);

        return $this->fetch('Weline_Theme::templates/backend/ThemeEditor/index.phtml');
    }

    public function legacyIndex()
    {
        $requestedThemeId = (int)$this->request->getParam('theme_id', 0);
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);
        $editorArea = (string)$this->request->getParam('editor_area', 'frontend');
        $editorArea = $editorArea === 'backend' ? 'backend' : 'frontend';
        
        // 获取主题列表页面 URL（用于重定向）
        $themeListUrl = $this->_url->getBackendUrl('theme/backend');

        // 如果明确传入了 theme_id，必须验证其存在性
        if ($requestedThemeId > 0) {
            $this->welineTheme->reset()->load($requestedThemeId);
            if (!$this->welineTheme->getId()) {
                // 传入的主题 ID 不存在，报错并重定向到主题列表
                $this->getMessageManager()->addError(__('主题 ID %{1} 不存在！请选择有效的主题。', $requestedThemeId));
                return $this->redirect($themeListUrl);
            }
            $themeId = $requestedThemeId;
        } else {
            // 没有指定主题，尝试使用当前激活的主题
            $activeTheme = $this->welineTheme->getActiveTheme($editorArea);
            if ($activeTheme && $activeTheme->getId()) {
                $themeId = (int)$activeTheme->getId();
                $this->welineTheme->reset()->load($themeId);
            } else {
                // 没有激活的主题，报错并重定向到主题列表
                $this->getMessageManager()->addError(__('系统没有激活的主题！请先选择或激活一个主题。'));
                return $this->redirect($themeListUrl);
            }
        }
        
        // 双重检查：确保加载的主题有效
        if (!$this->welineTheme->getId()) {
            $this->getMessageManager()->addError(__('无法加载主题！请检查主题配置。'));
            return $this->redirect($themeListUrl);
        }
        
        // 获取所有主题列表（按 id 去重，避免重复显示）
        $themesCollection = $this->welineTheme->reset()->select()->fetch()->getItems();
        $themesById = [];
        foreach ($themesCollection as $themeItem) {
            $data = is_object($themeItem) ? $themeItem->getData() : (is_array($themeItem) ? $themeItem : []);
            $tid = (int)($data['id'] ?? 0);
            if ($tid && !isset($themesById[$tid])) {
                $themesById[$tid] = $data;
            }
        }
        $themes = array_values($themesById);

        // 获取布局数据（编辑器读取草稿数据）
        $layout = [];
        $hasDraft = false;
        if ($themeId) {
            // 检查是否有草稿，如果没有则从已发布数据初始化草稿
            $hasDraft = $this->layoutService->hasDraft($themeId, $pageType);
            if (!$hasDraft) {
                // 首次编辑，从已发布数据初始化草稿
                $this->layoutService->initDraftFromPublished($themeId, $pageType);
            }
            // 读取草稿布局
            $layout = $this->layoutService->getFullDraftLayout($themeId, $pageType);
        }

        // 获取可用部件列表
        $availableWidgets = $this->layoutService->getAvailableWidgets();
        // 预编译部件预览 HTML（用于部件库快速预览）
        $availableWidgets = $this->attachWidgetPreviewHtml($availableWidgets);

        // 页面类型列表
        $pageTypes = ThemeLayout::getPageTypes();

        // 区域列表
        $areas = ThemeLayout::getAreas();

        // 编辑区域：默认前端；若主题目录无 backend 则仅前端
        $themeHasBackend = $this->themeHasBackendDir($this->welineTheme);
        if (!$themeHasBackend || ($editorArea !== 'frontend' && $editorArea !== 'backend')) {
            $editorArea = 'frontend';
        }

        $this->assign('theme_id', $themeId);
        $this->assign('theme', $this->welineTheme);
        $this->assign('themes', $themes);
        $this->assign('page_type', $pageType);
        $this->assign('page_types', $pageTypes);
        $this->assign('areas', $areas);
        $this->assign('editor_area', $editorArea);
        $this->assign('theme_has_backend', $themeHasBackend);
        $this->assign('layout', $layout);
        $this->assign('available_widgets', $availableWidgets);
        $this->assign('has_draft', $hasDraft);

        return $this->fetch('Weline_Theme::templates/backend/ThemeEditor/index.phtml');
    }

    /**
     * 获取布局数据 (AJAX) - 读取草稿数据
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

        // 检查是否有草稿，如果没有则从已发布数据初始化
        if (!$this->layoutService->hasDraft($themeId, $pageType)) {
            $this->layoutService->initDraftFromPublished($themeId, $pageType);
        }

        // 读取草稿布局
        $layout = $this->layoutService->getFullDraftLayout($themeId, $pageType);

        return $this->fetchJson([
            'success' => true,
            'data' => $layout,
            'has_draft' => $this->layoutService->hasDraft($themeId, $pageType),
        ]);
    }

    /**
     * 获取部件列表 (AJAX)
     * 
     * 参数：
     * - page_type: 页面类型（可选），用于过滤部件
     */
    public function getWidgets()
    {
        $pageType = $this->request->getParam('page_type', null);
        $filterOptions = ['area' => 'backend'];

        $widgets = $this->layoutService->getAvailableWidgets($pageType, $filterOptions);

        return $this->fetchJson([
            'success' => true,
            'data' => $widgets,
            'page_type' => $pageType,
        ]);
    }
    
    /**
     * 获取指定 slot 的推荐部件 (AJAX)
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
        
        if (empty($slotId)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少 slot_id 参数'),
            ]);
        }
        
        // 获取精细筛选的部件
        $result = $this->layoutService->getWidgetsForSlot($slotId, $area, $pageType);
        
        // 预编译预览 HTML
        if (!empty($result['exclusive_widgets'])) {
            foreach ($result['exclusive_widgets'] as &$widget) {
                $widget['preview_html'] = $this->buildWidgetPreviewHtml($widget);
            }
        }
        if (!empty($result['regular_widgets'])) {
            foreach ($result['regular_widgets'] as &$widget) {
                $widget['preview_html'] = $this->buildWidgetPreviewHtml($widget);
            }
        }
        if (!empty($result['matched_widgets'])) {
            foreach ($result['matched_widgets'] as &$widget) {
                $widget['preview_html'] = $this->buildWidgetPreviewHtml($widget);
            }
        }
        
        return $this->fetchJson([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * 保存部件 (AJAX)
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
        $keys = ['theme_id', 'area', 'widget_code', 'widget_module', 'widget_type', 'page_type', 'slot_id', 'config'];
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
            'header', 'logo', 'search', 'navigation',
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
            $layoutId = $this->layoutService->saveWidget($data);

            $response = [
                'success' => true,
                'message' => __('保存成功'),
                'data' => ['layout_id' => $layoutId],
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
     * 更新部件配置 (AJAX)
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

        try {
            $result = $this->layoutService->updateWidgetConfig($layoutId, $config);

            $response = [
                'success' => $result,
                'message' => $result ? __('配置已保存') : __('保存失败'),
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
     * 删除部件 (AJAX)
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
        
        $layoutId = (int)($data['layout_id'] ?? $this->request->getParam('layout_id', 0));
        $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));

        if (!$layoutId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少布局ID'),
            ]);
        }

        try {
            // 获取要删除的部件信息（在删除前）。不对 clearQuery() 链式调用 load()，避免 clearQuery() 返回 Query 时在 Query 上调用 load() 导致致命错误。
            $this->themeLayout->load($layoutId);
            $widget = $this->themeLayout;
            $slotId = $widget->getData('slot_id');
            $pageType = $widget->getData('page_type');
            $area = $widget->getData('area');
            
            // 如果 DB 记录不存在，使用前端提供的 fallback 数据
            $recordExists = !empty($widget->getLayoutId());
            if (!$recordExists) {
                $slotId = $data['slot_id'] ?? null;
                $area = $data['area'] ?? 'content';
                $pageType = $data['layout_type'] ?? 'homepage';
            }
            
            // 尝试删除部件（如果记录存在）
            $result = $recordExists ? $this->layoutService->deleteWidget($layoutId) : true;
            
            // 删除后清除插槽渲染缓存，否则 getOriginalSlotContent 会读到旧 layout 缓存，返回仍含已删部件的内容
            if ($result) {
                ObjectManager::getInstance(SlotRendererService::class)->clearCache();
            }
            
            $response = [
                'success' => $result,
                'message' => $result ? __('删除成功') : __('删除失败'),
                'slot_id' => $slotId,
            ];
            
            // 获取插槽的原始内容（无论记录是否存在，只要有足够信息就尝试恢复）
            if ($result && $themeId && $slotId) {
                $layoutType = $data['layout_type'] ?? $this->request->getParam('layout_type', 'homepage');
                $layoutOption = $data['layout_option'] ?? $this->request->getParam('layout_option', 'default');
                $originalHtml = $this->getOriginalSlotContent($themeId, $pageType, $slotId, $area, $layoutType, $layoutOption);
                $response['original_html'] = $originalHtml;
                $response['has_original'] = !empty($originalHtml);
            }
            
            return $this->fetchJson($response);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
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
        
        if (!$themeId || empty($slotIds)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }
        
        try {
            $deletedCount = 0;
            
            // 批量删除指定插槽的所有部件（包括 draft 和 published）
            foreach ($slotIds as $slotId) {

                // 先验证目标数据存在
                $existsBefore = $this->themeLayout->clearQuery()
                    ->where('theme_id', $themeId)
                    ->where('slot_id', $slotId)
                    ->select()
                    ->fetchArray();


                $this->themeLayout->clearQuery()
                    ->where('theme_id', $themeId)
                    ->where('slot_id', $slotId)
                    ->delete()
                    ->fetch();

                // 验证删除后是否还存在
                $existsAfter = $this->themeLayout->clearQuery()
                    ->where('theme_id', $themeId)
                    ->where('slot_id', $slotId)
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
     * 移动部件 (AJAX)
     */
    public function postMoveWidget()
    {
        $layoutId = (int)$this->request->getParam('layout_id');
        $newArea = $this->request->getParam('area');
        $sortOrder = (int)$this->request->getParam('sort_order', 0);

        if (!$layoutId || !$newArea) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }

        try {
            $result = $this->layoutService->moveWidget($layoutId, $newArea, $sortOrder);

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('移动成功') : __('移动失败'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 更新排序 (AJAX)
     */
    public function postUpdateSort()
    {
        // 尝试从 JSON body 获取参数
        $bodyParams = $this->request->getBodyParams();
        $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);
        $sortData = $body['sort_data'] ?? $this->request->getParam('sort_data', []);

        if (empty($sortData)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('排序数据为空'),
            ]);
        }

        try {
            $result = $this->layoutService->updateSortOrder($sortData);

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('排序已更新') : __('更新失败'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 交换两个部件的排序 (AJAX)
     */
    public function postSwapWidgetOrder()
    {
        // 尝试从 JSON body 获取参数
        $bodyParams = $this->request->getBodyParams();
        $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);
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
            $result = $this->layoutService->swapWidgetOrder($layoutId1, $layoutId2);

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('位置已交换') : __('交换失败'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 保存完整布局 (AJAX)
     */
    public function postSaveLayout()
    {
        $themeId = (int)$this->request->getParam('theme_id');
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);
        $layoutData = $this->request->getParam('layout_data', []);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请选择主题'),
            ]);
        }

        try {
            $result = $this->layoutService->saveLayout($themeId, $pageType, $layoutData);

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('布局已保存') : __('保存失败'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取部件位置信息 (AJAX)
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

    /**
     * 恢复原始布局（旧 API - 已废弃，保留兼容性）
     * 
     * @deprecated 使用 postRestoreOriginal() 代替，支持版本控制和自动备份
     */
    public function postRestoreLayout()
    {
        // 委托给新的恢复原始布局 API
        return $this->postRestoreOriginal();
    }

    public function postPublish()
    {
        $themeId = (int)$this->request->getParam('theme_id');
        $pageType = $this->request->getParam('page_type'); // 可选，null表示发布所有页面类型

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请选择主题'),
            ]);
        }

        try {
            // 1. 发布前清理：删除草稿中的孤儿部件（slot_id 指向不存在的插槽）
            $orphansCleaned = $this->layoutService->cleanOrphanWidgets($themeId, $pageType);
            
            // 2. 将草稿发布为正式版（复制 draft -> published，含去重）
            $publishResult = $this->layoutService->publishLayout($themeId, $pageType);
            if (!$publishResult) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('发布布局失败'),
                ]);
            }

            // 3. 清除旧缓存（主题生成缓存）
            $this->cacheGenerator->clearCache($themeId);

            // 4. 清除全页面缓存（FPC）— 布局变更后旧的缓存 HTML 必须失效
            $this->flushFullPageCache();

            // 5. 生成新缓存
            $cacheResult = $this->cacheGenerator->generate($themeId);

            $message = $cacheResult ? __('主题已发布') : __('生成缓存失败，但布局已发布');
            if ($orphansCleaned > 0) {
                $message .= ' ' . __('（已清理 %{count} 个无效部件）', ['count' => $orphansCleaned]);
            }

            return $this->fetchJson([
                'success' => $cacheResult,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 撤销草稿 (AJAX) - 放弃所有未发布的修改
     */
    public function postDiscardDraft()
    {
        $themeId = (int)$this->request->getParam('theme_id');
        $pageType = $this->request->getParam('page_type'); // 可选

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请选择主题'),
            ]);
        }

        try {
            $result = $this->layoutService->discardDraft($themeId, $pageType);

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
     * 预览页面 - 读取草稿数据进行预览
     */
    public function preview()
    {
        $themeId = (int)$this->request->getParam('theme_id');
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);

        if (!$themeId) {
            return $this->fetch('Weline_Theme::templates/backend/ThemeEditor/preview-empty.phtml');
        }

        // 获取草稿布局数据
        $layout = $this->layoutService->getFullDraftLayout($themeId, $pageType);

        $this->assign('theme_id', $themeId);
        $this->assign('page_type', $pageType);
        $this->assign('layout', $layout);
        $this->assign('areas', ThemeLayout::getAreas());
        $this->assign('preview_mode', true); // 标记为预览模式

        return $this->fetch('Weline_Theme::templates/backend/ThemeEditor/preview.phtml');
    }

    /**
     * 渲染单个部件 (AJAX) - 用于实时预览
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

        // 如果提供了 layout_id，从数据库获取配置
        if ($layoutId) {
            $layoutData = $this->layoutService->getWidgetByLayoutId($layoutId);
            if ($layoutData) {
                $widgetModule = $layoutData['widget_module'] ?? $widgetModule;
                $widgetCode = $layoutData['widget_code'] ?? $widgetCode;
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
                    'area' => 'frontend',
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
        $widgetMeta = $this->findWidgetMetaByModuleAndCode($widgetModule, $widgetCode);
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

        if (!$widgetModule || !$widgetCode) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少部件参数'),
            ]);
        }

        $widgetMeta = $this->findWidgetMetaByModuleAndCode($widgetModule, $widgetCode);
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
                    'area' => 'frontend',
                ],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_Widget::query', $eventData);
        $html = $eventData['data']['result'] ?? '';
        $err = $eventData['data']['error'] ?? null;
        if ($err !== null && $err !== '') {
            return $this->fetchJson([
                'success' => true,
                'html' => '<div class="widget-preview-error">' . htmlspecialchars((string)$err) . '</div>',
                'widget' => $widgetMeta,
            ]);
        }
        return $this->fetchJson([
            'success' => true,
            'html' => is_string($html) ? $html : '<div class="widget-preview-placeholder">' . htmlspecialchars((string)($widgetMeta['name'] ?? $widgetCode ?? '')) . '</div>',
            'widget' => $widgetMeta,
        ]);
    }

    /**
     * 获取已安装语言列表（含 SVG 国旗）
     *
     * 通过 Weline_I18n::query 查询器获取，由 I18n 模块统一提供。
     *
     * @return array JSON 响应 { success, locales: [ { code, name, flag }, ... ] }
     */
    public function getInstalledLocales()
    {
        $eventData = [
            'data' => [
                'operation' => 'getInstalledLocales',
                'params' => [],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_I18n::query', $eventData);

        $error = $eventData['data']['error'] ?? '';
        if ($error !== '') {
            return $this->fetchJson([
                'success' => false,
                'message' => $error,
                'locales' => [],
            ]);
        }

        $locales = $eventData['data']['result'] ?? [];
        if (!is_array($locales)) {
            $locales = [];
        }

        return $this->fetchJson([
            'success' => true,
            'locales' => $locales,
        ]);
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
        $area = $widgetLayout->getData('area') ?: 'frontend';
        
        // 通过 Weline_Widget::query 获取参数定义
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
        $params = $eventData['data']['result'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }
        if (empty($params)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('该部件没有配置项'),
            ]);
        }
        
        $identify = $this->resolveThemeConfigIdentify($widgetModule, $widgetType, $widgetCode, $area);

        // 以 layout 已保存配置为 base（保证选择器等非翻译字段刷新后回填正确）
        $config = $widgetLayout->getWidgetConfig();
        if (!is_array($config)) {
            $config = [];
        }

        // 按 locale 合并可翻译路径（顶级 + 数组子字段）到 base
        $config = ThemeData::mergeTranslatedPaths($config, $params, $identify, $locale);

        return $this->fetchJson([
            'success' => true,
            'data' => [
                'layout_id' => $layoutId,
                'widget_module' => $widgetModule,
                'widget_type' => $widgetType,
                'widget_code' => $widgetCode,
                'params' => $params,
                'config' => $config,
                'locale' => $locale,
            ],
        ]);
    }
    
    /**
     * 保存部件配置 (POST)
     * 
     * 支持多语言：传递 locale 参数保存特定语言的配置值
     * 
     * @return array JSON响应
     */
    public function postSaveWidgetConfig()
    {
        $layoutId = (int)$this->request->getParam('layout_id', 0);
        $configData = $this->request->getParam('config', []);
        $locale = $this->request->getParam('locale', null); // null表示保存为默认值
        
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
        $area = $widgetLayout->getData('area') ?: 'frontend';
        
        try {
            // 获取参数定义以识别可翻译路径
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
            if (!is_array($paramDefs)) {
                $paramDefs = [];
            }

            $identify = $this->resolveThemeConfigIdentify($widgetModule, $widgetType, $widgetCode, $area);

            // 分离路径 key（如 slides.0.title）与普通 key，同时写入翻译存储
            $normalConfig = ThemeData::saveTranslatablePaths($configData, $paramDefs, $identify, $locale);

            if ($locale === null) {
                // 默认语言：将完整普通 config 写入 m_theme_layout.config
                $widgetLayout->setData('config', json_encode($normalConfig));
                $widgetLayout->save();

                // 普通（非路径、非翻译）参数也写入 ThemeData 以保持兼容
                $this->persistThemeDefaultConfig($widgetModule, $widgetType, $widgetCode, $normalConfig, null, $area);
            } else {
                // 特定语言：只写翻译层（普通可翻译字段）
                $this->persistThemeDefaultConfig($widgetModule, $widgetType, $widgetCode, $normalConfig, $locale, $area);
            }

            return $this->fetchJson([
                'success' => true,
                'message' => $locale ? __('已保存 %{locale} 语言的配置', ['locale' => $locale]) : __('配置已保存'),
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
        $previewContextService = $this->getPreviewContextService();
        $editorArea = $previewContextService->normalizeArea(
            (string)$this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND)
        );
        $layoutType = (string)$this->request->getParam('layout_type', 'homepage');
        $layoutOption = (string)$this->request->getParam('layout_option', 'default');
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
            return $this->fetchJson([
                'success' => false,
                'message' => __('Missing theme ID'),
            ]);
        }

        $session = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
        $session->setData('preview_theme_id', $themeId);
        $session->setData('preview_theme_area', $editorArea);

        try {
            $this->welineTheme->load($themeId);
            if (!$this->welineTheme->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Theme not found'),
                ]);
            }

            $templatePath = "Weline_Theme::theme/{$editorArea}/layouts/{$layoutType}/{$layoutOption}.phtml";
            $this->assign('editor_mode', true);
            $this->assign('theme_id', $themeId);
            $this->assign('layout_type', $layoutType);
            $this->assign('preview_context', $context);
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

            $html = $this->fetchTagHtml($templatePath);
            $slots = $this->extractSlots($html);

            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'slots' => $slots,
                'layout' => [
                    'type' => $layoutType,
                    'option' => $layoutOption,
                ],
                'context' => $context,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function legacyGetCompileLayout()
    {
        return $this->getCompileLayout();
    }
/*
        $themeId = (int)$this->request->getParam('theme_id');
        $layoutType = $this->request->getParam('layout_type', 'homepage');
        $layoutOption = $this->request->getParam('layout_option', 'default');

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        // 设置预览主题到 session，让 TemplateFetchFile Observer 能识别当前编辑的主题
        // 注意：必须使用 ObjectManager::getInstance(Session::class) 与 Observer 保持一致
        $session = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
        $session->setData('preview_theme_id', $themeId);
        $session->setData('preview_theme_area', 'frontend');

        try {
            // 获取主题信息
            $this->welineTheme->load($themeId);
            if (!$this->welineTheme->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('主题不存在'),
                ]);
            }

            // 构建布局模板路径
            $templatePath = "Weline_Theme::theme/frontend/layouts/{$layoutType}/{$layoutOption}.phtml";

            // 编译模板并获取 HTML
            // 设置编辑模式标记
            $this->assign('editor_mode', true);
            $this->assign('theme_id', $themeId);
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

            $html = $this->fetchTagHtml($templatePath);

            // 提取所有插槽信息
            $slots = $this->extractSlots($html);

            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'slots' => $slots,
                'layout' => [
                    'type' => $layoutType,
                    'option' => $layoutOption,
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
     * 从 HTML 中提取插槽信息
     * 同时支持旧格式（widget-slot-area + data-slot-id）和新格式（data-wslot），
     * 否则 footer/header 等仅使用 data-wslot 的插槽不会被返回，导致 footer 布局异常。
     */
    private function extractSlots(string $html): array
    {
        $slots = [];

        // 1. 新格式：data-wslot（header、footer 及子插槽 footer-links 等均用此格式）
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
                $acceptAttr = $element->getAttribute('data-wslot-accept') ?: $element->getAttribute('data-slot-accept') ?: '';
                $slots[$slotId] = [
                    'id' => $slotId,
                    'name' => $element->getAttribute('data-wslot-name') ?: $element->getAttribute('data-slot-name') ?: $slotId,
                    'accept' => array_values(array_filter(array_map('trim', explode(',', $acceptAttr)))),
                    'exclusive' => ($element->getAttribute('data-wslot-exclusive') ?: $element->getAttribute('data-slot-exclusive')) === 'true',
                    'multiple' => ($element->getAttribute('data-wslot-multiple') ?: $element->getAttribute('data-slot-multiple')) !== 'false',
                    'position' => $element->getAttribute('data-wslot-position') ?: $element->getAttribute('data-slot-position') ?: '',
                ];
            }
        }

        // 2. 旧格式：widget-slot-area + data-slot-id（不覆盖已从 data-wslot 提取的插槽）
        $pattern = '/<[^>]+class="[^"]*widget-slot-area[^"]*"[^>]*data-slot-id="([^"]+)"[^>]*>/i';
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = $match[0];
                $slotId = $match[1];
                if (isset($slots[$slotId])) {
                    continue;
                }
                $slot = ['id' => $slotId];
                if (preg_match('/data-slot-name="([^"]+)"/', $fullTag, $m)) {
                    $slot['name'] = $m[1];
                }
                if (preg_match('/data-slot-accept="([^"]+)"/', $fullTag, $m)) {
                    $slot['accept'] = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
                } else {
                    $slot['accept'] = [];
                }
                if (preg_match('/data-slot-position="([^"]+)"/', $fullTag, $m)) {
                    $slot['position'] = $m[1];
                }
                if (preg_match('/data-slot-multiple="([^"]+)"/', $fullTag, $m)) {
                    $slot['multiple'] = $m[1] === 'true';
                }
                $slots[$slotId] = $slot;
            }
        }

        return $slots;
    }

    /**
     * 为部件库预编译预览 HTML
     */
    private function attachWidgetPreviewHtml(array $availableWidgets): array
    {
        foreach ($availableWidgets as $type => &$group) {
            if (empty($group['widgets']) || !is_array($group['widgets'])) {
                continue;
            }
            foreach ($group['widgets'] as &$widget) {
                if (!is_array($widget)) {
                    continue;
                }
                $widget['preview_html'] = $this->buildWidgetPreviewHtml($widget);
            }
        }

        return $availableWidgets;
    }

    /**
     * 使用默认参数渲染部件预览 HTML
     */
    private function buildWidgetPreviewHtml(array $widget): string
    {
        $widgetModule = (string)($widget['module'] ?? $widget['widget_module'] ?? '');
        $widgetType = (string)($widget['type'] ?? $widget['widget_type'] ?? '');
        $widgetCode = (string)($widget['code'] ?? $widget['widget_code'] ?? '');
        if ($widgetModule === 'Weline_Theme' && ($widgetType === 'theme_component' || str_contains($widgetCode, '/'))) {
            /** @var \Weline\Theme\Service\ThemePlaceableRegistry $placeableRegistry */
            $placeableRegistry = ObjectManager::getInstance(\Weline\Theme\Service\ThemePlaceableRegistry::class);
            $html = $placeableRegistry->renderPreview('Weline_Theme', 'theme_component', $widgetCode, [], null, 'frontend');
            if ($html !== '') {
                return $this->sanitizeWidgetPreviewHtml($html);
            }
        }

        $template = $widget['template'] ?? '';
        if (!$template) {
            $name = $widget['name'] ?? $widget['code'] ?? '';
            return '<div class="widget-preview-placeholder">' . htmlspecialchars((string)$name) . '</div>';
        }

        $defaultConfig = [];
        foreach ($widget['params'] ?? [] as $key => $param) {
            if (!is_array($param)) {
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
            return $this->sanitizeWidgetPreviewHtml(is_string($html) ? $html : '');
        } catch (\Exception $e) {
            return '<div class="widget-preview-error">' . htmlspecialchars((string)$e->getMessage()) . '</div>';
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
            'logo', 'search', 'main-nav', 'user-area', 'cart', 'language', 'currency',
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
     * @return string|null 预览 HTML，失败返回 null
     */
    private function buildPreviewHtmlForLayoutId(int $layoutId, array $config = []): ?string
    {
        // 获取部件信息
        $layoutData = $this->layoutService->getWidgetByLayoutId($layoutId);
        if (!$layoutData) {
            return null;
        }

        $widgetModule = $layoutData['widget_module'] ?? '';
        $widgetCode = $layoutData['widget_code'] ?? '';

        if (!$widgetModule || !$widgetCode) {
            return null;
        }

        $eventData = [
            'data' => [
                'operation' => 'preview',
                'params' => [
                    'widget_module' => $widgetModule,
                    'widget_code' => $widgetCode,
                    'config' => array_merge($layoutData['config'] ?? [], $config),
                    'area' => 'frontend',
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
     * 按 module + code 从注册表查找部件元数据
     */
    private function findWidgetMetaByModuleAndCode(string $widgetModule, string $widgetCode): ?array
    {
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
                    return $widget;
                }
            }
        }

        if ($widgetModule === 'Weline_Theme' && str_contains($widgetCode, '/')) {
            /** @var \Weline\Theme\Service\ThemePlaceableRegistry $placeableRegistry */
            $placeableRegistry = ObjectManager::getInstance(\Weline\Theme\Service\ThemePlaceableRegistry::class);
            $definition = $placeableRegistry->find($widgetModule, 'theme_component', $widgetCode, null, 'frontend');
            if ($definition) {
                return $definition->toWidgetArray();
            }
        }

        return null;
    }

    private function resolveThemeConfigIdentify(string $widgetModule, string $widgetType, string $widgetCode, string $area): string
    {
        if ($widgetModule === 'Weline_Theme' && ($widgetType === 'theme_component' || str_contains($widgetCode, '/'))) {
            /** @var ThemePlaceableRegistry $placeableRegistry */
            $placeableRegistry = ObjectManager::getInstance(ThemePlaceableRegistry::class);
            $definition = $placeableRegistry->find($widgetModule, 'theme_component', $widgetCode, null, $area);
            if ($definition) {
                return $definition->getMetaIdentify();
            }
        }

        return ThemeData::getWidgetIdentify($widgetModule, $widgetCode, $area);
    }

    private function persistThemeDefaultConfig(string $widgetModule, string $widgetType, string $widgetCode, array $config, ?string $locale, string $area): void
    {
        if ($widgetModule === 'Weline_Theme' && ($widgetType === 'theme_component' || str_contains($widgetCode, '/'))) {
            ThemeData::setParamValues(
                $this->resolveThemeConfigIdentify($widgetModule, $widgetType, $widgetCode, $area),
                $config,
                'default',
                $locale
            );
            return;
        }

        ThemeData::setWidgetParams($widgetModule, $widgetCode, $config, $locale, $area);
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

        // 移除所有脚本
        foreach ($xpath->query('//script') as $script) {
            $script->parentNode?->removeChild($script);
        }

        // 移除所有 iframe（避免 html2canvas 触发 layout-preview 请求）
        foreach ($xpath->query('//iframe') as $iframe) {
            $iframe->parentNode?->removeChild($iframe);
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

        // 移除所有内联事件（onclick/onload/...）
        foreach ($xpath->query('//*') as $node) {
            if (!$node->hasAttributes()) {
                continue;
            }
            $toRemove = [];
            foreach ($node->attributes as $attr) {
                if (stripos($attr->name, 'on') === 0) {
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

        $themeId = (int)($data['theme_id'] ?? 0);
        $layoutType = $data['layout_type'] ?? 'homepage';
        $layoutOption = $data['layout_option'] ?? 'default';
        $slotContents = $data['slot_contents'] ?? []; // 各插槽的部件内容

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        // 设置预览主题到 session，让 TemplateFetchFile Observer 能识别当前编辑的主题
        // 注意：必须使用 ObjectManager::getInstance(Session::class) 与 Observer 保持一致
        $session = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
        $session->setData('preview_theme_id', $themeId);
        $session->setData('preview_theme_area', 'frontend');

        try {
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
            
            $html = $this->fetchTagHtml($templatePath);

            // 保存到生成目录
            $savePath = $this->cacheGenerator->saveCompiledLayout(
                $themeId,
                $layoutType,
                $layoutOption,
                $html
            );

            return $this->fetchJson([
                'success' => true,
                'message' => __('布局已保存'),
                'path' => $savePath,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
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
        $editorArea = $previewContextService->normalizeArea(
            (string)$this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND)
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
            return $this->renderLayoutNotFoundError($layoutType, $layoutOption);
        }

        return $this->injectEditorModeAssets($html);
    }

    public function legacyGetLayoutPreview()
    {
        return $this->getLayoutPreview();
    }
/*
        $this->layoutType = null; // 禁用后端布局，iframe 仅渲染前端内容

        $themeId = (int)$this->request->getParam('theme_id');
        $layoutType = $this->request->getParam('layout_type', 'homepage');
        $layoutOption = $this->request->getParam('layout_option', 'default');
        $editorArea = (string)$this->request->getParam('editor_area', 'frontend');
        if ($editorArea !== 'backend') {
            $editorArea = 'frontend';
        }

        // 设置预览主题到 session，让 TemplateFetchFile Observer 能识别当前编辑的主题
        // 这是主题编辑器切换主题后实时生效的关键
        // 注意：必须使用 ObjectManager::getInstance(Session::class) 与 Observer 保持一致
        if ($themeId) {
            $session = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
            $session->setData('preview_theme_id', $themeId);
            $session->setData('preview_theme_area', $editorArea);
        }
        // 强制本次请求内 fetch 走 fetchFile 并触发 fetch_file（避免 view 缓存命中导致观察者不执行）
        $this->request->setData('skip_view_file_cache', true);

        // 主题编辑器预览：禁用一切相关缓存，确保每次解析到当前 theme_id 对应的主题目录
        try {
            w_cache('view')->clear();
            ObjectManager::getInstance(SlotRendererService::class)->clearCache();
            \Weline\Theme\Helper\ThemeData::clearCache();
        } catch (\Throwable $e) {
            // 忽略单类缓存清理失败，继续渲染
        }

        // 检查布局模板文件是否存在（按区域：frontend/backend）
        $relativePath = "theme/{$editorArea}/layouts/{$layoutType}/{$layoutOption}.phtml";
        $templateFile = BP . "app/code/Weline/Theme/view/{$relativePath}";
        
        if (!file_exists($templateFile)) {
            // 布局文件不存在，返回错误页面
            return $this->renderLayoutNotFoundError($layoutType, $layoutOption);
        }

        // 设置编辑/预览模式
        $this->assign('editor_mode', true);
        $this->assign('preview_mode', true); // 标记为预览模式，Observer 会读取草稿数据
        $this->assign('theme_id', $themeId);
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

        // 返回编译后的布局（在 iframe 中渲染，按 editor_area 使用前端或后端布局）
        $templatePath = "Weline_Theme::theme/{$editorArea}/layouts/{$layoutType}/{$layoutOption}.phtml";
        // 强制本次 fetch 走 fetchFile 并触发 fetch_file：删除该布局在 view 缓存中的键（与 Template 中 key 一致）
        try {
            $modulePath = $this->request->getRouterData('module_path');
            if ($modulePath) {
                $viewDir = rtrim(str_replace(['/', '\\'], DS, $modulePath), DS) . DS . 'view' . DS;
                $layoutRel = 'theme' . DS . $editorArea . DS . 'layouts' . DS . $layoutType . DS . $layoutOption . '.phtml';
                $langLocal = \Weline\Framework\Http\Cookie::getLangLocal();
                w_cache('view')->delete($viewDir . $layoutRel . $langLocal);
                w_cache('view')->delete($viewDir . $templatePath . '_tplFile' . $langLocal);
                w_cache('view')->delete($viewDir . $templatePath . '_comFileName' . $langLocal);
            }
        } catch (\Throwable $e) {
            // 忽略，继续 fetch
        }

        // 两阶段渲染：若布局模板依赖 base（setLayout('base')），先渲染内容再套 base，否则只渲染当前布局
        $contentHtml = $this->fetch($templatePath);
        $basePath = "Weline_Theme::theme/{$editorArea}/layouts/base.phtml";
        $baseExists = $this->resolveThemeLayoutExists($themeId, $editorArea, 'base', 'default');
        if ($baseExists) {
            $this->getTemplate()->setData('child_html', ['content' => $contentHtml]);
            try {
                if ($modulePath = $this->request->getRouterData('module_path')) {
                    $viewDir = rtrim(str_replace(['/', '\\'], DS, $modulePath), DS) . DS . 'view' . DS;
                    $baseRel = 'theme' . DS . $editorArea . DS . 'layouts' . DS . 'base.phtml';
                    $langLocal = \Weline\Framework\Http\Cookie::getLangLocal();
                    w_cache('view')->delete($viewDir . $baseRel . $langLocal);
                    w_cache('view')->delete($viewDir . $basePath . '_tplFile' . $langLocal);
                    w_cache('view')->delete($viewDir . $basePath . '_comFileName' . $langLocal);
                }
            } catch (\Throwable $e) {
                // 忽略
            }
            $html = $this->fetch($basePath);
        } else {
            $html = $contentHtml;
        }

        // 注入编辑模式的 CSS 和 JS
        $html = $this->injectEditorModeAssets($html);

        return $html;
    }
    
    /**
     * 注入编辑模式的 CSS 和 JS 到 HTML 中
     */
    private function injectEditorModeAssets(string $html): string
    {
        // 使用框架的静态资源获取方法
        $cssUrl = $this->getTemplate()->fetchTagSource('statics', 'Weline_Theme::css/editor-mode.css');
        $jsUrl = $this->getTemplate()->fetchTagSource('statics', 'Weline_Theme::js/editor-mode.js');

        // 编辑模式 CSS
        $editorCss = <<<HTML
<!-- Theme Editor Mode CSS -->
<link rel="stylesheet" href="{$cssUrl}">
HTML;
        
        // 编辑模式 JS
        $editorJs = <<<HTML
<!-- Theme Editor Mode JS -->
<script src="{$jsUrl}"></script>
HTML;
        
        // 在 </head> 前注入 CSS
        if (stripos($html, '</head>') !== false) {
            $html = str_ireplace('</head>', $editorCss . "\n</head>", $html);
        } else {
            // 如果没有 </head>，在开头添加
            $html = $editorCss . "\n" . $html;
        }
        
        // 在 </body> 前注入 JS
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</body>', $editorJs . "\n</body>", $html);
        } else {
            // 如果没有 </body>，在末尾添加
            $html .= "\n" . $editorJs;
        }
        
        return $html;
    }

    /**
     * 判断当前预览主题下是否存在指定布局文件（用于两阶段渲染时是否套 base）
     */
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
            if ((string)$this->request->getParam('status', '') === '') {
                $this->request->setGet('status', ThemeLayout::STATUS_DRAFT);
            }
            $this->request->setData('skip_view_file_cache', true);

            $this->assign('editor_mode', true);
            $this->assign('preview_mode', true);
            $this->assign('theme_id', $themeId);
            $this->assign('preview_context', $context);
            $this->assign('layout_type', $layoutType);
            $this->assign('layout_option', $layoutOption);
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

            return (string)$this->fetch('Weline_Theme::templates/frontend/theme-preview/content.phtml');
        } catch (\Throwable) {
            return '';
        } finally {
            $this->layoutType = $previousLayoutType;
        }
    }

    private function resolveThemeLayoutExists(int $themeId, string $editorArea, string $layoutType, string $layoutOption = 'default'): bool
    {
        if ($themeId <= 0) {
            return false;
        }
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        if (!$theme->getId()) {
            return false;
        }
        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
        if (!isset($modules['Weline_Theme'])) {
            return false;
        }
        $ds = DS;
        $modulePath = rtrim($modules['Weline_Theme']['base_path'], $ds) . $ds . 'view' . $ds . 'theme' . $ds
            . $editorArea . $ds . 'layouts' . $ds . $layoutType . $ds . $layoutOption . '.phtml';
        $resolver = ObjectManager::getInstance(\Weline\Theme\Helper\ThemePathResolver::class);
        $resolved = $resolver->resolveThemeFile($modulePath, $theme);

        return $resolved !== '' && is_file($resolved);
    }

    /**
     * 渲染布局文件不存在的错误页面
     */
    private function renderLayoutNotFoundError(string $layoutType, string $layoutOption): string
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>布局不存在</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background: #f5f5f5;
        }
        .error-container {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 500px;
        }
        .error-icon {
            font-size: 64px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        h1 {
            margin: 0 0 10px;
            color: #333;
            font-size: 24px;
        }
        p {
            color: #666;
            margin: 0 0 20px;
            line-height: 1.6;
        }
        .layout-info {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 4px;
            font-family: monospace;
            color: #495057;
            margin-bottom: 20px;
        }
        .hint {
            font-size: 14px;
            color: #888;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">⚠️</div>
        <h1>布局文件不存在</h1>
        <p>请求的布局模板文件未找到，请选择其他布局类型。</p>
        <div class="layout-info">
            布局类型: {$layoutType}<br>
            布局选项: {$layoutOption}
        </div>
        <p class="hint">请在左侧面板选择一个有效的布局类型</p>
    </div>
</body>
</html>
HTML;
        return $html;
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
    private function getOriginalSlotContent(int $themeId, string $pageType, string $slotId, string $area, string $layoutType = 'homepage', string $layoutOption = 'default'): string
    {
        try {
            // 根据 area 决定渲染哪个模板
            $fullHtml = '';
            if ($area === 'header') {
                $fullHtml = $this->renderPartialPreviewHtml($themeId, $pageType, 'header', $layoutOption);
            } elseif ($area === 'footer') {
                $fullHtml = $this->renderPartialPreviewHtml($themeId, $pageType, 'footer', $layoutOption);
            } else {
                $fullHtml = $this->renderLayoutPreviewHtml($themeId, $pageType, $layoutType, $layoutOption);
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
    private function renderLayoutPreviewHtml(int $themeId, string $pageType, string $layoutType, string $layoutOption): string
    {
        try {
            $session = ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
            $session->setData('preview_theme_id', $themeId);
            $session->setData('preview_theme_area', PreviewContextService::AREA_FRONTEND);
            $this->request->setGet('status', ThemeLayout::STATUS_DRAFT);
            $this->request->setGet('editor_area', PreviewContextService::AREA_FRONTEND);

            return $this->renderUnifiedLayoutPreview(
                $themeId,
                $layoutType,
                $layoutOption,
                PreviewContextService::AREA_FRONTEND,
                []
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
    private function renderPartialPreviewHtml(int $themeId, string $pageType, string $partialType, string $layoutOption): string
    {
        try {
            $templatePath = "Weline_Theme::theme/frontend/partials/{$partialType}/{$layoutOption}.phtml";
            
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
            $slotNodes = $xpath->query("//*[@data-wslot='{$slotId}' or @data-slot='{$slotId}']");
            
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
     * 获取版本列表 (AJAX)
     * 路由: /backend/theme-editor/versions (GET)
     */
    public function getVersions()
    {
        $themeId = (int)$this->request->getParam('theme_id');
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);
        $limit = (int)$this->request->getParam('limit', 20);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        try {
            // 初始化版本（如果是首次访问）
            $this->versionService->initializeVersionIfNeeded($themeId, $pageType);

            $versions = $this->versionService->getVersions($themeId, $pageType, $limit);
            $currentVersion = $this->versionService->getCurrentVersion($themeId, $pageType);
            $publishedVersion = $this->versionService->getPublishedVersion($themeId, $pageType);

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
     * 保存为新版本 (AJAX)
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
            $version = $this->versionService->saveVersion(
                themeId: $themeId,
                pageType: $pageType,
                name: $versionName,
                description: $description,
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
     * 切换到指定版本 (AJAX)
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
            $result = $this->versionService->switchToVersion($themeId, $pageType, $versionId);

            if ($result) {
                // 获取更新后的布局数据
                $layout = $this->layoutService->getFullDraftLayout($themeId, $pageType);

                return $this->fetchJson([
                    'success' => true,
                    'message' => __('已切换到选定版本'),
                    'data' => [
                        'layout' => $layout,
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
     * 恢复原始布局 (AJAX) - 重构版本
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
            $result = $this->versionService->restoreOriginal($themeId, $pageType);

            // 清除插槽渲染服务的布局缓存，否则 WLS 常驻进程会继续返回旧 draft 缓存，预览无法恢复为空白
            ObjectManager::getInstance(SlotRendererService::class)->clearCache();

            $backupVersion = $result['backup_version'];
            $newVersion = $result['new_version'];

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
     * 发布版本 (AJAX)
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
            // 使用版本服务发布
            $result = $this->versionService->publishVersion($themeId, $pageType, $versionId);

            if ($result) {
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
     * 删除版本 (AJAX)
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

        if (!$versionId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少版本ID'),
            ]);
        }

        try {
            $result = $this->versionService->deleteVersion($versionId);

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
     * 重命名版本 (AJAX)
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

        if (!$versionId || empty($newName)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }

        try {
            $result = $this->versionService->renameVersion($versionId, $newName);

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
     * 启动前端预览 (AJAX)
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
        $frontendThemeId = (int)($data['frontend_theme_id'] ?? $data['theme_id'] ?? $this->request->getParam('frontend_theme_id', $this->request->getParam('theme_id', 0)));
        if (!$frontendThemeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缂哄皯鍓嶇涓婚ID'),
            ]);
        }

        try {
            $context = $this->getPreviewContextService()->buildContext([
                'frontend_theme_id' => $frontendThemeId,
                'backend_theme_id' => (int)($data['backend_theme_id'] ?? $this->request->getParam('backend_theme_id', 0)),
                'editor_area' => $this->getPreviewContextService()->normalizeArea(
                    (string)($data['editor_area'] ?? $this->request->getParam('editor_area', PreviewContextService::AREA_FRONTEND))
                ),
                'shell' => PreviewContextService::SHELL_PREVIEW,
                'preview_mode' => (string)($data['preview_mode'] ?? $this->request->getParam('preview_mode', PreviewContextService::DEFAULT_PREVIEW_MODE)),
                'status' => (string)($data['status'] ?? $this->request->getParam('status', PreviewContextService::DEFAULT_STATUS)),
                'version_id' => isset($data['version_id']) ? (int)$data['version_id'] : ((int)$this->request->getParam('version_id', 0) ?: null),
                'scope' => (string)($data['scope'] ?? $this->request->getParam('scope', PreviewContextService::DEFAULT_SCOPE)),
                'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
                'target_value' => $pageType,
            ]);
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
                    'preview_url' => $this->buildFrontendPreviewUrl($context, $pageType),
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

    public function legacyPostStartPreview()
    {
        return $this->postStartPreview();
    }
/*
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
        $versionId = isset($data['version_id']) ? (int)$data['version_id'] : null;

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        try {
            // 生成预览 Token
            $token = $this->previewTokenService->generateToken($themeId, $pageType, $versionId);
            
            // 构建前端预览 URL
            // 触发 hook 允许其他模块（如多站点模块）修改基础 URL
            // 使用 getBaseHost() 获取仅主机部分（如 http://127.0.0.1:9981）
            $baseUrl = $this->request->getBaseHost();
            
            // 分发事件获取自定义预览 URL
            $eventData = [
                'base_url' => $baseUrl,
                'theme_id' => $themeId,
                'page_type' => $pageType,
            ];
            $this->getEventManager()->dispatch('Weline_Theme::build_preview_url', $eventData);
            
            // 如果事件修改了 base_url，使用修改后的值
            if (isset($eventData['base_url']) && $eventData['base_url'] !== $baseUrl) {
                $baseUrl = $eventData['base_url'];
            }
            
            // 根据页面类型构建预览路径
            $previewPath = $this->getPreviewPathByPageType($pageType);
            
            // 构建完整预览 URL，携带 token 参数
            $previewUrl = rtrim($baseUrl, '/') . $previewPath;
            $previewUrl .= (strpos($previewUrl, '?') !== false ? '&' : '?') 
                        . PreviewTokenService::TOKEN_KEY . '=' . urlencode($token);

            return $this->fetchJson([
                'success' => true,
                'message' => __('预览已启动'),
                'data' => [
                    'token' => $token,
                    'preview_url' => $previewUrl,
                    'expires_in' => 3600, // 1 小时
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
     * 退出前端预览 (AJAX)
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

        if (empty($token)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Missing preview token'),
            ]);
        }

        try {
            $tokenData = $this->previewTokenService->validateToken($token);
            $context = \is_array($tokenData['context'] ?? null) ? $tokenData['context'] : [];
            $pageType = (string)($tokenData['page_type'] ?? ($context['target_value'] ?? ThemeLayout::PAGE_TYPE_HOME));

            $result = $this->previewTokenService->deleteToken($token);
            $this->previewTokenService->clearPreviewCookie();
            $this->getPreviewContextService()->clearContext();
            PreviewManager::clearPreviewConfig();
            $this->session->delete('preview_auto_login');

            $editorContext = $this->getPreviewContextService()->buildContext(\array_replace($context, [
                'shell' => PreviewContextService::SHELL_THEME_EDITOR,
                'preview_token' => '',
                'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
                'target_value' => $pageType,
            ]), false);
            $editorContext = $this->getPreviewContextService()->ensureThemeIds($editorContext, true, true);

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('Preview exited') : __('Failed to exit preview'),
                'data' => [
                    'editor_url' => $this->buildEditorShellUrl($editorContext, $pageType),
                    'context' => $editorContext,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function legacyPostExitPreview()
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

        // 如果没有传入 token，尝试从请求中获取
        if (empty($token)) {
            $token = $this->previewTokenService->getTokenFromRequest();
        }

        if (empty($token)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少预览 Token'),
            ]);
        }

        try {
            // 获取 token 数据用于返回编辑器 URL
            $tokenData = $this->previewTokenService->validateToken($token);
            
            // 删除 token
            $result = $this->previewTokenService->deleteToken($token);
            PreviewManager::clearPreviewConfig();
            $this->session->delete('preview_auto_login');

            // 构建编辑器返回 URL
            $editorUrl = $this->_url->getBackendUrl('theme-editor/index');
            if ($tokenData) {
                $editorUrl = $this->_url->getBackendUrl('theme-editor/index', [
                    'theme_id' => $tokenData['theme_id'] ?? 0,
                    'page_type' => $tokenData['page_type'] ?? 'homepage',
                ]);
            }

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('已退出预览模式') : __('退出预览失败'),
                'data' => [
                    'editor_url' => $editorUrl,
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
     * 发布并退出预览 (AJAX)
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
            if (!$themeId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Preview token is missing theme information'),
                ]);
            }

            $publishResult = $this->layoutService->publishLayout($themeId, $pageType);
            if (!$publishResult) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Failed to publish layout'),
                ]);
            }

            $this->cacheGenerator->clearCache($themeId);
            $this->cacheGenerator->generate($themeId);
            $this->flushFullPageCache();

            $this->previewTokenService->deleteToken($token);
            $this->previewTokenService->clearPreviewCookie();
            $previewContext = \is_array($tokenData['context'] ?? null) ? $tokenData['context'] : [];
            $this->getPreviewContextService()->clearContext();
            PreviewManager::clearPreviewConfig();
            $this->session->delete('preview_auto_login');

            $editorContext = $this->getPreviewContextService()->buildContext(\array_replace($previewContext, [
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
                        []
                    ),
                    'editor_url' => $this->buildEditorShellUrl($editorContext, $pageType),
                    'context' => $editorContext,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function legacyPostPublishAndExit()
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

        // 如果没有传入 token，尝试从请求中获取
        if (empty($token)) {
            $token = $this->previewTokenService->getTokenFromRequest();
        }

        if (empty($token)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少预览 Token'),
            ]);
        }

        try {
            // 验证 token 并获取数据
            $tokenData = $this->previewTokenService->validateToken($token);
            
            if (!$tokenData) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('预览 Token 已过期或无效'),
                ]);
            }

            $themeId = (int)($tokenData['theme_id'] ?? 0);
            $pageType = $tokenData['page_type'] ?? ThemeLayout::PAGE_TYPE_HOME;

            if (!$themeId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Token 中缺少主题信息'),
                ]);
            }

            // 1. 发布布局
            $publishResult = $this->layoutService->publishLayout($themeId, $pageType);
            if (!$publishResult) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('发布布局失败'),
                ]);
            }

            // 2. 清除并重建缓存
            $this->cacheGenerator->clearCache($themeId);
            $this->cacheGenerator->generate($themeId);
            
            $this->flushFullPageCache();

            // 3. 删除预览 token
            $this->previewTokenService->deleteToken($token);
            PreviewManager::clearPreviewConfig();
            $this->session->delete('preview_auto_login');

            // 4. 获取前端首页 URL（非预览模式）
            $frontendUrl = $this->request->getBaseHost() . '/';

            return $this->fetchJson([
                'success' => true,
                'message' => __('主题已发布'),
                'data' => [
                    'redirect_url' => $frontendUrl,
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
        $context = $this->getPreviewContextService()->buildContext($overrides);
        $context = $this->getPreviewContextService()->ensureThemeIds($context, true, true);
        return $this->getPreviewContextService()->persistContext($context);
    }

    private function buildFrontendPreviewUrl(array $context, string $pageType): string
    {
        $context = $this->getPreviewContextService()->buildContext(\array_replace($context, [
            'shell' => PreviewContextService::SHELL_PREVIEW,
            'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
            'target_value' => $pageType,
        ]), false);
        $params = $this->getPreviewContextService()->toQueryParams($context);
        $params['page_type'] = $pageType;
        $params['layout_type'] = $pageType;
        $params['layout_option'] = 'default';
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
        $params['editor_area'] = $editorArea;
        $params['_t'] = \time();

        return $this->_url->getBackendUrl('theme/backend/theme-editor', $params);
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
        $pathMap = [
            ThemeLayout::PAGE_TYPE_HOME => '/',
            ThemeLayout::PAGE_TYPE_CATEGORY => '/category/default',
            ThemeLayout::PAGE_TYPE_PRODUCT => '/product/default',
            ThemeLayout::PAGE_TYPE_PRODUCT_LIST => '/products',
            ThemeLayout::PAGE_TYPE_CMS => '/page/default',
            ThemeLayout::PAGE_TYPE_CART => '/cart',
            ThemeLayout::PAGE_TYPE_CHECKOUT => '/checkout',
            ThemeLayout::PAGE_TYPE_ACCOUNT => '/account',
            ThemeLayout::PAGE_TYPE_SEARCH => '/search',
        ];

        return $pathMap[$pageType] ?? '/';
    }

    // ==================== 编辑锁定 API ====================

    /**
     * 检查编辑锁定状态 (AJAX)
     * 路由: /backend/theme-editor/check-lock (GET)
     * 
     * 返回当前锁定状态，如果被其他用户锁定，返回锁定信息
     */
    public function getCheckLock()
    {
        $themeId = (int)$this->request->getParam('theme_id', 0);
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

        // 获取当前用户信息
        $userId = $this->session->getLoginUserID() ?: 0;
        $userName = $this->session->getLoginUsername() ?: '';

        // 尝试获取锁定
        $result = $this->editorLockService->acquireLock($themeId, $pageType, $userId, $userName);

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
     * 释放编辑锁定 (AJAX)
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
        $result = $this->editorLockService->releaseLock($themeId, $pageType, $userId);

        return $this->fetchJson([
            'success' => $result,
            'message' => $result ? __('已释放编辑锁定') : __('释放锁定失败'),
        ]);
    }

    /**
     * 更新编辑活动时间 (AJAX)
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
        $result = $this->editorLockService->updateActivity($themeId, $pageType, $userId);

        return $this->fetchJson([
            'success' => $result,
        ]);
    }

    /**
     * 请求接管编辑 (AJAX)
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

        $result = $this->editorLockService->requestTakeover($themeId, $pageType, $userId, $userName);

        return $this->fetchJson($result);
    }

    /**
     * 检查是否有接管请求 (AJAX)
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

        $takeoverRequest = $this->editorLockService->getTakeoverRequest($themeId, $pageType);

        return $this->fetchJson([
            'success' => true,
            'data' => [
                'has_takeover_request' => $takeoverRequest !== null,
                'takeover_request' => $takeoverRequest,
            ],
        ]);
    }

    /**
     * 强制接管编辑 (AJAX)
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

        $result = $this->editorLockService->forceTakeover($themeId, $pageType, $userId, $userName);

        return $this->fetchJson($result);
    }
}
