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
     * 获取页面完整布局配置（包含从首页继承的 header/footer）
     * 
     * @param int $pageId 页面ID
     * @return array 完整布局配置
     */
    public function getFullLayoutConfig(int $pageId): array
    {
        $layout = $this->getOrCreate($pageId);
        $config = $layout->exportConfig();
        
        // 获取页面信息
        $pageModel = ObjectManager::getInstance(Page::class);
        $page = clone $pageModel;
        $page->load($pageId);
        
        if (!$page->getId()) {
            return $config;
        }
        
        $pageType = $page->getData(Page::schema_fields_TYPE);
        
        // 首页直接返回自己的配置
        if ($pageType === Page::TYPE_HOME) {
            return $config;
        }
        
        // 子页面：从首页继承 header/footer
        $homePage = $page->getHomePage();
        if ($homePage && $homePage->getId()) {
            $homeLayout = $this->getOrCreate((int)$homePage->getId());
            $homeConfig = $homeLayout->exportConfig();
            
            // header/footer 从首页继承
            if (!empty($homeConfig['header'])) {
                $config['header'] = $homeConfig['header'];
            }
            if (!empty($homeConfig['footer'])) {
                $config['footer'] = $homeConfig['footer'];
            }
        }
        
        return $config;
    }
    
    /**
     * 初始化页面布局（从页面模板）
     */
    public function initializeFromPage(PageLayout $layout, Page $page): PageLayout
    {
        $styleCode = $page->getData(Page::schema_fields_STYLE);
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
     * 
     * 特殊处理：
     * - 子页面修改 header/footer 时，实际保存到首页
     * - 子页面只保存自己的 content 配置
     */
    public function saveConfig(int $pageId, array $config): PageLayout
    {
        $layout = $this->getOrCreate($pageId);
        
        // 获取页面信息
        $pageModel = ObjectManager::getInstance(Page::class);
        $page = clone $pageModel;
        $page->load($pageId);
        
        if ($page->getId()) {
            $pageType = $page->getData(Page::schema_fields_TYPE);
            $isHomePage = ($pageType === Page::TYPE_HOME);
            
            if (!$isHomePage) {
                // 子页面：header/footer 保存到首页，content 保存到自己
                $this->syncHeaderFooterToHome($page, $config);
                
                // 从配置中移除 header/footer，只保留 content
                unset($config['header'], $config['footer']);
            }
        }
        
        $layout->importConfig($config)->save();
        return $layout;
    }
    
    /**
     * 将子页面的 header/footer 配置同步到首页
     */
    private function syncHeaderFooterToHome(Page $childPage, array $config): void
    {
        $homePage = $childPage->getHomePage();
        if (!$homePage || !$homePage->getId()) {
            return;
        }
        
        // 检查是否有 header 或 footer 配置需要同步
        $hasHeader = !empty($config['header']);
        $hasFooter = !empty($config['footer']);
        
        if (!$hasHeader && !$hasFooter) {
            return;
        }
        
        // 获取首页的布局
        $homeLayout = $this->getOrCreate((int)$homePage->getId());
        $homeConfig = $homeLayout->exportConfig();
        
        // 同步 header
        if ($hasHeader) {
            $homeConfig['header'] = $config['header'];
        }
        
        // 同步 footer
        if ($hasFooter) {
            $homeConfig['footer'] = $config['footer'];
        }
        
        // 保存首页布局
        $homeLayout->importConfig($homeConfig)->save();
    }
    
    /**
     * 添加组件到布局
     * 
     * 特殊处理：
     * - 子页面添加 header/footer 组件时，实际添加到首页
     */
    public function addComponent(
        int $pageId,
        string $componentCode,
        string $fromTemplate = '',
        string $position = 'content',
        ?int $sortOrder = null
    ): array {
        // 获取页面信息
        $pageModel = ObjectManager::getInstance(Page::class);
        $page = clone $pageModel;
        $page->load($pageId);
        
        $targetPageId = $pageId;
        $isHeaderFooter = in_array($position, ['header', 'footer']);
        
        // 如果是子页面添加 header/footer，则添加到首页
        if ($page->getId() && $isHeaderFooter) {
            $pageType = $page->getData(Page::schema_fields_TYPE);
            if ($pageType !== Page::TYPE_HOME) {
                $homePage = $page->getHomePage();
                if ($homePage && $homePage->getId()) {
                    $targetPageId = (int)$homePage->getId();
                }
            }
        }
        
        $layout = $this->getOrCreate($targetPageId);
        $instanceId = null;
        
        switch ($position) {
            case 'header':
                $layout->setData(PageLayout::schema_fields_HEADER_COMPONENT, $componentCode);
                break;
            case 'footer':
                $layout->setData(PageLayout::schema_fields_FOOTER_COMPONENT, $componentCode);
                break;
            default:
                $instanceId = $layout->addContentComponent($componentCode, [], $fromTemplate, $sortOrder);
                break;
        }
        
        // 切换到自定义布局模式
        $layout->useOriginalTemplate(false)->save();
        
        // 如果修改的是首页的 header/footer，返回合并后的配置
        if ($targetPageId !== $pageId && $isHeaderFooter) {
            // 获取当前页面的布局配置
            $currentLayout = $this->getOrCreate($pageId);
            $currentConfig = $currentLayout->exportConfig();
            $homeConfig = $layout->exportConfig();
            
            // 合并首页的 header/footer 到当前页面配置
            $currentConfig['header'] = $homeConfig['header'] ?? [];
            $currentConfig['footer'] = $homeConfig['footer'] ?? [];
            
            return [
                'instance_id' => $instanceId,
                'layout_config' => $currentConfig,
                'synced_to_home' => true,
            ];
        }
        
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
