<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Cache\ThemeCache;
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
         * @var $template Template
         */
        $template = $event->getData('object');
        /**
         * @var $fileData DataObject
         */
        $fileData = $event->getData('data');

        $module_file_path = $fileData->getData('filename');

        # 开始分析主题路径
        try {
            $theme = $this->welineTheme->getActiveTheme();
        } catch (\Exception $exception) {
            throw  new Exception(__('主题异常：') . $exception->getMessage());
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
        
        // 4. 如果主题继承链中都没有找到，返回基础模块文件
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
        
        // 提取模块基础路径（view 之前的部分）
        $moduleBasePath = substr($modulePath, 0, $themePos);
        
        // 尝试查找当前请求的模块
        $request = Env::getInstance()->getRequest();
        if ($request) {
            $currentModulePath = $request->getModulePath();
            if ($currentModulePath) {
                // 构建当前模块的 theme 路径
                $currentModuleThemePath = rtrim($currentModulePath, DS) . DS . 'view' . DS . 'theme' . DS . $themeRelativePath;
                if (is_file($currentModuleThemePath)) {
                    return $currentModuleThemePath;
                }
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
}
