<?php
declare(strict_types=1);

namespace Weline\Mail\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Mail\Service\MailAccountEnsureService;
use Weline\Mail\Service\MailComposerService;
use Weline\Mail\Service\MailSmtpAccountService;

class MailQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly MailSmtpAccountService $smtpAccountService,
        private readonly MailComposerService $composerService,
        private readonly ?MailAccountEnsureService $ensureService = null,
    ) {
    }

    private function ensureService(): MailAccountEnsureService
    {
        return $this->ensureService ?? ObjectManager::getInstance(MailAccountEnsureService::class);
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
            'resolveLocalMailboxByEmail' => $this->resolveLocalMailboxByEmail($params),
            'listLocalMailboxes' => $this->listLocalMailboxes($params),
            'listThreadBySource' => $this->listThreadBySource($params),
            'sendComposerMessage' => $this->sendComposerMessage($params),
            'resolveActiveMailDomain' => $this->resolveActiveMailDomain($params),
            'ensureContactAccount' => $this->ensureContactAccount($params),
            'ensureMailbox' => $this->ensureMailbox($params),
            default => throw new \InvalidArgumentException(
                (string)__('Mail 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function resolveActiveMailDomain(array $params): array
    {
        return $this->ensureService()->resolveActiveDomain((int)($params['domain_id'] ?? 0));
    }

    private function ensureContactAccount(array $params): array
    {
        return $this->ensureService()->ensureContactAccount((int)($params['domain_id'] ?? 0));
    }

    private function ensureMailbox(array $params): array
    {
        return $this->ensureService()->ensureMailbox(
            (int)($params['domain_id'] ?? 0),
            (string)($params['local_part'] ?? $params['local'] ?? ''),
            trim((string)($params['display_name'] ?? '')),
            (int)($params['quota_mb'] ?? 0)
        );
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

    private function resolveLocalMailboxByEmail(array $params): array
    {
        $resolved = $this->smtpAccountService->resolveLocalMailboxByEmail(
            (string)($params['email'] ?? '')
        );

        return [
            'success' => true,
            'local' => !empty($resolved['local']),
            'mailbox' => $resolved['mailbox'] ?? null,
        ];
    }

    private function listLocalMailboxes(array $params): array
    {
        return [
            'success' => true,
            'items' => $this->smtpAccountService->listLocalMailboxes(
                (int)($params['limit'] ?? 100)
            ),
        ];
    }

    private function listThreadBySource(array $params): array
    {
        return [
            'success' => true,
            'items' => $this->composerService->listThreadBySource(
                (string)($params['source'] ?? ''),
                (int)($params['source_id'] ?? 0),
                (int)($params['limit'] ?? 50)
            ),
        ];
    }

    private function sendComposerMessage(array $params): array
    {
        return $this->composerService->send(
            (int)($params['account_id'] ?? 0),
            (string)($params['to'] ?? ''),
            (string)($params['subject'] ?? ''),
            (string)($params['body'] ?? $params['content'] ?? ''),
            (string)($params['source'] ?? ''),
            (int)($params['source_id'] ?? 0)
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
                [
                    'name' => 'resolveLocalMailboxByEmail',
                    'description' => __('判断邮箱是否为本机已启用企业邮箱账号'),
                    'params' => [
                        ['name' => 'email', 'type' => 'string', 'required' => true, 'description' => __('邮箱地址')],
                    ],
                ],
                [
                    'name' => 'listLocalMailboxes',
                    'description' => __('列出本机已启用企业邮箱账号（无凭据）'),
                    'params' => [
                        ['name' => 'limit', 'type' => 'int', 'required' => false, 'description' => __('返回数量')],
                    ],
                ],
                [
                    'name' => 'listThreadBySource',
                    'description' => __('按业务来源加载邮件沟通线程'),
                    'params' => [
                        ['name' => 'source', 'type' => 'string', 'required' => true, 'description' => __('业务来源')],
                        ['name' => 'source_id', 'type' => 'int', 'required' => true, 'description' => __('业务来源ID')],
                    ],
                ],
                [
                    'name' => 'sendComposerMessage',
                    'description' => __('写信浮层发送并写入业务线程'),
                    'params' => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('发件账号')],
                        ['name' => 'to', 'type' => 'string', 'required' => true, 'description' => __('收件人')],
                        ['name' => 'subject', 'type' => 'string', 'required' => true, 'description' => __('主题')],
                        ['name' => 'body', 'type' => 'string', 'required' => true, 'description' => __('正文')],
                        ['name' => 'source', 'type' => 'string', 'required' => false, 'description' => __('业务来源')],
                        ['name' => 'source_id', 'type' => 'int', 'required' => false, 'description' => __('业务来源ID')],
                    ],
                ],
                [
                    'name' => 'resolveActiveMailDomain',
                    'description' => __('解析 Smtp 一键挂钩用的 active 邮局域名（多真实域须传 domain_id）'),
                    'params' => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => false, 'description' => __('显式域名ID')],
                    ],
                ],
                [
                    'name' => 'ensureContactAccount',
                    'description' => __('幂等确保 contact@域名 邮局账号；新建真实账号时返回一次性 SMTP 密码'),
                    'params' => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => false, 'description' => __('显式域名ID')],
                    ],
                ],
                [
                    'name' => 'ensureMailbox',
                    'description' => __('幂等确保指定 local_part@域名 邮局账号（分流建箱）'),
                    'params' => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => false, 'description' => __('显式域名ID')],
                        ['name' => 'local_part', 'type' => 'string', 'required' => true, 'description' => __('邮箱本地部分')],
                        ['name' => 'display_name', 'type' => 'string', 'required' => false, 'description' => __('显示名')],
                        ['name' => 'quota_mb', 'type' => 'int', 'required' => false, 'description' => __('容量MB')],
                    ],
                ],
            ],
        ];
    }
}
