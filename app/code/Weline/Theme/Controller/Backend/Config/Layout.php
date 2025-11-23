<?php

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
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Helper\ThemeConfigManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\PreviewManager;
use Weline\Theme\Helper\PreviewAccountManager;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题布局配置控制器
 */
class Layout extends BackendController
{
    private const DEFAULT_SCOPE = 'default';
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

        // 扫描可用的布局
        $frontendLayouts = LayoutScanner::scanLayouts($theme, 'frontend');
        $backendLayouts = LayoutScanner::scanLayouts($theme, 'backend');
        
        // 获取当前配置（优先使用 MetaConfig，回退到旧配置）
        $frontendLayoutConfig = $this->getLayoutConfigFromMeta($theme, $frontendArea, $frontendScope) 
            ?? LayoutScanner::getLayoutConfig($theme, $frontendArea, $frontendScope);
        $backendLayoutConfig = $this->getLayoutConfigFromMeta($theme, $backendArea, $backendScope) 
            ?? LayoutScanner::getLayoutConfig($theme, $backendArea, $backendScope);

        $this->assign('theme', $theme);
        $this->assign('frontendLayouts', $frontendLayouts);
        $this->assign('backendLayouts', $backendLayouts);
        $this->assign('frontendLayoutConfig', $frontendLayoutConfig);
        $this->assign('backendLayoutConfig', $backendLayoutConfig);

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

        // 扫描可用的布局
        $frontendLayouts = LayoutScanner::scanLayouts($theme, 'frontend');
        $backendLayouts = LayoutScanner::scanLayouts($theme, 'backend');
        
        // 获取当前配置（优先使用 MetaConfig，回退到旧配置）
        $frontendLayoutConfig = $this->getLayoutConfigFromMeta($theme, $frontendArea, $frontendScope) 
            ?? LayoutScanner::getLayoutConfig($theme, $frontendArea, $frontendScope);
        $backendLayoutConfig = $this->getLayoutConfigFromMeta($theme, $backendArea, $backendScope) 
            ?? LayoutScanner::getLayoutConfig($theme, $backendArea, $backendScope);
        
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
            'frontendLayoutConfig' => $frontendLayoutConfig,
            'backendLayoutConfig' => $backendLayoutConfig,
            'frontendScope' => $this->formatScopePath($frontendArea, $frontendScope),
            'backendScope' => $this->formatScopePath($backendArea, $backendScope),
            'scopeOptions' => [
                'frontend' => $this->formatScopeList($frontendArea, ThemeConfigManager::getScopes($theme, $frontendArea)),
                'backend' => $this->formatScopeList($backendArea, ThemeConfigManager::getScopes($theme, $backendArea)),
            ],
        ]));
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
        $availableLayouts = LayoutScanner::scanLayouts($theme, $area);
        foreach ($layouts as $layoutType => $option) {
            if (!isset($availableLayouts[$layoutType])) {
                return $this->fetchJson($this->error(__('布局类型不存在：%1', $layoutType)));
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
                return $this->fetchJson($this->error(__('布局选项无效：%1/%2', $layoutType, $option)));
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
        // 布局类型和选项到URL的映射
        // 注意：布局选项会在渲染时根据配置自动应用，所以URL只需要指向能展示该布局类型的页面即可
        $layoutUrlMap = [
            'account' => [
                'dashboard' => 'frontend/account/index',  // 个人中心首页，使用dashboard布局
                'profile' => 'frontend/account/index',     // 个人中心首页，使用profile布局
                'orders' => 'frontend/account/index',      // 个人中心首页，使用orders布局
                'auth' => 'frontend/account/login',        // 登录页面，使用auth布局
                'default' => 'frontend/account/index',     // 个人中心首页，使用默认布局
            ],
            'homepage' => [
                'default' => 'frontend/index/index',
                'minimal' => 'frontend/index/index',
            ],
            'category' => [
                'grid' => 'frontend/category/index',
                'list' => 'frontend/category/index',
                'default' => 'frontend/category/index',
            ],
            'product' => [
                'detail' => 'frontend/product/index',
                'list' => 'frontend/product/index',
                'default' => 'frontend/product/index',
            ],
            'cart' => [
                'default' => 'frontend/cart/index',
                'empty' => 'frontend/cart/index',
            ],
            'checkout' => [
                'default' => 'frontend/checkout/index',
                'one-page' => 'frontend/checkout/index',
                'success' => 'frontend/checkout/success',
            ],
        ];
        
        // 优先使用选中的布局类型和选项
        // 按照优先级：account > homepage > category > product > cart > checkout > default
        $priorityOrder = ['account', 'homepage', 'category', 'product', 'cart', 'checkout'];
        
        foreach ($priorityOrder as $layoutType) {
            if (isset($layouts[$layoutType]) && !empty($layouts[$layoutType])) {
                $layoutOption = $layouts[$layoutType];
                if (isset($layoutUrlMap[$layoutType][$layoutOption])) {
                    $route = $layoutUrlMap[$layoutType][$layoutOption];
                    return $url->getFrontendUrl($route, ['preview_theme' => $themeId, 'scope' => $scopePath]);
                }
            }
        }
        
        // 如果没有匹配的布局，使用默认首页
        return $url->getFrontendUrl('index/index', ['preview_theme' => $themeId, 'scope' => $scopePath]);
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

        // 扫描可用的布局
        $layouts = LayoutScanner::scanLayouts($theme, $area);
        $options = $layouts[$layoutType] ?? [];

        return $this->fetchJson($this->success('', ['options' => $options]));
    }

    /**
     * 获取可用的 Header 选项（AJAX）
     */
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

        // 扫描可用的 Header
        $headers = LayoutScanner::scanHeaders($theme, $area);

        return $this->fetchJson($this->success('', ['options' => $headers]));
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
            
            // 扫描可用的布局类型
            $availableLayouts = LayoutScanner::scanLayouts($theme, $area);
            $layoutConfig = [];
            
            foreach ($availableLayouts as $layoutType => $options) {
                // 格式：layouts.{layoutType}.value
                $identify = "layouts.{$layoutType}.value";
                $configValue = ThemeData::get($identify, null);
                
                if ($configValue !== null) {
                    $layoutConfig[$layoutType] = $configValue;
                }
            }
            
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
            
            // 格式：colors.primary.value
            $identify = "colors.primary.value";
            return ThemeData::get($identify);
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
            
            // 扫描可用的部件类型
            $availablePartials = LayoutScanner::scanPartials($theme, $area);
            $partialsConfig = [];
            
            foreach ($availablePartials as $partialType => $options) {
                // 格式：partials.{partialType}.value
                $identify = "partials.{$partialType}.value";
                $configValue = ThemeData::get($identify);
                
                if ($configValue !== null) {
                    $partialsConfig[$partialType] = $configValue;
                }
            }
            
            return !empty($partialsConfig) ? $partialsConfig : null;
        } catch (\Exception $e) {
            return null;
        }
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
            
            // 格式：variables.value（JSON 格式）
            $identify = "variables.value";
            $configValue = ThemeData::get($identify);
            
            if ($configValue !== null) {
                if (is_string($configValue)) {
                    $decoded = json_decode($configValue, true);
                    return is_array($decoded) ? $decoded : [$configValue];
                }
                return is_array($configValue) ? $configValue : [$configValue];
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}

