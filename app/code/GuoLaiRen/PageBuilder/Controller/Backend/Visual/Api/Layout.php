<?php

declare(strict_types=1);

/*
 * 布局API控制器 - 负责布局相关的API请求
 * 遵循单一职责原则(SRP) - 只负责布局API
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend\Visual\Api;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use GuoLaiRen\PageBuilder\Service\LayoutService;

class Layout extends BackendController
{
    private LayoutService $layoutService;
    
    public function __construct()
    {
        $this->layoutService = ObjectManager::getInstance(LayoutService::class);
    }
    
    /**
     * API: 获取布局配置
     * GET /backend/visual/api/layout/get
     */
    public function get()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id');
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            $layout = $this->layoutService->getOrCreate($pageId);
            
            return $this->fetchJson([
                'success' => true,
                'layout_id' => $layout->getId(),
                'layout_config' => $layout->exportConfig(),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 保存布局配置
     * POST /backend/visual/api/layout/save
     */
    public function save()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id');
            $layoutConfig = $this->request->getParam('layout_config', '');
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            $config = json_decode($layoutConfig, true);
            if (!is_array($config)) {
                throw new \Exception('布局配置格式错误');
            }
            
            $layout = $this->layoutService->saveConfig($pageId, $config);
            
            return $this->fetchJson([
                'success' => true,
                'message' => '布局保存成功',
                'layout_id' => $layout->getId(),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 添加组件到布局
     * POST /backend/visual/api/layout/addComponent
     */
    public function addComponent()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id');
            $componentCode = $this->request->getParam('component_code', '');
            $fromTemplate = $this->request->getParam('from_template', '');
            $position = $this->request->getParam('position', 'content');
            $sortOrder = $this->request->getParam('sort_order');
            
            if (!$pageId || !$componentCode) {
                throw new \Exception('参数不完整');
            }
            
            $result = $this->layoutService->addComponent(
                $pageId,
                $componentCode,
                $fromTemplate,
                $position,
                $sortOrder !== null ? (int)$sortOrder : null
            );
            
            return $this->fetchJson([
                'success' => true,
                'message' => '组件添加成功',
                'instance_id' => $result['instance_id'],
                'layout_config' => $result['layout_config'],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 移除组件
     * POST /backend/visual/api/layout/removeComponent
     */
    public function removeComponent()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id');
            $instanceId = $this->request->getParam('instance_id', '');
            
            if (!$pageId || !$instanceId) {
                throw new \Exception('参数不完整');
            }
            
            $result = $this->layoutService->removeComponent($pageId, $instanceId);
            
            return $this->fetchJson([
                'success' => true,
                'message' => '组件移除成功',
                'layout_config' => $result['layout_config'],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 更新组件配置
     * POST /backend/visual/api/layout/updateComponent
     */
    public function updateComponent()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id');
            $instanceId = $this->request->getParam('instance_id', '');
            $config = $this->request->getParam('config', '');
            
            if (!$pageId || !$instanceId) {
                throw new \Exception('参数不完整');
            }
            
            $configArray = json_decode($config, true) ?: [];
            $result = $this->layoutService->updateComponent($pageId, $instanceId, $configArray);
            
            return $this->fetchJson([
                'success' => true,
                'message' => '组件配置更新成功',
                'layout_config' => $result['layout_config'],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 重新排序组件
     * POST /backend/visual/api/layout/reorder
     */
    public function reorder()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id');
            $order = $this->request->getParam('order', '');
            
            if (!$pageId || !$order) {
                throw new \Exception('参数不完整');
            }
            
            $orderArray = json_decode($order, true);
            if (!is_array($orderArray)) {
                throw new \Exception('排序数据格式错误');
            }
            
            $result = $this->layoutService->reorderComponents($pageId, $orderArray);
            
            return $this->fetchJson([
                'success' => true,
                'message' => '组件排序更新成功',
                'layout_config' => $result['layout_config'],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 切换原始模板模式
     * POST /backend/visual/api/layout/toggleOriginal
     */
    public function toggleOriginal()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id');
            $useOriginal = $this->request->getParam('use_original', '1') === '1';
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            $result = $this->layoutService->toggleOriginalTemplate($pageId, $useOriginal);
            
            return $this->fetchJson([
                'success' => true,
                'message' => $useOriginal ? '已切换到原始模板' : '已切换到自定义布局',
                'use_original_template' => $result['use_original_template'],
                'layout_config' => $result['layout_config'],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
