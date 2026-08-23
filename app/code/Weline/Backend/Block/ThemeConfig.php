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
        $rtlMode = array_key_exists('rtl-mode-switch', $themeConfig)
            ? $this->resolveBool($themeConfig['rtl-mode-switch'])
            : null;
        $mode = $this->resolveThemeModeFromConfig($themeConfig);
        $themeConfig = ['theme-mode-switch' => $mode];
        if ($rtlMode !== null) {
            $themeConfig['rtl-mode-switch'] = $rtlMode;
        }
        $this->originThemeConfigCacheKey = $cacheKey;
        $this->originThemeConfigCacheValue = $themeConfig;
        $this->originThemeConfigCacheExpiresAt = microtime(true) + 30.0;
        return $key ? ($themeConfig[$key] ?? '') : $themeConfig;
    }

    public function getThemeConfig(string $key = '')
    {
        $themeConfig = $this->getOriginThemeConfig();
        return $key !== '' ? ($themeConfig[$key] ?? '') : $themeConfig;
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
            $nextConfig = [
                'theme-mode-switch' => strtolower(trim((string)$key['theme-mode-switch'])),
            ];
            if (array_key_exists('rtl-mode-switch', $key)) {
                $nextConfig['rtl-mode-switch'] = $this->resolveBool($key['rtl-mode-switch']);
            }
            $this->userSession->setData(self::theme_Session_Config, $nextConfig);
            if ($userId > 0) {
                $this->userConfig->setConfig(self::theme_Session_Config, json_encode($nextConfig), 'Weline_Backend', '主题设置');
            }
        } else {
            if (!in_array($key, ['theme-mode-switch', 'rtl-mode-switch'], true)) {
                throw new \InvalidArgumentException((string)__('后端主题配置字段无效。'));
            }
            if ($key === 'theme-mode-switch') {
                $this->assertThemeMode($value);
                $value = strtolower(trim((string)$value));
            } else {
                $value = $this->resolveBool($value);
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
        if (!array_key_exists('theme-mode-switch', $themeConfig)) {
            throw new \InvalidArgumentException((string)__('后端主题模式不能为空。'));
        }
        foreach (array_keys($themeConfig) as $key) {
            if (!in_array($key, ['theme-mode-switch', 'rtl-mode-switch'], true)) {
                throw new \InvalidArgumentException((string)__('后端主题配置字段无效：%{1}', [$key]));
            }
        }
        $this->assertThemeMode($themeConfig['theme-mode-switch']);
    }

    private function assertThemeMode(mixed $mode): void
    {
        if (!is_string($mode) || !in_array(strtolower(trim($mode)), self::THEME_MODES, true)) {
            throw new \InvalidArgumentException((string)__('后端主题模式无效。'));
        }
    }

    public function getLayouts()
    {
        return '';
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
        $resolvedMode = $mode === 'dark' ? 'dark' : 'light';
        $attributes = [
            'data-w-area="backend"',
            'data-theme="' . $resolvedMode . '"',
            'data-theme-preference="' . $mode . '"',
        ];

        return implode(' ', $attributes);
    }

    private function resolveThemeModeFromConfig(array $themeConfig, string $preferredMode = ''): string
    {
        $mode = $preferredMode !== '' ? $preferredMode : ($themeConfig['theme-mode-switch'] ?? 'system');
        return $this->normalizeThemeMode($mode) ?? 'system';
    }

    /**
     * Missing or invalid persisted values start from the Weline UI 2.0 default.
     */
    private function normalizeThemeMode(mixed $mode): ?string
    {
        if ($mode === null) {
            return null;
        }
        if (!\is_string($mode)) {
            return 'system';
        }
        $mode = trim(strtolower($mode));
        if ($mode === '') {
            return null;
        }
        return \in_array($mode, self::THEME_MODES, true) ? $mode : 'system';
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
