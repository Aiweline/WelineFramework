<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名同步服务
 *
 * 从域名商 API 同步域名到本地数据库，支持分页查询和批量操作
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\DomainRegistrarInterface;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainAutoResolveTask;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Service\SubdomainGeneratorService;

class DomainSyncService
{
    private Domain $domainModel;
    private DomainRegistrarAccount $accountModel;
    private DomainRegistrarResolverService $registrarResolver;

    public function __construct(
        Domain $domainModel,
        DomainRegistrarAccount $accountModel,
        DomainRegistrarResolverService $registrarResolver
    ) {
        $this->domainModel = $domainModel;
        $this->accountModel = $accountModel;
        $this->registrarResolver = $registrarResolver;
    }

    /**
     * 同步指定账户的所有域名
     *
     * @param int $accountId 账户ID
     * @return array{success: bool, message: string, synced: int, added: int, updated: int, deleted: int}
     */
    public function syncAccount(int $accountId): array
    {
        $account = $this->loadAccount($accountId);
        if (!$account->getAccountId()) {
            return [
                'success' => false,
                'message' => __('账户不存在'),
                'synced' => 0,
                'added' => 0,
                'updated' => 0,
                'deleted' => 0,
            ];
        }

        if ($account->getStatus() !== DomainRegistrarAccount::STATUS_ACTIVE) {
            return [
                'success' => false,
                'message' => __('账户未启用'),
                'synced' => 0,
                'added' => 0,
                'updated' => 0,
                'deleted' => 0,
            ];
        }

        try {
            $registrarCode = $account->getRegistrarCode();
            if ($registrarCode === null || $registrarCode === '') {
                $registrarId = $account->getRegistrarId();
                return [
                    'success' => false,
                    'message' => __('账户「%{1}」关联的域名商不存在或未配置（registrar_id=%{2}）', [
                        $account->getAccountName(),
                        $registrarId,
                    ]),
                    'synced' => 0,
                    'added' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                ];
            }

            $adapter = $this->registrarResolver->getAdapter($registrarCode);
            if (!$adapter) {
                return [
                    'success' => false,
                    'message' => __('账户「%{1}」的域名商适配器「%{2}」未注册', [
                        $account->getAccountName(),
                        $registrarCode,
                    ]),
                    'synced' => 0,
                    'added' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                ];
            }

            // 跳过非域名注册商（如 Cloudflare 只是 DNS 服务商）
            // 非域名注册商的 getDomainList 返回的是托管域名，不是真正拥有的域名
            if (!$adapter->isDomainRegistrar()) {
                return [
                    'success' => true,
                    'message' => __('「%{1}」是 DNS 服务商，不是域名注册商，跳过域名同步（域名归属应以实际注册商为准）', [
                        $adapter->getRegistrarName(),
                    ]),
                    'synced' => 0,
                    'added' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                ];
            }

            $credentials = $account->getCredentials();
            $remoteDomains = $adapter->getDomainList($credentials);

            if ($remoteDomains === []) {
                return [
                    'success' => true,
                    'message' => __('该账户下没有域名'),
                    'synced' => 0,
                    'added' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                ];
            }

            $domainsToSync = [];
            $domainNames = [];

            foreach ($remoteDomains as $rd) {
                $domainName = (string) ($rd['domain'] ?? '');
                if ($domainName === '') {
                    continue;
                }

                $domainNames[] = $domainName;

                $nameservers = [];
                if (!empty($rd['nameservers'])) {
                    $nameservers = \is_array($rd['nameservers']) ? $rd['nameservers'] : \explode(',', $rd['nameservers']);
                }

                $domainsToSync[] = [
                    'domain' => $domainName,
                    'status' => (string) ($rd['status'] ?? Domain::STATUS_ACTIVE),
                    'registrar_status' => (string) ($rd['registrar_status'] ?? $rd['status'] ?? ''),
                    'expires_at' => (string) ($rd['expires_at'] ?? ''),
                    'nameservers' => $nameservers,
                    'extra_data' => $rd,
                ];
            }

            $domainModelInstance = ObjectManager::getInstance(Domain::class);
            $syncResult = $domainModelInstance->syncDomains($accountId, $domainsToSync);

            $deleted = $domainModelInstance->removeStale($accountId, $domainNames);

            return [
                'success' => true,
                'message' => __('同步完成：新增 %{1}，更新 %{2}，删除 %{3}', [
                    $syncResult['added'],
                    $syncResult['updated'],
                    $deleted,
                ]),
                'synced' => $syncResult['synced'],
                'added' => $syncResult['added'],
                'updated' => $syncResult['updated'],
                'deleted' => $deleted,
            ];
        } catch (\Throwable $e) {
            w_log_error("同步账户 {$accountId} 失败: " . $e->getMessage(), [], 'domain_sync');
            return [
                'success' => false,
                'message' => __('同步失败：%{1}', [$e->getMessage()]),
                'synced' => 0,
                'added' => 0,
                'updated' => 0,
                'deleted' => 0,
            ];
        }
    }

    /**
     * 同步所有启用账户的域名
     *
     * @return array{success: bool, message: string, accounts: int, total_synced: int, results: array}
     */
    public function syncAllAccounts(): array
    {
        $accountModelInstance = ObjectManager::getInstance(DomainRegistrarAccount::class);
        $accountModelInstance->clearData(true);
        $accounts = $accountModelInstance
            ->where(DomainRegistrarAccount::schema_fields_STATUS, DomainRegistrarAccount::STATUS_ACTIVE)
            ->select()
            ->fetchArray();

        if ($accounts === []) {
            return [
                'success' => true,
                'message' => __('没有启用的域名商账户'),
                'accounts' => 0,
                'total_synced' => 0,
                'results' => [],
            ];
        }

        $results = [];
        $totalSynced = 0;
        $failedCount = 0;
        $failedDetails = [];
        $successDetails = [];

        foreach ($accounts as $accountData) {
            $accountId = (int) ($accountData[DomainRegistrarAccount::schema_fields_ID] ?? 0);
            $accountName = (string) ($accountData[DomainRegistrarAccount::schema_fields_ACCOUNT_NAME] ?? "ID:{$accountId}");
            if ($accountId <= 0) {
                continue;
            }

            $result = $this->syncAccount($accountId);
            $results[$accountId] = $result;

            if ($result['success']) {
                $totalSynced += $result['synced'];
                $successDetails[] = __('✓ %{1}：同步 %{2} 个域名', [$accountName, $result['synced']]);
            } else {
                $failedCount++;
                $failedDetails[] = __('✗ %{1}：%{2}', [$accountName, $result['message']]);
            }
        }

        $accountCount = \count($accounts);
        $successCount = $accountCount - $failedCount;

        $messageParts = [
            __('同步完成：%{1}/%{2} 个账户成功，共同步 %{3} 个域名', [
                $successCount,
                $accountCount,
                $totalSynced,
            ]),
        ];

        if ($successDetails !== []) {
            $messageParts[] = "\n" . __('【成功】') . "\n" . \implode("\n", $successDetails);
        }

        if ($failedDetails !== []) {
            $messageParts[] = "\n" . __('【失败】') . "\n" . \implode("\n", $failedDetails);
        }

        return [
            'success' => $failedCount === 0,
            'message' => \implode('', $messageParts),
            'accounts' => $accountCount,
            'total_synced' => $totalSynced,
            'results' => $results,
        ];
    }

    /**
     * 获取本地域名列表（分页+搜索）
     *
     * @param array $filters 筛选条件 [account_id, status, search]
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array{items: array, total: int, page: int, limit: int, pages: int, accounts: array}
     */
    public function getDomains(array $filters, int $page = 1, int $limit = 20): array
    {
        $domainModelInstance = ObjectManager::getInstance(Domain::class);
        $result = $domainModelInstance->getPagedList($filters, $page, $limit);

        $accountIds = \array_unique(\array_column($result['items'], Domain::schema_fields_ACCOUNT_ID));
        $accounts = [];

        if ($accountIds !== []) {
            $accountModelInstance = ObjectManager::getInstance(DomainRegistrarAccount::class);
            $accountModelInstance->clearData(true);
            $accountRows = $accountModelInstance
                ->where(DomainRegistrarAccount::schema_fields_ID, $accountIds, 'IN')
                ->fields(
                    DomainRegistrarAccount::schema_fields_ID . ',' .
                    DomainRegistrarAccount::schema_fields_ACCOUNT_NAME . ',' .
                    DomainRegistrarAccount::schema_fields_REGISTRAR_ID
                )
                ->select()
                ->fetchArray();

            foreach ($accountRows as $row) {
                $accounts[(int) $row[DomainRegistrarAccount::schema_fields_ID]] = $row[DomainRegistrarAccount::schema_fields_ACCOUNT_NAME] ?? '';
            }
        }

        $result['accounts'] = $accounts;

        return $result;
    }

    /**
     * 获取所有启用的域名商账户（用于筛选下拉）
     *
     * @return array
     */
    public function getActiveAccounts(): array
    {
        $accountModelInstance = ObjectManager::getInstance(DomainRegistrarAccount::class);
        $accountModelInstance->clearData(true);
        return $accountModelInstance
            ->where(DomainRegistrarAccount::schema_fields_STATUS, DomainRegistrarAccount::STATUS_ACTIVE)
            ->order(DomainRegistrarAccount::schema_fields_ACCOUNT_NAME, 'ASC')
            ->fields(
                DomainRegistrarAccount::schema_fields_ID . ',' .
                DomainRegistrarAccount::schema_fields_ACCOUNT_NAME . ',' .
                DomainRegistrarAccount::schema_fields_REGISTRAR_ID
            )
            ->select()
            ->fetchArray();
    }

    /**
     * 批量操作域名
     *
     * @param array $domainIds 域名ID数组
     * @param string $operation 操作类型：change_dns, renew, etc.
     * @param array $params 操作参数
     * @return array{success: bool, message: string, results: array}
     */
    public function batchOperate(array $domainIds, string $operation, array $params = []): array
    {
        if ($domainIds === []) {
            return [
                'success' => false,
                'message' => __('请选择要操作的域名'),
                'results' => [],
            ];
        }

        $domainModelInstance = ObjectManager::getInstance(Domain::class);
        $domains = $domainModelInstance->getByIds($domainIds);

        if ($domains === []) {
            return [
                'success' => false,
                'message' => __('未找到指定的域名'),
                'results' => [],
            ];
        }

        $results = [];

        switch ($operation) {
            case 'change_dns':
                $results = $this->batchChangeDns($domains, $params);
                break;

            case 'sync':
                $accountIds = \array_unique(\array_column($domains, Domain::schema_fields_ACCOUNT_ID));
                foreach ($accountIds as $accountId) {
                    $results[(int) $accountId] = $this->syncAccount((int) $accountId);
                }
                break;

            default:
                return [
                    'success' => false,
                    'message' => __('不支持的操作类型：%{1}', [$operation]),
                    'results' => [],
                ];
        }

        $successCount = 0;
        $failCount = 0;
        foreach ($results as $r) {
            if (!empty($r['success'])) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        return [
            'success' => $failCount === 0,
            'message' => __('批量操作完成：成功 %{1}，失败 %{2}', [$successCount, $failCount]),
            'results' => $results,
        ];
    }

    /**
     * 批量切换 DNS
     *
     * @param array $domains 域名数据数组
     * @param array $params 参数 [dns => 'ns1.cf.com,ns2.cf.com']
     * @return array
     */
    private function batchChangeDns(array $domains, array $params): array
    {
        $dnsServers = (string) ($params['dns'] ?? '');
        if ($dnsServers === '') {
            return ['all' => ['success' => false, 'message' => __('DNS 服务器不能为空')]];
        }

        $results = [];
        $accountCache = [];

        foreach ($domains as $domainData) {
            $domainName = (string) ($domainData[Domain::schema_fields_DOMAIN] ?? '');
            $accountId = (int) ($domainData[Domain::schema_fields_ACCOUNT_ID] ?? 0);

            if ($domainName === '' || $accountId <= 0) {
                $results[$domainName] = ['success' => false, 'message' => __('数据无效')];
                continue;
            }

            if (!isset($accountCache[$accountId])) {
                $account = $this->loadAccount($accountId);
                $adapter = $this->registrarResolver->getAdapter($account->getRegistrarCode());
                $accountCache[$accountId] = [
                    'account' => $account,
                    'adapter' => $adapter,
                    'credentials' => $account->getAccountId() ? $account->getCredentials() : [],
                ];
            }

            $cached = $accountCache[$accountId];

            if (!$cached['adapter'] instanceof DomainRegistrarInterface) {
                $results[$domainName] = ['success' => false, 'message' => __('适配器不支持此操作')];
                continue;
            }

            try {
                if (\method_exists($cached['adapter'], 'modifyDns')) {
                    $result = $cached['adapter']->modifyDns($domainName, $dnsServers, $cached['credentials']);
                    $results[$domainName] = $result;
                } else {
                    $results[$domainName] = ['success' => false, 'message' => __('适配器不支持修改 DNS')];
                }
            } catch (\Throwable $e) {
                $results[$domainName] = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * 加载账户
     */
    private function loadAccount(int $accountId): DomainRegistrarAccount
    {
        $account = ObjectManager::getInstance(DomainRegistrarAccount::class);
        $account->clearData(true);
        $account->load($accountId);
        return $account;
    }

    /**
     * 获取最后同步时间
     *
     * @param int $accountId 账户ID（0=所有账户）
     * @return string|null
     */
    public function getLastSyncTime(int $accountId = 0): ?string
    {
        $domainModelInstance = ObjectManager::getInstance(Domain::class);
        $domainModelInstance->clearData(true);

        if ($accountId > 0) {
            $domainModelInstance->where(Domain::schema_fields_ACCOUNT_ID, $accountId);
        }

        $result = $domainModelInstance->order(Domain::schema_fields_SYNCED_AT, 'DESC')
            ->limit(1)
            ->fields(Domain::schema_fields_SYNCED_AT)
            ->select()
            ->fetchArray();

        if ($result !== [] && !empty($result[0][Domain::schema_fields_SYNCED_AT])) {
            return $result[0][Domain::schema_fields_SYNCED_AT];
        }

        return null;
    }

    /**
     * 获取远程域名列表（仅查询，不存入本地）
     *
     * @param int $accountId 账户ID
     * @return array{success: bool, message: string, domains: array}
     */
    public function fetchRemoteDomains(int $accountId): array
    {
        $account = $this->loadAccount($accountId);
        if (!$account->getAccountId()) {
            return [
                'success' => false,
                'message' => __('账户不存在'),
                'domains' => [],
            ];
        }

        if ($account->getStatus() !== DomainRegistrarAccount::STATUS_ACTIVE) {
            return [
                'success' => false,
                'message' => __('账户未启用'),
                'domains' => [],
            ];
        }

        try {
            $registrarCode = $account->getRegistrarCode();
            if ($registrarCode === null || $registrarCode === '') {
                return [
                    'success' => false,
                    'message' => __('账户关联的域名商不存在或未配置'),
                    'domains' => [],
                ];
            }

            $adapter = $this->registrarResolver->getAdapter($registrarCode);
            if (!$adapter) {
                return [
                    'success' => false,
                    'message' => __('域名商适配器「%{1}」未注册', [$registrarCode]),
                    'domains' => [],
                ];
            }

            $isDomainRegistrar = $adapter->isDomainRegistrar();
            $credentials = $account->getCredentials();
            $remoteDomains = $adapter->getDomainList($credentials);

            // 获取本地已存在的域名
            $domainModelInstance = ObjectManager::getInstance(Domain::class);
            $localDomains = $domainModelInstance->clearQuery()
                ->where(Domain::schema_fields_ACCOUNT_ID, $accountId)
                ->fields(Domain::schema_fields_DOMAIN)
                ->select()
                ->fetchArray();
            $localDomainNames = \array_column($localDomains, Domain::schema_fields_DOMAIN);

            // 标记是否已导入本地
            $domains = [];
            foreach ($remoteDomains as $rd) {
                $domainName = (string) ($rd['domain'] ?? '');
                if ($domainName === '') {
                    continue;
                }

                $domains[] = [
                    'domain' => $domainName,
                    'status' => (string) ($rd['status'] ?? 'active'),
                    'expires_at' => (string) ($rd['expires_at'] ?? ''),
                    'is_local' => \in_array($domainName, $localDomainNames, true),
                    'extra' => $rd,
                ];
            }

            // 生成适当的消息
            $message = __('获取成功，共 %{1} 个域名', [\count($domains)]);
            if (!$isDomainRegistrar) {
                $message .= ' ' . __('（注意：「%{1}」是 DNS 服务商，非域名注册商，这些域名可能已属于其他注册商账户）', [$adapter->getRegistrarName()]);
            }

            return [
                'success' => true,
                'message' => $message,
                'domains' => $domains,
                'account_name' => $account->getAccountName(),
                'is_domain_registrar' => $isDomainRegistrar,
            ];
        } catch (\Throwable $e) {
            w_log_error("获取远程域名列表失败: " . $e->getMessage(), [], 'domain_sync');
            return [
                'success' => false,
                'message' => __('获取失败：%{1}', [$e->getMessage()]),
                'domains' => [],
            ];
        }
    }

    /**
     * 解析模式：批量解析到本站（默认）| 保持各自 DNS
     */
    public const RESOLVE_MODE_BATCH_TO_LOCAL = 'batch_to_local';
    public const RESOLVE_MODE_KEEP_EACH_DNS = 'keep_each_dns';

    /**
     * 手动导入指定域名到本地
     *
     * @param int $accountId 账户ID
     * @param array $domainNames 要导入的域名列表
     * @param bool|string $autoResolveOrMode 是否自动解析到本地（兼容）或 resolve_mode: batch_to_local|keep_each_dns
     *   默认 batch_to_local：批量解析到本服务器公网 IP，否则不导入
     *   keep_each_dns：保持各域名当前 DNS，不入池自动解析任务
     * @return array{success: bool, message: string, added: int, skipped: int, pool_added?: int, auto_resolve_queued?: bool}
     */
    public function importDomains(int $accountId, array $domainNames, bool|string $autoResolveOrMode = self::RESOLVE_MODE_BATCH_TO_LOCAL): array
    {
        if ($domainNames === []) {
            return [
                'success' => false,
                'message' => __('请选择要导入的域名'),
                'added' => 0,
                'skipped' => 0,
            ];
        }

        $account = $this->loadAccount($accountId);
        if (!$account->getAccountId()) {
            return [
                'success' => false,
                'message' => __('账户不存在'),
                'added' => 0,
                'skipped' => 0,
            ];
        }

        try {
            $registrarCode = $account->getRegistrarCode();
            $adapter = $this->registrarResolver->getAdapter($registrarCode);
            if (!$adapter) {
                return [
                    'success' => false,
                    'message' => __('域名商适配器未注册'),
                    'added' => 0,
                    'skipped' => 0,
                ];
            }

            // 对于 DNS 服务商，阻止导入以避免域名重复
            if (!$adapter->isDomainRegistrar()) {
                return [
                    'success' => false,
                    'message' => __('「%{1}」是 DNS 服务商而非域名注册商，无法导入域名。域名归属应以实际注册商（如 GName、阿里云）为准，DNS 服务商只用于管理 DNS 解析。', [$adapter->getRegistrarName()]),
                    'added' => 0,
                    'skipped' => \count($domainNames),
                ];
            }

            $credentials = $account->getCredentials();
            $remoteDomains = $adapter->getDomainList($credentials);

            // 过滤出要导入的域名
            $domainsToImport = [];
            foreach ($remoteDomains as $rd) {
                $domainName = (string) ($rd['domain'] ?? '');
                if ($domainName === '' || !\in_array($domainName, $domainNames, true)) {
                    continue;
                }

                $nameservers = [];
                if (!empty($rd['nameservers'])) {
                    $nameservers = \is_array($rd['nameservers']) ? $rd['nameservers'] : \explode(',', $rd['nameservers']);
                }

                $domainsToImport[] = [
                    'domain' => $domainName,
                    'status' => (string) ($rd['status'] ?? Domain::STATUS_ACTIVE),
                    'registrar_status' => (string) ($rd['registrar_status'] ?? $rd['status'] ?? ''),
                    'expires_at' => (string) ($rd['expires_at'] ?? ''),
                    'nameservers' => $nameservers,
                    'extra_data' => $rd,
                ];
            }

            if ($domainsToImport === []) {
                return [
                    'success' => false,
                    'message' => __('未在远程找到要导入的域名'),
                    'added' => 0,
                    'skipped' => 0,
                ];
            }

            $domainModelInstance = ObjectManager::getInstance(Domain::class);
            $result = $domainModelInstance->syncDomains($accountId, $domainsToImport);

            $resolveMode = \is_bool($autoResolveOrMode)
                ? ($autoResolveOrMode ? self::RESOLVE_MODE_BATCH_TO_LOCAL : self::RESOLVE_MODE_KEEP_EACH_DNS)
                : (string) $autoResolveOrMode;
            $doAutoResolve = $resolveMode === self::RESOLVE_MODE_BATCH_TO_LOCAL;

            $importedDomains = [];
            $poolAdded = 0;
            $autoResolveQueued = 0;
            foreach ($domainNames as $domainName) {
                $dm = ObjectManager::getInstance(Domain::class);
                $dm->loadByDomainAndAccount($domainName, $accountId);
                if ($dm->getDomainId()) {
                    $importedDomains[$domainName] = (int) $dm->getDomainId();

                    $subdomainGenerator = ObjectManager::getInstance(SubdomainGeneratorService::class);
                    $poolResult = $subdomainGenerator->generateDefaultSubdomains($dm);
                    $poolAdded += $poolResult['added'] ?? 0;

                    if ($doAutoResolve) {
                        try {
                            DomainAutoResolveTask::createTask($domainName, $accountId);
                            $autoResolveQueued++;
                        } catch (\Throwable $e) {
                            w_log_warning("创建自动解析任务失败: {$domainName}, " . $e->getMessage(), [], 'domain_sync');
                        }
                    }
                }
            }

            return [
                'success' => true,
                'message' => __('导入完成：新增 %{1}，已存在 %{2}，入池 %{3}', [
                    $result['added'],
                    $result['updated'],
                    $poolAdded,
                ]),
                'added' => $result['added'],
                'skipped' => $result['updated'],
                'pool_added' => $poolAdded,
                'domains' => $importedDomains,
                'resolve_mode' => $resolveMode,
                'auto_resolve_queued' => $doAutoResolve && $autoResolveQueued > 0,
            ];
        } catch (\Throwable $e) {
            w_log_error("导入域名失败: " . $e->getMessage(), [], 'domain_sync');
            return [
                'success' => false,
                'message' => __('导入失败：%{1}', [$e->getMessage()]),
                'added' => 0,
                'skipped' => 0,
            ];
        }
    }
}
