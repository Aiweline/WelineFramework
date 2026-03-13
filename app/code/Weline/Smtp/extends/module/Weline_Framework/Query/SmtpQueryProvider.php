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
            default => throw new \InvalidArgumentException(
                (string)__('Smtp 查询器不支持的操作：%{1}', [$operation])
            ),
        };
    }

    private function send(array $params): array
    {
        $to = $params['to'] ?? null;
        $subject = trim((string)($params['subject'] ?? ''));
        $content = (string)($params['content'] ?? '');
        $from = $params['from'] ?? null;
        $module = (string)($params['module'] ?? 'Weline_Smtp');
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
            /** @var SmtpSender $sender */
            $sender = ObjectManager::getInstance(SmtpSender::class);
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
            ],
        ];
    }
}
