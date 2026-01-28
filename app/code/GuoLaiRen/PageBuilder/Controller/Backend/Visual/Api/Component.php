<?php

declare(strict_types=1);

/*
 * 组件API控制器 - 负责组件相关的API请求
 * 遵循单一职责原则(SRP) - 只负责组件API
 * 
 * 重要逻辑（基于页面层级）：
 * - 首页类型（home_page）的页面保存 header、footer、content
 * - 子页面只保存 content，header/footer 从首页获取
 * - 组件按顺序排列
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend\Visual\Api;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use GuoLaiRen\PageBuilder\Service\ComponentService;
use GuoLaiRen\PageBuilder\Service\LayoutAssembler;
use GuoLaiRen\PageBuilder\Service\LayoutService;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\PageLayout;

class Component extends BackendController
{
    private ComponentService $componentService;
    private LayoutAssembler $layoutAssembler;
    private LayoutService $layoutService;
    private Page $pageModel;
    
    public function __construct()
    {
        $this->componentService = ObjectManager::getInstance(ComponentService::class);
        $this->layoutAssembler = ObjectManager::getInstance(LayoutAssembler::class);
        $this->layoutService = ObjectManager::getInstance(LayoutService::class);
        $this->pageModel = ObjectManager::getInstance(Page::class);
    }
    
    /**
     * API: 获取组件列表
     * GET /backend/visual/api/component/list
     * 
     * 返回结构：
     * - recommended: 推荐组件（当前模板专属）
     * - shared: 共享组件（跨模板通用）
     * - other_templates: 其他模板的兼容组件
     * - by_category: 按分类分组
     * - by_region: 按区域分组（如果指定了布局）
     * - default_layout_config: 页面类型对应的默认布局配置
     */
    public function list()
    {
        // 清除之前可能存在的输出缓冲（防止PHP警告/错误混入JSON响应）
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        
        // #region agent log
        $debugLog = function($msg, $data, $hyp) { @file_put_contents(BP . '.cursor/debug.log', json_encode(['location' => 'Component.php:list', 'message' => $msg, 'data' => $data, 'hypothesisId' => $hyp, 'timestamp' => microtime(true)]) . "\n", FILE_APPEND); };
        // #endregion
        try {
            $styleCode = $this->request->getParam('style_code', '');
            $layoutCode = $this->request->getParam('layout_code', '');
            $pageType = $this->request->getParam('page_type', ''); // 页面类型
            $includeCompatible = $this->request->getParam('include_compatible', '1') === '1';
            
            // #region agent log
            $debugLog('API params', ['styleCode' => $styleCode, 'layoutCode' => $layoutCode, 'pageType' => $pageType, 'includeCompatible' => $includeCompatible], 'A');
            // #endregion
            
            // 先扫描共享组件
            $this->componentService->scanAndRegister('_shared');
            
            // 扫描当前模板组件
            if ($styleCode) {
                $this->componentService->scanAndRegister($styleCode);
            }
            
            // 如果包含兼容组件，扫描所有其他模板
            if ($includeCompatible) {
                // #region agent log
                $debugLog('Before scanAndRegisterAll', [], 'E');
                // #endregion
                $this->componentService->scanAndRegisterAll();
                // #region agent log
                $debugLog('After scanAndRegisterAll', [], 'E');
                // #endregion
            }
            
            // 是否包含预览HTML（默认不包含，减小响应大小）
            $includePreview = $this->request->getParam('include_preview', '0') === '1';
            
            // #region agent log
            $debugLog('Before getComponentsForBuilder', ['includePreview' => $includePreview], 'B');
            // #endregion
            
            // 获取为构建器格式化的组件数据
            $data = $this->componentService->getComponentsForBuilder($styleCode, $layoutCode ?: null, $includePreview, $pageType ?: null);
            
            // #region agent log
            $debugLog('After getComponentsForBuilder success', ['dataKeys' => array_keys($data ?? [])], 'B');
            // #endregion
            
            // 清除缓冲区中可能存在的PHP警告/错误输出
            $unexpectedOutput = ob_get_clean();
            if (!empty($unexpectedOutput)) {
                // 记录意外输出到日志
                $debugLog('Unexpected output before JSON', ['output' => substr($unexpectedOutput, 0, 1000)], 'WARNING');
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            // #region agent log
            $debugLog('Exception caught', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 'ALL');
            // #endregion
            
            // 清除缓冲区
            $unexpectedOutput = ob_get_clean();
            if (!empty($unexpectedOutput)) {
                $debugLog('Unexpected output before JSON (exception)', ['output' => substr($unexpectedOutput, 0, 1000)], 'WARNING');
            }
            
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 获取组件列表（旧版兼容）
     * GET /backend/visual/api/component/listLegacy
     */
    public function listLegacy()
    {
        try {
            $styleCode = $this->request->getParam('style_code', '');
            $includeCompatible = $this->request->getParam('include_compatible', '1') === '1';
            
            // 扫描组件
            $this->componentService->scanAndRegister($styleCode);
            
            // 获取组件
            $components = $this->componentService->getComponentsByStyle($styleCode, $includeCompatible);
            
            $result = [
                'success' => true,
                'own' => $this->componentService->toArrayBatch($components['own']),
                'shared' => $this->componentService->toArrayBatch($components['shared'] ?? []),
                'compatible' => [],
            ];
            
            // 转换兼容组件
            foreach ($components['compatible'] as $templateCode => $templateComponents) {
                $result['compatible'][$templateCode] = $this->componentService->toArrayBatch($templateComponents);
            }
            
            return $this->fetchJson($result);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 预览组件
     * POST /backend/visual/api/component/preview
     */
    public function postPreview()
    {
        try {
            $componentCode = $this->request->getParam('component_code', '');
            $styleCode = $this->request->getParam('style_code', '');
            $config = $this->request->getParam('config', '');
            
            if (!$componentCode) {
                throw new \Exception('缺少组件代码');
            }
            
            $component = $this->componentService->getByCode($componentCode);
            if (!$component) {
                throw new \Exception('组件不存在: ' . $componentCode);
            }
            
            // 检查组件文件是否存在
            if (!$component->fileExists()) {
                $path = $component->getData(\GuoLaiRen\PageBuilder\Model\Component::fields_PATH);
                throw new \Exception('组件文件不存在: ' . $path);
            }
            
            $configArray = json_decode($config, true) ?: [];
            $html = $this->componentService->renderPreview($componentCode, $configArray);
            
            // 如果渲染结果为空或只包含注释，尝试提供更详细的错误信息
            $htmlTrimmed = trim(strip_tags($html));
            if (empty($htmlTrimmed) || strpos($html, '组件渲染错误') !== false) {
                // 提取错误信息
                if (preg_match('/<!--\s*组件渲染错误:\s*(.+?)\s*-->/', $html, $matches)) {
                    throw new \Exception('组件渲染失败: ' . $matches[1]);
                }
                // 如果没有明确的错误信息，提供通用提示
                if (empty($htmlTrimmed)) {
                    throw new \Exception('组件渲染结果为空，请检查组件模板文件是否正确');
                }
            }
            
            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'component' => $this->componentService->toArray($component),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 获取单个组件信息
     * GET /backend/visual/api/component/info
     */
    public function info()
    {
        try {
            $componentCode = $this->request->getParam('component_code', '');
            
            if (!$componentCode) {
                throw new \Exception('缺少组件代码');
            }
            
            $component = $this->componentService->getByCode($componentCode);
            
            if (!$component) {
                throw new \Exception('组件不存在');
            }
            
            return $this->fetchJson([
                'success' => true,
                'component' => $this->componentService->toArray($component),
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
     * POST /backend/visual/api/component/add
     * 
     * 重要逻辑（基于页面层级）：
     * - 首页类型（home_page）保存 header、footer、content
     * - 子页面只保存 content，header/footer 需要保存到首页
     * - Content 组件按顺序排列，可指定位置
     * 
     * 注意：使用 LayoutService 保存到 PageLayout 表，确保与渲染系统数据一致
     */
    public function postAdd()
    {
        try {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            
            $pageId = (int)($body['page_id'] ?? 0);
            $componentCode = $body['component_code'] ?? '';
            $region = $body['region'] ?? '';
            $replace = $body['replace'] ?? false;
            $templateCode = $body['template_code'] ?? '';
            $position = $body['position'] ?? null; // 插入位置（用于 content 排序）
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            if (!$componentCode) {
                throw new \Exception('缺少组件代码');
            }
            
            if (!$region) {
                throw new \Exception('缺少区域');
            }
            
            // 加载当前页面
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception('页面不存在');
            }
            
            $pageType = $page->getData('type');
            $isHomePage = ($pageType === Page::TYPE_HOME);
            $isGlobalRegion = in_array($region, ['header', 'footer']);
            
            // 确定要更新的目标页面ID
            // - Content 区域：总是保存到当前页面
            // - Header/Footer 区域：保存到首页
            $targetPageId = $pageId;
            if ($isGlobalRegion && !$isHomePage) {
                $homePage = $this->findHomePage($page);
                if ($homePage && $homePage->getId()) {
                    $targetPageId = (int)$homePage->getId();
                } else {
                    throw new \Exception('未找到首页，无法保存全局组件（header/footer）');
                }
            }
            
            // 使用 LayoutService 获取或创建 PageLayout
            $layout = $this->layoutService->getOrCreate($targetPageId);
            
            // 创建新组件配置
            $newComponent = [
                'code' => $componentCode,
                'enabled' => true,
                'config' => [],
                'template_code' => $templateCode,
            ];
            
            // 根据区域类型处理
            if ($region === 'header') {
                // Header 区域只能一个，直接替换
                $layout->setData(PageLayout::fields_HEADER_COMPONENT, $componentCode);
                $layout->setData(PageLayout::fields_HEADER_CONFIG, json_encode([]));
            } elseif ($region === 'footer') {
                // Footer 区域只能一个，直接替换
                $layout->setData(PageLayout::fields_FOOTER_COMPONENT, $componentCode);
                $layout->setData(PageLayout::fields_FOOTER_CONFIG, json_encode([]));
            } else {
                // Content 区域可以多个，按位置插入或添加到末尾
                $contentComponents = $layout->getContentComponents();
                if ($position !== null && $position >= 0 && $position < count($contentComponents)) {
                    array_splice($contentComponents, $position, 0, [$newComponent]);
                } else {
                    $contentComponents[] = $newComponent;
                }
                $layout->setContentComponents($contentComponents);
            }
            
            // 保存 PageLayout（切换到自定义布局模式）
            $layout->useOriginalTemplate(false)->save();
            
            // 同时同步到 Page.layout_config 保持向后兼容
            $layoutConfig = $layout->exportConfig();
            $this->syncLayoutConfigToPage($targetPageId, $layoutConfig);
            
            // 记录日志
            error_log("[Component API add()] Page ID: {$pageId}, Type: {$pageType}, IsHome: " . ($isHomePage ? 'yes' : 'no'));
            error_log("[Component API add()] Target Page ID: {$targetPageId}, Region: {$region}, Component: {$componentCode}");
            error_log("[Component API add()] Saved to PageLayout successfully");
            
            return $this->fetchJson([
                'success' => true,
                'message' => $isGlobalRegion && !$isHomePage 
                    ? __('全局组件已保存到首页') 
                    : __('组件已添加'),
                'layout_config' => $layoutConfig,
                'target_page_id' => $targetPageId,
                'is_global' => $isGlobalRegion,
                'saved_to_home' => $isGlobalRegion && !$isHomePage,
            ]);
        } catch (\Exception $e) {
            error_log("[Component API add()] Error: " . $e->getMessage());
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 同步布局配置到 Page.layout_config（向后兼容）
     * 
     * 注意：$layoutConfig 来自 PageLayout::exportConfig()，其中 content 已经使用 'code' 字段
     */
    private function syncLayoutConfigToPage(int $pageId, array $layoutConfig): void
    {
        try {
            // 转换 PageLayout 格式到 Page.layout_config 格式
            $pageLayoutConfig = [
                'header' => [],
                'content' => [],
                'footer' => [],
            ];
            
            // content 数组已经是正确的格式（exportConfig 已规范化为使用 'code' 字段）
            // 只需要确保字段名正确
            foreach ($layoutConfig['content'] ?? [] as $comp) {
                $pageLayoutConfig['content'][] = [
                    'code' => $comp['code'] ?? $comp['component'] ?? '',
                    'enabled' => $comp['enabled'] ?? true,
                    'config' => $comp['config'] ?? [],
                    'instance_id' => $comp['instance_id'] ?? $comp['id'] ?? '',
                ];
            }
            
            // 转换 header（exportConfig 格式为 {component: ..., config: ...}）
            if (!empty($layoutConfig['header']['component'])) {
                $pageLayoutConfig['header'] = [[
                    'code' => $layoutConfig['header']['component'],
                    'enabled' => true,
                    'config' => $layoutConfig['header']['config'] ?? [],
                ]];
            }
            
            // 转换 footer
            if (!empty($layoutConfig['footer']['component'])) {
                $pageLayoutConfig['footer'] = [[
                    'code' => $layoutConfig['footer']['component'],
                    'enabled' => true,
                    'config' => $layoutConfig['footer']['config'] ?? [],
                ]];
            }
            
            $page = clone $this->pageModel;
            $page->load($pageId);
            if ($page->getId()) {
                $page->setData(Page::fields_LAYOUT_CONFIG, json_encode($pageLayoutConfig, JSON_UNESCAPED_UNICODE));
                $page->save();
            }
        } catch (\Throwable $e) {
            error_log("[Component API syncLayoutConfigToPage()] Error: " . $e->getMessage());
        }
    }
    
    /**
     * 查找首页
     * 通过 parent_id 向上查找，或者直接查找类型为 home_page 的页面
     * 
     * @param Page $page 当前页面
     * @return Page|null 首页对象
     */
    private function findHomePage(Page $page): ?Page
    {
        // 方式1：如果当前页面有 parent_id，向上查找直到找到首页
        $parentId = $page->getData('parent_id');
        if ($parentId) {
            $parentPage = clone $this->pageModel;
            $parentPage->load($parentId);
            
            if ($parentPage->getId()) {
                // 如果父页面是首页，返回它
                if ($parentPage->getData('type') === Page::TYPE_HOME) {
                    return $parentPage;
                }
                // 否则继续向上查找
                return $this->findHomePage($parentPage);
            }
        }
        
        // 方式2：直接查找类型为 home_page 的页面
        // 如果当前页面有 website_id，在同一站点查找；否则查找任意首页
        $homePage = clone $this->pageModel;
        $homePage->where('type', Page::TYPE_HOME);
        
        $websiteId = $page->getData('website_id');
        if ($websiteId) {
            $homePage->where('website_id', $websiteId);
        }
        
        $homePage->find()->fetch();
        
        return $homePage->getId() ? $homePage : null;
    }
    
    /**
     * API: 从布局中移除组件
     * POST /backend/visual/api/component/remove
     * 
     * 重要逻辑（基于页面层级）：
     * - Content 组件从当前页面删除
     * - Header/Footer 组件从首页删除
     * 
     * 注意：使用 LayoutService 保存到 PageLayout 表，确保与渲染系统数据一致
     */
    public function postRemove()
    {
        try {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            
            $pageId = (int)($body['page_id'] ?? 0);
            $componentCode = $body['component_code'] ?? '';
            $region = $body['region'] ?? '';
            $index = $body['index'] ?? null;
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            if (!$componentCode && $index === null) {
                throw new \Exception('缺少组件代码或索引');
            }
            
            if (!$region) {
                throw new \Exception('缺少区域');
            }
            
            // 加载当前页面
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception('页面不存在');
            }
            
            $pageType = $page->getData('type');
            $isHomePage = ($pageType === Page::TYPE_HOME);
            $isGlobalRegion = in_array($region, ['header', 'footer']);
            
            // 确定要更新的目标页面ID
            // - Content 区域：总是从当前页面删除
            // - Header/Footer 区域：从首页删除
            $targetPageId = $pageId;
            if ($isGlobalRegion && !$isHomePage) {
                $homePage = $this->findHomePage($page);
                if ($homePage && $homePage->getId()) {
                    $targetPageId = (int)$homePage->getId();
                } else {
                    throw new \Exception('未找到首页，无法删除全局组件（header/footer）');
                }
            }
            
            // 使用 LayoutService 获取 PageLayout
            $layout = $this->layoutService->getOrCreate($targetPageId);
            
            // 记录日志
            error_log("[Component API remove()] Page ID: {$pageId}, Type: {$pageType}, IsHome: " . ($isHomePage ? 'yes' : 'no'));
            error_log("[Component API remove()] Target Page ID: {$targetPageId}, Region: {$region}, Component: {$componentCode}");
            
            // 根据区域类型处理删除
            $removedCount = 0;
            if ($region === 'header') {
                // 清空 header 组件
                $currentComponent = $layout->getData(PageLayout::fields_HEADER_COMPONENT);
                if (!empty($currentComponent)) {
                    $layout->setData(PageLayout::fields_HEADER_COMPONENT, '');
                    $layout->setData(PageLayout::fields_HEADER_CONFIG, '{}');
                    $removedCount = 1;
                }
            } elseif ($region === 'footer') {
                // 清空 footer 组件
                $currentComponent = $layout->getData(PageLayout::fields_FOOTER_COMPONENT);
                if (!empty($currentComponent)) {
                    $layout->setData(PageLayout::fields_FOOTER_COMPONENT, '');
                    $layout->setData(PageLayout::fields_FOOTER_CONFIG, '{}');
                    $removedCount = 1;
                }
            } else {
                // Content 区域：按索引或代码移除
                $contentComponents = $layout->getContentComponents();
                
                // 如果数据库中没有组件但要删除，先从默认布局配置初始化
                if (empty($contentComponents) && $index !== null) {
                    $targetPage = clone $this->pageModel;
                    $targetPage->load($targetPageId);
                    $styleCode = $targetPage->getData(Page::fields_STYLE);
                    $targetPageType = $targetPage->getData(Page::fields_TYPE);
                    
                    if ($styleCode && $targetPageType) {
                        $defaultConfig = $this->componentService->getDefaultLayoutConfigForPageType($styleCode, $targetPageType);
                        if ($defaultConfig && !empty($defaultConfig['layout_config']['content'])) {
                            $contentComponents = $this->convertDefaultComponentsToLayout(
                                $defaultConfig['layout_config']['content'],
                                $styleCode
                            );
                        }
                    }
                }
                
                if ($index !== null) {
                    // 按索引移除
                    if (isset($contentComponents[$index])) {
                        array_splice($contentComponents, $index, 1);
                        $removedCount = 1;
                    }
                } else {
                    // 按代码移除（移除所有匹配的组件）- 兼容两种存储格式
                    $originalCount = count($contentComponents);
                    $contentComponents = array_values(array_filter(
                        $contentComponents,
                        fn($comp) => ($comp['component'] ?? $comp['code'] ?? '') !== $componentCode
                    ));
                    $removedCount = $originalCount - count($contentComponents);
                }
                
                $layout->setContentComponents($contentComponents);
            }
            
            // 保存 PageLayout
            $layout->save();
            
            // 导出最新配置
            $layoutConfig = $layout->exportConfig();
            
            // 同步到 Page.layout_config 保持向后兼容
            $this->syncLayoutConfigToPage($targetPageId, $layoutConfig);
            
            error_log("[Component API remove()] Removed {$removedCount} component(s) from PageLayout");
            
            return $this->fetchJson([
                'success' => true,
                'message' => $isGlobalRegion && !$isHomePage 
                    ? __('全局组件已从首页删除') 
                    : __('组件已移除'),
                'layout_config' => $layoutConfig,
                'target_page_id' => $targetPageId,
                'is_global' => $isGlobalRegion,
                'removed_count' => $removedCount,
                'removed_from_home' => $isGlobalRegion && !$isHomePage,
            ]);
        } catch (\Exception $e) {
            error_log("[Component API remove()] Error: " . $e->getMessage());
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 更新组件配置
     * POST /backend/visual/api/component/updateConfig
     * 
     * 重要逻辑（基于页面层级）：
     * - Content 区域的组件配置保存到当前页面
     * - Header/Footer 区域的组件配置保存到首页
     * 
     * 注意：使用 LayoutService 保存到 PageLayout 表，确保与渲染系统数据一致
     */
    public function postUpdateConfig()
    {
        try {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            
            $pageId = (int)($body['page_id'] ?? 0);
            $componentCode = $body['component_code'] ?? '';
            $region = $body['region'] ?? '';
            $index = $body['index'] ?? 0;
            $config = $body['config'] ?? [];
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            if (!$componentCode) {
                throw new \Exception('缺少组件代码');
            }
            
            if (!$region) {
                throw new \Exception('缺少区域');
            }
            
            // 加载当前页面
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception('页面不存在');
            }
            
            $pageType = $page->getData('type');
            $isHomePage = ($pageType === Page::TYPE_HOME);
            $isGlobalRegion = in_array($region, ['header', 'footer']);
            
            // 确定要更新的目标页面ID
            $targetPageId = $pageId;
            if ($isGlobalRegion && !$isHomePage) {
                $homePage = $this->findHomePage($page);
                if ($homePage && $homePage->getId()) {
                    $targetPageId = (int)$homePage->getId();
                } else {
                    throw new \Exception('未找到首页，无法更新全局组件配置');
                }
            }
            
            // 使用 LayoutService 获取 PageLayout
            $layout = $this->layoutService->getOrCreate($targetPageId);
            
            // 根据区域类型更新配置
            if ($region === 'header') {
                // 验证组件代码
                $currentComponent = $layout->getData(PageLayout::fields_HEADER_COMPONENT);
                if ($currentComponent !== $componentCode) {
                    throw new \Exception('组件代码不匹配');
                }
                $layout->setData(PageLayout::fields_HEADER_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
            } elseif ($region === 'footer') {
                // 验证组件代码
                $currentComponent = $layout->getData(PageLayout::fields_FOOTER_COMPONENT);
                if ($currentComponent !== $componentCode) {
                    throw new \Exception('组件代码不匹配');
                }
                $layout->setData(PageLayout::fields_FOOTER_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
            } else {
                // Content 区域：更新指定索引的组件配置
                $contentComponents = $layout->getContentComponents();
                
                // 如果数据库中没有组件，先从默认布局配置初始化
                if (empty($contentComponents)) {
                    $targetPage = clone $this->pageModel;
                    $targetPage->load($targetPageId);
                    $styleCode = $targetPage->getData(Page::fields_STYLE);
                    $targetPageType = $targetPage->getData(Page::fields_TYPE);
                    
                    if ($styleCode && $targetPageType) {
                        $defaultConfig = $this->componentService->getDefaultLayoutConfigForPageType($styleCode, $targetPageType);
                        if ($defaultConfig && !empty($defaultConfig['layout_config']['content'])) {
                            $contentComponents = $this->convertDefaultComponentsToLayout(
                                $defaultConfig['layout_config']['content'],
                                $styleCode
                            );
                            // 先保存初始化的组件
                            $layout->setContentComponents($contentComponents);
                            $layout->save();
                        }
                    }
                }
                
                if (!isset($contentComponents[$index])) {
                    throw new \Exception('组件不存在');
                }
                
                // 检查组件代码 - 兼容两种存储格式
                $storedCode = $contentComponents[$index]['code'] ?? $contentComponents[$index]['component'] ?? '';
                if ($storedCode !== $componentCode) {
                    throw new \Exception('组件代码不匹配');
                }
                
                $contentComponents[$index]['config'] = $config;
                $layout->setContentComponents($contentComponents);
            }
            
            // 保存 PageLayout
            $layout->save();
            
            // 导出最新配置
            $layoutConfig = $layout->exportConfig();
            
            // 同步到 Page.layout_config 保持向后兼容
            $this->syncLayoutConfigToPage($targetPageId, $layoutConfig);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('配置已更新'),
                'layout_config' => $layoutConfig,
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
     * API: 调整组件顺序
     * POST /backend/visual/api/component/reorder
     * 
     * 注意：使用 LayoutService 保存到 PageLayout 表，确保与渲染系统数据一致
     */
    public function postReorder()
    {
        try {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            
            $pageId = (int)($body['page_id'] ?? 0);
            $region = $body['region'] ?? '';
            $newOrder = $body['order'] ?? []; // 新的组件索引顺序数组，例如 [2, 0, 1]
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            if (!$region) {
                throw new \Exception('缺少区域');
            }
            
            // 只有 content 区域支持重排
            if ($region !== 'content') {
                throw new \Exception('只有内容区域支持重新排序');
            }
            
            // 加载页面
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception('页面不存在');
            }
            
            // 使用 LayoutService 获取 PageLayout
            $layout = $this->layoutService->getOrCreate($pageId);
            
            // 获取当前内容组件
            $currentComponents = $layout->getContentComponents();
            $componentCount = count($currentComponents);
            
            // 如果数据库中没有组件，尝试从默认布局配置初始化
            if ($componentCount === 0 && count($newOrder) > 0) {
                $styleCode = $page->getData(Page::fields_STYLE);
                $pageType = $page->getData(Page::fields_TYPE);
                
                if ($styleCode && $pageType) {
                    $defaultConfig = $this->componentService->getDefaultLayoutConfigForPageType($styleCode, $pageType);
                    if ($defaultConfig && !empty($defaultConfig['layout_config']['content'])) {
                        // 将默认配置转换为 PageLayout 格式并保存
                        $defaultComponents = $this->convertDefaultComponentsToLayout(
                            $defaultConfig['layout_config']['content'],
                            $styleCode
                        );
                        $layout->setContentComponents($defaultComponents);
                        $layout->save();
                        
                        // 重新获取
                        $currentComponents = $layout->getContentComponents();
                        $componentCount = count($currentComponents);
                    }
                }
            }
            
            // 验证索引数组
            if (count($newOrder) !== $componentCount) {
                throw new \Exception('索引数量不匹配 (期望: ' . $componentCount . ', 实际: ' . count($newOrder) . ')');
            }
            
            // 验证所有索引都是有效的
            foreach ($newOrder as $index) {
                if (!is_numeric($index) || $index < 0 || $index >= $componentCount) {
                    throw new \Exception('无效的组件索引: ' . $index);
                }
            }
            
            // 按新索引顺序重排组件
            $newComponents = [];
            foreach ($newOrder as $oldIndex) {
                $oldIndex = (int)$oldIndex;
                if (isset($currentComponents[$oldIndex])) {
                    $newComponents[] = $currentComponents[$oldIndex];
                }
            }
            
            // 更新 PageLayout
            $layout->setContentComponents($newComponents);
            $layout->save();
            
            // 导出最新配置
            $layoutConfig = $layout->exportConfig();
            
            // 同步到 Page.layout_config 保持向后兼容
            $this->syncLayoutConfigToPage($pageId, $layoutConfig);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('顺序已更新'),
                'layout_config' => $layoutConfig,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 将默认布局配置中的组件转换为 PageLayout 格式
     * 
     * @param array $defaultComponents 默认配置中的组件数组
     * @param string $styleCode 样式代码
     * @return array PageLayout 格式的组件数组
     */
    private function convertDefaultComponentsToLayout(array $defaultComponents, string $styleCode): array
    {
        $layoutComponents = [];
        $sortOrder = 10;
        
        foreach ($defaultComponents as $comp) {
            $componentCode = $comp['code'] ?? '';
            if (empty($componentCode)) {
                continue;
            }
            
            $layoutComponents[] = [
                'id' => $comp['instance_id'] ?? uniqid('comp_'),
                'component' => $componentCode,
                'config' => $comp['config'] ?? [],
                'from_template' => $styleCode,
                'sort_order' => $sortOrder,
                'enabled' => $comp['enabled'] ?? true,
            ];
            
            $sortOrder += 10;
        }
        
        return $layoutComponents;
    }
    
    /**
     * API: 获取布局中组件的配置字段
     * GET /backend/visual/api/component/layoutFields
     * 
     * 用于左侧配置面板根据当前布局显示对应的配置项
     * 当组件更换时，配置项也会跟着变化
     */
    public function layoutFields()
    {
        try {
            $pageId = (int)$this->request->getParam('page_id', 0);
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception('页面不存在');
            }
            
            $styleCode = $page->getData('style') ?: 'default';
            
            // 获取布局中所有组件的配置字段
            $componentFields = $this->layoutAssembler->getLayoutComponentFields($page, $styleCode);
            
            // 获取完整布局配置
            $layoutConfig = $this->layoutAssembler->getFullLayoutConfig($page);
            
            return $this->fetchJson([
                'success' => true,
                'layout_config' => $layoutConfig,
                'component_fields' => $componentFields,
                'style_code' => $styleCode,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 获取单个组件的元数据（包括配置字段）
     * GET /backend/visual/api/component/metadata
     */
    public function metadata()
    {
        try {
            $componentCode = $this->request->getParam('component_code', '');
            $styleCode = $this->request->getParam('style_code', '');
            
            if (!$componentCode) {
                throw new \Exception('缺少组件代码');
            }
            
            if (!$styleCode) {
                throw new \Exception('缺少样式代码');
            }
            
            $metadata = $this->layoutAssembler->getComponentMetadata($styleCode, $componentCode);
            
            if (!$metadata) {
                throw new \Exception('组件不存在');
            }
            
            return $this->fetchJson([
                'success' => true,
                'metadata' => $metadata,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
