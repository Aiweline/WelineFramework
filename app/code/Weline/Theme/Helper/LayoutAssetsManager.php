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
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\CssVariableInjector;
use Weline\Theme\Model\WelineTheme;

/**
 * 布局资源文件管理器
 * 
 * 管理生成的CSS/JS文件路径、生成文件URL
 * 文件在模板编译阶段直接生成到pub/static目录，无需搬迁
 */
class LayoutAssetsManager
{
    /**
     * 获取生成的CSS文件路径
     * 
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @param WelineTheme|null $theme 主题对象
     * @return string 文件路径
     */
    public function getGeneratedCssPath(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null
    ): string {
        $themeName = $this->getThemeName($theme);
        // 统一路径分隔符，确保使用系统分隔符
        $themeName = str_replace(['/', '\\'], DS, $themeName);
        $fileName = "{$layoutOption}.css";
        
        // 布局相关的CSS/JS应该生成到前端静态文件目录 pub/static
        // 路径：pub/static/{themeName}/Weline/Theme/view/theme/{area}/layouts/{layoutType}/{layoutOption}.css
        $baseDir = BP . DS . 'pub' . DS . 'static' . DS . $themeName . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . $area . DS . 'layouts' . DS . $layoutType;
        
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }
        return $baseDir . DS . $fileName;
    }
    
    /**
     * 获取生成的JS文件路径
     * 
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @param WelineTheme|null $theme 主题对象
     * @return string 文件路径
     */
    public function getGeneratedJsPath(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null
    ): string {
        $themeName = $this->getThemeName($theme);
        // 统一路径分隔符，确保使用系统分隔符
        $themeName = str_replace(['/', '\\'], DS, $themeName);
        $fileName = "{$layoutOption}.js";
        
        // 布局相关的CSS/JS应该生成到前端静态文件目录 pub/static
        // 路径：pub/static/{themeName}/Weline/Theme/view/theme/{area}/layouts/{layoutType}/{layoutOption}.js
        $baseDir = BP . DS . 'pub' . DS . 'static' . DS . $themeName . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . $area . DS . 'layouts' . DS . $layoutType;
        
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }
        return $baseDir . DS . $fileName;
    }
    
    /**
     * 获取CSS文件URL
     * 
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @param WelineTheme|null $theme 主题对象
     * @param Template|null $template Template实例（用于生成URL）
     * @return string 文件URL
     */
    public function getCssUrl(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null,
        ?Template $template = null
    ): string {
        $themeName = $this->getThemeName($theme);
        $fileName = "{$layoutOption}.css";
        
        // 布局相关的CSS/JS应该生成到前端静态文件目录 pub/static
        // 路径：{themeName}/Weline/Theme/view/theme/{area}/layouts/{layoutType}/{fileName}
        $staticPath = "{$themeName}/Weline/Theme/view/theme/{$area}/layouts/{$layoutType}/{$fileName}";
        
        // 直接生成 /static/ 格式的URL，不依赖fetchTagSource
        // 因为布局CSS/JS文件在pub/static目录，应该使用/static/路径
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? ($_SERVER['HTTPS'] ?? '' === 'on' ? 'https' : 'http');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
        // 构建baseUrl，确保没有双斜杠
        $scriptDir = dirname($scriptName);
        // 如果scriptDir是根目录或只有斜杠，则设为空字符串
        if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
            $scriptDir = '';
        }
        $baseUrl = rtrim($scheme . '://' . $host . $scriptDir, '/');
        // 确保路径没有双斜杠
        $staticPathNormalized = ltrim(str_replace(DS, '/', $staticPath), '/');
        return $baseUrl . '/static/' . $staticPathNormalized;
    }
    
    /**
     * 获取JS文件URL
     * 
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @param WelineTheme|null $theme 主题对象
     * @param Template|null $template Template实例
     * @return string 文件URL
     */
    public function getJsUrl(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null,
        ?Template $template = null
    ): string {
        $themeName = $this->getThemeName($theme);
        $fileName = "{$layoutOption}.js";
        
        // 布局相关的CSS/JS应该生成到前端静态文件目录 pub/static
        // 路径：{themeName}/Weline/Theme/view/theme/{area}/layouts/{layoutType}/{fileName}
        $staticPath = "{$themeName}/Weline/Theme/view/theme/{$area}/layouts/{$layoutType}/{$fileName}";
        
        // 直接生成 /static/ 格式的URL，不依赖fetchTagSource
        // 因为布局CSS/JS文件在pub/static目录，应该使用/static/路径
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? ($_SERVER['HTTPS'] ?? '' === 'on' ? 'https' : 'http');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
        // 构建baseUrl，确保没有双斜杠
        $scriptDir = dirname($scriptName);
        // 如果scriptDir是根目录或只有斜杠，则设为空字符串
        if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
            $scriptDir = '';
        }
        $baseUrl = rtrim($scheme . '://' . $host . $scriptDir, '/');
        // 确保路径没有双斜杠
        $staticPathNormalized = ltrim(str_replace(DS, '/', $staticPath), '/');
        return $baseUrl . '/static/' . $staticPathNormalized;
    }
    
    /**
     * 获取主题名称
     * 
     * @param WelineTheme|null $theme 主题对象
     * @return string 主题名称（使用原始路径，如 Weline/test）
     */
    private function getThemeName(?WelineTheme $theme): string
    {
        if ($theme && $theme->getId()) {
            // 使用getOriginPath()获取原始路径（如 Weline/test），而不是完整路径
            $originPath = $theme->getOriginPath();
            if (!empty($originPath)) {
                // 原始路径格式：Weline/test 或 Weline\test
                // 直接使用，不需要提取最后一部分
                return str_replace('\\', '/', $originPath);
            }
        }
        
        // 默认主题
        $config = Env::getInstance()->getConfig('theme', []);
        $path = $config['path'] ?? 'default';
        // 如果配置中的path是完整路径，提取主题名
        if (strpos($path, '/') !== false || strpos($path, '\\') !== false) {
            return str_replace('\\', '/', $path);
        }
        return $path;
    }
    
    /**
     * 确保布局 CSS 文件存在，若不存在则按需生成（仅包含 CSS 变量）
     * 用于解决 LayoutAssetsGenerator 未运行时（如首次请求）的 404 问题
     *
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @param WelineTheme|null $theme 主题对象
     * @return bool 文件是否存在或已成功生成
     */
    public function ensureLayoutCssGenerated(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null
    ): bool {
        $cssPath = $this->getGeneratedCssPath($area, $layoutType, $layoutOption, $theme);
        if (is_file($cssPath)) {
            return true;
        }
        try {
            /** @var CssVariableInjector $injector */
            $injector = ObjectManager::getInstance(CssVariableInjector::class);
            $cssVariables = $injector->generateCssVariables($area, $theme, 'default');
            $cssContent = $cssVariables . "\n";
            if (!empty($cssContent)) {
                $dir = dirname($cssPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                return file_put_contents($cssPath, $cssContent) !== false;
            }
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV) {
                try {
                    Env::getInstance()->getLogger()?->warning('LayoutAssetsManager: 按需生成布局CSS失败', [
                        'area' => $area,
                        'layoutType' => $layoutType,
                        'layoutOption' => $layoutOption,
                        'error' => $e->getMessage()
                    ]);
                } catch (\Throwable) {
                    // 忽略
                }
            }
        }
        return false;
    }

    /**
     * 复制文件到静态目录（已废弃，文件现在直接在编译阶段生成到pub/static）
     * 
     * 注意：此方法保留仅为向后兼容，实际不再使用
     * 文件现在通过LayoutAssetsExtractor在模板编译阶段直接生成到pub/static目录
     * 
     * @param string $sourceFile 源文件路径
     * @param string $targetFile 目标文件路径
     * @return bool 是否成功
     * @deprecated 文件现在直接在编译阶段生成，无需复制
     */
    public function copyToStatic(string $sourceFile, string $targetFile): bool
    {
        // 文件现在直接在编译阶段生成到pub/static，此方法不再使用
        // 保留仅为向后兼容
        if (!is_file($sourceFile)) {
            return false;
        }
        
        $targetDir = dirname($targetFile);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        return copy($sourceFile, $targetFile);
    }
}

