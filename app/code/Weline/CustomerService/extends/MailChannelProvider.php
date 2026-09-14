<?php

declare(strict_types=1);

namespace Weline\CustomerService\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Smtp\Service\MailTemplateDefaultLocales;

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
                'variables' => [
                    ['code' => 'verification_url', 'label' => __('验证链接'), 'sample' => 'https://example.com/bind/verify?token=preview'],
                    ['code' => 'email', 'label' => __('待绑定邮箱'), 'sample' => 'user@example.com'],
                ],
                'default_templates' => MailTemplateDefaultLocales::fileEntries('email_binding'),
            ],
        ];
    }
}
