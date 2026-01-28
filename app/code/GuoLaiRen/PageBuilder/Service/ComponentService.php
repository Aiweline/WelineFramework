<?php

declare(strict_types=1);

/*
 * 组件服务类 - 负责组件相关的业务逻辑
 * 
 * 支持以下组件来源：
 * 1. 模板专属组件（style/{template}/components/）
 * 2. 共享组件（style/_shared/components/）
 * 3. 其他模板的兼容组件
 * 
 * 遵循单一职责原则(SRP)
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Component;
use GuoLaiRen\PageBuilder\Model\Layout;
use Weline\Framework\Manager\ObjectManager;

class ComponentService
{
    private Component $componentModel;
    private ComponentValidator $componentValidator;
    
    // 共享组件模板代码
    public const SHARED_STYLE_CODE = '_shared';
    
    public function __construct()
    {
        $this->componentModel = ObjectManager::getInstance(Component::class);
        $this->componentValidator = ObjectManager::getInstance(ComponentValidator::class);
    }
    
    /**
     * 扫描并注册模板组件（包含验证）
     * 
     * @param string $styleCode 模板代码
     * @param bool $validateFirst 是否先验证后扫描
     * @param bool $throwOnError 验证失败时是否抛出异常
     * @return array 扫描结果，包含验证信息
     */
    public function scanAndRegister(string $styleCode, bool $validateFirst = true, bool $throwOnError = false): array
    {
        $result = [
            'validation' => null,
            'scan' => null,
        ];
        
        if (empty($styleCode)) {
            return $result;
        }
        
        // 先进行验证
        if ($validateFirst) {
            $validation = $this->componentValidator->validateTemplate($styleCode, $throwOnError);
            $result['validation'] = $validation;
            
            // 如果有错误且不是强制模式，返回警告但仍继续扫描
            if (!$validation['valid'] && !$throwOnError) {
                error_log("[ComponentService] 模板 {$styleCode} 组件验证有错误: " . implode('; ', $validation['errors']));
            }
        }
        
        // 执行扫描
        $result['scan'] = Component::scanAndRegister($styleCode);
        
        return $result;
    }
    
    /**
     * 验证模板组件配置
     * 
     * @param string $styleCode 模板代码
     * @param bool $throwOnError 是否在出错时抛出异常
     * @return array 验证结果
     */
    public function validateTemplate(string $styleCode, bool $throwOnError = false): array
    {
        return $this->componentValidator->validateTemplate($styleCode, $throwOnError);
    }
    
    /**
     * 验证布局配置中的组件引用
     * 
     * @param array $layoutConfig 布局配置
     * @param string $styleCode 模板代码
     * @param bool $throwOnError 是否在出错时抛出异常
     * @return array 验证结果
     */
    public function validateLayoutConfig(array $layoutConfig, string $styleCode, bool $throwOnError = false): array
    {
        return $this->componentValidator->validateLayoutConfig($layoutConfig, $styleCode, $throwOnError);
    }
    
    /**
     * 生成验证报告
     * 
     * @param string $styleCode 模板代码
     * @return string 格式化的验证报告
     */
    public function generateValidationReport(string $styleCode): string
    {
        return $this->componentValidator->generateReport($styleCode);
    }
    
    /**
     * 扫描并注册所有模板的组件（包括共享组件）
     */
    public function scanAndRegisterAll(): array
    {
        $results = [];
        $styleDir = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/';
        
        if (!is_dir($styleDir)) {
            return $results;
        }
        
        $dirs = scandir($styleDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === '_layouts') {
                continue;
            }
            
            $fullPath = $styleDir . $dir;
            if (is_dir($fullPath)) {
                $results[$dir] = Component::scanAndRegister($dir);
            }
        }
        
        return $results;
    }
    
    /**
     * 获取模板的组件列表（包含共享组件）
     * 
     * @param string $styleCode 模板代码
     * @param bool $includeCompatible 是否包含兼容组件
     * @return array ['own' => Component[], 'shared' => Component[], 'compatible' => [templateCode => Component[]]]
     */
    public function getComponentsByStyle(string $styleCode, bool $includeCompatible = true): array
    {
        $result = Component::getByStyleCode($styleCode, $includeCompatible, true);
        
        // 添加共享组件
        if ($styleCode !== self::SHARED_STYLE_CODE) {
            $sharedComponents = $this->getSharedComponents();
            $result['shared'] = $sharedComponents;
        }
        
        return $result;
    }
    
    /**
     * 获取共享组件列表
     * 
     * @return array Component[]
     */
    public function getSharedComponents(): array
    {
        $components = clone $this->componentModel;
        return $components->clear()
            ->where(Component::fields_STYLE_CODE, self::SHARED_STYLE_CODE)
            ->where(Component::fields_IS_ACTIVE, 1)
            ->order(Component::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 获取用于可视化构建器的组件数据
     * 
     * @param string $styleCode 模板代码
     * @param string|null $layoutCode 布局代码（可选，用于过滤适合的组件）
     * @param bool $includePreview 是否包含预览HTML（默认true）
     * @param string|null $pageType 页面类型（可选，用于加载默认布局配置）
     * @return array 组织好的组件数据
     */
    public function getComponentsForBuilder(string $styleCode, ?string $layoutCode = null, bool $includePreview = true, ?string $pageType = null): array
    {
        // #region agent log
        $debugLog = function($msg, $data, $hyp) { @file_put_contents(BP . '.cursor/debug.log', json_encode(['location' => 'ComponentService.php:getComponentsForBuilder', 'message' => $msg, 'data' => $data, 'hypothesisId' => $hyp, 'timestamp' => microtime(true)]) . "\n", FILE_APPEND); };
        $debugLog('Entry', ['styleCode' => $styleCode, 'includePreview' => $includePreview], 'F');
        // #endregion
        
        // 启用输出缓冲，防止模板渲染时的直接输出破坏JSON响应
        $obLevel = ob_get_level();
        ob_start();
        
        try {
            $allComponents = $this->getComponentsByStyle($styleCode, true);
            
            // #region agent log
            $debugLog('Before toArrayBatch recommended', ['ownCount' => count($allComponents['own'] ?? [])], 'F');
            // #endregion
            
            $result = [
                // 当前模板的组件（推荐）- 包含预览
                'recommended' => [
                    'label' => '推荐组件',
                    'description' => '当前模板专属组件，样式最契合',
                    'components' => $this->toArrayBatch($allComponents['own'] ?? [], $includePreview),
                ],
                // 共享组件（通用）- 包含预览
                'shared' => [
                    'label' => '通用组件',
                    'description' => '跨模板通用组件',
                    'components' => $this->toArrayBatch($allComponents['shared'] ?? [], $includePreview),
                ],
                // 其他模板的兼容组件
                'other_templates' => [],
            ];
            
            // #region agent log
            $debugLog('After recommended and shared', ['recommendedCount' => count($result['recommended']['components']), 'sharedCount' => count($result['shared']['components'])], 'F');
            // #endregion
            
            // 整理其他模板的组件（不生成预览，避免跨模板渲染问题）
            if (!empty($allComponents['compatible'])) {
                foreach ($allComponents['compatible'] as $templateCode => $components) {
                    if ($templateCode === self::SHARED_STYLE_CODE) {
                        continue; // 跳过共享组件（已单独处理）
                    }
                    
                    // #region agent log
                    $debugLog('Processing compatible template', ['templateCode' => $templateCode, 'componentsCount' => count($components)], 'F');
                    // #endregion
                    
                    // 兼容组件不生成预览（避免跨模板渲染导致的输出问题）
                    $result['other_templates'][$templateCode] = [
                        'label' => $this->getTemplateName($templateCode),
                        'components' => $this->toArrayBatch($components, false),
                    ];
                }
            }
            
            // 如果指定了布局，按区域分组（不为兼容组件生成预览）
            if ($layoutCode) {
                $result['by_region'] = $this->groupComponentsByRegion($allComponents, $layoutCode, $includePreview);
            }
            
            // 按分类分组
            $result['by_category'] = $this->groupComponentsByCategory($allComponents, $includePreview);
            
            // 如果指定了页面类型，加载该页面类型的默认布局配置
            if ($pageType) {
                $result['default_layout_config'] = $this->getDefaultLayoutConfigForPageType($styleCode, $pageType);
                $result['page_type'] = $pageType;
            }
            
            // 清理可能的直接输出
            $unexpectedOutput = '';
            while (ob_get_level() > $obLevel) {
                $unexpectedOutput .= ob_get_clean();
            }
            if (!empty($unexpectedOutput)) {
                $debugLog('Unexpected output in getComponentsForBuilder', ['output' => substr($unexpectedOutput, 0, 500)], 'WARNING');
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            // 发生异常时清理输出缓冲
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            $debugLog('Exception in getComponentsForBuilder', ['error' => $e->getMessage()], 'ERROR');
            throw $e;
        }
    }
    
    /**
     * 获取页面类型的默认布局配置
     * 
     * 简化逻辑：直接使用页面类型代码作为文件名
     * 例如：blog_post → layouts/default/blog_post.json
     * 
     * @param string $styleCode 样式代码
     * @param string $pageType 页面类型
     * @return array|null 默认布局配置
     */
    public function getDefaultLayoutConfigForPageType(string $styleCode, string $pageType): ?array
    {
        if (empty($pageType)) {
            return null;
        }
        
        // 直接使用页面类型代码作为配置文件名
        $configFilePath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/layouts/default/{$pageType}.json";
        
        if (!file_exists($configFilePath)) {
            // fallback 到 custom_page
            $configFilePath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/layouts/default/custom_page.json";
            if (!file_exists($configFilePath)) {
                return null;
            }
        }
        
        $configData = json_decode(file_get_contents($configFilePath), true);
        
        if (empty($configData['layout_config'])) {
            return null;
        }
        
        $pageConfig = $configData['layout_config'];
        
        // 处理继承（header/footer 从首页继承）
        $inheritRegions = $configData['inherit_regions'] ?? [];
        
        foreach (['header', 'footer'] as $region) {
            // 如果该区域为空数组且需要继承
            if (empty($pageConfig[$region]) && isset($inheritRegions[$region])) {
                $inheritFrom = $inheritRegions[$region];
                $inheritedConfig = $this->getDefaultLayoutConfigForPageType($styleCode, $inheritFrom);
                if ($inheritedConfig) {
                    $pageConfig[$region] = $inheritedConfig['layout_config'][$region] ?? [];
                }
            }
        }
        
        return [
            'page_type' => $pageType,
            'layout_config' => $pageConfig,
        ];
    }
    
    /**
     * 按区域分组组件
     */
    private function groupComponentsByRegion(array $allComponents, string $layoutCode, bool $includePreview = false): array
    {
        $regions = Layout::getLayoutRegions($layoutCode);
        $grouped = [];
        
        foreach ($regions as $regionCode => $region) {
            $grouped[$regionCode] = [
                'label' => $region['name'] ?? ucfirst($regionCode),
                'components' => [],
            ];
        }
        
        // 合并所有组件
        $all = array_merge(
            $allComponents['own'] ?? [],
            $allComponents['shared'] ?? []
        );
        
        foreach ($all as $component) {
            $category = $component->getData(Component::fields_CATEGORY);
            $regionCode = $this->categoryToRegion($category);
            
            if (isset($grouped[$regionCode])) {
                $grouped[$regionCode]['components'][] = $this->toArray($component, $includePreview);
            }
        }
        
        return $grouped;
    }
    
    /**
     * 按分类分组组件
     */
    private function groupComponentsByCategory(array $allComponents, bool $includePreview = false): array
    {
        $categories = Component::getCategories();
        $grouped = [];
        
        foreach ($categories as $code => $label) {
            $grouped[$code] = [
                'label' => $label,
                'components' => [],
            ];
        }
        
        // 合并所有组件
        $all = array_merge(
            $allComponents['own'] ?? [],
            $allComponents['shared'] ?? []
        );
        
        foreach ($all as $component) {
            $category = $component->getData(Component::fields_CATEGORY);
            if (isset($grouped[$category])) {
                $grouped[$category]['components'][] = $this->toArray($component, $includePreview);
            }
        }
        
        return $grouped;
    }
    
    /**
     * 分类映射到区域
     */
    private function categoryToRegion(string $category): string
    {
        return match($category) {
            Component::CATEGORY_HEADER => Layout::REGION_HEADER,
            Component::CATEGORY_FOOTER => Layout::REGION_FOOTER,
            default => Layout::REGION_CONTENT,
        };
    }
    
    /**
     * 根据组件代码获取组件
     */
    public function getByCode(string $componentCode): ?Component
    {
        // #region agent log
        $debugLog = function($msg, $data, $hyp) { @file_put_contents(BP . '.cursor/debug.log', json_encode(['location' => 'ComponentService.php:getByCode', 'message' => $msg, 'data' => $data, 'hypothesisId' => $hyp, 'timestamp' => microtime(true)]) . "\n", FILE_APPEND); };
        $debugLog('Entry', ['componentCode' => $componentCode], 'H');
        // #endregion
        
        $component = clone $this->componentModel;
        $component->clear()
            ->where(Component::fields_CODE, $componentCode)
            ->find()
            ->fetch();
        
        // #region agent log
        $id = $component->getId();
        $debugLog('After fetch', ['componentCode' => $componentCode, 'id' => $id, 'idType' => gettype($id)], 'H');
        // #endregion
        
        return $component->getId() ? $component : null;
    }
    
    /**
     * 渲染组件预览（执行完整组件）
     * 
     * 支持跨模板组件渲染：
     * 1. 加载组件所属模板的颜色配置
     * 2. 正确处理组件的静态资源路径
     * 
     * @param string $componentCode 组件代码
     * @param array $config 自定义配置（空则使用默认配置）
     * @return string 渲染后的 HTML
     */
    public function renderPreview(string $componentCode, array $config = []): string
    {
        // #region agent log
        $debugLog = function($msg, $data, $hyp) { @file_put_contents(BP . '.cursor/debug.log', json_encode(['location' => 'ComponentService.php:renderPreview', 'message' => $msg, 'data' => $data, 'hypothesisId' => $hyp, 'timestamp' => microtime(true)]) . "\n", FILE_APPEND); };
        $debugLog('Entry', ['componentCode' => $componentCode], 'H');
        // #endregion
        
        $component = $this->getByCode($componentCode);
        
        // #region agent log
        $debugLog('After getByCode', ['componentCode' => $componentCode, 'found' => $component !== null], 'H');
        // #endregion
        
        if (!$component) {
            throw new \Exception('组件不存在: ' . $componentCode);
        }
        
        $styleCode = $component->getData(Component::fields_STYLE_CODE);
        $path = $component->getData(Component::fields_PATH);
        
        // #region agent log
        $debugLog('Got styleCode and path', ['componentCode' => $componentCode, 'styleCode' => $styleCode, 'path' => $path], 'I');
        // #endregion
        
        if (empty($path)) {
            throw new \Exception('组件路径未定义: ' . $componentCode);
        }
        
        // 合并默认配置和自定义配置
        $defaultConfig = $component->getDefaultConfig();
        $mergedConfig = array_merge($defaultConfig, $config);
        
        // #region agent log
        $debugLog('Got config', ['componentCode' => $componentCode], 'I');
        // #endregion
        
        // 使用框架的模板引擎渲染组件
        $template = \Weline\Framework\View\Template::getInstance();
        
        // 加载组件所属模板的颜色配置
        $colors = $this->loadTemplateColors($styleCode);
        
        // #region agent log
        $debugLog('Loaded colors', ['componentCode' => $componentCode, 'colorsCount' => count($colors)], 'I');
        // #endregion
        
        // 准备模板变量
        $template->assign('page', null);
        $template->assign('style', $mergedConfig);
        $template->assign('style_settings', $mergedConfig);
        $template->assign('component_config', $mergedConfig);
        $template->assign('is_preview', true);
        $template->assign('colors', $colors); // 颜色配置
        $template->assign('template_code', $styleCode); // 模板代码
        
        try {
            // 检查组件文件是否存在
            $fullPath = $component->getFullPath();
            
            // #region agent log
            $debugLog('Checking file', ['componentCode' => $componentCode, 'fullPath' => $fullPath, 'exists' => file_exists($fullPath)], 'I');
            // #endregion
            
            if (!file_exists($fullPath)) {
                throw new \Exception("组件文件不存在: {$path}");
            }
            
            // 使用模块路径格式渲染组件
            // path 格式类似: style/tpmst/components/header/nav.phtml
            $templatePath = "GuoLaiRen_PageBuilder::templates/{$path}";
            
            // #region agent log
            $debugLog('Before fetch', ['componentCode' => $componentCode, 'templatePath' => $templatePath], 'I');
            // #endregion
            
            // 启用输出缓冲捕获模板可能的直接输出
            ob_start();
            $html = $template->fetch($templatePath);
            $directOutput = ob_get_clean();
            
            // 如果有直接输出，合并到结果中
            if (!empty($directOutput)) {
                $html = $directOutput . ($html ?? '');
            }
            
            // #region agent log
            $debugLog('After fetch', ['componentCode' => $componentCode, 'htmlLen' => strlen($html ?? ''), 'directOutputLen' => strlen($directOutput)], 'I');
            // #endregion
            
            // 确保 $html 是字符串
            if (!is_string($html)) {
                $html = '';
            }
            
            // 如果渲染结果为空，返回提示信息
            if (empty(trim($html))) {
                return '<div style="padding: 20px; text-align: center; color: #999; font-size: 14px;">组件预览为空</div>';
            }
            
            return $html;
            
        } catch (\Throwable $e) {
            // 返回更友好的错误提示
            $errorMsg = htmlspecialchars($e->getMessage());
            return '<div style="padding: 20px; text-align: center; color: #e74c3c; font-size: 14px; border: 1px dashed #e74c3c; border-radius: 4px; background: #fff5f5;">
                <p style="margin: 0 0 10px 0;"><strong>组件预览失败</strong></p>
                <p style="margin: 0; font-size: 12px; color: #999;">' . $errorMsg . '</p>
            </div>';
        }
    }
    
    /**
     * 加载模板的颜色配置
     * 
     * @param string $styleCode 模板代码
     * @return array 颜色配置数组
     */
    private function loadTemplateColors(string $styleCode): array
    {
        $colors = [];
        
        // 颜色配置文件路径
        $colorFile = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/colors/default.phtml";
        
        if (!file_exists($colorFile)) {
            // 尝试共享颜色配置
            $colorFile = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/_shared/colors/default.phtml";
        }
        
        if (file_exists($colorFile)) {
            try {
                // 从颜色配置文件中提取颜色变量
                $content = file_get_contents($colorFile);
                
                // 解析 $colors 数组定义
                if (preg_match('/\$colors\s*=\s*\[([\s\S]*?)\];/m', $content, $matches)) {
                    // 尝试通过执行来获取颜色数组
                    ob_start();
                    $tempColors = [];
                    // 安全地执行，只提取颜色变量
                    $extractCode = '<?php ' . str_replace('<?php', '', $matches[0]) . ' return $colors;';
                    $tempFile = sys_get_temp_dir() . '/pb_colors_' . md5($styleCode) . '.php';
                    file_put_contents($tempFile, $extractCode);
                    $colors = include $tempFile;
                    @unlink($tempFile);
                    ob_end_clean();
                }
            } catch (\Throwable $e) {
                // 颜色配置加载失败，使用空数组
                $colors = [];
            }
        }
        
        return is_array($colors) ? $colors : [];
    }
    
    /**
     * 获取组件的预览 HTML（通过模板渲染获取）
     * 
     * @param string $componentCode 组件代码
     * @return string 预览 HTML
     */
    public function extractPreviewHtml(string $componentCode): string
    {
        // #region agent log
        $debugLog = function($msg, $data, $hyp) { @file_put_contents(BP . '.cursor/debug.log', json_encode(['location' => 'ComponentService.php:extractPreviewHtml', 'message' => $msg, 'data' => $data, 'hypothesisId' => $hyp, 'timestamp' => microtime(true)]) . "\n", FILE_APPEND); };
        $debugLog('Entry', ['componentCode' => $componentCode], 'G');
        // #endregion
        
        // 启用输出缓冲，捕获所有可能的直接输出
        ob_start();
        
        try {
            // 直接通过模板渲染获取组件 HTML
            $html = $this->renderPreview($componentCode, []);
            
            // 获取可能的直接输出
            $directOutput = ob_get_clean();
            
            // #region agent log
            $debugLog('After renderPreview', ['componentCode' => $componentCode, 'htmlLen' => strlen($html ?? ''), 'directOutputLen' => strlen($directOutput)], 'G');
            // #endregion
            
            // 如果有直接输出，记录但忽略（不混入结果）
            if (!empty($directOutput)) {
                $debugLog('Warning: Direct output detected', ['componentCode' => $componentCode, 'output' => substr($directOutput, 0, 200)], 'WARNING');
            }
            
            if (empty($html)) {
                return '';
            }
            
            // 为预览 HTML 添加唯一容器类名（样式隔离）
            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $componentCode);
            return '<div class="cp-' . $safeCode . ' component-preview-wrapper">' . $html . '</div>';
            
        } catch (\Throwable $e) {
            // 清理输出缓冲
            ob_end_clean();
            
            // #region agent log
            $debugLog('Exception', ['componentCode' => $componentCode, 'error' => $e->getMessage()], 'G');
            // #endregion
            // 渲染失败时返回错误提示
            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $componentCode);
            return '<div class="cp-' . $safeCode . ' component-preview-error" style="padding:10px;color:#999;font-size:12px;text-align:center;">预览加载失败</div>';
        }
    }
    
    /**
     * 将组件模型转换为数组格式
     * 
     * @param Component $component 组件模型
     * @param bool $includePreview 是否包含预览HTML
     */
    public function toArray(Component $component, bool $includePreview = false): array
    {
        // #region agent log
        $debugLog = function($msg, $data, $hyp) { @file_put_contents(BP . '.cursor/debug.log', json_encode(['location' => 'ComponentService.php:toArray', 'message' => $msg, 'data' => $data, 'hypothesisId' => $hyp, 'timestamp' => microtime(true)]) . "\n", FILE_APPEND); };
        // #endregion
        
        $styleCode = $component->getData(Component::fields_STYLE_CODE);
        $thumbnail = $component->getData(Component::fields_THUMBNAIL);
        $category = $component->getData(Component::fields_CATEGORY);
        $componentCode = $component->getData(Component::fields_CODE);
        
        // #region agent log
        $componentId = $component->getId();
        $debugLog('Entry', ['componentCode' => $componentCode, 'componentId' => $componentId, 'idType' => gettype($componentId), 'styleCode' => $styleCode], 'F');
        // #endregion
        
        // 构建缩略图完整路径
        $thumbnailUrl = '';
        if ($thumbnail) {
            // 检查是否已经是完整URL或绝对路径
            if (str_starts_with($thumbnail, 'http://') || str_starts_with($thumbnail, 'https://') || str_starts_with($thumbnail, '/')) {
                $thumbnailUrl = $thumbnail;
            } else {
                // thumbnail 路径是相对于 style 目录的（如 asset/img/logo.png）
                // 使用框架方法获取正确的静态资源URL
                try {
                    $template = \Weline\Framework\View\Template::getInstance();
                    $thumbnailUrl = $template->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/' . $styleCode . '/' . $thumbnail);
                } catch (\Throwable $e) {
                    // 回退到开发模式路径
                    $thumbnailUrl = '/app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $styleCode . '/' . $thumbnail;
                }
            }
        }
        
        // 从 config_schema 中提取 region（如果有的话）
        $configSchema = $component->getConfigSchema();
        $region = $configSchema['region'] ?? $this->categoryToRegion($category);
        
        $result = [
            'id' => $component->getId(),
            'code' => $componentCode,
            'name' => $component->getData(Component::fields_NAME),
            'description' => $component->getData(Component::fields_DESCRIPTION),
            'style_code' => $styleCode,
            'category' => $category,
            'region' => $region,
            'type' => $component->getData(Component::fields_TYPE),
            'thumbnail' => $thumbnail,
            'thumbnail_url' => $thumbnailUrl,
            'config_schema' => $configSchema,
            'default_config' => $component->getDefaultConfig(),
            'compatible_styles' => $component->getCompatibleStyles(),
            'is_system' => (bool)$component->getData(Component::fields_IS_SYSTEM),
            'is_shared' => $styleCode === self::SHARED_STYLE_CODE,
            'sort_order' => (int)$component->getData(Component::fields_SORT_ORDER),
            'preview_html' => '',
            'preview_html_encoded' => false, // 标记预览HTML是否已编码
        ];
        
        // 如果需要预览HTML，从组件文件中提取
        if ($includePreview) {
            $previewHtml = $this->extractPreviewHtml($componentCode);
            // 使用 Base64 编码预览HTML，防止特殊字符破坏JSON结构
            $result['preview_html'] = base64_encode($previewHtml);
            $result['preview_html_encoded'] = true;
        }
        
        return $result;
    }
    
    /**
     * 批量转换组件为数组
     * 
     * @param array $components 组件数组
     * @param bool $includePreview 是否包含预览HTML
     */
    public function toArrayBatch(array $components, bool $includePreview = false): array
    {
        return array_map(fn($c) => $this->toArray($c, $includePreview), $components);
    }
    
    /**
     * 获取模板名称
     */
    private function getTemplateName(string $styleCode): string
    {
        // 尝试从 readme.md 或 component.json 获取名称
        $basePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $styleCode . '/';
        
        // 尝试从 component.json 获取
        $componentJson = $basePath . 'components/component.json';
        if (file_exists($componentJson)) {
            $content = file_get_contents($componentJson);
            $config = json_decode($content, true);
            if (!empty($config['name'])) {
                return $config['name'];
            }
        }
        
        // 格式化代码为名称
        return ucwords(str_replace(['-', '_'], ' ', $styleCode));
    }
    
    /**
     * 获取页面已保存的组件配置
     * 
     * @param int $pageId 页面ID
     * @return array 组件配置
     */
    public function getPageComponents(int $pageId): array
    {
        // TODO: 从 PageLayout 模型获取页面的组件配置
        return [];
    }
    
    /**
     * 保存页面的组件配置
     * 
     * @param int $pageId 页面ID
     * @param array $components 组件配置
     * @return bool
     */
    public function savePageComponents(int $pageId, array $components): bool
    {
        // TODO: 保存到 PageLayout 模型
        return true;
    }
}
