<?php
declare(strict_types=1);

/**
 * Weline AppStore - 子站应用商城
 *
 * 子站应用商城模块，支持绑定官网账户、浏览下载模块、自动安装
 * 通过 PlatformAppStore API 获取模块列表、下载模块、验证许可证
 *
 * @author Aiweline
 * @email aiweline@qq.com
 * @website aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_AppStore',
    __DIR__,
    '1.0.0',
    __('子站应用商城，支持绑定官网账户、浏览下载模块、自动安装已购模块')
);
