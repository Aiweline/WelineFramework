<?php

declare(strict_types=1);

/*
 * 布局服务类 - 负责页面布局相关的业务逻辑
 * 遵循单一职责原则(SRP)
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\PageLayout;
use Weline\Framework\Manager\ObjectManager;

class LayoutService
{
    private ComponentService $componentService;
    
    public function __construct()
    {
        $this->componentService = ObjectManager::getInstance(ComponentService::class);
    }
    
    /**
     * 获取或创建页面布局
     */
    public function getOrCreate(int $pageId): PageLayout
    {
        return PageLayout::getOrCreateForPage($pageId);
    }
    
    /**
     * 根据页面ID获取布局
     */
    public function getByPageId(int $pageId): ?PageLayout
    {
        return PageLayout::getByPageId($pageId);
    }
    
    /**
     * 初始化页面布局（从页面模板）
     */
    public function initializeFromPage(PageLayout $layout, Page $page): PageLayout
    {
        $styleCode = $page->getData(Page::fields_STYLE);
        if (empty($styleCode)) {
            return $layout;
        }
        
        // 扫描组件
        $this->componentService->scanAndRegister($styleCode);
        
        // 初始化布局
        return $layout->initializeFromPage($page);
    }
    
    /**
     * 保存布局配置
     */
    public function saveConfig(int $pageId, array $config): PageLayout
    {
        $layout = $this->getOrCreate($pageId);
        $layout->importConfig($config)->save();
        return $layout;
    }
    
    /**
     * 添加组件到布局
     */
    public function addComponent(
        int $pageId,
        string $componentCode,
        string $fromTemplate = '',
        string $position = 'content',
        ?int $sortOrder = null
    ): array {
        $layout = $this->getOrCreate($pageId);
        $instanceId = null;
        
        switch ($position) {
            case 'header':
                $layout->setData(PageLayout::fields_HEADER_COMPONENT, $componentCode);
                break;
            case 'footer':
                $layout->setData(PageLayout::fields_FOOTER_COMPONENT, $componentCode);
                break;
            default:
                $instanceId = $layout->addContentComponent($componentCode, [], $fromTemplate, $sortOrder);
                break;
        }
        
        // 切换到自定义布局模式
        $layout->useOriginalTemplate(false)->save();
        
        return [
            'instance_id' => $instanceId,
            'layout_config' => $layout->exportConfig(),
        ];
    }
    
    /**
     * 移除组件
     */
    public function removeComponent(int $pageId, string $instanceId): array
    {
        $layout = $this->getByPageId($pageId);
        if (!$layout) {
            throw new \Exception('布局不存在');
        }
        
        if (!$layout->removeContentComponent($instanceId)) {
            throw new \Exception('组件不存在');
        }
        
        $layout->save();
        
        return ['layout_config' => $layout->exportConfig()];
    }
    
    /**
     * 更新组件配置
     */
    public function updateComponent(int $pageId, string $instanceId, array $config): array
    {
        $layout = $this->getByPageId($pageId);
        if (!$layout) {
            throw new \Exception('布局不存在');
        }
        
        if (!$layout->updateContentComponent($instanceId, $config)) {
            throw new \Exception('组件不存在');
        }
        
        $layout->save();
        
        return ['layout_config' => $layout->exportConfig()];
    }
    
    /**
     * 重新排序组件
     */
    public function reorderComponents(int $pageId, array $order): array
    {
        $layout = $this->getByPageId($pageId);
        if (!$layout) {
            throw new \Exception('布局不存在');
        }
        
        $layout->reorderContentComponents($order);
        $layout->save();
        
        return ['layout_config' => $layout->exportConfig()];
    }
    
    /**
     * 切换原始模板模式
     */
    public function toggleOriginalTemplate(int $pageId, bool $useOriginal): array
    {
        $layout = $this->getOrCreate($pageId);
        $layout->useOriginalTemplate($useOriginal)->save();
        
        return [
            'use_original_template' => $useOriginal,
            'layout_config' => $layout->exportConfig(),
        ];
    }
}
