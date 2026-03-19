<?php

declare(strict_types=1);

namespace Weline\Backend\Adapter\Notification;

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Enum\NotificationType;

class EmailAdapter implements ChannelAdapterInterface
{
    public function getChannelCode(): string
    {
        return 'email';
    }

    public function getChannelName(): string
    {
        return __('邮件');
    }

    public function send(array $notification, array $config): bool
    {
        $toEmail = trim((string) ($config['to_email'] ?? ''));
        if ($toEmail === '') {
            return false;
        }

        $message = $this->formatMessage($notification);
        $params = [
            'to' => $toEmail,
            'subject' => $message['subject'],
            'content' => $message['body'],
            'module' => 'Weline_Smtp',
        ];
        $senderCode = $config['sender_code'] ?? $config['code'] ?? null;
        if ($senderCode !== null && $senderCode !== '') {
            $params['sender_code'] = $senderCode;
        }

        try {
            $result = w_query('smtp', 'send', $params);
            $success = (bool) ($result['success'] ?? false);
            if (!$success) {
                w_log_warning('EmailAdapter::send failed: ' . ($result['message'] ?? 'unknown'), [], 'notification');
            }
            return $success;
        } catch (\Throwable $e) {
            w_log_error('EmailAdapter::send failed: ' . $e->getMessage(), [], 'notification');
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
        $color = $type->getHexColor();

        $subject = "[{$typeLabel}] {$title}";

        $body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: {$color}; color: white; padding: 15px 20px; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 18px; }
        .content { background: #f8f9fa; padding: 20px; border: 1px solid #e9ecef; border-top: none; }
        .footer { padding: 15px 20px; font-size: 12px; color: #6c757d; background: #f8f9fa; border: 1px solid #e9ecef; border-top: none; border-radius: 0 0 8px 8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>[{$typeLabel}] {$title}</h1>
        </div>
        <div class="content">
            <p>{$content}</p>
        </div>
        <div class="footer">
            主题: {$topicCode}
        </div>
    </div>
</body>
</html>
HTML;

        return [
            'subject' => $subject,
            'body' => $body,
        ];
    }

    public function test(array $config): bool
    {
        $testNotification = [
            'topic_code' => 'system_info',
            'type' => 'info',
            'title' => __('邮件渠道测试'),
            'content' => __('这是一条测试消息，如果您收到此邮件，说明邮件渠道配置正确。'),
        ];

        return $this->send($testNotification, $config);
    }

    public function getConfigFields(): array
    {
        $fields = [
            [
                'name' => 'to_email',
                'label' => __('收件邮箱'),
                'type' => 'text',
                'required' => true,
                'placeholder' => 'admin@example.com',
            ],
        ];
        if (function_exists('w_query')) {
            try {
                $senders = w_query('smtp', 'getSenders', []);
                if (!empty($senders)) {
                    $options = [];
                    foreach ($senders as $s) {
                        $code = $s['code'] ?? '';
                        if ($code !== '') {
                            $options[$code] = ($s['name'] ?? $code) . ' (' . $code . ')';
                        }
                    }
                    if (!empty($options)) {
                        $fields[] = [
                            'name' => 'sender_code',
                            'label' => __('发件人（可选）'),
                            'type' => 'select',
                            'required' => false,
                            'placeholder' => __('使用默认'),
                            'options' => $options,
                        ];
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        return $fields;
    }
}
