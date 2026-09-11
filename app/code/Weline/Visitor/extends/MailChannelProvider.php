<?php

declare(strict_types=1);

namespace Weline\Visitor\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        $topics = [
            'pixel_incident_js' => __('前端 JS 异常邮件'),
            'pixel_incident_promise' => __('未处理 Promise 邮件'),
            'pixel_incident_network' => __('接口技术失败邮件'),
            'pixel_incident_resource' => __('静态资源失败邮件'),
            'pixel_incident_stale_client' => __('客户端版本过期邮件'),
            'pixel_incident_unknown' => __('未分类技术错误邮件'),
            'pixel_incident_stock' => __('库存不足邮件'),
            'pixel_incident_checkout' => __('结账业务失败邮件'),
            'pixel_incident_payment' => __('支付失败邮件'),
            'pixel_incident_auth' => __('登录鉴权失败邮件'),
            'pixel_incident_business' => __('其他业务拒绝邮件'),
        ];
        $channels = [];
        foreach ($topics as $code => $name) {
            $channels[] = [
                'code' => 'Weline_Visitor::notify_' . $code,
                'name' => $name,
                'description' => __('站点错误监控主题 %{1} 的邮件渠道', [$code]),
                'module' => 'Weline_Visitor',
            ];
        }

        return $channels;
    }
}
