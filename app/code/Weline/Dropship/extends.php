<?php

declare(strict_types=1);

/**
 * Weline_Dropship 模块扩展规约（对齐 Payment）。
 */

use Weline\Dropship\Extends\MailChannelProvider;
use Weline\Smtp\Api\MailChannelProviderInterface;

return [
    MailChannelProviderInterface::class => [
        MailChannelProvider::class,
    ],
    'type' => 'module',
    'documentation' => 'doc/extends.md',
    'extends' => [
        'DropshipProvider' => [
            'path' => 'extends/module/Weline_Dropship/DropshipProvider',
            'interface' => 'Weline\Dropship\Interface\DropshipProviderInterface',
            'description' => '货源代发供应商扩展点。供应商模块实现此接口（及 Catalog/Fulfillment/Freight/Webhook 子接口）后自注入。',
            'required' => true,
            'multiple' => true,
        ],
    ],
];
