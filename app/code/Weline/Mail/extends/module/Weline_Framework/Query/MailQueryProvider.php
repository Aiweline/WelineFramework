<?php
declare(strict_types=1);

namespace Weline\Mail\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Mail\Service\MailSmtpAccountService;

class MailQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly MailSmtpAccountService $smtpAccountService
    ) {
    }

    public function getProviderName(): string
    {
        return 'mail';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getSmtpAccounts' => $this->getSmtpAccounts($params),
            'getSmtpAccountConfig' => $this->getSmtpAccountConfig($params),
            'sendViaSmtpAccount' => $this->sendViaSmtpAccount($params),
            default => throw new \InvalidArgumentException(
                (string)__('Mail 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function getSmtpAccounts(array $params): array
    {
        return [
            'success' => true,
            'items' => $this->smtpAccountService->searchAccounts(
                (string)($params['q'] ?? $params['query'] ?? ''),
                (int)($params['limit'] ?? 50)
            ),
        ];
    }

    private function getSmtpAccountConfig(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? $params['mail_account_id'] ?? 0);
        $config = $this->smtpAccountService->getAccountConfig($accountId);
        if ($config === null) {
            return [
                'success' => false,
                'message' => __('自建邮箱账号不存在或未启用'),
                'config' => null,
            ];
        }

        return [
            'success' => true,
            'config' => $config,
        ];
    }

    private function sendViaSmtpAccount(array $params): array
    {
        return $this->smtpAccountService->sendViaAccount(
            (int)($params['account_id'] ?? $params['mail_account_id'] ?? 0),
            $params['to'] ?? '',
            trim((string)($params['subject'] ?? '')),
            (string)($params['content'] ?? $params['body'] ?? '')
        );
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'mail',
            'name' => __('企业邮箱查询器'),
            'description' => __('提供自建企业邮箱账号的 SMTP 配置查询和 fake 发信能力。'),
            'module' => 'Weline_Mail',
            'operations' => [
                [
                    'name' => 'getSmtpAccounts',
                    'description' => __('查询可用于 SMTP 配置的自建邮箱账号'),
                    'params' => [
                        ['name' => 'q', 'type' => 'string', 'required' => false, 'description' => __('搜索关键词')],
                        ['name' => 'limit', 'type' => 'int', 'required' => false, 'description' => __('返回数量')],
                    ],
                ],
                [
                    'name' => 'getSmtpAccountConfig',
                    'description' => __('获取自建邮箱账号的 SMTP 自动配置'),
                    'params' => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('邮箱账号ID')],
                    ],
                ],
                [
                    'name' => 'sendViaSmtpAccount',
                    'description' => __('使用自建 fake 邮箱账号写入发件箱并投递本地收件箱'),
                    'params' => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('邮箱账号ID')],
                        ['name' => 'to', 'type' => 'string|array', 'required' => true, 'description' => __('收件人')],
                        ['name' => 'subject', 'type' => 'string', 'required' => true, 'description' => __('主题')],
                        ['name' => 'content', 'type' => 'string', 'required' => true, 'description' => __('正文')],
                    ],
                ],
            ],
        ];
    }
}
