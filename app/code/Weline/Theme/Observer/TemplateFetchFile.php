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
use Weline\Theme\Helper\Interface\ThemePathResolverInterface;
use Weline\Theme\Model\WelineTheme;

/**
 * 模板文件获取观察者
 * 
 * 职责：在模板文件获取时解析主题文件路径
 * 遵循：单一职责原则 (SRP)、依赖倒置原则 (DIP)
 */
class TemplateFetchFile implements ObserverInterface
{
    /**
     * @var WelineTheme
     */
    private WelineTheme $welineTheme;
    
    /**
     * @var ThemePathResolverInterface
     */
    private ThemePathResolverInterface $themePathResolver;

    /**
     * 依赖注入：遵循依赖倒置原则 (DIP)
     * 
     * @param WelineTheme $welineTheme
     * @param ThemePathResolverInterface $themePathResolver
     */
    public function __construct(
        WelineTheme $welineTheme,
        ThemePathResolverInterface $themePathResolver
    )
    {
        $this->welineTheme = $welineTheme;
        $this->themePathResolver = $themePathResolver;
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
        
        // 使用主题路径解析器解析文件路径（支持多级继承）
        $theme_file_path = $this->themePathResolver->resolveThemeFile($module_file_path, $theme);
        $theme_file_path = str_replace('\\', DS, $theme_file_path);
        $fileData->setData('filename', $theme_file_path);
    }
}
