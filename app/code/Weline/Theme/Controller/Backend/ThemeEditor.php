<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\WidgetPositionResolver;
use Weline\Widget\Service\WidgetRegistry;
use Weline\Theme\Helper\ThemeData;
use Weline\Meta\Model\Meta;

/**
 * 主题编辑器控制器
 */
class ThemeEditor extends BackendController
{
    private WelineTheme $welineTheme;
    private ThemeLayoutService $layoutService;
    private ThemeCacheGenerator $cacheGenerator;
    private WidgetPositionResolver $positionResolver;
    private WidgetRegistry $widgetRegistry;
    private ThemeLayout $themeLayout;
    private Meta $meta;

    public function __construct(
        WelineTheme $welineTheme,
        ThemeLayoutService $layoutService,
        ThemeCacheGenerator $cacheGenerator,
        WidgetPositionResolver $positionResolver,
        WidgetRegistry $widgetRegistry,
        ThemeLayout $themeLayout,
        Meta $meta
    ) {
        $this->welineTheme = $welineTheme;
        $this->layoutService = $layoutService;
        $this->cacheGenerator = $cacheGenerator;
        $this->positionResolver = $positionResolver;
        $this->widgetRegistry = $widgetRegistry;
        $this->themeLayout = $themeLayout;
        $this->meta = $meta;
    }

    /**
     * 编辑器主页
     */
    public function index()
    {
        $requestedThemeId = (int)$this->request->getParam('theme_id', 0);
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);
        
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
            $activeTheme = $this->welineTheme->getActiveTheme();
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
        
        // 获取所有主题列表
        $themesCollection = $this->welineTheme->reset()->select()->fetch()->getItems();
        $themes = [];
        foreach ($themesCollection as $themeItem) {
            if (is_object($themeItem)) {
                $themes[] = $themeItem->getData();
            } elseif (is_array($themeItem)) {
                $themes[] = $themeItem;
            }
        }

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

        $this->assign('theme_id', $themeId);
        $this->assign('theme', $this->welineTheme);
        $this->assign('themes', $themes);
        $this->assign('page_type', $pageType);
        $this->assign('page_types', $pageTypes);
        $this->assign('areas', $areas);
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
        
        // 如果传入了页面类型，则按页面类型过滤部件
        $widgets = $this->layoutService->getAvailableWidgets($pageType);

        return $this->fetchJson([
            'success' => true,
            'data' => $widgets,
            'page_type' => $pageType,
        ]);
    }

    /**
     * 保存部件 (AJAX)
     */
    public function postSaveWidget()
    {
        // 优先从请求体获取 JSON 数据（JSON 请求体可能已被 Request __init 合并到 getData，getBodyParams 二次读取 php://input 可能为空）
        $bodyParams = $this->request->getBodyParams();
        
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
        } elseif (is_array($bodyParams) && !empty($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        // 缺失时从 getParam 补全（Request __init 可能已将 JSON 合并到 setData，getBodyParams 二次读 php://input 可能为空）
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
        $exclusiveSlots = [
            'logo', 'search', 'main-nav', 'user-area', 'cart', 'language', 'currency',
            'header-container', 'footer-container', 'copyright', 'top-bar',
            'footer-links', 'footer-social', 'footer-newsletter',
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
            // 获取要删除的部件信息（在删除前）
            $widget = $this->themeLayout->reset()->load($layoutId);
            $slotId = $widget->getData('slot_id');
            $pageType = $widget->getData('page_type');
            $area = $widget->getData('area');
            
            // 删除部件
            $result = $this->layoutService->deleteWidget($layoutId);
            
            $response = [
                'success' => $result,
                'message' => $result ? __('删除成功') : __('删除失败'),
                'slot_id' => $slotId,
            ];
            
            // 如果删除成功，获取插槽的原始内容
            if ($result && $themeId && $slotId) {
                $originalHtml = $this->getOriginalSlotContent($themeId, $pageType, $slotId, $area);
                $response['original_html'] = $originalHtml;
                $response['has_original'] = !empty($originalHtml);
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
                // ✅ 正确方式：delete()->fetch() 才能真正执行删除
                $this->themeLayout->reset()
                    ->where('theme_id', $themeId)
                    ->where('slot_id', $slotId)
                    ->delete()
                    ->fetch();  // ✅ 必须调用 fetch() 执行删除
                    
                $deletedCount++;
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('已删除 %1 个孤儿部件', [$deletedCount]),
                'deleted_count' => $deletedCount,
            ]);
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
        $body = json_decode(file_get_contents('php://input'), true);
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
        $body = json_decode(file_get_contents('php://input'), true);
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
     * 发布主题 (将草稿发布为正式版并生成缓存)
     */
    /**
     * 恢复原始布局（从已发布版本重新初始化草稿）
     */
    public function postRestoreLayout()
    {
        try {
            $themeId = (int)$this->request->getParam('theme_id', 0);
            $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);

            if (!$themeId) {
                return $this->json([
                    'success' => false,
                    'message' => __('主题 ID 不能为空'),
                ]);
            }

            // 删除当前草稿布局数据
            $this->themeLayout->reset()
                ->where('theme_id', $themeId)
                ->where('page_type', $pageType)
                ->where('status', 'draft')
                ->delete()
                ->fetch();

            // 从已发布版本重新初始化草稿
            $result = $this->layoutService->initDraftFromPublished($themeId, $pageType);

            if ($result) {
                return $this->json([
                    'success' => true,
                    'message' => __('已成功恢复到原始布局'),
                ]);
            }

            return $this->json([
                'success' => false,
                'message' => __('恢复原始布局失败'),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => __('恢复失败: %1', $e->getMessage()),
            ]);
        }
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
            // 1. 将草稿发布为正式版（复制 draft -> published）
            $publishResult = $this->layoutService->publishLayout($themeId, $pageType);
            if (!$publishResult) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('发布布局失败'),
                ]);
            }

            // 2. 清除旧缓存
            $this->cacheGenerator->clearCache($themeId);

            // 3. 生成新缓存
            $cacheResult = $this->cacheGenerator->generate($themeId);

            return $this->fetchJson([
                'success' => $cacheResult,
                'message' => $cacheResult ? __('主题已发布') : __('生成缓存失败，但布局已发布'),
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

        // 获取部件元数据
        $registry = $this->widgetRegistry->getRegistry();
        $widgetMeta = null;

        foreach ($registry as $key => $widget) {
            if ($widget['module'] === $widgetModule && $widget['code'] === $widgetCode) {
                $widgetMeta = $widget;
                break;
            }
        }

        if (!$widgetMeta) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('部件不存在'),
            ]);
        }

        $template = $widgetMeta['template'] ?? '';
        if (!$template) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('部件无模板'),
            ]);
        }

        try {
            // 渲染部件
            $html = $this->fetchTagHtml($template, $config);

            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'widget' => [
                    'code' => $widgetCode,
                    'module' => $widgetModule,
                    'name' => $widgetMeta['name'] ?? $widgetCode,
                    'slot' => $widgetMeta['slot'] ?? null,
                    'is_container' => $widgetMeta['is_container'] ?? false,
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

        // 获取部件元数据
        $registry = $this->widgetRegistry->getRegistry();
        $widgetMeta = null;

        foreach ($registry as $key => $widget) {
            if ($widget['module'] === $widgetModule && $widget['code'] === $widgetCode) {
                $widgetMeta = $widget;
                break;
            }
        }

        if (!$widgetMeta) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('部件不存在'),
            ]);
        }

        $template = $widgetMeta['template'] ?? '';
        if (!$template) {
            // 返回默认占位符
            return $this->fetchJson([
                'success' => true,
                'html' => '<div class="widget-preview-placeholder">' . htmlspecialchars((string)($widgetMeta['name'] ?? $widgetCode ?? '')) . '</div>',
                'widget' => $widgetMeta,
            ]);
        }

        try {
            // 使用默认参数渲染预览
            $defaultConfig = [];
            foreach ($widgetMeta['params'] ?? [] as $key => $param) {
                $defaultConfig[$key] = $param['default'] ?? '';
            }

            $html = $this->fetchTagHtml($template, $defaultConfig);

            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'widget' => $widgetMeta,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => true,
                'html' => '<div class="widget-preview-error">' . htmlspecialchars((string)$e->getMessage()) . '</div>',
                'widget' => $widgetMeta,
            ]);
        }
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
        $area = $widgetLayout->getData('area') ?: 'frontend';
        
        // 使用 ThemeData 获取参数定义（自动从 WidgetRegistry 和 Meta 两个来源获取）
        $params = ThemeData::getWidgetParamDefinitionsWithRegistry(
            $widgetModule,
            $widgetCode,
            $this->widgetRegistry,
            $area
        );
        
        if (empty($params)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('该部件没有配置项'),
            ]);
        }
        
        // 使用 ThemeData 获取参数值（支持多语言）
        $config = ThemeData::getWidgetParams($widgetModule, $widgetCode, $locale, $area);
        
        // 返回参数定义和当前配置
        return $this->fetchJson([
            'success' => true,
            'data' => [
                'layout_id' => $layoutId,
                'widget_module' => $widgetModule,
                'widget_code' => $widgetCode,
                'params' => $params,  // 参数定义（含多语言标记）
                'config' => $config,  // 当前配置值（已支持多语言）
                'locale' => $locale,  // 当前语言
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
        $area = $widgetLayout->getData('area') ?: 'frontend';
        
        try {
            // 使用 ThemeData 保存参数（含多语言）
            ThemeData::setWidgetParams($widgetModule, $widgetCode, $configData, $locale, $area);
            
            // 仅在默认语言（locale=null）时，更新 m_theme_layout.config 字段（用于向后兼容）
            if ($locale === null) {
                $widgetLayout->setData('config', json_encode($configData));
                $widgetLayout->save();
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => $locale ? __('已保存 %1 语言的配置', $locale) : __('配置已保存'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存失败：%1', $e->getMessage()),
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
        $themeId = (int)$this->request->getParam('theme_id');
        $layoutType = $this->request->getParam('layout_type', 'homepage');
        $layoutOption = $this->request->getParam('layout_option', 'default');

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少主题ID'),
            ]);
        }

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
     */
    private function extractSlots(string $html): array
    {
        $slots = [];
        
        // 使用正则表达式提取所有 widget-slot-area 元素的属性
        $pattern = '/<[^>]+class="[^"]*widget-slot-area[^"]*"[^>]*data-slot-id="([^"]+)"[^>]*>/i';
        
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = $match[0];
                $slotId = $match[1];
                
                // 提取其他属性
                $slot = ['id' => $slotId];
                
                if (preg_match('/data-slot-name="([^"]+)"/', $fullTag, $m)) {
                    $slot['name'] = $m[1];
                }
                if (preg_match('/data-slot-accept="([^"]+)"/', $fullTag, $m)) {
                    $slot['accept'] = explode(',', $m[1]);
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
            $html = $this->getTemplate()->fetchHtml($template, $defaultConfig);
            return $this->sanitizeWidgetPreviewHtml($html);
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

        // 从注册表获取部件元数据
        // 注册表结构是 type -> code -> widget_data
        $registry = $this->widgetRegistry->getRegistry();
        $widgetMeta = null;

        foreach ($registry as $type => $typeWidgets) {
            if (!is_array($typeWidgets)) {
                continue;
            }
            foreach ($typeWidgets as $code => $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                if (isset($widget['module']) && isset($widget['code']) &&
                    $widget['module'] === $widgetModule && $widget['code'] === $widgetCode) {
                    $widgetMeta = $widget;
                    break 2;
                }
            }
        }

        if (!$widgetMeta) {
            return '<div class="widget-preview-placeholder">' . htmlspecialchars((string)$widgetCode) . '</div>';
        }

        $template = $widgetMeta['template'] ?? '';
        if (!$template) {
            return '<div class="widget-preview-placeholder">' . htmlspecialchars((string)($widgetMeta['name'] ?? $widgetCode)) . '</div>';
        }

        // 合并保存的配置和传入的配置（传入配置优先）
        $savedConfig = $layoutData['config'] ?? [];
        $finalConfig = array_merge($savedConfig, $config);

        // 处理特殊默认值
        foreach ($widgetMeta['params'] ?? [] as $key => $param) {
            if (!isset($finalConfig[$key]) || $finalConfig[$key] === '') {
                $defaultValue = $param['default'] ?? '';
                if (($key === 'end_date' || $key === 'countdown_end') && empty($defaultValue)) {
                    $defaultValue = date('Y-m-d H:i:s', time() + 86400);
                }
                if ($defaultValue !== '') {
                    $finalConfig[$key] = $defaultValue;
                }
            }
        }
        $finalConfig['preview_mode'] = true;

        try {
            $html = $this->getTemplate()->fetchHtml($template, $finalConfig);
            return $this->sanitizeWidgetPreviewHtml($html);
        } catch (\Exception $e) {
            return '<div class="widget-preview-error">' . htmlspecialchars((string)$e->getMessage()) . '</div>';
        }
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
     * 预览模式会读取草稿数据
     */
    public function getLayoutPreview()
    {
        $themeId = (int)$this->request->getParam('theme_id');
        $layoutType = $this->request->getParam('layout_type', 'homepage');
        $layoutOption = $this->request->getParam('layout_option', 'default');

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

        // 返回编译后的布局（在 iframe 中渲染）
        // 注意：URL 应该带有 preview_mode=1 参数，让 Observer 读取草稿数据
        $templatePath = "Weline_Theme::theme/frontend/layouts/{$layoutType}/{$layoutOption}.phtml";
        
        return $this->fetch($templatePath);
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
    private function getOriginalSlotContent(int $themeId, string $pageType, string $slotId, string $area): string
    {
        try {
            // 删除draft后，重新渲染布局预览（此时draft已被删除，会显示原始内容）
            $layoutType = $this->request->getParam('layout_type', 'homepage');
            $layoutOption = $this->request->getParam('layout_option', 'default');
            
            // 渲染完整的布局预览（使用draft状态，但刚才的draft已被删除）
            $fullHtml = $this->renderLayoutPreviewHtml($themeId, $pageType, $layoutType, $layoutOption);
            
            if (empty($fullHtml)) {
                return '';
            }
            
            // 从渲染的HTML中提取指定插槽的内容
            $slotContent = $this->extractSlotContentFromHtml($fullHtml, $slotId);
            
            return $slotContent;
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

}
