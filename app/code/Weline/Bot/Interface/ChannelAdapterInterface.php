<?php
declare(strict_types=1);

namespace Weline\Bot\Interface;

/**
 * 渠道适配器接口
 *
 * 用于对接不同的消息渠道（钉钉、飞书、Telegram 等）
 */
interface ChannelAdapterInterface
{
    /**
     * 获取渠道代码
     */
    public function getChannelCode(): string;

    /**
     * 获取渠道名称
     */
    public function getChannelName(): string;

    /**
     * 发送消息
     *
     * @param string $message 消息内容
     * @param array $context 上下文信息（用户ID、会话ID等）
     * @return bool
     */
    public function send(string $message, array $context = []): bool;

    /**
     * 接收消息（用于 Webhook 回调等）
     *
     * @return iterable<Message>
     */
    public function receive(): iterable;

    /**
     * 验证回调签名
     *
     * @param array $data 回调数据
     * @param string $signature 签名
     * @return bool
     */
    public function verifySignature(array $data, string $signature): bool;

    /**
     * 获取配置字段定义
     */
    public function getConfigFields(): array;

    /**
     * 测试连接
     */
    public function testConnection(): bool;
}
