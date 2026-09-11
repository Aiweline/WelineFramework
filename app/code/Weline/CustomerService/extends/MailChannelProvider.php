<?php

declare(strict_types=1);

namespace Weline\CustomerService\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        return [
            [
                'code' => 'Weline_CustomerService::email_binding',
                'name' => __('客服邮箱绑定验证'),
                'description' => __('客户绑定邮箱时的验证邮件'),
                'module' => 'Weline_CustomerService',
            ],
        ];
    }
}
