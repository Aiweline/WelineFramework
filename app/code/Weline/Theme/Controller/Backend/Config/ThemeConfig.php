<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Controller\Backend\Config;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Url;
use Weline\Meta\Model\MetaConfig;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题配置控制器 - 卡片式界面
 * 
 * 不使用 meta-manager 标签，直接根据元数据渲染卡片式配置界面
 */
class ThemeConfig extends BackendController
{
    private const DEFAULT_SCOPE = 'default';

    /**
     * 主题配置编辑页面
     */
    public function getIndex()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', self::DEFAULT_SCOPE);
        
        // 获取所有主题列表
        /** @var WelineTheme $themeModel */
        $themeModel = ObjectManager::getInstance(WelineTheme::class);
        $themes = $themeModel->select()->fetch()->getItems();
        
        // 如果有 theme_id，加载主题
        $theme = null;
        if ($themeId) {
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->load($themeId);
            if (!$theme->getId()) {
                $theme = null;
            }
        }
        
        // 生成预览URL
        $previewUrlFrontend = null;
        $previewUrlBackend = null;
        
        if ($theme) {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            
            $previewUrlFrontend = $url->getBackendUrl('theme/backend/index/preview', [
                'theme_id' => $theme->getId(),
                'area' => 'frontend'
            ]);
            
            $previewUrlBackend = $url->getBackendUrl('theme/backend/index/preview', [
                'theme_id' => $theme->getId(),
                'area' => 'backend'
            ]);
        }
        
        // 获取可用的 scope 选项
        $scopeOptions = $this->getScopeOptions($theme);

        $this->assign('themes', $themes);
        $this->assign('theme', $theme);
        $this->assign('area', $area);
        $this->assign('scope', $scope);
        $this->assign('scopeOptions', []); // 这里可以加载可用scope列表
        $this->assign('previewUrlFrontend', $previewUrlFrontend);
        $this->assign('previewUrlBackend', $previewUrlBackend);

        // 使用新的可视化编辑器模板
        return $this->fetch('Weline_Theme::templates/backend/config/visual-editor.phtml');
    }

    /**
     * 获取所有配置选项（AJAX）
     * 返回按类型分组的布局、部件、色系、变量等
     */
    public function getOptions()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', self::DEFAULT_SCOPE);
        
        if (!$themeId) {
            return $this->fetchJson(['code' => 400, 'msg' => __('请选择主题')]);
        }
        
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson(['code' => 404, 'msg' => __('主题不存在')]);
        }
        
        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        // 获取所有可用选项（按类型分组）
        $layouts = LayoutScanner::scanLayouts($theme, $area);
        $partials = LayoutScanner::scanPartials($theme, $area);
        $colors = LayoutScanner::scanColors($theme, $area);
        $variables = LayoutScanner::scanVariables($theme, $area);
        
        // 获取当前配置
        $layoutConfig = ThemeData::getLayoutConfig($area, $scope);
        $partialsConfig = ThemeData::getPartialsConfig($area, $scope);
        $colorConfig = ThemeData::getColorConfig($area, $scope);
        $variablesConfig = ThemeData::getVariablesConfig($area, $scope);
        
        // 格式化数据，补充 meta 信息
        $formattedLayouts = $this->formatLayoutsWithMeta($theme, $area, $layouts);
        $formattedPartials = $this->formatPartialsWithMeta($theme, $area, $partials);
        $formattedColors = $this->formatColorsWithMeta($colors);
        $formattedVariables = $this->formatVariablesWithMeta($variables);
        
        return $this->fetchJson([
            'code' => 200,
            'data' => [
                'layouts' => $formattedLayouts,
                'partials' => $formattedPartials,
                'colors' => $formattedColors,
                'variables' => $formattedVariables,
                'config' => [
                    'layouts' => $layoutConfig,
                    'partials' => $partialsConfig,
                    'colors' => $colorConfig,
                    'variables' => $variablesConfig,
                ]
            ]
        ]);
    }

    /**
     * 获取单个文件的参数配置（用于齿轮按钮）
     */
    /**
     * 获取单个文件的参数配置（用于齿轮按钮）
     */
    public function getFileParams()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $type = $this->request->getParam('type'); // layouts, partials
        $category = $this->request->getParam('category'); // default, account, header 等
        $value = $this->request->getParam('value'); // 文件名（不含扩展名）
        
        if (!$themeId || !$type || !$category) {
            return $this->fetchJson(['code' => 400, 'msg' => __('参数不完整')]);
        }
        
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson(['code' => 404, 'msg' => __('主题不存在')]);
        }
        
        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        // 构建 meta_identify
        // 如果 value 为空，则可能是全局配置（如 colors, variables），使用 type.category
        if (empty($value)) {
            $metaIdentify = "{$type}.{$category}";
        } else {
            $metaIdentify = "{$type}.{$category}.{$value}";
        }
        
        // 获取 meta 数据
        $metaDataArr = ThemeData::getMeta($metaIdentify);
        $metaData = [];
        $params = [];
        
        if ($metaDataArr) {
            $metaData = $metaDataArr['meta_data'] ?? [];
            $setting = $metaDataArr['setting'] ?? [];
            $params = $setting['param'] ?? [];
        }
        
        // 如果 Meta 中没有，尝试从文件解析
        if (empty($params) && !empty($value)) {
            $filePath = $this->resolveFilePath($theme, $area, $type, $category, $value);
            if ($filePath && is_file($filePath)) {
                $parsed = ComponentMetaParser::parse($filePath);
                if (!empty($parsed['params'])) {
                    $params = $this->formatParsedParams($parsed['params']);
                }
            }
        }
        
        // 如果是全局配置（value为空），尝试从目录元数据获取参数（待定逻辑）
        // 这里暂时假设全局配置已经写入了 meta 表
        
        // 读取当前配置值
        $config = [];
        if ($params) {
            foreach ($params as $paramKey => $paramMeta) {
                $paramIdentify = "{$metaIdentify}.param.{$paramKey}.value";
                $defaultValue = $paramMeta['default'] ?? null;
                $paramValue = ThemeData::get($paramIdentify, $defaultValue);
                $config[$paramKey] = $paramValue;
            }
        }
        
        return $this->fetchJson([
            'code' => 200,
            'data' => [
                'meta' => $metaData,
                'params' => $params,
                'config' => $config,
                'meta_identify' => $metaIdentify,
                'value' => $value // 返回原 value 以便前端判断
            ]
        ]);
    }

    /**
     * 保存文件参数配置
     */
    public function postSaveFileParams()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $scope = $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $locale = $this->request->getPost('locale');
        $metaIdentify = $this->request->getPost('meta_identify');
        $params = $this->request->getPost('params', []);
        $action = $this->request->getPost('action');
        
        if (!$themeId || !$metaIdentify) {
            return $this->fetchJson(['code' => 400, 'msg' => __('参数不完整')]);
        }
        
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson(['code' => 404, 'msg' => __('主题不存在')]);
        }
        
        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        try {
            // 处理重置操作
            if ($action === 'reset') {
                $paramKey = $this->request->getPost('param_key');
                if (!$paramKey) {
                    return $this->fetchJson(['code' => 400, 'msg' => __('重置操作缺少参数键')]);
                }
                ThemeData::deleteParamValue($metaIdentify, $paramKey, $scope, $locale);
                return $this->fetchJson(['code' => 200, 'msg' => __('重置成功')]);
            }

            // 保存每个参数
            foreach ($params as $paramKey => $paramValue) {
                // 如果参数包含 translatable 定义，需要处理 i18n
                // 这里 setParamValues 内部会自动判断 translatable
                ThemeData::setParamValues($metaIdentify, [$paramKey => $paramValue], $scope, $locale);
            }
            
            // 清除缓存
            ThemeData::clearCache();
            
            return $this->fetchJson(['code' => 200, 'msg' => __('保存成功')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => __('保存失败：') . $e->getMessage()]);
        }
    }

    /**
     * 同步后台主题模式
     */
    public function postSyncThemeMode()
    {
        $mode = $this->request->getPost('mode');
        if (!in_array($mode, ['light', 'dark'])) {
             return $this->fetchJson(['code' => 400, 'msg' => __('无效的模式')]);
        }

        try {
            /** @var \Weline\Backend\Block\ThemeConfig $themeConfigBlock */
            $themeConfigBlock = ObjectManager::getInstance(\Weline\Backend\Block\ThemeConfig::class);
            if (method_exists($themeConfigBlock, '__init')) {
                $themeConfigBlock->__init();
            }

            // 更新 theme-mode-switch
            $themeConfigBlock->setThemeConfig('theme-mode-switch', $mode);

            // 更新 layouts 相关配置 (仿照 app.js 逻辑)
            $layouts = $themeConfigBlock->getThemeConfig('layouts') ?: [];
            $layouts['data-topbar'] = $mode;
            $layouts['data-sidebar'] = $mode;
            $layouts['data-theme-mode'] = $mode;
            $layouts['data-layout-mode'] = $mode;
            
            $themeConfigBlock->setThemeConfig('layouts', $layouts);

            return $this->fetchJson(['code' => 200, 'msg' => __('同步成功')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => __('同步失败：') . $e->getMessage()]);
        }
    }

    /**
     * 保存布局/部件选择
     */
    public function postSaveSelection()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $scope = $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $type = $this->request->getPost('type'); // layouts, partials, colors, variables
        $category = $this->request->getPost('category'); // default, account, header 等
        $value = $this->request->getPost('value');
        
        if (!$themeId || !$type || !$value) {
            return $this->fetchJson(['code' => 400, 'msg' => __('参数不完整')]);
        }
        
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson(['code' => 404, 'msg' => __('主题不存在')]);
        }
        
        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        try {
            // 标准化类型名称（确保是复数形式）
            // layout -> layouts, partial -> partials, component -> components
            // colors 和 variables 已经是复数形式
            $typeForStorage = $type;
            if (!str_ends_with($type, 's')) {
                $typeForStorage = $type . 's';
            }
            
            // 构建配置键和 meta_identify
            $namespace = "theme.{$area}";
            
            // 对于 layouts 和 partials，category 是必需的
            if (in_array($typeForStorage, ['layouts', 'partials'])) {
                // category 不能为空
                if (empty($category)) {
                    return $this->fetchJson(['code' => 400, 'msg' => __('参数不完整：category 不能为空')]);
                }
                $configKey = "{$typeForStorage}.{$category}.value";
                $baseIdentify = "theme.{$area}.{$typeForStorage}.{$category}";
            } else {
                // colors 和 variables 不需要 category
                $configKey = "{$typeForStorage}.value";
                $baseIdentify = "theme.{$area}.{$typeForStorage}";
            }
            
            // 使用 MetaConfig::setConfig() 方法保存（这是正确的方式）
            /** @var MetaConfig $metaConfig */
            $metaConfig = ObjectManager::getInstance(MetaConfig::class);
            
            // 直接使用 ThemeData::set() 方法保存，它会自动处理所有逻辑
            // ThemeData::set() 内部会使用 MetaData::set()，最终调用 MetaConfig 保存
            // identify 格式：{type}.{category}.value 或 {type}.value
            $identify = $configKey; // 例如：partials.header.value 或 colors.value
            
            $success = ThemeData::set($identify, (string)$value, $scope);
            
            if (!$success) {
                // 如果 ThemeData::set() 失败，尝试直接使用 MetaConfig::setConfig()
                try {
                    // 尝试查找对应的 Meta 记录以获取 meta_id
                    $metaId = null;
                    $metaIdentify = null;
                    
                    try {
                        /** @var \Weline\Meta\Model\Meta $metaModel */
                        $metaModel = ObjectManager::getInstance(\Weline\Meta\Model\Meta::class);
                        
                        // 构建 Meta 表中的 meta_identify（不包含 theme.{area} 前缀）
                        if (in_array($typeForStorage, ['layouts', 'partials'])) {
                            $metaIdentifyForQuery = "{$typeForStorage}.{$category}.{$value}";
                        } else {
                            $metaIdentifyForQuery = "{$typeForStorage}.{$value}";
                        }
                        
                        $meta = $metaModel->reset()
                            ->where(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY, $metaIdentifyForQuery)
                            ->where(\Weline\Meta\Model\Meta::schema_fields_NAMESPACE, 'theme')
                            ->where(\Weline\Meta\Model\Meta::schema_fields_META_TYPE, $typeForStorage)
                            ->where(\Weline\Meta\Model\Meta::schema_fields_AREA, $area)
                            ->find()
                            ->fetch();
                        
                        if ($meta && $meta->getId()) {
                            $metaId = (int)$meta->getId();
                            $metaIdentify = $meta->getData(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY);
                        } else {
                            $metaIdentify = $baseIdentify;
                        }
                    } catch (\Exception $e) {
                        $metaIdentify = $baseIdentify;
                    }
                    
                    // 使用 setConfig 方法保存
                    /** @var \Weline\Theme\Service\PreviewThemeScopeService $previewThemeScopeService */
                    $previewThemeScopeService = ObjectManager::getInstance(\Weline\Theme\Service\PreviewThemeScopeService::class);
                    $effectiveScope = $previewThemeScopeService->resolveEffectiveScope((int)$themeId, $area, $scope);
                    $metaConfig->setConfig(
                        (string)$themeId,
                        $namespace,
                        $configKey,
                        (string)$value,
                        $effectiveScope,
                        null,
                        $metaId,
                        $metaIdentify
                    );
                } catch (\Exception $e) {
                    return $this->fetchJson(['code' => 500, 'msg' => __('保存失败：') . $e->getMessage()]);
                }
            }
            
            // 清除缓存
            ThemeData::clearCache();
            
            return $this->fetchJson(['code' => 200, 'msg' => __('保存成功')]);
        } catch (\Exception $e) {
            // 记录错误日志
            try {
                $logger = \Weline\Framework\App\Env::getInstance()->getLogger();
                if ($logger) {
                    $logger->error('postSaveSelection 保存失败', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'type' => $type,
                        'category' => $category ?? '',
                        'value' => $value,
                    ]);
                }
            } catch (\Exception $logError) {
                // 忽略日志错误
            }
            
            return $this->fetchJson(['code' => 500, 'msg' => __('保存失败：') . $e->getMessage()]);
        }
    }

    /**
     * 格式化布局数据，补充 meta 信息
     */
    private function formatLayoutsWithMeta(WelineTheme $theme, string $area, array $layouts): array
    {
        $result = [];
        
        foreach ($layouts as $layoutType => $options) {
            $result[$layoutType] = [];
            
            foreach ($options as $option) {
                $value = $option['value'] ?? '';
                $file = $option['file'] ?? '';
                $meta = $option['meta'] ?? [];
                
                // 如果 meta 中没有 name，尝试从 ThemeData 获取
                if (empty($meta['name'])) {
                    $metaIdentify = "layouts.{$layoutType}.{$value}";
                    $metaDataArr = ThemeData::getMeta($metaIdentify);
                    if ($metaDataArr && !empty($metaDataArr['meta_data'])) {
                        $meta = array_merge($meta, $metaDataArr['meta_data']);
                    }
                }
                
                $result[$layoutType][] = [
                    'value' => $value,
                    'label' => $meta['name'] ?? $value,
                    'description' => $meta['description'] ?? '',
                    'preview' => $meta['preview'] ?? null,
                    'meta' => $meta,
                    'file' => $file,
                ];
            }
        }
        
        return $result;
    }

    /**
     * 格式化部件数据，补充 meta 信息
     */
    private function formatPartialsWithMeta(WelineTheme $theme, string $area, array $partials): array
    {
        $result = [];
        
        foreach ($partials as $partialType => $options) {
            $result[$partialType] = [];
            
            foreach ($options as $option) {
                $value = $option['value'] ?? '';
                $file = $option['file'] ?? '';
                $meta = $option['meta'] ?? [];
                
                // 如果 meta 中没有 name，尝试从 ThemeData 获取
                if (empty($meta['name'])) {
                    $metaIdentify = "partials.{$partialType}.{$value}";
                    $metaDataArr = ThemeData::getMeta($metaIdentify);
                    if ($metaDataArr && !empty($metaDataArr['meta_data'])) {
                        $meta = array_merge($meta, $metaDataArr['meta_data']);
                    }
                }
                
                $result[$partialType][] = [
                    'value' => $value,
                    'label' => $meta['name'] ?? $value,
                    'description' => $meta['description'] ?? '',
                    'preview' => $meta['preview'] ?? null,
                    'meta' => $meta,
                    'file' => $file,
                ];
            }
        }
        
        return $result;
    }

    /**
     * 格式化色系数据
     */
    private function formatColorsWithMeta(array $colors): array
    {
        $result = [];
        
        foreach ($colors as $color) {
            $value = $color['value'] ?? '';
            $meta = $color['meta'] ?? [];
            
            $result[] = [
                'value' => $value,
                'label' => $meta['name'] ?? $value,
                'description' => $meta['description'] ?? '',
                'colors' => $meta['colors'] ?? [],
            ];
        }
        
        return $result;
    }

    /**
     * 格式化变量数据
     */
    private function formatVariablesWithMeta(array $variables): array
    {
        $result = [];
        
        foreach ($variables as $variable) {
            $value = $variable['value'] ?? '';
            $meta = $variable['meta'] ?? [];
            
            $result[] = [
                'value' => $value,
                'label' => $meta['name'] ?? $value,
                'description' => $meta['description'] ?? '',
            ];
        }
        
        return $result;
    }

    /**
     * 解析文件路径
     */
    private function resolveFilePath(WelineTheme $theme, string $area, string $type, string $category, string $value): ?string
    {
        $themePath = $theme->getPath();
        if (empty($themePath)) {
            return null;
        }
        
        $relativePath = "view/theme/{$area}/{$type}/{$category}/{$value}.phtml";
        $fullPath = rtrim($themePath, DS) . DS . str_replace('/', DS, $relativePath);
        
        if (is_file($fullPath)) {
            return $fullPath;
        }
        
        // 尝试父主题
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            return $this->resolveFilePath($parentTheme, $area, $type, $category, $value);
        }
        
        return null;
    }

    /**
     * 格式化解析的参数
     */
    private function formatParsedParams(array $parsedParams): array
    {
        $result = [];
        foreach ($parsedParams as $param) {
            $key = $param['name'] ?? '';
            if ($key === '') {
                continue;
            }
            $result[$key] = [
                'name' => $param['name_label'] ?? $key,
                'description' => $param['description'] ?? '',
                'default' => $param['default'] ?? '',
                'type' => $param['type'] ?? 'text',
                'required' => (bool)($param['required'] ?? false),
            ];
        }
        return $result;
    }

    /**
     * 获取可用的 scope 选项
     */
    private function getScopeOptions(?WelineTheme $theme): array
    {
        $options = [
            'frontend' => ['default'],
            'backend' => ['default'],
        ];
        
        return $options;
    }
}
