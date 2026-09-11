<?php

declare(strict_types=1);

namespace Weline\Customer\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        return [
            [
                'code' => 'Weline_Customer::password_reset',
                'name' => __('客户密码重置邮件'),
                'description' => __('前台/客户密码重置'),
                'module' => 'Weline_Customer',
            ],
        ];
    }
}
