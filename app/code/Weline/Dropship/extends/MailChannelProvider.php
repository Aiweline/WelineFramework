<?php

declare(strict_types=1);

namespace Weline\Dropship\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        return [
            [
                'code' => 'Weline_Dropship::fulfillment_consolation',
                'name' => __('货源无法履约安慰通知'),
                'description' => __('推单无法履约且已启动自动退款后的顾客安慰邮件'),
                'module' => 'Weline_Dropship',
                'variables' => [
                    ['code' => 'order_uuid', 'label' => __('订单 UUID'), 'sample' => 'ord-uuid-example'],
                    ['code' => 'message', 'label' => __('安慰文案'), 'sample' => '已启动退款'],
                ],
                'default_templates' => [
                    [
                        'locale' => 'zh_Hans_CN',
                        'subject_file' => 'view/email/fulfillment_consolation/zh_Hans_CN.subject.txt',
                        'body_file' => 'view/email/fulfillment_consolation/zh_Hans_CN.html',
                    ],
                    [
                        'locale' => 'en_US',
                        'subject_file' => 'view/email/fulfillment_consolation/en_US.subject.txt',
                        'body_file' => 'view/email/fulfillment_consolation/en_US.html',
                    ],
                ],
            ],
        ];
    }
}
