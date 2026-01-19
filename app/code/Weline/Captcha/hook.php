<?php
/**
 * Weline_Captcha 模块 Hook 规约文件
 * 
 * 本文件定义了 Weline_Captcha 模块提供的所有 Hook 扩展点
 */
return [
    // ==================== Backend Login ====================
    'Weline_Captcha::backend::layouts::login::captcha' => [
        'name' => __('后台登录验证码'),
        'description' => __('在后台登录页面显示验证码，允许其他模块自定义验证码显示方式。'),
        'doc' => 'backend/layouts/login/captcha.md',
    ],
];
