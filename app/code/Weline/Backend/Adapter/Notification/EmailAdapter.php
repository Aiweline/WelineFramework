<?php

declare(strict_types=1);

namespace Weline\Backend\Adapter\Notification;

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Enum\NotificationType;
use Weline\Backend\Service\TopicCollector;
use Weline\Framework\Manager\ObjectManager;

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

        $type = NotificationType::fromString($notification['type'] ?? 'info');
        $channel = $this->resolveMailChannel($notification, $config);
        $params = [
            'to' => $toEmail,
            'channel' => $channel,
            'vars' => [
                'title' => (string)($notification['title'] ?? ''),
                'content' => (string)($notification['content'] ?? ''),
                'type_label' => (string)$type->getLabel(),
                'topic_code' => (string)($notification['topic_code'] ?? ''),
            ],
        ];
        $senderCode = $config['sender_code'] ?? $config['code'] ?? null;
        if ($senderCode !== null && $senderCode !== '') {
            // 保留 channel 走模板；sender_code 仅作传输账户提示时仍由 channel 绑定优先
            $params['sender_code'] = $senderCode;
        }
        if (!empty($config['scope'])) {
            $params['scope'] = (string)$config['scope'];
        }
        if (!empty($config['website_code'])) {
            $params['website_code'] = (string)$config['website_code'];
        }
        if (!empty($config['locale'])) {
            $params['locale'] = (string)$config['locale'];
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

    /**
     * 约定：主题邮件渠道 = {module}::notify_{topic_code}；无主题时回退 notification_email。
     */
    private function resolveMailChannel(array $notification, array $config): string
    {
        $override = trim((string)($config['mail_channel'] ?? $config['channel'] ?? ''));
        if ($override !== '') {
            return $override;
        }

        $topicCode = trim((string)($notification['topic_code'] ?? ''));
        if ($topicCode === '') {
            return 'Weline_Backend::notification_email';
        }

        $module = 'Weline_Backend';
        try {
            /** @var TopicCollector $collector */
            $collector = ObjectManager::getInstance(TopicCollector::class);
            $topic = $collector->getTopicByCode($topicCode);
            $fromTopic = trim((string)($topic['module'] ?? ''));
            if ($fromTopic !== '') {
                $module = $fromTopic;
            }
        } catch (\Throwable $e) {
            // keep Backend fallback module
        }

        return $module . '::notify_' . $topicCode;
    }

    public function formatMessage(array $notification): array
    {
        $type = NotificationType::fromString($notification['type'] ?? 'info');
        $title = $notification['title'] ?? '';
        $content = $notification['content'] ?? '';
        $typeLabel = $type->getLabel();
        return [
            'subject' => "[{$typeLabel}] {$title}",
            'body' => (string)$content,
        ];
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
        $fields[] = [
            'name' => 'mail_channel',
            'label' => __('邮件渠道覆盖（可选）'),
            'type' => 'text',
            'required' => false,
        ];
        return $fields;
    }
}
