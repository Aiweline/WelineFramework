<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Block;

use Weline\Backend\Model\BackendUserConfig;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemeContextService;

class ThemeConfig extends \Weline\Framework\View\Block
{
    public const        area = 'backend_';
    public const        theme_Session_Config = 'backend_theme_config';
    private AuthenticatedSessionInterface $userSession;
    private BackendUserConfig $userConfig;
    private ?string $originThemeConfigCacheKey = null;
    private ?array $originThemeConfigCacheValue = null;
    private float $originThemeConfigCacheExpiresAt = 0.0;

    public function __construct(BackendUserConfig $userConfig, array $data = [])
    {
        parent::__construct($data);
        $this->userSession = $this->resolveSession();
        $this->userConfig = $userConfig;
    }

    private function resolveSession(): AuthenticatedSessionInterface
    {
        return SessionFactory::getInstance()->createBackendSession();
    }

    public function __init()
    {
        $this->userSession = $this->resolveSession();
        $this->userConfig = $this->userSession->getUserId() ? $this->userConfig->load($this->userSession->getUserId()) : $this->userConfig;
        $this->userConfig->setId($this->userSession->getUserId());
    }

    public function getOriginThemeConfig($key = '')
    {
        $this->userSession = $this->resolveSession();
        $sessionConfig = $this->userSession->getData(self::theme_Session_Config);
        $userId = (int)($this->userSession->getUserId() ?? 0);
        $cacheKey = $userId . '|' . md5(json_encode($sessionConfig) ?: '');
        if (
            $this->originThemeConfigCacheKey === $cacheKey
            && $this->originThemeConfigCacheValue !== null
            && $this->originThemeConfigCacheExpiresAt >= microtime(true)
        ) {
            return $key ? ($this->originThemeConfigCacheValue[$key] ?? '') : $this->originThemeConfigCacheValue;
        }

        $themeConfig = $sessionConfig;
        if (empty($themeConfig) && $this->userSession->isLoggedIn()) {
            $configValue = $this->userConfig->getConfig(self::theme_Session_Config, 'Weline_Backend', '主题设置');
            if ($configValue) {
                $themeConfig = json_decode($configValue, true);
                if (!is_array($themeConfig)) {
                    $themeConfig = [];
                }
            }
        }
        if (!is_array($themeConfig)) {
            $themeConfig = [];
        }
        $mode = $this->resolveThemeModeFromConfig($themeConfig);
        if ($mode !== '') {
            $themeConfig['theme-mode-switch'] = $mode;
            $themeConfig['dark-mode-switch'] = $mode === 'dark';
            $themeConfig['light-mode-switch'] = $mode === 'light';
            $layouts = isset($themeConfig['layouts']) && \is_array($themeConfig['layouts']) ? $themeConfig['layouts'] : [];
            $layouts['data-theme-mode'] = $mode;
            $layouts['data-layout-mode'] = $mode;
            $themeConfig['layouts'] = $layouts;
        }
        $this->originThemeConfigCacheKey = $cacheKey;
        $this->originThemeConfigCacheValue = $themeConfig;
        $this->originThemeConfigCacheExpiresAt = microtime(true) + 30.0;
        return $key ? ($themeConfig[$key] ?? '') : $themeConfig;
    }

    public function getThemeConfig(string $key = '')
    {
        $themeConfig = $this->getOriginThemeConfig();
        if ($key) {
            return $themeConfig[$key] ?? '';
        } else {
            if ($data = $this->userSession->getData(self::area . 'theme_config')) {
                return $data;
            }
            $data = $this->getOriginThemeConfig();
            if ($data) {
                $this->userSession->setData(self::theme_Session_Config, $data);
            }
        }
        return $data;
    }

    public function getThemeModel()
    {
        try {
            /** @var PreviewContextService $previewContextService */
            $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
            if ($previewContextService->shouldUseStoredContext()) {
                $context = $previewContextService->getCurrentContext();
                $previewThemeId = $previewContextService->getThemeIdForArea('backend', $context, false);
                if ($previewThemeId > 0) {
                    /** @var ThemeContextService $themeContext */
                    $themeContext = ObjectManager::getInstance(ThemeContextService::class);
                    $previewTheme = $themeContext->resolveTheme('backend');
                    if ($previewTheme && $previewTheme->getId()) {
                        $previewColor = LayoutScanner::getColorConfig($previewTheme, 'backend');
                        if ($previewColor) {
                            return $previewColor === 'light' ? '' : $previewColor;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        $themeConfig = $this->getOriginThemeConfig();
        $themeModeFromSwitch = $themeConfig['theme-mode-switch'] ?? '';
        $themeMode = $this->resolveThemeModeFromConfig(
            $themeConfig,
            \is_string($themeModeFromSwitch) ? $themeModeFromSwitch : ''
        );
        if ($themeMode !== '') {
            return $themeMode === 'light' ? '' : $themeMode;
        }
        if (!empty($themeConfig['rtl-mode-switch'])) {
            return 'rtl';
        }
        return '';
    }

    public function setThemeConfig(string|array $key, mixed $value = ''): static
    {
        $this->userSession = $this->resolveSession();
        $this->originThemeConfigCacheKey = null;
        $this->originThemeConfigCacheValue = null;
        $this->originThemeConfigCacheExpiresAt = 0.0;
        if (is_array($key)) {
            $originConfig = $this->getOriginThemeConfig();
            if (!\is_array($originConfig)) {
                $originConfig = [];
            }
            $key = \array_merge($originConfig, $key);
            $this->userSession->setData(self::theme_Session_Config, $key);
            if ($this->userSession->isLoggedIn()) {
                $this->userConfig->setConfig(self::theme_Session_Config, json_encode($key), 'Weline_Backend', '主题设置');
            }
        } else {
            $theme_Config = $this->getOriginThemeConfig();
            $theme_Config[$key] = $value;
            $this->userSession->setData(self::theme_Session_Config, $theme_Config);
            if ($this->userSession->isLoggedIn()) {
                $this->userConfig->setConfig(self::theme_Session_Config, json_encode($theme_Config), 'Weline_Backend', '主题设置');
            }
        }

        return $this;
    }


    public function getLayouts()
    {
        $this->userSession = $this->resolveSession();
        $themeConfig = $this->getOriginThemeConfig();
        $body_attributes = $themeConfig['layouts'] ?? [];
        if (!\is_array($body_attributes)) {
            $configData = $this->userConfig->getConfig(self::theme_Session_Config, 'Weline_Backend', '主题设置');
            if ($configData) {
                $decoded = json_decode($configData, true);
                $body_attributes = $decoded['layouts'] ?? [];
            }
        }
        if (!\is_array($body_attributes)) {
            $body_attributes = [];
        }
        $body_attributes_str = '';
        $class_value = '';
        // Always sync rendered theme attributes from current mode to avoid stale layout residue.
        $themeModeFromSwitch = $themeConfig['theme-mode-switch'] ?? '';
        $themeMode = $this->resolveThemeModeFromConfig(
            $themeConfig,
            \is_string($themeModeFromSwitch) ? $themeModeFromSwitch : ''
        );
        if ($themeMode !== '') {
            $body_attributes['data-theme-mode'] = $themeMode;
            $body_attributes['data-layout-mode'] = $themeMode;
        } else {
            unset($body_attributes['data-theme-mode'], $body_attributes['data-layout-mode']);
        }
        
        foreach ($body_attributes as $attribute => $value) {
            // 跳过空字符串值
            if ($value === '' || $value === null) {
                continue;
            }
            
            // 特殊处理 class 属性
            if ($attribute === 'class') {
                $class_value = $value;
                continue;
            }
            
            // 处理 data- 属性和其他属性
            if (is_string($value)) {
                $body_attributes_str .= "$attribute=\"$value\" ";
            }
        }
        
        // 添加 class 属性（如果有）
        if ($class_value !== '') {
            $body_attributes_str .= "class=\"$class_value\" ";
        }
        
        return trim($body_attributes_str);
    }

    private function resolveThemeModeFromConfig(array $themeConfig, string $preferredMode = ''): string
    {
        $mode = $preferredMode !== '' ? $preferredMode : ($themeConfig['theme-mode-switch'] ?? '');
        if (\is_string($mode)) {
            $mode = trim(strtolower($mode));
            if ($mode !== '') {
                return $mode;
            }
        }
        if ($this->resolveBool($themeConfig['dark-mode-switch'] ?? null)) {
            return 'dark';
        }
        if ($this->resolveBool($themeConfig['light-mode-switch'] ?? null)) {
            return 'light';
        }
        $layouts = $themeConfig['layouts'] ?? [];
        if (\is_array($layouts)) {
            foreach (['data-theme-mode', 'data-layout-mode'] as $layoutModeKey) {
                $layoutMode = $layouts[$layoutModeKey] ?? '';
                if (\is_string($layoutMode)) {
                    $layoutMode = trim(strtolower($layoutMode));
                    if ($layoutMode !== '') {
                        return $layoutMode;
                    }
                }
            }
        }
        return '';
    }

    private function resolveBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_numeric($value)) {
            return (int)$value === 1;
        }
        if (\is_string($value)) {
            return \in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }
        return false;
    }
}
