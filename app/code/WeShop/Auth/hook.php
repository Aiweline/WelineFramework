<?php
/**
 * WeShop_Auth module hook specification file.
 */
return [
    'WeShop_Auth::backend::account::security::cards' => [
        'name' => __('后台账号安全卡片'),
        'description' => __('在后台账号安全中心注入登录安全模块，例如双因素认证、第三方登录绑定和会话管理。'),
        'doc' => 'backend/account/security/cards.md',
    ],
];
