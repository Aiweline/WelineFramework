<?php
declare(strict_types=1);

namespace Weline\Websites\Extends\Module\Weline_Framework\Query;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Websites\Api\NameserverSwitchInterface;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\DomainPurchaseService;
use Weline\Websites\Service\DomainRegistrarResolverService;
use Weline\Websites\Service\ServerIpService;
use Weline\Websites\Service\DomainSyncService;
use Weline\Websites\Service\DnsProviderDetector;

class WebsitesQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly DomainRegistrarResolverService $resolver,
        private readonly DomainSyncService $domainSyncService,
        private readonly DomainRegistrarAccount $accountModel,
        private readonly DomainRegistrar $registrarModel,
        private readonly Website $websiteModel,
        private readonly WebsiteLanguage $websiteLanguageModel,
        private readonly DnsProviderDetector $dnsProviderDetector
    ) {
    }

    public function getProviderName(): string
    {
        return 'websites';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getRegistrars'          => $this->getRegistrars(),
            'getRegistrarAccounts'   => $this->getRegistrarAccounts($params),
            'saveRegistrarAccount'   => $this->saveRegistrarAccount($params),
            'deleteRegistrarAccount' => $this->deleteRegistrarAccount($params),
            'getDomainList'          => $this->getDomainList($params),
            'checkAvailability'      => $this->checkAvailability($params),
            'purchaseDomain'         => $this->purchaseDomain($params),
            'testConnection'         => $this->testConnection($params),
            'getConfigFields'        => $this->getConfigFields($params),
            'getRegistrarInfo'       => $this->getRegistrarInfo($params),
            'modifyDns'              => $this->modifyDns($params),
            'getActiveAccounts'      => $this->domainSyncService->getActiveAccounts(),
            'getDomainStatusOptions' => Domain::getStatusOptions(),
            'getLastSyncTime'        => $this->getLastSyncTime($params),
            'getLocalDomains'        => $this->getLocalDomains($params),
            'getRemoteDomains'       => $this->getRemoteDomains($params),
            'importDomains'          => $this->importDomains($params),
            'syncDomains'            => $this->syncDomains($params),
            'batchOperateDomains'    => $this->batchOperateDomains($params),
            'getWebsiteById'         => $this->getWebsiteById($params),
            'getWebsiteList'         => $this->getWebsiteList($params),
            'getWebsiteLanguageCodes' => $this->getWebsiteLanguageCodes($params),
            'getDomainPoolList'      => $this->getDomainPoolList($params),
            'getDnsRecords'          => $this->getDnsRecords($params),
            'addAcmeTxtRecord'       => $this->addAcmeTxtRecord($params),
            'removeAcmeTxtRecord'    => $this->removeAcmeTxtRecord($params),
            'getDnsCdnAccounts'      => $this->getDnsCdnAccounts($params),
            default => throw new \InvalidArgumentException(
                (string)__('Websites 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider'    => 'websites',
            'name'        => __('域名与网站查询'),
            'description' => __('提供域名注册商、账号管理、域名列表、可用性检查、购买等能力'),
            'module'      => 'Weline_Websites',
            'operations'  => [
                [
                    'name'        => 'getRegistrars',
                    'description' => __('获取所有可用的域名注册商适配器'),
                    'params'      => [],
                ],
                [
                    'name'        => 'getRegistrarAccounts',
                    'description' => __('获取已配置的注册商账号列表'),
                    'params'      => [
                        ['name' => 'status', 'type' => 'string|null', 'required' => false, 'description' => __('按状态过滤')],
                    ],
                ],
                [
                    'name'        => 'saveRegistrarAccount',
                    'description' => __('创建或更新注册商账号'),
                    'params'      => [
                        ['name' => 'account_id',     'type' => 'int|null',    'required' => false, 'description' => __('账号 ID（更新时必填）')],
                        ['name' => 'registrar_code', 'type' => 'string',      'required' => true,  'description' => __('注册商代码')],
                        ['name' => 'account_name',   'type' => 'string',      'required' => true,  'description' => __('账号名称')],
                        ['name' => 'api_key',        'type' => 'string|null', 'required' => false, 'description' => __('API Key')],
                        ['name' => 'api_secret',     'type' => 'string|null', 'required' => false, 'description' => __('API Secret')],
                        ['name' => 'region',         'type' => 'string|null', 'required' => false, 'description' => __('区域')],
                        ['name' => 'extra_config',   'type' => 'array|null',  'required' => false, 'description' => __('额外配置')],
                        ['name' => 'status',         'type' => 'string|null', 'required' => false, 'description' => __('状态')],
                    ],
                ],
                [
                    'name'        => 'deleteRegistrarAccount',
                    'description' => __('删除注册商账号'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账号 ID')],
                    ],
                ],
                [
                    'name'        => 'getDomainList',
                    'description' => __('通过注册商 API 获取域名列表'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账号 ID')],
                    ],
                ],
                [
                    'name'        => 'checkAvailability',
                    'description' => __('检查域名可用性'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int',   'required' => true, 'description' => __('账号 ID')],
                        ['name' => 'domains',    'type' => 'array', 'required' => true, 'description' => __('域名数组')],
                    ],
                ],
                [
                    'name'        => 'purchaseDomain',
                    'description' => __('购买域名'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int',   'required' => true, 'description' => __('账号 ID')],
                        ['name' => 'items',      'type' => 'array', 'required' => true, 'description' => __('购买项数组')],
                        ['name' => 'auto_resolve', 'type' => 'bool', 'required' => false, 'description' => __('是否自动解析到本地，默认 true')],
                        ['name' => 'resolve_to_local', 'type' => 'string', 'required' => false, 'description' => __('默认 yes/no，对 items 中未指定者生效')],
                        ['name' => 'subdomains', 'type' => 'array|string', 'required' => false, 'description' => __('默认子域名列表，如 ["@","www"] 或 "@,www"')],
                        ['name' => 'dns_choice', 'type' => 'string', 'required' => false, 'description' => __('DNS 策略：follow_registrar/custom_nameservers')],
                        ['name' => 'dns_nameservers', 'type' => 'string', 'required' => false, 'description' => __('自定义 Nameserver，逗号分隔')],
                        ['name' => 'cdn_choice', 'type' => 'string', 'required' => false, 'description' => __('CDN 策略：follow_registrar/none')],
                        ['name' => 'start_lifecycle', 'type' => 'string|bool', 'required' => false, 'description' => __('是否启动后续全流程状态跟踪')],
                    ],
                ],
                [
                    'name'        => 'testConnection',
                    'description' => __('测试注册商账号连接'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账号 ID')],
                    ],
                ],
                [
                    'name'        => 'getConfigFields',
                    'description' => __('获取注册商的配置字段定义'),
                    'params'      => [
                        ['name' => 'registrar_code', 'type' => 'string', 'required' => true, 'description' => __('注册商代码')],
                    ],
                ],
                [
                    'name'        => 'getRegistrarInfo',
                    'description' => __('获取注册商完整信息（含配置字段、帮助说明、默认值）'),
                    'params'      => [
                        ['name' => 'registrar_code', 'type' => 'string', 'required' => true, 'description' => __('注册商代码')],
                    ],
                ],
                [
                    'name'        => 'modifyDns',
                    'description' => __('修改域名 DNS/NS 记录'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账号 ID')],
                        ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => __('域名')],
                        ['name' => 'nameservers', 'type' => 'string', 'required' => true, 'description' => __('NS 列表，逗号分隔')],
                    ],
                ],
                [
                    'name'        => 'getActiveAccounts',
                    'description' => __('获取已启用账号列表（同步业务）'),
                    'params'      => [],
                ],
                [
                    'name'        => 'getDomainStatusOptions',
                    'description' => __('获取域名状态选项'),
                    'params'      => [],
                ],
                [
                    'name'        => 'getLastSyncTime',
                    'description' => __('获取最后同步时间'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name'        => 'getLocalDomains',
                    'description' => __('获取本地域名列表（分页）'),
                    'params'      => [
                        ['name' => 'filters', 'type' => 'array', 'required' => false],
                        ['name' => 'page', 'type' => 'int', 'required' => false],
                        ['name' => 'limit', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name'        => 'getRemoteDomains',
                    'description' => __('获取远程域名列表（不落库）'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name'        => 'importDomains',
                    'description' => __('导入远程域名到本地'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true],
                        ['name' => 'domains', 'type' => 'array', 'required' => true],
                        ['name' => 'resolve_mode', 'type' => 'string|bool', 'required' => false],
                    ],
                ],
                [
                    'name'        => 'syncDomains',
                    'description' => __('同步账号域名'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name'        => 'batchOperateDomains',
                    'description' => __('域名批量操作'),
                    'params'      => [
                        ['name' => 'domain_ids', 'type' => 'array', 'required' => true],
                        ['name' => 'operation', 'type' => 'string', 'required' => true],
                        ['name' => 'params', 'type' => 'array', 'required' => false],
                    ],
                ],
                [
                    'name'        => 'getWebsiteById',
                    'description' => __('根据 ID 获取站点信息'),
                    'params'      => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name'        => 'getWebsiteList',
                    'description' => __('获取所有站点列表'),
                    'params'      => [],
                ],
                [
                    'name'        => 'getWebsiteLanguageCodes',
                    'description' => __('获取站点关联的语言代码列表'),
                    'params'      => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name'        => 'getDomainPoolList',
                    'description' => __('获取域名池列表（用于 SSL/建站选择）'),
                    'params'      => [
                        ['name' => 'status', 'type' => 'int|string|null', 'required' => false],
                        ['name' => 'limit', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name'        => 'getDnsRecords',
                    'description' => __('获取域名 DNS 记录（查询层自动同步域名池）'),
                    'params'      => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name'        => 'addAcmeTxtRecord',
                    'description' => __('添加 ACME DNS-01 验证 TXT 记录'),
                    'params'      => [
                        ['name' => 'domain', 'type' => 'string', 'required' => true],
                        ['name' => 'challenge_value', 'type' => 'string', 'required' => true],
                        ['name' => 'pool_id', 'type' => 'int', 'required' => false],
                        ['name' => 'domain_id', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name'        => 'removeAcmeTxtRecord',
                    'description' => __('删除 ACME DNS-01 验证 TXT 记录'),
                    'params'      => [
                        ['name' => 'domain', 'type' => 'string', 'required' => true],
                        ['name' => 'record_id', 'type' => 'string', 'required' => true],
                        ['name' => 'pool_id', 'type' => 'int', 'required' => false],
                        ['name' => 'domain_id', 'type' => 'int', 'required' => false],
                    ],
                ],
            ],
        ];
    }

    private function getRegistrars(): array
    {
        $registrars = [];
        foreach ($this->resolver->getAllAdapters() as $adapter) {
            $registrars[] = [
                'code'        => $adapter->getRegistrarCode(),
                'name'        => $adapter->getRegistrarName(),
                'description' => $adapter->getDescription(),
                'version'     => $adapter->getVersion(),
            ];
        }
        return $registrars;
    }

    private function getRegistrarAccounts(array $params): array
    {
        $model = clone $this->accountModel;
        $model->clearQuery();
        $status = $params['status'] ?? null;
        if ($status !== null && $status !== '') {
            $model->where(DomainRegistrarAccount::schema_fields_STATUS, (string)$status);
        }
        $records = $model->select()->fetchArray();
        $accounts = [];
        foreach ($records as $record) {
            $registrarId   = (int)($record[DomainRegistrarAccount::schema_fields_REGISTRAR_ID] ?? 0);
            $registrarCode = '';
            $registrarName = '';
            if ($registrarId > 0) {
                $reg = clone $this->registrarModel;
                $reg->clearQuery();
                $reg->where(DomainRegistrar::schema_fields_ID, $registrarId)->find()->fetch();
                $registrarCode = (string)($reg->getData(DomainRegistrar::schema_fields_CODE) ?? '');
                $registrarName = (string)($reg->getData(DomainRegistrar::schema_fields_NAME) ?? '');
            }
            $extraConfigRaw = $record[DomainRegistrarAccount::schema_fields_EXTRA_CONFIG] ?? '';
            $extraConfig = [];
            if (\is_string($extraConfigRaw) && $extraConfigRaw !== '') {
                $extraConfig = \json_decode($extraConfigRaw, true) ?: [];
            } elseif (\is_array($extraConfigRaw)) {
                $extraConfig = $extraConfigRaw;
            }
            
            $accounts[] = [
                'account_id'     => (int)($record[DomainRegistrarAccount::schema_fields_ID] ?? 0),
                'account_name'   => (string)($record[DomainRegistrarAccount::schema_fields_ACCOUNT_NAME] ?? ''),
                'registrar_id'   => $registrarId,
                'registrar_code' => $registrarCode,
                'registrar_name' => $registrarName,
                'region'         => (string)($record[DomainRegistrarAccount::schema_fields_REGION] ?? ''),
                'extra_config'   => $extraConfig,
                'status'         => (string)($record[DomainRegistrarAccount::schema_fields_STATUS] ?? ''),
                'created_at'     => (string)($record[DomainRegistrarAccount::schema_fields_CREATED_AT] ?? ''),
            ];
        }
        return $accounts;
    }

    /**
     * 获取可用于 DNS/CDN 管理的账户列表（与域名购买/管理中的逻辑一致）
     * 返回结构同 DomainManagement::getDnsAccounts，供 QuickBuild 向导等复用。
     */
    private function getDnsCdnAccounts(array $params): array
    {
        $p = \array_merge(['status' => DomainRegistrarAccount::STATUS_ACTIVE], $params);
        $all = $this->getRegistrarAccounts($p);
        $dnsAccounts = [];
        $cdnAccounts = [];
        foreach ($all as $record) {
            $registrarCode = (string) ($record['registrar_code'] ?? '');
            $accountInfo = [
                'account_id'     => (int) ($record['account_id'] ?? 0),
                'name'           => (string) ($record['account_name'] ?? ''),
                'registrar_code' => $registrarCode,
                'registrar_name' => (string) ($record['registrar_name'] ?? $registrarCode),
            ];
            $adapter = $this->resolver->getAdapter($registrarCode);
            if ($adapter !== null && $adapter->supportsDnsManagement()) {
                $dnsAccounts[] = $accountInfo;
            }
            if ($this->dnsProviderDetector->isCdnProvider($registrarCode)) {
                $cdnAccounts[] = $accountInfo;
            }
        }
        return [
            'dns_accounts' => $dnsAccounts,
            'cdn_accounts' => $cdnAccounts,
        ];
    }

    private function saveRegistrarAccount(array $params): array
    {
        $accountId     = (int)($params['account_id'] ?? 0);
        $registrarCode = (string)($params['registrar_code'] ?? '');
        $accountName   = (string)($params['account_name'] ?? '');

        if ($registrarCode === '' || $accountName === '') {
            return ['success' => false, 'message' => (string)__('注册商代码和账号名称不能为空')];
        }

        $adapter = $this->resolver->getAdapter($registrarCode);
        if ($adapter === null) {
            return ['success' => false, 'message' => (string)__('未找到注册商适配器：%{1}', $registrarCode)];
        }

        // 根据适配器配置校验必填字段
        $configFields = $adapter->getConfigFields();
        $missingFields = [];
        $isEditMode = $accountId > 0;
        $extraConfig = $params['extra_config'] ?? [];
        
        foreach ($configFields as $field) {
            if (!($field['required'] ?? false)) {
                continue;
            }
            $fieldName = $field['name'] ?? '';
            $fieldType = $field['type'] ?? 'text';
            $mapping = $field['mapping'] ?? $fieldName;
            $isPassword = $fieldType === 'password';
            
            // 编辑模式下，密码类字段允许为空（表示不修改）
            if ($isEditMode && $isPassword) {
                continue;
            }
            // 编辑模式下，api_key 未回显（列表不返回敏感字段），留空表示不修改，不参与必填校验
            if ($isEditMode && $mapping === 'api_key') {
                continue;
            }
            
            $value = '';
            
            if ($mapping === 'api_key') {
                $value = (string)($params['api_key'] ?? '');
            } elseif ($mapping === 'api_secret') {
                $value = (string)($params['api_secret'] ?? '');
            } elseif ($mapping === 'region') {
                $value = (string)($params['region'] ?? '');
            } elseif (\str_starts_with($mapping, 'extra_config.')) {
                $extraKey = \str_replace('extra_config.', '', $mapping);
                $value = trim((string)($extraConfig[$extraKey] ?? ''));
            } else {
                $value = trim((string)($extraConfig[$fieldName] ?? ''));
            }
            
            if ($value === '') {
                $missingFields[] = $field['label'] ?? $fieldName;
            }
        }
        
        if (!empty($missingFields)) {
            return ['success' => false, 'message' => (string)__('请填写必填字段：%{1}', \implode(', ', $missingFields))];
        }

        try {
            $reg = clone $this->registrarModel;
            $reg->clearQuery();
            $reg->where(DomainRegistrar::schema_fields_CODE, $registrarCode)->find()->fetch();
            if (!$reg->getData(DomainRegistrar::schema_fields_ID)) {
                $reg->setData(DomainRegistrar::schema_fields_CODE, $registrarCode);
                $reg->setData(DomainRegistrar::schema_fields_NAME, $adapter->getRegistrarName());
                $reg->setData(DomainRegistrar::schema_fields_DESCRIPTION, $adapter->getDescription());
                $reg->setData(DomainRegistrar::schema_fields_STATUS, DomainRegistrar::STATUS_ACTIVE);
                $reg->save();
            }
            $registrarId = (int)$reg->getData(DomainRegistrar::schema_fields_ID);

            $account = clone $this->accountModel;
            $account->clearQuery();
            if ($accountId > 0) {
                $account->where(DomainRegistrarAccount::schema_fields_ID, $accountId)->find()->fetch();
                if (!$account->getAccountId()) {
                    return ['success' => false, 'message' => (string)__('账号不存在：%{1}', (string)$accountId)];
                }
            }
            $account->setRegistrarId($registrarId);
            $account->setAccountName($accountName);
            if (isset($params['api_key']) && $params['api_key'] !== '') {
                $account->setApiKey((string)$params['api_key']);
            }
            if (isset($params['api_secret']) && $params['api_secret'] !== '') {
                $account->setApiSecret((string)$params['api_secret']);
            }
            $account->setData('region', (string)($params['region'] ?? ''));
            $extraConfig = $params['extra_config'] ?? [];
            if (is_array($extraConfig) && $extraConfig !== []) {
                $account->setData('extra_config', json_encode($extraConfig, JSON_UNESCAPED_UNICODE));
            }
            $account->setData('status', (string)($params['status'] ?? 'active'));
            $account->save();

            $action = $accountId > 0 ? __('更新') : __('创建');
            return [
                'success'    => true,
                'message'    => (string)__('账号%{1}成功', (string)$action),
                'account_id' => $account->getAccountId(),
            ];
        } catch (\Throwable $e) {
            w_log_error((string)__('保存账号失败：%{1}', $e->getMessage()), [], 'domain_management');
            return ['success' => false, 'message' => (string)__('保存失败：%{1}', $e->getMessage())];
        }
    }

    private function deleteRegistrarAccount(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return ['success' => false, 'message' => (string)__('账号 ID 无效')];
        }
        try {
            $account = clone $this->accountModel;
            $account->clearQuery();
            $account->where(DomainRegistrarAccount::schema_fields_ID, $accountId)->find()->fetch();
            if (!$account->getAccountId()) {
                return ['success' => false, 'message' => (string)__('账号不存在')];
            }
            $account->delete();
            return ['success' => true, 'message' => (string)__('账号已删除')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => (string)__('删除失败：%{1}', $e->getMessage())];
        }
    }

    private function getDomainList(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return [];
        }
        try {
            $account = clone $this->accountModel;
            $account->clearQuery();
            $account->where(DomainRegistrarAccount::schema_fields_ID, $accountId)->find()->fetch();
            if (!$account->getAccountId()) {
                return [];
            }
            $registrarId = (int)$account->getData(DomainRegistrarAccount::schema_fields_REGISTRAR_ID);
            $reg = clone $this->registrarModel;
            $reg->clearQuery();
            $reg->where(DomainRegistrar::schema_fields_ID, $registrarId)->find()->fetch();
            $registrarCode = (string)($reg->getData(DomainRegistrar::schema_fields_CODE) ?? '');
            $adapter = $this->resolver->getAdapter($registrarCode);
            if ($adapter === null) {
                return [];
            }
            return $adapter->getDomainList($account->getCredentials());
        } catch (\Throwable $e) {
            w_log_error((string)__('获取域名列表失败：%{1}', $e->getMessage()), [], 'domain_management');
            return [];
        }
    }

    private function checkAvailability(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        $domains   = (array)($params['domains'] ?? []);
        if ($accountId <= 0 || $domains === []) {
            return [];
        }
        try {
            $account = clone $this->accountModel;
            $account->clearQuery();
            $account->where(DomainRegistrarAccount::schema_fields_ID, $accountId)->find()->fetch();
            if (!$account->getAccountId()) {
                return [];
            }
            $registrarId = (int)$account->getData(DomainRegistrarAccount::schema_fields_REGISTRAR_ID);
            $reg = clone $this->registrarModel;
            $reg->clearQuery();
            $reg->where(DomainRegistrar::schema_fields_ID, $registrarId)->find()->fetch();
            $registrarCode = (string)($reg->getData(DomainRegistrar::schema_fields_CODE) ?? '');
            $adapter = $this->resolver->getAdapter($registrarCode);
            if ($adapter === null) {
                return [];
            }
            $credentials = $account->getCredentials();
            $results = [];
            foreach ($domains as $domain) {
                $domain = trim((string)$domain);
                if ($domain === '') {
                    continue;
                }
                try {
                    $results[] = $adapter->checkAvailability($domain, $credentials);
                } catch (\Throwable $e) {
                    $results[] = [
                        'domain'    => $domain,
                        'available' => false,
                        'error'     => $e->getMessage(),
                    ];
                }
            }
            return $results;
        } catch (\Throwable $e) {
            w_log_error((string)__('检查可用性失败：%{1}', $e->getMessage()), [], 'domain_management');
            return [];
        }
    }

    private function purchaseDomain(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        $items     = (array)($params['items'] ?? []);
        $autoResolve = (bool)($params['auto_resolve'] ?? true);
        $defaultResolveToLocal = isset($params['resolve_to_local'])
            ? (($params['resolve_to_local'] ?? 'yes') === 'yes')
            : $autoResolve;
        $defaultSubdomains = $params['subdomains'] ?? ['@', 'www'];
        $defaultDnsChoice = (string)($params['dns_choice'] ?? 'follow_registrar');
        $defaultDnsProvider = (string)($params['dns_provider'] ?? '');
        $defaultDnsAccountId = (int)($params['dns_account_id'] ?? 0);
        $defaultDnsNameservers = (string)($params['dns_nameservers'] ?? '');
        $defaultCdnChoice = (string)($params['cdn_choice'] ?? 'follow_registrar');
        $defaultCdnProvider = (string)($params['cdn_provider'] ?? '');
        $defaultCdnAccountId = (int)($params['cdn_account_id'] ?? 0);
        $defaultStartLifecycle = isset($params['start_lifecycle'])
            ? (string) $params['start_lifecycle']
            : '1';
        if (!\is_array($defaultSubdomains)) {
            $s = \trim((string) $defaultSubdomains);
            $defaultSubdomains = \str_starts_with($s, '[')
                ? (\json_decode($s, true) ?: \array_map('trim', \explode(',', $s)))
                : \array_map('trim', \explode(',', $s));
        }
        if (!\is_array($defaultSubdomains)) {
            $defaultSubdomains = ['@', 'www'];
        }

        foreach ($items as &$item) {
            if (!isset($item['resolve_to_local'])) {
                $item['resolve_to_local'] = $defaultResolveToLocal ? 'yes' : 'no';
            }
            if (!isset($item['subdomains'])) {
                $item['subdomains'] = $defaultSubdomains;
            }
            if (!isset($item['dns_choice'])) {
                $item['dns_choice'] = $defaultDnsChoice;
            }
            if (!isset($item['dns_provider'])) {
                $item['dns_provider'] = $defaultDnsProvider;
            }
            if (!isset($item['dns_account_id'])) {
                $item['dns_account_id'] = $defaultDnsAccountId;
            }
            if (!isset($item['dns_nameservers'])) {
                $item['dns_nameservers'] = $defaultDnsNameservers;
            }
            if (!isset($item['cdn_choice'])) {
                $item['cdn_choice'] = $defaultCdnChoice;
            }
            if (!isset($item['cdn_provider'])) {
                $item['cdn_provider'] = $defaultCdnProvider;
            }
            if (!isset($item['cdn_account_id'])) {
                $item['cdn_account_id'] = $defaultCdnAccountId;
            }
            if (!isset($item['start_lifecycle'])) {
                $item['start_lifecycle'] = $defaultStartLifecycle;
            }
        }
        unset($item);

        if ($accountId <= 0 || $items === []) {
            return ['success' => false, 'message' => (string)__('参数不完整')];
        }
        try {
            /** @var DomainPurchaseService $purchaseService */
            $purchaseService = ObjectManager::getInstance(DomainPurchaseService::class);
            return $purchaseService->createAndProcessOrder($accountId, $items, $autoResolve);
        } catch (\Throwable $e) {
            w_log_error((string)__('域名购买异常：%{1}', $e->getMessage()), [], 'domain_management');
            return ['success' => false, 'message' => (string)__('域名购买异常：%{1}', $e->getMessage())];
        }
    }

    private function testConnection(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return ['success' => false, 'message' => (string)__('账号 ID 无效')];
        }
        try {
            $account = clone $this->accountModel;
            $account->clearQuery();
            $account->where(DomainRegistrarAccount::schema_fields_ID, $accountId)->find()->fetch();
            if (!$account->getAccountId()) {
                return ['success' => false, 'message' => (string)__('账号不存在')];
            }
            $registrarId = (int)$account->getData(DomainRegistrarAccount::schema_fields_REGISTRAR_ID);
            $reg = clone $this->registrarModel;
            $reg->clearQuery();
            $reg->where(DomainRegistrar::schema_fields_ID, $registrarId)->find()->fetch();
            $registrarCode = (string)($reg->getData(DomainRegistrar::schema_fields_CODE) ?? '');
            $adapter = $this->resolver->getAdapter($registrarCode);
            if ($adapter === null) {
                return ['success' => false, 'message' => (string)__('未找到适配器')];
            }
            $adapter->testConnection($account->getCredentials());
            return ['success' => true, 'message' => (string)__('连接成功')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getConfigFields(array $params): array
    {
        $registrarCode = (string)($params['registrar_code'] ?? '');
        if ($registrarCode === '') {
            return [];
        }
        try {
            $adapter = $this->resolver->getAdapter($registrarCode);
            if ($adapter === null) {
                return [];
            }
            return $adapter->getConfigFields();
        } catch (\Throwable $e) {
            w_log_error((string)__('获取配置字段失败：%{1}', $e->getMessage()), [], 'domain_management');
            return [];
        }
    }

    private function getRegistrarInfo(array $params): array
    {
        $registrarCode = (string)($params['registrar_code'] ?? '');
        if ($registrarCode === '') {
            return [];
        }
        try {
            $adapter = $this->resolver->getAdapter($registrarCode);
            if ($adapter === null) {
                return [];
            }
            return [
                'code'          => $adapter->getRegistrarCode(),
                'name'          => $adapter->getRegistrarName(),
                'description'   => $adapter->getDescription(),
                'version'       => $adapter->getVersion(),
                'config_fields' => $adapter->getConfigFields(),
                'config_help'   => $adapter->getConfigHelp(),
            ];
        } catch (\Throwable $e) {
            w_log_error((string)__('获取注册商信息失败：%{1}', $e->getMessage()), [], 'domain_management');
            return [];
        }
    }

    private function modifyDns(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        $domain = (string)($params['domain'] ?? '');
        $nameservers = (string)($params['nameservers'] ?? '');

        if ($accountId <= 0 || $domain === '' || $nameservers === '') {
            return ['success' => false, 'message' => (string)__('账号 ID、域名和 NS 列表不能为空')];
        }

        try {
            $account = clone $this->accountModel;
            $account->clearQuery();
            $account->where(DomainRegistrarAccount::schema_fields_ID, $accountId)->find()->fetch();
            if (!$account->getAccountId()) {
                return ['success' => false, 'message' => (string)__('账号不存在')];
            }

            $registrarId = (int)$account->getData(DomainRegistrarAccount::schema_fields_REGISTRAR_ID);
            $reg = clone $this->registrarModel;
            $reg->clearQuery();
            $reg->where(DomainRegistrar::schema_fields_ID, $registrarId)->find()->fetch();
            $registrarCode = (string)($reg->getData(DomainRegistrar::schema_fields_CODE) ?? '');

            $adapter = $this->resolver->getAdapter($registrarCode);
            if ($adapter === null) {
                return ['success' => false, 'message' => (string)__('未找到适配器：%{1}', $registrarCode)];
            }

            if (!$adapter instanceof NameserverSwitchInterface) {
                return ['success' => false, 'message' => (string)__('域名商 %{1} 不支持 NS 切换', $registrarCode)];
            }

            $credentials = $account->getCredentials();
            $nsList = \array_filter(\array_map('trim', \explode(',', $nameservers)));
            return $adapter->updateNameservers($domain, $nsList, $credentials);
        } catch (\Throwable $e) {
            w_log_error((string)__('修改 DNS 失败：%{1}', $e->getMessage()), [], 'domain_management');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getLastSyncTime(array $params): ?string
    {
        $accountId = (int)($params['account_id'] ?? 0);
        return $this->domainSyncService->getLastSyncTime($accountId);
    }

    private function getLocalDomains(array $params): array
    {
        $filters = (array)($params['filters'] ?? []);
        $page = \max(1, (int)($params['page'] ?? 1));
        $limit = \max(1, \min(500, (int)($params['limit'] ?? 20)));
        return $this->domainSyncService->getDomains($filters, $page, $limit);
    }

    private function getRemoteDomains(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return ['success' => false, 'message' => __('账号 ID 无效'), 'domains' => []];
        }
        return $this->domainSyncService->fetchRemoteDomains($accountId);
    }

    private function importDomains(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        $domains = (array)($params['domains'] ?? []);
        $resolveMode = $params['resolve_mode'] ?? DomainSyncService::RESOLVE_MODE_BATCH_TO_LOCAL;
        return $this->domainSyncService->importDomains($accountId, $domains, $resolveMode);
    }

    private function syncDomains(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId > 0) {
            return $this->domainSyncService->syncAccount($accountId);
        }
        return $this->domainSyncService->syncAllAccounts();
    }

    private function batchOperateDomains(array $params): array
    {
        $domainIds = (array)($params['domain_ids'] ?? []);
        $operation = (string)($params['operation'] ?? '');
        $operationParams = (array)($params['params'] ?? []);
        return $this->domainSyncService->batchOperate($domainIds, $operation, $operationParams);
    }

    private function getWebsiteById(array $params): ?array
    {
        $websiteId = (int)($params['website_id'] ?? 0);
        if ($websiteId <= 0) {
            return null;
        }
        $website = clone $this->websiteModel;
        $website->load($websiteId);
        if (!$website->getId()) {
            return null;
        }
        return [
            'website_id' => (int)$website->getId(),
            'name' => $website->getData(Website::schema_fields_NAME),
            'code' => $website->getData(Website::schema_fields_CODE),
            'url' => $website->getData(Website::schema_fields_URL),
            'default_currency' => $website->getData(Website::schema_fields_DEFAULT_CURRENCY),
            'default_language' => $website->getData(Website::schema_fields_DEFAULT_LANGUAGE),
            'default_timezone' => $website->getData(Website::schema_fields_DEFAULT_TIMEZONE),
            'scope' => $website->getData(Website::schema_fields_SCOPE),
        ];
    }

    private function getWebsiteList(array $params): array
    {
        $website = clone $this->websiteModel;
        $website->clearQuery();
        $items = $website->select()->fetch()->getItems();
        $list = [];
        foreach ($items as $w) {
            if (!$w->getId()) {
                continue;
            }
            $list[] = [
                'website_id' => (int)$w->getId(),
                'name' => $w->getData(Website::schema_fields_NAME),
                'code' => $w->getData(Website::schema_fields_CODE),
                'url' => $w->getData(Website::schema_fields_URL),
            ];
        }
        return $list;
    }

    private function getWebsiteLanguageCodes(array $params): array
    {
        $websiteId = (int)($params['website_id'] ?? 0);
        if ($websiteId <= 0) {
            return [];
        }
        return $this->websiteLanguageModel->getWebsiteLanguageCodes($websiteId);
    }

    private function getDomainPoolList(array $params): array
    {
        $status = $params['status'] ?? DomainPool::STATUS_ACTIVE;
        $limit = (int)($params['limit'] ?? 500);
        $excludeSiteCreated = ($params['exclude_site_created'] ?? false);
        if ($limit <= 0) {
            $limit = 500;
        }
        $limit = \min($limit, 2000);

        /** @var DomainPool $pool */
        $pool = ObjectManager::getInstance(DomainPool::class);
        $pool->clearQuery();
        if ($status !== null && $status !== '') {
            $pool->where(DomainPool::schema_fields_STATUS, (string)$status);
        }
        if ($excludeSiteCreated) {
            $pool->whereRaw(
                '(' . DomainPool::schema_fields_SITE_CREATED . ' IS NULL OR ' . DomainPool::schema_fields_SITE_CREATED . ' = 0)',
                'AND'
            );
        }
        $rows = $pool->order(DomainPool::schema_fields_DOMAIN, 'ASC')
            ->pagination(1, $limit)
            ->select()
            ->fetchArray();

        $list = [];
        foreach ($rows as $row) {
            $domain = (string)($row[DomainPool::schema_fields_DOMAIN] ?? '');
            if ($domain === '') {
                continue;
            }
            $list[] = [
                'pool_id' => (int)($row[DomainPool::schema_fields_ID] ?? 0),
                'domain' => $domain,
                'root_domain' => (string)($row[DomainPool::schema_fields_ROOT_DOMAIN] ?? ''),
                'status' => (string)($row[DomainPool::schema_fields_STATUS] ?? ''),
                'https_status' => (string)($row[DomainPool::schema_fields_HTTPS_STATUS] ?? ''),
            ];
        }
        return $list;
    }

    private function getDnsRecords(array $params): array
    {
        $domainId = (int)($params['domain_id'] ?? 0);
        if ($domainId <= 0) {
            return ['success' => false, 'message' => (string)__('域名 ID 不能为空')];
        }

        /** @var Domain $domain */
        $domain = ObjectManager::getInstance(Domain::class, [], false);
        $domain->load($domainId);
        if (!$domain->getDomainId()) {
            return ['success' => false, 'message' => (string)__('域名不存在')];
        }

        /** @var DomainResolveService $resolveService */
        $resolveService = ObjectManager::getInstance(DomainResolveService::class);
        /** @var ServerIpService $serverIpService */
        $serverIpService = ObjectManager::getInstance(ServerIpService::class);

        $sync = $resolveService->syncDnsRecords($domain);
        $details = $resolveService->getDnsDetails($domain);

        $records = \is_array($details['records'] ?? null) ? $details['records'] : [];
        // 查询 DNS 时：查到什么就入池；本机/非本机只影响标记，不影响是否入池
        $poolSync = $this->syncDnsRecordsToDomainPool($domain, $records, true);

        // 双保险：再用实时 DNS 查询补写一次池子，防止第三方接口返回不全或失败
        $liveRecords = $this->collectLiveDnsRecordsForPoolSync($domain);
        if ($liveRecords !== []) {
            $liveSync = $this->syncDnsRecordsToDomainPool($domain, $liveRecords, true);
            $poolSync['added'] += (int)($liveSync['added'] ?? 0);
            $poolSync['marked_non_local'] += (int)($liveSync['marked_non_local'] ?? 0);
            $poolSync['skipped'] += (int)($liveSync['skipped'] ?? 0);
        }

        $dnsProvider = \is_array($details['dns_provider'] ?? null) ? $details['dns_provider'] : [];
        $syncError = (string)($sync['error'] ?? '');

        return [
            // 查询层统一返回 success，避免上层因第三方适配器波动中断 DNS 管理流程
            'success' => true,
            'message' => $syncError === ''
                ? (string)__('获取成功')
                : (string)__('DNS远程同步失败，已使用本地/实时数据回填并同步域名池：%{1}', [$syncError]),
            'data' => [
                'domain' => $domain->getDomain(),
                'records' => $records,
                'dns_provider' => (string)($dnsProvider['provider'] ?? $domain->getDnsProvider() ?? ''),
                'dns_provider_name' => (string)($dnsProvider['name'] ?? $domain->getDnsProvider() ?? ''),
                'server_ip' => $serverIpService->getPublicIpv4(),
                'pool_sync' => $poolSync,
                'sync_error' => $syncError,
            ],
        ];
    }

    private function addAcmeTxtRecord(array $params): array
    {
        $domain = \strtolower(\trim((string)($params['domain'] ?? '')));
        $challengeValue = (string)($params['challenge_value'] ?? '');
        $poolId = (int)($params['pool_id'] ?? 0);
        $domainId = (int)($params['domain_id'] ?? 0);
        $onProgress = $params['_on_progress'] ?? null;
        $onProgress = $onProgress instanceof \Closure ? $onProgress : null;

        if ($domain === '' || $challengeValue === '') {
            return ['success' => false, 'message' => (string)__('domain 和 challenge_value 不能为空'), 'record_id' => ''];
        }

        if ($onProgress) {
            $onProgress((string)__('解析根域名与 DNS 账户...'), ['step' => 'resolve']);
        }
        $rootDomainModel = $this->resolveRootDomainForAcme($domain, $poolId, $domainId);
        if ($rootDomainModel === null) {
            return ['success' => false, 'message' => (string)__('无法解析域名的 DNS 管理账户。请确保域名已在系统中配置且已关联 DNS 账户。'), 'record_id' => ''];
        }

        /** @var DomainResolveService $resolveService */
        $resolveService = ObjectManager::getInstance(DomainResolveService::class);
        $dnsResult = $resolveService->getDnsManagementAccount($rootDomainModel, false);
        if ($dnsResult['error'] !== '') {
            return ['success' => false, 'message' => $dnsResult['error'], 'record_id' => ''];
        }
        $providerName = $dnsResult['adapter']->getRegistrarName() ?? (string)__('DNS 供应商');
        if ($onProgress) {
            $onProgress((string)__('使用 %{1} 添加 TXT 记录', [$providerName]), ['step' => 'add', 'dns_provider' => $providerName]);
        }

        $rootDomain = \strtolower((string)$rootDomainModel->getDomain());
        $domain = \strtolower($domain);
        if ($domain === $rootDomain || \str_starts_with($domain, '*.')) {
            $host = '_acme-challenge';
        } else {
            $prefix = \explode('.', $domain)[0];
            $host = '_acme-challenge.' . $prefix;
        }

        $record = [
            'type' => 'TXT',
            'host' => $host,
            'value' => $challengeValue,
            'ttl' => 60,
        ];

        try {
            $result = $dnsResult['adapter']->addDnsRecord($rootDomain, $record, $dnsResult['account']->getCredentials());
            if ($result['success'] ?? false) {
                if ($onProgress) {
                    $onProgress((string)__('TXT 记录已在 %{1} 添加成功', [$providerName]), ['step' => 'add_done']);
                }
                return [
                    'success' => true,
                    'message' => (string)__('TXT 记录添加成功'),
                    'record_id' => (string)($result['record_id'] ?? ''),
                ];
            }
            return [
                'success' => false,
                'message' => (string)($result['message'] ?? __('添加 TXT 记录失败')),
                'record_id' => '',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'record_id' => ''];
        }
    }

    private function removeAcmeTxtRecord(array $params): array
    {
        $domain = \strtolower(\trim((string)($params['domain'] ?? '')));
        $recordId = (string)($params['record_id'] ?? '');
        $poolId = (int)($params['pool_id'] ?? 0);
        $domainId = (int)($params['domain_id'] ?? 0);

        if ($domain === '' || $recordId === '') {
            return ['success' => false, 'message' => (string)__('domain 和 record_id 不能为空')];
        }

        $rootDomainModel = $this->resolveRootDomainForAcme($domain, $poolId, $domainId);
        if ($rootDomainModel === null) {
            return ['success' => false, 'message' => (string)__('无法解析域名的 DNS 管理账户')];
        }

        /** @var DomainResolveService $resolveService */
        $resolveService = ObjectManager::getInstance(DomainResolveService::class);
        $dnsResult = $resolveService->getDnsManagementAccount($rootDomainModel, false);
        if ($dnsResult['error'] !== '') {
            return ['success' => false, 'message' => $dnsResult['error']];
        }

        try {
            $result = $dnsResult['adapter']->deleteDnsRecord(
                $rootDomainModel->getDomain(),
                $recordId,
                $dnsResult['account']->getCredentials()
            );
            return [
                'success' => (bool)($result['success'] ?? false),
                'message' => (string)($result['message'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function resolveRootDomainForAcme(string $domain, int $poolId, int $domainId): ?Domain
    {
        $domainModel = ObjectManager::getInstance(Domain::class, [], false);

        if ($domainId > 0) {
            $domainModel->load($domainId);
            if ($domainModel->getDomainId()) {
                return $domainModel;
            }
        }

        if ($poolId > 0) {
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            $pool->load($poolId);
            $parentId = (int)$pool->getParentDomainId();
            $rootDomain = \trim((string)$pool->getRootDomain());
            if ($parentId > 0) {
                $domainModel->load($parentId);
                if ($domainModel->getDomainId()) {
                    return $domainModel;
                }
            }
            if ($rootDomain !== '') {
                $domainModel->clearQuery();
                $domainModel->where(Domain::schema_fields_DOMAIN, $rootDomain)->find()->fetch();
                if ($domainModel->getDomainId()) {
                    return $domainModel;
                }
            }
        }

        $parts = \explode('.', $domain);
        $rootDomain = \count($parts) >= 2 ? $parts[\count($parts) - 2] . '.' . $parts[\count($parts) - 1] : $domain;
        $domainModel->clearQuery();
        $domainModel->where(Domain::schema_fields_DOMAIN, $rootDomain)->find()->fetch();
        return $domainModel->getDomainId() ? $domainModel : null;
    }

    private function syncDnsRecordsToDomainPool(Domain $rootDomain, array $records, bool $createNonLocal = false): array
    {
        $serverIpService = ObjectManager::getInstance(ServerIpService::class);
        $rootDomainName = \strtolower((string)$rootDomain->getDomain());
        $parentDomainId = (int)$rootDomain->getDomainId();
        $now = \date('Y-m-d H:i:s');

        $added = 0;
        $markedNonLocal = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (!\is_array($record)) {
                $skipped++;
                continue;
            }

            $type = \strtoupper((string)($record['type'] ?? $record['record_type'] ?? ''));
            $host = \trim((string)($record['host'] ?? $record['name'] ?? '@'));
            $value = \trim((string)($record['value'] ?? $record['data'] ?? ''));

            $fullDomain = $this->buildFullDomainFromHost($rootDomainName, $host);
            if ($fullDomain === '') {
                $skipped++;
                continue;
            }

            $isIpRecord = $type === 'A' || $type === 'AAAA';
            $isLocal = $isIpRecord && $value !== '' && $serverIpService->isLocalServer($value);

            $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
            $poolDomain->loadByDomain($fullDomain);
            $exists = $poolDomain->getPoolId() > 0;

            if (!$exists && !$isLocal && !$createNonLocal) {
                $skipped++;
                continue;
            }

            if (!$exists) {
                $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
                $poolDomain->setDomain($fullDomain);
                $poolDomain->setParentDomainId($parentDomainId);
                $poolDomain->setDescription(__('由查询层 DNS 同步自动写入'));
                $poolDomain->setStatus(DomainPool::STATUS_ACTIVE);
                $poolDomain->setResolveStatus(DomainPool::RESOLVE_STATUS_RESOLVED);
                $poolDomain->setDnsStatus(DomainPool::INFRA_STATUS_READY);
                $poolDomain->setCdnStatus(DomainPool::INFRA_STATUS_READY);
                $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_NONE);
                $poolDomain->setResolveCheckedAt($now);
                $poolDomain->setResolveError('');
                $poolDomain->setIsLocalServer($isLocal);
                if ($type === 'A') {
                    $poolDomain->setResolvedIp($value);
                }
                if ($type === 'AAAA') {
                    $poolDomain->setResolvedIpv6($value);
                }
                $poolDomain->calculateSiteReady();
                $poolDomain->save();
                $added++;
                continue;
            }

            if ($value === '') {
                $skipped++;
                continue;
            }

            $wasLocal = $poolDomain->isLocalServer();
            $poolDomain->setResolveStatus(DomainPool::RESOLVE_STATUS_RESOLVED);
            $poolDomain->setResolveCheckedAt($now);
            $poolDomain->setResolveError('');
            if ($type === 'A') {
                $poolDomain->setResolvedIp($value);
            }
            if ($type === 'AAAA') {
                $poolDomain->setResolvedIpv6($value);
            }
            if ($isIpRecord) {
                $poolDomain->setIsLocalServer($isLocal);
                if ($wasLocal && !$isLocal) {
                    $markedNonLocal++;
                }
            }
            $poolDomain->calculateSiteReady();
            $poolDomain->save();
        }

        return [
            'added' => $added,
            'marked_non_local' => $markedNonLocal,
            'skipped' => $skipped,
        ];
    }

    private function buildFullDomainFromHost(string $rootDomain, string $host): string
    {
        $rootDomain = \strtolower(\trim($rootDomain));
        $host = \strtolower(\trim($host));

        if ($rootDomain === '') {
            return '';
        }
        if ($host === '' || $host === '@') {
            return $rootDomain;
        }

        $host = \rtrim($host, '.');
        if ($host === $rootDomain) {
            return $rootDomain;
        }
        if (\str_ends_with($host, '.' . $rootDomain)) {
            return $host;
        }

        return $host . '.' . $rootDomain;
    }

    private function collectLiveDnsRecordsForPoolSync(Domain $rootDomain): array
    {
        $root = \strtolower(\trim((string)$rootDomain->getDomain()));
        if ($root === '') {
            return [];
        }

        $targets = [
            ['host' => '@', 'fqdn' => $root],
            ['host' => 'www', 'fqdn' => 'www.' . $root],
        ];

        $records = [];
        foreach ($targets as $target) {
            $host = $target['host'];
            $fqdn = $target['fqdn'];

            $aRecords = @\dns_get_record($fqdn, \DNS_A);
            if (\is_array($aRecords)) {
                foreach ($aRecords as $aRecord) {
                    $ip = \trim((string)($aRecord['ip'] ?? ''));
                    if ($ip !== '') {
                        $records[] = ['type' => 'A', 'host' => $host, 'value' => $ip];
                    }
                }
            }

            $aaaaRecords = @\dns_get_record($fqdn, \DNS_AAAA);
            if (\is_array($aaaaRecords)) {
                foreach ($aaaaRecords as $aaaaRecord) {
                    $ip6 = \trim((string)($aaaaRecord['ipv6'] ?? ''));
                    if ($ip6 !== '') {
                        $records[] = ['type' => 'AAAA', 'host' => $host, 'value' => $ip6];
                    }
                }
            }
        }

        return $records;
    }
}
