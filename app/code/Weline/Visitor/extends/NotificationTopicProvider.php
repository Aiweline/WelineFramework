<?php
declare(strict_types=1);

namespace Weline\Visitor\Extends;

use Weline\Backend\Api\NotificationTopicProviderInterface;

/**
 * 像素错误监控 Msg 主题：每个 error_type 对应一个可订阅 topic。
 */
class NotificationTopicProvider implements NotificationTopicProviderInterface
{
    public const GROUP = 'pixel_incident';

    /**
     * @return list<array<string, mixed>>
     */
    public function getTopics(): array
    {
        $groupName = (string)__('站点错误监控');
        $defaultsTech = ['backend', 'email'];
        $defaultsOps = ['backend', 'email', 'webhook'];

        $topics = [
            ['code' => 'pixel_incident_js', 'name' => __('前端 JS 异常'), 'description' => __('window.onerror / ErrorEvent 运行时错误'), 'icon' => 'warning', 'color' => '#f06548', 'channels' => $defaultsTech],
            ['code' => 'pixel_incident_promise', 'name' => __('未处理 Promise'), 'description' => __('unhandledrejection'), 'icon' => 'warning', 'color' => '#f06548', 'channels' => $defaultsTech],
            ['code' => 'pixel_incident_network', 'name' => __('接口技术失败'), 'description' => __('网络中断、超时、5xx、协议失败'), 'icon' => 'wifi', 'color' => '#f1b44c', 'channels' => $defaultsTech],
            ['code' => 'pixel_incident_resource', 'name' => __('静态资源失败'), 'description' => __('关键 script/css 加载失败'), 'icon' => 'file', 'color' => '#f1b44c', 'channels' => $defaultsTech],
            ['code' => 'pixel_incident_stale_client', 'name' => __('客户端版本过期'), 'description' => __('缓存旧包与当前发布不一致'), 'icon' => 'refresh', 'color' => '#50a5f1', 'channels' => $defaultsTech],
            ['code' => 'pixel_incident_unknown', 'name' => __('未分类技术错误'), 'description' => __('未能归类的技术错误'), 'icon' => 'help', 'color' => '#74788d', 'channels' => $defaultsTech],
            ['code' => 'pixel_incident_stock', 'name' => __('库存不足'), 'description' => __('库存不足或不可售'), 'icon' => 'package', 'color' => '#f1b44c', 'channels' => $defaultsOps],
            ['code' => 'pixel_incident_checkout', 'name' => __('结账业务失败'), 'description' => __('结账 freeze/submit 等业务失败'), 'icon' => 'cart', 'color' => '#f06548', 'channels' => $defaultsOps],
            ['code' => 'pixel_incident_payment', 'name' => __('支付失败'), 'description' => __('支付或支付恢复失败'), 'icon' => 'credit-card', 'color' => '#f06548', 'channels' => $defaultsOps],
            ['code' => 'pixel_incident_auth', 'name' => __('登录鉴权失败'), 'description' => __('登录或鉴权失败'), 'icon' => 'lock', 'color' => '#f1b44c', 'channels' => $defaultsTech],
            ['code' => 'pixel_incident_business', 'name' => __('其他业务拒绝'), 'description' => __('优惠码、限购、风控等业务拒绝'), 'icon' => 'ban', 'color' => '#50a5f1', 'channels' => $defaultsOps],
        ];

        $out = [];
        foreach ($topics as $topic) {
            $out[] = [
                'code' => (string)$topic['code'],
                'name' => (string)$topic['name'],
                'group' => self::GROUP,
                'group_name' => $groupName,
                'description' => (string)$topic['description'],
                'icon' => (string)$topic['icon'],
                'color' => (string)$topic['color'],
                'default_channels' => $topic['channels'],
            ];
        }

        return $out;
    }
}
