<?php

declare(strict_types=1);

/**
 * 模板路径解析服务
 * 
 * 集中管理所有模板相关的路径，消除硬编码
 * 
 * @author GuoLaiRen
 * @since 1.0.0
 */

namespace GuoLaiRen\PageBuilder\Service\Template;

class TemplatePathResolver
{
    /**
     * 模板基础路径（相对于 BP）
     */
    private const BASE_PATH = 'app/code/GuoLaiRen/PageBuilder/view/templates/style';
    
    /**
     * 共享组件的模板代码
     */
    public const SHARED_STYLE_CODE = '_shared';
    
    /**
     * 模板引用前缀（用于框架模板加载）
     */
    private const TEMPLATE_REFERENCE_PREFIX = 'GuoLaiRen_PageBuilder::templates/style';
    
    /**
     * 路径缓存
     */
    private static array $pathCache = [];
    
    /**
     * 单例实例
     */
    private static ?self $instance = null;
    
    /**
     * 获取模板基础路径（绝对路径）
     */
    public function getBasePath(): string
    {
        return BP . self::BASE_PATH;
    }
    
    /**
     * 获取模板目录路径
     * 
     * @param string $styleCode 模板代码
     * @return string 绝对路径
     */
    public function getTemplatePath(string $styleCode): string
    {
        $cacheKey = "template:{$styleCode}";
        if (!isset(self::$pathCache[$cacheKey])) {
            self::$pathCache[$cacheKey] = $this->getBasePath() . '/' . $styleCode;
        }
        return self::$pathCache[$cacheKey];
    }
    
    /**
     * 获取模板元数据配置文件路径 (template.json)
     * 
     * @param string $styleCode 模板代码
     * @return string 绝对路径
     */
    public function getTemplateJsonPath(string $styleCode): string
    {
        return $this->getTemplatePath($styleCode) . '/template.json';
    }
    
    /**
     * 获取组件配置文件路径 (component.json)
     * 
     * @param string $styleCode 模板代码
     * @return string 绝对路径
     */
    public function getComponentJsonPath(string $styleCode): string
    {
        return $this->getTemplatePath($styleCode) . '/components/component.json';
    }
    
    /**
     * 获取组件目录路径
     * 
     * @param string $styleCode 模板代码
     * @return string 绝对路径
     */
    public function getComponentsPath(string $styleCode): string
    {
        return $this->getTemplatePath($styleCode) . '/components';
    }
    
    /**
     * 获取组件文件路径
     * 
     * @param string $styleCode 模板代码
     * @param string $file 组件文件相对路径（如 header/nav.phtml）
     * @return string 绝对路径
     */
    public function getComponentFilePath(string $styleCode, string $file): string
    {
        return $this->getComponentsPath($styleCode) . '/' . $file;
    }
    
    /**
     * 获取组件文件的模板引用路径（用于框架模板加载）
     * 
     * @param string $styleCode 模板代码
     * @param string $file 组件文件相对路径
     * @return string 模板引用路径
     */
    public function getComponentTemplateReference(string $styleCode, string $file): string
    {
        $fs = $this->resolveComponentFilesystemPath($styleCode, $file);
        $sharedFs = $this->getComponentFilePath(self::SHARED_STYLE_CODE, 'legal-content.phtml');
        if ($fs === $sharedFs && is_file($sharedFs)) {
            return self::TEMPLATE_REFERENCE_PREFIX . '/' . self::SHARED_STYLE_CODE . '/components/legal-content.phtml';
        }

        return self::TEMPLATE_REFERENCE_PREFIX . "/{$styleCode}/components/{$file}";
    }

    /**
     * 解析组件物理路径：当前主题下文件存在则用主题文件；否则 legal-content 回退到 style/_shared/components/legal-content.phtml。
     */
    public function resolveComponentFilesystemPath(string $styleCode, string $file): string
    {
        $primary = $this->getComponentFilePath($styleCode, $file);
        if (is_file($primary)) {
            return $primary;
        }
        $norm = str_replace('\\', '/', $file);
        if (str_ends_with($norm, 'legal-content.phtml')) {
            $shared = $this->getComponentFilePath(self::SHARED_STYLE_CODE, 'legal-content.phtml');
            if (is_file($shared)) {
                return $shared;
            }
        }

        return $primary;
    }
    
    /**
     * 获取布局配置目录路径
     * 
     * @param string $styleCode 模板代码
     * @return string 绝对路径
     */
    public function getLayoutsPath(string $styleCode): string
    {
        return $this->getTemplatePath($styleCode) . '/layouts';
    }
    
    /**
     * 获取默认布局配置目录路径
     * 
     * @param string $styleCode 模板代码
     * @return string 绝对路径
     */
    public function getDefaultLayoutsPath(string $styleCode): string
    {
        return $this->getLayoutsPath($styleCode) . '/default';
    }
    
    /**
     * 获取页面类型的默认布局配置文件路径
     * 
     * @param string $styleCode 模板代码
     * @param string $pageType 页面类型
     * @return string 绝对路径
     */
    public function getLayoutConfigPath(string $styleCode, string $pageType): string
    {
        return $this->getDefaultLayoutsPath($styleCode) . '/' . $pageType . '.json';
    }
    
    /**
     * 获取布局列表配置文件路径 (layouts.json)
     * 
     * @param string $styleCode 模板代码
     * @return string 绝对路径
     */
    public function getLayoutsJsonPath(string $styleCode): string
    {
        return $this->getLayoutsPath($styleCode) . '/layouts.json';
    }
    
    /**
     * 获取颜色主题目录路径
     * 
     * @param string $styleCode 模板代码
     * @return string 绝对路径
     */
    public function getColorsPath(string $styleCode): string
    {
        return $this->getTemplatePath($styleCode) . '/colors';
    }
    
    /**
     * 获取颜色主题文件路径
     * 
     * @param string $styleCode 模板代码
     * @param string $colorScheme 颜色主题名称（如 default, blue）
     * @return string 绝对路径
     */
    public function getColorFilePath(string $styleCode, string $colorScheme = 'default'): string
    {
        return $this->getColorsPath($styleCode) . '/' . $colorScheme . '.phtml';
    }
    
    /**
     * 获取静态资源目录路径
     * 
     * @param string $styleCode 模板代码
     * @return string 绝对路径
     */
    public function getAssetsPath(string $styleCode): string
    {
        return $this->getTemplatePath($styleCode) . '/assets';
    }
    
    /**
     * 获取 CSS 文件路径
     * 
     * @param string $styleCode 模板代码
     * @param string $file CSS 文件名
     * @return string 绝对路径
     */
    public function getCssFilePath(string $styleCode, string $file): string
    {
        return $this->getAssetsPath($styleCode) . '/css/' . $file;
    }
    
    /**
     * 获取静态资源的模板引用路径
     * 
     * @param string $styleCode 模板代码
     * @param string $file 资源文件相对路径（如 css/main.css）
     * @return string 模板引用路径
     */
    public function getAssetTemplateReference(string $styleCode, string $file): string
    {
        return "GuoLaiRen_PageBuilder::style/{$styleCode}/assets/{$file}";
    }
    
    /**
     * 获取传统模板文件路径（header.phtml, footer.phtml, content.phtml, layout.phtml）
     * 
     * @param string $styleCode 模板代码
     * @param string $file 文件名
     * @return string 绝对路径
     */
    public function getLegacyTemplateFilePath(string $styleCode, string $file): string
    {
        return $this->getTemplatePath($styleCode) . '/' . $file;
    }
    
    /**
     * 获取传统模板文件的模板引用路径
     * 
     * @param string $styleCode 模板代码
     * @return string 模板引用路径
     */
    public function getLegacyTemplateReference(string $styleCode): string
    {
        return self::TEMPLATE_REFERENCE_PREFIX . "/{$styleCode}";
    }
    
    /**
     * 验证路径是否存在
     * 
     * @param string $path 绝对路径
     * @return bool
     */
    public function pathExists(string $path): bool
    {
        return file_exists($path);
    }
    
    /**
     * 验证目录是否存在
     * 
     * @param string $path 绝对路径
     * @return bool
     */
    public function directoryExists(string $path): bool
    {
        return is_dir($path);
    }
    
    /**
     * 验证文件是否存在
     * 
     * @param string $path 绝对路径
     * @return bool
     */
    public function fileExists(string $path): bool
    {
        return is_file($path);
    }
    
    /**
     * 获取所有可用的模板代码列表
     * 
     * @param bool $includeShared 是否包含共享模板
     * @return array<string>
     */
    public function getAllStyleCodes(bool $includeShared = false): array
    {
        $basePath = $this->getBasePath();
        if (!is_dir($basePath)) {
            return [];
        }
        
        $styleCodes = [];
        $dirs = scandir($basePath);
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            
            // 跳过非目录
            if (!is_dir($basePath . '/' . $dir)) {
                continue;
            }
            
            // 跳过以下划线开头的目录（除非包含共享模板）
            if (strpos($dir, '_') === 0) {
                if ($includeShared && $dir === self::SHARED_STYLE_CODE) {
                    $styleCodes[] = $dir;
                }
                continue;
            }
            
            $styleCodes[] = $dir;
        }
        
        return $styleCodes;
    }
    
    /**
     * 检查模板是否存在
     * 
     * @param string $styleCode 模板代码
     * @return bool
     */
    public function templateExists(string $styleCode): bool
    {
        return $this->directoryExists($this->getTemplatePath($styleCode));
    }
    
    /**
     * 获取组件路径（数据库中存储的相对路径）
     * 
     * @param string $styleCode 模板代码
     * @param string $file 组件文件相对路径
     * @return string 相对于 templates/ 的路径
     */
    public function getComponentRelativePath(string $styleCode, string $file): string
    {
        return "style/{$styleCode}/components/{$file}";
    }
    
    /**
     * 从组件相对路径解析模板代码和文件路径
     * 
     * @param string $relativePath 相对路径（如 style/tpmst/components/header/nav.phtml）
     * @return array{style_code: string, file: string}|null
     */
    public function parseComponentRelativePath(string $relativePath): ?array
    {
        if (preg_match('#^style/([^/]+)/components/(.+)$#', $relativePath, $matches)) {
            return [
                'style_code' => $matches[1],
                'file' => $matches[2],
            ];
        }
        return null;
    }
    
    /**
     * 清除路径缓存
     */
    public function clearCache(): void
    {
        self::$pathCache = [];
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
