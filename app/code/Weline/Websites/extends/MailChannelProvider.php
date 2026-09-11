<?php

declare(strict_types=1);

namespace Weline\Websites\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        return [
            [
                'code' => 'Weline_Websites::notify_domain_expiring',
                'name' => __('域名到期提醒邮件'),
                'description' => __('通知主题 domain_expiring 的邮件渠道'),
                'module' => 'Weline_Websites',
            ],
            [
                'code' => 'Weline_Websites::notify_domain_transfer',
                'name' => __('域名转移通知邮件'),
                'description' => __('通知主题 domain_transfer 的邮件渠道'),
                'module' => 'Weline_Websites',
            ],
            [
                'code' => 'Weline_Websites::notify_domain_pool_resolve_off_local',
                'name' => __('域名池解析偏离邮件'),
                'description' => __('通知主题 domain_pool_resolve_off_local 的邮件渠道'),
                'module' => 'Weline_Websites',
            ],
        ];
    }
}
