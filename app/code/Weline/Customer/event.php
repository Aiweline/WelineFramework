<?php

/*
 * Weline_Customer 模块事件规约
 */

return [
    'Weline_Customer_Account_Login::login_after' => [
        'name' => __('客户账户登录后'),
        'description' => __('客户身份和前台会话建立成功后同步触发，供购物车合并等登录后业务扩展使用。'),
        'doc' => 'account/客户账户登录后.md',
    ],
];
