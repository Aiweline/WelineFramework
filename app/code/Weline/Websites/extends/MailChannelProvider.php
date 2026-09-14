<?php

declare(strict_types=1);

namespace Weline\Websites\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Smtp\Service\MailTemplateDefaultLocales;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        $variables = [
            ['code' => 'title', 'label' => __('标题'), 'sample' => 'Domain'],
            ['code' => 'content', 'label' => __('正文'), 'sample' => 'Details'],
            ['code' => 'type_label', 'label' => __('类型'), 'sample' => 'Info'],
        ];
        $defs = [
            'notify_domain_expiring' => __('域名到期提醒邮件'),
            'notify_domain_transfer' => __('域名转移通知邮件'),
            'notify_domain_pool_resolve_off_local' => __('域名池解析偏离邮件'),
        ];
        $templates = MailTemplateDefaultLocales::fileEntries('notification', 'short');
        $channels = [];
        foreach ($defs as $slug => $name) {
            $channels[] = [
                'code' => 'Weline_Websites::' . $slug,
                'name' => $name,
                'description' => __('通知主题邮件渠道'),
                'module' => 'Weline_Websites',
                'variables' => $variables,
                'default_templates' => $templates,
            ];
        }

        return $channels;
    }
}
