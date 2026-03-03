<?php

declare(strict_types=1);

namespace Weline\Backend\Adapter\Notification;

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Enum\NotificationType;
use Weline\Framework\App\Env;

class FeishuAdapter implements ChannelAdapterInterface
{
    public function getChannelCode(): string
    {
        return 'feishu';
    }

    public function getChannelName(): string
    {
        return __('飞书');
    }

    public function send(array $notification, array $config): bool
    {
        $webhookUrl = $config['webhook_url'] ?? '';
        if (empty($webhookUrl)) {
            return false;
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
                return ($result['code'] ?? -1) === 0;
            }

            return false;
        } catch (\Exception $e) {
            w_log_error('FeishuAdapter::send failed: ' . $e->getMessage(), [], 'notification');
            return false;
        }
    }

    public function formatMessage(array $notification): array
    {
        $type = NotificationType::fromString($notification['type'] ?? 'info');
        $title = $notification['title'] ?? '';
        $content = $notification['content'] ?? '';
        $topicCode = $notification['topic_code'] ?? '';

        $typeLabel = $type->getLabel();
        $color = match ($type) {
            NotificationType::SUCCESS => 'green',
            NotificationType::WARNING => 'orange',
            NotificationType::ERROR, NotificationType::URGENT => 'red',
            default => 'blue',
        };

        return [
            'msg_type' => 'interactive',
            'card' => [
                'config' => [
                    'wide_screen_mode' => true,
                ],
                'header' => [
                    'title' => [
                        'tag' => 'plain_text',
                        'content' => "[{$typeLabel}] {$title}",
                    ],
                    'template' => $color,
                ],
                'elements' => [
                    [
                        'tag' => 'div',
                        'text' => [
                            'tag' => 'plain_text',
                            'content' => $content,
                        ],
                    ],
                    [
                        'tag' => 'note',
                        'elements' => [
                            [
                                'tag' => 'plain_text',
                                'content' => __('主题: %{topic}', ['topic' => $topicCode]),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test(array $config): bool
    {
        $testNotification = [
            'topic_code' => 'system_info',
            'type' => 'info',
            'title' => __('飞书渠道测试'),
            'content' => __('这是一条测试消息，如果您收到此消息，说明飞书渠道配置正确。'),
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
                'placeholder' => 'https://open.feishu.cn/open-apis/bot/v2/hook/xxx',
            ],
            [
                'name' => 'secret',
                'label' => __('签名密钥（可选）'),
                'type' => 'password',
                'required' => false,
                'placeholder' => '',
            ],
        ];
    }
}
