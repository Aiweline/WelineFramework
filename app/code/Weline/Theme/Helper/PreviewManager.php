<?php

/*
 * 预览管理器
 * 管理预览模式的session数据
 */

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Theme\Service\PreviewContextService;

class PreviewManager
{
    const SESSION_KEY_PREFIX = 'preview_';
    const SESSION_KEY_THEME_ID = 'preview_theme_id';
    const SESSION_KEY_THEME_AREA = 'preview_theme_area';
    
    /**
     * 设置预览配置
     * 
     * @param int $themeId 主题ID
     * @param string $area 区域
     * @param array $configs 配置数组 ['layouts' => [...], 'colors' => [...], ...]
     * @return void
     */
    public static function setPreviewConfig(int $themeId, string $area, array $configs): void
    {
        $session = ObjectManager::getInstance(Session::class);
        
        // 设置主题ID和区域
        $session->setData(self::SESSION_KEY_THEME_ID, $themeId);
        $session->setData(self::SESSION_KEY_THEME_AREA, $area);
        
        // 设置各类配置
        if (isset($configs['layouts'])) {
            $session->setData(self::SESSION_KEY_PREFIX . 'layouts_' . $area, $configs['layouts']);
        }
        if (isset($configs['headers'])) {
            $session->setData(self::SESSION_KEY_PREFIX . 'header_' . $area, $configs['headers']);
        }
        if (isset($configs['colors'])) {
            $session->setData(self::SESSION_KEY_PREFIX . 'color_' . $area, $configs['colors']);
        }
        if (isset($configs['partials'])) {
            $session->setData(self::SESSION_KEY_PREFIX . 'partials_' . $area, $configs['partials']);
        }
        if (isset($configs['variables'])) {
            $session->setData(self::SESSION_KEY_PREFIX . 'variables_' . $area, $configs['variables']);
        }
        if (isset($configs['component'])) {
            $session->setData(self::SESSION_KEY_PREFIX . 'component_' . $area, $configs['component']);
        }
        if (isset($configs['scope'])) {
            $session->setData(self::SESSION_KEY_PREFIX . 'scope_' . $area, $configs['scope']);
        }
    }
    
    /**
     * 获取预览配置
     * 
     * @param string $type 配置类型 (layouts|headers|colors|partials|variables|component)
     * @param string $area 区域
     * @return mixed 预览配置值，如果没有则返回null
     */
    public static function getPreviewConfig(string $type, string $area)
    {
        if (!self::isPreviewMode()) {
            return null;
        }
        
        $session = ObjectManager::getInstance(Session::class);
        
        // 构建session key
        $sessionKey = self::SESSION_KEY_PREFIX . $type . '_' . $area;
        
        return $session->getData($sessionKey);
    }
    
    /**
     * 获取预览主题ID
     * 
     * @return int|null
     */
    public static function getPreviewThemeId(): ?int
    {
        $session = ObjectManager::getInstance(Session::class);
        return $session->getData(self::SESSION_KEY_THEME_ID);
    }
    
    /**
     * 获取预览区域
     * 
     * @return string|null
     */
    public static function getPreviewArea(): ?string
    {
        $session = ObjectManager::getInstance(Session::class);
        return $session->getData(self::SESSION_KEY_THEME_AREA);
    }
    
    /**
     * 清除预览配置
     * 
     * @return void
     */
    public static function clearPreviewConfig(): void
    {
        $session = ObjectManager::getInstance(Session::class);
        
        // 清除所有预览相关的session数据
        $session->unsetData(self::SESSION_KEY_THEME_ID);
        $session->unsetData(self::SESSION_KEY_THEME_AREA);
        
        // 清除各类配置
        $areas = ['frontend', 'backend'];
        $types = ['layouts', 'header', 'color', 'partials', 'variables', 'component'];
        
        foreach ($areas as $area) {
            foreach ($types as $type) {
                $session->unsetData(self::SESSION_KEY_PREFIX . $type . '_' . $area);
            }
            $session->unsetData(self::SESSION_KEY_PREFIX . 'scope_' . $area);
        }
    }
    
    /**
     * 检查是否在预览模式
     * 
     * @return bool
     */
    public static function isPreviewMode(): bool
    {
        if (!self::shouldUsePreviewContext()) {
            return false;
        }

        $session = ObjectManager::getInstance(Session::class);
        $themeId = $session->getData(self::SESSION_KEY_THEME_ID);
        return !empty($themeId);
    }

    public static function getPreviewScope(string $area): ?string
    {
        if (!self::isPreviewMode()) {
            return null;
        }
        $session = ObjectManager::getInstance(Session::class);
        return $session->getData(self::SESSION_KEY_PREFIX . 'scope_' . $area);
    }

    private static function shouldUsePreviewContext(): bool
    {
        try {
            /** @var PreviewContextService $previewContextService */
            $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
            return $previewContextService->shouldUseStoredContext();
        } catch (\Throwable) {
            return true;
        }
    }
    
    /**
     * 清除预览相关的缓存
     * 
     * @param int|null $themeId 主题ID，如果为null则清除当前预览主题的缓存
     * @param string|null $area 区域，如果为null则清除所有区域的缓存
     * @return void
     */
    public static function clearPreviewCache(?int $themeId = null, ?string $area = null): void
    {
        try {
            // 清除主题缓存
            $cache = w_cache('theme');
            $cache->clear();
            
            // 清除CSS编译缓存（通过删除生成的CSS文件）
            if ($themeId) {
                /** @var \Weline\Theme\Model\WelineTheme $theme */
                $theme = ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
                $theme->load($themeId);
                
                if ($theme->getId()) {
                    /** @var \Weline\Theme\Helper\LayoutAssetsManager $assetsManager */
                    $assetsManager = ObjectManager::getInstance(\Weline\Theme\Helper\LayoutAssetsManager::class);
                    
                    $areas = $area ? [$area] : ['frontend', 'backend'];
                    $layoutTypes = ['homepage', 'account', 'default'];
                    
                    foreach ($areas as $areaItem) {
                        foreach ($layoutTypes as $layoutType) {
                            $cssPath = $assetsManager->getGeneratedCssPath($areaItem, $layoutType, 'default', $theme);
                            if (is_file($cssPath)) {
                                @unlink($cssPath);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 清除缓存失败不影响其他操作
            if (defined('DEV') && DEV) {
                w_log_error('清除预览缓存失败: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * 生成带时间戳的预览URL
     * 
     * @param int $themeId 主题ID
     * @param string $area 区域
     * @param string|null $previewPath 预览路径，如果为null则使用默认路径
     * @return string 预览URL
     */
    public static function refreshPreviewUrl(int $themeId, string $area, ?string $previewPath = null): string
    {
        // 构建基础预览URL
        if ($previewPath === null) {
            // 默认预览路径：根据area选择
            if ($area === 'frontend') {
                $previewPath = '/';
            } else {
                $previewPath = '/backend';
            }
        }
        
        // 添加预览参数和时间戳
        $url = $previewPath . '?preview_theme=' . $themeId;
        $url .= '&area=' . urlencode($area);
        $url .= '&t=' . time(); // 时间戳，强制刷新
        
        return $url;
    }
}

