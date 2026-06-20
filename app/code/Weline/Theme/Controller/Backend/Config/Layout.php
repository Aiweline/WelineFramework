<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Controller\Backend\Config;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Url;
use Weline\Meta\Model\Meta;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Helper\ThemeConfigManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\PreviewManager;
use Weline\Theme\Helper\PreviewAccountManager;
use Weline\Theme\Helper\MetaTranslation;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\CssVariableParser;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeLayoutVersionService;

/**
 * 主题布局配置控制器
 */
class Layout extends BackendController
{
    private const DEFAULT_SCOPE = 'default';
    private const THEME_AREA_FRONTEND = 'frontend';
    private const THEME_AREA_BACKEND = 'backend';

    /** @var array<string, array> 缓存 ThemeData 元信息池，避免重复查询 */
    private array $metaPoolCache = [];

    private ?string $defaultThemeBasePath = null;
    /**
     * 获取主题的布局配置页面
     */
    public function getIndex()
    {
        $themeId = $this->request->getParam('theme_id');
        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        [$frontendArea, $frontendScope] = $this->resolveAreaAndScope('frontend', $this->request->getParam('scope_frontend'));
        [$backendArea, $backendScope] = $this->resolveAreaAndScope('backend', $this->request->getParam('scope_backend'));

        // 优先通过 ThemeData 获取配置选项，必要时回退到扫描结果
        $frontendLayouts = $this->getAvailableLayouts($theme, 'frontend');
        $backendLayouts = $this->getAvailableLayouts($theme, 'backend');
        $frontendColors = $this->getAvailableColors($theme, 'frontend');
        $backendColors = $this->getAvailableColors($theme, 'backend');
        $frontendVariables = $this->getAvailableVariables($theme, 'frontend');
        $backendVariables = $this->getAvailableVariables($theme, 'backend');
        $frontendComponents = $this->getAvailableComponents($theme, 'frontend');
        $backendComponents = $this->getAvailableComponents($theme, 'backend');
        $frontendPartials = $this->getAvailablePartials($theme, 'frontend');
        $backendPartials = $this->getAvailablePartials($theme, 'backend');
        
        // 从 ThemeData 读取元数据并补充 i18n 翻译
        $this->enrichWithThemeData($theme, 'frontend', $frontendLayouts, $frontendColors, $frontendVariables, $frontendComponents, $frontendPartials);
        $this->enrichWithThemeData($theme, 'backend', $backendLayouts, $backendColors, $backendVariables, $backendComponents, $backendPartials);
        
        // 获取当前配置（优先使用 MetaConfig，回退到旧配置）
        $frontendLayoutConfig = $this->getLayoutConfigFromMeta($theme, $frontendArea, $frontendScope) 
            ?? LayoutScanner::getLayoutConfig($theme, $frontendArea, $frontendScope);
        $backendLayoutConfig = $this->getLayoutConfigFromMeta($theme, $backendArea, $backendScope) 
            ?? LayoutScanner::getLayoutConfig($theme, $backendArea, $backendScope);

        $frontendColorConfig = $this->getColorConfigFromMeta($theme, $frontendArea, $frontendScope)
            ?? LayoutScanner::getColorConfig($theme, $frontendArea, $frontendScope);
        $backendColorConfig = $this->getColorConfigFromMeta($theme, $backendArea, $backendScope)
            ?? LayoutScanner::getColorConfig($theme, $backendArea, $backendScope);

        $frontendPartialsConfig = $this->getResolvedPartialsConfig($theme, $frontendArea, $frontendScope);
        $backendPartialsConfig = $this->getResolvedPartialsConfig($theme, $backendArea, $backendScope);

        $frontendVariablesConfig = $this->getVariablesConfigFromMeta($theme, $frontendArea, $frontendScope)
            ?? LayoutScanner::getVariablesConfig($theme, $frontendArea, $frontendScope);
        $backendVariablesConfig = $this->getVariablesConfigFromMeta($theme, $backendArea, $backendScope)
            ?? LayoutScanner::getVariablesConfig($theme, $backendArea, $backendScope);

        $this->assign('theme', $theme);
        $this->assign('frontendLayouts', $frontendLayouts);
        $this->assign('backendLayouts', $backendLayouts);
        $this->assign('frontendColors', $frontendColors);
        $this->assign('backendColors', $backendColors);
        $this->assign('frontendVariables', $frontendVariables);
        $this->assign('backendVariables', $backendVariables);
        $this->assign('frontendComponents', $frontendComponents);
        $this->assign('backendComponents', $backendComponents);
        $this->assign('frontendPartials', $frontendPartials);
        $this->assign('backendPartials', $backendPartials);
        $this->assign('frontendLayoutConfig', $frontendLayoutConfig);
        $this->assign('backendLayoutConfig', $backendLayoutConfig);
        $this->assign('frontendColorConfig', $frontendColorConfig);
        $this->assign('backendColorConfig', $backendColorConfig);
        $this->assign('frontendPartialsConfig', $frontendPartialsConfig);
        $this->assign('backendPartialsConfig', $backendPartialsConfig);
        $this->assign('frontendVariablesConfig', $frontendVariablesConfig);
        $this->assign('backendVariablesConfig', $backendVariablesConfig);

        return $this->fetch('Weline_Theme::templates/backend/config/layout.phtml');
    }

    /**
     * 获取布局配置数据（用于modal异步加载）
     */
    public function getConfigData()
    {
        $themeId = $this->request->getParam('theme_id');
        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        [$frontendArea, $frontendScope] = $this->resolveAreaAndScope('frontend', $this->request->getParam('scope_frontend'));
        [$backendArea, $backendScope] = $this->resolveAreaAndScope('backend', $this->request->getParam('scope_backend'));

        $frontendLayouts = $this->getAvailableLayouts($theme, 'frontend');
        $backendLayouts = $this->getAvailableLayouts($theme, 'backend');
        $frontendColors = $this->getAvailableColors($theme, 'frontend');
        $backendColors = $this->getAvailableColors($theme, 'backend');
        $frontendVariables = $this->getAvailableVariables($theme, 'frontend');
        $backendVariables = $this->getAvailableVariables($theme, 'backend');
        $frontendComponents = $this->getAvailableComponents($theme, 'frontend');
        $backendComponents = $this->getAvailableComponents($theme, 'backend');
        $frontendPartials = $this->getAvailablePartials($theme, 'frontend');
        $backendPartials = $this->getAvailablePartials($theme, 'backend');
        // 从 ThemeData 读取元数据并补充 i18n 翻译
        $this->enrichWithThemeData($theme, 'frontend', $frontendLayouts, $frontendColors, $frontendVariables, $frontendComponents, $frontendPartials);
        $this->enrichWithThemeData($theme, 'backend', $backendLayouts, $backendColors, $backendVariables, $backendComponents, $backendPartials);
        
        // 获取当前配置（优先使用 MetaConfig，回退到旧配置）
        $frontendLayoutConfig = $this->getLayoutConfigFromMeta($theme, $frontendArea, $frontendScope) 
            ?? LayoutScanner::getLayoutConfig($theme, $frontendArea, $frontendScope);
        $backendLayoutConfig = $this->getLayoutConfigFromMeta($theme, $backendArea, $backendScope) 
            ?? LayoutScanner::getLayoutConfig($theme, $backendArea, $backendScope);

        $frontendColorConfig = $this->getColorConfigFromMeta($theme, $frontendArea, $frontendScope)
            ?? LayoutScanner::getColorConfig($theme, $frontendArea, $frontendScope);
        $backendColorConfig = $this->getColorConfigFromMeta($theme, $backendArea, $backendScope)
            ?? LayoutScanner::getColorConfig($theme, $backendArea, $backendScope);

        $frontendPartialsConfig = $this->getResolvedPartialsConfig($theme, $frontendArea, $frontendScope);
        $backendPartialsConfig = $this->getResolvedPartialsConfig($theme, $backendArea, $backendScope);

        $frontendVariablesConfig = $this->getVariablesConfigFromMeta($theme, $frontendArea, $frontendScope)
            ?? LayoutScanner::getVariablesConfig($theme, $frontendArea, $frontendScope);
        $backendVariablesConfig = $this->getVariablesConfigFromMeta($theme, $backendArea, $backendScope)
            ?? LayoutScanner::getVariablesConfig($theme, $backendArea, $backendScope);
        
        // 如果布局配置为空，使用默认的主题布局配置（使用扫描到的第一个选项）
        if (empty($frontendLayoutConfig) && !empty($frontendLayouts)) {
            foreach ($frontendLayouts as $layoutType => $options) {
                if (!empty($options) && is_array($options[0])) {
                    $frontendLayoutConfig[$layoutType] = $options[0]['value']; // 使用第一个选项的值作为默认值
                } elseif (!empty($options)) {
                    $frontendLayoutConfig[$layoutType] = $options[0]; // 兼容旧格式
                }
            }
        }
        
        if (empty($backendLayoutConfig) && !empty($backendLayouts)) {
            foreach ($backendLayouts as $layoutType => $options) {
                if (!empty($options) && is_array($options[0])) {
                    $backendLayoutConfig[$layoutType] = $options[0]['value']; // 使用第一个选项的值作为默认值
                } elseif (!empty($options)) {
                    $backendLayoutConfig[$layoutType] = $options[0]; // 兼容旧格式
                }
            }
        }

        return $this->fetchJson($this->success('', [
            'theme' => [
                'id' => $theme->getId(),
                'name' => $theme->getName(),
            ],
            'frontendLayouts' => $frontendLayouts,
            'backendLayouts' => $backendLayouts,
            'frontendColors' => $frontendColors,
            'backendColors' => $backendColors,
            'frontendVariables' => $frontendVariables,
            'backendVariables' => $backendVariables,
            'frontendComponents' => $frontendComponents,
            'backendComponents' => $backendComponents,
            'frontendPartials' => $frontendPartials,
            'backendPartials' => $backendPartials,
            'frontendLayoutConfig' => $frontendLayoutConfig,
            'backendLayoutConfig' => $backendLayoutConfig,
            'frontendColorConfig' => $frontendColorConfig,
            'backendColorConfig' => $backendColorConfig,
            'frontendPartialsConfig' => $frontendPartialsConfig,
            'backendPartialsConfig' => $backendPartialsConfig,
            'frontendVariablesConfig' => $frontendVariablesConfig,
            'backendVariablesConfig' => $backendVariablesConfig,
            'frontendScope' => $this->formatScopePath($frontendArea, $frontendScope),
            'backendScope' => $this->formatScopePath($backendArea, $backendScope),
            'scopeOptions' => [
                'frontend' => $this->formatScopeList($frontendArea, ThemeConfigManager::getScopes($theme, $frontendArea)),
                'backend' => $this->formatScopeList($backendArea, ThemeConfigManager::getScopes($theme, $backendArea)),
            ],
        ]));
    }

    /**
     * 主题编辑页面（独立页面，非弹窗）
     */
    public function getEdit()
    {
        $themeId = $this->request->getParam('theme_id');
        $area    = $this->request->getParam('area', 'frontend');
        $scope   = $this->request->getParam('scope', self::DEFAULT_SCOPE);

        // 获取所有主题列表
        /** @var WelineTheme $themeModel */
        $themeModel = ObjectManager::getInstance(WelineTheme::class);
        $themes = $themeModel->select()->fetch()->getItems();

        // 尝试获取当前激活主题
        $activeTheme = null;
        foreach ($themes as $item) {
            if ((int)($item['is_active'] ?? 0) === 1) {
                $activeTheme = $item;
                break;
            }
        }

        $theme = null;
        if ($themeId) {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->load($themeId);
            
            if (!$theme->getId()) {
                $theme = null;
            }
        }

        // 如果没有显式选择主题，则默认使用当前激活主题
        if (!$theme && $activeTheme) {
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->load($activeTheme['id']);
        }

        // 仍然没有则退回到列表中的第一个
        if (!$theme && count($themes) > 0) {
            $first = reset($themes);
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->load($first['id']);
        }

        $area  = strtolower($area) ?: 'frontend';
        $scope = $scope ?: self::DEFAULT_SCOPE;

        // 生成预览URL
        $previewUrlFrontend = null;
        $previewUrlBackend = null;
        if ($theme) {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            
            $previewUrlFrontend = $url->getBackendUrl('theme/backend/index/preview', [
                'theme_id' => $theme->getId(),
                'area' => 'frontend',
                'auto_login' => '1'
            ]);
            
            $previewUrlBackend = $url->getBackendUrl('theme/backend/index/preview', [
                'theme_id' => $theme->getId(),
                'area' => 'backend'
            ]);
        }

        // Check theme mode from Backend Config
        /** @var \Weline\Backend\Block\ThemeConfig $themeConfigBlock */
        $themeConfigBlock = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Backend\Block\ThemeConfig::class);
        // Ensure init is called to load user config
        if (method_exists($themeConfigBlock, '__init')) {
            $themeConfigBlock->__init();
        }
        
        $backendThemeMode = $themeConfigBlock->getThemeConfig('theme-mode-switch');
        $themeMode = $backendThemeMode ?: 'light';

        $this->assign('themes', $themes);
        $this->assign('theme', $theme);
        $this->assign('area', $area);
        $this->assign('scope', $scope);
        $this->assign('scopeOptions', []);
        $this->assign('previewUrlFrontend', $previewUrlFrontend);
        $this->assign('previewUrlBackend', $previewUrlBackend);
        $this->assign('themeMode', $themeMode);

        // 使用新的可视化编辑器模板
        return $this->fetch('Weline_Theme::templates/backend/config/visual-editor.phtml');
    }

    /**
     * 主题 Meta 配置片段（用于布局弹窗中的 iframe）
     */
    public function getMeta()
    {
        $themeId = $this->request->getParam('theme_id');
        $area    = $this->request->getParam('area', 'backend');
        $scope   = $this->request->getParam('scope', self::DEFAULT_SCOPE);

        if (!$themeId) {
            return $this->error(__('请选择主题'));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->error(__('主题不存在'));
        }

        $area  = strtolower($area) ?: 'backend';
        $scope = $scope ?: self::DEFAULT_SCOPE;

        $this->assign('theme', $theme);
        $this->assign('area', $area);
        $this->assign('scope', $scope);

        return $this->fetch('Weline_Theme::templates/backend/config/theme-meta-manager.phtml');
    }

    /**
     * 保存主题配置
     */
    public function save()
    {
        $themeId = $this->request->getParam('theme_id');
        $type = $this->request->getParam('type');
        $category = $this->request->getParam('category');
        $value = $this->request->getParam('value');
        $key = $this->request->getParam('key');
        $action = $this->request->getParam('action');
        $params = $this->request->getParam('params', []);

        /** @var \Weline\Theme\Helper\ThemeData $themeData */
        $themeData = $this->container->get(\Weline\Theme\Helper\ThemeData::class);
        $theme = $themeData->getTheme($themeId);

        if (!$theme) {
            return $this->json(['code' => 400, 'msg' => '主题未找到']);
        }

        try {
            if ($action === 'reset' && $key) {
                // Delete specific parameter configuration
                $themeData->deleteParamValue($theme, $type, $category, $value, $key, $this->request->getParam('locale'));
                return $this->json(['code' => 200, 'msg' => '已重置配置']);
            }

            // Standard save logic
            if (!empty($params)) {
                $themeData->setParamValues($theme, $type, $category, $value, $params, $this->request->getParam('locale'));
            }
            
            return $this->json(['code' => 200, 'msg' => '保存成功']);
        } catch (\Exception $e) {
            return $this->json(['code' => 500, 'msg' => '保存失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 保存布局配置
     */
    public function postSave()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend'); // frontend 或 backend
        $layouts = $this->request->getPost('layouts', []); // ['account' => 'auth', 'default' => 'default', ...]

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        [$area, $scope] = $this->resolveAreaAndScope($area, $this->request->getPost('scope') ?? $this->request->getPost('scope_' . $area));

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 验证布局选项是否存在
        $availableLayouts = $this->getAvailableLayouts($theme, $area);
        foreach ($layouts as $layoutType => $option) {
            if (!isset($availableLayouts[$layoutType])) {
                return $this->fetchJson($this->error(__('布局类型不存在：%{type}', ['type' => $layoutType])));
            }
            
            // 检查选项是否存在（支持新格式和旧格式）
            $found = false;
            foreach ($availableLayouts[$layoutType] as $layoutOption) {
                if (is_array($layoutOption)) {
                    if ($layoutOption['value'] === $option) {
                        $found = true;
                        break;
                    }
                } else {
                    if ($layoutOption === $option) {
                        $found = true;
                        break;
                    }
                }
            }
            
            if (!$found) {
                return $this->fetchJson($this->error(__('布局选项无效：%{type}/%{option}', ['type' => $layoutType, 'option' => $option])));
            }
        }

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        // 使用 ThemeData 保存配置
        // 保存布局配置（使用 .value 后缀格式）
        if (!empty($layouts)) {
            foreach ($layouts as $layoutType => $layoutOption) {
                // 格式：layouts.{layoutType}.value
                $identify = "layouts.{$layoutType}.value";
                ThemeData::set($identify, $layoutOption, $scope);
            }
        }
        
        // 同时保存到 ThemeConfigManager（保持兼容性）
        $configsToSave = [];
        $areaKey = $this->formatScopePath($area, $scope);
        if (!empty($layouts)) {
            $configsToSave['layouts'][$areaKey] = $layouts;
        }
        
        // 批量保存配置（保持兼容性）
        if (!empty($configsToSave)) {
            ThemeConfigManager::saveConfigs($theme, $configsToSave);
        }

        return $this->fetchJson($this->success(__('配置保存成功')));
    }

    /**
     * 上传主题资源（图片等）
     */
    public function postUpload()
    {
        $themeId = $this->request->getPost('theme_id');
        if (!$themeId) {
            return $this->json(['code' => 400, 'msg' => __('主题ID缺失')]);
        }

        $file = $this->request->getFile('file');
        if (!$file) {
            return $this->json(['code' => 400, 'msg' => __('未发现上传文件')]);
        }

        try {
            // 简单验证
            $allowTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowTypes)) {
                return $this->json(['code' => 400, 'msg' => __('不支持的文件类型')]);
            }

            // 限制大小 (e.g., 5MB)
            if ($file['size'] > 5 * 1024 * 1024) {
                return $this->json(['code' => 400, 'msg' => __('文件太大，请限制在5MB以内')]);
            }

            $mediaDir = PUB . 'media' . DS . 'theme' . DS . 'param' . DS;
            if (!is_dir($mediaDir)) {
                mkdir($mediaDir, 0777, true);
            }

            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newFileName = md5($file['name'] . time()) . '.' . $extension;
            $targetPath = $mediaDir . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $url = '/media/theme/param/' . $newFileName;
                return $this->json(['code' => 200, 'msg' => __('上传成功'), 'url' => $url]);
            } else {
                return $this->json(['code' => 500, 'msg' => __('文件保存失败')]);
            }
        } catch (\Exception $e) {
            return $this->json(['code' => 500, 'msg' => __('上传出错: %{error}', ['error' => $e->getMessage()])]);
        }
    }

    /**
     * 获取目录配置表单
     */
    public function getDirConfigForm()
    {
        $themeId = $this->request->getParam('identity_id') ?: $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', self::DEFAULT_SCOPE);
        $path = $this->request->getParam('path', '');
        $type = $this->request->getParam('type', 'layout'); // 默认为 layout，支持 partial
        
        // 标准化类型名称为复数形式（用于存储和查询）
        // layout -> layouts, partial -> partials, component -> components
        // colors 已经是复数形式，不需要加 s
        $typeForStorage = $type;
        if (!str_ends_with($type, 's')) {
            $typeForStorage = $type . 's';
        }

        if (!$themeId) {
            return $this->error(__('请选择主题'));
        }

        if (empty($path)) {
            return $this->error(__('目录路径不能为空'));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->error(__('主题不存在'));
        }

        [$area, $scope] = $this->resolveAreaAndScope($area, $scope);

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        // 获取当前配置
        $baseIdentify = "dir-config.{$path}";
        $currentConfig = [];
        
        // 根据类型加载已保存的配置
        if ($type === 'variable') {
            // 变量类型：读取所有变量配置
            $variables = [];
            try {
                /** @var \Weline\Meta\Model\MetaConfig $metaConfig */
                $metaConfig = ObjectManager::make(\Weline\Meta\Model\MetaConfig::class);
                $namespace = "theme.{$area}";
                
                // 获取该目录下所有变量的配置
                // 这里先获取所有变量选项，然后在循环中读取每个变量的值
                // 为了简化，我们将在获取 options 后再读取配置值
                $currentConfig['variables'] = [];
            } catch (\Exception $e) {
                // 忽略错误
            }
        } else {
            // 其他类型：读取单个配置值
            // 直接使用 MetaConfig 读取，确保使用正确的 scope
            $configIdentify = "{$typeForStorage}.{$path}.value";
            $currentValue = null;
            
            try {
                // 方法1：通过 ThemeData 读取（会自动处理主题和区域）
                $currentValue = ThemeData::get($configIdentify, null);
                
                // 方法2：如果 ThemeData 读取失败，直接使用 MetaConfig 读取（确保使用正确的 scope）
                if (empty($currentValue)) {
                    /** @var \Weline\Meta\Model\MetaConfig $metaConfig */
                    $metaConfig = ObjectManager::make(\Weline\Meta\Model\MetaConfig::class);
                    $namespace = "theme.{$area}";
                    $configKey = "{$typeForStorage}.{$path}.value";
                    /** @var \Weline\Theme\Service\PreviewThemeScopeService $previewThemeScopeService */
                    $previewThemeScopeService = ObjectManager::make(\Weline\Theme\Service\PreviewThemeScopeService::class);
                    $effectiveScope = $previewThemeScopeService->resolveEffectiveScope((int)$theme->getId(), $area, $scope);
                    $currentValue = $metaConfig->getConfig($theme->getId(), $namespace, $configKey, $effectiveScope);
                }
            } catch (\Exception $e) {
                // 如果读取失败，记录错误但不影响后续流程（如果 logger 可用）
                try {
                    $logger = \Weline\Framework\App\Env::getInstance()->getLogger();
                    if ($logger) {
                        $logger->error('getDirConfigForm: 读取配置失败', [
                            'error' => $e->getMessage(),
                            'themeId' => $theme->getId(),
                            'area' => $area,
                            'scope' => $scope,
                            'path' => $path,
                            'type' => $type
                        ]);
                    }
                } catch (\Exception $logError) {
                    // 忽略日志错误
                }
            }
            
            // 调试：记录读取到的配置（如果 logger 可用）
            try {
                $logger = \Weline\Framework\App\Env::getInstance()->getLogger();
                if ($logger) {
                    $logger->debug('getDirConfigForm', [
                        'themeId' => $theme->getId(),
                        'area' => $area,
                        'scope' => $scope,
                        'path' => $path,
                        'type' => $type,
                        'configIdentify' => $configIdentify,
                        'currentValue' => $currentValue
                    ]);
                }
            } catch (\Exception $e) {
                // 忽略日志错误
            }
            
            if ($currentValue) {
                $currentConfig[$type] = $currentValue;
            }
        }
        
        // 从数据库 Meta 表获取该目录下可用的文件列表（根据类型）
        $options = [];
        
        /** @var \Weline\Meta\Model\Meta $metaModel */
        $metaModel = ObjectManager::make(\Weline\Meta\Model\Meta::class);
        
        // 查询条件：namespace=theme, meta_type={type}, category=path, area=area
        // 对于 color 和 variable 类型，category 可能为空（因为这些目录本身就是类型目录）
        $metasResult = $metaModel->where(\Weline\Meta\Model\Meta::schema_fields_NAMESPACE, 'theme')
                                 ->where(\Weline\Meta\Model\Meta::schema_fields_META_TYPE, $type)
                                 ->where(\Weline\Meta\Model\Meta::schema_fields_AREA, $area);
        
        // 对于 colors 和 variable 类型，category 可能为空（因为这些目录本身就是类型目录）
        // 使用 OR 条件：category IS NULL OR category = '' OR category = path
        if (($type === 'colors' && $path === 'colors') || ($type === 'variable' && $path === 'variables')) {
            $categoryField = \Weline\Meta\Model\Meta::schema_fields_CATEGORY;
            $metasResult->where($categoryField, '', '=', 'OR')
                ->where($categoryField, $path, '=');
        } else {
            $metasResult->where(\Weline\Meta\Model\Meta::schema_fields_CATEGORY, $path);
        }
        
        $metasResult = $metasResult->select()->fetch();
        
        $metas = $metasResult ? $metasResult->getItems() : [];
        
        // 调试：记录查询结果（如果 logger 可用）
        try {
            $logger = \Weline\Framework\App\Env::getInstance()->getLogger();
            if ($logger) {
                $logger->debug('getDirConfigForm: 查询 Meta 结果', [
                    'type' => $type,
                    'path' => $path,
                    'area' => $area,
                    'metas_count' => count($metas),
                    'metas' => array_map(function($meta) {
                        return [
                            'id' => $meta->getId(),
                            'meta_identify' => $meta->getData(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY),
                            'category' => $meta->getData(\Weline\Meta\Model\Meta::schema_fields_CATEGORY),
                            'meta_type' => $meta->getData(\Weline\Meta\Model\Meta::schema_fields_META_TYPE),
                        ];
                    }, $metas)
                ]);
            }
        } catch (\Exception $e) {
            // 忽略日志错误
        }
        
        if (!empty($metas)) {
            foreach ($metas as $meta) {
                // 从 meta_identify 中提取文件名
                // 格式：theme.frontend.{type}s.category.default
                $metaIdentify = $meta->getData(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY);
                $identifyParts = explode('.', $metaIdentify);
                $fileName = end($identifyParts); // 最后一个部分就是文件名
                
                // 解析 meta_data JSON
                $metaData = $meta->getData(\Weline\Meta\Model\Meta::schema_fields_META_DATA);
                $metaDataArray = [];
                if (!empty($metaData)) {
                    $metaDataArray = json_decode($metaData, true) ?: [];
                    // 从 meta_data 中获取 file_name（备用）
                    if (isset($metaDataArray['file_name'])) {
                        $fileName = $metaDataArray['file_name'];
                    }
                }
                
                // 查询该文件的 name 字段
                $nameFieldModel = ObjectManager::make(\Weline\Meta\Model\Meta::class);
                $nameField = $nameFieldModel->where(\Weline\Meta\Model\Meta::schema_fields_NAMESPACE, 'theme')
                                            ->where(\Weline\Meta\Model\Meta::schema_fields_META_TYPE, 'field')
                                            ->where(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY, "theme.{$area}.{$typeForStorage}.{$path}.{$fileName}.name")
                                            ->where(\Weline\Meta\Model\Meta::schema_fields_AREA, $area)
                                            ->find()
                                            ->fetch();
                
                $itemName = '';
                if ($nameField && $nameField->getId()) {
                    $nameMetaData = json_decode($nameField->getData(\Weline\Meta\Model\Meta::schema_fields_META_DATA), true) ?: [];
                    $itemName = $nameMetaData['attributes']['default'] ?? $nameMetaData['attributes']['name'] ?? '';
                }
                
                // 如果 name 字段不存在，尝试查询目录级别的 name（兼容旧数据）
                if (empty($itemName)) {
                    $nameFieldModel2 = ObjectManager::make(\Weline\Meta\Model\Meta::class);
                    $nameField2 = $nameFieldModel2->where(\Weline\Meta\Model\Meta::schema_fields_NAMESPACE, 'theme')
                                                  ->where(\Weline\Meta\Model\Meta::schema_fields_META_TYPE, 'field')
                                                  ->where(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY, "theme.{$area}.{$typeForStorage}.{$path}.name")
                                                  ->where(\Weline\Meta\Model\Meta::schema_fields_AREA, $area)
                                                  ->find()
                                                  ->fetch();
                    
                    if ($nameField2 && $nameField2->getId()) {
                        $nameMetaData2 = json_decode($nameField2->getData(\Weline\Meta\Model\Meta::schema_fields_META_DATA), true) ?: [];
                        $itemName = $nameMetaData2['attributes']['default'] ?? $nameMetaData2['attributes']['name'] ?? '';
                    }
                }
                
                // 如果还是没有，使用默认值
                if (empty($itemName)) {
                    $itemName = ucfirst($fileName);
                }
                
                // 查询该文件的 description 字段
                $descFieldModel = ObjectManager::make(\Weline\Meta\Model\Meta::class);
                $descField = $descFieldModel->where(\Weline\Meta\Model\Meta::schema_fields_NAMESPACE, 'theme')
                                            ->where(\Weline\Meta\Model\Meta::schema_fields_META_TYPE, 'field')
                                            ->where(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY, "theme.{$area}.{$typeForStorage}.{$path}.{$fileName}.description")
                                            ->where(\Weline\Meta\Model\Meta::schema_fields_AREA, $area)
                                            ->find()
                                            ->fetch();
                
                $itemDescription = '';
                if ($descField && $descField->getId()) {
                    $descMetaData = json_decode($descField->getData(\Weline\Meta\Model\Meta::schema_fields_META_DATA), true) ?: [];
                    $itemDescription = $descMetaData['attributes']['default'] ?? $descMetaData['attributes']['name'] ?? '';
                }
                
                // 如果 description 字段不存在，尝试查询目录级别的 description（兼容旧数据）
                if (empty($itemDescription)) {
                    $descFieldModel2 = ObjectManager::make(\Weline\Meta\Model\Meta::class);
                    $descField2 = $descFieldModel2->where(\Weline\Meta\Model\Meta::schema_fields_NAMESPACE, 'theme')
                                                  ->where(\Weline\Meta\Model\Meta::schema_fields_META_TYPE, 'field')
                                                  ->where(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY, "theme.{$area}.{$typeForStorage}.{$path}.description")
                                                  ->where(\Weline\Meta\Model\Meta::schema_fields_AREA, $area)
                                                  ->find()
                                                  ->fetch();
                    
                    if ($descField2 && $descField2->getId()) {
                        $descMetaData2 = json_decode($descField2->getData(\Weline\Meta\Model\Meta::schema_fields_META_DATA), true) ?: [];
                        $itemDescription = $descMetaData2['attributes']['default'] ?? $descMetaData2['attributes']['name'] ?? '';
                    }
                }
                
                $filePath = $meta->getData(\Weline\Meta\Model\Meta::schema_fields_FILE_PATH);
                $fileFullPath = $meta->getData(\Weline\Meta\Model\Meta::schema_fields_FILE_FULL_PATH);
                
                // 对于色系类型，尝试从 meta_data 或 CSS 文件中提取主题标识和主要颜色
                $themeIdentifier = '';
                $primaryColor = '';
                $secondaryColor = '';
                if ($type === 'colors') {
                    // 尝试从 meta_data 中获取 theme 标识
                    if (!empty($metaDataArray) && isset($metaDataArray['theme'])) {
                        $themeIdentifier = $metaDataArray['theme'];
                    }
                    
                    // 尝试从 CSS 文件中读取主要颜色
                    if ($fileFullPath && file_exists($fileFullPath)) {
                        try {
                            $cssContent = file_get_contents($fileFullPath);
                            
                            // 提取 --color-primary（优先）
                            if (preg_match('/--color-primary:\s*([^;]+);/', $cssContent, $matches)) {
                                $primaryColor = trim($matches[1]);
                            } 
                            // 如果没有 --color-primary，尝试 --color-bg-primary
                            if (empty($primaryColor) && preg_match('/--color-bg-primary:\s*([^;]+);/', $cssContent, $matches)) {
                                $primaryColor = trim($matches[1]);
                            }
                            
                            // 提取 --color-bg-secondary（优先）
                            if (preg_match('/--color-bg-secondary:\s*([^;]+);/', $cssContent, $matches)) {
                                $secondaryColor = trim($matches[1]);
                            }
                            // 如果没有 --color-bg-secondary，尝试 --color-text-primary
                            if (empty($secondaryColor) && preg_match('/--color-text-primary:\s*([^;]+);/', $cssContent, $matches)) {
                                $secondaryColor = trim($matches[1]);
                            }
                        } catch (\Exception $e) {
                            // 忽略文件读取错误
                        }
                    }
                    
                    // 如果从 CSS 文件中没有提取到，尝试从 meta_data 中获取
                    if (empty($primaryColor) && !empty($metaDataArray)) {
                        if (isset($metaDataArray['primary_color'])) {
                            $primaryColor = $metaDataArray['primary_color'];
                        }
                    }
                    if (empty($secondaryColor) && !empty($metaDataArray)) {
                        if (isset($metaDataArray['secondary_color'])) {
                            $secondaryColor = $metaDataArray['secondary_color'];
                        }
                    }
                    
                    // 如果还是没有，根据主题标识设置默认颜色
                    if (empty($primaryColor) || empty($secondaryColor)) {
                        if (empty($themeIdentifier) && !empty($metaDataArray) && isset($metaDataArray['theme'])) {
                            $themeIdentifier = $metaDataArray['theme'];
                        }
                        if (empty($themeIdentifier)) {
                            $themeIdentifier = $fileName; // 使用文件名作为标识
                        }
                        
                        switch (strtolower($themeIdentifier)) {
                            case 'light':
                                if (empty($primaryColor)) $primaryColor = '#ffffff';
                                if (empty($secondaryColor)) $secondaryColor = '#f8f9fa';
                                break;
                            case 'dark':
                                if (empty($primaryColor)) $primaryColor = '#1a1a1a';
                                if (empty($secondaryColor)) $secondaryColor = '#2d2d2d';
                                break;
                            case 'amazon':
                                if (empty($primaryColor)) $primaryColor = '#ff9900';
                                if (empty($secondaryColor)) $secondaryColor = '#131921';
                                break;
                            default:
                                if (empty($primaryColor)) $primaryColor = '#0d6efd';
                                if (empty($secondaryColor)) $secondaryColor = '#6c757d';
                        }
                    }
                }
                
                $optionData = [
                    'value' => $fileName,
                    'label' => $itemName,
                    'file' => basename($filePath ?: $fileFullPath),
                    'meta' => [
                        'name' => $itemName,
                        'description' => $itemDescription
                    ]
                ];
                
                // 对于色系类型，添加颜色信息
                if ($type === 'colors') {
                    $optionData['meta']['theme'] = $themeIdentifier;
                    $optionData['meta']['primary_color'] = $primaryColor;
                    $optionData['meta']['secondary_color'] = $secondaryColor;
                }
                
                // 对于变量类型，读取当前配置值
                if ($type === 'variable') {
                    try {
                        /** @var \Weline\Meta\Model\MetaConfig $varMetaConfig */
                        $varMetaConfig = ObjectManager::make(\Weline\Meta\Model\MetaConfig::class);
                        $varNamespace = "theme.{$area}";
                        $varConfigKey = "variables.{$path}.{$fileName}.value";
                        /** @var \Weline\Theme\Service\PreviewThemeScopeService $previewThemeScopeService */
                        $previewThemeScopeService = ObjectManager::make(\Weline\Theme\Service\PreviewThemeScopeService::class);
                        $effectiveScope = $previewThemeScopeService->resolveEffectiveScope((int)$theme->getId(), $area, $scope);
                        $varValue = $varMetaConfig->getConfig($theme->getId(), $varNamespace, $varConfigKey, $effectiveScope);
                        if ($varValue !== null) {
                            $currentConfig['variables'][$fileName] = $varValue;
                        }
                    } catch (\Exception $e) {
                        // 忽略错误
                    }
                }
                
                $options[] = $optionData;
            }
        }
        
        // 查找配置对应的 Meta 记录（用于保存时设置 meta_id 和 meta_identify）
        // 配置的 Meta identify 格式：theme.{area}.{type}s.{path}
        // 注意：这是目录级别的 Meta，不是具体文件的 Meta
        $configMetaIdentify = "theme.{$area}.{$type}s.{$path}";
        $configMetaId = null;
        $configMetaIdentifyValue = null;
        
        try {
            /** @var \Weline\Meta\Model\Meta $configMetaModel */
            $configMetaModel = ObjectManager::make(\Weline\Meta\Model\Meta::class);
            
            // 先尝试精确匹配目录级别的 Meta 记录
            $configMeta = $configMetaModel->where(\Weline\Meta\Model\Meta::schema_fields_NAMESPACE, 'theme')
                                          ->where(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY, $configMetaIdentify)
                                          ->find()
                                          ->fetch();
            
            // 如果找不到精确匹配，尝试查找该目录下任意一个文件的 Meta 记录（作为备用）
            if (!$configMeta || !$configMeta->getId()) {
                // 查找该目录下的第一个文件 Meta 记录
                $fileMetasResult = $configMetaModel->reset()
                                                   ->where(\Weline\Meta\Model\Meta::schema_fields_NAMESPACE, 'theme')
                                                   ->where(\Weline\Meta\Model\Meta::schema_fields_META_TYPE, $type)
                                                   ->where(\Weline\Meta\Model\Meta::schema_fields_CATEGORY, $path)
                                                   ->where(\Weline\Meta\Model\Meta::schema_fields_AREA, $area)
                                                   ->select()
                                                   ->fetch();
                
                $fileMetas = $fileMetasResult ? $fileMetasResult->getItems() : [];
                
                // 如果找到了文件的 Meta，使用第一个记录的 meta_id，但使用目录级别的 identify
                if (!empty($fileMetas)) {
                    $firstFileMeta = reset($fileMetas);
                    if ($firstFileMeta && $firstFileMeta->getId()) {
                        $configMetaId = (int)$firstFileMeta->getId();
                        // 使用目录级别的 identify（去掉文件名部分）
                        $configMetaIdentifyValue = $configMetaIdentify;
                    }
                }
            } else {
                // 如果找到了精确匹配
                $configMetaId = (int)$configMeta->getId();
                $configMetaIdentifyValue = $configMeta->getData(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY) ?: $configMetaIdentify;
            }
        } catch (\Exception $e) {
            // 忽略错误
        }
        
        // 确保 $theme 是对象，如果不是则重新加载
        if (!is_object($theme) || !method_exists($theme, 'getId')) {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->load($themeId);
        }
        
        $this->assign('theme', $theme);
        $this->assign('area', $area);
        $this->assign('scope', $scope);
        $this->assign('path', $path);
        $this->assign('type', $type); // 传递类型到模板
        $this->assign('config', $currentConfig);
        $this->assign('options', $options); // 统一使用 options 变量名
        $this->assign('meta_id', $configMetaId);
        $this->assign('meta_identify', $configMetaIdentifyValue);

        return $this->fetch('Weline_Theme::templates/backend/config/dir-config-form.phtml');
    }

    /**
     * 保存目录配置
     */
    public function postSaveDirConfig()
    {
        $themeId = $this->request->getPost('identity_id') ?: $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $scope = $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $path = $this->request->getPost('path', '');
        $config = $this->request->getPost('config', []);
        $metaId = $this->request->getPost('meta_id');
        $metaIdentify = $this->request->getPost('meta_identify');
        $type = $this->request->getPost('type', 'layout'); // 获取类型参数，默认为 layout
        
        // 调试：记录接收到的数据（如果 logger 可用）
        try {
            $logger = \Weline\Framework\App\Env::getInstance()->getLogger();
            if ($logger) {
                $logger->debug('postSaveDirConfig', [
                    'themeId' => $themeId,
                    'area' => $area,
                    'scope' => $scope,
                    'path' => $path,
                    'type' => $type,
                    'config' => $config,
                    'config_type_value' => $config[$type] ?? 'NOT_SET',
                    'meta_id' => $metaId,
                    'meta_identify' => $metaIdentify
                ]);
            }
        } catch (\Exception $e) {
            // 忽略日志错误
        }

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        if (empty($path)) {
            return $this->fetchJson($this->error(__('目录路径不能为空')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        [$area, $scope] = $this->resolveAreaAndScope($area, $scope);

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        // 保存配置（根据类型：layout, partial, color, component）
        // 对于 variable 类型，需要特殊处理
        if ($type === 'variable') {
            // 变量类型：保存所有变量配置
            $variables = $config['variables'] ?? [];
            if (!empty($variables)) {
                foreach ($variables as $varName => $varValue) {
                    $varConfigKey = "variables.{$path}.{$varName}.value";
                    $varBaseIdentify = "theme.{$area}.variables.{$path}.{$varName}";
                    $namespace = "theme.{$area}";
                    
                    try {
                        /** @var \Weline\Meta\Model\MetaConfig $metaConfig */
                        $metaConfig = ObjectManager::make(\Weline\Meta\Model\MetaConfig::class);
                        /** @var \Weline\Theme\Service\PreviewThemeScopeService $previewThemeScopeService */
                        $previewThemeScopeService = ObjectManager::make(\Weline\Theme\Service\PreviewThemeScopeService::class);
                        $effectiveScope = $previewThemeScopeService->resolveEffectiveScope((int)$theme->getId(), $area, $scope);
                        $metaConfig->setConfig(
                            $theme->getId(),
                            $namespace,
                            $varConfigKey,
                            (string)$varValue,
                            $effectiveScope,
                            null,
                            null,
                            $varBaseIdentify
                        );
                    } catch (\Exception $e) {
                        // 回退到 ThemeData
                        ThemeData::set("variables.{$path}.{$varName}.value", (string)$varValue, $scope);
                    }
                }
                ThemeData::clearCache();
            }
        } else {
            // 其他类型：保存单个配置值
            // 获取配置值（根据类型）
            $configValue = $config[$type] ?? '';
            
            // 调试日志
            try {
                $logger = \Weline\Framework\App\Env::getInstance()->getLogger();
                if ($logger) {
                    $logger->debug('postSaveDirConfig: 准备保存配置', [
                        'type' => $type,
                        'configValue' => $configValue,
                        'config_array' => $config
                    ]);
                }
            } catch (\Exception $e) {
                // 忽略日志错误
            }
            
            if (empty($configValue)) {
                return $this->fetchJson($this->error(__('请选择' . ($type === 'partial' ? '部件' : ($type === 'colors' ? '色系' : ($type === 'component' ? '组件' : '布局'))))));
            }
            
            // 标准化类型名称为复数形式（用于存储）
            // layout -> layouts, partial -> partials, component -> components
            // colors 已经是复数形式，不需要加 s
            $typeForStorage = $type;
            if (!str_ends_with($type, 's')) {
                $typeForStorage = $type . 's';
            }
            
            // 构建完整的 baseIdentify（用于查找 Meta 记录）
            // 格式：theme.{area}.{typeForStorage}.{path}
            $baseIdentify = "theme.{$area}.{$typeForStorage}.{$path}";
            $namespace = "theme.{$area}";
            // config_key 格式：{typeForStorage}.{path}.value（例如：layouts.homepage.value 或 partials.header.value）
            $configKey = "{$typeForStorage}.{$path}.value";
            
            // 使用 MetaConfig 直接保存，传递 meta_id 和 meta_identify
            try {
                /** @var \Weline\Meta\Model\MetaConfig $metaConfig */
                $metaConfig = ObjectManager::getInstance(\Weline\Meta\Model\MetaConfig::class);
                
                // 转换 meta_id
                $metaIdInt = null;
                if ($metaId) {
                    $metaIdInt = is_numeric($metaId) ? (int)$metaId : null;
                }
                
                // 如果没有提供 meta_identify，使用 baseIdentify
                $metaIdentifyValue = $metaIdentify ?: $baseIdentify;
                
                // 直接调用 setConfig，传递 meta_id 和 meta_identify
                /** @var \Weline\Theme\Service\PreviewThemeScopeService $previewThemeScopeService */
                $previewThemeScopeService = ObjectManager::make(\Weline\Theme\Service\PreviewThemeScopeService::class);
                $effectiveScope = $previewThemeScopeService->resolveEffectiveScope((int)$theme->getId(), $area, $scope);
                $metaConfig->setConfig(
                    $theme->getId(),
                    $namespace,
                    $configKey,
                    (string)$configValue,
                    $effectiveScope,
                    null, // locale
                    $metaIdInt,
                    $metaIdentifyValue
                );
                
                // 清除缓存，确保立即生效
                ThemeData::clearCache();
            } catch (\Exception $e) {
                // 如果直接保存失败，回退到 ThemeData::set()
                // 格式：{typeForStorage}.{path}.value（例如：layouts.homepage.value 或 partials.header.value）
                $configIdentify = "{$typeForStorage}.{$path}.value";
                ThemeData::set($configIdentify, (string)$configValue, $scope);
                ThemeData::clearCache();
            }
        }
        
        // 保存其他目录配置（如 description 等）
        // 格式：dir-config.{path}.{key}.value
        $baseIdentify = "dir-config.{$path}";
        foreach ($config as $key => $value) {
            if ($key === $type) {
                continue; // 已经单独处理了
            }
            $identify = "{$baseIdentify}.{$key}.value";
            ThemeData::set($identify, (string)$value, $scope);
        }

        return $this->fetchJson($this->success(__('目录配置保存成功')));
    }

    /**
     * 预览布局配置（临时设置到session中）
     */
    public function getPreview()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend'); // frontend 或 backend
        $header = $this->request->getParam('header', ''); // header选项
        $layouts = $this->request->getParam('layouts', []); // 布局配置数组，格式：['default' => 'wide', 'account' => 'auth']
        $color = $this->request->getParam('color', ''); // 色系选项
        $partials = $this->request->getParam('partials', []); // 部件配置数组
        $variables = $this->request->getParam('variables', []); // 变量配置数组（多选）
        $component = $this->request->getParam('component', ''); // 组件名称（用于组件预览）
        [$area, $scope] = $this->resolveAreaAndScope($area, $this->request->getParam('scope') ?? $this->request->getParam('scope_' . $area));
        
        if (!$themeId) {
            return $this->error(__('请选择主题'));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->error(__('主题不存在'));
        }

        // 使用PreviewManager设置预览配置
        $previewConfigs = [
            'layouts' => $layouts,
            'headers' => $header,
            'colors' => $color,
            'partials' => $partials,
            'variables' => $variables,
            'scope' => $scope,
        ];
        if ($component) {
            $previewConfigs['component'] = $component;
        }
        
        PreviewManager::setPreviewConfig($themeId, $area, $previewConfigs);

        // 如果是组件预览，重定向到组件预览页面
        if ($component) {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            $componentPreviewUrl = $url->getBackendUrl('theme/backend/config/layout/component', [
                'theme_id' => $themeId,
                'area' => $area,
                'component' => $component
            ]);
            $this->request->getResponse()->redirect($componentPreviewUrl);
            return '';
        }

        // 判断是否需要自动登录（根据布局文件的 @preview.login 标记）
        // 注意：自动登录逻辑现在由 PreviewAutoLogin Observer 在路由拦截之前处理
        // 这里只设置session标志，供Observer使用
        $autoLogin = $this->request->getParam('auto_login'); // 如果手动指定，优先使用
        if ($area === 'frontend' && ($autoLogin === null || $autoLogin === '')) {
            // 根据选中的布局文件判断是否需要登录
            $autoLogin = $this->shouldAutoLoginByLayoutConfig($theme, $area, $layouts) ? '1' : '0';
        }
        
        $shouldAutoLogin = ($autoLogin === '1' || $autoLogin === 1 || $autoLogin === true);
        // 设置预览自动登录标志到session，供Observer使用
        $this->session->setData('preview_auto_login', $shouldAutoLogin);

        if ($shouldAutoLogin) {
            PreviewAccountManager::ensurePreviewUser($theme);
        }
        
        // 根据区域和布局配置重定向到相应的预览页面
        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        $scopePath = $this->formatScopePath($area, $scope);
        if ($area === 'backend') {
            // 后端预览：重定向到后台首页
            $previewUrl = $url->getBackendUrl('admin/index/index', ['preview_theme' => $themeId, 'scope' => $scopePath]);
            $this->request->getResponse()->redirect($previewUrl);
            return '';
        } else {
            // 前端预览：根据布局配置选择预览页面
            $previewUrl = $this->getLayoutPreviewUrl($url, $layouts, $themeId, $scopePath);
            $this->request->getResponse()->redirect($previewUrl);
            return '';
        }
    }
    
    /**
     * 根据布局配置获取预览URL
     * 
     * @param Url $url URL对象
     * @param array $layouts 布局配置数组，格式：['account' => 'profile', 'homepage' => 'default']
     * @param int $themeId 主题ID
     * @return string 预览URL
     */
    private function getLayoutPreviewUrl(Url $url, array $layouts, int $themeId, string $scopePath): string
    {
        // 统一走 ThemeEditor layout-preview 链路，避免业务路由差异影响主题预览
        $layoutType = 'homepage';
        $layoutOption = 'default';
        $priorityOrder = ['account', 'homepage', 'category', 'product', 'cart', 'checkout', 'default'];
        foreach ($priorityOrder as $candidate) {
            if (isset($layouts[$candidate]) && $layouts[$candidate] !== '') {
                $layoutType = $candidate;
                $layoutOption = (string)$layouts[$candidate];
                break;
            }
        }
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }

        $versionId = null;
        try {
            /** @var ThemeLayoutVersionService $versionService */
            $versionService = ObjectManager::getInstance(ThemeLayoutVersionService::class);
            $currentVersion = $versionService->getCurrentVersion($themeId, $layoutType);
            $versionId = $currentVersion?->getVersionId() ?: null;
        } catch (\Throwable) {
            $versionId = null;
        }

        $params = [
            'theme_id' => $themeId,
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'editor_mode' => '1',
            'preview_mode' => 'version',
            'status' => 'draft',
            'editor_area' => 'frontend',
            'scope' => $scopePath,
            '_t' => time(),
        ];
        if ($versionId !== null && $versionId > 0) {
            $params['version_id'] = $versionId;
        }

        return $url->getBackendUrl('theme/backend/theme-editor/layout-preview', $params);
    }
    
    /**
     * 根据布局配置判断是否需要自动登录
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @param array $layouts 布局配置数组
     * @return bool 是否需要自动登录
     */
    private function shouldAutoLoginByLayoutConfig(WelineTheme $theme, string $area, array $layouts): bool
    {
        try {
            // 优先检查 account 布局（通常需要登录）
            $priorityOrder = ['account', 'homepage', 'default'];
            
            foreach ($priorityOrder as $layoutType) {
                if (isset($layouts[$layoutType]) && !empty($layouts[$layoutType])) {
                    $layoutOption = $layouts[$layoutType];
                    $previewLogin = $this->getLayoutPreviewLogin($theme, $area, $layoutType, $layoutOption);
                    if ($previewLogin !== null) {
                        return $previewLogin == 1;
                    }
                }
            }
            
            // 如果都没有找到，默认不登录
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取布局文件的 @preview.login 标记值
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @return int|null 标记值（0或1），如果找不到返回null
     */
    private function getLayoutPreviewLogin(WelineTheme $theme, string $area, string $layoutType, string $layoutOption): ?int
    {
        try {
            $themePath = $theme->getPath();
            if (empty($themePath)) {
                return null;
            }
            
            $layoutPath = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'layouts' . DS . $layoutType . DS . $layoutOption . '.phtml';
            $layoutPath = str_replace('\\', DS, $layoutPath);
            
            // 如果当前主题不存在，尝试父主题
            if (!is_file($layoutPath)) {
                $parentId = $theme->getParentId();
                if ($parentId) {
                    /** @var WelineTheme $parentTheme */
                    $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                    $parentTheme->load($parentId);
                    if ($parentTheme->getId()) {
                        return $this->getLayoutPreviewLogin($parentTheme, $area, $layoutType, $layoutOption);
                    }
                }
                return null;
            }
            
            // 解析布局文件的 Meta 信息
            $meta = \Weline\Theme\Helper\ComponentMetaParser::parse($layoutPath);
            
            return isset($meta['preview_login']) ? (int)$meta['preview_login'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 组件预览页面
     */
    public function getComponent()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $component = $this->request->getParam('component', '');
        
        if (!$themeId || !$component) {
            return $this->error(__('参数不完整'));
        }
        
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->error(__('主题不存在'));
        }
        
        // 使用PreviewManager设置预览配置
        PreviewManager::setPreviewConfig($themeId, $area, [
            'component' => $component
        ]);
        
        $this->assign('theme', $theme);
        $this->assign('area', $area);
        $this->assign('component', $component);
        
        return $this->fetch('Weline_Theme::templates/backend/config/component.phtml');  
    }

    /**
     * 获取可用的布局选项（AJAX）
     */
    public function getLayoutOptions()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $layoutType = $this->request->getParam('layout_type'); // account, default 等

        if (!$themeId || !$layoutType) {
            return $this->fetchJson($this->error(__('参数不完整')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        $layouts = $this->getAvailableLayouts($theme, $area);
        $options = $layouts[$layoutType] ?? [];

        return $this->fetchJson($this->success('', ['options' => $options]));
    }

    /**
     * 获取可用的 Header 选项（AJAX）
     */
    /**
     * 获取当前主题的布局激活配置（用于标记选中的布局节点）
     * 返回格式：{ "homepage": "minimal", "cart": "default", ... }
     */
    public function getActiveLayouts()
    {
        $themeId = $this->request->getParam('identity_id') ?: $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', self::DEFAULT_SCOPE);

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 设置当前主题（确保 ThemeData 使用正确的主题）
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        // 获取当前布局配置
        $layoutConfig = ThemeData::getLayoutConfig($area, $scope);
        
        // 转换为简单的路径 => 布局文件名的映射
        $activeLayouts = [];
        foreach ($layoutConfig as $path => $layoutValue) {
            if ($layoutValue) {
                $activeLayouts[$path] = $layoutValue;
            }
        }
        
        // 如果没有配置，默认选中 default
        // 获取所有可用的布局类型
        if (empty($activeLayouts)) {
            $layoutScanner = ObjectManager::getInstance(LayoutScanner::class);
            $layouts = $layoutScanner->scanLayouts($theme, $area);
            
            // 为每个布局类型设置默认值为 'default'
            foreach ($layouts as $layoutType => $options) {
                // 检查是否存在 'default' 选项
                $hasDefault = false;
                if (is_array($options)) {
                    foreach ($options as $option) {
                        $optionValue = is_array($option) ? ($option['value'] ?? $option) : $option;
                        if ($optionValue === 'default') {
                            $hasDefault = true;
                            break;
                        }
                    }
                }
                
                // 如果存在 'default' 选项，则设置为默认选中
                if ($hasDefault) {
                    $activeLayouts[$layoutType] = 'default';
                } elseif (!empty($options)) {
                    // 如果没有 'default' 选项，使用第一个选项
                    $firstOption = is_array($options[0]) ? ($options[0]['value'] ?? $options[0]) : $options[0];
                    $activeLayouts[$layoutType] = $firstOption;
                }
            }
        }
        
        // 调试日志
        try {
            $logger = Env::getInstance()->getLogger();
            if ($logger) {
                $logger->debug('getActiveLayouts', [
                    'theme_id' => $theme->getId(),
                    'area' => $area,
                    'scope' => $scope,
                    'layoutConfig' => $layoutConfig,
                    'activeLayouts' => $activeLayouts,
                    'activeLayouts_json' => json_encode($activeLayouts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]);
            }
        } catch (\Exception $e) {
            // 忽略日志错误
        }

        // 返回格式与 Meta API 保持一致（code: 200）
        return $this->fetchJson([
            'code' => 200,
            'msg' => __('获取成功'),
            'data' => [
                'active_layouts' => $activeLayouts,
                'theme_id' => $theme->getId(),
                'area' => $area,
                'scope' => $scope
            ]
        ]);
    }

    public function getHeaderOptions()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        $headers = $this->getAvailableHeaders($theme, $area);

        return $this->fetchJson($this->success('', ['options' => $headers]));
    }

    /**
     * 获取可用布局（优先从 ThemeData 读取 Meta 数据）
     */
    private function getAvailableLayouts(WelineTheme $theme, string $area): array
    {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        $options = ThemeData::getAvailableOptions($area, 'layouts');
        return !empty($options) ? $options : LayoutScanner::scanLayouts($theme, $area);
    }

    /**
     * 获取可用色系（优先从 ThemeData 读取 Meta 数据）
     */
    private function getAvailableColors(WelineTheme $theme, string $area): array
    {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        $options = ThemeData::getAvailableOptions($area, 'colors');
        return !empty($options) ? $options : LayoutScanner::scanColors($theme, $area);
    }

    /**
     * 获取可用变量（优先从 ThemeData 读取 Meta 数据）
     */
    private function getAvailableVariables(WelineTheme $theme, string $area): array
    {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        $options = ThemeData::getAvailableOptions($area, 'variables');
        return !empty($options) ? $options : LayoutScanner::scanVariables($theme, $area);
    }

    /**
     * 获取可用组件（优先从 ThemeData 读取 Meta 数据）
     */
    private function getAvailableComponents(WelineTheme $theme, string $area): array
    {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        $options = ThemeData::getAvailableOptions($area, 'components');
        return !empty($options) ? $options : LayoutScanner::scanComponents($theme, $area);
    }

    /**
     * 获取可用部件（优先从 ThemeData 读取 Meta 数据）
     */
    private function getAvailablePartials(WelineTheme $theme, string $area): array
    {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        $options = ThemeData::getAvailableOptions($area, 'partials');
        return !empty($options) ? $options : LayoutScanner::scanPartials($theme, $area);
    }

    /**
     * 获取可用 Header 选项
     */
    private function getAvailableHeaders(WelineTheme $theme, string $area): array
    {
        $partials = $this->getAvailablePartials($theme, $area);
        if (!empty($partials['header'])) {
            $values = [];
            foreach ($partials['header'] as $header) {
                if (isset($header['value'])) {
                    $values[] = $header['value'];
                }
            }
            return array_values(array_unique($values));
        }
        return LayoutScanner::scanHeaders($theme, $area);
    }

    private function resolveAreaAndScope(string $defaultArea, ?string $scopeParam): array
    {
        $area = strtolower($defaultArea);
        $scope = self::DEFAULT_SCOPE;

        if ($scopeParam !== null) {
            $scopeParam = trim($scopeParam);
            if ($scopeParam !== '') {
                if (str_contains($scopeParam, '/')) {
                    [$maybeArea, $rest] = explode('/', $scopeParam, 2);
                    if ($maybeArea !== '') {
                        $area = strtolower($maybeArea);
                    }
                    $scopeParam = $rest;
                }
                if ($scopeParam !== '') {
                    $scope = $scopeParam;
                }
            }
        }

        return [$area, $scope];
    }

    /**
     * 准备色盘数据（用于卡片式UI）
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域
     * @return array 色盘数组，格式：['paletteName' => ['name' => '...', 'variables' => [...], 'is_selected' => true], ...]
     */
    private function prepareColorPalettes(WelineTheme $theme, string $area, string $scope): array
    {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        $palettes = [];
        $themePath = $theme->getPath();
        
        if (empty($themePath) || !is_dir($themePath)) {
            return $palettes;
        }
        
        // 扫描colors目录
        $colorsDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'colors';
        if (!is_dir($colorsDir)) {
            $colorsDir = rtrim($themePath, DS) . DS . 'theme' . DS . $area . DS . 'colors';
        }
        
        if (!is_dir($colorsDir)) {
            return $palettes;
        }
        
                // 获取当前选中的色盘和所有颜色配置
        $colorConfig = ThemeData::getColorConfig($area, $scope);
        $selectedPalette = $colorConfig ?: 'default';
        $configList = ThemeData::getConfigList($area, 'colors', $scope);
        
        // 扫描所有色盘文件
        $colorFiles = glob($colorsDir . DS . '_*.css');
        foreach ($colorFiles as $file) {
            $basename = basename($file, '.css');
            if (strpos($basename, '_') === 0) {
                $paletteName = substr($basename, 1);
                if (empty($paletteName)) {
                    continue;
                }
                
                // 解析色盘文件
                $variables = CssVariableParser::parseFile($file);
                
                // 提取文件meta信息
                $content = file_get_contents($file);
                
                // 从文件内容提取meta信息
                $name = ucfirst($paletteName);
                $description = '';
                $themeId = $paletteName;
                
                if (preg_match('/@meta\.name\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
                    $name = trim($matches[1]);
                } elseif (preg_match('/@meta\.name\s*\{[^}]*name=["\']([^"\']+)["\']/', $content, $matches)) {
                    $name = trim($matches[1]);
                }
                
                if (preg_match('/@meta\.description\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
                    $description = trim($matches[1]);
                } elseif (preg_match('/@meta\.description\s*\{[^}]*name=["\']([^"\']+)["\']/', $content, $matches)) {
                    $description = trim($matches[1]);
                }
                
                // 提取主色和次色（从变量中查找）
                $primaryColor = '#4a90e2';
                $secondaryColor = '#f1f5f9';
                
                foreach ($variables as $var) {
                    $varName = $var['variable_name'] ?? '';
                    $varValue = $var['default_value'] ?? '';
                    
                    // 查找主色
                    if (strpos($varName, '--color-primary') === 0 && $varName === '--color-primary') {
                        $primaryColor = $varValue;
                    }
                    // 查找次色（背景色或次要色）
                    if (strpos($varName, '--color-bg-secondary') === 0 && $varName === '--color-bg-secondary') {
                        $secondaryColor = $varValue;
                    } elseif (strpos($varName, '--color-secondary') === 0 && $varName === '--color-secondary' && $secondaryColor === '#f1f5f9') {
                        $secondaryColor = $varValue;
                    }
                }
                
                // 构建变量数组，包含配置值
                $variablesData = [];
                foreach ($variables as $var) {
                    $varName = $var['variable_name'] ?? '';
                    $defaultValue = $var['default_value'] ?? '';
                    $varType = $var['variable_type'] ?? 'other';
                    $category = $var['category'] ?? '其他';
                    $varDescription = $var['description'] ?? '';
                    $isColor = $var['is_color'] ?? false;
                    
                    // 读取配置值（如果已配置）
                    // configList的键格式：colors.{paletteName}.variables.{varName}.value
                    $configKey = "colors.{$paletteName}.variables.{$varName}.value";
                    $configValue = $configList[$configKey] ?? null;
                    
                    $variablesData[$varName] = [
                        'value' => $configValue !== null ? $configValue : $defaultValue,
                        'default' => $defaultValue,
                        'type' => $varType,
                        'category' => $category,
                        'description' => $varDescription,
                        'is_color' => $isColor,
                        'variable_name' => $varName
                    ];
                }
                
                $palettes[$paletteName] = [
                    'name' => $name,
                    'description' => $description,
                    'primary_color' => $primaryColor,
                    'secondary_color' => $secondaryColor,
                    'variables' => $variablesData,
                    'is_selected' => ($selectedPalette === $paletteName),
                    'file' => basename($file)
                ];
            }
        }
        
        // 如果主题有继承，合并父主题的色盘
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentPalettes = $this->prepareColorPalettes($parentTheme, $area, $scope);
            foreach ($parentPalettes as $paletteName => $palette) {
                if (!isset($palettes[$paletteName])) {
                    $palettes[$paletteName] = $palette;
                }
            }
        }
        
        return $palettes;
    }

    /**
     * 准备变量数据（用于卡片式UI）
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域
     * @return array 变量数组，按文件分组，格式：['file' => ['name' => '...', 'variables' => [...], ...], ...]
     */
    private function prepareVariables(WelineTheme $theme, string $area, string $scope): array
    {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        $variables = [];
        $themePath = $theme->getPath();
        
        if (empty($themePath) || !is_dir($themePath)) {
            return $variables;
        }
        
        // 扫描variables目录
        $variablesDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'variables';
        if (!is_dir($variablesDir)) {
            $variablesDir = rtrim($themePath, DS) . DS . 'theme' . DS . $area . DS . 'variables';
        }
        
        if (!is_dir($variablesDir)) {
            return $variables;
        }
        
        // 获取所有变量配置
        $configList = ThemeData::getConfigList($area, 'variables', $scope);
        
        // 扫描所有变量文件
        $variableFiles = glob($variablesDir . DS . '_*.css');
        foreach ($variableFiles as $file) {
            $basename = basename($file, '.css');
            if (strpos($basename, '_') === 0) {
                $fileName = substr($basename, 1);
                if (empty($fileName)) {
                    continue;
                }
                
                // 解析变量文件
                $vars = CssVariableParser::parseFile($file);
                
                // 提取文件meta信息
                $content = file_get_contents($file);
                
                $name = ucfirst($fileName);
                $description = '';
                $category = ucfirst($fileName);
                
                // 提取 @meta.name
                if (preg_match('/@meta\.name\s*\{[^}]*default\s*=\s*"([^"]+)"/', $content, $matches)) {
                    $name = trim($matches[1]);
                } elseif (preg_match('/@meta\.name\s*\{[^}]*name\s*=\s*"([^"]+)"/', $content, $matches)) {
                    $name = trim($matches[1]);
                }
                
                // 提取 @meta.description
                if (preg_match('/@meta\.description\s*\{[^}]*default\s*=\s*"([^"]+)"/', $content, $matches)) {
                    $description = trim($matches[1]);
                } elseif (preg_match('/@meta\.description\s*\{[^}]*name\s*=\s*"([^"]+)"/', $content, $matches)) {
                    $description = trim($matches[1]);
                }
                
                // 提取 @meta.category
                if (preg_match('/@meta\.category\s*\{[^}]*default\s*=\s*"([^"]+)"/', $content, $matches)) {
                    $category = trim($matches[1]);
                } elseif (preg_match('/@meta\.category\s*\{[^}]*name\s*=\s*"([^"]+)"/', $content, $matches)) {
                    $category = trim($matches[1]);
                }
                
                // 构建变量数组，包含配置值
                $variablesData = [];
                foreach ($vars as $var) {
                    $varName = $var['variable_name'] ?? '';
                    $defaultValue = $var['default_value'] ?? '';
                    $varType = $var['variable_type'] ?? 'other';
                    $varCategory = $var['category'] ?? '其他';
                    $varDescription = $var['description'] ?? '';
                    $isColor = $var['is_color'] ?? false;
                    
                    // 读取配置值（如果已配置）
                    // configList的键格式：variables.{file}.{varName}.value
                    $configKey = "variables.{$fileName}.{$varName}.value";
                    $configValue = $configList[$configKey] ?? null;
                    
                    $variablesData[$varName] = [
                        'value' => $configValue !== null ? $configValue : $defaultValue,
                        'default' => $defaultValue,
                        'type' => $varType,
                        'category' => $varCategory,
                        'description' => $varDescription,
                        'is_color' => $isColor,
                        'variable_name' => $varName
                    ];
                }
                
                $variables[$fileName] = [
                    'name' => $name,
                    'description' => $description,
                    'category' => $category,
                    'variables' => $variablesData,
                    'file' => basename($file),
                    'variable_count' => count($variablesData),
                    'configured_count' => count(array_filter($variablesData, function($v) use ($configList, $fileName) {
                        $configKey = "variables.{$fileName}.{$v['variable_name']}.value";
                        return isset($configList[$configKey]);
                    }))
                ];
            }
        }
        
        // 如果主题有继承，合并父主题的变量
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentVariables = $this->prepareVariables($parentTheme, $area, $scope);
            foreach ($parentVariables as $fileName => $variable) {
                if (!isset($variables[$fileName])) {
                    $variables[$fileName] = $variable;
                }
            }
        }
        
        return $variables;
    }

    /**
     * 准备组件数据（用于卡片式UI）
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域
     * @return array 组件数组，格式：['componentName' => ['name' => '...', 'params' => [...], ...], ...]
     */
    private function prepareComponents(WelineTheme $theme, string $area, string $scope): array
    {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        $components = [];
        $themePath = $theme->getPath();
        
        if (empty($themePath) || !is_dir($themePath)) {
            return $components;
        }
        
        // 扫描components目录
        $componentsDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'components';
        if (!is_dir($componentsDir)) {
            $componentsDir = rtrim($themePath, DS) . DS . 'theme' . DS . $area . DS . 'components';
        }
        
        if (!is_dir($componentsDir)) {
            return $components;
        }
        
        // 获取所有组件配置
        $configList = ThemeData::getConfigList($area, 'components', $scope);
        
        // 扫描所有组件文件
        $componentFiles = glob($componentsDir . DS . '*.phtml');
        foreach ($componentFiles as $file) {
            $componentName = basename($file, '.phtml');
            if (empty($componentName)) {
                continue;
            }
            
            // 解析组件文件
            $meta = ComponentMetaParser::parse($file);
            
            $name = $meta['component'] ?? ucfirst($componentName);
            $description = $meta['description'] ?? '';
            $params = $meta['params'] ?? [];
            
            // 构建参数数组，包含配置值
            $paramsData = [];
            foreach ($params as $param) {
                $paramName = $param['name'] ?? '';
                $paramType = $param['type'] ?? 'mixed';
                $paramDefault = $param['default'] ?? null;
                $paramDescription = $param['description'] ?? '';
                $paramRequired = $param['required'] ?? false;
                
                // 读取配置值（如果已配置）
                // configList的键格式：components.{componentName}.param.{paramName}.value
                $configKey = "components.{$componentName}.param.{$paramName}.value";
                $configValue = $configList[$configKey] ?? null;
                
                $paramsData[$paramName] = [
                    'value' => $configValue !== null ? $configValue : $paramDefault,
                    'default' => $paramDefault,
                    'type' => $paramType,
                    'description' => $paramDescription,
                    'required' => $paramRequired,
                    'name' => $paramName
                ];
            }
            
            $components[$componentName] = [
                'name' => $name,
                'description' => $description,
                'params' => $paramsData,
                'file' => basename($file),
                'param_count' => count($paramsData),
                'configured_count' => count(array_filter($paramsData, function($p) use ($configList, $componentName) {
                    $configKey = "components.{$componentName}.param.{$p['name']}.value";
                    return isset($configList[$configKey]);
                }))
            ];
        }
        
        // 如果主题有继承，合并父主题的组件
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentComponents = $this->prepareComponents($parentTheme, $area, $scope);
            foreach ($parentComponents as $componentName => $component) {
                if (!isset($components[$componentName])) {
                    $components[$componentName] = $component;
                }
            }
        }
        
        return $components;
    }

    private function formatScopePath(string $area, string $scope): string
    {
        return $area . '/' . $scope;
    }

    private function formatScopeList(string $area, array $scopes): array
    {
        $formatted = [];
        foreach ($scopes as $scope) {
            $formatted[] = $this->formatScopePath($area, $scope);
        }

        if (empty($formatted)) {
            $formatted[] = $this->formatScopePath($area, self::DEFAULT_SCOPE);
        }

        return array_values(array_unique($formatted));
    }
    
    /**
     * 从 ThemeData 获取布局配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @param string $scope 作用域
     * @return array|null 布局配置数组，格式：['account' => 'auth', 'default' => 'default']
     */
    private function getLayoutConfigFromMeta(WelineTheme $theme, string $area, string $scope): ?array
    {
        try {
            // 设置当前主题和区域
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            
            // 使用 ThemeData 的统一方法获取布局配置
            $layoutConfig = ThemeData::getLayoutConfig($area, $scope);
            
            return !empty($layoutConfig) ? $layoutConfig : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 从 ThemeData 获取 Header 配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @param string $scope 作用域
     * @return string|null Header 配置值
     */
    private function getHeaderConfigFromMeta(WelineTheme $theme, string $area, string $scope): ?string
    {
        try {
            // 设置当前主题和区域
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            
            // 格式：partials.header.value
            $identify = "partials.header.value";
            return ThemeData::get($identify);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 从 ThemeData 获取色系配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @param string $scope 作用域
     * @return string|null 色系配置值
     */
    private function getColorConfigFromMeta(WelineTheme $theme, string $area, string $scope): ?string
    {
        try {
            // 设置当前主题和区域
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            
            // 使用 ThemeData 的统一方法获取色系配置
            return ThemeData::getColorConfig($area, $scope);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 从 ThemeData 获取部件配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @param string $scope 作用域
     * @return array|null 部件配置数组
     */
    private function getPartialsConfigFromMeta(WelineTheme $theme, string $area, string $scope): ?array
    {
        try {
            // 设置当前主题和区域
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            
            // 使用 ThemeData 的统一方法获取部件配置
            $partialsConfig = ThemeData::getPartialsConfig($area, $scope);
            
            return !empty($partialsConfig) ? $partialsConfig : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getResolvedPartialsConfig(WelineTheme $theme, string $area, string $scope): array
    {
        $metaConfig = $this->getPartialsConfigFromMeta($theme, $area, $scope);
        if (\is_array($metaConfig) && !empty($metaConfig)) {
            return $metaConfig;
        }

        $themeConfig = (array)$theme->getConfig();
        $partialsByArea = (array)($themeConfig['partials'] ?? []);
        $normalizedArea = \strtolower(\trim($area)) === 'backend' ? 'backend' : 'frontend';
        return (array)($partialsByArea[$normalizedArea] ?? []);
    }
    
    /**
     * 从 ThemeData 获取变量配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @param string $scope 作用域
     * @return array|null 变量配置数组
     */
    private function getVariablesConfigFromMeta(WelineTheme $theme, string $area, string $scope): ?array
    {
        try {
            // 设置当前主题和区域
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            
            // 使用 ThemeData 的统一方法获取变量配置
            $variablesConfig = ThemeData::getVariablesConfig($area, $scope);
            
            return !empty($variablesConfig) ? $variablesConfig : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 从 ThemeData 读取元数据并补充 i18n 翻译到扫描结果中
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @param array $layouts 布局数组（引用传递，会被修改）
     * @param array $colors 色系数组（引用传递，会被修改）
     * @param array $variables 变量数组（引用传递，会被修改）
     * @param array $components 组件数组（引用传递，会被修改）
     * @param array $partials 部件数组（引用传递，会被修改）
     */
    private function enrichWithThemeData(
        WelineTheme $theme,
        string $area,
        array &$layouts,
        array &$colors,
        array &$variables,
        array &$components,
        array &$partials
    ): void {
        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        // 补充布局元数据
        $this->enrichLayoutsMeta($layouts, $area);
        
        // 补充色系元数据
        $this->enrichColorsMeta($colors, $area);
        
        // 补充变量元数据
        $this->enrichVariablesMeta($variables, $area);
        
        // 补充组件元数据
        $this->enrichComponentsMeta($components, $area);
        
        // 补充部件元数据
        $this->enrichPartialsMeta($partials, $area);
    }
    
    /**
     * 补充布局元数据
     * 
     * @param array $layouts 布局数组（引用传递）
     * @param string $area 区域
     */
    private function enrichLayoutsMeta(array &$layouts, string $area): void
    {
        foreach ($layouts as $layoutType => &$options) {
            foreach ($options as &$option) {
                if (!is_array($option)) {
                    continue;
                }

                $value = $option['value'] ?? '';
                if ($value === '') {
                    continue;
                }

                $metaIdentify = $option['meta_identify'] ?? "layouts.{$layoutType}.{$value}";
                $metaInfo = $option['meta'] ?? [];
                if (!is_array($metaInfo)) {
                    $metaInfo = [];
                }

                $params = $metaInfo['params'] ?? [];
                // 只要「参数」或「名称 / 描述」有缺失，就尝试从物理文件 Meta 中补全一次
                $needParseFile = (empty($params) || !is_array($params) || empty($metaInfo['name']) || empty($metaInfo['description']));
                if ($needParseFile) {
                    $filePath = $this->resolveOptionFilePath($option['file'] ?? '');
                    if ($filePath && is_file($filePath)) {
                        $parsed = ComponentMetaParser::parse($filePath);
                        if (!empty($parsed['params']) && (empty($params) || !is_array($params))) {
                            $params = $parsed['params'];
                        }
                        if (empty($metaInfo['name']) && !empty($parsed['meta']['name'])) {
                            $metaInfo['name'] = $parsed['meta']['name'];
                        }
                        if (empty($metaInfo['description']) && !empty($parsed['meta']['description'])) {
                            $metaInfo['description'] = $parsed['meta']['description'];
                        }
                    }
                }
                $metaInfo['params'] = is_array($params) ? $params : [];

                if (!empty($metaInfo['name'])) {
                    $defaultName = $metaInfo['name'];
                    if (is_array($defaultName)) {
                        $defaultName = $defaultName['name'] ?? $defaultName['default'] ?? reset($defaultName) ?? '';
                    }
                    $defaultName = (string)$defaultName;
                    $translatedName = MetaTranslation::getTranslatedValue(
                        "{$metaIdentify}.info.name",
                        null,
                        $defaultName
                    );
                    $metaInfo['name'] = $translatedName ?: $defaultName;
                } else {
                    $metaInfo['name'] = ucfirst($value);
                }

                if (!empty($metaInfo['description'])) {
                    $defaultDesc = $metaInfo['description'];
                    if (is_array($defaultDesc)) {
                        $defaultDesc = $defaultDesc['name'] ?? $defaultDesc['default'] ?? reset($defaultDesc) ?? '';
                    }
                    $defaultDesc = (string)$defaultDesc;
                    $translatedDesc = MetaTranslation::getTranslatedValue(
                        "{$metaIdentify}.info.description",
                        null,
                        $defaultDesc
                    );
                    $metaInfo['description'] = $translatedDesc ?: $defaultDesc;
                }

                $option['meta'] = $metaInfo;
            }
        }
    }
    
    /**
     * 补充色系元数据
     * 
     * @param array $colors 色系数组（引用传递）
     * @param string $area 区域
     */
    private function enrichColorsMeta(array &$colors, string $area): void
    {
        foreach ($colors as &$color) {
            if (!is_array($color)) {
                continue;
            }

            $metaIdentify = $color['meta_identify'] ?? null;
            $value = $color['value'] ?? '';
            if (!$metaIdentify && $value !== '') {
                $metaIdentify = "colors.{$value}";
            }

            $metaInfo = $color['meta'] ?? [];
            if (!is_array($metaInfo)) {
                $metaInfo = [];
            }

            if (!empty($metaInfo['name']) && $metaIdentify) {
                $defaultName = $metaInfo['name'];
                if (is_array($defaultName)) {
                    $defaultName = $defaultName['name'] ?? $defaultName['default'] ?? reset($defaultName) ?? '';
                }
                $defaultName = (string)$defaultName;
                $translatedName = MetaTranslation::getTranslatedValue(
                    "{$metaIdentify}.info.name",
                    null,
                    $defaultName
                );
                $metaInfo['name'] = $translatedName ?: $defaultName;
            }

            if (!empty($metaInfo['description']) && $metaIdentify) {
                $defaultDesc = $metaInfo['description'];
                if (is_array($defaultDesc)) {
                    $defaultDesc = $defaultDesc['name'] ?? $defaultDesc['default'] ?? reset($defaultDesc) ?? '';
                }
                $defaultDesc = (string)$defaultDesc;
                $translatedDesc = MetaTranslation::getTranslatedValue(
                    "{$metaIdentify}.info.description",
                    null,
                    $defaultDesc
                );
                $metaInfo['description'] = $translatedDesc ?: $defaultDesc;
            }

            $color['meta'] = $metaInfo;
        }
    }
    
    /**
     * 补充变量元数据
     * 
     * @param array $variables 变量数组（引用传递）
     * @param string $area 区域
     */
    private function enrichVariablesMeta(array &$variables, string $area): void
    {
        foreach ($variables as &$variable) {
            if (!is_array($variable)) {
                continue;
            }

            $metaIdentify = $variable['meta_identify'] ?? null;
            $value = $variable['value'] ?? '';
            if (!$metaIdentify && $value !== '') {
                $metaIdentify = "variables.{$value}";
            }

            $metaInfo = $variable['meta'] ?? [];
            if (!is_array($metaInfo)) {
                $metaInfo = [];
            }

            if (!empty($metaInfo['name']) && $metaIdentify) {
                $defaultName = $metaInfo['name'];
                if (is_array($defaultName)) {
                    $defaultName = $defaultName['name'] ?? $defaultName['default'] ?? reset($defaultName) ?? '';
                }
                $defaultName = (string)$defaultName;
                $translatedName = MetaTranslation::getTranslatedValue(
                    "{$metaIdentify}.info.name",
                    null,
                    $defaultName
                );
                $metaInfo['name'] = $translatedName ?: $defaultName;
            }

            if (!empty($metaInfo['description']) && $metaIdentify) {
                $defaultDesc = $metaInfo['description'];
                if (is_array($defaultDesc)) {
                    $defaultDesc = $defaultDesc['name'] ?? $defaultDesc['default'] ?? reset($defaultDesc) ?? '';
                }
                $defaultDesc = (string)$defaultDesc;
                $translatedDesc = MetaTranslation::getTranslatedValue(
                    "{$metaIdentify}.info.description",
                    null,
                    $defaultDesc
                );
                $metaInfo['description'] = $translatedDesc ?: $defaultDesc;
            }

            $variable['meta'] = $metaInfo;
        }
    }
    
    /**
     * 补充组件元数据
     * 
     * @param array $components 组件数组（引用传递）
     * @param string $area 区域
     */
    private function enrichComponentsMeta(array &$components, string $area): void
    {
        foreach ($components as &$component) {
            if (!is_array($component)) {
                continue;
            }

            $metaIdentify = $component['meta_identify'] ?? null;
            $value = $component['value'] ?? '';
            if (!$metaIdentify && $value !== '') {
                $metaIdentify = "components.{$value}";
            }

            $metaInfo = $component['meta'] ?? [];
            if (!is_array($metaInfo)) {
                $metaInfo = [];
            }

            if (!empty($metaInfo['name']) && $metaIdentify) {
                $translatedName = MetaTranslation::getTranslatedValue(
                    "{$metaIdentify}.info.name",
                    null,
                    $metaInfo['name']
                );
                $metaInfo['name'] = $translatedName ?: $metaInfo['name'];
            }

            if (!empty($metaInfo['description']) && $metaIdentify) {
                $translatedDesc = MetaTranslation::getTranslatedValue(
                    "{$metaIdentify}.info.description",
                    null,
                    $metaInfo['description']
                );
                $metaInfo['description'] = $translatedDesc ?: $metaInfo['description'];
            }

            if (empty($metaInfo['params']) || !is_array($metaInfo['params'])) {
                $filePath = $this->resolveOptionFilePath($component['file'] ?? '');
                if ($filePath && is_file($filePath)) {
                    $parsed = ComponentMetaParser::parse($filePath);
                    if (!empty($parsed['params'])) {
                        $metaInfo['params'] = $parsed['params'];
                    }
                }
                if (empty($metaInfo['params']) || !is_array($metaInfo['params'])) {
                    $metaInfo['params'] = [];
                }
            }

            $component['meta'] = $metaInfo;
        }
    }
    
    /**
     * 补充部件元数据
     * 
     * @param array $partials 部件数组（引用传递）
     * @param string $area 区域
     */
    private function enrichPartialsMeta(array &$partials, string $area): void
    {
        foreach ($partials as $partialType => &$options) {
            foreach ($options as &$option) {
                if (!is_array($option)) {
                    continue;
                }
                
                $value = $option['value'] ?? '';
                if (empty($value)) {
                    continue;
                }

                $metaIdentify = $option['meta_identify'] ?? "partials.{$partialType}.{$value}";
                $metaInfo = $option['meta'] ?? [];
                if (!is_array($metaInfo)) {
                    $metaInfo = [];
                }

                if (!empty($metaInfo['name']) && $metaIdentify) {
                    $defaultName = $metaInfo['name'];
                    if (is_array($defaultName)) {
                        $defaultName = $defaultName['name'] ?? $defaultName['default'] ?? reset($defaultName) ?? '';
                    }
                    $defaultName = (string)$defaultName;
                    $translatedName = MetaTranslation::getTranslatedValue(
                        "{$metaIdentify}.info.name",
                        null,
                        $defaultName
                    );
                    $metaInfo['name'] = $translatedName ?: $defaultName;
                }

                if (!empty($metaInfo['description']) && $metaIdentify) {
                    $defaultDesc = $metaInfo['description'];
                    if (is_array($defaultDesc)) {
                        $defaultDesc = $defaultDesc['name'] ?? $defaultDesc['default'] ?? reset($defaultDesc) ?? '';
                    }
                    $defaultDesc = (string)$defaultDesc;
                    $translatedDesc = MetaTranslation::getTranslatedValue(
                        "{$metaIdentify}.info.description",
                        null,
                        $defaultDesc
                    );
                    $metaInfo['description'] = $translatedDesc ?: $defaultDesc;
                }

                if (empty($metaInfo['params']) || !is_array($metaInfo['params'])) {
                    $filePath = $this->resolveOptionFilePath($option['file'] ?? '');
                    if ($filePath && is_file($filePath)) {
                        $parsed = ComponentMetaParser::parse($filePath);
                        if (!empty($parsed['params'])) {
                            $metaInfo['params'] = $parsed['params'];
                        }
                    }
                    if (empty($metaInfo['params']) || !is_array($metaInfo['params'])) {
                        $metaInfo['params'] = [];
                    }
                }

                $option['meta'] = $metaInfo;
            }
        }
    }

    /**
     * 将 Meta 选项中的 file 字段解析为可读的绝对路径
     *
     * @param string $file 选项中记录的文件路径/别名
     * @return string|null
     */
    private function resolveOptionFilePath(string $file): ?string
    {
        $file = trim($file);
        if ($file === '') {
            return null;
        }

        // 已经是绝对路径
        if (preg_match('/^[A-Za-z]:\\\\|^[\\/]/', $file)) {
            return $file;
        }

        $normalized = str_replace(['/', '\\\\'], DS, $file);

        // 模块别名形式：Module_Package::path/to/file.phtml
        if (str_contains($normalized, '::')) {
            [$module, $path] = explode('::', $normalized, 2);
            $modulePath = BP . DS . 'app' . DS . 'code' . DS . str_replace('_', DS, $module);
            return $modulePath . DS . ltrim($path, DS);
        }

        // 相对项目根目录的路径（如 app/code/...）
        return BP . DS . ltrim($normalized, DS);
    }
    
    /**
     * 获取色系文件的颜色变量
     */
    public function getColorVariables()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $scope = $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $colorFile = $this->request->getPost('color_file', '');
        $colorValue = $this->request->getPost('color_value', '');
        
        if (!$themeId || !$colorFile || !$colorValue) {
            return $this->fetchJson($this->error(__('参数不完整')));
        }
        
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }
        
        [$area, $scope] = $this->resolveAreaAndScope($area, $scope);
        $inputError = $this->validateColorRequestInput($area, $colorFile, $colorValue);
        if ($inputError !== null) {
            return $this->fetchJson($this->error($inputError));
        }
        
        // 解析文件路径
        $filePath = $this->resolveColorFilePath($theme, $area, $colorFile, $colorValue);
        
        if (!$filePath || !file_exists($filePath)) {
            return $this->fetchJson($this->error(__('色系文件不存在：%{file}', ['file' => $colorFile])));
        }
        
        // 读取CSS文件内容
        $cssContent = file_get_contents($filePath);
        if ($cssContent === false) {
            return $this->fetchJson($this->error(__('无法读取色系文件')));
        }
        
        // 提取颜色变量
        $variables = $this->extractColorVariables($cssContent);
        
        // 获取色系名称
        $colorName = $this->getColorName($theme, $area, $colorValue);
        
        return $this->fetchJson($this->success('', [
            'colorName' => $colorName,
            'colorFile' => $colorFile,
            'colorValue' => $colorValue,
            'variables' => $variables
        ]));
    }
    
    /**
     * 保存色系颜色变量配置
     */
    public function saveColorVariables()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $scope = $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $colorFile = $this->request->getPost('color_file', '');
        $colorValue = $this->request->getPost('color_value', '');
        $variablesJson = $this->request->getPost('variables', '{}');
        
        if (!$themeId || !$colorFile || !$colorValue) {
            return $this->fetchJson($this->error(__('参数不完整')));
        }
        
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }
        
        [$area, $scope] = $this->resolveAreaAndScope($area, $scope);
        $inputError = $this->validateColorRequestInput($area, $colorFile, $colorValue);
        if ($inputError !== null) {
            return $this->fetchJson($this->error($inputError));
        }
        
        // 解析变量JSON
        $variables = json_decode($variablesJson, true);
        if (!is_array($variables)) {
            return $this->fetchJson($this->error(__('变量格式错误')));
        }
        $variableError = $this->validateColorVariablesPayload($variables);
        if ($variableError !== null) {
            return $this->fetchJson($this->error($variableError));
        }
        
        // 解析文件路径
        $filePath = $this->resolveColorFilePath($theme, $area, $colorFile, $colorValue);
        
        if (!$filePath || !file_exists($filePath)) {
            return $this->fetchJson($this->error(__('色系文件不存在：%{file}', ['file' => $colorFile])));
        }
        
        // 读取CSS文件内容
        $cssContent = file_get_contents($filePath);
        if ($cssContent === false) {
            return $this->fetchJson($this->error(__('无法读取色系文件')));
        }
        
        // 更新颜色变量
        try {
            $updatedContent = $this->updateColorVariables($cssContent, $variables);
        } catch (\InvalidArgumentException $exception) {
            return $this->fetchJson($this->error($exception->getMessage()));
        }
        
        // 备份原文件
        $backupPath = $filePath . '.backup.' . date('YmdHis');
        if (!copy($filePath, $backupPath)) {
            return $this->fetchJson($this->error(__('无法创建备份文件')));
        }
        
        // 保存更新后的内容
        if (file_put_contents($filePath, $updatedContent) === false) {
            // 如果保存失败，尝试恢复备份
            if (file_exists($backupPath)) {
                copy($backupPath, $filePath);
            }
            return $this->fetchJson($this->error(__('保存失败')));
        }
        
        // 清除缓存
        ThemeData::clearCache();
        
        return $this->fetchJson($this->success(__('配置保存成功'), [
            'backup' => $backupPath
        ]));
    }
    
    /**
     * 解析色系文件路径
     */
    private function resolveColorFilePath(WelineTheme $theme, string $area, string $colorFile, string $colorValue): ?string
    {
        $themePath = $theme->getPath();
        if (empty($themePath)) {
            return null;
        }

        $themeRoot = realpath($themePath);
        if ($themeRoot === false || !is_dir($themeRoot)) {
            return null;
        }

        $colorsRoot = $this->resolveColorsRoot($themeRoot, $area);
        if ($colorsRoot === null) {
            return null;
        }

        $allowedFiles = [
            $colorValue . '.css',
            '_' . $colorValue . '.css',
        ];

        $colorFile = basename(str_replace('\\', '/', $colorFile));
        if ($colorFile !== '' && !in_array($colorFile, $allowedFiles, true)) {
            return null;
        }

        $candidateFiles = $colorFile !== '' ? [$colorFile] : $allowedFiles;
        
        foreach ($candidateFiles as $fileName) {
            $realPath = realpath($colorsRoot . DS . $fileName);
            if ($realPath !== false && is_file($realPath) && $this->isPathInsideRoot($realPath, $colorsRoot)) {
                return $realPath;
            }
        }
        
        // 如果当前主题不存在，尝试父主题
        $parentId = $theme->getParentId();
        if ($parentId) {
            /** @var WelineTheme $parentTheme */
            $parentTheme = ObjectManager::getInstance(WelineTheme::class);
            $parentTheme->load($parentId);
            if ($parentTheme->getId()) {
                return $this->resolveColorFilePath($parentTheme, $area, $colorFile, $colorValue);
            }
        }
        
        return null;
    }

    private function resolveColorsRoot(string $themeRoot, string $area): ?string
    {
        $candidateRoots = [
            $themeRoot . DS . 'view' . DS . 'theme' . DS . $area . DS . 'colors',
            $themeRoot . DS . 'theme' . DS . $area . DS . 'colors',
        ];

        foreach ($candidateRoots as $root) {
            $realRoot = realpath($root);
            if ($realRoot !== false && is_dir($realRoot) && $this->isPathInsideRoot($realRoot, $themeRoot)) {
                return $realRoot;
            }
        }

        return null;
    }

    private function isPathInsideRoot(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $path === $root || str_starts_with($path . '/', $root . '/');
    }

    private function validateColorRequestInput(string $area, string $colorFile, string $colorValue): ?string
    {
        if (!$this->isSafeThemeArea($area)) {
            return __('主题区域无效：%{area}', ['area' => $area]);
        }

        if (!$this->isSafeIdentifierSegment($colorValue)) {
            return __('色系标识无效：%{value}', ['value' => $colorValue]);
        }

        $colorFile = basename(str_replace('\\', '/', $colorFile));
        $allowedFiles = [
            $colorValue . '.css',
            '_' . $colorValue . '.css',
        ];

        if (!in_array($colorFile, $allowedFiles, true)) {
            return __('色系文件名无效：%{file}', ['file' => $colorFile]);
        }

        return null;
    }

    private function isSafeThemeArea(string $area): bool
    {
        return in_array($area, [self::THEME_AREA_FRONTEND, self::THEME_AREA_BACKEND], true);
    }

    private function isSafeIdentifierSegment(string $value): bool
    {
        if ($value === '' || preg_match('/[\x00-\x1F\x7F\\\\\/]/', $value)) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1;
    }

    private function validateColorVariablesPayload(array $variables): ?string
    {
        foreach ($variables as $varName => $varValue) {
            $varName = is_string($varName) ? trim($varName) : '';
            $varValueRaw = is_scalar($varValue) ? (string)$varValue : '';

            if (!$this->isSafeCssVariableName($varName)) {
                return __('CSS变量名无效：%{name}', ['name' => $varName]);
            }

            if (!$this->isSafeCssColorToken($varValueRaw)) {
                return __('CSS颜色值无效：%{name}', ['name' => $varName]);
            }
        }

        return null;
    }

    private function isSafeCssVariableName(string $name): bool
    {
        return preg_match('/^--[A-Za-z0-9_-]+$/', $name) === 1;
    }
    
    /**
     * 从CSS内容中提取颜色变量
     */
    private function extractColorVariables(string $cssContent): array
    {
        $variables = [];
        
        // 匹配CSS变量定义：--variable-name: value;
        // 支持注释分组
        $pattern = '/\/\*\s*([^*]|[\r\n]|(\*+([^*\/]|[\r\n])))*\*+\/\s*|(--[\w-]+)\s*:\s*([^;]+);/';
        
        $currentCategory = '其他';
        $lines = explode("\n", $cssContent);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // 检查是否是分类注释
            if (preg_match('/\/\*\s*={3,}\s*([^=]+)\s*={3,}/', $line, $matches)) {
                $currentCategory = trim($matches[1]);
                continue;
            }
            
            // 匹配CSS变量
            if (preg_match('/--([\w-]+)\s*:\s*([^;]+);/', $line, $matches)) {
                $varName = '--' . $matches[1];
                $varValue = trim($matches[2]);
                
                // 跳过非颜色值（如数字、函数等）
                if (!$this->isColorValue($varValue)) {
                    continue;
                }
                
                // 规范化颜色值
                $normalizedValue = $this->normalizeColorValue($varValue);
                
                $variables[] = [
                    'name' => $varName,
                    'value' => $normalizedValue,
                    'originalValue' => $varValue,
                    'category' => $currentCategory,
                    'description' => $this->extractVariableDescription($line, $cssContent, $varName)
                ];
            }
        }
        
        return $variables;
    }
    
    /**
     * 判断是否是颜色值
     */
    private function isColorValue(string $value): bool
    {
        return $this->isSafeCssColorToken($value);
    }

    private function isSafeCssColorToken(string $value): bool
    {
        if ($value === '' || preg_match('/[\x00-\x1F\x7F;]/', $value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/[{}]/', $value)) {
            return false;
        }

        if (preg_match('/(?:url|expression)\s*\(/i', $value)) {
            return false;
        }

        if (preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $value)) {
            return true;
        }

        if (preg_match('/^rgba?\(\s*(?:\d{1,3}|(?:\d{1,2}(?:\.\d+)?|100(?:\.0+)?)%)\s*,\s*(?:\d{1,3}|(?:\d{1,2}(?:\.\d+)?|100(?:\.0+)?)%)\s*,\s*(?:\d{1,3}|(?:\d{1,2}(?:\.\d+)?|100(?:\.0+)?)%)(?:\s*,\s*(?:0|1|0?\.\d+|(?:\d{1,2}(?:\.\d+)?|100(?:\.0+)?)%))?\s*\)$/i', $value)) {
            return $this->isSafeRgbColorToken($value);
        }

        if (preg_match('/^hsla?\(\s*(?:\d{1,3}(?:\.\d+)?)(?:deg|grad|rad|turn)?\s*,\s*(?:\d{1,2}(?:\.\d+)?|100(?:\.0+)?)%\s*,\s*(?:\d{1,2}(?:\.\d+)?|100(?:\.0+)?)%(?:\s*,\s*(?:0|1|0?\.\d+|(?:\d{1,2}(?:\.\d+)?|100(?:\.0+)?)%))?\s*\)$/i', $value)) {
            return true;
        }

        if (preg_match('/^var\(\s*--[A-Za-z0-9_-]+\s*\)$/', $value)) {
            return true;
        }

        $safeKeywords = ['transparent', 'currentcolor', 'black', 'white'];
        return in_array(strtolower($value), $safeKeywords, true);
    }

    private function isSafeRgbColorToken(string $value): bool
    {
        if (!preg_match('/^rgba?\((.+)\)$/i', trim($value), $matches)) {
            return false;
        }

        $parts = array_map('trim', explode(',', $matches[1]));
        if (count($parts) < 3 || count($parts) > 4) {
            return false;
        }

        for ($i = 0; $i < 3; $i++) {
            $part = $parts[$i];
            if (str_ends_with($part, '%')) {
                $number = (float)substr($part, 0, -1);
                if ($number < 0 || $number > 100) {
                    return false;
                }
                continue;
            }

            $number = (int)$part;
            if ((string)$number !== $part || $number < 0 || $number > 255) {
                return false;
            }
        }

        return true;
    }
    
    /**
     * 规范化颜色值（转换为十六进制）
     */
    private function normalizeColorValue(string $value): string
    {
        $value = trim($value);
        
        // 已经是十六进制
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            return strtoupper($value);
        }
        
        // 3位十六进制转6位
        if (preg_match('/^#([0-9A-Fa-f]{3})$/', $value, $matches)) {
            $hex = $matches[1];
            return '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        
        // rgb/rgba转十六进制
        if (preg_match('/^rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*[\d.]+)?\)$/', $value, $matches)) {
            $r = str_pad(dechex((int)$matches[1]), 2, '0', STR_PAD_LEFT);
            $g = str_pad(dechex((int)$matches[2]), 2, '0', STR_PAD_LEFT);
            $b = str_pad(dechex((int)$matches[3]), 2, '0', STR_PAD_LEFT);
            return '#' . strtoupper($r . $g . $b);
        }
        
        // 命名颜色转十六进制
        $colorMap = [
            'red' => '#FF0000',
            'blue' => '#0000FF',
            'green' => '#008000',
            'yellow' => '#FFFF00',
            'orange' => '#FFA500',
            'purple' => '#800080',
            'pink' => '#FFC0CB',
            'cyan' => '#00FFFF',
            'magenta' => '#FF00FF',
            'black' => '#000000',
            'white' => '#FFFFFF',
            'gray' => '#808080',
            'grey' => '#808080'
        ];
        
        if (isset($colorMap[strtolower($value)])) {
            return $colorMap[strtolower($value)];
        }
        
        // 如果无法转换，返回原值
        return $value;
    }
    
    /**
     * 提取变量描述（从注释中）
     */
    private function extractVariableDescription(string $line, string $cssContent, string $varName): string
    {
        // 尝试从行内注释提取
        if (preg_match('/\/\*\s*(.+?)\s*\*\//', $line, $matches)) {
            return trim($matches[1]);
        }
        
        // 尝试从上一行注释提取
        $lines = explode("\n", $cssContent);
        $lineIndex = array_search($line, $lines);
        if ($lineIndex > 0) {
            $prevLine = trim($lines[$lineIndex - 1]);
            if (preg_match('/\/\*\s*(.+?)\s*\*\//', $prevLine, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return '';
    }
    
    /**
     * 更新CSS内容中的颜色变量
     */
    private function updateColorVariables(string $cssContent, array $variables): string
    {
        $lines = explode("\n", $cssContent);
        $updatedLines = [];
        $safeVariables = [];

        foreach ($variables as $varName => $varValue) {
            $varName = is_string($varName) ? trim($varName) : '';
            $varValueRaw = is_scalar($varValue) ? (string)$varValue : '';
            $varValue = trim($varValueRaw);

            if (!$this->isSafeCssVariableName($varName) || !$this->isSafeCssColorToken($varValueRaw)) {
                throw new \InvalidArgumentException(__('CSS变量配置无效'));
            }

            $safeVariables[$varName] = $this->normalizeColorValue($varValue);
        }
        
        foreach ($lines as $line) {
            // 匹配CSS变量定义
            foreach ($safeVariables as $varName => $varValue) {
                $pattern = '/(?<![A-Za-z0-9_-])(' . preg_quote($varName, '/') . ')\s*:\s*[^;{}]+;/';
                
                if (preg_match($pattern, $line)) {
                    $line = preg_replace(
                        $pattern,
                        '$1: ' . $varValue . ';',
                        $line
                    );
                    break;
                }
            }
            
            $updatedLines[] = $line;
        }
        
        return implode("\n", $updatedLines);
    }
    
    /**
     * 获取色系名称
     */
    private function getColorName(WelineTheme $theme, string $area, string $colorValue): string
    {
        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        // 从ThemeData获取色系选项
        $colors = ThemeData::getAvailableOptions($area, 'colors');
        foreach ($colors as $color) {
            if (is_array($color) && ($color['value'] ?? '') === $colorValue) {
                return $color['label'] ?? $color['meta']['name'] ?? $colorValue;
            }
        }
        
        return ucfirst($colorValue);
    }

    /**
     * 保存色盘选择
     */
    public function postSaveColorPalette()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $scope = $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $paletteName = $this->request->getPost('palette_name');

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        if (empty($paletteName)) {
            return $this->fetchJson($this->error(__('请选择色盘')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        [$area, $scope] = $this->resolveAreaAndScope($area, $scope);

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        // 保存色盘选择：colors.value
        $result = ThemeData::set('colors.value', $paletteName, $scope);

        if ($result) {
            ThemeData::clearCache();
            return $this->fetchJson($this->success(__('色盘选择已保存')));
        } else {
            return $this->fetchJson($this->error(__('保存失败')));
        }
    }

    /**
     * 保存色盘变量配置
     */
    public function postSaveColorPaletteVariables()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $scope = $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $paletteName = $this->request->getPost('palette_name');
        $variables = $this->request->getPost('variables', []);

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        if (empty($paletteName)) {
            return $this->fetchJson($this->error(__('请选择色盘')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        [$area, $scope] = $this->resolveAreaAndScope($area, $scope);

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        // 批量保存色盘变量
        $savedCount = 0;
        foreach ($variables as $varName => $varValue) {
            $configKey = "colors.{$paletteName}.variables.{$varName}.value";
            
            // 验证颜色值格式
            if (strpos($varName, '--color') === 0) {
                $normalizedValue = CssVariableParser::normalizeValue($varValue, 'color');
            } else {
                $normalizedValue = $varValue;
            }
            
            if (ThemeData::set($configKey, $normalizedValue, $scope)) {
                $savedCount++;
            }
        }

        ThemeData::clearCache();
        
        // 清除CSS缓存
        try {
            /** @var \Weline\Theme\Helper\PreviewManager $previewManager */
            $previewManager = ObjectManager::getInstance(\Weline\Theme\Helper\PreviewManager::class);
            $previewManager->clearPreviewCache();
        } catch (\Exception $e) {
            // 忽略错误
        }

        // 生成预览URL
        $previewUrl = null;
        try {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            $previewUrl = $url->getBackendUrl('theme/backend/index/preview', [
                'theme_id' => $theme->getId(),
                'area' => $area,
                'auto_login' => '1'
            ]);
        } catch (\Exception $e) {
            // 忽略错误
        }

        return $this->fetchJson($this->success(__('已保存 %{count} 个变量', ['count' => $savedCount]), [
            'saved_count' => $savedCount,
            'preview_url' => $previewUrl
        ]));
    }

    /**
     * 保存变量配置
     */
    public function postSaveVariables()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $scope = $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $fileName = $this->request->getPost('file_name');
        $variables = $this->request->getPost('variables', []);

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        if (empty($fileName)) {
            return $this->fetchJson($this->error(__('请选择变量文件')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        [$area, $scope] = $this->resolveAreaAndScope($area, $scope);

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        // 批量保存变量
        $savedCount = 0;
        foreach ($variables as $varName => $varValue) {
            $configKey = "variables.{$fileName}.{$varName}.value";
            
            // 根据变量类型验证和规范化值
            $varType = 'other';
            if (strpos($varName, '--color') === 0) {
                $varType = 'color';
            } elseif (strpos($varName, '--spacing') === 0 || strpos($varName, '--space') === 0) {
                $varType = 'spacing';
            }
            
            $normalizedValue = CssVariableParser::normalizeValue($varValue, $varType);
            
            if (ThemeData::set($configKey, $normalizedValue, $scope)) {
                $savedCount++;
            }
        }

        ThemeData::clearCache();
        
        // 清除CSS缓存
        try {
            /** @var \Weline\Theme\Helper\PreviewManager $previewManager */
            $previewManager = ObjectManager::getInstance(\Weline\Theme\Helper\PreviewManager::class);
            $previewManager->clearPreviewCache();
        } catch (\Exception $e) {
            // 忽略错误
        }

        // 生成预览URL
        $previewUrl = null;
        try {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            $previewUrl = $url->getBackendUrl('theme/backend/index/preview', [
                'theme_id' => $theme->getId(),
                'area' => $area,
                'auto_login' => '1'
            ]);
        } catch (\Exception $e) {
            // 忽略错误
        }

        return $this->fetchJson($this->success(__('已保存 %{count} 个变量', ['count' => $savedCount]), [
            'saved_count' => $savedCount,
            'preview_url' => $previewUrl
        ]));
    }

    /**
     * 保存组件参数配置
     */
    public function postSaveComponentParams()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $scope = $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $componentName = $this->request->getPost('component_name');
        $params = $this->request->getPost('params', []);

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        if (empty($componentName)) {
            return $this->fetchJson($this->error(__('请选择组件')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        [$area, $scope] = $this->resolveAreaAndScope($area, $scope);

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        // 批量保存组件参数
        $savedCount = 0;
        foreach ($params as $paramName => $paramValue) {
            $configKey = "components.{$componentName}.param.{$paramName}.value";
            
            // 将值转换为字符串
            if (is_array($paramValue)) {
                $paramValue = json_encode($paramValue, JSON_UNESCAPED_UNICODE);
            } else {
                $paramValue = (string)$paramValue;
            }
            
            if (ThemeData::set($configKey, $paramValue, $scope)) {
                $savedCount++;
            }
        }

        ThemeData::clearCache();

        return $this->fetchJson($this->success(__('已保存 %{count} 个参数', ['count' => $savedCount]), [
            'saved_count' => $savedCount
        ]));
    }

    /**
     * 获取色盘变量数据（用于弹窗）
     */
    public function getColorPaletteVariables()
    {
        $themeId = $this->request->getParam('theme_id') ?: $this->request->getPost('theme_id');
        $area = $this->request->getParam('area', 'frontend') ?: $this->request->getPost('area', 'frontend');
        $scope = $this->request->getParam('scope', self::DEFAULT_SCOPE) ?: $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $paletteName = $this->request->getParam('palette_name') ?: $this->request->getPost('palette_name');

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        if (empty($paletteName)) {
            return $this->fetchJson($this->error(__('请选择色盘')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        [$area, $scope] = $this->resolveAreaAndScope($area, $scope);

        // 准备色盘数据
        $palettes = $this->prepareColorPalettes($theme, $area, $scope);
        $palette = $palettes[$paletteName] ?? null;

        if (!$palette) {
            return $this->fetchJson($this->error(__('色盘不存在')));
        }

        return $this->fetchJson($this->success('', [
            'palette_name' => $paletteName,
            'palette' => $palette
        ]));
    }

    /**
     * 获取变量文件数据（用于弹窗）
     */
    public function getVariableFileData()
    {
        $themeId = $this->request->getParam('theme_id') ?: $this->request->getPost('theme_id');
        $area = $this->request->getParam('area', 'frontend') ?: $this->request->getPost('area', 'frontend');
        $scope = $this->request->getParam('scope', self::DEFAULT_SCOPE) ?: $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $fileName = $this->request->getParam('file_name') ?: $this->request->getPost('file_name');

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        if (empty($fileName)) {
            return $this->fetchJson($this->error(__('请选择变量文件')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        [$area, $scope] = $this->resolveAreaAndScope($area, $scope);

        // 准备变量数据
        $variables = $this->prepareVariables($theme, $area, $scope);
        $variable = $variables[$fileName] ?? null;

        if (!$variable) {
            return $this->fetchJson($this->error(__('变量文件不存在')));
        }

        return $this->fetchJson($this->success('', [
            'file_name' => $fileName,
            'variable' => $variable
        ]));
    }

    /**
     * 获取组件参数数据（用于弹窗）
     */
    public function getComponentParams()
    {
        $themeId = $this->request->getParam('theme_id') ?: $this->request->getPost('theme_id');
        $area = $this->request->getParam('area', 'frontend') ?: $this->request->getPost('area', 'frontend');
        $scope = $this->request->getParam('scope', self::DEFAULT_SCOPE) ?: $this->request->getPost('scope', self::DEFAULT_SCOPE);
        $componentName = $this->request->getParam('component_name') ?: $this->request->getPost('component_name');

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        if (empty($componentName)) {
            return $this->fetchJson($this->error(__('请选择组件')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        [$area, $scope] = $this->resolveAreaAndScope($area, $scope);

        // 准备组件数据
        $components = $this->prepareComponents($theme, $area, $scope);
        $component = $components[$componentName] ?? null;

        if (!$component) {
            return $this->fetchJson($this->error(__('组件不存在')));
        }

        return $this->fetchJson($this->success('', [
            'component_name' => $componentName,
            'component' => $component
        ]));
    }

    /**
     * 获取色盘变量配置弹窗HTML
     */
    public function getColorPaletteModal()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', self::DEFAULT_SCOPE);
        $paletteName = $this->request->getParam('palette_name');
        $paletteJson = $this->request->getParam('palette');

        if (!$themeId || !$paletteName) {
            return $this->error(__('参数不完整'));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->error(__('主题不存在'));
        }

        // 解析palette数据
        $palette = [];
        if ($paletteJson) {
            $palette = json_decode($paletteJson, true) ?: [];
        } else {
            // 如果没有传递palette数据，重新获取
            $palettes = $this->prepareColorPalettes($theme, $area, $scope);
            $palette = $palettes[$paletteName] ?? [];
        }

        $this->assign('theme', $theme);
        $this->assign('area', $area);
        $this->assign('scope', $scope);
        $this->assign('paletteName', $paletteName);
        $this->assign('palette', $palette);

        return $this->fetch('Weline_Theme::templates/backend/config/modals/color-palette-variables.phtml');
    }

    /**
     * 获取变量文件配置弹窗HTML
     */
    public function getVariableFileModal()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', self::DEFAULT_SCOPE);
        $fileName = $this->request->getParam('file_name');
        $variableJson = $this->request->getParam('variable');

        if (!$themeId || !$fileName) {
            return $this->error(__('参数不完整'));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->error(__('主题不存在'));
        }

        // 解析variable数据
        $variable = [];
        if ($variableJson) {
            $variable = json_decode($variableJson, true) ?: [];
        } else {
            // 如果没有传递variable数据，重新获取
            $variables = $this->prepareVariables($theme, $area, $scope);
            $variable = $variables[$fileName] ?? [];
        }

        $this->assign('theme', $theme);
        $this->assign('area', $area);
        $this->assign('scope', $scope);
        $this->assign('fileName', $fileName);
        $this->assign('variable', $variable);

        return $this->fetch('Weline_Theme::templates/backend/config/modals/variable-file-config.phtml');
    }

    /**
     * 获取组件参数配置弹窗HTML
     */
    public function getComponentParamsModal()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', self::DEFAULT_SCOPE);
        $componentName = $this->request->getParam('component_name');
        $componentJson = $this->request->getParam('component');

        if (!$themeId || !$componentName) {
            return $this->error(__('参数不完整'));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->error(__('主题不存在'));
        }

        // 解析component数据
        $component = [];
        if ($componentJson) {
            $component = json_decode($componentJson, true) ?: [];
        } else {
            // 如果没有传递component数据，重新获取
            $components = $this->prepareComponents($theme, $area, $scope);
            $component = $components[$componentName] ?? [];
        }

        $this->assign('theme', $theme);
        $this->assign('area', $area);
        $this->assign('scope', $scope);
        $this->assign('componentName', $componentName);
        $this->assign('component', $component);

        return $this->fetch('Weline_Theme::templates/backend/config/modals/component-params.phtml');
    }
}

