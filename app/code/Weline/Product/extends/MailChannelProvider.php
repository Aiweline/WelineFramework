<?php

declare(strict_types=1);

namespace Weline\Product\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Smtp\Service\MailTemplateDefaultLocales;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        $vars = [
            ['code' => 'message', 'label' => __('说明'), 'sample' => 'Updated'],
        ];

        return [
            [
                'code' => 'Weline_Product::product_update',
                'name' => __('产品更新通知'),
                'description' => __('产品信息/库存等更新相关邮件'),
                'module' => 'Weline_Product',
                'variables' => $vars,
                'default_templates' => MailTemplateDefaultLocales::fileEntries('product_update', 'short'),
            ],
            [
                'code' => 'Weline_Product::quote_reply',
                'name' => __('询价回复通知'),
                'description' => __('产品询价回复邮件'),
                'module' => 'Weline_Product',
                'variables' => $vars,
                'default_templates' => MailTemplateDefaultLocales::fileEntries('quote_reply', 'short'),
            ],
        ];
    }
}
