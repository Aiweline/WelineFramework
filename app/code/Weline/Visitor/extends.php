<?php

declare(strict_types=1);

/*
 * Weline_Visitor 扩展规约：像素事件供应商壳（对齐万能支付）。
 */

use Weline\Backend\Api\NotificationTopicProviderInterface;
use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Visitor\Extends\MailChannelProvider;
use Weline\Visitor\Extends\NotificationTopicProvider;
use Weline\Visitor\Interface\EventChainProviderInterface;
use Weline\Visitor\Interface\PixelEventVendorInterface;

return [
    NotificationTopicProviderInterface::class => [
        NotificationTopicProvider::class,
    ],
    MailChannelProviderInterface::class => [
        MailChannelProvider::class,
    ],
    'type' => 'module',
    'documentation' => 'doc/像素事件供应商管理-定稿合同.md',
    'extends' => [
        'PixelEventVendor' => [
            'path' => 'extends/module/Weline_Visitor/PixelEventVendor',
            'interface' => PixelEventVendorInterface::class,
            'description' => '像素第三方事件供应商扩展点。其他模块可实现此接口接入 GA4/GTM/自有像素；壳负责配置、搭接、沙盒/注入调度。',
            'required' => true,
            'multiple' => true,
        ],
        'EventChainProvider' => [
            'path' => 'extends/module/Weline_Visitor/EventChainProvider',
            'interface' => EventChainProviderInterface::class,
            'description' => '高级事件链贡献扩展点。业务模块可声明漏斗步骤与 complete_event；亦可通过事件 Weline_Visitor::event_chain_collect 追加。',
            'required' => false,
            'multiple' => true,
        ],
    ],
];
