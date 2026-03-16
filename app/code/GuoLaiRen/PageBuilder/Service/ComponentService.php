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
                w_log_error("[ComponentService] 模板 {$styleCode} 组件验证有错误: " . implode('; ', $validation['errors']));
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
            ->where(Component::schema_fields_STYLE_CODE, self::SHARED_STYLE_CODE)
            ->where(Component::schema_fields_IS_ACTIVE, 1)
            ->order(Component::schema_fields_SORT_ORDER, 'ASC')
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
        // 启用输出缓冲，防止模板渲染时的直接输出破坏JSON响应
        $obLevel = ob_get_level();
        ob_start();
        
        try {
            $allComponents = $this->getComponentsByStyle($styleCode, true);
            
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
            
            // 整理其他模板的组件（不生成预览，避免跨模板渲染问题）
            if (!empty($allComponents['compatible'])) {
                foreach ($allComponents['compatible'] as $templateCode => $components) {
                    if ($templateCode === self::SHARED_STYLE_CODE) {
                        continue; // 跳过共享组件（已单独处理）
                    }
                    
                    // 兼容组件不生成预览（避免跨模板渲染导致的输出问题）
                    $result['other_templates'][$templateCode] = [
                        'label' => $this->getTemplateName($templateCode),
                        'components' => $this->toArrayBatch($components, false),
                    ];
                }
            }
            
            // 如果指定了布局，按区域分组（不为兼容组件生成预览）
            if ($layoutCode) {
                $result['by_region'] = $this->groupComponentsByRegion($allComponents, $layoutCode, $includePreview, $styleCode);
            }
            
            // 按分类分组
            $result['by_category'] = $this->groupComponentsByCategory($allComponents, $includePreview, $styleCode);
            
            // 如果指定了页面类型，加载该页面类型的默认布局配置
            if ($pageType) {
                $result['default_layout_config'] = $this->getDefaultLayoutConfigForPageType($styleCode, $pageType);
                $result['page_type'] = $pageType;
            }
            
            // 清理可能的直接输出
            while (ob_get_level() > $obLevel) {
                ob_get_clean();
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            // 发生异常时清理输出缓冲
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
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
    private function groupComponentsByRegion(array $allComponents, string $layoutCode, bool $includePreview = false, string $currentStyleCode = ''): array
    {
        $regions = Layout::getLayoutRegions($layoutCode);
        $grouped = [];
        
        foreach ($regions as $regionCode => $region) {
            $grouped[$regionCode] = [
                'label' => $region['name'] ?? ucfirst($regionCode),
                'components' => [],
            ];
        }
        
        // 合并所有组件（当前模板 + 共享 + 其他模板）
        $all = [];
        $currentStyleCode = $currentStyleCode ?: (string)($allComponents['style_code'] ?? '');
        $currentStyleName = $currentStyleCode ? $this->getTemplateName($currentStyleCode) : $currentStyleCode;
        
        foreach ($allComponents['own'] ?? [] as $component) {
            $item = $this->toArray($component, $includePreview);
            $item['isOwn'] = true;
            $item['templateCode'] = $currentStyleCode;
            $item['templateName'] = $currentStyleName;
            $all[] = $item;
        }
        foreach ($allComponents['shared'] ?? [] as $component) {
            $item = $this->toArray($component, $includePreview);
            $item['isShared'] = true;
            $item['templateCode'] = self::SHARED_STYLE_CODE;
            $item['templateName'] = '通用组件';
            $all[] = $item;
        }
        foreach ($allComponents['compatible'] ?? [] as $templateCode => $components) {
            foreach ($components as $component) {
                $item = $this->toArray($component, $includePreview);
                $item['templateCode'] = $templateCode;
                $item['templateName'] = $this->getTemplateName($templateCode);
                $all[] = $item;
            }
        }
        
        foreach ($all as $item) {
            $category = $item['category'] ?? '';
            $regionCode = $this->categoryToRegion($category);
            
            if (isset($grouped[$regionCode])) {
                $grouped[$regionCode]['components'][] = $item;
            }
        }
        
        return $grouped;
    }
    
    /**
     * 按分类分组组件
     */
    private function groupComponentsByCategory(array $allComponents, bool $includePreview = false, string $currentStyleCode = ''): array
    {
        $categories = Component::getCategories();
        $grouped = [];
        
        foreach ($categories as $code => $label) {
            $grouped[$code] = [
                'label' => $label,
                'components' => [],
            ];
        }
        
        // 合并所有组件（当前模板 + 共享 + 其他模板）
        $all = [];
        $currentStyleCode = $currentStyleCode ?: (string)($allComponents['style_code'] ?? '');
        $currentStyleName = $currentStyleCode ? $this->getTemplateName($currentStyleCode) : $currentStyleCode;
        
        foreach ($allComponents['own'] ?? [] as $component) {
            $item = $this->toArray($component, $includePreview);
            $item['isOwn'] = true;
            $item['templateCode'] = $currentStyleCode;
            $item['templateName'] = $currentStyleName;
            $all[] = $item;
        }
        foreach ($allComponents['shared'] ?? [] as $component) {
            $item = $this->toArray($component, $includePreview);
            $item['isShared'] = true;
            $item['templateCode'] = self::SHARED_STYLE_CODE;
            $item['templateName'] = '通用组件';
            $all[] = $item;
        }
        foreach ($allComponents['compatible'] ?? [] as $templateCode => $components) {
            foreach ($components as $component) {
                $item = $this->toArray($component, $includePreview);
                $item['templateCode'] = $templateCode;
                $item['templateName'] = $this->getTemplateName($templateCode);
                $all[] = $item;
            }
        }
        
        foreach ($all as $item) {
            $category = $item['category'] ?? '';
            if (isset($grouped[$category])) {
                $grouped[$category]['components'][] = $item;
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
     * 
     * 查找顺序：
     * 1. 如果指定了 styleCode，先精确匹配 code + style_code
     * 2. 如果没有指定 styleCode 或没找到，尝试只用 code 查找
     * 3. 如果组件代码是旧格式（带模板前缀），尝试解析并查找
     * 
     * @param string $componentCode 组件代码
     * @param string|null $styleCode 模板代码（可选，推荐传入）
     * @return Component|null
     */
    public function getByCode(string $componentCode, ?string $styleCode = null): ?Component
    {
        // 标准化组件代码（移除可能的模板前缀，转换下划线为破折号）
        $normalizedCode = $this->normalizeComponentCode($componentCode, $styleCode);
        
        // 1. 如果指定了 styleCode，先精确匹配
        if ($styleCode) {
            $component = clone $this->componentModel;
            $component->clear()
                ->where(Component::schema_fields_CODE, $normalizedCode)
                ->where(Component::schema_fields_STYLE_CODE, $styleCode)
                ->find()
                ->fetch();
            
            if ($component->getId()) {
                return $component;
            }
        }
        
        // 2. 尝试只用标准化后的 code 查找
        $component = clone $this->componentModel;
        $component->clear()
            ->where(Component::schema_fields_CODE, $normalizedCode)
            ->where(Component::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        
        if ($component->getId()) {
            return $component;
        }
        
        // 3. 如果还没找到，尝试用原始代码查找（兼容旧格式）
        if ($normalizedCode !== $componentCode) {
            $component = clone $this->componentModel;
            $component->clear()
                ->where(Component::schema_fields_CODE, $componentCode)
                ->where(Component::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($component->getId()) {
                return $component;
            }
        }
        
        // 4. 尝试模糊匹配（处理可能的格式差异）
        $component = $this->fuzzyFindComponent($componentCode, $styleCode);
        
        return $component;
    }
    
    /**
     * 标准化组件代码
     * 
     * 处理各种格式：
     * - sattaking_header_nav -> header-nav
     * - tpmst_content_hero -> content-hero
     * - header-nav -> header-nav（已是标准格式）
     */
    private function normalizeComponentCode(string $code, ?string $styleCode = null): string
    {
        // 如果已经是标准格式（包含破折号，不包含下划线），直接返回
        if (strpos($code, '-') !== false && strpos($code, '_') === false) {
            return strtolower($code);
        }
        
        // 如果有模板前缀，尝试移除
        if ($styleCode && strpos($code, $styleCode . '_') === 0) {
            $withoutPrefix = substr($code, strlen($styleCode) + 1);
            return strtolower(str_replace('_', '-', $withoutPrefix));
        }
        
        // 尝试检测并移除模板前缀（格式：{styleCode}_{category}_{name}）
        if (preg_match('/^([a-z0-9]+)_([a-z]+)_(.+)$/i', $code, $matches)) {
            $category = strtolower($matches[2]);
            $name = str_replace('_', '-', strtolower($matches[3]));
            return "{$category}-{$name}";
        }
        
        // 只转换下划线为破折号
        return strtolower(str_replace('_', '-', $code));
    }
    
    /**
     * 模糊查找组件
     * 
     * 尝试多种格式匹配
     */
    private function fuzzyFindComponent(string $componentCode, ?string $styleCode = null): ?Component
    {
        $possibleCodes = [];
        
        // 生成可能的代码格式
        $normalizedCode = strtolower(str_replace('_', '-', $componentCode));
        $possibleCodes[] = $normalizedCode;
        
        // 如果有模板前缀格式的代码，提取核心部分
        if (preg_match('/^([a-z0-9]+)[-_]([a-z]+)[-_](.+)$/i', $componentCode, $matches)) {
            $possibleCodes[] = strtolower($matches[2] . '-' . str_replace('_', '-', $matches[3]));
        }
        
        // 尝试每种可能的代码
        foreach (array_unique($possibleCodes) as $code) {
            $component = clone $this->componentModel;
            $query = $component->clear()->where(Component::schema_fields_CODE, $code);
            
            if ($styleCode) {
                $query->where(Component::schema_fields_STYLE_CODE, $styleCode);
            }
            
            $query->where(Component::schema_fields_IS_ACTIVE, 1)->find()->fetch();
            
            if ($component->getId()) {
                return $component;
            }
        }
        
        return null;
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
     * @param string|null $styleCode 模板代码（可选，用于精确查找组件）
     * @return string 渲染后的 HTML
     */
    public function renderPreview(string $componentCode, array $config = [], ?string $styleCode = null): string
    {
        $component = $this->getByCode($componentCode, $styleCode);
        
        if (!$component) {
            throw new \Exception('组件不存在: ' . $componentCode);
        }
        
        $styleCode = $component->getData(Component::schema_fields_STYLE_CODE);
        $path = $component->getData(Component::schema_fields_PATH);
        
        if (empty($path)) {
            throw new \Exception('组件路径未定义: ' . $componentCode);
        }
        
        // 合并默认配置和自定义配置
        $defaultConfig = $component->getDefaultConfig();
        $mergedConfig = array_merge($defaultConfig, $config);
        
        // 使用框架的模板引擎渲染组件
        $template = \Weline\Framework\View\Template::getInstance();
        
        // 加载组件所属模板的颜色配置
        $colors = $this->loadTemplateColors($styleCode);
        
        // 准备模板变量：预览时使用桩对象提供导航/页脚示例数据，避免 nav、footer 等组件渲染为空
        $template->assign('page', new PreviewPageStub());
        $template->assign('style', $mergedConfig);
        $template->assign('style_settings', $mergedConfig);
        $template->assign('component_config', $mergedConfig);
        $template->assign('getConfig', static function (string $key, $default = null) use ($mergedConfig) {
            $value = $mergedConfig;
            foreach (explode('.', $key) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }
            return $value;
        });
        $template->assign('is_preview', true);
        $template->assign('colors', $colors);
        $template->assign('template_code', $styleCode);
        
        // 为预览模式提供示例数据（确保所有组件都能正常显示预览）
        $previewData = $this->getPreviewSampleData($componentCode);
        foreach ($previewData as $key => $value) {
            $template->assign($key, $value);
        }
        
        try {
            // 检查组件文件是否存在
            $fullPath = $component->getFullPath();
            
            if (!file_exists($fullPath)) {
                throw new \Exception("组件文件不存在: {$path}");
            }
            
            // 使用模块路径格式渲染组件
            // path 格式类似: style/tpmst/components/header/nav.phtml
            $templatePath = "GuoLaiRen_PageBuilder::templates/{$path}";
            
            // 启用输出缓冲捕获模板可能的直接输出
            ob_start();
            $html = $template->fetch($templatePath);
            $directOutput = ob_get_clean();
            
            // 如果有直接输出，合并到结果中
            if (!empty($directOutput)) {
                $html = $directOutput . ($html ?? '');
            }
            
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
    public function extractPreviewHtml(Component $component): string
    {
        // 启用输出缓冲，捕获所有可能的直接输出
        ob_start();
        
        try {
            $componentCode = $component->getData(Component::schema_fields_CODE);
            $styleCode = $component->getData(Component::schema_fields_STYLE_CODE);
            // 直接通过模板渲染获取组件 HTML（使用组件所属模板）
            $html = $this->renderPreview($componentCode, [], $styleCode);
            
            // 获取可能的直接输出
            ob_get_clean();
            
            if (empty($html)) {
                return '';
            }
            
            // 为预览 HTML 添加唯一容器类名（样式隔离）
            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $componentCode);
            return '<div class="cp-' . $safeCode . ' component-preview-wrapper">' . $html . '</div>';
            
        } catch (\Throwable $e) {
            // 清理输出缓冲
            ob_end_clean();
            
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
        $styleCode = $component->getData(Component::schema_fields_STYLE_CODE);
        $thumbnail = $component->getData(Component::schema_fields_THUMBNAIL);
        $category = $component->getData(Component::schema_fields_CATEGORY);
        $componentCode = $component->getData(Component::schema_fields_CODE);
        
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
        
        // 从 config_schema 中提取 region 和 icon（如果有的话）
        $configSchema = $component->getConfigSchema();
        $region = $configSchema['region'] ?? $this->categoryToRegion($category);
        $icon = $configSchema['icon'] ?? null;
        
        $result = [
            'id' => $component->getId(),
            'code' => $componentCode,
            'name' => $component->getData(Component::schema_fields_NAME),
            'description' => $component->getData(Component::schema_fields_DESCRIPTION),
            'style_code' => $styleCode,
            'category' => $category,
            'region' => $region,
            'type' => $component->getData(Component::schema_fields_TYPE),
            'thumbnail' => $thumbnail,
            'thumbnail_url' => $thumbnailUrl,
            'icon' => $icon, // 组件图标（用于预览缩略图的后备显示）
            'config_schema' => $configSchema,
            'default_config' => $component->getDefaultConfig(),
            'compatible_styles' => $component->getCompatibleStyles(),
            'is_system' => (bool)$component->getData(Component::schema_fields_IS_SYSTEM),
            'is_shared' => $styleCode === self::SHARED_STYLE_CODE,
            'is_ai_generated' => (bool)$component->getData(Component::schema_fields_IS_AI_GENERATED),
            'sort_order' => (int)$component->getData(Component::schema_fields_SORT_ORDER),
            'preview_html' => '',
            'preview_html_encoded' => false, // 标记预览HTML是否已编码
        ];
        
        // 如果需要预览HTML，从组件文件中提取
        if ($includePreview) {
            $previewHtml = $this->extractPreviewHtml($component);
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
    
    /**
     * 获取预览模式的示例数据
     * 
     * 为依赖外部数据的组件提供示例数据，确保预览能正常显示
     * 
     * @param string $componentCode 组件代码
     * @return array 示例数据
     */
    private function getPreviewSampleData(string $componentCode): array
    {
        $sampleData = [];
        
        // 根据组件代码或类别提供相应的示例数据
        $codeNormalized = strtolower(str_replace(['_', '-'], '', $componentCode));
        
        // 博客相关组件
        if (str_contains($codeNormalized, 'blog') || str_contains($codeNormalized, 'post')) {
            $sampleData['blog_posts'] = $this->getSampleBlogPosts();
            $sampleData['blog_categories'] = $this->getSampleBlogCategories();
            $sampleData['recent_posts'] = array_slice($this->getSampleBlogPosts(), 0, 5);
        }
        
        // 游戏相关组件
        if (str_contains($codeNormalized, 'game')) {
            $sampleData['games'] = $this->getSampleGames();
        }
        
        // 评价/评论相关组件
        if (str_contains($codeNormalized, 'testimonial') || str_contains($codeNormalized, 'review')) {
            $sampleData['testimonials'] = $this->getSampleTestimonials();
        }
        
        // FAQ 组件
        if (str_contains($codeNormalized, 'faq')) {
            $sampleData['faq_items'] = $this->getSampleFaqItems();
        }
        
        // 团队成员组件
        if (str_contains($codeNormalized, 'team')) {
            $sampleData['team_members'] = $this->getSampleTeamMembers();
        }
        
        // 特性/功能组件
        if (str_contains($codeNormalized, 'feature') || str_contains($codeNormalized, 'advantage')) {
            $sampleData['features'] = $this->getSampleFeatures();
        }
        
        // 合作伙伴/品牌组件
        if (str_contains($codeNormalized, 'partner') || str_contains($codeNormalized, 'brand') || str_contains($codeNormalized, 'client')) {
            $sampleData['partners'] = $this->getSamplePartners();
        }
        
        // 价格表组件
        if (str_contains($codeNormalized, 'pricing') || str_contains($codeNormalized, 'plan')) {
            $sampleData['pricing_plans'] = $this->getSamplePricingPlans();
        }
        
        // 统计数字组件
        if (str_contains($codeNormalized, 'stat') || str_contains($codeNormalized, 'counter')) {
            $sampleData['statistics'] = $this->getSampleStatistics();
        }
        
        return $sampleData;
    }
    
    /**
     * 示例博客文章数据
     */
    private function getSampleBlogPosts(): array
    {
        return [
            [
                'id' => 1,
                'title' => 'Getting Started with Our Platform',
                'summary' => 'Learn how to quickly set up and start using our platform with this comprehensive guide.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/6c5ce7/ffffff?text=Blog+Post+1',
                'category_name' => 'Guides',
                'category_slug' => 'guides',
                'author' => 'John Doe',
                'published_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
                'url' => '#blog-post-1',
            ],
            [
                'id' => 2,
                'title' => 'Top 10 Tips for Success',
                'summary' => 'Discover the top strategies that successful users employ to get the most out of their experience.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/00cec9/ffffff?text=Blog+Post+2',
                'category_name' => 'Tips',
                'category_slug' => 'tips',
                'author' => 'Jane Smith',
                'published_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
                'url' => '#blog-post-2',
            ],
            [
                'id' => 3,
                'title' => 'Latest Updates and Features',
                'summary' => 'Stay informed about the newest features and improvements we have released this month.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/fd79a8/ffffff?text=Blog+Post+3',
                'category_name' => 'Updates',
                'category_slug' => 'updates',
                'author' => 'Mike Johnson',
                'published_at' => date('Y-m-d H:i:s', strtotime('-7 days')),
                'url' => '#blog-post-3',
            ],
            [
                'id' => 4,
                'title' => 'Best Practices for Beginners',
                'summary' => 'A comprehensive overview of best practices that every new user should know.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/a29bfe/ffffff?text=Blog+Post+4',
                'category_name' => 'Guides',
                'category_slug' => 'guides',
                'author' => 'Sarah Williams',
                'published_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
                'url' => '#blog-post-4',
            ],
            [
                'id' => 5,
                'title' => 'Community Spotlight: User Stories',
                'summary' => 'Read inspiring stories from our community members about their journey and achievements.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/74b9ff/ffffff?text=Blog+Post+5',
                'category_name' => 'Community',
                'category_slug' => 'community',
                'author' => 'Emily Brown',
                'published_at' => date('Y-m-d H:i:s', strtotime('-14 days')),
                'url' => '#blog-post-5',
            ],
            [
                'id' => 6,
                'title' => 'Advanced Techniques Deep Dive',
                'summary' => 'Take your skills to the next level with these advanced techniques and strategies.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/55a3ff/ffffff?text=Blog+Post+6',
                'category_name' => 'Advanced',
                'category_slug' => 'advanced',
                'author' => 'David Chen',
                'published_at' => date('Y-m-d H:i:s', strtotime('-21 days')),
                'url' => '#blog-post-6',
            ],
        ];
    }
    
    /**
     * 示例博客分类数据
     */
    private function getSampleBlogCategories(): array
    {
        return [
            ['id' => 1, 'name' => 'Guides', 'slug' => 'guides', 'url' => '#category-guides', 'post_count' => 12],
            ['id' => 2, 'name' => 'Tips & Tricks', 'slug' => 'tips', 'url' => '#category-tips', 'post_count' => 8],
            ['id' => 3, 'name' => 'Updates', 'slug' => 'updates', 'url' => '#category-updates', 'post_count' => 15],
            ['id' => 4, 'name' => 'Community', 'slug' => 'community', 'url' => '#category-community', 'post_count' => 6],
            ['id' => 5, 'name' => 'Advanced', 'slug' => 'advanced', 'url' => '#category-advanced', 'post_count' => 10],
        ];
    }
    
    /**
     * 示例游戏数据
     */
    private function getSampleGames(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Teen Patti',
                'description' => 'Classic Indian card game with exciting gameplay',
                'image' => 'https://placehold.co/400x300/6c5ce7/ffffff?text=Game+1',
                'players' => '2-6',
                'rating' => 4.8,
            ],
            [
                'id' => 2,
                'name' => 'Rummy',
                'description' => 'Popular card game requiring skill and strategy',
                'image' => 'https://placehold.co/400x300/00cec9/ffffff?text=Game+2',
                'players' => '2-4',
                'rating' => 4.6,
            ],
            [
                'id' => 3,
                'name' => 'Poker',
                'description' => 'World-famous card game with multiple variants',
                'image' => 'https://placehold.co/400x300/fd79a8/ffffff?text=Game+3',
                'players' => '2-10',
                'rating' => 4.9,
            ],
            [
                'id' => 4,
                'name' => 'Blackjack',
                'description' => 'Classic casino card game of 21',
                'image' => 'https://placehold.co/400x300/a29bfe/ffffff?text=Game+4',
                'players' => '1-7',
                'rating' => 4.7,
            ],
        ];
    }
    
    /**
     * 示例用户评价数据
     */
    private function getSampleTestimonials(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'John D.',
                'role' => 'Business Owner',
                'avatar' => 'https://placehold.co/100x100/6c5ce7/ffffff?text=JD',
                'content' => 'Absolutely amazing experience! The platform exceeded all my expectations and the support team is fantastic.',
                'rating' => 5,
            ],
            [
                'id' => 2,
                'name' => 'Sarah M.',
                'role' => 'Designer',
                'avatar' => 'https://placehold.co/100x100/00cec9/ffffff?text=SM',
                'content' => 'I have been using this for months now and I can not imagine going back. It has transformed how I work.',
                'rating' => 5,
            ],
            [
                'id' => 3,
                'name' => 'Michael T.',
                'role' => 'Developer',
                'avatar' => 'https://placehold.co/100x100/fd79a8/ffffff?text=MT',
                'content' => 'Great product with excellent features. The team is constantly improving and adding new capabilities.',
                'rating' => 4,
            ],
        ];
    }
    
    /**
     * 示例 FAQ 数据
     */
    private function getSampleFaqItems(): array
    {
        return [
            [
                'question' => 'How do I get started?',
                'answer' => 'Getting started is easy! Simply sign up for an account, complete the onboarding process, and you will be ready to go in minutes.',
            ],
            [
                'question' => 'What payment methods do you accept?',
                'answer' => 'We accept all major credit cards, PayPal, and bank transfers. All payments are processed securely.',
            ],
            [
                'question' => 'Is there a free trial available?',
                'answer' => 'Yes! We offer a 14-day free trial with full access to all features. No credit card required.',
            ],
            [
                'question' => 'How can I contact support?',
                'answer' => 'Our support team is available 24/7 via email, live chat, and phone. We typically respond within 2 hours.',
            ],
        ];
    }
    
    /**
     * 示例团队成员数据
     */
    private function getSampleTeamMembers(): array
    {
        return [
            [
                'name' => 'Alex Johnson',
                'role' => 'CEO & Founder',
                'avatar' => 'https://placehold.co/300x300/6c5ce7/ffffff?text=AJ',
                'bio' => 'Visionary leader with 15+ years of industry experience.',
            ],
            [
                'name' => 'Emily Chen',
                'role' => 'CTO',
                'avatar' => 'https://placehold.co/300x300/00cec9/ffffff?text=EC',
                'bio' => 'Tech expert driving innovation and engineering excellence.',
            ],
            [
                'name' => 'Michael Brown',
                'role' => 'Head of Design',
                'avatar' => 'https://placehold.co/300x300/fd79a8/ffffff?text=MB',
                'bio' => 'Creative director crafting beautiful user experiences.',
            ],
            [
                'name' => 'Sarah Williams',
                'role' => 'Marketing Director',
                'avatar' => 'https://placehold.co/300x300/a29bfe/ffffff?text=SW',
                'bio' => 'Strategic marketer building brand awareness globally.',
            ],
        ];
    }
    
    /**
     * 示例特性/功能数据
     */
    private function getSampleFeatures(): array
    {
        return [
            [
                'title' => 'Easy to Use',
                'description' => 'Intuitive interface designed for users of all skill levels.',
                'icon' => 'star',
            ],
            [
                'title' => 'Fast & Reliable',
                'description' => 'Lightning-fast performance with 99.9% uptime guarantee.',
                'icon' => 'zap',
            ],
            [
                'title' => 'Secure',
                'description' => 'Enterprise-grade security protecting your data 24/7.',
                'icon' => 'shield',
            ],
            [
                'title' => '24/7 Support',
                'description' => 'Round-the-clock customer support whenever you need help.',
                'icon' => 'headphones',
            ],
        ];
    }
    
    /**
     * 示例合作伙伴数据
     */
    private function getSamplePartners(): array
    {
        return [
            ['name' => 'Partner A', 'logo' => 'https://placehold.co/200x80/f5f5f5/333333?text=Partner+A'],
            ['name' => 'Partner B', 'logo' => 'https://placehold.co/200x80/f5f5f5/333333?text=Partner+B'],
            ['name' => 'Partner C', 'logo' => 'https://placehold.co/200x80/f5f5f5/333333?text=Partner+C'],
            ['name' => 'Partner D', 'logo' => 'https://placehold.co/200x80/f5f5f5/333333?text=Partner+D'],
            ['name' => 'Partner E', 'logo' => 'https://placehold.co/200x80/f5f5f5/333333?text=Partner+E'],
        ];
    }
    
    /**
     * 示例价格方案数据
     */
    private function getSamplePricingPlans(): array
    {
        return [
            [
                'name' => 'Starter',
                'price' => 9,
                'period' => 'month',
                'features' => ['Basic features', 'Email support', '1 project', '1GB storage'],
                'is_popular' => false,
            ],
            [
                'name' => 'Professional',
                'price' => 29,
                'period' => 'month',
                'features' => ['All Starter features', 'Priority support', '10 projects', '10GB storage', 'API access'],
                'is_popular' => true,
            ],
            [
                'name' => 'Enterprise',
                'price' => 99,
                'period' => 'month',
                'features' => ['All Pro features', 'Dedicated support', 'Unlimited projects', '100GB storage', 'Custom integrations'],
                'is_popular' => false,
            ],
        ];
    }
    
    /**
     * 示例统计数据
     */
    private function getSampleStatistics(): array
    {
        return [
            ['label' => 'Happy Customers', 'value' => '10,000+', 'icon' => 'users'],
            ['label' => 'Projects Completed', 'value' => '50,000+', 'icon' => 'check-circle'],
            ['label' => 'Years Experience', 'value' => '10+', 'icon' => 'calendar'],
            ['label' => 'Team Members', 'value' => '100+', 'icon' => 'briefcase'],
        ];
    }
}
