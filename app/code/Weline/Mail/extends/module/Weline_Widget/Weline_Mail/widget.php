<?php

declare(strict_types=1);

/**
 * Mail storefront widgets via default_injections.
 * 企业邮箱仅提供注册申请入口；站内登录统一走 Customer 普通登录，不单独挂企业邮箱登录。
 */
return [
    'account-mail-register' => [
        'name' => '企业邮箱注册',
        'description' => '注册页申请本站企业邮箱；须 SystemConfig 开启（mail/frontend_register）。',
        'type' => 'content',
        'code' => 'account-mail-register',
        'area' => 'frontend',
        'template' => 'Weline_Mail::templates/frontend/widgets/account-mail-register.phtml',
        'page_layouts' => ['account/register', 'account.auth'],
        'position' => ['content'],
        'slot' => 'account-mail-register-panel',
        'supports' => [
            'account-register-extras',
            'account-mail-register-panel',
            'layout-account-register-extras',
        ],
        // Customer 注册模板空槽承载，开关与域名条件仍由 Mail 部件执行。
        'placement' => 'injection',
        'default_injections' => [
            [
                'layout_type' => 'account/register',
                'layout_option' => 'default',
                'slot' => 'account-mail-register-panel',
                'area' => 'content',
                'sort_order' => 0,
                'required' => true,
                'config' => ['mail_register_variant' => 'panel'],
            ],
            [
                'layout_type' => 'account',
                'layout_option' => 'auth',
                'slot' => 'account-mail-register-panel',
                'area' => 'content',
                'sort_order' => 0,
                'required' => true,
                'config' => ['mail_register_variant' => 'panel'],
            ],
        ],
    ],
];
