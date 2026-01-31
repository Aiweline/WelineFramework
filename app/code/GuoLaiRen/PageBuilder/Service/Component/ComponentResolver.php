<?php

declare(strict_types=1);

/**
 * 组件解析服务
 * 
 * 统一的组件查找逻辑，支持：
 * - 当前模板组件查找
 * - 共享组件查找
 * - 跨模板兼容组件查找
 * - 组件代码格式标准化
 * 
 * @author GuoLaiRen
 * @since 1.0.0
 */

namespace GuoLaiRen\PageBuilder\Service\Component;

use GuoLaiRen\PageBuilder\Model\Component;
use GuoLaiRen\PageBuilder\Service\Template\TemplatePathResolver;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use GuoLaiRen\PageBuilder\Service\AI\AIComponentRegistry;
use GuoLaiRen\PageBuilder\Service\AI\EntityFileManager;
use Weline\Framework\Manager\ObjectManager;

class ComponentResolver
{
    private ?TemplatePathResolver $pathResolver = null;
    private ?LayoutConfigNormalizer $configNormalizer = null;
    private ?Component $componentModel = null;
    private ?AIComponentRegistry $aiRegistry = null;
    private ?EntityFileManager $entityFileManager = null;
    
    /**
     * 组件缓存
     */
    private static array $componentCache = [];
    
    /**
     * 组件文件映射缓存（从 component.json 加载）
     */
    private static array $componentFilesCache = [];
    
    /**
     * 单例实例
     */
    private static ?self $instance = null;
    
    public function __construct(
        ?TemplatePathResolver $pathResolver = null,
        ?LayoutConfigNormalizer $configNormalizer = null,
        ?Component $componentModel = null
    ) {
        $this->pathResolver = $pathResolver;
        $this->configNormalizer = $configNormalizer;
        $this->componentModel = $componentModel;
    }
    
    /**
     * 获取 AI 组件注册表（延迟加载）
     */
    private function getAIRegistry(): AIComponentRegistry
    {
        if ($this->aiRegistry === null) {
            $this->aiRegistry = ObjectManager::getInstance(AIComponentRegistry::class);
        }
        return $this->aiRegistry;
    }
    
    /**
     * 获取实体文件管理器（延迟加载）
     */
    private function getEntityFileManager(): EntityFileManager
    {
        if ($this->entityFileManager === null) {
            $this->entityFileManager = ObjectManager::getInstance(EntityFileManager::class);
        }
        return $this->entityFileManager;
    }
    
    /**
     * 获取路径解析器（延迟加载）
     */
    private function getPathResolver(): TemplatePathResolver
    {
        if ($this->pathResolver === null) {
            $this->pathResolver = TemplatePathResolver::getInstance();
        }
        return $this->pathResolver;
    }
    
    /**
     * 获取配置标准化器（延迟加载）
     */
    private function getConfigNormalizer(): LayoutConfigNormalizer
    {
        if ($this->configNormalizer === null) {
            $this->configNormalizer = LayoutConfigNormalizer::getInstance();
        }
        return $this->configNormalizer;
    }
    
    /**
     * 获取组件模型（延迟加载）
     */
    private function getComponentModel(): Component
    {
        if ($this->componentModel === null) {
            $this->componentModel = ObjectManager::getInstance(Component::class);
        }
        return $this->componentModel;
    }
    
    /**
     * 根据组件代码解析组件
     * 
     * 查找优先级：
     * 1. 如果指定了优先模板，先在该模板中查找
     * 2. 在当前模板中查找
     * 3. 在 AI 生成组件中查找
     * 4. 在共享组件中查找
     * 5. 在兼容组件中查找
     * 
     * @param string $code 组件代码（如 header-nav）
     * @param string $styleCode 当前模板代码
     * @param string|null $preferredStyleCode 优先使用的模板代码
     * @return Component|null
     */
    public function resolve(string $code, string $styleCode, ?string $preferredStyleCode = null): ?Component
    {
        // 标准化组件代码
        $normalizedCode = $this->getConfigNormalizer()->normalizeComponentCode($code);
        
        // 生成缓存键
        $cacheKey = "{$normalizedCode}:{$styleCode}:{$preferredStyleCode}";
        if (isset(self::$componentCache[$cacheKey])) {
            return self::$componentCache[$cacheKey];
        }
        
        $component = null;
        
        // 1. 如果指定了优先模板，先在该模板中查找
        if ($preferredStyleCode && $preferredStyleCode !== $styleCode) {
            $component = $this->findInStyle($normalizedCode, $preferredStyleCode);
            if ($component) {
                self::$componentCache[$cacheKey] = $component;
                return $component;
            }
        }
        
        // 2. 在当前模板中查找
        $component = $this->findInStyle($normalizedCode, $styleCode);
        if ($component) {
            self::$componentCache[$cacheKey] = $component;
            return $component;
        }
        
        // 3. 在 AI 生成组件中查找
        $component = $this->findAIComponent($normalizedCode);
        if ($component) {
            self::$componentCache[$cacheKey] = $component;
            return $component;
        }
        
        // 4. 在共享组件中查找
        $component = $this->findInStyle($normalizedCode, TemplatePathResolver::SHARED_STYLE_CODE);
        if ($component) {
            self::$componentCache[$cacheKey] = $component;
            return $component;
        }
        
        // 5. 在兼容组件中查找
        $component = $this->findCompatible($normalizedCode, $styleCode);
        if ($component) {
            self::$componentCache[$cacheKey] = $component;
            return $component;
        }
        
        // 缓存空结果
        self::$componentCache[$cacheKey] = null;
        return null;
    }
    
    /**
     * 在 AI 生成组件中查找
     * 
     * @param string $code 组件代码
     * @return Component|null
     */
    public function findAIComponent(string $code): ?Component
    {
        try {
            $component = $this->getAIRegistry()->getComponent($code);
            
            if ($component) {
                // 确保 AI 组件的实体文件存在
                $this->getEntityFileManager()->ensureEntityFile($component);
                return $component;
            }
        } catch (\Exception $e) {
            // AI 组件查找失败，记录日志但不中断
            error_log("[ComponentResolver] AI component lookup failed for {$code}: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * 检查是否是 AI 组件
     * 
     * @param string $code 组件代码
     * @return bool
     */
    public function isAIComponent(string $code): bool
    {
        return $this->getAIRegistry()->isAIComponent($code);
    }
    
    /**
     * 在指定模板中查找组件
     * 
     * @param string $code 组件代码
     * @param string $styleCode 模板代码
     * @return Component|null
     */
    public function findInStyle(string $code, string $styleCode): ?Component
    {
        // 从数据库查找
        $component = clone $this->getComponentModel();
        $component->clear()
            ->where(Component::fields_CODE, $code)
            ->where(Component::fields_STYLE_CODE, $styleCode)
            ->where(Component::fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        
        if ($component->getId()) {
            return $component;
        }
        
        return null;
    }
    
    /**
     * 查找兼容当前模板的组件
     * 
     * @param string $code 组件代码
     * @param string $styleCode 当前模板代码
     * @return Component|null
     */
    public function findCompatible(string $code, string $styleCode): ?Component
    {
        // 查找所有其他模板中的同名组件
        $component = clone $this->getComponentModel();
        $components = $component->clear()
            ->where(Component::fields_CODE, $code)
            ->where(Component::fields_STYLE_CODE, '!=', $styleCode)
            ->where(Component::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();
        
        // 检查兼容性
        foreach ($components as $comp) {
            if ($comp->isCompatibleWith($styleCode)) {
                return $comp;
            }
        }
        
        return null;
    }
    
    /**
     * 获取组件的模板文件路径
     * 
     * @param Component $component 组件对象
     * @return string|null 模板引用路径（用于框架模板加载）
     */
    public function getComponentTemplatePath(Component $component): ?string
    {
        $styleCode = $component->getData(Component::fields_STYLE_CODE);
        $path = $component->getData(Component::fields_PATH);
        
        // 对于 AI 组件，确保实体文件存在并返回正确的路径
        if ($component->isAIGenerated()) {
            try {
                $this->getEntityFileManager()->ensureEntityFile($component);
                $path = $component->getData(Component::fields_PATH);
            } catch (\Exception $e) {
                error_log("[ComponentResolver] Failed to ensure AI component entity file: " . $e->getMessage());
            }
        }
        
        if (empty($path)) {
            return null;
        }
        
        // 如果是相对路径，转换为模板引用
        if (strpos($path, 'style/') === 0) {
            return "GuoLaiRen_PageBuilder::templates/{$path}";
        }
        
        // 构建完整的模板引用路径
        return $this->getPathResolver()->getComponentTemplateReference($styleCode, $this->extractFileFromPath($path));
    }
    
    /**
     * 获取组件的绝对文件路径
     * 
     * @param Component $component 组件对象
     * @return string|null 绝对文件路径
     */
    public function getComponentFilePath(Component $component): ?string
    {
        $styleCode = $component->getData(Component::fields_STYLE_CODE);
        $path = $component->getData(Component::fields_PATH);
        
        if (empty($path)) {
            return null;
        }
        
        // 提取文件路径
        $file = $this->extractFileFromPath($path);
        
        return $this->getPathResolver()->getComponentFilePath($styleCode, $file);
    }
    
    /**
     * 从组件路径中提取文件名
     * 
     * @param string $path 路径（可能是 style/xxx/components/header/nav.phtml 或 header/nav.phtml）
     * @return string 文件路径（如 header/nav.phtml）
     */
    private function extractFileFromPath(string $path): string
    {
        // 如果包含 components/，提取之后的部分
        if (preg_match('#components/(.+)$#', $path, $matches)) {
            return $matches[1];
        }
        
        return $path;
    }
    
    /**
     * 从 component.json 获取组件文件映射
     * 
     * @param string $styleCode 模板代码
     * @return array<string, string> [code => file] 映射
     */
    public function getComponentFilesMap(string $styleCode): array
    {
        if (isset(self::$componentFilesCache[$styleCode])) {
            return self::$componentFilesCache[$styleCode];
        }
        
        $componentJsonPath = $this->getPathResolver()->getComponentJsonPath($styleCode);
        
        if (!file_exists($componentJsonPath)) {
            self::$componentFilesCache[$styleCode] = [];
            return [];
        }
        
        $jsonContent = file_get_contents($componentJsonPath);
        $jsonConfig = json_decode($jsonContent, true);
        
        if (!$jsonConfig || !isset($jsonConfig['components'])) {
            self::$componentFilesCache[$styleCode] = [];
            return [];
        }
        
        $map = [];
        foreach ($jsonConfig['components'] as $code => $config) {
            $map[$code] = $config['file'] ?? ($code . '.phtml');
        }
        
        self::$componentFilesCache[$styleCode] = $map;
        return $map;
    }
    
    /**
     * 根据组件代码获取组件文件路径（从 component.json）
     * 
     * @param string $code 组件代码
     * @param string $styleCode 模板代码
     * @return string|null 组件文件路径
     */
    public function resolveComponentFile(string $code, string $styleCode): ?string
    {
        // 标准化组件代码
        $normalizedCode = $this->getConfigNormalizer()->normalizeComponentCode($code);
        
        // 从 component.json 映射中查找
        $filesMap = $this->getComponentFilesMap($styleCode);
        
        // 直接匹配
        if (isset($filesMap[$normalizedCode])) {
            return $this->getPathResolver()->getComponentFilePath($styleCode, $filesMap[$normalizedCode]);
        }
        
        // 尝试各种转换格式
        $alternativeCodes = $this->generateAlternativeCodes($code, $styleCode);
        foreach ($alternativeCodes as $altCode) {
            if (isset($filesMap[$altCode])) {
                return $this->getPathResolver()->getComponentFilePath($styleCode, $filesMap[$altCode]);
            }
        }
        
        return null;
    }
    
    /**
     * 生成组件代码的各种可能格式
     * 
     * @param string $code 原始代码
     * @param string $styleCode 模板代码
     * @return array 可能的代码格式
     */
    private function generateAlternativeCodes(string $code, string $styleCode): array
    {
        $codes = [];
        
        // 如果有模板前缀，尝试移除
        if (strpos($code, $styleCode . '_') === 0) {
            $withoutPrefix = substr($code, strlen($styleCode) + 1);
            $codes[] = str_replace('_', '-', $withoutPrefix);
        }
        
        if (strpos($code, $styleCode . '-') === 0) {
            $codes[] = substr($code, strlen($styleCode) + 1);
        }
        
        // 下划线转破折号
        if (strpos($code, '_') !== false) {
            $codes[] = str_replace('_', '-', $code);
        }
        
        // 特殊处理：{styleCode}_header_header -> header-nav
        if (preg_match('/^' . preg_quote($styleCode, '/') . '_header_header$/i', $code)) {
            $codes[] = 'header-nav';
        }
        
        // 特殊处理：{styleCode}_footer_footer -> footer-links
        if (preg_match('/^' . preg_quote($styleCode, '/') . '_footer_(footer|links)$/i', $code)) {
            $codes[] = 'footer-links';
        }
        
        return $codes;
    }
    
    /**
     * 获取组件的模板引用路径（用于框架 fetch）
     * 
     * @param string $code 组件代码
     * @param string $styleCode 模板代码
     * @param string|null $preferredStyleCode 优先模板代码
     * @return string|null 模板引用路径
     */
    public function resolveComponentTemplateReference(string $code, string $styleCode, ?string $preferredStyleCode = null): ?string
    {
        // 优先从数据库解析
        $component = $this->resolve($code, $styleCode, $preferredStyleCode);
        if ($component) {
            return $this->getComponentTemplatePath($component);
        }
        
        // 回退到 component.json 解析
        $filePath = $this->resolveComponentFile($code, $styleCode);
        if ($filePath && file_exists($filePath)) {
            // 从绝对路径提取相对路径
            $relativePath = $this->extractFileFromPath($filePath);
            return $this->getPathResolver()->getComponentTemplateReference($styleCode, $relativePath);
        }
        
        // 如果有优先模板，尝试从那里查找
        if ($preferredStyleCode && $preferredStyleCode !== $styleCode) {
            $filePath = $this->resolveComponentFile($code, $preferredStyleCode);
            if ($filePath && file_exists($filePath)) {
                $relativePath = $this->extractFileFromPath($filePath);
                return $this->getPathResolver()->getComponentTemplateReference($preferredStyleCode, $relativePath);
            }
        }
        
        return null;
    }
    
    /**
     * 检查组件是否存在
     * 
     * @param string $code 组件代码
     * @param string $styleCode 模板代码
     * @return bool
     */
    public function componentExists(string $code, string $styleCode): bool
    {
        return $this->resolve($code, $styleCode) !== null;
    }
    
    /**
     * 获取组件的默认配置
     * 
     * @param string $code 组件代码
     * @param string $styleCode 模板代码
     * @return array
     */
    public function getDefaultConfig(string $code, string $styleCode): array
    {
        $component = $this->resolve($code, $styleCode);
        if ($component) {
            return $component->getDefaultConfig();
        }
        
        // 从 component.json 获取
        $componentJsonPath = $this->getPathResolver()->getComponentJsonPath($styleCode);
        if (!file_exists($componentJsonPath)) {
            return [];
        }
        
        $jsonConfig = json_decode(file_get_contents($componentJsonPath), true);
        $normalizedCode = $this->getConfigNormalizer()->normalizeComponentCode($code);
        
        return $jsonConfig['components'][$normalizedCode]['default_config'] ?? [];
    }
    
    /**
     * 获取组件的配置 schema
     * 
     * @param string $code 组件代码
     * @param string $styleCode 模板代码
     * @return array
     */
    public function getConfigSchema(string $code, string $styleCode): array
    {
        $component = $this->resolve($code, $styleCode);
        if ($component) {
            return $component->getConfigSchema();
        }
        
        // 从 component.json 获取
        $componentJsonPath = $this->getPathResolver()->getComponentJsonPath($styleCode);
        if (!file_exists($componentJsonPath)) {
            return [];
        }
        
        $jsonConfig = json_decode(file_get_contents($componentJsonPath), true);
        $normalizedCode = $this->getConfigNormalizer()->normalizeComponentCode($code);
        
        return $jsonConfig['components'][$normalizedCode]['config_schema'] ?? [];
    }
    
    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        self::$componentCache = [];
        self::$componentFilesCache = [];
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
