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
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ConfigLoader;
use Weline\Theme\Model\WelineTheme;

/**
 * 控制器模板获取前观察者
 * 根据控制器的 layoutType 自动加载对应的主题布局
 */
class ControllerFetchFileBefore implements ObserverInterface
{
    private WelineTheme $welineTheme;

    public function __construct(WelineTheme $welineTheme)
    {
        $this->welineTheme = $welineTheme;
    }

    public function execute(Event &$event): void
    {
        /** @var DataObject $eventData */
        $eventData = $event->getData('data');
        if (!$eventData instanceof DataObject) {
            return;
        }

        $layoutType = $eventData->getData('layoutType');
        $fileName = $eventData->getData('fileName');

        // 如果没有指定 layoutType，不处理
        if (empty($layoutType)) {
            return;
        }

        // // 如果文件名包含 '::'，说明是模块路径，不处理（保持原有逻辑）
        // if (strpos($fileName, '::') !== false) {
        //     return;
        // }
        try {
            // 获取当前主题
            $theme = $this->welineTheme->getActiveTheme();
            // 判断区域（frontend/backend）
            $request = ObjectManager::getInstance(Request::class);
            $area = $request && $request->isBackend() ? 'backend' : 'frontend';

            // 解析布局类型和选项
            // 支持格式：'account.auth' (布局类型.布局选项) 或 'account' (仅布局类型)
            $originalLayoutType = $layoutType; // 保存原始值用于调试
            $layoutOption = null;
            
            // 检查是否包含点号
            $dotPos = strpos($layoutType, '.');
            if ($dotPos !== false) {
                // 包含点号，分割为布局类型和布局选项
                $parts = explode('.', $layoutType, 2);
                
                $layoutType = trim($parts[0]);  // 布局类型：account
                $layoutOption = isset($parts[1]) && !empty(trim($parts[1])) ? trim($parts[1]) : null; // 布局选项：auth（代码中明确指定，优先级最高）
            }

            // 从主题配置中动态获取布局配置
            $layoutConfig = ConfigLoader::getLayoutConfig($theme, $area);
            // dd($layoutConfig); // 调试代码已注释
            // 如果代码中没有指定布局选项，则从配置中获取
            if ($layoutOption === null || $layoutOption === '') {
                // 检查 layoutType 是否在配置中存在
                if (!isset($layoutConfig[$layoutType])) {
                    // 如果不存在，尝试使用 default 布局类型
                    if (isset($layoutConfig['default'])) {
                        $layoutType = 'default';
                        $layoutOption = $layoutConfig['default'];
                    } else {
                        // 如果连 default 都没有，使用默认值
                        $layoutOption = 'default';
                    }
                } else {
                    // 从配置中获取布局选项
                    $layoutOption = $layoutConfig[$layoutType] ?? 'default';
                }
            }
            
            // 构建布局模板路径
            $layoutPath = $this->buildLayoutPath($fileName, $area, $layoutType, $layoutOption);
            // 检查布局模板是否存在（支持主题继承）
            $resolvedLayoutPath = $this->resolveLayoutTemplate($layoutPath, $theme, $area);

            if ($resolvedLayoutPath) {
                // 将原模板路径保存为变量，供布局模板使用
                // 布局模板可以通过 $this->getData('contentTemplate') 获取原模板路径
                // 然后使用 $this->fetch($contentTemplate) 渲染原模板内容
                $eventData->setData('contentTemplate', $fileName);
                $eventData->setData('fileName', $resolvedLayoutPath);
                $eventData->setData('layoutType', $layoutType);
                $eventData->setData('layoutOption', $layoutOption);
                
                // 同时将 contentTemplate 传递给模板数据，方便布局模板直接使用
                $template = Template::getInstance();
                $template->setData('contentTemplate', $fileName);
                $template->setData('fileName', $resolvedLayoutPath);
                $template->setData('layoutType', $layoutType);
                $template->setData('layoutOption', $layoutOption);
            }
            // 如果布局模板不存在，保持原路径（回退机制）
        } catch (\Exception $e) {
            // 如果出现异常，保持原路径，不影响原有功能
            return;
        }
    }

    /**
     * 构建布局模板路径
     * 
     * @param string $originalPath 原模板路径
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @return string 布局模板路径
     */
    private function buildLayoutPath(string $originalPath, string $area, string $layoutType, string $layoutOption): string
    {
        // 构建布局模板路径
        // theme/{area}/layouts/{layoutType}/{layoutOption}.phtml
        return 'theme' . DS . $area . DS . 'layouts' . DS . $layoutType . DS . $layoutOption . '.phtml';
    }

    /**
     * 解析布局模板路径（支持主题继承）
     * 
     * @param string $layoutPath 布局模板路径
     * @param WelineTheme $theme 当前主题
     * @param string $area 区域
     * @return string|null 解析后的布局模板路径，如果不存在返回 null
     */
    private function resolveLayoutTemplate(string $layoutPath, WelineTheme $theme, string $area): ?string
    {
        // 构建完整路径
        $themePath = $theme->getPath();
        if (empty($themePath)) {
            return null;
        }

        // 构建主题布局文件路径
        $fullPath = rtrim($themePath, DS) . DS . 'view' . DS . $layoutPath;
        $fullPath = str_replace('\\', DS, $fullPath);

        // 检查当前主题是否存在
        if (is_file($fullPath)) {
            // 转换为模块路径格式，供 Template 使用
            return $this->convertToModulePath($fullPath, $area);
        }

        // 如果当前主题不存在，尝试父主题
        $parentId = $theme->getParentId();
        if ($parentId) {
            try {
                /** @var WelineTheme $parentTheme */
                $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                $parentTheme->load($parentId);

                if ($parentTheme->getId()) {
                    return $this->resolveLayoutTemplate($layoutPath, $parentTheme, $area);
                }
            } catch (\Exception $e) {
                // 父主题加载失败，继续查找
            }
        }

        // 如果主题继承链中都没有，尝试默认主题路径
        $defaultPath = $this->getDefaultLayoutPath($layoutPath, $area);
        if ($defaultPath && is_file($defaultPath)) {
            return $this->convertToModulePath($defaultPath, $area);
        }

        return null;
    }

    /**
     * 获取默认布局路径（从 Weline_Theme 模块）
     * 
     * @param string $layoutPath 布局模板路径
     * @param string $area 区域
     * @return string|null 默认布局路径
     */
    private function getDefaultLayoutPath(string $layoutPath, string $area): ?string
    {
        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules['Weline_Theme'])) {
            return null;
        }

        $themeModule = $modules['Weline_Theme'];
        $defaultPath = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . $layoutPath;

        return $defaultPath;
    }

    /**
     * 将文件系统路径转换为模块路径格式
     * 
     * @param string $fullPath 完整文件路径
     * @param string $area 区域
     * @return string 模块路径格式（Weline_Theme::theme/...）
     */
    private function convertToModulePath(string $fullPath, string $area): string
    {
        // 查找 view/theme/ 的位置
        $themePos = strpos($fullPath, DS . 'view' . DS . 'theme' . DS);
        if ($themePos === false) {
            return $fullPath;
        }

        // 提取 view/theme/ 之后的部分
        $themeRelativePath = substr($fullPath, $themePos + strlen(DS . 'view' . DS . 'theme' . DS));
        $themeRelativePath = str_replace('\\', '/', $themeRelativePath);

        // 转换为模块路径格式：Weline_Theme::theme/{area}/...
        return 'Weline_Theme::theme/' . $themeRelativePath;
    }
}

