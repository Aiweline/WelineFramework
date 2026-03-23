<?php

declare(strict_types=1);

namespace Weline\Backend\Adapter\Notification;

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Enum\NotificationType;

class TelegramAdapter implements ChannelAdapterInterface
{
    public function getChannelCode(): string
    {
        return 'telegram';
    }

    public function getChannelName(): string
    {
        return __('Telegram');
    }

    public function send(array $notification, array $config): bool
    {
        $botToken = trim((string) ($config['bot_token'] ?? ''));
        $chatId = trim((string) ($config['chat_id'] ?? ''));
        $parseMode = trim((string) ($config['parse_mode'] ?? 'HTML'));
        $disablePreview = !empty($config['disable_web_page_preview']);

        if ($botToken === '' || $chatId === '') {
            return false;
        }

        $message = $this->formatMessage($notification);
        $payload = [
            'chat_id' => $chatId,
            'text' => $message['text'],
            'parse_mode' => $parseMode !== '' ? $parseMode : 'HTML',
            'disable_web_page_preview' => $disablePreview,
        ];

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.telegram.org/bot' . $botToken . '/sendMessage');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !is_string($response)) {
                return false;
            }

            $result = json_decode($response, true);
            return (bool) ($result['ok'] ?? false);
        } catch (\Throwable $e) {
            w_log_error('TelegramAdapter::send failed: ' . $e->getMessage(), [], 'notification');
            return false;
        }
    }

    public function formatMessage(array $notification): array
    {
        $type = NotificationType::fromString((string) ($notification['type'] ?? 'info'));
        $title = htmlspecialchars((string) ($notification['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $content = htmlspecialchars((string) ($notification['content'] ?? ''), ENT_QUOTES, 'UTF-8');
        $topicCode = htmlspecialchars((string) ($notification['topic_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $recipientName = htmlspecialchars((string) ($notification['recipient_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $contactName = htmlspecialchars((string) ($notification['contact']['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8');

        $lines = [
            '<b>[' . $type->getLabel() . ']</b> ' . $title,
            '',
            $content,
            '',
            '<b>主题</b>: ' . $topicCode,
        ];

        if ($recipientName !== '') {
            $lines[] = '<b>接收用户</b>: ' . $recipientName;
        }
        if ($contactName !== '') {
            $lines[] = '<b>联系人</b>: ' . $contactName;
        }

        return [
            'text' => implode("\n", $lines),
        ];
    }

    public function test(array $config): bool
    {
        $testNotification = [
            'topic_code' => 'system_info',
            'type' => 'info',
            'title' => __('Telegram 渠道测试'),
            'content' => __('这是一条测试消息，如果您收到了它，说明 Telegram 渠道配置是可用的。'),
        ];

        return $this->send($testNotification, $config);
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'bot_token',
                'label' => __('Bot Token（建议填在渠道配置）'),
                'type' => 'password',
                'required' => false,
                'placeholder' => '123456789:AA...',
            ],
            [
                'name' => 'chat_id',
                'label' => __('Chat ID（建议填在联系人配置）'),
                'type' => 'text',
                'required' => false,
                'placeholder' => '@channel_name or 123456789',
            ],
            [
                'name' => 'parse_mode',
                'label' => __('消息格式'),
                'type' => 'select',
                'required' => false,
                'placeholder' => __('默认 HTML'),
                'options' => [
                    'HTML' => 'HTML',
                    'MarkdownV2' => 'MarkdownV2',
                ],
            ],
            [
                'name' => 'disable_web_page_preview',
                'label' => __('关闭链接预览'),
                'type' => 'select',
                'required' => false,
                'placeholder' => __('默认否'),
                'options' => [
                    '0' => __('否'),
                    '1' => __('是'),
                ],
            ],
        ];
    }
}
