<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\ConfigLoader;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Helper\PreviewManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * 部件文件获取前观察者
 * 根据配置的部件选项动态替换部件文件路径
 */
class PartialsFetchFileBefore implements ObserverInterface
{
    private WelineTheme $welineTheme;

    public function __construct(WelineTheme $welineTheme)
    {
        $this->welineTheme = $welineTheme;
    }

    public function execute(Event &$event): void
    {
        /** @var DataObject $fileData */
        $fileData = $event->getData('data');
        
        if (!$fileData instanceof DataObject) {
            return;
        }

        $modulePath = $fileData->getData('filename');
        if (empty($modulePath)) {
            return;
        }

        // 快速预检查：是否是部件文件路径
        $partialsPos = strpos($modulePath, '/partials/');
        if ($partialsPos === false) {
            return; // 不是部件文件，直接退出
        }

        try {
            // 解析部件路径
            $pathInfo = $this->parsePartialsPath($modulePath);
            if ($pathInfo === null) {
                return; // 路径格式不符合，退出
            }

            $area = $pathInfo['area'];
            $partialType = $pathInfo['type'];
            $currentOption = $pathInfo['option'];

            // 获取当前主题
            $theme = $this->welineTheme->getActiveTheme();
            
            // 检查是否有预览主题
            $session = ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
            $previewThemeId = $session->getData('preview_theme_id');
            if ($previewThemeId) {
                $theme->load($previewThemeId);
            }

            if (!$theme->getId()) {
                return;
            }

            // 解析 scope（优先从预览模式获取，其次从请求参数获取，最后使用 default）
            $scope = 'default';
            try {
                // 检查预览模式
                if (PreviewManager::isPreviewMode()) {
                    $previewScope = PreviewManager::getPreviewScope($area);
                    if ($previewScope) {
                        $scope = $previewScope;
                    }
                }
                
                // 如果不在预览模式，尝试从请求参数获取
                if ($scope === 'default') {
                    try {
                        /** @var Request $request */
                        $request = ObjectManager::getInstance(Request::class);
                        if ($request) {
                            $paramName = 'scope_' . $area;
                            $scopeParam = $request->getParam($paramName) ?? $request->getParam('scope');
                            if ($scopeParam) {
                                // 处理 scope 格式（可能是 frontend/default）
                                if (str_contains($scopeParam, '/')) {
                                    [$maybeArea, $rest] = explode('/', $scopeParam, 2);
                                    if ($maybeArea === $area) {
                                        $scope = $rest ?: 'default';
                                    }
                                } else {
                                    $scope = $scopeParam;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        // 忽略错误，使用默认 scope
                    }
                }
            } catch (\Throwable $e) {
                // 忽略错误，使用默认 scope
            }

            // 获取配置的部件选项
            $configOption = ConfigLoader::getPartialConfig($theme, $area, $partialType, $scope);

            // 如果配置的选项与当前不同，替换路径
            $finalOption = $currentOption;
            if ($configOption !== $currentOption && !empty($configOption)) {
                $newPath = $this->replacePartialsOption($modulePath, $currentOption, $configOption);
                
                // 验证新路径是否存在（可选，如果不存在可以回退）
                // 这里先直接替换，让 TemplateFetchFile 处理文件查找
                $fileData->setData('filename', $newPath);
                $finalOption = $configOption;
            }

            // 加载部件文件的参数配置（自动读取 @param 定义的参数）
            // 构建 meta_identify：partials.{partialType} 或 partials.{partialType}.{partialOption}
            $metaIdentify = "partials.{$partialType}";
            if ($finalOption && $finalOption !== 'default') {
                $metaIdentify .= ".{$finalOption}";
            } else {
                $metaIdentify = "partials.{$partialType}.default";
            }
            
            // 读取部件文件的参数配置值
            $partialParams = ThemeData::getFileParams($metaIdentify, $scope);
            
            // 如果从 Meta 表中没有读取到参数，尝试从文件直接解析
            if (empty($partialParams)) {
                // 获取最终的文件路径（可能已被替换）
                $finalPath = $fileData->getData('filename');
                // 尝试解析文件路径获取完整路径
                $partialFilePath = $this->getPartialFilePath($finalPath, $theme, $area, $partialType, $finalOption);
                if ($partialFilePath && is_file($partialFilePath)) {
                    // 使用 ComponentMetaParser 从文件解析参数定义
                    $parsedMeta = ComponentMetaParser::parse($partialFilePath);
                    if (!empty($parsedMeta['params']) && is_array($parsedMeta['params'])) {
                        // 格式化参数定义
                        $formattedParams = LayoutPathResolver::formatParsedParams($parsedMeta['params']);
                        // 提取默认值作为参数值
                        foreach ($formattedParams as $paramName => $paramDef) {
                            $defaultValue = $paramDef['default'] ?? null;
                            // 处理布尔值默认值
                            if ($defaultValue === 'true' || $defaultValue === true) {
                                $defaultValue = true;
                            } elseif ($defaultValue === 'false' || $defaultValue === false) {
                                $defaultValue = false;
                            }
                            // 处理空字符串默认值
                            if ($defaultValue === '') {
                                $defaultValue = '';
                            }
                            $partialParams[$paramName] = $defaultValue;
                        }
                    }
                }
            }
            
            // 确保即使没有参数，也至少设置一个空的 meta 数组，避免模板中访问 meta 时出错
            if (empty($partialParams)) {
                $partialParams = [];
            }
            
            // 将所有参数统一设置到 meta 数组中（供模板使用 {{meta.参数}} 语法）
            // 获取模板实例并设置 meta 数据
            try {
                $template = Template::getInstance();
                $existingMeta = $template->getData('meta') ?? [];
                if (empty($existingMeta)) {
                    $metaData = array_merge($existingMeta, $partialParams);
                } else {
                    // 如果已有 meta 数据，合并 partials 的参数（partials 的参数优先级更高）
                    $metaData = array_merge($existingMeta, $partialParams);
                }
                
                // 关于主题的元数据传递给模板数据
                ThemeData::performanceLoad();
                $themeMetaDataObj = ThemeData::getMeta("theme.{$area}.partials.{$partialType}");
                if ($themeMetaDataObj && !empty($themeMetaDataObj['meta_data'])) {
                    // 合并 meta_data 中的配置值到 metaData
                    $metaData = array_merge($metaData, $themeMetaDataObj['meta_data']);
                }
                
                // 将 meta 数据设置到模板中
                $template->setData('meta', $metaData);
            } catch (\Exception $e) {
                // 如果获取模板实例失败，忽略错误，不影响原有功能
            }
        } catch (\Exception $e) {
            // 如果出现异常，保持原路径，不影响原有功能
            return;
        }
    }

    /**
     * 解析部件文件路径（高效字符串操作）
     * 
     * @param string $path 文件路径（模块路径或绝对路径）
     * @return array|null 返回 ['area' => 'frontend', 'type' => 'header', 'option' => 'default'] 或 null
     */
    private function parsePartialsPath(string $path): ?array
    {
        // 快速检查：必须包含 /partials/
        $partialsPos = strpos($path, '/partials/');
        if ($partialsPos === false) {
            return null;
        }
        
        // 提取 /partials/ 之后的部分
        $afterPartials = substr($path, $partialsPos + 10); // '/partials/' 长度是 10
        if (empty($afterPartials)) {
            return null;
        }
        
        // 分割路径段：type/option.phtml
        $parts = explode('/', $afterPartials, 2);
        if (count($parts) !== 2) {
            return null;
        }
        
        $type = $parts[0];
        $file = $parts[1];
        
        // 提取文件名（去掉 .phtml 扩展名）
        if (!str_ends_with($file, '.phtml')) {
            return null;
        }
        $option = substr($file, 0, -6); // '.phtml' 长度是 6
        
        // 提取 area：查找 theme/{area}/partials
        // 支持模块路径格式：Module::theme/{area}/partials 或绝对路径：.../theme/{area}/partials
        $themePos = strpos($path, 'theme/');
        if ($themePos === false) {
            // 尝试查找 view/theme/
            $viewThemePos = strpos($path, 'view/theme/');
            if ($viewThemePos !== false) {
                $themePos = $viewThemePos + 5; // 'view/' 长度是 5
            } else {
                return null;
            }
        }
        
        $afterTheme = substr($path, $themePos + 6); // 'theme/' 长度是 6
        $areaParts = explode('/', $afterTheme, 2);
        if (count($areaParts) < 1) {
            return null;
        }
        
        $area = $areaParts[0];
        if (!in_array($area, ['frontend', 'backend'], true)) {
            return null;
        }
        
        return [
            'area' => $area,
            'type' => $type,
            'option' => $option
        ];
    }

    /**
     * 替换部件路径中的选项
     * 
     * @param string $path 原路径
     * @param string $oldOption 旧选项
     * @param string $newOption 新选项
     * @return string 替换后的路径
     */
    private function replacePartialsOption(string $path, string $oldOption, string $newOption): string
    {
        // 查找最后一个 / 的位置（文件名之前）
        $lastSlash = strrpos($path, '/');
        if ($lastSlash === false) {
            return $path;
        }
        
        // 替换文件名部分
        return substr($path, 0, $lastSlash + 1) . $newOption . '.phtml';
    }

    /**
     * 获取部件文件的完整路径
     * 
     * @param string $modulePath 模块路径
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @param string $partialType 部件类型
     * @param string $partialOption 部件选项
     * @return string|null 完整文件路径，如果不存在返回 null
     */
    private function getPartialFilePath(string $modulePath, WelineTheme $theme, string $area, string $partialType, string $partialOption): ?string
    {
        // 如果已经是绝对路径，直接返回
        if (is_file($modulePath)) {
            return $modulePath;
        }
        
        // 尝试构建主题路径
        $themePath = $theme->getPath();
        if ($themePath) {
            // 构建部件文件路径：app/design/{theme}/view/theme/{area}/partials/{type}/{option}.phtml
            $partialPath = $themePath . DS . 'view' . DS . 'theme' . DS . $area . DS . 'partials' . DS . $partialType . DS . $partialOption . '.phtml';
            if (is_file($partialPath)) {
                return $partialPath;
            }
        }
        
        // 尝试从模块路径解析
        // 查找 view/theme/ 的位置
        $themePos = strpos($modulePath, DS . 'view' . DS . 'theme' . DS);
        if ($themePos !== false) {
            // 提取 view/theme/ 之后的部分
            $relativePath = substr($modulePath, $themePos + strlen(DS . 'view' . DS . 'theme' . DS));
            // 构建完整路径
            $fullPath = BP . DS . $relativePath;
            if (is_file($fullPath)) {
                return $fullPath;
            }
        }
        
        // 尝试从模块路径直接构建
        if (strpos($modulePath, '::') !== false) {
            // 模块路径格式：Module::path
            [$moduleName, $relativePath] = explode('::', $modulePath, 2);
            $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
            if (isset($modules[$moduleName])) {
                $moduleBasePath = $modules[$moduleName]['base_path'];
                $fullPath = rtrim($moduleBasePath, DS) . DS . $relativePath;
                if (is_file($fullPath)) {
                    return $fullPath;
                }
            }
        }
        
        return null;
    }
}





