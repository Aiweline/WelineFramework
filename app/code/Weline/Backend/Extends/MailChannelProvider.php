<?php

declare(strict_types=1);

namespace Weline\Backend\Extends;

use Weline\Backend\Service\TopicCollector;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Api\MailChannelProviderInterface;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        $variables = [
            ['code' => 'title', 'label' => __('标题'), 'sample' => '系统通知'],
            ['code' => 'content', 'label' => __('正文'), 'sample' => '通知内容'],
            ['code' => 'type_label', 'label' => __('类型标签'), 'sample' => '信息'],
            ['code' => 'topic_code', 'label' => __('主题代码'), 'sample' => 'system_info'],
        ];
        $templates = [
            [
                'locale' => 'zh_Hans_CN',
                'subject_file' => 'view/email/notification/zh_Hans_CN.subject.txt',
                'body_file' => 'view/email/notification/zh_Hans_CN.html',
            ],
            [
                'locale' => 'en_US',
                'subject_file' => 'view/email/notification/en_US.subject.txt',
                'body_file' => 'view/email/notification/en_US.html',
            ],
        ];

        $byCode = [
            'Weline_Backend::notification_email' => [
                'code' => 'Weline_Backend::notification_email',
                'name' => __('后台通知邮件（默认）'),
                'description' => __('通知中心邮件渠道默认回退；无主题专用绑定时使用'),
                'module' => 'Weline_Backend',
                'variables' => $variables,
                'default_templates' => $templates,
            ],
            'Weline_Backend::notify_system_alert' => [
                'code' => 'Weline_Backend::notify_system_alert',
                'name' => __('系统告警邮件'),
                'description' => __('通知主题 system_alert 的邮件渠道'),
                'module' => 'Weline_Backend',
                'variables' => $variables,
                'default_templates' => $templates,
            ],
            'Weline_Backend::notify_security_alert' => [
                'code' => 'Weline_Backend::notify_security_alert',
                'name' => __('安全告警邮件'),
                'description' => __('通知主题 security_alert 的邮件渠道'),
                'module' => 'Weline_Backend',
                'variables' => $variables,
                'default_templates' => $templates,
            ],
        ];

        foreach ($this->collectTopicRows() as $topic) {
            $topicCode = trim((string)($topic['topic_code'] ?? $topic['code'] ?? ''));
            if ($topicCode === '') {
                continue;
            }
            $module = trim((string)($topic['module'] ?? 'Weline_Backend'));
            if ($module === '') {
                $module = 'Weline_Backend';
            }
            // 非 Backend 主题由各模块自有 MailChannelProvider 注册；此处只展开 Backend Topic（R2 回退仍覆盖）
            if ($module !== 'Weline_Backend') {
                continue;
            }
            $code = $module . '::notify_' . $topicCode;
            if (isset($byCode[$code])) {
                continue;
            }
            $byCode[$code] = [
                'code' => $code,
                'name' => (string)($topic['topic_name'] ?? $topic['name'] ?? $topicCode),
                'description' => (string)__('通知主题 %{1} 的邮件渠道', [$topicCode]),
                'module' => 'Weline_Backend',
                'variables' => $variables,
                'default_templates' => $templates,
            ];
        }

        return array_values($byCode);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectTopicRows(): array
    {
        try {
            /** @var TopicCollector $collector */
            $collector = ObjectManager::getInstance(TopicCollector::class);
            $rows = $collector->getEnabledTopics();
            if (is_array($rows) && $rows !== []) {
                return $rows;
            }
        } catch (\Throwable) {
            // fall through to static Backend topics
        }

        try {
            $provider = ObjectManager::getInstance(NotificationTopicProvider::class);
            $out = [];
            foreach ($provider->getTopics() as $topic) {
                if (!is_array($topic)) {
                    continue;
                }
                $out[] = [
                    'topic_code' => (string)($topic['code'] ?? ''),
                    'topic_name' => (string)($topic['name'] ?? ''),
                    'module' => 'Weline_Backend',
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
