<?php
declare(strict_types=1);

namespace Weline\Mail\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Mail\Service\MailAccountEnsureService;
use Weline\Mail\Service\MailAccountManagementService;
use Weline\Mail\Service\MailAccountOutboundRelayService;
use Weline\Mail\Service\MailComposerService;
use Weline\Mail\Service\MailDomainAdminService;
use Weline\Mail\Service\MailOutboundRouteRuleService;
use Weline\Mail\Service\MailSmtpAccountService;
use Weline\Mail\Service\StalwartTlsCertificateSyncService;

class MailQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly MailSmtpAccountService $smtpAccountService,
        private readonly MailComposerService $composerService,
        private readonly ?MailAccountEnsureService $ensureService = null,
        private readonly ?StalwartTlsCertificateSyncService $tlsSync = null,
        private readonly ?MailOutboundRouteRuleService $routeRules = null,
        private readonly ?MailAccountManagementService $accountManagement = null,
    ) {
    }

    private function routeRules(): MailOutboundRouteRuleService
    {
        return $this->routeRules ?? ObjectManager::getInstance(MailOutboundRouteRuleService::class);
    }

    private function accountManagement(): MailAccountManagementService
    {
        return $this->accountManagement ?? ObjectManager::getInstance(MailAccountManagementService::class);
    }

    private function tlsSync(): StalwartTlsCertificateSyncService
    {
        return $this->tlsSync ?? ObjectManager::getInstance(StalwartTlsCertificateSyncService::class);
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
            'ensureDefaultSmtpAccount' => $this->ensureDefaultSmtpAccount($params),
            'setDefaultSmtpAccount' => $this->setDefaultSmtpAccount($params),
            'ensureMailbox' => $this->ensureMailbox($params),
            'getSmtpTlsStatus' => $this->getSmtpTlsStatus($params),
            'syncSmtpTlsFromDomainPool' => $this->syncSmtpTlsFromDomainPool($params),
            'listOutboundRouteRules' => [
                'success' => true,
                'items' => $this->routeRules()->listRules(),
            ],
            'saveOutboundRouteRule' => $this->routeRules()->saveRule($params),
            'deleteOutboundRouteRule' => $this->routeRules()->deleteRules(
                (int)($params['rule_id'] ?? $params['id'] ?? 0)
            ),
            'batchDeleteOutboundRouteRules' => $this->routeRules()->deleteRules(
                is_array($params['rule_ids'] ?? null)
                    ? $params['rule_ids']
                    : (is_array($params['ids'] ?? null) ? $params['ids'] : [])
            ),
            'createMailboxAccount' => $this->createMailboxAccount($params),
            'createMailboxDomain' => $this->createMailboxDomain($params),
            'resetMailboxPassword' => $this->resetMailboxPassword($params),
            'setMailboxOutboundRelay' => $this->setMailboxOutboundRelay($params),
            'createSmtpServiceAccount' => $this->createSmtpServiceAccount($params),
            default => throw new \InvalidArgumentException(
                (string)__('Mail 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function createMailboxAccount(array $params): array
    {
        return $this->accountManagement()->createAccount(
            (int)($params['domain_id'] ?? 0),
            (string)($params['email'] ?? ''),
            (string)($params['local_part'] ?? ''),
            (string)($params['display_name'] ?? ''),
            max(0, (int)($params['customer_id'] ?? 0)),
            max(128, (int)($params['quota_mb'] ?? 1024)),
            (string)($params['password'] ?? '')
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function createMailboxDomain(array $params): array
    {
        $domain = strtolower(trim((string)($params['domain_name'] ?? $params['website_domain'] ?? '')));
        $hostname = strtolower(trim((string)($params['hostname'] ?? '')));
        $engine = strtolower(trim((string)($params['engine'] ?? 'stalwart')));
        $quota = max(128, (int)($params['default_quota_mb'] ?? 1024));

        /** @var MailDomainAdminService $service */
        $service = ObjectManager::getInstance(MailDomainAdminService::class);

        return $service->createDomain($domain, $hostname, $engine, $quota);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resetMailboxPassword(array $params): array
    {
        return $this->accountManagement()->provisionOrResetPassword(
            (int)($params['account_id'] ?? 0),
            (string)($params['password'] ?? '')
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function setMailboxOutboundRelay(array $params): array
    {
        /** @var MailAccountOutboundRelayService $service */
        $service = ObjectManager::getInstance(MailAccountOutboundRelayService::class);

        return $service->setRelay($params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function createSmtpServiceAccount(array $params): array
    {
        $serviceId = (int)($params['smtp_service_id'] ?? 0);
        $local = trim((string)($params['local_part'] ?? ''));
        $role = trim((string)($params['account_role'] ?? 'submit'));
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->createAccountOnService($serviceId, $local, $role);
        $msg = (string)($result['message'] ?? __('失败'));
        $once = (string)($result['password_once'] ?? '');
        if (!empty($result['success']) && $once !== '') {
            $msg .= ' ' . (string)__('密码（仅一次）：%{1}', [$once]);
        }

        return [
            'success' => !empty($result['success']),
            'message' => $msg,
            'password_once' => $once,
            'code' => !empty($result['success']) ? 200 : 400,
        ];
    }

    private function getSmtpTlsStatus(array $params): array
    {
        $hostname = trim((string)($params['hostname'] ?? $params['domain'] ?? ''));
        if ($hostname === '') {
            return [
                'success' => false,
                'status' => StalwartTlsCertificateSyncService::STATUS_ERROR,
                'message' => (string)__('主机名为空'),
            ];
        }

        return $this->tlsSync()->getStatus($hostname);
    }

    private function syncSmtpTlsFromDomainPool(array $params): array
    {
        $root = trim((string)($params['root_domain'] ?? ''));
        $originIp = trim((string)($params['origin_ip'] ?? ''));
        $hostnames = $params['hostnames'] ?? null;
        if (is_array($hostnames) && $hostnames !== []) {
            $items = $this->tlsSync()->ensureForHostnames(
                array_map('strval', $hostnames),
                $root,
                $originIp
            );

            return [
                'success' => $items !== [] && !in_array(false, array_column($items, 'success'), true),
                'items' => $items,
            ];
        }
        $hostname = trim((string)($params['hostname'] ?? $params['domain'] ?? ''));
        if ($hostname === '') {
            return [
                'success' => false,
                'status' => StalwartTlsCertificateSyncService::STATUS_ERROR,
                'message' => (string)__('主机名为空'),
            ];
        }

        return $this->tlsSync()->ensureForHostname($hostname, $root, $originIp);
    }

    private function resolveActiveMailDomain(array $params): array
    {
        return $this->ensureService()->resolveActiveDomain((int)($params['domain_id'] ?? 0));
    }

    private function ensureContactAccount(array $params): array
    {
        return $this->ensureService()->ensureContactAccount((int)($params['domain_id'] ?? 0));
    }

    private function ensureDefaultSmtpAccount(array $params): array
    {
        return $this->ensureService()->ensureDefaultSmtpAccount((int)($params['domain_id'] ?? 0));
    }

    private function setDefaultSmtpAccount(array $params): array
    {
        return $this->ensureService()->setDefaultSmtpAccount(
            (int)($params['domain_id'] ?? 0),
            (int)($params['account_id'] ?? 0)
        );
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
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Mail::mail_send_as',
                    ],
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
                    'name' => 'ensureDefaultSmtpAccount',
                    'description' => __('幂等确保默认 Smtp 提交账号（初值 smtp@）并写入域名 default_smtp_account_id'),
                    'params' => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => false, 'description' => __('显式域名ID')],
                    ],
                ],
                [
                    'name' => 'setDefaultSmtpAccount',
                    'description' => __('将域名默认 Smtp 提交账号改绑为指定本域 active 账号'),
                    'params' => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => true, 'description' => __('域名ID')],
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('邮局账号ID')],
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
                [
                    'name' => 'getSmtpTlsStatus',
                    'description' => __('查询 smtp/mail 主机相对域名池与本机 Stalwart 的 TLS 状态'),
                    'params' => [
                        ['name' => 'hostname', 'type' => 'string', 'required' => true, 'description' => __('FQDN')],
                    ],
                ],
                [
                    'name' => 'syncSmtpTlsFromDomainPool',
                    'description' => __('入池出证（如需）并将 PEM 导入本机 Stalwart'),
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'hostname', 'type' => 'string', 'required' => false, 'description' => __('单主机 FQDN')],
                        ['name' => 'hostnames', 'type' => 'array', 'required' => false, 'description' => __('多主机')],
                        ['name' => 'root_domain', 'type' => 'string', 'required' => false, 'description' => __('根域')],
                        ['name' => 'origin_ip', 'type' => 'string', 'required' => false, 'description' => __('源站 IP')],
                    ],
                ],
                [
                    'name' => 'listOutboundRouteRules',
                    'description' => __('列出收件域外投导流规则'),
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Mail::mail',
                    ],
                    'params' => [],
                ],
                [
                    'name' => 'saveOutboundRouteRule',
                    'description' => __('新增或更新收件域外投导流规则并同步 Stalwart'),
                    'frontend' => true,
                    'mode' => 'write',
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Mail::mail_accounts_manage',
                    ],
                    'params' => [
                        ['name' => 'rule_id', 'type' => 'int', 'required' => false, 'description' => __('规则ID（更新时）')],
                        ['name' => 'match_domain', 'type' => 'string', 'required' => true, 'description' => __('收件域')],
                        ['name' => 'priority', 'type' => 'int', 'required' => false, 'description' => __('优先级')],
                        ['name' => 'enabled', 'type' => 'int', 'required' => false, 'description' => __('是否启用')],
                        ['name' => 'relay_host', 'type' => 'string', 'required' => false, 'description' => __('中继主机')],
                        ['name' => 'relay_port', 'type' => 'int', 'required' => false, 'description' => __('中继端口')],
                        ['name' => 'relay_username', 'type' => 'string', 'required' => false, 'description' => __('中继用户')],
                        ['name' => 'relay_password', 'type' => 'string', 'required' => false, 'description' => __('中继密码')],
                        ['name' => 'relay_tls', 'type' => 'string', 'required' => false, 'description' => __('TLS')],
                        ['name' => 'outbound_smtp_pick', 'type' => 'string', 'required' => false, 'description' => __('Smtp 目录选型')],
                    ],
                ],
                [
                    'name' => 'deleteOutboundRouteRule',
                    'description' => __('删除一条收件域外投导流规则并同步'),
                    'frontend' => true,
                    'mode' => 'write',
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Mail::mail_accounts_manage',
                    ],
                    'params' => [
                        ['name' => 'rule_id', 'type' => 'int', 'required' => true, 'description' => __('规则ID')],
                    ],
                ],
                [
                    'name' => 'batchDeleteOutboundRouteRules',
                    'description' => __('批量删除收件域外投导流规则并同步'),
                    'frontend' => true,
                    'mode' => 'write',
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Mail::mail_accounts_manage',
                    ],
                    'params' => [
                        ['name' => 'rule_ids', 'type' => 'array', 'required' => true, 'description' => __('规则ID列表')],
                    ],
                ],
                [
                    'name' => 'createMailboxAccount',
                    'description' => __('开通企业邮箱账号（后台弹层）'),
                    'frontend' => true,
                    'mode' => 'write',
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Mail::mail_accounts_manage',
                    ],
                    'params' => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => true, 'description' => __('邮局域名 ID')],
                        ['name' => 'local_part', 'type' => 'string', 'required' => true, 'description' => __('邮箱账号前缀')],
                        ['name' => 'email', 'type' => 'string', 'required' => false, 'description' => __('完整邮箱（可空，由前缀拼接）')],
                        ['name' => 'display_name', 'type' => 'string', 'required' => false, 'description' => __('显示名称')],
                        ['name' => 'customer_id', 'type' => 'int', 'required' => false, 'description' => __('系统用户 ID')],
                        ['name' => 'quota_mb', 'type' => 'int', 'required' => false, 'description' => __('容量 MB')],
                        ['name' => 'password', 'type' => 'string', 'required' => false, 'description' => __('初始密码')],
                    ],
                ],
                [
                    'name' => 'createMailboxDomain',
                    'description' => __('开通邮局域名（后台弹层）'),
                    'frontend' => true,
                    'mode' => 'write',
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Mail::mail_accounts_manage',
                    ],
                    'params' => [
                        ['name' => 'website_domain', 'type' => 'string', 'required' => false, 'description' => __('网站域名候选')],
                        ['name' => 'domain_name', 'type' => 'string', 'required' => false, 'description' => __('邮箱域名')],
                        ['name' => 'hostname', 'type' => 'string', 'required' => false, 'description' => __('邮件主机')],
                        ['name' => 'engine', 'type' => 'string', 'required' => false, 'description' => __('引擎')],
                        ['name' => 'default_quota_mb', 'type' => 'int', 'required' => false, 'description' => __('默认容量 MB')],
                    ],
                ],
                [
                    'name' => 'resetMailboxPassword',
                    'description' => __('开通或重置企业邮箱密码（后台弹层）'),
                    'frontend' => true,
                    'mode' => 'write',
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        // 与 createMailboxAccount 同源：password 细 ACL 未挂 frontend worker 能力面时会 capability_denied
                        'source_id' => 'Weline_Mail::mail_accounts_manage',
                    ],
                    'params' => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('邮箱账号 ID')],
                        ['name' => 'password', 'type' => 'string', 'required' => true, 'description' => __('新密码')],
                    ],
                ],
                [
                    'name' => 'setMailboxOutboundRelay',
                    'description' => __('设置企业邮箱外投 smarthost（后台弹层）'),
                    'frontend' => true,
                    'mode' => 'write',
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Mail::mail_accounts_manage',
                    ],
                    'params' => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('邮箱账号 ID')],
                        ['name' => 'outbound_relay_enabled', 'type' => 'int', 'required' => false, 'description' => __('是否启用外投')],
                        ['name' => 'outbound_smtp_pick', 'type' => 'string', 'required' => false, 'description' => __('Smtp 目录选型')],
                        ['name' => 'outbound_relay_host', 'type' => 'string', 'required' => false, 'description' => __('主机')],
                        ['name' => 'outbound_relay_port', 'type' => 'int', 'required' => false, 'description' => __('端口')],
                        ['name' => 'outbound_relay_username', 'type' => 'string', 'required' => false, 'description' => __('用户名')],
                        ['name' => 'outbound_relay_password', 'type' => 'string', 'required' => false, 'description' => __('密码')],
                        ['name' => 'outbound_relay_tls', 'type' => 'string', 'required' => false, 'description' => __('TLS')],
                    ],
                ],
                [
                    'name' => 'createSmtpServiceAccount',
                    'description' => __('在 Smtp 服务上增加本机账户（后台弹层）'),
                    'frontend' => true,
                    'mode' => 'write',
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Mail::mail_accounts_manage',
                    ],
                    'params' => [
                        ['name' => 'smtp_service_id', 'type' => 'int', 'required' => true, 'description' => __('Smtp 服务 ID')],
                        ['name' => 'local_part', 'type' => 'string', 'required' => true, 'description' => __('账号前缀')],
                        ['name' => 'account_role', 'type' => 'string', 'required' => false, 'description' => __('角色')],
                    ],
                ],
            ],
        ];
    }
}
