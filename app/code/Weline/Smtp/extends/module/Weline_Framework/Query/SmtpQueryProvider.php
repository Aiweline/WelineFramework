<?php

declare(strict_types=1);

namespace Weline\Smtp\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Smtp\Helper\Data;
use Weline\Smtp\Helper\SmtpSender;

/**
 * SMTP 统一查询器
 *
 * 提供 send/test/getConfig 能力，供其他模块通过 w_query('smtp', ...) 调用。
 * 支持多模块 SMTP 配置：module 参数指定使用哪一模块的配置。
 */
class SmtpQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'smtp';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'send' => $this->send($params),
            'test' => $this->test($params),
            'getConfig' => $this->getConfig($params),
            'isAvailable' => $this->isAvailable($params),
            'getSenders' => $this->getSenders($params),
            'getSenderByCode' => $this->getSenderByCode($params),
            'setContact' => $this->setContact($params),
            'getContact' => $this->getContact($params),
            default => throw new \InvalidArgumentException(
                (string)__('Smtp 查询器不支持的操作：%{1}', [$operation])
            ),
        };
    }

    /**
     * 检测 SMTP 是否已配置可用（至少有一个发件人配置了 host + username）
     */
    private function isAvailable(array $params): array
    {
        $module = (string) ($params['module'] ?? 'Weline_Smtp');
        $senders = $this->getSendersInternal($module);
        foreach ($senders as $s) {
            $host = trim((string) ($s['smtp_host'] ?? ''));
            $user = trim((string) ($s['smtp_username'] ?? ''));
            if ($host !== '' && $user !== '') {
                return ['available' => true, 'message' => __('SMTP 已配置')];
            }
        }
        return ['available' => false, 'message' => __('请先在 SMTP 配置中添加至少一个发件人')];
    }

    private function getSenders(array $params): array
    {
        $module = (string) ($params['module'] ?? 'Weline_Smtp');
        return $this->getSendersInternal($module);
    }

    private function getSendersInternal(string $module): array
    {
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        return $data->getSenders($module);
    }

    private function getSenderByCode(array $params): ?array
    {
        $code = (string) ($params['sender_code'] ?? $params['code'] ?? '');
        $module = (string) ($params['module'] ?? 'Weline_Smtp');
        if ($code === '') {
            return null;
        }
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        return $data->getSenderByCode($code, $module);
    }

    /**
     * 设置某发件人 code 的默认联系人（收件邮箱），供消息通知等调用
     */
    private function setContact(array $params): array
    {
        $code = (string) ($params['sender_code'] ?? $params['code'] ?? '');
        $toEmail = trim((string) ($params['to_email'] ?? $params['email'] ?? ''));
        $module = (string) ($params['module'] ?? 'Weline_Smtp');
        if ($code === '') {
            return ['success' => false, 'message' => __('发件人 code 不能为空')];
        }
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        $data->setSenderContact($code, $toEmail, $module);
        return ['success' => true, 'message' => __('已保存')];
    }

    /**
     * 获取某发件人 code 的默认联系人（收件邮箱）
     */
    private function getContact(array $params): array
    {
        $code = (string) ($params['sender_code'] ?? $params['code'] ?? '');
        $module = (string) ($params['module'] ?? 'Weline_Smtp');
        if ($code === '') {
            return ['success' => false, 'to_email' => ''];
        }
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        $toEmail = $data->getSenderContact($code, $module);
        return ['success' => true, 'to_email' => $toEmail];
    }

    private function send(array $params): array
    {
        $to = $params['to'] ?? null;
        $subject = trim((string)($params['subject'] ?? ''));
        $content = (string)($params['content'] ?? '');
        $from = $params['from'] ?? null;
        $module = (string)($params['module'] ?? 'Weline_Smtp');
        $senderCode = $params['sender_code'] ?? $params['code'] ?? null;
        $alt = (string)($params['alt'] ?? '');
        $attachment = $params['attachment'] ?? '';
        $cc = $params['cc'] ?? '';
        $bcc = $params['bcc'] ?? '';

        if (empty($to)) {
            return ['success' => false, 'message' => __('收件人不能为空')];
        }
        if ($subject === '') {
            return ['success' => false, 'message' => __('邮件主题不能为空')];
        }

        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        /** @var SmtpSender $sender */
        $sender = ObjectManager::getInstance(SmtpSender::class);

        if ($senderCode !== null && $senderCode !== '') {
            $senderConfig = $data->getSenderByCode((string) $senderCode, $module);
            if (!$senderConfig || empty($senderConfig['smtp_host']) || empty($senderConfig['smtp_username'])) {
                return ['success' => false, 'message' => __('发件人 %{1} 未配置或配置不完整', [$senderCode])];
            }
            $username = trim((string)($senderConfig['smtp_username'] ?? ''));
            $fromResolved = $from;
            if (empty($fromResolved)) {
                $fromResolved = ['email' => $username, 'name' => (string)($senderConfig['name'] ?? __('系统'))];
            } elseif (is_string($fromResolved)) {
                $fromResolved = ['email' => $fromResolved, 'name' => __('系统')];
            }
            try {
                $ok = $sender->sendWithConfig(
                    $fromResolved,
                    $to,
                    $subject,
                    $content,
                    $alt,
                    $attachment,
                    '',
                    $cc,
                    $bcc,
                    $senderConfig,
                    $module
                );
                return ['success' => $ok, 'message' => $ok ? __('发送成功') : __('发送失败')];
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => __('发送失败：%{1}', [$e->getMessage()])];
            }
        }

        $username = $data->get(Data::smtp_username, $module);
        if (empty($username)) {
            return ['success' => false, 'message' => __('模块 %{1} 未配置 SMTP，请先在后台配置', [$module])];
        }
        $fromResolved = $from;
        if (empty($fromResolved)) {
            $fromResolved = ['email' => $username, 'name' => __('系统')];
        } elseif (is_string($fromResolved)) {
            $fromResolved = ['email' => $fromResolved, 'name' => __('系统')];
        }
        try {
            $ok = $sender->sender(
                $fromResolved,
                $to,
                $subject,
                $content,
                $alt,
                $attachment,
                '',
                $cc,
                $bcc,
                $module
            );
            return [
                'success' => $ok,
                'message' => $ok ? __('发送成功') : __('发送失败'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('发送失败：%{1}', [$e->getMessage()]),
            ];
        }
    }

    private function test(array $params): array
    {
        $to = $params['to'] ?? null;
        $module = (string)($params['module'] ?? 'Weline_Smtp');

        if (empty($to)) {
            return ['success' => false, 'message' => __('测试邮箱不能为空')];
        }

        return $this->send([
            'from' => null,
            'to' => $to,
            'subject' => __('[SMTP 测试] WelineFramework 邮件测试'),
            'content' => __('这是一封测试邮件。如果您收到此邮件，说明 SMTP 配置正确。'),
            'module' => $module,
        ]);
    }

    private function getConfig(array $params): array
    {
        $module = (string)($params['module'] ?? 'Weline_Smtp');
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        $all = $data->get('', $module);
        return is_array($all) ? $all : [];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'smtp',
            'name' => __('SMTP 邮件'),
            'description' => __('提供 SMTP 邮件发送能力，支持多模块独立配置'),
            'module' => 'Weline_Smtp',
            'operations' => [
                [
                    'name' => 'send',
                    'description' => __('发送邮件'),
                    'params' => [
                        ['name' => 'to', 'type' => 'string|array', 'required' => true, 'description' => __('收件人')],
                        ['name' => 'subject', 'type' => 'string', 'required' => true, 'description' => __('主题')],
                        ['name' => 'content', 'type' => 'string', 'required' => true, 'description' => __('HTML 正文')],
                        ['name' => 'from', 'type' => 'string|array|null', 'required' => false, 'description' => __('发件人，空则用配置')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('使用哪一模块的 SMTP 配置')],
                        ['name' => 'alt', 'type' => 'string', 'required' => false],
                        ['name' => 'attachment', 'type' => 'string|array', 'required' => false],
                        ['name' => 'cc', 'type' => 'string|array', 'required' => false],
                        ['name' => 'bcc', 'type' => 'string|array', 'required' => false],
                    ],
                ],
                [
                    'name' => 'test',
                    'description' => __('发送测试邮件'),
                    'params' => [
                        ['name' => 'to', 'type' => 'string', 'required' => true, 'description' => __('测试邮箱')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')],
                    ],
                ],
                [
                    'name' => 'getConfig',
                    'description' => __('获取模块 SMTP 配置'),
                    'params' => [
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')],
                    ],
                ],
                [
                    'name' => 'isAvailable',
                    'description' => __('检测 SMTP 是否已配置可用'),
                    'params' => [['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')]],
                ],
                [
                    'name' => 'getSenders',
                    'description' => __('获取所有发件人列表（含 code）'),
                    'params' => [['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')]],
                ],
                [
                    'name' => 'getSenderByCode',
                    'description' => __('按 code 获取发件人配置'),
                    'params' => [
                        ['name' => 'sender_code', 'type' => 'string', 'required' => true, 'description' => __('发件人 code')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')],
                    ],
                ],
                [
                    'name' => 'setContact',
                    'description' => __('设置发件人默认联系人/收件邮箱，供消息通知等调用'),
                    'params' => [
                        ['name' => 'sender_code', 'type' => 'string', 'required' => true, 'description' => __('发件人 code')],
                        ['name' => 'to_email', 'type' => 'string', 'required' => true, 'description' => __('默认收件邮箱')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')],
                    ],
                ],
                [
                    'name' => 'getContact',
                    'description' => __('获取发件人默认联系人/收件邮箱'),
                    'params' => [
                        ['name' => 'sender_code', 'type' => 'string', 'required' => true, 'description' => __('发件人 code')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')],
                    ],
                ],
            ],
        ];
    }
}
