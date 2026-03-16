<?php
declare(strict_types=1);

/**
 * Weline PlatformAppStore - 官方平台应用商店
 *
 * 官方平台模块仓库，提供模块上传、版本管理、许可证服务、开发者中心等功能
 * 供子站 AppStore 调用 API 获取模块列表、下载模块、验证许可证
 *
 * @author Aiweline
 * @email aiweline@qq.com
 * @website aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_PlatformAppStore',
    __DIR__,
    '1.0.0',
    __('官方平台应用商店，提供模块仓库、版本管理、许可证服务、开发者中心，供子站 AppStore 调用')
);
