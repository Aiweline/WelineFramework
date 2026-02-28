<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Notification;

interface ChannelAdapterInterface
{
    /**
     * 获取渠道标识
     */
    public function getChannelCode(): string;

    /**
     * 获取渠道名称
     */
    public function getChannelName(): string;

    /**
     * 发送通知
     *
     * @param array $notification 通知数据
     *   - topic_code: string 主题标识
     *   - type: string 类型（info/success/warning/error/urgent）
     *   - title: string 标题
     *   - content: string 内容
     *   - priority: int 优先级
     *   - metadata: array 扩展数据
     * @param array $config 渠道配置（来自 NotificationChannel 模型）
     * @return bool 是否发送成功
     */
    public function send(array $notification, array $config): bool;

    /**
     * 格式化消息内容
     *
     * @param array $notification 通知数据
     * @return array 格式化后的消息（渠道特定格式）
     */
    public function formatMessage(array $notification): array;

    /**
     * 测试渠道连通性
     *
     * @param array $config 渠道配置
     * @return bool 是否连通
     */
    public function test(array $config): bool;

    /**
     * 获取配置字段定义（用于后台表单）
     *
     * @return array 字段定义数组
     *   每个元素格式：
     *   [
     *     'name' => 'webhook_url',
     *     'label' => 'Webhook URL',
     *     'type' => 'text', // text/password/textarea/select
     *     'required' => true,
     *     'placeholder' => '',
     *     'options' => [], // 仅 select 类型需要
     *   ]
     */
    public function getConfigFields(): array;
}
