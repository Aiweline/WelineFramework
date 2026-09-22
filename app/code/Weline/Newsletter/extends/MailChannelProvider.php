<?php

declare(strict_types=1);

namespace Weline\Newsletter\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Smtp\Service\MailTemplateDefaultLocales;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        return [
            [
                'code' => 'Weline_Newsletter::subscribe_welcome',
                'name' => __('邮件订阅确认'),
                'description' => __('订阅成功欢迎邮件（无券或仅订阅）'),
                'module' => 'Weline_Newsletter',
                'variables' => [
                    ['code' => 'email', 'label' => __('订阅邮箱'), 'sample' => 'guest@example.com'],
                    ['code' => 'topics_label', 'label' => __('主题偏好'), 'sample' => '优惠活动 / 上新资讯'],
                    ['code' => 'site_name', 'label' => __('站点名称'), 'sample' => 'Weline'],
                ],
                'default_templates' => MailTemplateDefaultLocales::fileEntries('subscribe_welcome'),
            ],
            [
                'code' => 'Weline_Newsletter::subscribe_gift',
                'name' => __('邮件订阅欢迎礼'),
                'description' => __('订阅发券通知（含券码）'),
                'module' => 'Weline_Newsletter',
                'variables' => [
                    ['code' => 'email', 'label' => __('订阅邮箱'), 'sample' => 'guest@example.com'],
                    ['code' => 'coupon_code', 'label' => __('优惠券码'), 'sample' => 'MWABCD1234'],
                    ['code' => 'discount_label', 'label' => __('折扣说明'), 'sample' => '10%'],
                    ['code' => 'valid_until', 'label' => __('有效期至'), 'sample' => '2026-10-06'],
                    ['code' => 'shop_url', 'label' => __('商店链接'), 'sample' => 'https://example.com/'],
                    ['code' => 'topics_label', 'label' => __('主题偏好'), 'sample' => '优惠活动 / 上新资讯'],
                    ['code' => 'site_name', 'label' => __('站点名称'), 'sample' => 'Weline'],
                ],
                'default_templates' => MailTemplateDefaultLocales::fileEntries('subscribe_gift'),
            ],
        ];
    }
}
