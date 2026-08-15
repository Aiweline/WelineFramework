<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Block;

use Weline\Backend\Api\View\BackendThemeConfigInterface;
use Weline\Backend\Api\View\ThemePreviewModeProviderInterface;
use Weline\Backend\Model\BackendUserConfig;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

class ThemeConfig extends \Weline\Framework\View\Block implements BackendThemeConfigInterface
{
    public const        area = 'backend_';
    public const        theme_Session_Config = 'backend_theme_config';
    private const THEME_MODES = ['system', 'light', 'dark'];
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
        $userId = $this->userConfig->getCurrentUserId();
        $this->userConfig = $userId > 0 ? $this->userConfig->load($userId) : $this->userConfig;
        $this->userConfig->setId($userId);
    }

    public function reloadForCurrentUser(): void
    {
        $this->__init();
    }

    public function getOriginThemeConfig($key = '')
    {
        $this->userSession = $this->resolveSession();
        $sessionConfig = $this->userSession->getData(self::theme_Session_Config);
        $userId = $this->userConfig->getCurrentUserId();
        // WLS keeps this block alive across requests.  A preference write is
        // durable in BackendUserConfig before its session copy is flushed, so
        // the cache identity must include the durable value as well.
        $configValue = '';
        if ($userId > 0) {
            $configValue = $this->userConfig->getConfig(
                self::theme_Session_Config,
                'Weline_Backend',
                '主题设置',
                true
            );
        }
        $cacheKey = $userId . '|' . md5((json_encode($sessionConfig) ?: '') . '|' . $configValue);
        if (
            $this->originThemeConfigCacheKey === $cacheKey
            && $this->originThemeConfigCacheValue !== null
            && $this->originThemeConfigCacheExpiresAt >= microtime(true)
        ) {
            return $key ? ($this->originThemeConfigCacheValue[$key] ?? '') : $this->originThemeConfigCacheValue;
        }

        // User configuration is the durable source of truth.  A JSON response
        // can terminate before a long-lived WLS session flushes its updated
        // data; using a non-empty session value first would then resurrect an
        // older preference after refresh.
        $themeConfig = [];
        if ($configValue !== '') {
            // Theme preference is edited by a separate HTTP request.  In WLS
            // the model instance is long-lived, so bypass its process-local
            // config cache and read the just-persisted user value.
            $themeConfig = json_decode($configValue, true);
            if (!is_array($themeConfig)) {
                $themeConfig = [];
            }
        }
        if (empty($themeConfig)) {
            $themeConfig = $sessionConfig;
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
            $layouts['data-theme-preference'] = $mode;
            if ($mode === 'light' || $mode === 'dark') {
                $layouts['data-theme-mode'] = $mode;
                $layouts['data-layout-mode'] = $mode;
            } else {
                unset($layouts['data-topbar'], $layouts['data-sidebar'], $layouts['data-theme-mode'], $layouts['data-layout-mode']);
            }
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

    public function getThemeModel(): string
    {
        try {
            $previewModeProvider = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(ThemePreviewModeProviderInterface::class);
            if ($previewModeProvider instanceof ThemePreviewModeProviderInterface) {
                $previewMode = $previewModeProvider->resolveBackendMode();
                if ($previewMode !== null) {
                    return $previewMode;
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
        if ($themeMode === 'dark') {
            return 'dark';
        }
        if (!empty($themeConfig['rtl-mode-switch'])) {
            return 'rtl';
        }
        return '';
    }

    public function setThemeConfig(string|array $key, mixed $value = ''): static
    {
        $this->userSession = $this->resolveSession();
        $userId = $this->userConfig->getCurrentUserId();
        $this->resetOriginThemeConfigRuntimeCache();
        if (is_array($key)) {
            $this->assertThemeModePayload($key);
            $originConfig = $this->getOriginThemeConfig();
            if (!\is_array($originConfig)) {
                $originConfig = [];
            }
            $key = \array_merge($originConfig, $key);
            $this->userSession->setData(self::theme_Session_Config, $key);
            if ($userId > 0) {
                $this->userConfig->setConfig(self::theme_Session_Config, json_encode($key), 'Weline_Backend', '主题设置');
            }
        } else {
            if ($key === 'theme-mode-switch') {
                $this->assertThemeMode($value);
            }
            $theme_Config = $this->getOriginThemeConfig();
            $theme_Config[$key] = $value;
            $this->userSession->setData(self::theme_Session_Config, $theme_Config);
            if ($userId > 0) {
                $this->userConfig->setConfig(self::theme_Session_Config, json_encode($theme_Config), 'Weline_Backend', '主题设置');
            }
        }

        $this->resetOriginThemeConfigRuntimeCache();
        return $this;
    }

    private function resetOriginThemeConfigRuntimeCache(): void
    {
        $this->originThemeConfigCacheKey = null;
        $this->originThemeConfigCacheValue = null;
        $this->originThemeConfigCacheExpiresAt = 0.0;
    }

    private function assertThemeModePayload(array $themeConfig): void
    {
        if (array_key_exists('theme-mode-switch', $themeConfig)) {
            $this->assertThemeMode($themeConfig['theme-mode-switch']);
        }
        if (!array_key_exists('layouts', $themeConfig)) {
            return;
        }
        $layouts = $themeConfig['layouts'];
        if (!is_array($layouts)) {
            throw new \InvalidArgumentException((string)__('后端主题模式无效。'));
        }
        if (array_key_exists('data-theme-preference', $layouts)) {
            $this->assertThemeMode($layouts['data-theme-preference']);
        }
        foreach (['data-theme-mode', 'data-layout-mode'] as $key) {
            if (array_key_exists($key, $layouts)) {
                $this->assertResolvedThemeMode($layouts[$key]);
            }
        }
    }

    private function assertThemeMode(mixed $mode): void
    {
        if (!is_string($mode) || !in_array(strtolower(trim($mode)), self::THEME_MODES, true)) {
            throw new \InvalidArgumentException((string)__('后端主题模式无效。'));
        }
    }

    private function assertResolvedThemeMode(mixed $mode): void
    {
        if (!is_string($mode) || !in_array(strtolower(trim($mode)), ['light', 'dark'], true)) {
            throw new \InvalidArgumentException((string)__('后端主题模式无效。'));
        }
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
        $body_attributes['data-theme-preference'] = $themeMode;
        if ($themeMode === 'light' || $themeMode === 'dark') {
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

    /**
     * Emits the theme attributes needed on the document root before CSS loads.
     * This also leaves the CSS media-query fallback able to resolve `system`
     * when JavaScript is unavailable.
     */
    public function getThemeHtmlAttributes(): string
    {
        $themeConfig = $this->getOriginThemeConfig();
        $themeModeFromSwitch = $themeConfig['theme-mode-switch'] ?? '';
        $mode = $this->resolveThemeModeFromConfig(
            $themeConfig,
            \is_string($themeModeFromSwitch) ? $themeModeFromSwitch : ''
        );
        // `system` needs a deterministic no-JS first paint; the inline Head
        // prepaint script replaces this light placeholder before CSS evaluates.
        $resolvedMode = $mode === 'dark' ? 'dark' : 'light';
        $attributes = [
            'data-theme="' . $resolvedMode . '"',
            'data-theme-preference="' . $mode . '"',
            'data-bs-theme="' . $resolvedMode . '"',
            'data-theme-mode="' . $resolvedMode . '"',
            'data-layout-mode="' . $resolvedMode . '"',
        ];

        return implode(' ', $attributes);
    }

    private function resolveThemeModeFromConfig(array $themeConfig, string $preferredMode = ''): string
    {
        $mode = $preferredMode !== '' ? $preferredMode : ($themeConfig['theme-mode-switch'] ?? '');
        $resolvedMode = $this->normalizeThemeMode($mode);
        if ($resolvedMode !== null) {
            return $resolvedMode;
        }
        $layouts = $themeConfig['layouts'] ?? [];
        if (\is_array($layouts)) {
            $resolvedPreference = $this->normalizeThemeMode($layouts['data-theme-preference'] ?? '');
            if ($resolvedPreference !== null) {
                return $resolvedPreference;
            }
        }
        if (array_key_exists('dark-mode-switch', $themeConfig)) {
            return $this->resolveBool($themeConfig['dark-mode-switch']) ? 'dark' : 'light';
        }
        if (array_key_exists('light-mode-switch', $themeConfig)) {
            return $this->resolveBool($themeConfig['light-mode-switch']) ? 'light' : 'dark';
        }
        if (\is_array($layouts)) {
            foreach (['data-theme-mode', 'data-layout-mode'] as $layoutModeKey) {
                $resolvedLegacyMode = $this->normalizeThemeMode($layouts[$layoutModeKey] ?? '');
                if ($resolvedLegacyMode !== null) {
                    return $resolvedLegacyMode;
                }
            }
        }
        return 'system';
    }

    /**
     * Returns null only when a mode has not been supplied. Invalid explicit
     * persisted values deliberately fall back to light for rollback safety.
     */
    private function normalizeThemeMode(mixed $mode): ?string
    {
        if ($mode === null) {
            return null;
        }
        if (!\is_string($mode)) {
            return 'light';
        }
        $mode = trim(strtolower($mode));
        if ($mode === '') {
            return null;
        }
        return \in_array($mode, self::THEME_MODES, true) ? $mode : 'light';
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
