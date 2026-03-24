<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Block;

use Weline\Frontend\Model\FrontendUserConfig;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemeContextService;

class ThemeConfig extends \Weline\Framework\View\Block
{
    public const        area                 = 'frontend_';
    public const        theme_Session_Config = 'frontend_theme_config';
    private AuthenticatedSessionInterface $userSession;
    private FrontendUserConfig $userConfig;

    public function __construct(FrontendUserConfig $userConfig, array $data = [])
    {
        parent::__construct($data);
        $this->userSession = $this->resolveSession();
        $this->userConfig  = $userConfig;
    }

    private function resolveSession(): AuthenticatedSessionInterface
    {
        return SessionFactory::getInstance()->createFrontendSession();
    }

    public function __init()
    {
        $this->userSession = $this->resolveSession();
        $this->userConfig = $this->userSession->getUserId() ? $this->userConfig->load($this->userSession->getUserId()) : $this->userConfig;
    }

    public function getOriginThemeConfig($key = '')
    {
        $this->userSession = $this->resolveSession();
        $themeConfig = $this->userSession->getData(self::theme_Session_Config);
        if (empty($themeConfig) and $this->userSession->isLoggedIn()) {
            $themeConfig = $this->userConfig->getData(self::theme_Session_Config);
        }
        if (\is_string($themeConfig) && $themeConfig !== '') {
            $decoded = json_decode($themeConfig, true);
            $themeConfig = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($themeConfig)) {
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
        return $key ? ($themeConfig[$key] ?? '') : ($themeConfig ?: []);
    }

    public function getThemeConfig(string $key = '')
    {
        $themeConfig = $this->getOriginThemeConfig();
        if ($key) {
            return $themeConfig[$key] ?? '';
        }
        return $themeConfig;
    }

    public function getThemeModel()
    {
        try {
            /** @var PreviewContextService $previewContextService */
            $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
            if ($previewContextService->shouldUseStoredContext()) {
                $context = $previewContextService->getCurrentContext();
                $previewThemeId = $previewContextService->getThemeIdForArea('frontend', $context, false);
                if ($previewThemeId > 0) {
                    /** @var ThemeContextService $themeContext */
                    $themeContext = ObjectManager::getInstance(ThemeContextService::class);
                    $previewTheme = $themeContext->resolveTheme('frontend');
                    if ($previewTheme && $previewTheme->getId()) {
                        $previewColor = LayoutScanner::getColorConfig($previewTheme, 'frontend');
                        if ($previewColor) {
                            return $previewColor === 'light' ? '' : $previewColor;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        $themeModeFromSwitch = $this->getThemeConfig('theme-mode-switch');
        $themeMode = $this->resolveThemeModeFromConfig(
            $this->getThemeConfig(),
            \is_string($themeModeFromSwitch) ? $themeModeFromSwitch : ''
        );
        if ($themeMode !== '') {
            return $themeMode === 'light' ? '' : $themeMode;
        }
        if ($this->getThemeConfig('rtl-mode-switch')) {
            return 'rtl';
        }
        return '';
    }

    public function setThemeConfig(string|array $key, mixed $value = ''): static
    {
        $this->userSession = $this->resolveSession();
        $theme_Config = $this->getOriginThemeConfig();
        if (is_array($key)) {
            $key = array_merge($theme_Config, $key);
            $this->userSession->setData(self::theme_Session_Config, $key);
            if ($this->userSession->isLoggedIn()) {
                $this->userConfig->setUserId($this->userSession->getUserId())->addConfig(self::theme_Session_Config, $key)->save();
            }
        } else {
            $theme_Config[$key] = $value;
            $this->userSession->setData(self::theme_Session_Config, $theme_Config);
            if ($this->userSession->isLoggedIn()) {
                $this->userConfig->setUserId($this->userSession->getUserId())->addConfig(self::theme_Session_Config, $theme_Config)->save();
            }
        }

        return $this;
    }


    public function getLayouts()
    {
        $this->userSession = $this->resolveSession();
        $body_attributes = $this->userSession->getData(self::theme_Session_Config)['layouts'] ?? [];
        if (empty($body_attributes)) {
            $decoded = json_decode((string)($this->userConfig->getData(self::theme_Session_Config) ?: ''), true);
            $body_attributes = \is_array($decoded) ? ($decoded['layouts'] ?? []) : [];
        }
        $themeModeFromSwitch = $this->getThemeConfig('theme-mode-switch');
        $themeMode = $this->resolveThemeModeFromConfig(
            $this->getThemeConfig(),
            \is_string($themeModeFromSwitch) ? $themeModeFromSwitch : ''
        );
        if ($themeMode !== '') {
            $body_attributes['data-theme-mode'] = $themeMode;
            $body_attributes['data-layout-mode'] = $themeMode;
        } else {
            unset($body_attributes['data-theme-mode'], $body_attributes['data-layout-mode']);
        }
        $body_attributes_str = '';
        foreach ($body_attributes as $attribute => $value) {
            if (is_string($value)) {
                $body_attributes_str .= "$attribute=\"$value\" ";
            }
        }
        return $body_attributes_str;
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
