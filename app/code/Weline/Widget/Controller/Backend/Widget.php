<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Widget\Service\WidgetScanner;

/**
 * 部件管理控制器
 */
#[\Weline\Framework\Acl\Acl('Weline_Widget::widget_management', '部件管理', 'mdi mdi-puzzle', '部件管理')]
class Widget extends BackendController
{
    private WidgetScanner $widgetScanner;

    public function __construct(WidgetScanner $widgetScanner)
    {
        $this->widgetScanner = $widgetScanner;
    }

    /**
     * 部件列表
     */
    #[\Weline\Framework\Acl\Acl('Weline_Widget::widget_index', '部件列表', '', '查看部件列表')]
    public function index()
    {
        // 获取筛选参数
        $moduleFilter = $this->request->getParam('module', '');
        $typeFilter = $this->request->getParam('type', '');
        $search = $this->request->getParam('search', '');

        // 扫描所有部件
        $allWidgets = $this->widgetScanner->scanAllWidgets();

        // 筛选部件
        $filteredWidgets = [];
        foreach ($allWidgets as $type => $typeWidgets) {
            if ($typeFilter && $typeFilter !== $type) {
                continue;
            }

            foreach ($typeWidgets as $name => $widget) {
                // 模块筛选
                if ($moduleFilter && ($widget['module'] ?? '') !== $moduleFilter) {
                    continue;
                }

                // 搜索筛选
                if ($search) {
                    $searchLower = strtolower($search);
                    $nameMatch = strpos(strtolower($widget['name'] ?? ''), $searchLower) !== false;
                    $descMatch = strpos(strtolower($widget['description'] ?? ''), $searchLower) !== false;
                    $moduleMatch = strpos(strtolower($widget['module'] ?? ''), $searchLower) !== false;
                    
                    if (!$nameMatch && !$descMatch && !$moduleMatch) {
                        continue;
                    }
                }

                if (!isset($filteredWidgets[$type])) {
                    $filteredWidgets[$type] = [];
                }
                $filteredWidgets[$type][$name] = $widget;
            }
        }

        // 获取所有模块列表（用于筛选）
        $modules = [];
        foreach ($allWidgets as $type => $typeWidgets) {
            foreach ($typeWidgets as $name => $widget) {
                $module = $widget['module'] ?? '';
                if ($module && !in_array($module, $modules)) {
                    $modules[] = $module;
                }
            }
        }
        sort($modules);

        $this->assign('widgets', $filteredWidgets);
        $this->assign('allWidgets', $allWidgets);
        $this->assign('modules', $modules);
        $this->assign('allowedTypes', $this->widgetScanner->getAllowedTypes());
        $this->assign('moduleFilter', $moduleFilter);
        $this->assign('typeFilter', $typeFilter);
        $this->assign('search', $search);
        $this->assign('page_title', __('部件管理'));
        $this->assign('breadcrumb_parent', __('内容管理'));
        $this->assign('breadcrumb_current', __('部件管理'));

        return $this->fetch();
    }

    /**
     * 获取部件详情（AJAX）
     */
    #[\Weline\Framework\Acl\Acl('Weline_Widget::widget_detail', '部件详情', '', '查看部件详情')]
    public function detail()
    {
        try {
            $type = $this->request->getParam('type', '');
            $name = $this->request->getParam('name', '');

            if (empty($type) || empty($name)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('部件类型和名称不能为空')
                ]);
            }

            $widget = $this->widgetScanner->scanWidget($type, $name);
            if (!$widget) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('部件不存在')
                ]);
            }

            return $this->fetchJson([
                'success' => true,
                'widget' => $widget
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取部件详情失败: %{1}', [$e->getMessage()])
            ]);
        }
    }
}

