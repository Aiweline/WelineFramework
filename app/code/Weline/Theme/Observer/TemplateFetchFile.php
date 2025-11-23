<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;

class TemplateFetchFile implements ObserverInterface
{
    /**
     * @var WelineTheme
     */
    private WelineTheme $welineTheme;

    /**
     * TemplateFetchBefore 初始函数...
     *
     * @param WelineTheme $welineTheme
     * @param CacheInterface $themeCache
     */
    public function __construct(
        WelineTheme $welineTheme
    )
    {
        $this->welineTheme = $welineTheme;
    }

    public function execute(Event &$event): void
    {
        /**
         * @var $fileData DataObject
         */
        $fileData = $event->getData('data');
        if (!$fileData instanceof DataObject) {
            return;
        }

        $module_file_path = $fileData->getData('filename');
        if (empty($module_file_path)) {
            return;
        }

        // 如果是编译文件路径（包含 com_ 前缀或已经是绝对路径且不在 app/code 或 app/design 下），不处理
        // 编译文件路径应该保持原样，不应该被主题系统处理
        if (strpos(basename($module_file_path), 'com_') === 0) {
            // 这是编译文件，不处理
            return;
        }
        
        // 如果路径已经是绝对路径，检查是否在编译目录下
        if (strpos($module_file_path, DS) === 0 || (strlen($module_file_path) > 2 && $module_file_path[1] === ':')) {
            // 绝对路径，检查是否在编译目录或模板编译目录下
            if (strpos($module_file_path, 'tpl' . DS) !== false || 
                strpos($module_file_path, 'template_compile' . DS) !== false ||
                strpos($module_file_path, 'generated' . DS . 'complicate' . DS) !== false) {
                // 这是编译目录下的文件，不处理
                return;
            }
        }

        # 开始分析主题路径
        // 检查是否有预览主题（从请求参数获取）
        $previewThemeId = 0;
        if (!CLI) {
            try {
                $request = ObjectManager::getInstance(Request::class);
                $previewThemeId = (int)$request->getParam('preview_theme', 0);
            } catch (\Throwable $e) {
                // 如果获取 Request 失败，忽略预览逻辑
                $previewThemeId = 0;
            }
        }
        if ($previewThemeId) {
            // 使用预览主题
            $this->welineTheme->load($previewThemeId);
            if ($this->welineTheme->getId()) {
                $theme = $this->welineTheme;
            } else {
                // 预览主题不存在，使用激活的主题
                try {
                    $theme = $this->welineTheme->getActiveTheme();
                } catch (\Exception $exception) {
                    throw  new Exception(__('主题异常：') . $exception->getMessage());
                }
            }
        } else {
            // 正常流程：使用激活的主题
            try {
                $theme = $this->welineTheme->getActiveTheme();
            } catch (\Exception $exception) {
                throw  new Exception(__('主题异常：') . $exception->getMessage());
            }
        }
        
        # 主题不存在且非开发环境
        if (PROD && !isset($theme)) {
            $theme = $this->welineTheme->setData(Env::default_theme_DATA);
        }
        
        // 使用新的主题文件解析逻辑（支持多级继承）
        $theme_file_path = $this->resolveThemeFile($module_file_path, $theme);
        $theme_file_path = str_replace('\\', DS, $theme_file_path);
        $fileData->setData('filename', $theme_file_path);
    }

    /**
     * 解析主题文件（支持多级继承链和模块级主题继承）
     * 
     * @param string $modulePath 模块文件路径
     * @param WelineTheme $theme 当前主题
     * @param array $visited 已访问的主题ID（防止循环引用）
     * @return string 解析后的文件路径
     */
    private function resolveThemeFile(string $modulePath, WelineTheme $theme, array $visited = []): string
    {
        // 防止循环引用
        $themeId = $theme->getId();
        if ($themeId && in_array($themeId, $visited)) {
            // 如果检测到循环，返回基础模块文件
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
        
        // 如果当前主题中存在该文件，直接返回
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
                    // 递归查找父主题
                    return $this->resolveThemeFile($modulePath, $parentTheme, $visited);
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
            return $modulePath; // 不是 theme 文件，直接返回
        }
        
        // 提取模块路径和相对路径
        // 例如：app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml
        // 需要转换为：app/code/Weline/Frontend/view/theme/frontend/partials/header/default.phtml
        
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
                    // 构建当前模块的 theme 路径
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
    private function buildThemePath(string $modulePath, string $themePath): string
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
        // 检查路径中是否包含 theme/frontend/layouts、theme/backend/layouts、
        // theme/frontend/partials 或 theme/backend/partials
        return (strpos($modulePath, DS . 'theme' . DS . 'frontend' . DS . 'layouts') !== false) ||
               (strpos($modulePath, DS . 'theme' . DS . 'backend' . DS . 'layouts') !== false) ||
               (strpos($modulePath, DS . 'theme' . DS . 'frontend' . DS . 'partials') !== false) ||
               (strpos($modulePath, DS . 'theme' . DS . 'backend' . DS . 'partials') !== false);
    }
    
    /**
     * 获取默认主题文件路径（从 Weline_Theme 模块）
     * 支持布局文件和部分文件
     * 
     * @param string $modulePath 模块文件路径
     * @return string|null 默认主题文件路径，如果不存在则返回null
     */
    private function getDefaultThemePath(string $modulePath): ?string
    {
        // 检查是否是 theme 目录下的布局或部分文件
        if (!$this->isThemeFile($modulePath)) {
            return null;
        }
        
        // 查找 view/theme/ 的位置
        $themePos = strpos($modulePath, DS . 'view' . DS . 'theme' . DS);
        if ($themePos === false) {
            return null;
        }
        
        // 提取 view/theme/ 之后的部分（相对路径）
        $themeRelativePath = substr($modulePath, $themePos + strlen(DS . 'view' . DS . 'theme' . DS));
        
        // 获取 Weline_Theme 模块路径
        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules['Weline_Theme'])) {
            return null;
        }
        
        $themeModule = $modules['Weline_Theme'];
        $defaultThemePath = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $themeRelativePath;
        
        return $defaultThemePath;
    }
}
