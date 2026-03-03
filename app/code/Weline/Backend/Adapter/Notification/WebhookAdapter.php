<?php

declare(strict_types=1);

namespace Weline\Backend\Adapter\Notification;

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Enum\NotificationType;
use Weline\Framework\App\Env;

class WebhookAdapter implements ChannelAdapterInterface
{
    public function getChannelCode(): string
    {
        return 'webhook';
    }

    public function getChannelName(): string
    {
        return __('Webhook');
    }

    public function send(array $notification, array $config): bool
    {
        $webhookUrl = $config['webhook_url'] ?? '';
        if (empty($webhookUrl)) {
            return false;
        }

        $message = $this->formatMessage($notification);

        $headers = ['Content-Type: application/json'];

        $secret = $config['secret'] ?? '';
        if (!empty($secret)) {
            $payload = json_encode($message);
            $signature = hash_hmac('sha256', $payload, $secret);
            $headers[] = 'X-Signature: ' . $signature;
        }

        $customHeaders = $config['custom_headers'] ?? '';
        if (!empty($customHeaders)) {
            $lines = explode("\n", $customHeaders);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && strpos($line, ':') !== false) {
                    $headers[] = $line;
                }
            }
        }

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $webhookUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode >= 200 && $httpCode < 300;
        } catch (\Exception $e) {
            w_log_error('WebhookAdapter::send failed: ' . $e->getMessage(), [], 'notification');
            return false;
        }
    }

    public function formatMessage(array $notification): array
    {
        $type = NotificationType::fromString($notification['type'] ?? 'info');

        return [
            'event' => 'notification',
            'topic_code' => $notification['topic_code'] ?? '',
            'type' => $notification['type'] ?? 'info',
            'type_label' => $type->getLabel(),
            'priority' => $notification['priority'] ?? $type->getPriority(),
            'title' => $notification['title'] ?? '',
            'content' => $notification['content'] ?? '',
            'metadata' => $notification['metadata'] ?? [],
            'timestamp' => date('c'),
        ];
    }

    public function test(array $config): bool
    {
        $testNotification = [
            'topic_code' => 'system_info',
            'type' => 'info',
            'title' => __('Webhook 渠道测试'),
            'content' => __('这是一条测试消息，如果您的服务收到此请求，说明 Webhook 渠道配置正确。'),
        ];

        return $this->send($testNotification, $config);
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'webhook_url',
                'label' => __('Webhook URL'),
                'type' => 'text',
                'required' => true,
                'placeholder' => 'https://your-server.com/webhook',
            ],
            [
                'name' => 'secret',
                'label' => __('签名密钥（可选）'),
                'type' => 'password',
                'required' => false,
                'placeholder' => __('用于生成 X-Signature 头'),
            ],
            [
                'name' => 'custom_headers',
                'label' => __('自定义请求头（可选）'),
                'type' => 'textarea',
                'required' => false,
                'placeholder' => "Authorization: Bearer xxx\nX-Custom: value",
            ],
        ];
    }
}
