<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Service\Template\TemplatePathResolver;
use Weline\Framework\Manager\ObjectManager;

/**
 * 组件规约验证服务
 * 
 * 用于验证组件配置的完整性和正确性，确保：
 * 1. component.json 中定义的组件文件存在
 * 2. 组件代码命名规范（小写、使用连字符）
 * 3. 必需字段完整（name, file, region, category）
 * 4. 文件路径正确且文件存在
 * 5. 布局配置中的组件代码在 component.json 中有定义
 * 
 * @author GuoLaiRen
 * @since 1.0.0
 */
class ComponentValidator
{
    private TemplatePathResolver $pathResolver;
    
    /**
     * 单例实例
     */
    private static ?self $instance = null;
    
    public function __construct(?TemplatePathResolver $pathResolver = null)
    {
        $this->pathResolver = $pathResolver ?? TemplatePathResolver::getInstance();
    }

    /**
     * 组件必需字段
     */
    private const REQUIRED_FIELDS = ['name', 'file', 'region', 'category'];
    
    /**
     * 有效的区域名称
     */
    private const VALID_REGIONS = ['header', 'content', 'footer'];
    
    /**
     * 有效的组件类别
     */
    private const VALID_CATEGORIES = ['header', 'content', 'footer', 'widget'];
    
    /**
     * 验证结果
     */
    private array $errors = [];
    private array $warnings = [];
    
    /**
     * 验证模板的所有组件
     * 
     * @param string $styleCode 模板代码
     * @param bool $throwOnError 是否在遇到错误时抛出异常
     * @return array ['valid' => bool, 'errors' => [], 'warnings' => []]
     * @throws \Exception
     */
    public function validateTemplate(string $styleCode, bool $throwOnError = false): array
    {
        $this->errors = [];
        $this->warnings = [];
        
        $basePath = $this->pathResolver->getTemplatePath($styleCode);
        $componentJsonPath = $this->pathResolver->getComponentJsonPath($styleCode);
        
        // 1. 检查 component.json 是否存在
        if (!file_exists($componentJsonPath)) {
            $this->addError("模板 {$styleCode} 的 component.json 文件不存在: {$componentJsonPath}");
            return $this->getResult($throwOnError);
        }
        
        // 2. 解析 component.json
        $jsonContent = file_get_contents($componentJsonPath);
        $config = json_decode($jsonContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError("component.json JSON 格式错误: " . json_last_error_msg());
            return $this->getResult($throwOnError);
        }
        
        // 3. 验证基本结构
        $this->validateJsonStructure($config, $styleCode);
        
        // 4. 验证每个组件
        $components = $config['components'] ?? [];
        foreach ($components as $code => $componentConfig) {
            $this->validateComponent($styleCode, $code, $componentConfig);
        }
        $componentsForValidation = $this->appendImplicitSystemComponents($components, $styleCode);
        
        // 5. 验证区域配置
        $regions = $config['regions'] ?? [];
        foreach ($regions as $regionName => $regionConfig) {
            $this->validateRegion($regionName, $regionConfig, $componentsForValidation);
        }
        
        // 6. 检查是否有孤立的组件文件（在目录中但不在配置中）
        $this->checkOrphanedFiles($basePath, $components);
        
        return $this->getResult($throwOnError);
    }
    
    /**
     * 验证布局配置中的组件引用
     * 
     * @param array $layoutConfig 布局配置
     * @param string $styleCode 模板代码
     * @param bool $throwOnError 是否在遇到错误时抛出异常
     * @return array
     */
    public function validateLayoutConfig(array $layoutConfig, string $styleCode, bool $throwOnError = false): array
    {
        $this->errors = [];
        $this->warnings = [];
        
        // 加载组件配置
        $componentJsonPath = $this->pathResolver->getComponentJsonPath($styleCode);
        
        if (!file_exists($componentJsonPath)) {
            $this->addError("模板 {$styleCode} 的 component.json 不存在");
            return $this->getResult($throwOnError);
        }
        
        $config = json_decode(file_get_contents($componentJsonPath), true);
        $validComponents = array_keys($this->appendImplicitSystemComponents($config['components'] ?? [], $styleCode));
        
        // 检查每个区域的组件
        foreach (['header', 'content', 'footer'] as $region) {
            $regionConfig = $layoutConfig[$region] ?? [];
            
            // 处理不同格式的配置
            $components = $this->normalizeRegionConfig($regionConfig);
            
            foreach ($components as $component) {
                $code = $component['code'] ?? $component['component'] ?? '';
                if (empty($code)) {
                    continue;
                }
                
                if (!in_array($code, $validComponents)) {
                    $this->addError("布局配置中的组件 '{$code}' 在 component.json 中未定义 (区域: {$region})");
                }
            }
        }
        
        return $this->getResult($throwOnError);
    }
    
    /**
     * 获取组件元数据并验证
     * 
     * @param string $styleCode 模板代码
     * @param string $componentCode 组件代码
     * @return array|null 组件元数据，如果验证失败返回 null
     */
    public function getValidatedComponentMetadata(string $styleCode, string $componentCode): ?array
    {
        $componentJsonPath = $this->pathResolver->getComponentJsonPath($styleCode);
        
        if (!file_exists($componentJsonPath)) {
            return null;
        }
        
        $config = json_decode(file_get_contents($componentJsonPath), true);
        $componentConfig = $config['components'][$componentCode] ?? null;
        
        if (!$componentConfig) {
            return null;
        }
        
        // 验证组件文件存在
        $filePath = $componentConfig['file'] ?? '';
        $fullPath = $this->pathResolver->resolveComponentFilesystemPath($styleCode, $filePath);

        if (!file_exists($fullPath)) {
            return null;
        }
        
        return $componentConfig;
    }
    
    /**
     * 验证 JSON 结构
     */
    private function validateJsonStructure(array $config, string $styleCode): void
    {
        // 检查必需的顶级字段
        $requiredTopLevel = ['template', 'components'];
        foreach ($requiredTopLevel as $field) {
            if (!isset($config[$field])) {
                $this->addError("component.json 缺少必需字段: {$field}");
            }
        }
        
        // 验证 template 字段与目录名一致
        if (isset($config['template']) && $config['template'] !== $styleCode) {
            $this->addWarning("component.json 中的 template 字段 '{$config['template']}' 与目录名 '{$styleCode}' 不一致");
        }
        
        // 检查 components 是否是数组
        if (isset($config['components']) && !is_array($config['components'])) {
            $this->addError("components 字段必须是数组");
        }
    }
    
    /**
     * 验证单个组件配置
     */
    private function validateComponent(string $styleCode, string $code, array $config): void
    {
        // 1. 验证组件代码格式
        if (!$this->isValidComponentCode($code)) {
            $this->addError("组件代码 '{$code}' 格式不正确（应使用小写字母和连字符，如 'hero-slider'）");
        }
        
        // 2. 检查必需字段
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($config[$field]) || (is_string($config[$field]) && trim($config[$field]) === '')) {
                $this->addError("组件 '{$code}' 缺少必需字段: {$field}");
            }
        }
        
        // 3. 验证 region 值
        if (isset($config['region']) && !in_array($config['region'], self::VALID_REGIONS)) {
            $this->addError("组件 '{$code}' 的 region 值 '{$config['region']}' 无效，有效值: " . implode(', ', self::VALID_REGIONS));
        }
        
        // 4. 验证 category 值
        if (isset($config['category']) && !in_array($config['category'], self::VALID_CATEGORIES)) {
            $this->addWarning("组件 '{$code}' 的 category 值 '{$config['category']}' 不在推荐列表中: " . implode(', ', self::VALID_CATEGORIES));
        }
        
        // 5. 验证文件存在
        if (isset($config['file'])) {
            $filePath = (string)$config['file'];
            $resolvedPath = $this->pathResolver->resolveComponentFilesystemPath($styleCode, $filePath);
            if (!file_exists($resolvedPath)) {
                $this->addError("组件 '{$code}' 的文件不存在: {$filePath}（完整路径: {$resolvedPath}）");
            }
        }
        
        // 6. 验证 region 和 category 一致性
        if (isset($config['region']) && isset($config['category'])) {
            if ($config['region'] !== $config['category'] && $config['category'] !== 'widget') {
                $this->addWarning("组件 '{$code}' 的 region ('{$config['region']}') 与 category ('{$config['category']}') 不一致");
            }
        }
        
        // 7. 验证 config_schema（如果存在）
        if (isset($config['config_schema']) && is_array($config['config_schema'])) {
            $this->validateConfigSchema($code, $config['config_schema']);
        }
    }
    
    /**
     * 验证配置 schema
     */
    private function validateConfigSchema(string $componentCode, array $schema): void
    {
        $validTypes = ['text', 'textarea', 'number', 'select', 'boolean', 'color', 'image', 'json'];
        
        foreach ($schema as $fieldKey => $fieldConfig) {
            if (!isset($fieldConfig['type'])) {
                $this->addWarning("组件 '{$componentCode}' 的配置字段 '{$fieldKey}' 缺少 type 定义");
                continue;
            }
            
            if (!in_array($fieldConfig['type'], $validTypes)) {
                $this->addWarning("组件 '{$componentCode}' 的配置字段 '{$fieldKey}' 的类型 '{$fieldConfig['type']}' 不在支持列表中");
            }
            
            // select 类型必须有 options
            if ($fieldConfig['type'] === 'select' && !isset($fieldConfig['options'])) {
                $this->addWarning("组件 '{$componentCode}' 的配置字段 '{$fieldKey}' 是 select 类型但缺少 options");
            }
        }
    }
    
    /**
     * 验证区域配置
     */
    private function validateRegion(string $regionName, array $config, array $components): void
    {
        // 验证区域名
        if (!in_array($regionName, self::VALID_REGIONS)) {
            $this->addWarning("区域名 '{$regionName}' 不在标准列表中");
        }
        
        // 验证默认组件
        if (isset($config['default_component'])) {
            if (!isset($components[$config['default_component']])) {
                $this->addError("区域 '{$regionName}' 的默认组件 '{$config['default_component']}' 在 components 中未定义");
            }
        }
        
        // 验证默认组件列表
        if (isset($config['default_components']) && is_array($config['default_components'])) {
            foreach ($config['default_components'] as $defaultCode) {
                if (!isset($components[$defaultCode])) {
                    $this->addError("区域 '{$regionName}' 的默认组件 '{$defaultCode}' 在 components 中未定义");
                }
            }
        }
    }

    /**
     * default 主题等模板会把 header/footer 作为根级系统组件，
     * 运行时扫描器可直接识别，这里补齐给校验器避免误报。
     */
    private function appendImplicitSystemComponents(array $components, string $styleCode): array
    {
        $basePath = $this->pathResolver->getTemplatePath($styleCode);
        $implicitSystemFiles = [
            'header-system' => $basePath . '/header.phtml',
            'footer-system' => $basePath . '/footer.phtml',
        ];

        foreach ($implicitSystemFiles as $code => $path) {
            if (!isset($components[$code]) && file_exists($path)) {
                $components[$code] = [
                    'name' => $code,
                    'region' => str_starts_with($code, 'header-') ? 'header' : 'footer',
                    'category' => str_starts_with($code, 'header-') ? 'header' : 'footer',
                    'file' => basename($path),
                ];
            }
        }

        return $components;
    }
    
    /**
     * 检查孤立的组件文件
     */
    private function checkOrphanedFiles(string $basePath, array $components): void
    {
        $componentsDir = "{$basePath}/components";
        $registeredFiles = [];
        
        // 收集所有注册的文件
        foreach ($components as $config) {
            if (isset($config['file'])) {
                $registeredFiles[] = $config['file'];
            }
        }
        
        // 扫描目录
        $this->scanForOrphanedFiles($componentsDir, '', $registeredFiles);
    }
    
    /**
     * 递归扫描孤立文件
     */
    private function scanForOrphanedFiles(string $baseDir, string $relativePath, array $registeredFiles): void
    {
        $currentDir = $relativePath ? "{$baseDir}/{$relativePath}" : $baseDir;
        
        if (!is_dir($currentDir)) {
            return;
        }
        
        $items = scandir($currentDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $fullPath = "{$currentDir}/{$item}";
            $relPath = $relativePath ? "{$relativePath}/{$item}" : $item;
            
            if (is_dir($fullPath)) {
                $this->scanForOrphanedFiles($baseDir, $relPath, $registeredFiles);
            } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'phtml' && $item !== 'component.json') {
                if (!in_array($relPath, $registeredFiles)) {
                    $this->addWarning("文件 '{$relPath}' 存在但未在 component.json 中注册");
                }
            }
        }
    }
    
    /**
     * 验证组件代码格式
     */
    private function isValidComponentCode(string $code): bool
    {
        // 组件代码应该是：小写字母、数字、连字符组成
        return (bool)preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $code);
    }
    
    /**
     * 规范化区域配置
     */
    private function normalizeRegionConfig($config): array
    {
        if (empty($config)) {
            return [];
        }
        
        // 数组格式 [{code: ...}, ...]
        if (is_array($config) && isset($config[0])) {
            return $config;
        }
        
        // PageLayout 导出格式 {component: ..., config: ...}
        if (is_array($config) && isset($config['component'])) {
            return !empty($config['component']) ? [['code' => $config['component']]] : [];
        }
        
        // 单组件格式 {code: ...}
        if (is_array($config) && isset($config['code'])) {
            return [$config];
        }
        
        return [];
    }
    
    /**
     * 添加错误
     */
    private function addError(string $message): void
    {
        $this->errors[] = $message;
    }
    
    /**
     * 添加警告
     */
    private function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }
    
    /**
     * 获取验证结果
     */
    private function getResult(bool $throwOnError): array
    {
        $result = [
            'valid' => empty($this->errors),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
        
        if ($throwOnError && !empty($this->errors)) {
            throw new \Exception(
                "组件验证失败:\n" . implode("\n", array_map(fn($e) => "  - {$e}", $this->errors))
            );
        }
        
        return $result;
    }
    
    /**
     * 生成验证报告
     */
    public function generateReport(string $styleCode): string
    {
        $result = $this->validateTemplate($styleCode, false);
        
        $report = "=== 组件验证报告: {$styleCode} ===\n\n";
        
        if ($result['valid']) {
            $report .= "✅ 验证通过\n";
        } else {
            $report .= "❌ 验证失败\n";
        }
        
        if (!empty($result['errors'])) {
            $report .= "\n错误 (" . count($result['errors']) . "):\n";
            foreach ($result['errors'] as $error) {
                $report .= "  ❌ {$error}\n";
            }
        }
        
        if (!empty($result['warnings'])) {
            $report .= "\n警告 (" . count($result['warnings']) . "):\n";
            foreach ($result['warnings'] as $warning) {
                $report .= "  ⚠️ {$warning}\n";
            }
        }
        
        return $report;
    }
    
    /**
     * 获取实例（单例模式）
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
