<?php

declare(strict_types=1);

namespace Weline\Product\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        $vars = [
            ['code' => 'message', 'label' => __('说明'), 'sample' => 'Updated'],
        ];
        $locales = static function (string $slug): array {
            return [
                [
                    'locale' => 'zh_Hans_CN',
                    'subject_file' => $slug . '/zh_Hans_CN.subject.txt',
                    'body_file' => $slug . '/zh_Hans_CN.html',
                ],
                [
                    'locale' => 'en_US',
                    'subject_file' => $slug . '/en_US.subject.txt',
                    'body_file' => $slug . '/en_US.html',
                ],
            ];
        };

        return [
            [
                'code' => 'Weline_Product::product_update',
                'name' => __('产品更新通知'),
                'description' => __('产品信息/库存等更新相关邮件'),
                'module' => 'Weline_Product',
                'variables' => $vars,
                'default_templates' => $locales('product_update'),
            ],
            [
                'code' => 'Weline_Product::quote_reply',
                'name' => __('询价回复通知'),
                'description' => __('产品询价回复邮件'),
                'module' => 'Weline_Product',
                'variables' => $vars,
                'default_templates' => $locales('quote_reply'),
            ],
        ];
    }
}
