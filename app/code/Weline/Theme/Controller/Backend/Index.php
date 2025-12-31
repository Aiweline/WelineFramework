<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Helper\MetaData;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\ConfigLoader;
use Weline\Theme\Helper\MetaTranslation;
use Weline\Theme\Helper\PreviewAccountManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题管理控制器
 */
class Index extends BackendController
{
    /**
     * 主题列表页面
     */
    public function getIndex()
    {
        /** @var WelineTheme $themeModel */
        $themeModel = ObjectManager::getInstance(WelineTheme::class);
        
        // 获取所有主题
        $themes = $themeModel->select()->fetch()->getItems();
        
        // 为每个主题获取父主题信息
        foreach ($themes as &$theme) {
            // 确保 $theme 是数组格式
            if (is_object($theme)) {
                $theme = $theme->getData();
            }
            
            $themeObj = ObjectManager::getInstance(WelineTheme::class);
            $themeObj->setData($theme);
            $parentTheme = $themeObj->getParentTheme();
            
            if ($parentTheme && $parentTheme->getId()) {
                $theme['parent_id'] = $parentTheme->getId();
                $theme['parent_theme_name'] = $parentTheme->getName();
            } else {
                $theme['parent_id'] = null;
                $theme['parent_theme_name'] = null;
            }
        }
        unset($theme); // 解除引用
        
        $this->assign('themes', $themes);
        $this->assign('page_title', __('主题管理'));
        
        return $this->fetch('Weline_Theme::templates/backend/index.phtml');
    }

    /**
     * 获取主题详情（用于modal显示）
     */
    public function getThemeInfo()
    {
        $themeId = $this->request->getParam('theme_id');
        
        if (!$themeId) {
            return $this->fetchJson(['status' => 'error', 'message' => __('请选择主题'), 'data' => []]);
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson(['status' => 'error', 'message' => __('主题不存在'), 'data' => []]);
        }

        // 获取父主题信息
        $parentTheme = null;
        if ($theme->getParentId()) {
            $parentTheme = ObjectManager::getInstance(WelineTheme::class);
            $parentTheme->load($theme->getParentId());
            if ($parentTheme->getId()) {
                $parentTheme = [
                    'id' => $parentTheme->getId(),
                    'name' => $parentTheme->getName(),
                ];
            } else {
                $parentTheme = null;
            }
        }

        // 生成预览URL（使用Url类自动处理货币和语言前缀）
        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        
        $previewUrlFrontend = $url->getBackendUrl('theme/backend/index/preview', [
            'theme_id' => $theme->getId(),
            'area' => 'frontend',
            'auto_login' => '1' // 默认开启自动登录
        ]);
        
        $previewUrlBackend = $url->getBackendUrl('theme/backend/index/preview', [
            'theme_id' => $theme->getId(),
            'area' => 'backend'
        ]);

        return $this->fetchJson([
            'status' => 'success',
            'message' => '',
            'data' => [
                'id' => $theme->getId(),
                'name' => $theme->getName(),
                'module_name' => $theme->getModuleName(),
                'path' => $theme->getPath(),
                'is_active' => $theme->getIsActive(),
                'parent_id' => $theme->getParentId(),
                'parent_theme' => $parentTheme,
                'config' => $theme->getConfig(),
                'preview_url_frontend' => $previewUrlFrontend,
                'preview_url_backend' => $previewUrlBackend,
            ]
        ]);
    }

    /**
     * 主题预览（临时激活主题并渲染真实页面）
     */
    public function getPreview()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend'); // frontend、backend 或 mobile
        $autoLogin = $this->request->getParam('auto_login', '1'); // 是否自动登录，默认开启（1=开启，0=关闭）
        
        if (!$themeId) {
            return $this->error(__('请选择主题'));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->error(__('主题不存在'));
        }

        // 将预览主题ID存储到session中，供TemplateFetchFile观察者使用
        $this->session->setData('preview_theme_id', $themeId);
        $this->session->setData('preview_theme_area', $area);
        
        // 判断是否需要自动登录
        $shouldAutoLogin = false;
        if ($area === 'frontend') {
            // 如果手动指定了 auto_login 参数，优先使用
            if ($autoLogin !== null && $autoLogin !== '') {
                $shouldAutoLogin = ($autoLogin === '1' || $autoLogin === 1 || $autoLogin === true);
            } else {
                // 否则根据布局文件的 @preview.login 标记决定
                $shouldAutoLogin = $this->shouldAutoLoginByLayout($theme, $area);
            }
        }
        
        $this->session->setData('preview_auto_login', $shouldAutoLogin);

        // 如果需要自动登录，确保预览用户已创建
        if ($shouldAutoLogin) {
            PreviewAccountManager::ensurePreviewUser($theme);
        }

        // 根据区域重定向到相应的预览页面
        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        
        if ($area === 'backend') {
            // 后端预览：重定向到后台首页
            $previewUrl = $url->getBackendUrl('admin', ['preview_theme' => $themeId]);
            $this->request->getResponse()->redirect($previewUrl);
            return '';
        } else {
            // 前端预览：重定向到前端首页
            $previewUrl = $url->getFrontendUrl('index/index', ['preview_theme' => $themeId]);
            $this->request->getResponse()->redirect($previewUrl);
            return '';
        }
    }


    /**
     * 激活主题（异步）
     */
    public function postActivate()
    {
        $themeId = $this->request->getPost('theme_id');
        
        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        try {
            // 先取消激活所有主题
            $theme->clearQuery();
            $theme->where(WelineTheme::fields_IS_ACTIVE, 1)->update(['is_active' => 0])->fetch();

            // 激活指定主题
            $theme->clearData();
            $theme->load($themeId);
            $theme->setIsActive(true);
            $theme->save();

            // 清除主题缓存
            $theme->_cache->delete('theme');
            $theme->_cache->delete('theme_parent_' . $themeId);

            return $this->fetchJson($this->success(__('主题激活成功')));
        } catch (\Exception $e) {
            return $this->fetchJson($this->error(__('激活失败：%{1}', $e->getMessage())));
        }
    }

    /**
     * 根据布局文件的 @preview.login 标记判断是否需要自动登录
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return bool 是否需要自动登录
     */
    private function shouldAutoLoginByLayout(WelineTheme $theme, string $area): bool
    {
        try {
            // 获取布局配置
            $layoutConfig = ConfigLoader::getLayoutConfig($theme, $area);
            
            // 获取默认布局类型（通常是 'default'）
            $layoutType = 'default';
            $layoutOption = $layoutConfig[$layoutType] ?? 'default';
            
            // 构建布局文件路径
            $themePath = $theme->getPath();
            if (empty($themePath)) {
                return false; // 默认不登录
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
                        return $this->shouldAutoLoginByLayout($parentTheme, $area);
                    }
                }
                
                // 如果都找不到，使用默认值
                return false;
            }
            
            // 解析布局文件的 Meta 信息
            $meta = ComponentMetaParser::parse($layoutPath);
            
            // 返回 preview_login 标记的值（默认 0，即不登录）
            return isset($meta['preview_login']) && $meta['preview_login'] == 1;
        } catch (\Exception $e) {
            // 解析失败，默认不登录
            return false;
        }
    }

    /**
     * 获取主题的meta信息和配置
     */
    public function getMetaConfig()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $type = $this->request->getParam('type', 'layouts'); // layouts, components, partials
        $identify = $this->request->getParam('identify', ''); // 如 default, account 等
        $locale = $this->request->getParam('locale') ?: (\Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN');
        
        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        // 构建meta_identify（格式：theme.frontend.layouts.default）
        // 规则统一：{type}.{identify}，不再额外追加 .default 段
        $metaIdentify = "{$type}";
        if ($identify) {
            $metaIdentify .= ".{$identify}";
        }

        // 使用 ThemeData 加载元数据信息（从缓存读取，不触发数据库查询）
        $metaDataArr = ThemeData::getMeta($metaIdentify);
        $metaData = [];
        $params = [];
        
        if ($metaDataArr) {
            $metaData = $metaDataArr['meta_data'] ?? [];
            $setting = $metaDataArr['setting'] ?? [];
            $params = $setting['param'] ?? [];
        }

        // 如果 Meta 模块中没有找到定义，尝试从模板文件解析
        if (empty($metaData) || empty($params)) {
            $filePath = $this->resolveThemeFilePath($theme, $area, $type, $identify);
            if ($filePath) {
                $parsed = ComponentMetaParser::parse($filePath);
                if (empty($metaData) && !empty($parsed['meta'])) {
                    $metaData = $parsed['meta'];
                }
                if ((empty($params) || !is_array($params)) && !empty($parsed['params'])) {
                    $params = $this->formatParsedParams($parsed['params']);
                }
            }
        }

        // 读取参数配置值（使用 ThemeData::get 从缓存读取）
        $themeConfig = [];
        $baseIdentify = "{$type}";
        if ($identify) {
            $baseIdentify .= ".{$identify}";
        }
        
        if ($params) {
            foreach ($params as $paramKey => $paramMeta) {
                $paramIdentify = "{$baseIdentify}.param.{$paramKey}.value";
                $defaultValue = $paramMeta['default'] ?? null;
                $paramValue = ThemeData::get($paramIdentify, $defaultValue);
                $themeConfig[$paramKey] = $paramValue;
            }
        }

        // 获取所有可用语言
        /** @var \Weline\I18n\Model\Locale $localeModel */
        $localeModel = ObjectManager::getInstance(\Weline\I18n\Model\Locale::class);
        $locales = $localeModel->select()->fetch()->getItems();
        $localeList = [];
        foreach ($locales as $loc) {
            $localeList[] = [
                'code' => $loc->getCode(),
                'name' => $loc->getName(),
            ];
        }

        return $this->fetchJson($this->success('', [
            'meta' => $metaData,
            'params' => $params,
            'theme_config' => $themeConfig,
            'locale' => $locale,
            'locales' => $localeList,
        ]));
    }

    /**
     * 保存主题的meta配置
     */
    public function postSaveMetaConfig()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $type = $this->request->getPost('type', 'layouts');
        $identify = $this->request->getPost('identify', '');
        $locale = $this->request->getPost('locale') ?: (\Weline\Framework\Http\Cookie::getLangLocal() ?? null);
        $configJson = $this->request->getPost('config', '{}'); // JSON格式的配置数据

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 解析配置JSON
        $config = json_decode($configJson, true);
        if (!is_array($config)) {
            $config = [];
        }

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        // 构建配置键（布局参数配置）
        // 格式：{type}.{identify}.{key}.value
        $baseConfigKey = "{$type}";
        if ($identify) {
            $baseConfigKey .= ".{$identify}";
        }
        
        // 保存每个参数配置（使用 ThemeData::set()）
        foreach ($config as $key => $value) {
            $normalizedKey = ltrim($key, '.');
            $fullIdentify = "{$baseConfigKey}.param.{$normalizedKey}.value";
            ThemeData::set($fullIdentify, (string)$value, 'default', $locale);
        }

        return $this->fetchJson($this->success(__('配置保存成功')));
    }

    /**
     * 根据类型和标识解析模板文件路径
     */
    private function resolveThemeFilePath(WelineTheme $theme, string $area, string $type, string $identify): ?string
    {
        $relativePath = $this->buildRelativePath($area, $type, $identify);
        if (!$relativePath) {
            return null;
        }
        return $this->findFileInThemeHierarchy($theme, $relativePath);
    }

    private function buildRelativePath(string $area, string $type, string $identify): ?string
    {
        $area = strtolower($area);
        $identify = trim($identify);

        switch ($type) {
            case 'layouts':
                [$layoutType, $option] = array_pad(explode('.', $identify, 2), 2, 'default');
                if (!$layoutType) {
                    $layoutType = 'default';
                }
                if (!$option) {
                    $option = 'default';
                }
                return 'view' . DS . 'theme' . DS . $area . DS . 'layouts' . DS . $layoutType . DS . $option . '.phtml';
            case 'partials':
                [$partialType, $option] = array_pad(explode('.', $identify, 2), 2, 'default');
                if (!$partialType) {
                    $partialType = 'default';
                }
                if (!$option) {
                    $option = 'default';
                }
                return 'view' . DS . 'theme' . DS . $area . DS . 'partials' . DS . $partialType . DS . $option . '.phtml';
            case 'components':
                $component = $identify ?: 'default';
                return 'view' . DS . 'theme' . DS . $area . DS . 'components' . DS . $component . '.phtml';
            case 'colors':
                $color = ltrim($identify, '_');
                if ($color === '') {
                    $color = 'default';
                }
                return 'view' . DS . 'theme' . DS . $area . DS . 'colors' . DS . '_' . $color . '.css';
            case 'variables':
                $variable = ltrim($identify, '_');
                if ($variable === '') {
                    $variable = 'colors';
                }
                return 'view' . DS . 'theme' . DS . $area . DS . 'variables' . DS . '_' . $variable . '.css';
            default:
                return null;
        }
    }

    private function findFileInThemeHierarchy(WelineTheme $theme, string $relativePath): ?string
    {
        $currentTheme = $theme;
        while ($currentTheme) {
            $themePath = $currentTheme->getPath();
            if ($themePath) {
                $fullPath = rtrim($themePath, DS) . DS . $relativePath;
                if (is_file($fullPath)) {
                    return $fullPath;
                }
            }
            $currentTheme = $currentTheme->getParentTheme();
        }

        $modules = Env::getInstance()->getModuleList();
        if (isset($modules['Weline_Theme'])) {
            $basePath = rtrim($modules['Weline_Theme']['base_path'], DS);
            $fullPath = $basePath . DS . $relativePath;
            if (is_file($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }

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
}

