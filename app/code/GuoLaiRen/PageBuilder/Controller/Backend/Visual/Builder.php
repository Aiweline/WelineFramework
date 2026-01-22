<?php

declare(strict_types=1);

/*
 * 可视化构建器主控制器 - 负责页面渲染
 * 遵循单一职责原则(SRP) - 只负责页面展示逻辑
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend\Visual;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use GuoLaiRen\PageBuilder\Service\ComponentService;
use GuoLaiRen\PageBuilder\Service\LayoutService;
use GuoLaiRen\PageBuilder\Service\PageService;

class Builder extends BackendController
{
    private PageService $pageService;
    private ComponentService $componentService;
    private LayoutService $layoutService;
    
    public function __construct()
    {
        $this->pageService = ObjectManager::getInstance(PageService::class);
        $this->componentService = ObjectManager::getInstance(ComponentService::class);
        $this->layoutService = ObjectManager::getInstance(LayoutService::class);
    }
    
    /**
     * 可视化构建器主页面
     */
    public function index()
    {
        $pageId = (int)$this->request->getParam('page_id');
        
        if (!$pageId) {
            $this->getMessageManager()->addError(__('缺少页面ID参数'));
            return $this->redirect($this->getBackendUrl('*/backend/page/index'));
        }
        
        // 获取页面
        $page = $this->pageService->getById($pageId);
        if (!$page) {
            $this->getMessageManager()->addError(__('页面不存在'));
            return $this->redirect($this->getBackendUrl('*/backend/page/index'));
        }
        
        $styleCode = $this->pageService->getStyleCode($page);
        
        // 扫描并注册组件
        $this->componentService->scanAndRegister($styleCode);
        
        // 获取或创建页面布局
        $layout = $this->layoutService->getOrCreate($pageId);
        
        // 如果布局是新创建的且有样式，初始化布局
        if ($layout->isUsingOriginalTemplate() && empty($layout->getContentComponents()) && $styleCode) {
            $this->layoutService->initializeFromPage($layout, $page);
            $layout->save();
        }
        
        // 获取组件
        $components = $this->componentService->getComponentsByStyle($styleCode);
        
        // 获取所有可用的样式模板
        $styles = $this->pageService->getAvailableStyles();
        
        $this->assign([
            'page' => $page,
            'page_id' => $pageId,
            'style_code' => $styleCode,
            'layout' => $layout,
            'layout_config' => $layout->exportConfig(),
            'own_components' => $components['own'],
            'compatible_components' => $components['compatible'],
            'styles' => $styles,
            'page_title' => __('可视化页面构建器') . ' - ' . $page->getData('name'),
        ]);
        
        return $this->fetch('visual/builder');
    }
}
