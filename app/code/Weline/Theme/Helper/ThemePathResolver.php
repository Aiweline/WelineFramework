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
        try {
            /** @var \Weline\Theme\Service\ThemeDirectoryResolver $directoryResolver */
            $directoryResolver = ObjectManager::getInstance(\Weline\Theme\Service\ThemeDirectoryResolver::class);
            $resolved = $directoryResolver->resolveThemeTemplatePath($modulePath, $theme);
            // 仅当解析结果与输入不同且文件真实存在时才采用；否则回退到继承链 + buildThemePath
            // （避免 ThemeDirectoryResolver 在部分环境下 is_file 未命中时仍返回原始 Weline_Theme 路径，导致 design 主题布局不生效）
            if ($resolved !== $modulePath && is_file($resolved)) {
                return $resolved;
            }
        } catch (\Throwable $throwable) {
        }

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
        if ($themeId && in_array($themeId, $visited, true)) {
            return $modulePath;
        }
        if ($themeId) {
            $visited[] = $themeId;
        }

        // 1. 当前主题 design（design 为 themePath/frontend/ 或 themePath/backend/，不含 view/theme）
        $themePathBuilt = $this->buildThemePath($modulePath, $theme->getPath());
        if (is_file($themePathBuilt)) {
            return $themePathBuilt;
        }
        // 兼容：请求 .../homepage/default.phtml 时，若主题只有 .../layouts/homepage.phtml 则使用它
        if (str_ends_with($themePathBuilt, DS . 'default.phtml')) {
            $fallback = dirname($themePathBuilt, 2) . DS . basename(dirname($themePathBuilt)) . '.phtml';
            if (is_file($fallback)) {
                return $fallback;
            }
        }

        // 2. 递归查找父主题（design 继承链）
        $parentId = $theme->getParentId();
        if ($parentId) {
            try {
                /** @var WelineTheme $parentTheme */
                $parentTheme = ObjectManager::make(WelineTheme::class);
                $parentTheme->load($parentId);
                
                if ($parentTheme->getId()) {
                    return $this->resolveThemeFileRecursive($modulePath, $parentTheme, $visited);
                }
            } catch (\Exception $e) {
                // 如果父主题加载失败，继续查找基础模块文件
            }
        }

        // 3. 继承链耗尽后回退到“顶层”：当前文件所属模块的 view/theme
        if (!$parentId) {
            $topLayerPath = $this->getTopLayerPath($modulePath);
            if ($topLayerPath && is_file($topLayerPath)) {
                return $topLayerPath;
            }
        }

        // 4. 如果继承链与顶层都没有找到，返回基础模块文件
        return $modulePath;
    }

    /**
     * 构建模块级主题文件路径（模块自己的 theme 目录）
     * 
     * @param string $modulePath 模块文件路径
     * @return string 模块主题文件路径
     */
    /**
     * 构建主题文件路径
     *
     * design 主题目录结构为 themePath/frontend/ 或 themePath/backend/，不包含 view/theme。
     * 从模块路径中提取 theme 之后的 frontend/... 或 backend/...，拼成 themePath + 该相对路径。
     *
     * @param string $modulePath 模块文件路径（如 .../view/theme/frontend/layouts/homepage/default.phtml）
     * @param string $themePath 主题根路径（如 .../app/design/WeShop/motor/）
     * @return string 主题文件路径（如 .../app/design/WeShop/motor/frontend/layouts/homepage/default.phtml）
     */
    public function buildThemePath(string $modulePath, string $themePath): string
    {
        $ds = DS;
        $needle = $ds . 'theme' . $ds;
        $pos = strpos($modulePath, $needle);
        if ($pos !== false) {
            $afterTheme = substr($modulePath, $pos + strlen($needle));
            $themeFilePath = rtrim(str_replace(['/', '\\'], $ds, $themePath), $ds) . $ds . str_replace(['/', '\\'], $ds, $afterTheme);
            return $themeFilePath;
        }
        // 兼容：找不到 theme/ 时按原逻辑替换 app/code
        $themeFilePath = str_replace(APP_CODE_PATH, $themePath, $modulePath);
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
    private function isThemeViewPath(string $modulePath): bool
    {
        $path = str_replace(['/', '\\'], DS, $modulePath);
        return str_contains($path, DS . 'view' . DS . 'theme' . DS . 'frontend' . DS) ||
               str_contains($path, DS . 'view' . DS . 'theme' . DS . 'backend' . DS);
    }

    /**
     * 获取“顶层”回退路径：modulePath 所属模块的 view/theme 同相对路径
     * 
     * @param string $modulePath 模块文件路径
     * @return string|null 顶层路径，不可解析时返回 null
     */
    private function getTopLayerPath(string $modulePath): ?string
    {
        $path = str_replace(['/', '\\'], DS, $modulePath);
        if (!$this->isThemeViewPath($path)) {
            return null;
        }

        $themePos = strpos($path, DS . 'view' . DS . 'theme' . DS);
        if ($themePos === false) {
            return null;
        }

        $themeRelativePath = substr($path, $themePos + strlen(DS . 'view' . DS . 'theme' . DS));

        $modules = Env::getInstance()->getModuleList();
        $ownerBasePath = $this->resolveOwnerModuleBasePath($path, $modules);
        if (!$ownerBasePath) {
            return null;
        }

        return rtrim($ownerBasePath, DS) . DS . 'view' . DS . 'theme' . DS . $themeRelativePath;
    }

    /**
     * 解析 modulePath 所属模块 base_path（最长前缀匹配）
     *
     * @param string $modulePath 标准化后的绝对路径
     * @param array $modules Env::getModuleList() 返回结果
     * @return string|null
     */
    private function resolveOwnerModuleBasePath(string $modulePath, array $modules): ?string
    {
        $ownerBasePath = null;
        $ownerLen = 0;
        foreach ($modules as $moduleInfo) {
            $basePath = (string)($moduleInfo['base_path'] ?? '');
            if ($basePath === '') {
                continue;
            }
            $basePath = rtrim(str_replace(['/', '\\'], DS, $basePath), DS);
            $prefix = $basePath . DS;
            if (($modulePath === $basePath || str_starts_with($modulePath, $prefix)) && strlen($basePath) > $ownerLen) {
                $ownerBasePath = $basePath;
                $ownerLen = strlen($basePath);
            }
        }

        return $ownerBasePath;
    }
}
