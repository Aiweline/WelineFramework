<?php
return [
    // ========== 前端账户事件 ==========
    'Weline_Frontend_Account_Login::login_after' => [
        'name' => __('前端账户登录后'),
        'description' => __('前端用户登录成功后触发，允许其他模块监听登录事件并执行相应操作。'),
        'doc' => 'account/前端账户登录后.md',
    ],
    'Weline_Frontend_Account_Register::register_after' => [
        'name' => __('前端账户注册后'),
        'description' => __('前端用户注册成功后触发，允许其他模块监听注册事件并执行相应操作。'),
        'doc' => 'account/前端账户注册后.md',
    ],
];

