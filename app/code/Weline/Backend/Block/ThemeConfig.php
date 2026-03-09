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
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

class ThemeConfig extends \Weline\Framework\View\Block
{
    public const        area = 'backend_';
    public const        theme_Session_Config = 'backend_theme_config';
    private AuthenticatedSessionInterface $userSession;
    private BackendUserConfig $userConfig;

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
        $themeConfig = $this->userSession->getData(self::theme_Session_Config);
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
        // 优先检查预览配置
        $session = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
        $previewThemeId = $session->getData('preview_theme_id');
        $previewThemeArea = $session->getData('preview_theme_area');
        
        if ($previewThemeId && $previewThemeArea === 'backend') {
            // 预览模式：使用预览色系配置
            $previewColor = $session->getData('preview_color_backend');
            if ($previewColor) {
                // light 模式返回空字符串，其他模式返回对应的值
                return $previewColor === 'light' ? '' : $previewColor;
            }
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
        $body_attributes = $this->userSession->getData(self::theme_Session_Config)['layouts'] ?? [];
        if (empty($body_attributes)) {
            $configData = $this->userConfig->getConfig(self::theme_Session_Config, 'Weline_Backend', '主题设置');
            if ($configData) {
                $decoded = json_decode($configData, true);
                $body_attributes = $decoded['layouts'] ?? [];
            }
        }
        $body_attributes_str = '';
        $class_value = '';
        // Always sync rendered theme attributes from current mode to avoid stale layout residue.
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
