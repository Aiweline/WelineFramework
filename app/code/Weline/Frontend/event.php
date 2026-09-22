<?php
return [
    // ========== 前端账户事件 ==========
    // 登录已迁至 Customer：Weline_Customer_Account_Login::login_after（见 Customer/doc/event）
    'Weline_Frontend_Account_Login::login_after' => [
        'name' => __('前端账户登录后（已废弃·迁 Customer）'),
        'description' => __('已无派发。请改听 Weline_Customer_Account_Login::login_after。'),
        'doc' => 'account/前端账户登录后.md',
    ],
    'Weline_Frontend_Account_Register::register_after' => [
        'name' => __('前端账户注册后'),
        'description' => __('前端用户注册成功后触发（现由 CustomerAccountService 派发本事件名），允许其他模块监听注册事件并执行相应操作。'),
        'doc' => 'account/前端账户注册后.md',
    ],
];

