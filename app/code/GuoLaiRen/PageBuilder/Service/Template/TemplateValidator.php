<?php

declare(strict_types=1);

/**
 * 模板验证服务
 * 
 * 验证模板结构是否符合规范，包括：
 * - 必需目录和文件是否存在
 * - 配置文件格式是否正确
 * - 组件文件是否完整
 * 
 * @author GuoLaiRen
 * @since 1.0.0
 */

namespace GuoLaiRen\PageBuilder\Service\Template;

use Weline\Framework\Manager\ObjectManager;

class TemplateValidator
{
    private TemplatePathResolver $pathResolver;
    
    /**
     * 必需的目录列表
     */
    private const REQUIRED_DIRECTORIES = [
        'components',
        'components/header',
        'components/content',
        'components/footer',
        'layouts',
        'layouts/default',
    ];
    
    /**
     * 必需的文件列表
     */
    private const REQUIRED_FILES = [
        'components/component.json',
    ];
    
    /**
     * 必需的布局配置文件
     */
    private const REQUIRED_LAYOUT_CONFIGS = [
        'home_page.json',
        'custom_page.json',
    ];
    
    /**
     * 验证结果
     */
    private array $errors = [];
    private array $warnings = [];
    
    /**
     * 单例实例
     */
    private static ?self $instance = null;
    
    public function __construct(
        ?TemplatePathResolver $pathResolver = null
    ) {
        $this->pathResolver = $pathResolver ?? TemplatePathResolver::getInstance();
    }
    
    /**
     * 验证模板
     * 
     * @param string $styleCode 模板代码
     * @param bool $strict 是否严格模式（警告也视为错误）
     * @return bool 是否通过验证
     */
    public function validate(string $styleCode, bool $strict = false): bool
    {
        $this->errors = [];
        $this->warnings = [];
        
        // 1. 检查模板目录是否存在
        if (!$this->pathResolver->templateExists($styleCode)) {
            $this->errors[] = "模板目录不存在: {$styleCode}";
            return false;
        }
        
        // 2. 检查必需目录
        $this->validateRequiredDirectories($styleCode);
        
        // 3. 检查必需文件
        $this->validateRequiredFiles($styleCode);
        
        // 4. 验证 component.json
        $this->validateComponentJson($styleCode);
        
        // 5. 验证布局配置文件
        $this->validateLayoutConfigs($styleCode);
        
        // 6. 验证 template.json（可选，但推荐）
        $this->validateTemplateJson($styleCode);
        
        // 7. 验证组件文件完整性
        $this->validateComponentFiles($styleCode);
        
        if ($strict) {
            return empty($this->errors) && empty($this->warnings);
        }
        
        return empty($this->errors);
    }
    
    /**
     * 获取验证错误
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * 获取验证警告
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
    
    /**
     * 获取验证报告
     */
    public function getReport(): array
    {
        return [
            'valid' => empty($this->errors),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
    
    /**
     * 生成格式化的验证报告
     */
    public function generateReport(string $styleCode): string
    {
        $this->validate($styleCode);
        
        $report = "模板验证报告: {$styleCode}\n";
        $report .= str_repeat('=', 50) . "\n\n";
        
        if (empty($this->errors) && empty($this->warnings)) {
            $report .= "✓ 模板验证通过，无错误和警告\n";
            return $report;
        }
        
        if (!empty($this->errors)) {
            $report .= "错误 (" . count($this->errors) . "):\n";
            foreach ($this->errors as $error) {
                $report .= "  ✗ {$error}\n";
            }
            $report .= "\n";
        }
        
        if (!empty($this->warnings)) {
            $report .= "警告 (" . count($this->warnings) . "):\n";
            foreach ($this->warnings as $warning) {
                $report .= "  ⚠ {$warning}\n";
            }
        }
        
        return $report;
    }
    
    /**
     * 验证必需目录
     */
    private function validateRequiredDirectories(string $styleCode): void
    {
        $basePath = $this->pathResolver->getTemplatePath($styleCode);
        
        foreach (self::REQUIRED_DIRECTORIES as $dir) {
            $path = $basePath . '/' . $dir;
            if (!is_dir($path)) {
                $this->errors[] = "缺少必需目录: {$dir}";
            }
        }
    }
    
    /**
     * 验证必需文件
     */
    private function validateRequiredFiles(string $styleCode): void
    {
        $basePath = $this->pathResolver->getTemplatePath($styleCode);
        
        foreach (self::REQUIRED_FILES as $file) {
            $path = $basePath . '/' . $file;
            if (!is_file($path)) {
                $this->errors[] = "缺少必需文件: {$file}";
            }
        }
    }
    
    /**
     * 验证 component.json
     */
    private function validateComponentJson(string $styleCode): void
    {
        $jsonPath = $this->pathResolver->getComponentJsonPath($styleCode);
        
        if (!is_file($jsonPath)) {
            return; // 已在 validateRequiredFiles 中报告
        }
        
        $content = file_get_contents($jsonPath);
        $config = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = "component.json 格式错误: " . json_last_error_msg();
            return;
        }
        
        // 检查必需字段
        if (!isset($config['components']) || !is_array($config['components'])) {
            $this->errors[] = "component.json 缺少 'components' 字段";
            return;
        }
        
        // 验证每个组件配置
        foreach ($config['components'] as $code => $componentConfig) {
            $this->validateComponentConfig($styleCode, $code, $componentConfig);
        }
        
        // 检查 regions 配置（可选但推荐）
        if (!isset($config['regions'])) {
            $this->warnings[] = "component.json 缺少 'regions' 配置，建议添加";
        }
    }
    
    /**
     * 验证单个组件配置
     */
    private function validateComponentConfig(string $styleCode, string $code, array $config): void
    {
        $prefix = "组件 '{$code}'";
        
        // 验证组件代码格式
        if (!$this->isValidComponentCode($code)) {
            $this->errors[] = "{$prefix}: 组件代码格式不正确，应使用 {category}-{name} 格式";
        }
        
        // 必需字段
        if (empty($config['name'])) {
            $this->errors[] = "{$prefix}: 缺少 'name' 字段";
        }
        
        if (empty($config['category'])) {
            $this->errors[] = "{$prefix}: 缺少 'category' 字段";
        } elseif (!in_array($config['category'], ['header', 'content', 'footer', 'widget'])) {
            $this->errors[] = "{$prefix}: 'category' 值无效，应为 header/content/footer/widget";
        }
        
        if (empty($config['file'])) {
            $this->errors[] = "{$prefix}: 缺少 'file' 字段";
        }
        
        // 推荐字段
        if (empty($config['description'])) {
            $this->warnings[] = "{$prefix}: 建议添加 'description' 字段";
        }
        
        if (!isset($config['sort_order'])) {
            $this->warnings[] = "{$prefix}: 建议添加 'sort_order' 字段";
        }
    }
    
    /**
     * 验证布局配置文件
     */
    private function validateLayoutConfigs(string $styleCode): void
    {
        $layoutPath = $this->pathResolver->getDefaultLayoutsPath($styleCode);
        
        if (!is_dir($layoutPath)) {
            return; // 已在 validateRequiredDirectories 中报告
        }
        
        foreach (self::REQUIRED_LAYOUT_CONFIGS as $file) {
            $filePath = $layoutPath . '/' . $file;
            if (!is_file($filePath)) {
                $this->errors[] = "缺少必需布局配置: layouts/default/{$file}";
                continue;
            }
            
            $this->validateLayoutConfigFile($styleCode, $file);
        }
    }
    
    /**
     * 验证单个布局配置文件
     */
    private function validateLayoutConfigFile(string $styleCode, string $file): void
    {
        $filePath = $this->pathResolver->getLayoutConfigPath(
            $styleCode,
            str_replace('.json', '', $file)
        );
        
        $content = file_get_contents($filePath);
        $config = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = "布局配置 {$file} 格式错误: " . json_last_error_msg();
            return;
        }
        
        // 检查必需字段
        if (!isset($config['layout_config'])) {
            $this->errors[] = "布局配置 {$file} 缺少 'layout_config' 字段";
            return;
        }
        
        $layoutConfig = $config['layout_config'];
        
        // 检查 header 和 footer
        if (empty($layoutConfig['header'])) {
            $this->warnings[] = "布局配置 {$file} 的 'header' 为空";
        }
        
        if (empty($layoutConfig['footer'])) {
            $this->warnings[] = "布局配置 {$file} 的 'footer' 为空";
        }
        
        // 验证组件配置格式
        foreach (['header', 'content', 'footer'] as $region) {
            if (isset($layoutConfig[$region]) && is_array($layoutConfig[$region])) {
                foreach ($layoutConfig[$region] as $index => $component) {
                    if (!isset($component['code'])) {
                        $this->errors[] = "布局配置 {$file} 的 {$region}[{$index}] 缺少 'code' 字段";
                    }
                }
            }
        }
    }
    
    /**
     * 验证 template.json（可选）
     */
    private function validateTemplateJson(string $styleCode): void
    {
        $jsonPath = $this->pathResolver->getTemplateJsonPath($styleCode);
        
        if (!is_file($jsonPath)) {
            $this->warnings[] = "缺少 template.json 文件，建议添加以定义模板元数据";
            return;
        }
        
        $content = file_get_contents($jsonPath);
        $config = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = "template.json 格式错误: " . json_last_error_msg();
            return;
        }
        
        // 检查推荐字段
        $recommendedFields = ['code', 'name', 'version', 'description'];
        foreach ($recommendedFields as $field) {
            if (empty($config[$field])) {
                $this->warnings[] = "template.json 建议添加 '{$field}' 字段";
            }
        }
        
        // 验证 code 与目录名一致
        if (!empty($config['code']) && $config['code'] !== $styleCode) {
            $this->warnings[] = "template.json 中的 'code' ({$config['code']}) 与目录名 ({$styleCode}) 不一致";
        }
    }
    
    /**
     * 验证组件文件完整性
     */
    private function validateComponentFiles(string $styleCode): void
    {
        $jsonPath = $this->pathResolver->getComponentJsonPath($styleCode);
        
        if (!is_file($jsonPath)) {
            return;
        }
        
        $content = file_get_contents($jsonPath);
        $config = json_decode($content, true);
        
        if (!isset($config['components']) || !is_array($config['components'])) {
            return;
        }
        
        foreach ($config['components'] as $code => $componentConfig) {
            $file = $componentConfig['file'] ?? '';
            if (empty($file)) {
                continue; // 已在 validateComponentConfig 中报告
            }
            
            $filePath = $this->pathResolver->resolveComponentFilesystemPath($styleCode, $file);
            if (!is_file($filePath)) {
                $this->errors[] = "组件 '{$code}' 的文件不存在: {$file}";
            }
        }
    }
    
    /**
     * 验证组件代码格式
     * 
     * 有效格式: {category}-{name}
     * 示例: header-nav, content-hero, footer-links
     */
    private function isValidComponentCode(string $code): bool
    {
        // 允许的格式: category-name 或 category-name-suffix
        return (bool)preg_match('/^[a-z]+-[a-z0-9]+(-[a-z0-9]+)*$/i', $code);
    }
    
    /**
     * 快速验证模板是否可用（仅检查关键文件）
     */
    public function quickValidate(string $styleCode): bool
    {
        // 检查模板目录
        if (!$this->pathResolver->templateExists($styleCode)) {
            return false;
        }
        
        // 检查 component.json
        if (!$this->pathResolver->fileExists($this->pathResolver->getComponentJsonPath($styleCode))) {
            return false;
        }
        
        // 检查至少有一个布局配置
        $layoutPath = $this->pathResolver->getDefaultLayoutsPath($styleCode);
        if (!is_dir($layoutPath)) {
            return false;
        }
        
        return true;
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
