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

use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualLayoutService;
use GuoLaiRen\PageBuilder\Helper\PageBuilderUrlCacheInvalidator;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use GuoLaiRen\PageBuilder\Service\ComponentService;
use GuoLaiRen\PageBuilder\Service\LayoutAssembler;
use GuoLaiRen\PageBuilder\Service\LayoutService;
use GuoLaiRen\PageBuilder\Service\Component\SlotValidator;
use GuoLaiRen\PageBuilder\Service\Component\ComponentRenderer;
use GuoLaiRen\PageBuilder\Service\Template\TemplatePathResolver;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\PageLayout;

class Component extends BackendController
{
    private ComponentService $componentService;
    private LayoutAssembler $layoutAssembler;
    private LayoutService $layoutService;
    private SlotValidator $slotValidator;
    private ComponentRenderer $componentRenderer;
    private Page $pageModel;
    
    public function __construct()
    {
        $this->componentService = ObjectManager::getInstance(ComponentService::class);
        $this->layoutAssembler = ObjectManager::getInstance(LayoutAssembler::class);
        $this->layoutService = ObjectManager::getInstance(LayoutService::class);
        $this->slotValidator = SlotValidator::getInstance();
        $this->componentRenderer = ComponentRenderer::getInstance();
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
        // Capture noisy scan output without clearing WLS global buffers.
        $bufferLevel = \ob_get_level();
        \ob_start();
        
        try {
            $styleCode = $this->request->getParam('style_code', '');
            $layoutCode = $this->request->getParam('layout_code', '');
            $pageType = $this->request->getParam('page_type', ''); // 页面类型
            $includeCompatible = $this->request->getParam('include_compatible', '1') === '1';
            
            // 先扫描共享组件
            $this->componentService->scanAndRegister('_shared');
            
            // 扫描当前模板组件
            if ($styleCode) {
                $this->componentService->scanAndRegister($styleCode);
            }
            
            // 如果包含兼容组件，扫描所有其他模板
            if ($includeCompatible) {
                $this->componentService->scanAndRegisterAll();
            }
            
            // 是否包含预览HTML（默认不包含，减小响应大小）
            $includePreview = $this->request->getParam('include_preview', '0') === '1';
            $virtualThemeId = $this->resolveRequestVirtualThemeId();
            $themeComponentArea = (string) $this->request->getParam('theme_component_area', 'frontend');
            
            // 获取为构建器格式化的组件数据
            $data = $this->componentService->getComponentsForBuilder(
                $styleCode,
                $layoutCode ?: null,
                $includePreview,
                $pageType ?: null,
                $virtualThemeId,
                $themeComponentArea
            );
            
            return $this->fetchJson([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } finally {
            while (\ob_get_level() > $bufferLevel) {
                \ob_end_clean();
            }
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
            $virtualThemeId = $this->resolveRequestVirtualThemeId();
            $themeComponentArea = (string) $this->request->getParam('theme_component_area', 'frontend');
            
            if (!$componentCode) {
                throw new \Exception('缺少组件代码');
            }
            
            $configArray = json_decode($config, true) ?: [];
            
            // 绑定 Weline 主题虚拟部件时：走 ComponentRenderer（虚拟未命中仍可回落到文件模板）
            if ($virtualThemeId > 0) {
                $styleForRender = $styleCode !== '' ? $styleCode : 'default';
                $virtualPreview = $this->componentRenderer->renderPreview(
                    $componentCode,
                    $styleForRender,
                    $configArray,
                    [
                        'virtual_theme_id' => $virtualThemeId,
                        'theme_component_area' => $themeComponentArea,
                        'style_settings' => [],
                    ]
                );
                if ($virtualPreview->isSuccess()) {
                    $html = $virtualPreview->getHtml();
                    $htmlTrimmed = trim(strip_tags($html));
                    if (empty($htmlTrimmed) || strpos($html, '组件渲染错误') !== false) {
                        if (preg_match('/<!--\s*组件渲染错误:\s*(.+?)\s*-->/', $html, $matches)) {
                            throw new \Exception('组件渲染失败: ' . $matches[1]);
                        }
                        if (empty($htmlTrimmed)) {
                            throw new \Exception('组件渲染结果为空，请检查组件模板文件是否正确');
                        }
                    }
                    $componentRow = $this->componentService->getByCode($componentCode, $styleCode ?: null);
                    $componentPayload = $componentRow
                        ? $this->componentService->toArray($componentRow)
                        : [
                            'code' => $componentCode,
                            'style_code' => $styleForRender,
                            'virtual_theme_id' => $virtualThemeId,
                        ];
                    $componentPayload['virtual_theme_id'] = $virtualThemeId;
                    $componentPayload['render_source'] = $virtualPreview->getData()['render_source'] ?? '';
                    
                    return $this->fetchJson([
                        'success' => true,
                        'html' => $html,
                        'component' => $componentPayload,
                    ]);
                }
            }
            
            // 使用 styleCode 进行精确查找
            $component = $this->componentService->getByCode($componentCode, $styleCode ?: null);
            if (!$component) {
                throw new \Exception('组件不存在: ' . $componentCode . ($styleCode ? " (模板: {$styleCode})" : ''));
            }
            
            // 检查组件文件是否存在
            if (!$component->fileExists()) {
                $path = $component->getData(\GuoLaiRen\PageBuilder\Model\Component::schema_fields_PATH);
                throw new \Exception('组件文件不存在: ' . $path);
            }
            
            // 传递组件所属的模板代码以确保正确渲染
            $actualStyleCode = $component->getData(\GuoLaiRen\PageBuilder\Model\Component::schema_fields_STYLE_CODE);
            $html = $this->componentService->renderPreview($componentCode, $configArray, $actualStyleCode);
            
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
            $styleCode = $this->request->getParam('style_code', '');
            
            if (!$componentCode) {
                throw new \Exception('缺少组件代码');
            }
            
            // 使用 styleCode 进行精确查找
            $component = $this->componentService->getByCode($componentCode, $styleCode ?: null);
            
            if (!$component) {
                throw new \Exception('组件不存在: ' . $componentCode . ($styleCode ? " (模板: {$styleCode})" : ''));
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
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);

            if ($this->isVirtualRequest($body)) {
                return $this->postAddVirtual($body);
            }
            
            $pageId = (int)($body['page_id'] ?? 0);
            $componentCode = $body['component_code'] ?? '';
            $region = $body['region'] ?? '';
            $replace = $body['replace'] ?? false;
            $templateCode = $body['template_code'] ?? '';
            $position = $body['position'] ?? null; // 插入位置（用于 content 排序）
            $parentComponentId = $body['parent_component_id'] ?? null; // 父组件实例ID（嵌套时）
            $targetSlot = $body['slot'] ?? null; // 目标 slot 名称（嵌套时）
            $returnHtml = $body['return_html'] ?? true; // 是否返回渲染的 HTML（用于局部刷新）
            $virtualThemeId = $this->resolvePayloadVirtualThemeId($body);
            $themeComponentArea = (string) ($body['theme_component_area'] ?? 'frontend');
            
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
            
            $styleCode = $page->getData('style') ?: 'default';
            $pageType = $page->getData('type');
            $isHomePage = ($pageType === Page::TYPE_HOME);
            $isGlobalRegion = in_array($region, ['header', 'footer']);
            
            // ========== Slot 验证 ==========
            // 验证组件是否可以放置到目标位置
            if ($parentComponentId && $targetSlot) {
                // 嵌套放置：验证 slot 规则
                $parentComponentCode = $this->getComponentCodeByInstanceId($pageId, $parentComponentId);
                if (!$parentComponentCode) {
                    throw new \Exception('父组件不存在');
                }
                
                $validation = $this->slotValidator->canPlaceInSlot(
                    $componentCode,
                    $parentComponentCode,
                    $targetSlot,
                    $styleCode,
                    $parentComponentId,
                    $welineThemeId,
                    $themeComponentArea
                );
            } else {
                // 顶级放置：验证区域规则
                $validation = $this->slotValidator->canPlaceInRegion(
                    $componentCode,
                    $region,
                    $styleCode,
                    $welineThemeId,
                    $themeComponentArea
                );
            }
            
            if (!$validation->isValid()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $validation->getMessage(),
                    'error_code' => $validation->getErrorCode(),
                    'validation_failed' => true,
                ]);
            }
            
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
            
            // 生成组件实例ID
            $instanceId = 'comp-' . uniqid();
            
            // 创建新组件配置
            $newComponent = [
                'code' => $componentCode,
                'instance_id' => $instanceId,
                'enabled' => true,
                'config' => [],
                'template_code' => $templateCode,
                'children' => [], // 预留嵌套子组件
            ];
            
            // 根据区域类型处理
            $actualPosition = null;
            if ($region === 'header') {
                // Header 区域只能一个，直接替换
                $layout->setData(PageLayout::schema_fields_HEADER_COMPONENT, $componentCode);
                $layout->setData(PageLayout::schema_fields_HEADER_CONFIG, json_encode([]));
                $actualPosition = 0;
            } elseif ($region === 'footer') {
                // Footer 区域只能一个，直接替换
                $layout->setData(PageLayout::schema_fields_FOOTER_COMPONENT, $componentCode);
                $layout->setData(PageLayout::schema_fields_FOOTER_CONFIG, json_encode([]));
                $actualPosition = 0;
            } else {
                // Content 区域可以多个，按位置插入或添加到末尾
                $contentComponents = $layout->getContentComponents();
                
                if ($parentComponentId && $targetSlot) {
                    // 嵌套放置：添加到父组件的 children 中
                    $contentComponents = $this->addToParentSlot(
                        $contentComponents,
                        $parentComponentId,
                        $targetSlot,
                        $newComponent
                    );
                    $actualPosition = $position ?? 0;
                } else {
                    // 顶级放置
                    if ($position !== null && $position >= 0 && $position < count($contentComponents)) {
                        array_splice($contentComponents, $position, 0, [$newComponent]);
                        $actualPosition = $position;
                    } else {
                        $contentComponents[] = $newComponent;
                        $actualPosition = count($contentComponents) - 1;
                    }
                }
                
                $layout->setContentComponents($contentComponents);
            }
            
            // 保存 PageLayout（切换到自定义布局模式）
            $layout->useOriginalTemplate(false)->save();
            
            // 同时同步到 Page.layout_config 保持向后兼容
            $layoutConfig = $layout->exportConfig();
            $this->syncLayoutConfigToPage($targetPageId, $layoutConfig);
            $this->invalidatePageCache($pageId);
            
            // 记录日志
            w_log_debug("[Component API add()] Page ID: {$pageId}, Type: {$pageType}, IsHome: " . ($isHomePage ? 'yes' : 'no'));
            w_log_debug("[Component API add()] Target Page ID: {$targetPageId}, Region: {$region}, Component: {$componentCode}");
            w_log_debug("[Component API add()] Instance ID: {$instanceId}, Position: {$actualPosition}");
            w_log_debug("[Component API add()] Saved to PageLayout successfully");
            
            // ========== 局部刷新：渲染组件 HTML ==========
            $componentHtml = '';
            if ($returnHtml) {
                $pageStyleSettings = $page->getStyleSettings();
                $styleSettings = is_array($pageStyleSettings) ? $pageStyleSettings : [];
                
                $renderOptions = [
                    'region' => $region,
                    'index' => $actualPosition,
                    'visual_mode' => true,
                    'page' => $page,
                    'style_settings' => $styleSettings,
                ];
                if ($virtualThemeId > 0) {
                    $renderOptions['virtual_theme_id'] = $virtualThemeId;
                    $renderOptions['theme_component_area'] = $themeComponentArea;
                }
                
                $renderResult = $this->componentRenderer->renderSingle(
                    $componentCode,
                    $instanceId,
                    $styleCode,
                    [],
                    $renderOptions
                );
                
                if ($renderResult->isSuccess()) {
                    $componentHtml = $renderResult->getHtml();
                } else {
                    w_log_warning("[Component API add()] Render warning: " . $renderResult->getMessage());
                }
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => $isGlobalRegion && !$isHomePage 
                    ? __('全局组件已保存到首页') 
                    : __('组件已添加'),
                'instance_id' => $instanceId,
                'component_html' => $componentHtml,
                'position' => $actualPosition,
                'partial' => true, // 标识为局部更新
                'layout_config' => $layoutConfig,
                'target_page_id' => $targetPageId,
                'is_global' => $isGlobalRegion,
                'saved_to_home' => $isGlobalRegion && !$isHomePage,
            ]);
        } catch (\Exception $e) {
            w_log_error("[Component API add()] Error: " . $e->getMessage());
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 根据实例ID获取组件代码
     */
    private function getComponentCodeByInstanceId(int $pageId, string $instanceId): ?string
    {
        $layout = $this->layoutService->getOrCreate($pageId);
        $contentComponents = $layout->getContentComponents();
        
        foreach ($contentComponents as $comp) {
            if (($comp['instance_id'] ?? $comp['id'] ?? '') === $instanceId) {
                return $comp['code'] ?? null;
            }
            // 递归查找嵌套组件
            if (!empty($comp['children'])) {
                $found = $this->findComponentCodeInChildren($comp['children'], $instanceId);
                if ($found) {
                    return $found;
                }
            }
        }
        
        return null;
    }
    
    /**
     * 在 children 中递归查找组件代码
     */
    private function findComponentCodeInChildren(array $children, string $instanceId): ?string
    {
        foreach ($children as $slotName => $slotComponents) {
            if (!is_array($slotComponents)) {
                continue;
            }
            foreach ($slotComponents as $comp) {
                if (($comp['instance_id'] ?? $comp['id'] ?? '') === $instanceId) {
                    return $comp['code'] ?? null;
                }
                if (!empty($comp['children'])) {
                    $found = $this->findComponentCodeInChildren($comp['children'], $instanceId);
                    if ($found) {
                        return $found;
                    }
                }
            }
        }
        return null;
    }
    
    /**
     * 将组件添加到父组件的 slot 中
     */
    private function addToParentSlot(array $components, string $parentInstanceId, string $slotName, array $newComponent): array
    {
        foreach ($components as &$comp) {
            if (($comp['instance_id'] ?? $comp['id'] ?? '') === $parentInstanceId) {
                // 找到父组件，添加到其 children 中
                if (!isset($comp['children'])) {
                    $comp['children'] = [];
                }
                if (!isset($comp['children'][$slotName])) {
                    $comp['children'][$slotName] = [];
                }
                $comp['children'][$slotName][] = $newComponent;
                return $components;
            }
            
            // 递归查找
            if (!empty($comp['children'])) {
                $comp['children'] = $this->addToParentSlotInChildren($comp['children'], $parentInstanceId, $slotName, $newComponent);
            }
        }
        
        return $components;
    }
    
    /**
     * 在嵌套 children 中添加组件到 slot
     */
    private function addToParentSlotInChildren(array $children, string $parentInstanceId, string $slotName, array $newComponent): array
    {
        foreach ($children as $slot => &$slotComponents) {
            if (!is_array($slotComponents)) {
                continue;
            }
            foreach ($slotComponents as &$comp) {
                if (($comp['instance_id'] ?? $comp['id'] ?? '') === $parentInstanceId) {
                    if (!isset($comp['children'])) {
                        $comp['children'] = [];
                    }
                    if (!isset($comp['children'][$slotName])) {
                        $comp['children'][$slotName] = [];
                    }
                    $comp['children'][$slotName][] = $newComponent;
                    return $children;
                }
                if (!empty($comp['children'])) {
                    $comp['children'] = $this->addToParentSlotInChildren($comp['children'], $parentInstanceId, $slotName, $newComponent);
                }
            }
        }
        return $children;
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
                $page->setData(Page::schema_fields_LAYOUT_CONFIG, json_encode($pageLayoutConfig, JSON_UNESCAPED_UNICODE));
                $page->save();
            }
        } catch (\Throwable $e) {
            w_log_error("[Component API syncLayoutConfigToPage()] Error: " . $e->getMessage());
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
     * API: 验证组件是否可以放置到目标位置
     * POST /backend/visual/api/component/validate
     * 
     * 用于拖拽前预验证，提供即时反馈
     * 
     * 请求参数：
     * - component_code: 组件代码
     * - region: 目标区域
     * - style_code: 模板代码
     * - parent_component_code: 父组件代码（嵌套时）
     * - slot: 目标 slot（嵌套时）
     * - parent_instance_id: 父组件实例ID（用于数量检查）
     * 
     * 返回：
     * - valid: 是否可以放置
     * - message: 错误消息
     * - error_code: 错误代码
     * - compatible_components: 可放置的组件列表（如果验证失败）
     */
    public function postValidate()
    {
        try {
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);
            
            $componentCode = $body['component_code'] ?? '';
            $region = $body['region'] ?? '';
            $styleCode = $body['style_code'] ?? '';
            $parentComponentCode = $body['parent_component_code'] ?? null;
            $targetSlot = $body['slot'] ?? null;
            $parentInstanceId = $body['parent_instance_id'] ?? null;
            $virtualThemeId = $this->resolvePayloadVirtualThemeId($body);
            $themeComponentArea = (string) ($body['theme_component_area'] ?? 'frontend');
            
            if (!$componentCode) {
                return $this->fetchJson([
                    'success' => false,
                    'valid' => false,
                    'message' => '缺少组件代码',
                    'error_code' => 'MISSING_COMPONENT_CODE',
                ]);
            }
            
            if (!$region && !$parentComponentCode) {
                return $this->fetchJson([
                    'success' => false,
                    'valid' => false,
                    'message' => '缺少目标区域或父组件',
                    'error_code' => 'MISSING_TARGET',
                ]);
            }
            
            // 执行验证
            if ($parentComponentCode && $targetSlot) {
                // 验证 slot 放置
                $validation = $this->slotValidator->canPlaceInSlot(
                    $componentCode,
                    $parentComponentCode,
                    $targetSlot,
                    $styleCode,
                    $parentInstanceId,
                    $welineThemeId,
                    $themeComponentArea
                );
                
                // 如果验证失败，返回可放置的组件列表
                $compatibleComponents = [];
                if (!$validation->isValid()) {
                    $compatibleComponents = $this->slotValidator->getCompatibleComponentsForSlot(
                        $parentComponentCode,
                        $targetSlot,
                        $styleCode
                    );
                }
            } else {
                // 验证区域放置
                $validation = $this->slotValidator->canPlaceInRegion(
                    $componentCode,
                    $region,
                    $styleCode,
                    $welineThemeId,
                    $themeComponentArea
                );
                
                // 如果验证失败，返回可放置的组件列表
                $compatibleComponents = [];
                if (!$validation->isValid()) {
                    $compatibleComponents = $this->slotValidator->getCompatibleComponentsForRegion(
                        $region,
                        $styleCode
                    );
                }
            }
            
            return $this->fetchJson([
                'success' => true,
                'valid' => $validation->isValid(),
                'message' => $validation->getMessage(),
                'error_code' => $validation->getErrorCode(),
                'compatible_components' => $compatibleComponents ?? [],
            ]);
            
        } catch (\Exception $e) {
            w_log_error("[Component API validate()] Error: " . $e->getMessage());
            return $this->fetchJson([
                'success' => false,
                'valid' => false,
                'message' => $e->getMessage(),
                'error_code' => 'VALIDATION_ERROR',
            ]);
        }
    }
    
    /**
     * API: 获取区域/slot 的可放置组件列表
     * GET /backend/visual/api/component/compatible
     * 
     * 用于智能筛选部件库
     * 
     * 请求参数：
     * - region: 目标区域
     * - style_code: 模板代码
     * - parent_component_code: 父组件代码（查询 slot 时）
     * - slot: slot 名称（查询 slot 时）
     */
    public function compatible()
    {
        try {
            $region = $this->request->getParam('region', '');
            $styleCode = $this->request->getParam('style_code', '');
            $parentComponentCode = $this->request->getParam('parent_component_code', '');
            $targetSlot = $this->request->getParam('slot', '');
            $virtualThemeId = $this->resolveRequestVirtualThemeId();
            $themeComponentArea = (string) $this->request->getParam('theme_component_area', 'frontend');
            
            if ($parentComponentCode && $targetSlot) {
                // 获取 slot 兼容组件
                $components = $this->slotValidator->getCompatibleComponentsForSlot(
                    $parentComponentCode,
                    $targetSlot,
                    $styleCode,
                    $virtualThemeId,
                    $themeComponentArea
                );
                
                // 获取 slot 信息
                $slotInfo = $this->slotValidator->getComponentSlots($parentComponentCode, $styleCode, $virtualThemeId, $themeComponentArea);
                $slotConfig = $slotInfo[$targetSlot] ?? [];
                
                return $this->fetchJson([
                    'success' => true,
                    'type' => 'slot',
                    'slot_name' => $targetSlot,
                    'slot_type' => $slotConfig['slot_type'] ?? null,
                    'accepts' => $slotConfig['accepts'] ?? [],
                    'components' => $components,
                ]);
            } else {
                // 获取区域兼容组件
                $components = $this->slotValidator->getCompatibleComponentsForRegion(
                    $region,
                    $styleCode,
                    $virtualThemeId,
                    $themeComponentArea
                );
                
                return $this->fetchJson([
                    'success' => true,
                    'type' => 'region',
                    'region' => $region,
                    'accepts' => $this->slotValidator->getRegionAccepts($region),
                    'components' => $components,
                ]);
            }
            
        } catch (\Exception $e) {
            w_log_error("[Component API compatible()] Error: " . $e->getMessage());
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 获取组件的 slots 信息
     * GET /backend/visual/api/component/slots
     * 
     * 用于查询组件是否是容器以及其 slot 定义
     */
    public function slots()
    {
        try {
            $componentCode = $this->request->getParam('component_code', '');
            $styleCode = $this->request->getParam('style_code', '');
            $virtualThemeId = $this->resolveRequestVirtualThemeId();
            $themeComponentArea = (string) $this->request->getParam('theme_component_area', 'frontend');
            
            if (!$componentCode) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => '缺少组件代码',
                ]);
            }
            
            $slots = $this->slotValidator->getComponentSlots($componentCode, $styleCode, $virtualThemeId, $themeComponentArea);
            $isContainer = !empty($slots);
            $componentInfo = $this->slotValidator->resolvePlacementComponentInfo($componentCode, $styleCode, $virtualThemeId, $themeComponentArea);
            
            return $this->fetchJson([
                'success' => true,
                'component_code' => $componentCode,
                'is_container' => $isContainer,
                'slots' => $slots,
                'region' => $componentInfo['region'] ?? 'content',
                'category' => $componentInfo['category'] ?? 'content',
                'placeable_in' => $componentInfo['placeable_in'] ?? [],
            ]);
            
        } catch (\Exception $e) {
            w_log_error("[Component API slots()] Error: " . $e->getMessage());
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
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
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);

            if ($this->isVirtualRequest($body)) {
                return $this->postRemoveVirtual($body);
            }
            
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
            w_log_debug("[Component API remove()] Page ID: {$pageId}, Type: {$pageType}, IsHome: " . ($isHomePage ? 'yes' : 'no'));
            w_log_debug("[Component API remove()] Target Page ID: {$targetPageId}, Region: {$region}, Component: {$componentCode}");
            
            // 根据区域类型处理删除
            $removedCount = 0;
            if ($region === 'header') {
                // 清空 header 组件
                $currentComponent = $layout->getData(PageLayout::schema_fields_HEADER_COMPONENT);
                if (!empty($currentComponent)) {
                    $layout->setData(PageLayout::schema_fields_HEADER_COMPONENT, '');
                    $layout->setData(PageLayout::schema_fields_HEADER_CONFIG, '{}');
                    $removedCount = 1;
                }
            } elseif ($region === 'footer') {
                // 清空 footer 组件
                $currentComponent = $layout->getData(PageLayout::schema_fields_FOOTER_COMPONENT);
                if (!empty($currentComponent)) {
                    $layout->setData(PageLayout::schema_fields_FOOTER_COMPONENT, '');
                    $layout->setData(PageLayout::schema_fields_FOOTER_CONFIG, '{}');
                    $removedCount = 1;
                }
            } else {
                // Content 区域：按索引或代码移除
                $contentComponents = $layout->getContentComponents();
                
                // 如果数据库中没有组件但要删除，先从默认布局配置初始化
                if (empty($contentComponents) && $index !== null) {
                    $targetPage = clone $this->pageModel;
                    $targetPage->load($targetPageId);
                    $styleCode = $targetPage->getData(Page::schema_fields_STYLE);
                    $targetPageType = $targetPage->getData(Page::schema_fields_TYPE);
                    
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
            $this->invalidatePageCache($pageId);
            
            w_log_info("[Component API remove()] Removed {$removedCount} component(s) from PageLayout");
            
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
            w_log_error("[Component API remove()] Error: " . $e->getMessage());
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
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);

            if ($this->isVirtualRequest($body)) {
                return $this->postUpdateConfigVirtual($body);
            }
            
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
            
            // 目标页面的 styleCode（用于 header/footer 组件代码规范化比较）
            $targetPage = clone $this->pageModel;
            $targetPage->load($targetPageId);
            $styleCode = $targetPage->getData(Page::schema_fields_STYLE) ?: '';
            
            // 根据区域类型更新配置
            if ($region === 'header') {
                $currentComponent = $layout->getData(PageLayout::schema_fields_HEADER_COMPONENT);
                $currentComponent = $currentComponent === null ? '' : trim((string)$currentComponent);
                if ($currentComponent !== '') {
                    $storedCanonical = $this->normalizeHeaderFooterComponentCode($currentComponent, 'header', $styleCode);
                    $requestCanonical = $this->normalizeHeaderFooterComponentCode($componentCode, 'header', $styleCode);
                    if ($storedCanonical !== $requestCanonical) {
                        throw new \Exception('组件代码不匹配');
                    }
                }
                $layout->setData(PageLayout::schema_fields_HEADER_COMPONENT, $componentCode);
                $layout->setData(PageLayout::schema_fields_HEADER_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
            } elseif ($region === 'footer') {
                $currentComponent = $layout->getData(PageLayout::schema_fields_FOOTER_COMPONENT);
                $currentComponent = $currentComponent === null ? '' : trim((string)$currentComponent);
                if ($currentComponent !== '') {
                    $storedCanonical = $this->normalizeHeaderFooterComponentCode($currentComponent, 'footer', $styleCode);
                    $requestCanonical = $this->normalizeHeaderFooterComponentCode($componentCode, 'footer', $styleCode);
                    if ($storedCanonical !== $requestCanonical) {
                        throw new \Exception('组件代码不匹配');
                    }
                }
                $layout->setData(PageLayout::schema_fields_FOOTER_COMPONENT, $componentCode);
                $layout->setData(PageLayout::schema_fields_FOOTER_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
            } else {
                // Content 区域：更新指定索引的组件配置
                $contentComponents = $layout->getContentComponents();
                
                // 如果数据库中没有组件，先从默认布局配置初始化
                if (empty($contentComponents)) {
                    $targetPage = clone $this->pageModel;
                    $targetPage->load($targetPageId);
                    $styleCode = $targetPage->getData(Page::schema_fields_STYLE);
                    $targetPageType = $targetPage->getData(Page::schema_fields_TYPE);
                    
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
            $this->invalidatePageCache($pageId);
            
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
     * 将 header/footer 组件代码规范化为与 LayoutAssembler 一致的 canonical 形式，用于比较
     * 例如：sattaking-header -> header-nav，sattaking-footer -> footer-links
     */
    private function normalizeHeaderFooterComponentCode(string $code, string $region, string $styleCode): string
    {
        $code = trim($code);
        if ($region === 'header') {
            if ($code === $styleCode . '-header' || $code === 'header') {
                return 'header-nav';
            }
            if (preg_match('/^' . preg_quote($styleCode, '/') . '_header_header$/i', $code)) {
                return 'header-nav';
            }
        }
        if ($region === 'footer') {
            if ($code === $styleCode . '-footer' || $code === 'footer') {
                return 'footer-links';
            }
            if (preg_match('/^' . preg_quote($styleCode, '/') . '_footer_(footer|links)$/i', $code)) {
                return 'footer-links';
            }
        }
        return $code;
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
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);

            if ($this->isVirtualRequest($body)) {
                return $this->postReorderVirtual($body);
            }
            
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
                $styleCode = $page->getData(Page::schema_fields_STYLE);
                $pageType = $page->getData(Page::schema_fields_TYPE);
                
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
            $this->invalidatePageCache($pageId);
            
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

    private function invalidatePageCache(int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }

        try {
            PageBuilderUrlCacheInvalidator::invalidateForPageId($pageId);
        } catch (\Throwable) {
        }
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
            $params = [
                'public_id' => (string)$this->request->getParam('public_id', ''),
                'page_type' => (string)$this->request->getParam('page_type', ''),
                'style_code' => (string)$this->request->getParam('style_code', ''),
            ];
            if ($this->isVirtualRequest($params)) {
                return $this->layoutFieldsVirtual($params);
            }

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
            $styleCode = trim((string)$this->request->getParam('style_code', ''));
            
            if ($componentCode === '' || $componentCode === null) {
                throw new \Exception('缺少组件代码');
            }
            
            $metadata = null;
            if ($styleCode !== '') {
                $metadata = $this->layoutAssembler->getComponentMetadata($styleCode, $componentCode);
            }
            // style_code 为空时依次尝试常见模板，避免前端未传 style_code 时直接报“缺少样式代码”
            if (!$metadata && $styleCode === '') {
                $styleCandidates = ['tpmst', 'default', 'saas-starter', 'fitness-pro', 'fintech-hub'];
                foreach ($styleCandidates as $candidate) {
                    $metadata = $this->layoutAssembler->getComponentMetadata($candidate, $componentCode);
                    if ($metadata) {
                        break;
                    }
                }
            }
            
            if (!$metadata) {
                throw new \Exception('组件不存在: ' . $componentCode . ($styleCode !== '' ? " (模板: {$styleCode})" : ''));
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
    
    /**
     * API: 获取组件源代码
     * GET /backend/visual/api/component/code
     * 
     * 用于AI生成组件时作为参考模板
     * 
     * 请求参数：
     * - component_code: 组件代码（必填）
     * - style_code: 样式代码（可选）
     */
    public function code()
    {
        try {
            $componentCode = $this->request->getParam('component_code', '');
            $styleCode = $this->request->getParam('style_code', 'tpmst');
            
            if (empty($componentCode)) {
                throw new \Exception('请提供组件代码');
            }
            
            // 获取组件信息
            $component = $this->componentService->getByCode($componentCode, $styleCode);
            
            if (!$component) {
                throw new \Exception('组件不存在: ' . $componentCode);
            }
            
            // 获取组件模板代码
            $templateCode = '';
            
            // 如果是AI组件，从数据库获取
            if ($component->isAIGenerated()) {
                $templateCode = $component->getTemplateContent();
            } else {
                // 如果是模板组件，读取文件内容
                $templatePath = $component->getData('template_path');
                if ($templatePath && file_exists($templatePath)) {
                    $templateCode = file_get_contents($templatePath);
                } else {
                    // 尝试构建模板路径
                    $category = $component->getData('category') ?: 'content';
                    $componentStyleCode = $component->getData('style_code') ?: $styleCode;
                    
                    // 可能的路径模式
                    $possiblePaths = [
                        BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $componentStyleCode . '/components/' . $category . '/' . $componentCode . '.phtml',
                        BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $componentStyleCode . '/' . $category . '/' . $componentCode . '.phtml',
                        BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $componentStyleCode . '/components/' . $componentCode . '.phtml',
                    ];
                    
                    foreach ($possiblePaths as $path) {
                        if (file_exists($path)) {
                            $templateCode = file_get_contents($path);
                            break;
                        }
                    }
                    // legal-content：主题无本地文件时回退 _shared（与 TemplatePathResolver 一致）
                    if ($templateCode === '' && ($componentCode === 'legal-content' || str_ends_with((string) $componentCode, 'legal-content'))) {
                        $resolver = ObjectManager::getInstance(TemplatePathResolver::class);
                        foreach (['content/legal-content.phtml', 'legal-content.phtml'] as $rel) {
                            $resolved = $resolver->resolveComponentFilesystemPath($componentStyleCode, $rel);
                            if (is_file($resolved)) {
                                $templateCode = (string) file_get_contents($resolved);
                                break;
                            }
                        }
                    }
                }
            }
            
            if (empty($templateCode)) {
                throw new \Exception('无法获取组件代码');
            }
            
            return $this->fetchJson([
                'success' => true,
                'code' => $templateCode,
                'component' => [
                    'code' => $component->getData('code'),
                    'name' => $component->getData('name'),
                    'category' => $component->getData('category'),
                    'region' => $component->getData('region') ?: $component->getData('category'),
                    'is_ai_generated' => $component->isAIGenerated(),
                ],
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function isVirtualRequest(array $payload): bool
    {
        return \trim((string)($payload['public_id'] ?? '')) !== ''
            && \trim((string)($payload['page_type'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   session:mixed,
     *   scope:array<string,mixed>,
     *   page:Page,
     *   public_id:string,
     *   page_type:string,
     *   virtual_theme_id:int,
     *   virtual_page:array<string,mixed>,
     *   virtual_pages_by_type:array<string,array<string,mixed>>,
     *   layout:array<string,mixed>,
     *   style_code:string
     * }|null
     */
    private function resolveVirtualContext(array $payload): ?array
    {
        $publicId = \trim((string)($payload['public_id'] ?? ''));
        $requestedPageType = \trim((string)($payload['page_type'] ?? ''));
        $adminId = (int)$this->getLoginUserId();
        if ($publicId === '' || $requestedPageType === '' || $adminId <= 0) {
            return null;
        }

        $context = $this->getVirtualLayoutService()->loadContext($publicId, $adminId, $requestedPageType);
        if ($context === null) {
            return null;
        }

        $scopeService = $this->getScopeCompatibilityService();
        $scope = $scopeService->normalizeScope($context['scope']);
        $virtualPages = $scopeService->buildVirtualPagesByType(
            $scopeService->normalizePageTypes($scope['page_types'] ?? []),
            $scope
        );
        $pageType = $scopeService->resolvePreviewPageType($virtualPages, $requestedPageType);
        if ($pageType === '' || !isset($virtualPages[$pageType])) {
            return null;
        }

        $virtualThemeId = (int)$context['virtual_theme_id'];
        $virtualPage = $virtualPages[$pageType];
        $styleCode = \trim((string)($payload['style_code'] ?? ($virtualPage['style_code'] ?? 'default')));
        $styleCode = $styleCode !== '' ? $styleCode : 'default';
        $layout = $this->getVirtualLayoutService()->getResolvedLayout($virtualThemeId, $pageType);

        return [
            'session' => $context['session'],
            'scope' => $scope,
            'page' => $this->buildVirtualPage($publicId, $scope, $pageType, $virtualThemeId, $virtualPages, $virtualPage, $layout, $styleCode),
            'public_id' => $publicId,
            'page_type' => $pageType,
            'virtual_theme_id' => $virtualThemeId,
            'virtual_page' => $virtualPage,
            'virtual_pages_by_type' => $virtualPages,
            'layout' => $layout,
            'style_code' => $styleCode,
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,array<string,mixed>> $virtualPagesByType
     * @param array<string,mixed> $virtualPage
     * @param array<string,mixed> $layout
     */
    private function buildVirtualPage(
        string $publicId,
        array $scope,
        string $pageType,
        int $virtualThemeId,
        array $virtualPagesByType,
        array $virtualPage,
        array $layout,
        string $styleCode
    ): Page {
        /** @var Page $page */
        $page = ObjectManager::make(Page::class);
        $locale = \trim((string)($virtualPage['locale'] ?? ''));
        $locale = $locale !== '' ? $locale : 'en_US';
        $virtualBlocks = \is_array($virtualPage['blocks'] ?? null) ? $virtualPage['blocks'] : [];
        $renderMode = $virtualBlocks === [] ? Page::RENDER_MODE_THEME : Page::RENDER_MODE_AI_HTML;
        $page->setData([
            Page::schema_fields_ID => 0,
            Page::schema_fields_WEBSITE_ID => (int)($scope['draft_website_id'] ?? 0),
            Page::schema_fields_PARENT_ID => $pageType === Page::TYPE_HOME ? 0 : 1,
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
            Page::schema_fields_TITLE => (string)($virtualPage['title'] ?? ''),
            Page::schema_fields_NAME => (string)($virtualPage['title'] ?? ''),
            Page::schema_fields_HANDLE => (string)($virtualPage['handle'] ?? ''),
            Page::schema_fields_STYLE => $styleCode,
            Page::schema_fields_TYPE => $pageType,
            Page::schema_fields_META_TITLE => (string)($virtualPage['meta_title'] ?? ''),
            Page::schema_fields_META_DESCRIPTION => (string)($virtualPage['meta_description'] ?? ''),
            Page::schema_fields_META_KEYWORDS => (string)($virtualPage['meta_keywords'] ?? ''),
            Page::schema_fields_AI_DESCRIPTION => (string)($virtualPage['ai_description'] ?? ''),
            Page::schema_fields_LOCALES => \json_encode([$locale], JSON_UNESCAPED_UNICODE),
            Page::schema_fields_DEFAULT_LOCALE => $locale,
            Page::schema_fields_STYLE_SETTING => \json_encode([
                $styleCode => \is_array($virtualPage['style_settings'] ?? null) ? $virtualPage['style_settings'] : [],
            ], JSON_UNESCAPED_UNICODE),
            Page::schema_fields_LAYOUT_CONFIG => \json_encode($layout, JSON_UNESCAPED_UNICODE),
            Page::schema_fields_RENDER_MODE => $renderMode,
            Page::schema_fields_AI_LAYOUT => \json_encode(['blocks' => $virtualBlocks], JSON_UNESCAPED_UNICODE),
        ]);
        $page->setData('virtual_public_id', $publicId);
        $page->setData('virtual_page_type', $pageType);
        $page->setData('virtual_theme_id', $virtualThemeId);
        $page->setData('virtual_pages_by_type', $virtualPagesByType);
        return $page;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postAddVirtual(array $body)
    {
        $context = $this->resolveVirtualContext($body);
        if ($context === null) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('会话不存在或无访问权限'),
            ]);
        }

        try {
            $componentCode = \trim((string)($body['component_code'] ?? ''));
            $region = \trim((string)($body['region'] ?? ''));
            $position = $body['position'] ?? null;
            $parentComponentId = $body['parent_component_id'] ?? null;
            $targetSlot = $body['slot'] ?? null;
            $returnHtml = $body['return_html'] ?? true;
            $virtualThemeId = $this->resolvePayloadVirtualThemeId($body);
            $themeComponentArea = (string)($body['theme_component_area'] ?? 'frontend');

            if ($componentCode === '' || $region === '') {
                throw new \Exception('缺少组件编码或区域');
            }

            $layout = $context['layout'];
            if ($parentComponentId && $targetSlot) {
                $parentComponentCode = $this->getVirtualComponentCodeByInstanceId(
                    \is_array($layout['content'] ?? null) ? $layout['content'] : [],
                    (string)$parentComponentId
                );
                if (!$parentComponentCode) {
                    throw new \Exception('父组件不存在');
                }
                $validation = $this->slotValidator->canPlaceInSlot(
                    $componentCode,
                    $parentComponentCode,
                    (string)$targetSlot,
                    $context['style_code'],
                    (string)$parentComponentId,
                    $virtualThemeId,
                    $themeComponentArea
                );
            } else {
                $validation = $this->slotValidator->canPlaceInRegion(
                    $componentCode,
                    $region,
                    $context['style_code'],
                    $virtualThemeId,
                    $themeComponentArea
                );
            }

            if (!$validation->isValid()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $validation->getMessage(),
                    'error_code' => $validation->getErrorCode(),
                    'validation_failed' => true,
                ]);
            }

            $instanceId = 'comp-' . \uniqid();
            $newComponent = [
                'code' => $componentCode,
                'instance_id' => $instanceId,
                'enabled' => true,
                'config' => [],
                'children' => [],
            ];
            $actualPosition = 0;

            if ($region === 'header' || $region === 'footer') {
                $layout[$region] = [
                    'component' => $componentCode,
                    'config' => [],
                    'instance_id' => $instanceId,
                ];
            } else {
                $contentComponents = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
                if ($parentComponentId && $targetSlot) {
                    $contentComponents = $this->addToParentSlot(
                        $contentComponents,
                        (string)$parentComponentId,
                        (string)$targetSlot,
                        $newComponent
                    );
                } elseif ($position !== null && (int)$position >= 0 && (int)$position < \count($contentComponents)) {
                    \array_splice($contentComponents, (int)$position, 0, [$newComponent]);
                    $actualPosition = (int)$position;
                } else {
                    $contentComponents[] = $newComponent;
                    $actualPosition = \count($contentComponents) - 1;
                }
                $layout['content'] = \array_values($contentComponents);
            }

            $resolvedLayout = $this->getVirtualLayoutService()->saveResolvedLayout(
                (int)$context['virtual_theme_id'],
                (string)$context['page_type'],
                $layout,
                $region
            );

            $componentHtml = '';
            if ($returnHtml) {
                $styleSettings = \is_array($context['virtual_page']['style_settings'] ?? null)
                    ? $context['virtual_page']['style_settings']
                    : [];
                $renderOptions = [
                    'region' => $region,
                    'index' => $actualPosition,
                    'visual_mode' => true,
                    'page' => $context['page'],
                    'style_settings' => $styleSettings,
                ];
                if ($virtualThemeId > 0) {
                    $renderOptions['virtual_theme_id'] = $virtualThemeId;
                    $renderOptions['theme_component_area'] = $themeComponentArea;
                }
                $renderResult = $this->componentRenderer->renderSingle(
                    $componentCode,
                    $instanceId,
                    $context['style_code'],
                    [],
                    $renderOptions
                );
                if ($renderResult->isSuccess()) {
                    $componentHtml = $renderResult->getHtml();
                }
            }

            return $this->fetchJson([
                'success' => true,
                'message' => __('组件已添加'),
                'instance_id' => $instanceId,
                'component_html' => $componentHtml,
                'position' => $actualPosition,
                'partial' => true,
                'layout_config' => $this->buildEditorLayoutConfig($resolvedLayout),
                'target_page_id' => 0,
                'is_global' => \in_array($region, ['header', 'footer'], true),
                'public_id' => $context['public_id'],
                'page_type' => $context['page_type'],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postRemoveVirtual(array $body)
    {
        $context = $this->resolveVirtualContext($body);
        if ($context === null) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('会话不存在或无访问权限'),
            ]);
        }

        try {
            $componentCode = \trim((string)($body['component_code'] ?? ''));
            $region = \trim((string)($body['region'] ?? ''));
            $index = $body['index'] ?? null;
            if ($region === '') {
                throw new \Exception('缺少区域');
            }

            $layout = $context['layout'];
            $removedCount = 0;
            if ($region === 'header' || $region === 'footer') {
                if (!empty($layout[$region]['component'])) {
                    $layout[$region] = ['component' => '', 'config' => []];
                    $removedCount = 1;
                }
            } else {
                $contentComponents = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
                if ($index !== null && isset($contentComponents[(int)$index])) {
                    \array_splice($contentComponents, (int)$index, 1);
                    $removedCount = 1;
                } elseif ($componentCode !== '') {
                    $originalCount = \count($contentComponents);
                    $contentComponents = \array_values(\array_filter(
                        $contentComponents,
                        static fn(array $comp): bool => (string)($comp['code'] ?? $comp['component'] ?? '') !== $componentCode
                    ));
                    $removedCount = $originalCount - \count($contentComponents);
                }
                $layout['content'] = $contentComponents;
            }

            $resolvedLayout = $this->getVirtualLayoutService()->saveResolvedLayout(
                (int)$context['virtual_theme_id'],
                (string)$context['page_type'],
                $layout,
                $region
            );

            return $this->fetchJson([
                'success' => true,
                'message' => __('组件已删除'),
                'layout_config' => $this->buildEditorLayoutConfig($resolvedLayout),
                'target_page_id' => 0,
                'is_global' => \in_array($region, ['header', 'footer'], true),
                'removed_count' => $removedCount,
                'public_id' => $context['public_id'],
                'page_type' => $context['page_type'],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postUpdateConfigVirtual(array $body)
    {
        $context = $this->resolveVirtualContext($body);
        if ($context === null) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('会话不存在或无访问权限'),
            ]);
        }

        try {
            $componentCode = \trim((string)($body['component_code'] ?? ''));
            $region = \trim((string)($body['region'] ?? ''));
            $index = (int)($body['index'] ?? 0);
            $config = \is_array($body['config'] ?? null) ? $body['config'] : [];
            if ($componentCode === '' || $region === '') {
                throw new \Exception('缺少组件编码或区域');
            }

            $layout = $context['layout'];
            if ($region === 'header' || $region === 'footer') {
                $currentComponent = (string)($layout[$region]['component'] ?? '');
                if (
                    $currentComponent !== ''
                    && $this->normalizeHeaderFooterComponentCode($currentComponent, $region, $context['style_code'])
                        !== $this->normalizeHeaderFooterComponentCode($componentCode, $region, $context['style_code'])
                ) {
                    throw new \Exception('当前区域组件与请求组件不匹配');
                }
                $layout[$region] = [
                    'component' => $componentCode,
                    'config' => $config,
                    'instance_id' => (string)($layout[$region]['instance_id'] ?? ('virtual-' . $region)),
                ];
            } else {
                $contentComponents = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
                if (!isset($contentComponents[$index])) {
                    throw new \Exception('组件不存在');
                }
                $storedCode = (string)($contentComponents[$index]['code'] ?? $contentComponents[$index]['component'] ?? '');
                if ($storedCode !== $componentCode) {
                    throw new \Exception('当前组件与请求组件不匹配');
                }
                $contentComponents[$index]['config'] = $config;
                $layout['content'] = $contentComponents;
            }

            $resolvedLayout = $this->getVirtualLayoutService()->saveResolvedLayout(
                (int)$context['virtual_theme_id'],
                (string)$context['page_type'],
                $layout,
                $region
            );

            return $this->fetchJson([
                'success' => true,
                'message' => __('组件配置已保存'),
                'layout_config' => $this->buildEditorLayoutConfig($resolvedLayout),
                'target_page_id' => 0,
                'public_id' => $context['public_id'],
                'page_type' => $context['page_type'],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postReorderVirtual(array $body)
    {
        $context = $this->resolveVirtualContext($body);
        if ($context === null) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('会话不存在或无访问权限'),
            ]);
        }

        try {
            $region = \trim((string)($body['region'] ?? ''));
            $newOrder = \is_array($body['order'] ?? null) ? $body['order'] : [];
            if ($region !== 'content') {
                throw new \Exception('只支持对内容区域排序');
            }

            $layout = $context['layout'];
            $currentComponents = \array_values(\is_array($layout['content'] ?? null) ? $layout['content'] : []);
            $componentCount = \count($currentComponents);
            if (\count($newOrder) !== $componentCount) {
                throw new \Exception('排序数量与当前组件数量不一致');
            }

            $ordered = [];
            foreach ($newOrder as $oldIndex) {
                $oldIndex = (int)$oldIndex;
                if (!isset($currentComponents[$oldIndex])) {
                    throw new \Exception('无效的原始索引: ' . $oldIndex);
                }
                $ordered[] = $currentComponents[$oldIndex];
            }
            $layout['content'] = $ordered;

            $resolvedLayout = $this->getVirtualLayoutService()->saveResolvedLayout(
                (int)$context['virtual_theme_id'],
                (string)$context['page_type'],
                $layout,
                'content'
            );

            return $this->fetchJson([
                'success' => true,
                'message' => __('排序已保存'),
                'layout_config' => $this->buildEditorLayoutConfig($resolvedLayout),
                'public_id' => $context['public_id'],
                'page_type' => $context['page_type'],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function layoutFieldsVirtual(array $params)
    {
        $context = $this->resolveVirtualContext($params);
        if ($context === null) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('会话不存在或无访问权限'),
            ]);
        }

        return $this->fetchJson([
            'success' => true,
            'layout_config' => $this->buildEditorLayoutConfig($context['layout']),
            'component_fields' => [],
            'style_code' => $context['style_code'],
            'public_id' => $context['public_id'],
            'page_type' => $context['page_type'],
        ]);
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildEditorLayoutConfig(array $layout): array
    {
        $normalized = [
            'header' => [],
            'content' => [],
            'footer' => [],
        ];

        if (!empty($layout['header']['component'])) {
            $normalized['header'][] = [
                'code' => (string)$layout['header']['component'],
                'enabled' => true,
                'config' => \is_array($layout['header']['config'] ?? null) ? $layout['header']['config'] : [],
                'instance_id' => (string)($layout['header']['instance_id'] ?? 'virtual-header'),
            ];
        }

        foreach ((array)($layout['content'] ?? []) as $component) {
            if (!\is_array($component)) {
                continue;
            }
            $normalized['content'][] = [
                'code' => (string)($component['code'] ?? $component['component'] ?? ''),
                'enabled' => (bool)($component['enabled'] ?? true),
                'config' => \is_array($component['config'] ?? null) ? $component['config'] : [],
                'instance_id' => (string)($component['instance_id'] ?? $component['id'] ?? ('comp-' . \uniqid())),
                'children' => \is_array($component['children'] ?? null) ? $component['children'] : [],
            ];
        }

        if (!empty($layout['footer']['component'])) {
            $normalized['footer'][] = [
                'code' => (string)$layout['footer']['component'],
                'enabled' => true,
                'config' => \is_array($layout['footer']['config'] ?? null) ? $layout['footer']['config'] : [],
                'instance_id' => (string)($layout['footer']['instance_id'] ?? 'virtual-footer'),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $components
     */
    private function getVirtualComponentCodeByInstanceId(array $components, string $instanceId): ?string
    {
        foreach ($components as $comp) {
            if ((string)($comp['instance_id'] ?? $comp['id'] ?? '') === $instanceId) {
                return (string)($comp['code'] ?? $comp['component'] ?? '');
            }
            if (!empty($comp['children']) && \is_array($comp['children'])) {
                $found = $this->findComponentCodeInChildren($comp['children'], $instanceId);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function resolveRequestVirtualThemeId(): int
    {
        return \max(0, (int)$this->request->getParam('virtual_theme_id', 0));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolvePayloadVirtualThemeId(array $payload): int
    {
        return \max(0, (int)($payload['virtual_theme_id'] ?? 0));
    }

    private function getVirtualLayoutService(): AiSiteVirtualLayoutService
    {
        return ObjectManager::getInstance(AiSiteVirtualLayoutService::class);
    }

    private function getScopeCompatibilityService(): AiSiteScopeCompatibilityService
    {
        return ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
    }
}
