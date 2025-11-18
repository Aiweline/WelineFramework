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
 * Partials 扫描器
 * 用于扫描主题中可用的 partials 选项
 */
class PartialsScanner
{
    /**
     * 扫描主题的 partials 选项
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend 或 backend）
     * @return array 返回格式：['header' => ['default', 'minimal', ...], 'footer' => [...], ...]
     */
    public static function scanPartials(WelineTheme $theme, string $area = 'frontend'): array
    {
        $result = [];
        
        // 获取主题路径
        $themePath = $theme->getPath();
        if (empty($themePath)) {
            return $result;
        }
        
        // 构建 partials 目录路径
        $partialsPath = rtrim($themePath, DS) . DS . 'theme' . DS . $area . DS . 'partials';
        
        if (!is_dir($partialsPath)) {
            return $result;
        }
        
        // 扫描 partials 目录下的子目录
        $partialsTypes = ['header', 'footer', 'sidebar', 'breadcrumb', 'pagination'];
        
        foreach ($partialsTypes as $type) {
            $typePath = $partialsPath . DS . $type;
            if (is_dir($typePath)) {
                $options = [];
                // 扫描该类型下的所有 .phtml 文件
                $files = glob($typePath . DS . '*.phtml');
                foreach ($files as $file) {
                    $basename = basename($file, '.phtml');
                    $options[] = $basename;
                }
                if (!empty($options)) {
                    $result[$type] = $options;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * 获取主题的 partials 配置（包括父主题）
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend 或 backend）
     * @return array
     */
    public static function getPartialsConfig(WelineTheme $theme, string $area = 'frontend'): array
    {
        $config = $theme->getConfigValue('partials', []);
        $areaConfig = $config[$area] ?? [];
        
        // 如果当前主题没有配置，尝试从父主题获取
        if (empty($areaConfig)) {
            $parentTheme = $theme->getParentTheme();
            if ($parentTheme) {
                $parentConfig = $parentTheme->getConfigValue('partials', []);
                $areaConfig = $parentConfig[$area] ?? [];
            }
        }
        
        return $areaConfig;
    }
    
    /**
     * 获取 partials 模板路径（支持模块级主题继承）
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend 或 backend）
     * @param string $type partials 类型（header, footer, sidebar 等）
     * @param string $option 选项名称（default, minimal 等）
     * @return string|null 模板路径，如果不存在则返回 null
     */
    public static function getPartialsPath(WelineTheme $theme, string $area, string $type, string $option): ?string
    {
        // 获取配置的选项
        $config = self::getPartialsConfig($theme, $area);
        $selectedOption = $config[$type] ?? 'default';
        
        // 如果请求的选项与配置的不一致，使用配置的选项
        if ($option !== $selectedOption) {
            $option = $selectedOption;
        }
        
        // 1. 先查找模块自己的 theme 目录（模块级主题继承）
        $modulePartialsPath = self::getModulePartialsPath($area, $type, $option);
        if ($modulePartialsPath) {
            return $modulePartialsPath;
        }
        
        // 2. 构建主题路径
        $themePath = $theme->getPath();
        if (empty($themePath)) {
            return null;
        }
        
        $partialsPath = rtrim($themePath, DS) . DS . 'theme' . DS . $area . DS . 'partials' . DS . $type . DS . $option . '.phtml';
        
        // 检查文件是否存在
        if (file_exists($partialsPath)) {
            // 返回相对于主题模块的路径
            $moduleName = $theme->getModuleName();
            
            // 尝试从 app/code 路径解析
            $codePath = BP . DS . 'app' . DS . 'code' . DS . str_replace('_', DS, $moduleName) . DS . 'view' . DS;
            if (strpos($partialsPath, $codePath) === 0) {
                $relativePath = str_replace($codePath, '', $partialsPath);
                $relativePath = str_replace(DS, '/', $relativePath);
                return $moduleName . '::' . $relativePath;
            }
            
            // 尝试从 app/design 路径解析
            $designPath = BP . DS . 'app' . DS . 'design' . DS;
            if (strpos($partialsPath, $designPath) === 0) {
                $relativePath = str_replace($designPath, '', $partialsPath);
                $relativePath = str_replace(DS, '/', $relativePath);
                // app/design 下的主题路径格式：frontend/ThemeName/...
                return 'Weline_Theme::theme/' . $relativePath;
            }
            
            // 如果都不匹配，返回相对路径
            $relativePath = str_replace(BP . DS, '', $partialsPath);
            $relativePath = str_replace(DS, '/', $relativePath);
            return $moduleName . '::' . $relativePath;
        }
        
        // 3. 如果当前主题没有，尝试从父主题获取
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            return self::getPartialsPath($parentTheme, $area, $type, $option);
        }
        
        // 4. 如果主题继承链中都没有找到，尝试查找 Weline_Theme 模块的基础文件
        $baseThemePath = BP . DS . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . $area . DS . 'partials' . DS . $type . DS . $option . '.phtml';
        if (file_exists($baseThemePath)) {
            $relativePath = 'theme/' . $area . '/partials/' . $type . '/' . $option . '.phtml';
            return 'Weline_Theme::' . $relativePath;
        }
        
        return null;
    }
    
    /**
     * 获取模块级 partials 路径（模块自己的 theme 目录）
     * @param string $area 区域（frontend 或 backend）
     * @param string $type partials 类型（header, footer, sidebar 等）
     * @param string $option 选项名称（default, minimal 等）
     * @return string|null 模板路径，如果不存在则返回 null
     */
    private static function getModulePartialsPath(string $area, string $type, string $option): ?string
    {
        $modules = Env::getInstance()->getModuleList();
        
        // 1. 先查找当前请求的模块
        $request = Env::getInstance()->getRequest();
        if ($request) {
            $currentModuleName = $request->getModuleName();
            if ($currentModuleName && isset($modules[$currentModuleName])) {
                $currentModule = $modules[$currentModuleName];
                $modulePartialsPath = rtrim($currentModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'partials' . DS . $type . DS . $option . '.phtml';
                if (file_exists($modulePartialsPath)) {
                    $relativePath = 'theme/' . $area . '/partials/' . $type . '/' . $option . '.phtml';
                    return $currentModuleName . '::' . $relativePath;
                }
            }
        }
        
        // 2. 如果当前模块没有，尝试查找 Weline_Frontend 模块（前端默认模块）
        if ($area === 'frontend' && isset($modules['Weline_Frontend'])) {
            $frontendModule = $modules['Weline_Frontend'];
            $frontendPartialsPath = rtrim($frontendModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'partials' . DS . $type . DS . $option . '.phtml';
            if (file_exists($frontendPartialsPath)) {
                $relativePath = 'theme/' . $area . '/partials/' . $type . '/' . $option . '.phtml';
                return 'Weline_Frontend::' . $relativePath;
            }
        }
        
        // 3. 如果前端模块没有，尝试查找 Weline_Backend 模块（后端默认模块）
        if ($area === 'backend' && isset($modules['Weline_Backend'])) {
            $backendModule = $modules['Weline_Backend'];
            $backendPartialsPath = rtrim($backendModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'partials' . DS . $type . DS . $option . '.phtml';
            if (file_exists($backendPartialsPath)) {
                $relativePath = 'theme/' . $area . '/partials/' . $type . '/' . $option . '.phtml';
                return 'Weline_Backend::' . $relativePath;
            }
        }
        
        return null;
    }
}

