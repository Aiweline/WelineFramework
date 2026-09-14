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
                'variables' => [
                    ['code' => 'verification_url', 'label' => __('验证链接'), 'sample' => 'https://example.com/bind/verify?token=preview'],
                    ['code' => 'email', 'label' => __('待绑定邮箱'), 'sample' => 'user@example.com'],
                ],
                'default_templates' => [
                    [
                        'locale' => 'zh_Hans_CN',
                        'subject_file' => 'view/email/email_binding/zh_Hans_CN.subject.txt',
                        'body_file' => 'view/email/email_binding/zh_Hans_CN.html',
                    ],
                    [
                        'locale' => 'en_US',
                        'subject_file' => 'view/email/email_binding/en_US.subject.txt',
                        'body_file' => 'view/email/email_binding/en_US.html',
                    ],
                ],
            ],
        ];
    }
}
