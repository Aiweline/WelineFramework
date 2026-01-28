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
use GuoLaiRen\PageBuilder\Service\LayoutOwnerResolver;
use GuoLaiRen\PageBuilder\Model\Page;

class Layout extends BackendController
{
    private LayoutService $layoutService;
    private LayoutOwnerResolver $layoutOwnerResolver;
    private Page $pageModel;
    
    public function __construct()
    {
        $this->layoutService = ObjectManager::getInstance(LayoutService::class);
        $this->layoutOwnerResolver = ObjectManager::getInstance(LayoutOwnerResolver::class);
        $this->pageModel = ObjectManager::getInstance(Page::class);
    }
    
    /**
     * API: 获取布局配置
     * GET /backend/visual/api/layout/get
     * 
     * 特殊处理：
     * - 如果页面设置了 layout_page_id，返回该页面的布局配置
     * - header/footer 始终从首页继承
     * - 返回 layout_owner_page_id 供前端使用（API调用时使用此ID）
     */
    public function get()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id');
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            // 加载页面
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception('页面不存在');
            }
            
            // 解析布局拥有者页面ID
            $layoutOwnerPageId = $this->layoutOwnerResolver->resolveLayoutOwnerPageId($page);
            
            // 获取布局拥有者的布局
            $layout = $this->layoutService->getOrCreate($layoutOwnerPageId);
            
            // 获取完整布局配置（通过 LayoutOwnerResolver 处理继承）
            // 后台编辑时传入 true，允许访问草稿状态首页的 header/footer
            $fullConfig = $this->layoutOwnerResolver->getFullLayoutConfig($page, true);
            
            // 获取布局页面信息（如果使用外部布局页面）
            $layoutPageInfo = $this->layoutOwnerResolver->getLayoutPageInfo($page);
            
            return $this->fetchJson([
                'success' => true,
                'layout_id' => $layout->getId(),
                'layout_config' => $fullConfig,
                'page_id' => $pageId,
                'layout_owner_page_id' => $layoutOwnerPageId,
                'layout_page_info' => $layoutPageInfo,
                'uses_external_layout' => $this->layoutOwnerResolver->hasExternalLayoutPage($page),
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
     * 
     * 特殊处理：
     * - 如果页面设置了 layout_page_id，content 保存到布局拥有者页面
     * - header/footer 始终保存到首页（全局统一）
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
            
            // 加载页面
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception('页面不存在');
            }
            
            // 使用 LayoutOwnerResolver 保存配置（处理 layout_page_id 和 header/footer 继承）
            $layout = $this->layoutOwnerResolver->saveLayoutConfig($page, $config);
            
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
     * 
     * 特殊处理：
     * - 如果页面设置了 layout_page_id 且添加的是 content 组件，添加到布局拥有者页面
     * - header/footer 组件始终添加到首页
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
            
            // 解析目标页面ID（考虑 layout_page_id）
            $targetPageId = $pageId;
            if ($position === 'content') {
                // content 组件添加到布局拥有者页面
                $targetPageId = $this->layoutOwnerResolver->resolveLayoutOwnerPageIdByPageId($pageId);
            }
            // header/footer 组件由 LayoutService 内部处理（添加到首页）
            
            $result = $this->layoutService->addComponent(
                $targetPageId,
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
                'target_page_id' => $targetPageId,
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
     * 
     * 特殊处理：
     * - 操作布局拥有者页面的布局（考虑 layout_page_id）
     */
    public function removeComponent()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id');
            $instanceId = $this->request->getParam('instance_id', '');
            
            if (!$pageId || !$instanceId) {
                throw new \Exception('参数不完整');
            }
            
            // 解析目标页面ID（考虑 layout_page_id）
            $targetPageId = $this->layoutOwnerResolver->resolveLayoutOwnerPageIdByPageId($pageId);
            
            $result = $this->layoutService->removeComponent($targetPageId, $instanceId);
            
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
     * 
     * 特殊处理：
     * - 操作布局拥有者页面的布局（考虑 layout_page_id）
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
            
            // 解析目标页面ID（考虑 layout_page_id）
            $targetPageId = $this->layoutOwnerResolver->resolveLayoutOwnerPageIdByPageId($pageId);
            
            $configArray = json_decode($config, true) ?: [];
            $result = $this->layoutService->updateComponent($targetPageId, $instanceId, $configArray);
            
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
     * 
     * 特殊处理：
     * - 操作布局拥有者页面的布局（考虑 layout_page_id）
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
            
            // 解析目标页面ID（考虑 layout_page_id）
            $targetPageId = $this->layoutOwnerResolver->resolveLayoutOwnerPageIdByPageId($pageId);
            
            $result = $this->layoutService->reorderComponents($targetPageId, $orderArray);
            
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
     * 
     * 特殊处理：
     * - 操作布局拥有者页面的布局（考虑 layout_page_id）
     */
    public function toggleOriginal()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id');
            $useOriginal = $this->request->getParam('use_original', '1') === '1';
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            // 解析目标页面ID（考虑 layout_page_id）
            $targetPageId = $this->layoutOwnerResolver->resolveLayoutOwnerPageIdByPageId($pageId);
            
            $result = $this->layoutService->toggleOriginalTemplate($targetPageId, $useOriginal);
            
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
