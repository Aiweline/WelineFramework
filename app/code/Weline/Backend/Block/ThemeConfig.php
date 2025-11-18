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
use Weline\Backend\Session\BackendSession;

class ThemeConfig extends \Weline\Framework\View\Block
{
    public const        area = 'backend_';
    public const        theme_Session_Config = 'backend_theme_config';
    private BackendSession $userSession;
    private BackendUserConfig $userConfig;

    public function __construct(BackendUserConfig $userConfig, BackendSession $backendSession, array $data = [])
    {
        parent::__construct($data);
        $this->userSession = $backendSession;
        $this->userConfig = $userConfig;
    }

    public function __init()
    {
        $this->userConfig = $this->userSession->getLoginUserID() ? $this->userConfig->load($this->userSession->getLoginUserID()) : $this->userConfig;
        $this->userConfig->setId($this->userSession->getLoginUserID());
    }

    public function getOriginThemeConfig($key = '')
    {
        $themeConfig = $this->userSession->getData(self::theme_Session_Config);
        if (empty($themeConfig) and $this->userSession->isLogin()) {
            $themeConfig = $this->userConfig->getData(self::theme_Session_Config);
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
            $data = $this->userConfig->getOriginThemeConfig();
            # 保存配置 (更新session配置)
            if ($data) {
                $this->setThemeConfig($data);
            }
        }
        return $data;
    }

    public function getThemeModel()
    {
        $data = '';
        // 优先检查 theme-mode-switch（新的统一配置方式）
        $themeMode = $this->getThemeConfig('theme-mode-switch');
        if (!empty($themeMode)) {
            // light 模式返回空字符串，用于加载 app.css 而不是 app-light.css
            // dark 模式返回 'dark'，用于加载 app-dark.css
            // 其他模式返回对应的值，用于加载 app-{mode}.css
            if ($themeMode === 'light') {
                $data = '';
            } else {
                $data = $themeMode;
            }
        } elseif ($this->getThemeConfig('rtl-mode-switch')) {
            $data = 'rtl';
        }
        // 兼容旧的配置方式（向后兼容）
        if (empty($data)) {
            if ($this->getThemeConfig('dark-mode-switch')) {
                $data = 'dark';
            } elseif ($this->getThemeConfig('light-mode-switch')) {
                $data = '';
            }
        }
        return $data;
    }

    public function setThemeConfig(string|array $key, mixed $value = ''): static
    {
        if (is_array($key)) {
            $this->userSession->setData(self::theme_Session_Config, $key);
            if ($this->userSession->isLogin()) {
                $this->userConfig->setConfig(self::theme_Session_Config, json_encode($key), 'Weline_Backend', '主题设置');
            }
        } else {
            $theme_Config = $this->getOriginThemeConfig();
            $theme_Config[$key] = $value;
            $this->userSession->setData(self::theme_Session_Config, $theme_Config);
            if ($this->userSession->isLogin()) {
                $this->userConfig->setConfig(self::theme_Session_Config, $theme_Config, 'Weline_Backend', '主题设置');
            }
        }

        return $this;
    }


    public function getLayouts()
    {
        $body_attributes = $this->userSession->getData(self::theme_Session_Config)['layouts'] ?? [];
        if (empty($body_attributes)) {
            $configData = $this->userConfig->getData(self::theme_Session_Config);
            if ($configData) {
                $decoded = json_decode($configData, true);
                $body_attributes = $decoded['layouts'] ?? [];
            }
        }
        $body_attributes_str = '';
        $class_value = '';
        
        // 自动添加 data-theme-mode 属性
        // 如果配置中没有明确设置 data-theme-mode，则根据主题色系配置自动设置
        if (!isset($body_attributes['data-theme-mode'])) {
            // 从配置中动态获取主题模式
            $themeConfig = $this->getOriginThemeConfig();
            $themeMode = $themeConfig['theme-mode-switch'] ?? '';
            
            // 如果配置了主题模式，设置 data-theme-mode 属性
            // 主题模式可以是 'dark'、'light' 或任何其他主题名称
            if (!empty($themeMode)) {
                $body_attributes['data-theme-mode'] = $themeMode;
            }
            // 如果没有配置主题模式，不添加 data-theme-mode 属性，让浏览器使用默认主题
        }
        // 如果配置中已经设置了 data-theme-mode，则使用配置的值（不覆盖）
        
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
}
