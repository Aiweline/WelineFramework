<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\Interface\ThemeChainResolverInterface;
use Weline\Theme\Helper\Interface\ThemePathResolverInterface;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题路径解析器
 * 
 * 职责：解析主题文件路径，支持多级继承链
 * 遵循：单一职责原则 (SRP)、依赖倒置原则 (DIP)
 */
class ThemePathResolver implements ThemePathResolverInterface
{
    /**
     * @var ThemeChainResolverInterface
     */
    private ThemeChainResolverInterface $themeChainResolver;

    /**
     * 依赖注入：遵循依赖倒置原则 (DIP)
     * 
     * @param ThemeChainResolverInterface $themeChainResolver
     */
    public function __construct(ThemeChainResolverInterface $themeChainResolver)
    {
        $this->themeChainResolver = $themeChainResolver;
    }

    /**
     * 解析主题文件路径（支持多级继承链）
     * 
     * @param string $modulePath 模块文件路径
     * @param WelineTheme $theme 当前主题
     * @return string 解析后的文件路径
     */
    public function resolveThemeFile(string $modulePath, WelineTheme $theme): string
    {
        $visited = [];
        return $this->resolveThemeFileRecursive($modulePath, $theme, $visited);
    }

    /**
     * 递归解析主题文件路径
     * 
     * @param string $modulePath 模块文件路径
     * @param WelineTheme $theme 当前主题
     * @param array $visited 已访问的主题ID（防止循环引用）
     * @return string 解析后的文件路径
     */
    private function resolveThemeFileRecursive(string $modulePath, WelineTheme $theme, array $visited = []): string
    {
        // 防止循环引用
        $themeId = $theme->getId();
        if ($themeId && in_array($themeId, $visited)) {
            return $modulePath;
        }
        if ($themeId) {
            $visited[] = $themeId;
        }
        
        // 1. 先查找模块自己的 theme 目录（模块级主题继承）
        $moduleThemePath = $this->buildModuleThemePath($modulePath);
        if (is_file($moduleThemePath)) {
            return $moduleThemePath;
        }
        
        // 2. 构建当前主题的文件路径
        $themePath = $this->buildThemePath($modulePath, $theme->getPath());
        
        // 如果当前主题中存在该文件，直接返回（同名文件以激活主题为准）
        if (is_file($themePath)) {
            return $themePath;
        }
        
        // 3. 递归查找父主题
        $parentId = $theme->getParentId();
        if ($parentId) {
            try {
                /** @var WelineTheme $parentTheme */
                $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                $parentTheme->load($parentId);
                
                if ($parentTheme->getId()) {
                    return $this->resolveThemeFileRecursive($modulePath, $parentTheme, $visited);
                }
            } catch (\Exception $e) {
                // 如果父主题加载失败，继续查找基础模块文件
            }
        }
        
        // 4. 如果主题没有继承（没有父主题），且是布局或部分文件，尝试使用默认文件
        if (!$parentId && $this->isThemeFile($modulePath)) {
            $defaultThemePath = $this->getDefaultThemePath($modulePath);
            if ($defaultThemePath && is_file($defaultThemePath)) {
                return $defaultThemePath;
            }
        }
        
        // 5. 如果主题继承链中都没有找到，返回基础模块文件
        return $modulePath;
    }

    /**
     * 构建模块级主题文件路径（模块自己的 theme 目录）
     * 
     * @param string $modulePath 模块文件路径
     * @return string 模块主题文件路径
     */
    private function buildModuleThemePath(string $modulePath): string
    {
        // 检查是否是 theme 目录下的文件
        if (strpos($modulePath, DS . 'theme' . DS) === false) {
            return $modulePath;
        }
        
        // 查找 view/theme/ 的位置
        $themePos = strpos($modulePath, DS . 'view' . DS . 'theme' . DS);
        if ($themePos === false) {
            return $modulePath;
        }
        
        // 提取 view/theme/ 之后的部分
        $themeRelativePath = substr($modulePath, $themePos + strlen(DS . 'view' . DS . 'theme' . DS));
        
        // 尝试查找当前请求的模块
        if (!CLI) {
            try {
                $request = ObjectManager::getInstance(Request::class);
                $currentModulePath = $request->getModulePath();
                if ($currentModulePath) {
                    $currentModuleThemePath = rtrim($currentModulePath, DS) . DS . 'view' . DS . 'theme' . DS . $themeRelativePath;
                    if (is_file($currentModuleThemePath)) {
                        return $currentModuleThemePath;
                    }
                }
            } catch (\Throwable $e) {
                // 如果获取 Request 失败，继续后续逻辑
            }
        }
        
        // 如果当前模块没有，尝试查找 Weline_Frontend 模块（前端默认模块）
        $modules = Env::getInstance()->getModuleList();
        if (isset($modules['Weline_Frontend'])) {
            $frontendModule = $modules['Weline_Frontend'];
            $frontendThemePath = rtrim($frontendModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $themeRelativePath;
            if (is_file($frontendThemePath)) {
                return $frontendThemePath;
            }
        }
        
        return $modulePath;
    }

    /**
     * 构建主题文件路径
     * 
     * @param string $modulePath 模块文件路径
     * @param string $themePath 主题路径
     * @return string 主题文件路径
     */
    public function buildThemePath(string $modulePath, string $themePath): string
    {
        // 替换 app/code 为 app/design/{theme}
        $themeFilePath = str_replace(APP_CODE_PATH, $themePath, $modulePath);
        
        // 如果没替换成功，尝试替换 vendor
        if ($themeFilePath === $modulePath) {
            $themeFilePath = str_replace(VENDOR_PATH, $themePath, $modulePath);
        }
        
        return $themeFilePath;
    }

    /**
     * 判断是否是主题文件（布局或部分文件）
     * 
     * @param string $modulePath 模块文件路径
     * @return bool
     */
    private function isThemeFile(string $modulePath): bool
    {
        return (strpos($modulePath, DS . 'theme' . DS . 'frontend' . DS . 'layouts') !== false) ||
               (strpos($modulePath, DS . 'theme' . DS . 'backend' . DS . 'layouts') !== false) ||
               (strpos($modulePath, DS . 'theme' . DS . 'frontend' . DS . 'partials') !== false) ||
               (strpos($modulePath, DS . 'theme' . DS . 'backend' . DS . 'partials') !== false);
    }

    /**
     * 获取默认主题文件路径（从 Weline_Theme 模块）
     * 
     * @param string $modulePath 模块文件路径
     * @return string|null 默认主题文件路径，如果不存在则返回null
     */
    private function getDefaultThemePath(string $modulePath): ?string
    {
        if (!$this->isThemeFile($modulePath)) {
            return null;
        }
        
        $themePos = strpos($modulePath, DS . 'view' . DS . 'theme' . DS);
        if ($themePos === false) {
            return null;
        }
        
        $themeRelativePath = substr($modulePath, $themePos + strlen(DS . 'view' . DS . 'theme' . DS));
        
        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules['Weline_Theme'])) {
            return null;
        }
        
        $themeModule = $modules['Weline_Theme'];
        $defaultThemePath = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $themeRelativePath;
        
        return $defaultThemePath;
    }
}
