<?php

declare(strict_types=1);

return [
    'Weline_Captcha::providers::collect' => [
        'name' => __('收集人机验证提供者'),
        'description' => __('向统一 Captcha 注册 VerificationProviderInterface 实现。'),
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'providers' => ['type' => 'array', 'required' => true, 'description' => 'provider code 到实例的映射'],
        ],
    ],
    'Weline_Captcha::domains::collect' => [
        'name' => __('收集人机验证允许域名'),
        'description' => __('由 Websites 等域名所有者补充 reCAPTCHA hostname 白名单，Captcha 不反向依赖业务模块。'),
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'domains' => ['type' => 'array', 'required' => true, 'description' => '规范化域名列表'],
        ],
    ],
];
