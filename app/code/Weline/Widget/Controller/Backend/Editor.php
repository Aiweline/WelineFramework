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
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Model\Page;
use Weline\Widget\Service\WidgetData;

/**
 * 可视化编辑器控制器
 */
#[\Weline\Framework\Acl\Acl('Weline_Widget::editor', '可视化编辑器', 'mdi mdi-pencil-box', '可视化编辑器')]
class Editor extends BackendController
{
    private Page $pageModel;
    private WidgetData $widgetData;

    public function __construct(
        Page $pageModel,
        WidgetData $widgetData
    ) {
        $this->pageModel = $pageModel;
        $this->widgetData = $widgetData;
    }

    /**
     * 编辑器主界面
     */
    #[\Weline\Framework\Acl\Acl('Weline_Widget::editor_index', '编辑器首页', '', '访问编辑器')]
    public function index()
    {
        $pageId = (int)$this->request->getParam('page_id', 0);
        
        // 如果提供了 page_id，加载页面
        $page = null;
        if ($pageId > 0) {
            $page = clone $this->pageModel;
            $page->load($pageId);
            if (!$page->getId()) {
                $page = null;
            }
        }

        // 从注册表读取所有可用部件（运行时只读取，不扫描）
        $widgets = $this->widgetData->getAllWidgets();
        
        // 按类型分组
        $widgetsByType = [];
        foreach ($widgets as $type => $typeWidgets) {
            $widgetsByType[$type] = $typeWidgets;
        }

        $this->assign('page', $page);
        $this->assign('widgets', $widgets);
        $this->assign('widgetsByType', $widgetsByType);
        $this->assign('allowedTypes', $this->widgetData->getAllowedTypes());
        $this->assign('page_title', __('可视化编辑器'));
        $this->assign('breadcrumb_parent', __('内容管理'));
        $this->assign('breadcrumb_current', __('可视化编辑器'));

        return $this->fetch();
    }

    /**
     * 保存页面
     */
    #[\Weline\Framework\Acl\Acl('Weline_Widget::editor_save', '保存页面', '', '保存页面')]
    public function save()
    {
        try {
            $pageId = (int)$this->request->getPost('page_id', 0);
            $title = $this->request->getPost('title', '');
            $handle = $this->request->getPost('handle', '');
            $content = $this->request->getPost('content', '');
            $metaData = $this->request->getPost('meta_data', '{}');
            $status = $this->request->getPost('status', 'draft');

            if (empty($title) || empty($handle)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('标题和标识不能为空')
                ]);
            }

            $page = clone $this->pageModel;
            
            if ($pageId > 0) {
                $page->load($pageId);
            }

            // 检查 handle 是否已存在（排除当前页面）
            if (!$page->getId() || $page->getData(Page::fields_HANDLE) !== $handle) {
                $existing = clone $this->pageModel;
                $existing->clear()
                    ->where(Page::fields_HANDLE, $handle)
                    ->find()
                    ->fetch();
                
                if ($existing->getId()) {
                    return $this->fetchJson([
                        'success' => false,
                        'message' => __('页面标识已存在')
                    ]);
                }
            }

            // 解析 meta_data
            $metaDataArray = [];
            if (!empty($metaData)) {
                $decoded = json_decode($metaData, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $metaDataArray = $decoded;
                }
            }

            // 保存页面
            $page->setData(Page::fields_TITLE, $title)
                 ->setData(Page::fields_HANDLE, $handle)
                 ->setData(Page::fields_CONTENT, $content)
                 ->setMetaData($metaDataArray)
                 ->setData(Page::fields_STATUS, $status);

            if ($page->getId()) {
                $page->setData(Page::fields_UPDATE_TIME, date('Y-m-d H:i:s'));
            } else {
                $page->setData(Page::fields_CREATE_TIME, date('Y-m-d H:i:s'));
                $page->setData(Page::fields_UPDATE_TIME, date('Y-m-d H:i:s'));
            }

            $page->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('保存成功'),
                'page_id' => $page->getId()
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存失败: %{1}', [$e->getMessage()])
            ]);
        }
    }

    /**
     * 加载页面
     */
    #[\Weline\Framework\Acl\Acl('Weline_Widget::editor_load', '加载页面', '', '加载页面')]
    public function load()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id', 0);
            
            if ($pageId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }

            $page = clone $this->pageModel;
            $page->load($pageId);

            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'page_id' => $page->getId(),
                    'title' => $page->getData(Page::fields_TITLE),
                    'handle' => $page->getData(Page::fields_HANDLE),
                    'content' => $page->getData(Page::fields_CONTENT),
                    'meta_data' => $page->getMetaData(),
                    'status' => $page->getData(Page::fields_STATUS)
                ]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('加载失败: %{1}', [$e->getMessage()])
            ]);
        }
    }

    /**
     * 获取部件列表（AJAX）
     */
    #[\Weline\Framework\Acl\Acl('Weline_Widget::editor_widgets', '获取部件列表', '', '获取部件列表')]
    public function getWidgets()
    {
        try {
            $widgets = $this->widgetData->getAllWidgets();
            
            // 格式化输出
            $formatted = [];
            foreach ($widgets as $type => $typeWidgets) {
                foreach ($typeWidgets as $name => $widget) {
                    $formatted[] = [
                        'type' => $type,
                        'name' => $name,
                        'display_name' => $widget['name'] ?? $name,
                        'description' => $widget['description'] ?? '',
                        'module' => $widget['module'] ?? '',
                        'params' => $widget['params'] ?? []
                    ];
                }
            }

            return $this->fetchJson([
                'success' => true,
                'widgets' => $formatted,
                'widgetsByType' => $widgets
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取部件列表失败: %{1}', [$e->getMessage()])
            ]);
        }
    }
}

