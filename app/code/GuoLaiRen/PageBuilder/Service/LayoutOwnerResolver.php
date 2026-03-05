<?php

declare(strict_types=1);

/*
 * 布局拥有者解析服务
 * 
 * 负责解析页面的布局拥有者：
 * - 如果页面设置了 layout_page_id，则使用该目标页面的布局
 * - 否则使用页面自身的布局
 * 
 * 同时处理 header/footer 的全局继承逻辑：
 * - header/footer 始终从首页继承
 * - content 区域使用布局拥有者页面的配置
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\PageLayout;
use Weline\Framework\Manager\ObjectManager;

class LayoutOwnerResolver
{
    private Page $pageModel;
    private LayoutService $layoutService;
    
    public function __construct()
    {
        $this->pageModel = ObjectManager::getInstance(Page::class);
        $this->layoutService = ObjectManager::getInstance(LayoutService::class);
    }
    
    /**
     * 获取页面的布局拥有者页面ID
     * 
     * 解析逻辑：
     * 1. 如果页面设置了 layout_page_id，返回该ID
     * 2. 否则返回页面自身ID
     * 
     * @param Page $page 当前页面
     * @return int 布局拥有者页面ID
     */
    public function resolveLayoutOwnerPageId(Page $page): int
    {
        $layoutPageId = $page->getLayoutPageId();
        
        if ($layoutPageId) {
            // 验证 layout_page_id 指向的页面是否存在
            $layoutPage = clone $this->pageModel;
            $layoutPage->load($layoutPageId);
            
            if ($layoutPage->getId()) {
                return $layoutPageId;
            }
        }
        
        return (int)$page->getId();
    }
    
    /**
     * 根据页面ID获取布局拥有者页面ID
     * 
     * @param int $pageId 页面ID
     * @return int 布局拥有者页面ID
     */
    public function resolveLayoutOwnerPageIdByPageId(int $pageId): int
    {
        $page = clone $this->pageModel;
        $page->load($pageId);
        
        if (!$page->getId()) {
            return $pageId;
        }
        
        return $this->resolveLayoutOwnerPageId($page);
    }
    
    /**
     * 获取布局拥有者页面
     * 
     * @param Page $page 当前页面
     * @return Page 布局拥有者页面（如果没有设置 layout_page_id，返回页面自身）
     */
    public function getLayoutOwnerPage(Page $page): Page
    {
        $layoutOwnerPageId = $this->resolveLayoutOwnerPageId($page);
        
        if ($layoutOwnerPageId === (int)$page->getId()) {
            return $page;
        }
        
        $layoutOwnerPage = clone $this->pageModel;
        $layoutOwnerPage->load($layoutOwnerPageId);
        
        return $layoutOwnerPage->getId() ? $layoutOwnerPage : $page;
    }
    
    /**
     * 获取完整的布局配置（用于渲染）
     * 
     * 合并逻辑：
     * 1. header/footer 从首页继承（全局统一）
     * 2. content 从布局拥有者页面获取
     * 3. use_original_template 标志从布局拥有者页面获取
     * 4. 如果页面没有自定义布局配置，加载页面类型的默认布局配置
     * 5. 虚拟页面（id=0）直接使用默认布局配置，不访问数据库
     * 
     * @param Page $page 当前页面
     * @param bool $forBackend 是否为后台编辑场景（true时不检查首页发布状态）
     * @return array 完整布局配置
     */
    public function getFullLayoutConfig(Page $page, bool $forBackend = false): array
    {
        $layoutOwnerPageId = $this->resolveLayoutOwnerPageId($page);
        $pageType = $page->getData(Page::schema_fields_TYPE);
        $styleCode = $page->getData('style') ?: 'default';
        
        // 虚拟页面（id=0）处理：直接使用默认布局配置，不访问数据库
        if ($layoutOwnerPageId === 0) {
            $layoutConfig = [];
            if ($pageType) {
                $defaultConfig = $this->getDefaultLayoutConfigForPageType($styleCode, $pageType);
                if (!empty($defaultConfig)) {
                    $layoutConfig = $defaultConfig;
                }
            }
            return $layoutConfig;
        }
        
        // 获取布局拥有者的布局配置
        $layoutOwnerLayout = $this->layoutService->getOrCreate($layoutOwnerPageId);
        $layoutConfig = $layoutOwnerLayout->exportConfig();
        
        // 检查是否有自定义布局配置
        $hasCustomLayout = $this->hasCustomLayoutConfig($layoutConfig);
        
        // 如果没有自定义布局配置，加载页面类型的默认布局配置
        if (!$hasCustomLayout && $pageType) {
            $defaultConfig = $this->getDefaultLayoutConfigForPageType($styleCode, $pageType);
            if (!empty($defaultConfig)) {
                $layoutConfig = $defaultConfig;
            }
        }
        
        // 如果是首页，直接返回自身配置
        if ($pageType === Page::TYPE_HOME) {
            return $layoutConfig;
        }
        
        // 非首页：header/footer 从首页继承
        // 后台编辑时不检查首页发布状态，允许预览/编辑草稿状态首页的配置
        $homePage = $page->getHomePage(null, !$forBackend);
        if ($homePage && $homePage->getId()) {
            $homeLayout = $this->layoutService->getOrCreate((int)$homePage->getId());
            $homeConfig = $homeLayout->exportConfig();
            
            // 检查首页是否有自定义布局
            $homeHasCustomLayout = $this->hasCustomLayoutConfig($homeConfig);
            
            // 如果首页也没有自定义布局，尝试加载首页类型的默认布局
            if (!$homeHasCustomLayout) {
                $homeDefaultConfig = $this->getDefaultLayoutConfigForPageType($styleCode, Page::TYPE_HOME);
                if (!empty($homeDefaultConfig)) {
                    $homeConfig = $homeDefaultConfig;
                }
            }
            
            // 强制使用首页的 header/footer
            if (!empty($homeConfig['header'])) {
                $layoutConfig['header'] = $homeConfig['header'];
            }
            if (!empty($homeConfig['footer'])) {
                $layoutConfig['footer'] = $homeConfig['footer'];
            }
        }
        
        return $layoutConfig;
    }
    
    /**
     * 检查布局配置是否有自定义内容
     * 
     * @param array $layoutConfig 布局配置
     * @return bool 是否有自定义内容
     */
    private function hasCustomLayoutConfig(array $layoutConfig): bool
    {
        // 检查 header
        if (!empty($layoutConfig['header']) && is_array($layoutConfig['header'])) {
            if (!empty($layoutConfig['header']['component'])) {
                return true;
            }
        }
        
        // 检查 content
        if (!empty($layoutConfig['content']) && is_array($layoutConfig['content'])) {
            foreach ($layoutConfig['content'] as $component) {
                if (!empty($component['code'])) {
                    return true;
                }
            }
        }
        
        // 检查 footer
        if (!empty($layoutConfig['footer']) && is_array($layoutConfig['footer'])) {
            if (!empty($layoutConfig['footer']['component'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 获取页面类型的默认布局配置
     * 
     * 简化逻辑：直接使用页面类型代码作为文件名
     * 例如：blog_post → layouts/default/blog_post.json
     * 
     * @param string $styleCode 样式代码
     * @param string $pageType 页面类型
     * @return array 默认布局配置
     */
    private function getDefaultLayoutConfigForPageType(string $styleCode, string $pageType): array
    {
        if (empty($pageType)) {
            return [];
        }
        
        // 直接使用页面类型代码作为配置文件名
        $configFilePath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/layouts/default/{$pageType}.json";
        
        if (!file_exists($configFilePath)) {
            // 如果没有对应的配置文件，尝试 custom_page 作为 fallback
            $configFilePath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/layouts/default/custom_page.json";
            if (!file_exists($configFilePath)) {
                return [];
            }
        }
        
        $configData = json_decode(file_get_contents($configFilePath), true);
        
        if (empty($configData['layout_config'])) {
            return [];
        }
        
        $pageConfig = $configData['layout_config'];
        
        // 转换为 PageLayout 格式
        $result = [
            'header' => [],
            'content' => [],
            'footer' => [],
        ];
        
        // 转换 header（数组格式 -> PageLayout 格式）
        if (!empty($pageConfig['header']) && is_array($pageConfig['header'])) {
            $firstHeader = $pageConfig['header'][0] ?? null;
            if ($firstHeader && !empty($firstHeader['code'])) {
                $result['header'] = [
                    'component' => $firstHeader['code'],
                    'config' => $firstHeader['config'] ?? [],
                ];
            }
        }
        
        // 转换 content（保持数组格式）
        if (!empty($pageConfig['content']) && is_array($pageConfig['content'])) {
            $result['content'] = $pageConfig['content'];
        }
        
        // 转换 footer（数组格式 -> PageLayout 格式）
        if (!empty($pageConfig['footer']) && is_array($pageConfig['footer'])) {
            $firstFooter = $pageConfig['footer'][0] ?? null;
            if ($firstFooter && !empty($firstFooter['code'])) {
                $result['footer'] = [
                    'component' => $firstFooter['code'],
                    'config' => $firstFooter['config'] ?? [],
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * 根据页面ID获取完整布局配置
     * 
     * @param int $pageId 页面ID
     * @param bool $forBackend 是否为后台编辑场景（true时不检查首页发布状态）
     * @return array 完整布局配置
     */
    public function getFullLayoutConfigByPageId(int $pageId, bool $forBackend = false): array
    {
        $page = clone $this->pageModel;
        $page->load($pageId);
        
        if (!$page->getId()) {
            return [];
        }
        
        return $this->getFullLayoutConfig($page, $forBackend);
    }
    
    /**
     * 检查页面是否使用外部布局页面
     * 
     * @param Page $page 页面
     * @return bool
     */
    public function hasExternalLayoutPage(Page $page): bool
    {
        $layoutPageId = $page->getLayoutPageId();
        return $layoutPageId !== null && $layoutPageId !== (int)$page->getId();
    }
    
    /**
     * 获取布局页面信息（用于UI显示）
     * 
     * @param Page $page 页面
     * @return array|null 布局页面信息，或null如果使用自身布局
     */
    public function getLayoutPageInfo(Page $page): ?array
    {
        if (!$this->hasExternalLayoutPage($page)) {
            return null;
        }
        
        $layoutPage = $page->getLayoutPage();
        if (!$layoutPage) {
            return null;
        }
        
        return [
            'page_id' => $layoutPage->getId(),
            'name' => $layoutPage->getData(Page::schema_fields_NAME),
            'title' => $layoutPage->getData(Page::schema_fields_TITLE),
            'handle' => $layoutPage->getData(Page::schema_fields_HANDLE),
            'type' => $layoutPage->getData(Page::schema_fields_TYPE),
            'type_name' => $layoutPage->getTypeName(),
        ];
    }
    
    /**
     * 保存布局配置（处理 layout_page_id 的特殊逻辑）
     * 
     * 保存逻辑：
     * 1. 如果页面有 layout_page_id，content 保存到布局拥有者页面
     * 2. header/footer 始终保存到首页
     * 3. 如果页面没有 layout_page_id，使用 LayoutService 的默认保存逻辑
     * 
     * @param Page $page 当前页面
     * @param array $config 布局配置
     * @return PageLayout 保存后的布局对象
     */
    public function saveLayoutConfig(Page $page, array $config): PageLayout
    {
        $layoutOwnerPageId = $this->resolveLayoutOwnerPageId($page);
        $pageType = $page->getData(Page::schema_fields_TYPE);
        
        // 处理 header/footer：始终保存到首页
        if ($pageType !== Page::TYPE_HOME) {
            // 后台编辑时不检查首页发布状态（传入 false），允许保存到草稿状态的首页
            $homePage = $page->getHomePage(null, false);
            if ($homePage && $homePage->getId()) {
                $homePageId = (int)$homePage->getId();
                
                // 如果有 header 或 footer 配置，同步到首页
                $hasHeader = !empty($config['header']);
                $hasFooter = !empty($config['footer']);
                
                if ($hasHeader || $hasFooter) {
                    $homeLayout = $this->layoutService->getOrCreate($homePageId);
                    $homeConfig = $homeLayout->exportConfig();
                    
                    if ($hasHeader) {
                        $homeConfig['header'] = $config['header'];
                    }
                    if ($hasFooter) {
                        $homeConfig['footer'] = $config['footer'];
                    }
                    
                    $homeLayout->importConfig($homeConfig)->save();
                }
                
                // 从配置中移除 header/footer，只保留 content 到布局拥有者
                unset($config['header'], $config['footer']);
            }
        }
        
        // 保存 content 到布局拥有者页面
        $layout = $this->layoutService->getOrCreate($layoutOwnerPageId);
        $layout->importConfig($config)->save();
        
        return $layout;
    }
}
