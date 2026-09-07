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
        'page_layouts' => ['account.auth', 'account_auth'],
        'position' => ['content'],
        'slot' => 'account-register-extras',
        'supports' => [
            'account-register-extras',
            'layout-account-register-extras',
        ],
        // Register page mounts via Customer fetch + tabs when enabled; keep slot for
        // Theme overrides only (avoid double-render with default_injections).
        'default_injections' => [],
    ],
];
