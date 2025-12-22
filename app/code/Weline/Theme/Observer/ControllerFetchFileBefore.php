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
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\ThemeModeResolver;
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
        // 关键检查：只有当控制器设置了 layoutType 时才处理
        if (empty($layoutType)) {
            return;
        }
        $fileName = $eventData->getData('fileName');
        
        // 判断区域（frontend/backend）
        $request = ObjectManager::getInstance(Request::class);
        $area = $request && $request->isBackend() ? 'backend' : 'frontend';
        
        // 设置主题相关数据到 theme 对象中（由Helper处理业务逻辑，不在模板中处理）
        $template = Template::getInstance();
        $welineThemeColorMode = ThemeModeResolver::getThemeMode($area);
        
        // 获取当前主题（无论是否有 layoutType，都需要主题对象）
        $theme = $this->welineTheme->getActiveTheme();
        
        // 如果没有指定 layoutType，使用默认值（确保布局信息始终存在）
        $originalLayoutType = $layoutType;

        // // 如果文件名包含 '::'，说明是模块路径，不处理（保持原有逻辑）
        // if (strpos($fileName, '::') !== false) {
        //     return;
        // }
        try {

            // 解析布局类型和选项
            // 支持格式：'account.auth' (布局类型.布局选项) 或 'account' (仅布局类型)
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
            // 直接使用 ThemeData 读取布局配置
            // 设置当前主题和区域
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            
            // 解析 scope（优先从预览模式获取，其次从请求参数获取，最后使用 default）
            $scope = 'default';
            try {
                // 检查预览模式
                if (class_exists(\Weline\Theme\Helper\PreviewManager::class)) {
                    if (\Weline\Theme\Helper\PreviewManager::isPreviewMode()) {
                        $previewScope = \Weline\Theme\Helper\PreviewManager::getPreviewScope($area);
                        if ($previewScope) {
                            $scope = $previewScope;
                        }
                    }
                }
                
                // 如果不在预览模式，尝试从请求参数获取
                if ($scope === 'default' && $request) {
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
            
            // 直接使用 ThemeData 读取布局配置
            $layoutConfig = ThemeData::getLayoutConfig($area, $scope);

            // 配置来自元数据配置的布局
            if(isset($layoutConfig[$layoutType]) && $layoutOption !== $layoutConfig[$layoutType]){
                $layoutOption = $layoutConfig[$layoutType];
            }

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
            
            // 无论是否找到布局模板，都要设置布局信息到 theme 对象中（供 head partial 使用）
            // 将所有主题相关数据统一放到 theme 对象中（包括主题对象本身）
            $themeData = [
                'area' => $area,
                'colorMode' => $welineThemeColorMode,
                'layoutType' => $layoutType,
                'layoutOption' => $layoutOption,
                'theme' => $theme, // 主题对象本身，供模板直接使用
            ];
            $template->setData('theme', $themeData);
            
            // 构建布局模板路径
            $layoutPath = LayoutPathResolver::buildLayoutPath($fileName, $area, $layoutType, $layoutOption);
            // 检查布局模板是否存在（支持主题继承）
            $resolvedLayoutPath = LayoutPathResolver::resolveLayoutTemplate($layoutPath, $theme, $area);
            if ($resolvedLayoutPath) {
                // 将原模板路径保存为变量，供布局模板使用
                // 布局模板可以通过 $this->getData('contentTemplate') 获取原模板路径
                // 然后使用 $this->fetch($contentTemplate) 渲染原模板内容
                $eventData->setData('contentTemplate', $fileName);
                $eventData->setData('fileName', $resolvedLayoutPath);
                $eventData->setData('layoutType', $layoutType);
                $eventData->setData('layoutOption', $layoutOption);
                
                // 同时将 contentTemplate 传递给模板数据，方便布局模板直接使用
                $template->setData('contentTemplate', $fileName);
                $template->setData('fileName', $resolvedLayoutPath);

                // 加载布局文件的参数配置（自动读取 @param 定义的参数）
                // 构建 meta_identify：layouts.{layoutType} 或 layouts.{layoutType}.{layoutOption}
                // 注意：meta_identify 格式应该是 theme.{area}.layouts.{layoutType} 或 theme.{area}.layouts.{layoutType}.{layoutOption}
                $metaIdentify = "layouts.{$layoutType}";
                if ($layoutOption && $layoutOption !== 'default') {
                    $metaIdentify .= ".{$layoutOption}";
                }else{
                    $metaIdentify = "layouts.{$layoutType}.default";
                }
                
                // 读取布局文件的参数配置值
                // 注意：getFileParams 内部会处理 identify 格式，但需要确保 ThemeData 已正确初始化
                $layoutParams = ThemeData::getFileParams($metaIdentify, $scope);
                
                // 如果从 Meta 表中没有读取到参数，尝试从文件直接解析
                if (empty($layoutParams)) {
                    // 获取布局文件的完整路径
                    $layoutFilePath = LayoutPathResolver::getLayoutFilePath($resolvedLayoutPath, $theme, $area);
                    if ($layoutFilePath && is_file($layoutFilePath)) {
                        // 使用 ComponentMetaParser 从文件解析参数定义
                        $parsedMeta = \Weline\Theme\Helper\ComponentMetaParser::parse($layoutFilePath);
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
                                $layoutParams[$paramName] = $defaultValue;
                            }
                        }
                    }
                }
                
                // 确保即使没有参数，也至少设置一个空的 meta 数组，避免模板中访问 meta 时出错
                if (empty($layoutParams)) {
                    $layoutParams = [];
                }
                // 将所有参数统一设置到 meta 数组中（供模板使用 {{meta.参数}} 语法）
                $existingMeta = $template->getData('meta') ?? [];
                if(empty($existingMeta)){
                    $metaData = array_merge($existingMeta, $layoutParams);
                }else{
                    $metaData = $layoutParams;
                }
                // 关于主题的元数据传递给模板数据
                ThemeData::performanceLoad();
                // 注意：必须使用 getMeta() 而不是 get()
                // get() 方法用于获取 .value 格式的配置值，对于非 .value 格式会调用 MetaData::get()
                // MetaData::get() 会返回 MetaData 对象，创建对象时会进行数据库查询，可能导致阻塞
                // getMeta() 方法从性能缓存中读取，不会触发额外的数据库查询
                $themeMetaDataObj = ThemeData::getMeta("theme.{$area}.layouts.{$layoutType}");
                if ($themeMetaDataObj && !empty($themeMetaDataObj['meta_data'])) {
                    // 合并 meta_data 中的配置值到 metaData
                    $metaData = array_merge($metaData, $themeMetaDataObj['meta_data']);
                }
                
                // 将 meta 数据设置到模板中（转义处理由模板自行决定）
                $template->setData('meta', $metaData);
                
                // 如果控制器没有设置标题，则从 meta 中获取默认标题并设置
                if (!$template->getData('title') && !empty($metaData['title'])) {
                    $template->assign('title', $metaData['title']);
                }
            }
            // 如果布局模板不存在，保持原路径（回退机制），但布局信息已设置到 theme 对象中
        } catch (\Exception $e) {
            // 如果出现异常，至少设置基本的主题数据（包括主题对象和默认布局信息）
            // 确保模板可以正常使用主题数据
            if (empty($layoutType)) {
                $layoutType = 'default';
            }
            if (empty($layoutOption)) {
                $layoutOption = 'default';
            }
            $themeData = [
                'area' => $area,
                'colorMode' => $welineThemeColorMode,
                'layoutType' => $layoutType,
                'layoutOption' => $layoutOption,
                'theme' => $theme, // 主题对象本身，供模板直接使用
            ];
            $template->setData('theme', $themeData);
            // 保持原路径，不影响原有功能
            return;
        }
    }

}

