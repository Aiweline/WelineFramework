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
        if ($this->getThemeConfig('dark-mode-switch')) {
            $data = 'dark';
        } elseif ($this->getThemeConfig('rtl-mode-switch')) {
            $data = 'rtl';
        } elseif ($this->getThemeConfig('light-mode-switch')) {
            $data = '';
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
            $body_attributes = json_decode($this->userConfig->getData(self::theme_Session_Config) ?? '')['layouts'] ?? [];
        }
        $body_attributes_str = '';
        foreach ($body_attributes as $attribute => $value) {
            if (is_string($value)) {
                $body_attributes_str .= "$attribute=\"$value\" ";
            }
        }
        return $body_attributes_str;
    }
}
