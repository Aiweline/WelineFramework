<?php

declare(strict_types=1);

namespace Weline\Backend\Adapter\Notification;

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Enum\NotificationType;
use Weline\Framework\App\Env;

class DingtalkAdapter implements ChannelAdapterInterface
{
    public function getChannelCode(): string
    {
        return 'dingtalk';
    }

    public function getChannelName(): string
    {
        return __('钉钉');
    }

    public function send(array $notification, array $config): bool
    {
        $webhookUrl = $config['webhook_url'] ?? '';
        if (empty($webhookUrl)) {
            return false;
        }

        $secret = $config['secret'] ?? '';
        if (!empty($secret)) {
            $timestamp = (int)(microtime(true) * 1000);
            $sign = $this->generateSign($timestamp, $secret);
            $webhookUrl .= "&timestamp={$timestamp}&sign={$sign}";
        }

        $message = $this->formatMessage($notification);

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $webhookUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                return ($result['errcode'] ?? -1) === 0;
            }

            return false;
        } catch (\Exception $e) {
            Env::log_error('DingtalkAdapter::send failed: ' . $e->getMessage());
            return false;
        }
    }

    private function generateSign(int $timestamp, string $secret): string
    {
        $stringToSign = $timestamp . "\n" . $secret;
        $sign = hash_hmac('sha256', $stringToSign, $secret, true);
        return urlencode(base64_encode($sign));
    }

    public function formatMessage(array $notification): array
    {
        $type = NotificationType::fromString($notification['type'] ?? 'info');
        $title = $notification['title'] ?? '';
        $content = $notification['content'] ?? '';
        $topicCode = $notification['topic_code'] ?? '';

        $typeLabel = $type->getLabel();

        $markdownContent = "### [{$typeLabel}] {$title}\n\n{$content}\n\n> " . __('主题: %{topic}', ['topic' => $topicCode]);

        return [
            'msgtype' => 'markdown',
            'markdown' => [
                'title' => "[{$typeLabel}] {$title}",
                'text' => $markdownContent,
            ],
        ];
    }

    public function test(array $config): bool
    {
        $testNotification = [
            'topic_code' => 'system_info',
            'type' => 'info',
            'title' => __('钉钉渠道测试'),
            'content' => __('这是一条测试消息，如果您收到此消息，说明钉钉渠道配置正确。'),
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
                'placeholder' => 'https://oapi.dingtalk.com/robot/send?access_token=xxx',
            ],
            [
                'name' => 'secret',
                'label' => __('签名密钥'),
                'type' => 'password',
                'required' => false,
                'placeholder' => __('加签密钥（SEC 开头）'),
            ],
        ];
    }
}
