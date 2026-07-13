<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Api\Data\MetaConfigScopeSearch;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Theme\Model\WelineTheme;

/**
 * 布局依赖追踪器
 * 
 * 追踪布局文件对partials的依赖关系，检测文件更新和Meta配置更新
 */
class LayoutDependencyTracker
{
    /** @var array 依赖关系缓存 [layoutFile => [dependencies => [], lastCheck => timestamp]] */
    private static array $dependencyCache = [];
    
    /**
     * 解析布局文件，提取partials依赖
     * 
     * @param string $layoutFile 布局文件路径
     * @return array 依赖的partials文件路径数组
     */
    public function extractDependencies(string $layoutFile): array
    {
        // 检查缓存
        $cacheKey = md5($layoutFile);
        if (isset(self::$dependencyCache[$cacheKey])) {
            $cache = self::$dependencyCache[$cacheKey];
            // 如果文件未更新，直接返回缓存
            if (file_exists($layoutFile) && filemtime($layoutFile) <= $cache['lastCheck']) {
                return $cache['dependencies'];
            }
        }
        
        if (!is_file($layoutFile)) {
            return [];
        }
        
        $content = file_get_contents($layoutFile);
        $dependencies = [];
        
        // 提取 getPartialsPath 调用
        // 匹配: getPartialsPath('frontend', 'header', 'default')
        if (preg_match_all(
            '/getPartialsPath\s*\(\s*[\'"]([^\'"]+)[\'"],\s*[\'"]([^\'"]+)[\'"],\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            $content,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $area = $match[1];
                $type = $match[2];
                $option = $match[3];
                
                // 构建partials文件路径
                $partialPath = $this->resolvePartialsPath($area, $type, $option);
                if ($partialPath && is_file($partialPath)) {
                    $dependencies[] = $partialPath;
                }
            }
        }
        
        // 提取 fetch 调用中的partials路径
        // 匹配: fetch('Weline_Theme::theme/frontend/partials/header/default.phtml')
        if (preg_match_all(
            '/fetch\s*\(\s*[\'"]([^\'"]*partials[^\'"]*\.phtml)[\'"]/',
            $content,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $partialPath = $this->resolveModulePath($match[1]);
                if ($partialPath && is_file($partialPath)) {
                    $dependencies[] = $partialPath;
                }
            }
        }
        
        // 去重
        $dependencies = array_unique($dependencies);
        
        // 更新缓存
        self::$dependencyCache[$cacheKey] = [
            'dependencies' => $dependencies,
            'lastCheck' => time()
        ];
        
        return $dependencies;
    }
    
    /**
     * 检查布局文件是否需要重新生成
     * 
     * @param string $layoutFile 布局文件路径
     * @param string $generatedFile 生成的CSS/JS文件路径
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @return bool 是否需要重新生成
     */
    public function needsRegeneration(
        string $layoutFile,
        string $generatedFile,
        WelineTheme $theme,
        string $area
    ): bool {
        // 检查布局文件是否存在
        if (!is_file($layoutFile)) {
            return false;
        }
        
        // 检查生成的文件是否存在
        if (!is_file($generatedFile)) {
            return true;
        }
        
        $layoutMtime = filemtime($layoutFile);
        $generatedMtime = filemtime($generatedFile);
        
        // 如果布局文件更新了，需要重新生成
        if ($layoutMtime > $generatedMtime) {
            return true;
        }
        
        // 检查依赖的partials文件
        $dependencies = $this->extractDependencies($layoutFile);
        foreach ($dependencies as $depFile) {
            if (is_file($depFile) && filemtime($depFile) > $generatedMtime) {
                return true;
            }
        }
        
        // 检查Meta配置更新（变量配置）
        if ($this->hasMetaConfigUpdates($theme, $area, $generatedMtime)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 检查Meta配置是否有更新
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @param int $sinceTimestamp 检查此时间之后的更新
     * @return bool 是否有更新
     */
    private function hasMetaConfigUpdates(WelineTheme $theme, string $area, int $sinceTimestamp): bool
    {
        if (!$theme->getId()) {
            return false;
        }
        
        try {
            $repository = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(MetaConfigRepositoryInterface::class);
            if (!$repository instanceof MetaConfigRepositoryInterface) {
                return true;
            }

            // Meta config rows have no persisted update timestamp. Keep the previous
            // conservative regeneration behavior whenever variable overrides exist.
            $namespace = "theme.{$area}";
            $identifyId = (string)$theme->getId();
            foreach ($repository->listScopes(new MetaConfigScopeSearch(
                namespace: $namespace,
                identifyId: $identifyId,
            )) as $scope) {
                if ($repository->search(new MetaConfigSearch(
                    namespace: $namespace,
                    scope: $scope,
                    configKeyPrefix: 'variables.',
                    allLocales: true,
                    identifyId: $identifyId,
                )) !== []) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            // 查询失败，保守处理：返回true
            return true;
        }
    }
    
    /**
     * 解析partials路径
     * 
     * @param string $area 区域
     * @param string $type partials类型
     * @param string $option 选项
     * @return string|null 文件路径
     */
    private function resolvePartialsPath(string $area, string $type, string $option): ?string
    {
        // 尝试从当前主题解析
        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
        
        // 首先尝试Weline_Theme模块
        if (isset($modules['Weline_Theme'])) {
            $themeModule = $modules['Weline_Theme'];
            $path = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'partials' . DS . $type . DS . $option . '.phtml';
            if (is_file($path)) {
                return $path;
            }
        }
        
        return null;
    }
    
    /**
     * 解析模块路径格式为绝对路径
     * 
     * @param string $modulePath 模块路径（如 Weline_Theme::theme/frontend/partials/header/default.phtml）
     * @return string|null 绝对路径
     */
    private function resolveModulePath(string $modulePath): ?string
    {
        if (strpos($modulePath, '::') === false) {
            return null;
        }
        
        list($moduleName, $relativePath) = explode('::', $modulePath, 2);
        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
        
        if (!isset($modules[$moduleName])) {
            return null;
        }
        
        $module = $modules[$moduleName];
        $basePath = rtrim($module['base_path'], DS);
        $relativePath = str_replace('/', DS, $relativePath);
        
        return $basePath . DS . 'view' . DS . $relativePath;
    }
    
    /**
     * 清除依赖缓存
     * 
     * @param string|null $layoutFile 布局文件路径，如果为null则清除所有缓存
     * @return void
     */
    public static function clearCache(?string $layoutFile = null): void
    {
        if ($layoutFile) {
            $cacheKey = md5($layoutFile);
            unset(self::$dependencyCache[$cacheKey]);
        } else {
            self::$dependencyCache = [];
        }
    }
}
