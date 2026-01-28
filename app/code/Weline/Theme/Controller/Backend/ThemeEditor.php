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

    public function __construct(
        WelineTheme $welineTheme,
        ThemeLayoutService $layoutService,
        ThemeCacheGenerator $cacheGenerator,
        WidgetPositionResolver $positionResolver,
        WidgetRegistry $widgetRegistry
    ) {
        $this->welineTheme = $welineTheme;
        $this->layoutService = $layoutService;
        $this->cacheGenerator = $cacheGenerator;
        $this->positionResolver = $positionResolver;
        $this->widgetRegistry = $widgetRegistry;
    }

    /**
     * 编辑器主页
     */
    public function index()
    {
        $themeId = (int)$this->request->getParam('theme_id', 0);
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_HOME);

        // 如果没有指定主题，使用当前激活的主题
        if (!$themeId) {
            $activeTheme = $this->welineTheme->getActiveTheme();
            $themeId = $activeTheme->getId() ?: 0;
        }

        // 获取主题信息
        $this->welineTheme->reset()->load($themeId);
        
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

        if (empty($data['theme_id']) || empty($data['area']) || empty($data['widget_code'])) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整'),
            ]);
        }

        // 如果 area 不是标准区域，则视为自定义插槽，默认归到 content 区域
        $area = $data['area'];
        if (!array_key_exists($area, ThemeLayout::getAreas())) {
            $data['slot_id'] = $data['slot_id'] ?? $area;
            $data['area'] = ThemeLayout::AREA_CONTENT;
        }

        // 检查位置是否允许
        if (!$this->positionResolver->canPlaceInArea($data['widget_module'] ?? '', $data['widget_code'], $data['area'])) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('该部件不能放置在此区域'),
            ]);
        }

        // 处理独占插槽参数
        // slot_id: 插槽ID（如 logo, search, user-area 等）
        // exclusive: 是否独占（true 表示替换现有部件）
        $data['slot_id'] = $data['slot_id'] ?? null;
        $data['exclusive'] = (bool)($data['exclusive'] ?? false);

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
     */
    public function postDeleteWidget()
    {
        $layoutId = (int)$this->request->getParam('layout_id');

        if (!$layoutId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少布局ID'),
            ]);
        }

        try {
            $result = $this->layoutService->deleteWidget($layoutId);

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('删除成功') : __('删除失败'),
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
        $sortData = $this->request->getParam('sort_data', []);

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
}
