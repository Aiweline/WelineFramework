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
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_DEFAULT);

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

        // 获取布局数据
        $layout = [];
        if ($themeId) {
            $layout = $this->layoutService->getFullLayout($themeId, $pageType);
        }

        // 获取可用部件列表
        $availableWidgets = $this->layoutService->getAvailableWidgets();

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

        return $this->fetch('Weline_Theme::templates/backend/ThemeEditor/index.phtml');
    }

    /**
     * 获取布局数据 (AJAX)
     */
    public function getLayout()
    {
        $themeId = (int)$this->request->getParam('theme_id');
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_DEFAULT);

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请选择主题'),
            ]);
        }

        $layout = $this->layoutService->getFullLayout($themeId, $pageType);

        return $this->fetchJson([
            'success' => true,
            'data' => $layout,
        ]);
    }

    /**
     * 获取部件列表 (AJAX)
     */
    public function getWidgets()
    {
        $widgets = $this->layoutService->getAvailableWidgets();

        return $this->fetchJson([
            'success' => true,
            'data' => $widgets,
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

        // 检查位置是否允许
        if (!$this->positionResolver->canPlaceInArea($data['widget_module'] ?? '', $data['widget_code'], $data['area'])) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('该部件不能放置在此区域'),
            ]);
        }

        try {
            $layoutId = $this->layoutService->saveWidget($data);

            return $this->fetchJson([
                'success' => true,
                'message' => __('保存成功'),
                'data' => ['layout_id' => $layoutId],
            ]);
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

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('配置已保存') : __('保存失败'),
            ]);
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
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_DEFAULT);
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
     * 发布主题 (生成缓存)
     */
    public function postPublish()
    {
        $themeId = (int)$this->request->getParam('theme_id');

        if (!$themeId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请选择主题'),
            ]);
        }

        try {
            // 清除旧缓存
            $this->cacheGenerator->clearCache($themeId);

            // 生成新缓存
            $result = $this->cacheGenerator->generate($themeId);

            return $this->fetchJson([
                'success' => $result,
                'message' => $result ? __('主题已发布') : __('发布失败'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 预览页面
     */
    public function preview()
    {
        $themeId = (int)$this->request->getParam('theme_id');
        $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_DEFAULT);

        if (!$themeId) {
            return $this->fetch('Weline_Theme::templates/backend/ThemeEditor/preview-empty.phtml');
        }

        // 获取布局数据
        $layout = $this->layoutService->getFullLayout($themeId, $pageType);

        $this->assign('theme_id', $themeId);
        $this->assign('page_type', $pageType);
        $this->assign('layout', $layout);
        $this->assign('areas', ThemeLayout::getAreas());

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
                'html' => '<div class="widget-preview-placeholder">' . htmlspecialchars($widgetMeta['name'] ?? $widgetCode) . '</div>',
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
                'html' => '<div class="widget-preview-error">' . htmlspecialchars($e->getMessage()) . '</div>',
                'widget' => $widgetMeta,
            ]);
        }
    }
}
