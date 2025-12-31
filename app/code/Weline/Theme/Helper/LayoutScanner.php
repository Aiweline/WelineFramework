<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\App\Env;
use Weline\Theme\Model\WelineTheme;

/**
 * 布局扫描器
 * 扫描主题中可用的布局选项
 */
class LayoutScanner
{
    /**
     * 扫描主题中可用的布局（包含meta信息）
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @param bool $includeDefault 是否包含默认主题的布局（当主题没有继承时）
     * @return array 布局选项数组，格式：['layout_type' => [['value' => 'option1', 'meta' => [...], 'file' => '...'], ...]]
     */
    public static function scanLayouts(WelineTheme $theme, string $area = 'frontend', bool $includeDefault = true): array
    {
        $layouts = [];
        $area = strtolower($area);
        
        // 1. 扫描当前主题的布局
        $themePath = $theme->getPath();
        if (!empty($themePath) && is_dir($themePath)) {
            $layoutsDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'layouts';
            
            // 如果上面的路径不存在，尝试 theme/{area}/layouts/（主题可能在 app/design 目录）
            if (!is_dir($layoutsDir)) {
                $layoutsDir = rtrim($themePath, DS) . DS . 'theme' . DS . $area . DS . 'layouts';
            }
            
            if (is_dir($layoutsDir)) {
                $layouts = self::scanLayoutsFromDir($layoutsDir, $area);
            }
        }
        
        // 2. 如果主题有继承，合并父主题的布局
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentLayouts = self::scanLayouts($parentTheme, $area, false);
            $layouts = self::mergeLayouts($layouts, $parentLayouts);
        }
        
        // 3. 如果主题没有继承，且includeDefault为true，添加默认主题的布局
        if (!$parentTheme && $includeDefault) {
            $defaultLayouts = self::scanDefaultThemeLayouts($area);
            $layouts = self::mergeLayouts($layouts, $defaultLayouts);
        }
        
        // 排序选项
        foreach ($layouts as $type => $options) {
            usort($layouts[$type], function($a, $b) {
                $nameA = $a['meta']['name'] ?? $a['value'];
                $nameB = $b['meta']['name'] ?? $b['value'];
                return strcmp($nameA, $nameB);
            });
        }
        
        return $layouts;
    }
    
    /**
     * 从目录扫描布局文件
     * 
     * @param string $layoutsDir 布局目录路径
     * @param string $area 区域
     * @return array 布局数组
     */
    private static function scanLayoutsFromDir(string $layoutsDir, string $area): array
    {
        $layouts = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($layoutsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'phtml') {
                $filePath = $file->getPathname();
                $relativePath = str_replace($layoutsDir . DS, '', $filePath);
                $relativePath = str_replace(DS, '/', $relativePath);
                
                // 提取布局类型和选项
                $pathParts = explode('/', $relativePath);
                $fileName = basename($relativePath, '.phtml');
                
                if (count($pathParts) === 1) {
                    // 顶级布局：default.phtml
                    $layoutType = 'default';
                    $option = $fileName;
                } else {
                    // 子目录布局：account/auth.phtml
                    $layoutType = $pathParts[0];
                    $option = $fileName;
                }
                
                // 提取meta信息
                $meta = self::extractLayoutMeta($filePath, $area);
                
                if (!isset($layouts[$layoutType])) {
                    $layouts[$layoutType] = [];
                }
                
                // 检查是否已存在相同的选项，如果存在则覆盖（当前主题优先）
                $exists = false;
                foreach ($layouts[$layoutType] as $index => $item) {
                    if ($item['value'] === $option) {
                        $layouts[$layoutType][$index] = [
                            'value' => $option,
                            'meta' => $meta,
                            'file' => $relativePath
                        ];
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $layouts[$layoutType][] = [
                        'value' => $option,
                        'meta' => $meta,
                        'file' => $relativePath
                    ];
                }
            }
        }
        
        return $layouts;
    }
    
    /**
     * 扫描默认主题的布局
     * 
     * @param string $area 区域
     * @return array 布局数组
     */
    private static function scanDefaultThemeLayouts(string $area): array
    {
        $layouts = [];
        $modules = Env::getInstance()->getModuleList();
        
        if (!isset($modules['Weline_Theme'])) {
            return $layouts;
        }
        
        $themeModule = $modules['Weline_Theme'];
        $defaultLayoutsDir = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'layouts';
        
        if (is_dir($defaultLayoutsDir)) {
            $layouts = self::scanLayoutsFromDir($defaultLayoutsDir, $area);
        }
        
        return $layouts;
    }
    
    /**
     * 合并布局数组（当前主题优先）
     * 
     * @param array $current 当前布局
     * @param array $parent 父布局
     * @return array 合并后的布局
     */
    private static function mergeLayouts(array $current, array $parent): array
    {
        foreach ($parent as $type => $options) {
            if (!isset($current[$type])) {
                $current[$type] = [];
            }
            
            foreach ($options as $option) {
                // 检查是否已存在相同的选项
                $exists = false;
                foreach ($current[$type] as $item) {
                    if ($item['value'] === $option['value']) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $current[$type][] = $option;
                }
            }
        }
        
        return $current;
    }
    
    /**
     * 提取布局文件的meta信息
     * 
     * @param string $filePath 文件路径
     * @param string $area 区域
     * @return array meta信息数组
     */
    public static function extractLayoutMeta(string $filePath, string $area): array
    {
        $meta = [
            'name' => '',
            'description' => '',
            'icon' => '',
            'version' => '',
            'author' => '',
            'preview_url' => '',
            'params' => []
        ];
        
        if (!is_file($filePath)) {
            return $meta;
        }
        
        $content = file_get_contents($filePath);
        
        // 提取 @meta.name 格式的meta信息（新格式，支持CSS和PHTML）
        if (preg_match('/@meta\.name\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['name'] = trim($matches[1]);
        } elseif (preg_match('/@meta\.name\s*\{[^}]*name=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['name'] = trim($matches[1]);
        }

        // 提取 @meta.preview_url 格式的meta信息
        if (preg_match('/@meta\.preview_url\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['preview_url'] = trim($matches[1]);
        } elseif (preg_match('/@meta\.preview_url\s*\{[^}]*name=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['preview_url'] = trim($matches[1]);
        }
        
        // 提取 @meta.description 格式的meta信息
        if (preg_match('/@meta\.description\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['description'] = trim($matches[1]);
        } elseif (preg_match('/@meta\.description\s*\{[^}]*name=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['description'] = trim($matches[1]);
        }
        
        // 提取 @meta::info 格式的meta信息（兼容旧格式）
        if (preg_match_all('/@meta::info\s+(\w+)\s+(.+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = trim($match[1]);
                $value = trim($match[2]);
                
                // 处理 "名称：值" 或 "描述：值" 格式
                if (preg_match('/^[^：:]+[：:]\s*(.+)$/u', $value, $valueMatch)) {
                    $value = trim($valueMatch[1]);
                }
                
                if (isset($meta[$key])) {
                    $meta[$key] = $value;
                }
            }
        }
        
        // 提取 @info 格式的meta信息（兼容旧格式）
        if (preg_match_all('/@info\s+(\w+)\s+(.+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = trim($match[1]);
                $value = trim($match[2]);
                
                // 处理 "名称：值" 或 "描述：值" 格式
                if (preg_match('/^[^：:]+[：:]\s*(.+)$/u', $value, $valueMatch)) {
                    $value = trim($valueMatch[1]);
                }
                
                if (isset($meta[$key])) {
                    $meta[$key] = $value;
                }
            }
        }
        
        // 如果没有提取到名称，使用文件名
        if (empty($meta['name'])) {
            $fileName = basename($filePath, '.phtml');
            $meta['name'] = ucfirst($fileName);
        }
        
        return $meta;
    }
    
    /**
     * 扫描 Header 选项
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return array Header 选项数组
     */
    public static function scanHeaders(WelineTheme $theme, string $area = 'frontend'): array
    {
        $headers = [];
        $area = strtolower($area);
        
        // 获取主题路径
        $themePath = $theme->getPath();
        if (empty($themePath) || !is_dir($themePath)) {
            return $headers;
        }
        
        // 扫描 Header 目录：view/theme/{area}/partials/header/
        $headersDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'partials' . DS . 'header';
        
        // 如果上面的路径不存在，尝试 theme/{area}/partials/header/（主题可能在 app/design 目录）
        if (!is_dir($headersDir)) {
            $headersDir = rtrim($themePath, DS) . DS . 'theme' . DS . $area . DS . 'partials' . DS . 'header';
        }
        
        // 如果当前主题没有 Header，尝试从父主题继承
        if (!is_dir($headersDir)) {
            $parentTheme = $theme->getParentTheme();
            if ($parentTheme) {
                return self::scanHeaders($parentTheme, $area);
            }
            return $headers;
        }
        
        // 扫描 Header 文件
        if (is_dir($headersDir)) {
            $files = glob($headersDir . DS . '*.phtml');
            foreach ($files as $file) {
                $fileName = basename($file, '.phtml');
                if (!in_array($fileName, $headers)) {
                    $headers[] = $fileName;
                }
            }
        }
        
        // 合并父主题的 Header
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentHeaders = self::scanHeaders($parentTheme, $area);
            foreach ($parentHeaders as $header) {
                if (!in_array($header, $headers)) {
                    $headers[] = $header;
                }
            }
        }
        
        sort($headers);
        return $headers;
    }
    
    /**
     * 获取主题的布局配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return array 布局配置数组
     */
    public static function getLayoutConfig(WelineTheme $theme, string $area = 'frontend', ?string $scope = null): array
    {
        $area = strtolower($area);
        $config = ConfigLoader::loadConfig($theme, 'layouts', $area, $scope);
        return is_array($config) ? $config : [];
    }
    
    /**
     * 获取主题的 Header 配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return string Header 配置值
     */
    public static function getHeaderConfig(WelineTheme $theme, string $area = 'frontend', ?string $scope = null): string
    {
        $area = strtolower($area);
        return ConfigLoader::getHeaderConfig($theme, $area, $scope);
    }
    
    /**
     * 获取主题的色系配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return string 色系配置值
     */
    public static function getColorConfig(WelineTheme $theme, string $area = 'frontend', ?string $scope = null): string
    {
        $area = strtolower($area);
        return ConfigLoader::getColorConfig($theme, $area, $scope);
    }
    
    /**
     * 获取主题的部件配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return array 部件配置数组
     */
    public static function getPartialsConfig(WelineTheme $theme, string $area = 'frontend', ?string $scope = null): array
    {
        $area = strtolower($area);
        $config = ConfigLoader::loadConfig($theme, 'partials', $area, $scope);
        return is_array($config) ? $config : [];
    }
    
    /**
     * 获取主题的变量配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return array 变量配置数组
     */
    public static function getVariablesConfig(WelineTheme $theme, string $area = 'frontend', ?string $scope = null): array
    {
        $area = strtolower($area);
        return ConfigLoader::getVariablesConfig($theme, $area, $scope);
    }
    
    /**
     * 扫描主题中可用的色系（颜色主题）
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return array 色系选项数组，格式：[['value' => 'light', 'meta' => [...], 'file' => '...'], ...]
     */
    public static function scanColors(WelineTheme $theme, string $area = 'frontend'): array
    {
        $colors = [];
        $area = strtolower($area);
        
        // 1. 扫描当前主题的色系
        $themePath = $theme->getPath();
        if (!empty($themePath) && is_dir($themePath)) {
            $colorsDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'colors';
            
            if (!is_dir($colorsDir)) {
                $colorsDir = rtrim($themePath, DS) . DS . 'theme' . DS . $area . DS . 'colors';
            }
            
            if (is_dir($colorsDir)) {
                $colorFiles = glob($colorsDir . DS . '_*.css');
                foreach ($colorFiles as $file) {
                    $basename = basename($file, '.css');
                    if (strpos($basename, '_') === 0) {
                        $colorName = substr($basename, 1);
                        if (!empty($colorName)) {
                            $meta = self::extractColorMeta($file, $colorName);
                            $colors[] = [
                                'value' => $colorName,
                                'meta' => $meta,
                                'file' => basename($file)
                            ];
                        }
                    }
                }
            }
        }
        
        // 2. 如果主题有继承，合并父主题的色系
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentColors = self::scanColors($parentTheme, $area);
            foreach ($parentColors as $color) {
                $exists = false;
                foreach ($colors as $c) {
                    if ($c['value'] === $color['value']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $colors[] = $color;
                }
            }
        }
        
        // 3. 如果主题没有继承，添加默认主题的色系
        if (!$parentTheme) {
            $defaultColors = self::scanDefaultThemeColors($area);
            foreach ($defaultColors as $color) {
                $exists = false;
                foreach ($colors as $c) {
                    if ($c['value'] === $color['value']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $colors[] = $color;
                }
            }
        }
        
        // 排序
        usort($colors, function($a, $b) {
            $nameA = $a['meta']['name'] ?? $a['value'];
            $nameB = $b['meta']['name'] ?? $b['value'];
            return strcmp($nameA, $nameB);
        });
        
        return $colors;
    }
    
    /**
     * 扫描默认主题的色系
     * 
     * @param string $area 区域
     * @return array 色系数组
     */
    private static function scanDefaultThemeColors(string $area): array
    {
        $colors = [];
        $modules = Env::getInstance()->getModuleList();
        
        if (!isset($modules['Weline_Theme'])) {
            return $colors;
        }
        
        $themeModule = $modules['Weline_Theme'];
        $defaultColorsDir = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'colors';
        
        if (is_dir($defaultColorsDir)) {
            $colorFiles = glob($defaultColorsDir . DS . '_*.css');
            foreach ($colorFiles as $file) {
                $basename = basename($file, '.css');
                if (strpos($basename, '_') === 0) {
                    $colorName = substr($basename, 1);
                    if (!empty($colorName)) {
                        $meta = self::extractColorMeta($file, $colorName);
                        $colors[] = [
                            'value' => $colorName,
                            'meta' => $meta,
                            'file' => basename($file)
                        ];
                    }
                }
            }
        }
        
        return $colors;
    }
    
    /**
     * 提取色系文件的meta信息
     * 
     * @param string $filePath 文件路径
     * @param string $colorName 色系名称
     * @return array meta信息数组
     */
    private static function extractColorMeta(string $filePath, string $colorName): array
    {
        $meta = [
            'name' => ucfirst($colorName),
            'description' => '',
            'theme' => $colorName
        ];
        
        if (!is_file($filePath)) {
            return $meta;
        }
        
        $content = file_get_contents($filePath);
        
        // 提取 @meta.name 格式的meta信息
        if (preg_match('/@meta\.name\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['name'] = trim($matches[1]);
        } elseif (preg_match('/@meta\.name\s*\{[^}]*name=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['name'] = trim($matches[1]);
        }
        
        // 提取 @meta.description 格式的meta信息
        if (preg_match('/@meta\.description\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['description'] = trim($matches[1]);
        } elseif (preg_match('/@meta\.description\s*\{[^}]*name=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['description'] = trim($matches[1]);
        }
        
        // 提取 @meta.theme 格式的meta信息
        if (preg_match('/@meta\.theme\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['theme'] = trim($matches[1]);
        }
        
        // 如果没有提取到名称，使用文件名（首字母大写）
        if (empty($meta['name']) || $meta['name'] === ucfirst($colorName)) {
            $meta['name'] = ucfirst($colorName);
        }
        
        // 如果没有提取到描述，尝试从注释中提取
        if (empty($meta['description'])) {
            // 尝试提取注释块的第一行描述
            if (preg_match('/\/\*\*\s*\n\s*\*\s*(.+?)\s*\n/', $content, $matches)) {
                $meta['description'] = trim($matches[1]);
            }
        }

        // 提取参数定义（使用 ComponentMetaParser，保证与 Meta 配置一致）
        try {
            $parsedMeta = ComponentMetaParser::parse($filePath);
            if (!empty($parsedMeta['params'])) {
                $meta['params'] = self::formatParsedParams($parsedMeta['params']);
            } else {
                $meta['params'] = [];
            }
        } catch (\Throwable $throwable) {
            $meta['params'] = [];
        }
        
        return $meta;
    }

    /**
     * 将 ComponentMetaParser 的参数定义转换为布局配置所需结构
     */
    private static function formatParsedParams(array $parsedParams): array
    {
        $params = [];
        foreach ($parsedParams as $param) {
            $key = $param['name'] ?? null;
            if (!$key) {
                continue;
            }
            $params[$key] = [
                'name' => $param['name_label'] ?? $key,
                'description' => $param['description'] ?? '',
                'default' => $param['default'] ?? '',
                'type' => $param['type'] ?? 'text',
                'required' => (bool)($param['required'] ?? false),
                'options' => $param['options'] ?? null,
            ];
        }
        return $params;
    }
    
    /**
     * 扫描主题中可用的部件（partials）
     * 扩展scanHeaders方法，扫描所有partials类型
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return array 部件选项数组，格式：['partial_type' => [['value' => 'option1', 'meta' => [...], 'file' => '...'], ...]]
     */
    public static function scanPartials(WelineTheme $theme, string $area = 'frontend'): array
    {
        $partials = [];
        $area = strtolower($area);
        
        // 1. 扫描当前主题的部件
        $themePath = $theme->getPath();
        if (!empty($themePath) && is_dir($themePath)) {
            $partialsDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'partials';
            
            if (!is_dir($partialsDir)) {
                $partialsDir = rtrim($themePath, DS) . DS . 'theme' . DS . $area . DS . 'partials';
            }
            
            if (is_dir($partialsDir)) {
                $partials = self::scanPartialsFromDir($partialsDir, $area);
            }
        }
        
        // 2. 如果主题有继承，合并父主题的部件
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentPartials = self::scanPartials($parentTheme, $area);
            $partials = self::mergePartials($partials, $parentPartials);
        }
        
        // 3. 如果主题没有继承，添加默认主题的部件
        if (!$parentTheme) {
            $defaultPartials = self::scanDefaultThemePartials($area);
            $partials = self::mergePartials($partials, $defaultPartials);
        }
        
        // 排序选项
        foreach ($partials as $type => $options) {
            usort($partials[$type], function($a, $b) {
                $nameA = $a['meta']['name'] ?? $a['value'];
                $nameB = $b['meta']['name'] ?? $b['value'];
                return strcmp($nameA, $nameB);
            });
        }
        
        return $partials;
    }
    
    /**
     * 从目录扫描部件文件
     * 
     * @param string $partialsDir 部件目录路径
     * @param string $area 区域
     * @return array 部件数组
     */
    private static function scanPartialsFromDir(string $partialsDir, string $area): array
    {
        $partials = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($partialsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'phtml') {
                $filePath = $file->getPathname();
                $relativePath = str_replace($partialsDir . DS, '', $filePath);
                $relativePath = str_replace(DS, '/', $relativePath);
                
                // 提取部件类型和选项
                $pathParts = explode('/', $relativePath);
                $fileName = basename($relativePath, '.phtml');
                
                if (count($pathParts) === 1) {
                    // 顶级部件：default.phtml
                    $partialType = 'default';
                    $option = $fileName;
                } else {
                    // 子目录部件：header/default.phtml
                    $partialType = $pathParts[0];
                    $option = $fileName;
                }
                
                // 提取meta信息
                $meta = self::extractLayoutMeta($filePath, $area);
                
                if (!isset($partials[$partialType])) {
                    $partials[$partialType] = [];
                }
                
                // 检查是否已存在相同的选项，如果存在则覆盖（当前主题优先）
                $exists = false;
                foreach ($partials[$partialType] as $index => $item) {
                    if ($item['value'] === $option) {
                        $partials[$partialType][$index] = [
                            'value' => $option,
                            'meta' => $meta,
                            'file' => $relativePath
                        ];
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $partials[$partialType][] = [
                        'value' => $option,
                        'meta' => $meta,
                        'file' => $relativePath
                    ];
                }
            }
        }
        
        return $partials;
    }
    
    /**
     * 扫描默认主题的部件
     * 
     * @param string $area 区域
     * @return array 部件数组
     */
    private static function scanDefaultThemePartials(string $area): array
    {
        $partials = [];
        $modules = Env::getInstance()->getModuleList();
        
        if (!isset($modules['Weline_Theme'])) {
            return $partials;
        }
        
        $themeModule = $modules['Weline_Theme'];
        $defaultPartialsDir = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'partials';
        
        if (is_dir($defaultPartialsDir)) {
            $partials = self::scanPartialsFromDir($defaultPartialsDir, $area);
        }
        
        return $partials;
    }
    
    /**
     * 合并部件数组（当前主题优先）
     * 
     * @param array $current 当前部件
     * @param array $parent 父部件
     * @return array 合并后的部件
     */
    private static function mergePartials(array $current, array $parent): array
    {
        foreach ($parent as $type => $options) {
            if (!isset($current[$type])) {
                $current[$type] = [];
            }
            
            foreach ($options as $option) {
                // 检查是否已存在相同的选项
                $exists = false;
                foreach ($current[$type] as $item) {
                    if ($item['value'] === $option['value']) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $current[$type][] = $option;
                }
            }
        }
        
        return $current;
    }
    
    /**
     * 扫描主题中可用的样式变量文件
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return array 变量文件数组，格式：[['value' => 'colors', 'meta' => [...], 'file' => '...'], ...]
     */
    public static function scanVariables(WelineTheme $theme, string $area = 'frontend'): array
    {
        $variables = [];
        $area = strtolower($area);
        
        // 1. 扫描当前主题的变量文件
        $themePath = $theme->getPath();
        if (!empty($themePath) && is_dir($themePath)) {
            $variablesDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'variables';
            
            if (!is_dir($variablesDir)) {
                $variablesDir = rtrim($themePath, DS) . DS . 'theme' . DS . $area . DS . 'variables';
            }
            
            if (is_dir($variablesDir)) {
                $variableFiles = glob($variablesDir . DS . '_*.css');
                foreach ($variableFiles as $file) {
                    $basename = basename($file, '.css');
                    if (strpos($basename, '_') === 0) {
                        $varName = substr($basename, 1);
                        if (!empty($varName)) {
                            $meta = self::extractVariableMeta($file, $varName);
                            $variables[] = [
                                'value' => $varName,
                                'meta' => $meta,
                                'file' => basename($file)
                            ];
                        }
                    }
                }
            }
        }
        
        // 2. 如果主题有继承，合并父主题的变量文件
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentVariables = self::scanVariables($parentTheme, $area);
            foreach ($parentVariables as $var) {
                $exists = false;
                foreach ($variables as $v) {
                    if ($v['value'] === $var['value']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $variables[] = $var;
                }
            }
        }
        
        // 3. 如果主题没有继承，添加默认主题的变量文件
        if (!$parentTheme) {
            $defaultVariables = self::scanDefaultThemeVariables($area);
            foreach ($defaultVariables as $var) {
                $exists = false;
                foreach ($variables as $v) {
                    if ($v['value'] === $var['value']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $variables[] = $var;
                }
            }
        }
        
        // 排序
        usort($variables, function($a, $b) {
            $nameA = $a['meta']['name'] ?? $a['value'];
            $nameB = $b['meta']['name'] ?? $b['value'];
            return strcmp($nameA, $nameB);
        });
        
        return $variables;
    }
    
    /**
     * 扫描默认主题的变量文件
     * 
     * @param string $area 区域
     * @return array 变量文件数组
     */
    private static function scanDefaultThemeVariables(string $area): array
    {
        $variables = [];
        $modules = Env::getInstance()->getModuleList();
        
        if (!isset($modules['Weline_Theme'])) {
            return $variables;
        }
        
        $themeModule = $modules['Weline_Theme'];
        $defaultVariablesDir = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'variables';
        
        if (is_dir($defaultVariablesDir)) {
            $variableFiles = glob($defaultVariablesDir . DS . '_*.css');
            foreach ($variableFiles as $file) {
                $basename = basename($file, '.css');
                if (strpos($basename, '_') === 0) {
                    $varName = substr($basename, 1);
                    if (!empty($varName)) {
                        $meta = self::extractVariableMeta($file, $varName);
                        $variables[] = [
                            'value' => $varName,
                            'meta' => $meta,
                            'file' => basename($file)
                        ];
                    }
                }
            }
        }
        
        return $variables;
    }
    
    /**
     * 提取变量文件的meta信息
     * 
     * @param string $filePath 文件路径
     * @param string $varName 变量名称
     * @return array meta信息数组
     */
    private static function extractVariableMeta(string $filePath, string $varName): array
    {
        $meta = [
            'name' => ucfirst($varName),
            'description' => ''
        ];
        
        if (!is_file($filePath)) {
            return $meta;
        }
        
        $content = file_get_contents($filePath);
        
        // 提取 @meta.name 格式的meta信息
        if (preg_match('/@meta\.name\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['name'] = trim($matches[1]);
        } elseif (preg_match('/@meta\.name\s*\{[^}]*name=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['name'] = trim($matches[1]);
        }
        
        // 提取 @meta.description 格式的meta信息
        if (preg_match('/@meta\.description\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['description'] = trim($matches[1]);
        } elseif (preg_match('/@meta\.description\s*\{[^}]*name=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['description'] = trim($matches[1]);
        }
        
        // 如果没有提取到名称，使用默认映射
        if (empty($meta['name']) || $meta['name'] === ucfirst($varName)) {
            $nameMap = [
                'colors' => '颜色变量',
                'spacing' => '间距变量',
                'typography' => '字体变量',
                'shadows' => '阴影变量',
                'borders' => '边框变量'
            ];
            
            if (isset($nameMap[$varName])) {
                $meta['name'] = $nameMap[$varName];
            }
        }
        
        // 如果没有提取到描述，尝试从注释中提取
        if (empty($meta['description'])) {
            if (preg_match('/\/\*\*\s*\n\s*\*\s*(.+?)\s*\n/', $content, $matches)) {
                $meta['description'] = trim($matches[1]);
            }
        }
        
        return $meta;
    }
    
    /**
     * 扫描主题中可用的组件
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return array 组件数组，格式：[['value' => 'button', 'meta' => [...], 'file' => '...'], ...]
     */
    public static function scanComponents(WelineTheme $theme, string $area = 'frontend'): array
    {
        $components = [];
        $area = strtolower($area);
        
        // 1. 扫描当前主题的组件
        $themePath = $theme->getPath();
        if (!empty($themePath) && is_dir($themePath)) {
            $componentsDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'components';
            
            if (!is_dir($componentsDir)) {
                $componentsDir = rtrim($themePath, DS) . DS . 'theme' . DS . $area . DS . 'components';
            }
            
            if (is_dir($componentsDir)) {
                $componentFiles = glob($componentsDir . DS . '*.phtml');
                foreach ($componentFiles as $file) {
                    $componentName = basename($file, '.phtml');
                    $meta = self::extractLayoutMeta($file, $area);
                    $components[] = [
                        'value' => $componentName,
                        'meta' => $meta,
                        'file' => basename($file)
                    ];
                }
            }
        }
        
        // 2. 如果主题有继承，合并父主题的组件
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentComponents = self::scanComponents($parentTheme, $area);
            foreach ($parentComponents as $component) {
                $exists = false;
                foreach ($components as $c) {
                    if ($c['value'] === $component['value']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $components[] = $component;
                }
            }
        }
        
        // 3. 如果主题没有继承，添加默认主题的组件
        if (!$parentTheme) {
            $defaultComponents = self::scanDefaultThemeComponents($area);
            foreach ($defaultComponents as $component) {
                $exists = false;
                foreach ($components as $c) {
                    if ($c['value'] === $component['value']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $components[] = $component;
                }
            }
        }
        
        // 排序
        usort($components, function($a, $b) {
            $nameA = $a['meta']['name'] ?? $a['value'];
            $nameB = $b['meta']['name'] ?? $b['value'];
            return strcmp($nameA, $nameB);
        });
        
        return $components;
    }
    
    /**
     * 扫描默认主题的组件
     * 
     * @param string $area 区域
     * @return array 组件数组
     */
    private static function scanDefaultThemeComponents(string $area): array
    {
        $components = [];
        $modules = Env::getInstance()->getModuleList();
        
        if (!isset($modules['Weline_Theme'])) {
            return $components;
        }
        
        $themeModule = $modules['Weline_Theme'];
        $defaultComponentsDir = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'components';
        
        if (is_dir($defaultComponentsDir)) {
            $componentFiles = glob($defaultComponentsDir . DS . '*.phtml');
            foreach ($componentFiles as $file) {
                $componentName = basename($file, '.phtml');
                $meta = self::extractLayoutMeta($file, $area);
                $components[] = [
                    'value' => $componentName,
                    'meta' => $meta,
                    'file' => basename($file)
                ];
            }
        }
        
        return $components;
    }
}

