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
            $previewUrl = $url->getBackendUrl('admin/index/index', ['preview_theme' => $themeId]);
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

        // 构建meta_identify（格式：theme.frontend.layouts.default 或 theme.frontend.layouts.default.default）
        $metaIdentify = "{$type}";
        if ($identify) {
            $metaIdentify .= ".{$identify}";
            // 如果是布局，还需要添加布局选项（默认default）
            if ($type === 'layouts') {
                $metaIdentify .= ".default";
            }
        }

        // 使用 ThemeData 加载元数据信息
        $metaDataObj = ThemeData::getMeta($metaIdentify);
        $metaData = [];
        $params = [];
        
        if ($metaDataObj && $metaDataObj->isLoaded()) {
            // 获取 meta_data（支持多语言）
            $allData = $metaDataObj->getAll();
            $metaData = $allData['meta_data'] ?? [];
            $params = $allData['param'] ?? [];
        } else {
            // 如果没找到，尝试查找group级别的meta
            if ($identify) {
                $groupIdentify = "{$type}.{$identify}";
                $metaDataObj = ThemeData::getMeta($groupIdentify);
                if ($metaDataObj && $metaDataObj->isLoaded()) {
                    $allData = $metaDataObj->getAll();
                    $metaData = $allData['meta_data'] ?? [];
                    $params = $allData['param'] ?? [];
                }
            }
        }

        // 从 ThemeData 获取布局参数配置（使用 .value 后缀）
        $themeConfig = [];
        if ($type === 'layouts' && $identify) {
            // 格式：layouts.{identify}.param.{paramKey}.value
            $baseConfigKey = "layouts.{$identify}";
            
            // 获取所有参数配置
            if (!empty($params)) {
                foreach ($params as $paramKey => $paramValue) {
                    $paramIdentify = "{$baseConfigKey}.param.{$paramKey}.value";
                    $configValue = ThemeData::get($paramIdentify);
                    if ($configValue !== null) {
                        $themeConfig['param'][$paramKey] = $configValue;
                    } else {
                        $themeConfig['param'][$paramKey] = $paramValue; // 使用默认值
                    }
                }
            }
        } else {
            // 使用 ThemeData 读取配置（替代旧的 setting 配置）
            // 构建完整的 identify：{type}.{identify}.param.*.value
            $baseIdentify = "{$type}";
            if ($identify) {
                $baseIdentify .= ".{$identify}";
            }
            
            // 从 ThemeData 读取所有参数配置
            $themeConfig = [];
            if ($params) {
                foreach ($params as $paramKey => $paramMeta) {
                    $paramIdentify = "{$baseIdentify}.param.{$paramKey}.value";
                    $paramValue = ThemeData::get($paramIdentify);
                    if ($paramValue !== null) {
                        // 将扁平化的配置转换为嵌套结构
                        $parts = explode('.', $paramKey);
                        $current = &$themeConfig;
                        foreach ($parts as $part) {
                            if (!isset($current[$part])) {
                                $current[$part] = [];
                            }
                            $current = &$current[$part];
                        }
                        $current = $paramValue;
                    } else {
                        // 使用默认值
                        $defaultValue = $paramMeta['default'] ?? null;
                        if ($defaultValue !== null) {
                            $parts = explode('.', $paramKey);
                            $current = &$themeConfig;
                            foreach ($parts as $part) {
                                if (!isset($current[$part])) {
                                    $current[$part] = [];
                                }
                                $current = &$current[$part];
                            }
                            $current = $defaultValue;
                        }
                    }
                }
            }
        }

        // 获取当前语言
        $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';

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
            // 如果 key 是 param.title，保存为 {type}.{identify}.param.title.value
            $fullIdentify = "{$baseConfigKey}.{$key}.value";
            ThemeData::set($fullIdentify, (string)$value);
        }

        return $this->fetchJson($this->success(__('配置保存成功')));
    }
}

