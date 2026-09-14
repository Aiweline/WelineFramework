<?php

declare(strict_types=1);

namespace Weline\Customer\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Smtp\Service\MailTemplateDefaultLocales;

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
                'variables' => [
                    ['code' => 'reset_url', 'label' => __('重置链接'), 'sample' => 'https://example.com/reset?token=preview'],
                    ['code' => 'customer_email', 'label' => __('客户邮箱'), 'sample' => 'user@example.com'],
                ],
                'default_templates' => MailTemplateDefaultLocales::fileEntries('password_reset'),
            ],
        ];
    }
}
