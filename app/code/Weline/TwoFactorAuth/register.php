<?php

declare(strict_types=1);

/**
 * Weline TwoFactorAuth Module Registration
 * 
 * 双因素身份验证模块
 * - 完全自主开发的TOTP算法实现
 * - 兼容所有标准2FA应用（Google Authenticator等）
 * - 包含独立的PWA客户端应用
 * 
 * @package Weline_TwoFactorAuth
 * @author WelineFramework Team
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_TwoFactorAuth',
    __DIR__,
    '1.0.0',
    '完全自主开发的双因素身份验证模块，提供标准TOTP实现和独立PWA客户端应用'
);

