<?php

declare(strict_types=1);

namespace Weline\Backend\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        return [
            [
                'code' => 'Weline_Backend::notification_email',
                'name' => __('后台通知邮件（默认）'),
                'description' => __('通知中心邮件渠道默认回退；无主题专用绑定时使用'),
                'module' => 'Weline_Backend',
            ],
            [
                'code' => 'Weline_Backend::notify_system_alert',
                'name' => __('系统告警邮件'),
                'description' => __('通知主题 system_alert 的邮件渠道'),
                'module' => 'Weline_Backend',
            ],
            [
                'code' => 'Weline_Backend::notify_security_alert',
                'name' => __('安全告警邮件'),
                'description' => __('通知主题 security_alert 的邮件渠道'),
                'module' => 'Weline_Backend',
            ],
        ];
    }
}
