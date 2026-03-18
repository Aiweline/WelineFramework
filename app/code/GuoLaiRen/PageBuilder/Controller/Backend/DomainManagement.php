<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Service\QuickBuildAggregator;
use Weline\Admin\Controller\BaseController;
use Weline\Backend\Model\Config as BackendConfig;
use Weline\Cron\Schedule\Schedule;
use Weline\Framework\App\Env;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Service\DomainPoolResolveService;
use Weline\Websites\Service\DomainRegistrarResolverService;
use Weline\Websites\Service\ServerIpService;
use Weline\Websites\Service\SubdomainGeneratorService;

/**
 * @DESC | PageBuilder 域名管理控制器 - 一站式域名管理（账户管理 + 域名列表 + 批量购买）
 */
#[Acl('GuoLaiRen_PageBuilder::domain_management', '域名管理', 'mdi-dns', '域名管理', 'GuoLaiRen_PageBuilder::website_management')]
class DomainManagement extends BaseController
{
    private const CRON_MODULE = 'Weline_Cron';

    private QuickBuildAggregator $aggregator;
    private Schedule $schedule;
    private BackendConfig $backendConfig;

    public function __construct(
        QuickBuildAggregator $aggregator,
        Schedule $schedule,
        BackendConfig $backendConfig
    ) {
        $this->aggregator = $aggregator;
        $this->schedule = $schedule;
        $this->backendConfig = $backendConfig;
    }

    #[Acl('GuoLaiRen_PageBuilder::domain_management_index', '域名管理首页', 'mdi-dns', '查看域名管理')]
    public function index(): string
    {
        $accounts = $this->aggregator->queryRegistrarAccounts([]);
        $registrars = $this->aggregator->queryRegistrars();

        $activeAccounts = $this->aggregator->getActiveRegistrarAccounts();

        $lastSyncTime = $this->aggregator->getDomainLastSyncTime();

        $statusOptions = $this->aggregator->getDomainStatusOptions();

        $cronInstalled = $this->isCronInstalled();

        $this->assign('title', __('域名管理'));
        $this->assign('accounts', $accounts);
        $this->assign('registrars', $registrars);
        $this->assign('activeAccounts', $activeAccounts);
        $this->assign('lastSyncTime', $lastSyncTime);
        $this->assign('statusOptions', $statusOptions);
        $this->assign('cronInstalled', $cronInstalled);

        return $this->fetch();
    }

    /**
     * 检测系统定时任务是否已安装（用于域名池解析状态自动检测）
     * 主检测：schedule->exist() 通过 crontab/schtasks 查询
     * 回退：当 Web 进程用户与安装用户不同时（如 root 安装、www-data 运行），exist() 可能误报未安装，
     *      此时检查 generated 目录下是否已有 cron 脚本文件（安装成功时会创建）
     */
    private function isCronInstalled(): bool
    {
        try {
            $cronName = (string) ($this->backendConfig->getConfig(Schedule::cron_config_key, self::CRON_MODULE) ?? '');
            if ($cronName === '') {
                $cronName = Schedule::cron_flag . '-' . \md5(self::CRON_MODULE) . '-' . Schedule::cron_flag;
            }
            if ($this->schedule->exist($cronName)) {
                return true;
            }
            // 回退：检查 generated 目录下 cron 脚本是否存在
            $suffix = (\defined('IS_WIN') && IS_WIN) ? '-cron.vbs' : '-cron.sh';
            $scriptPath = Env::path_framework_generated . $cronName . $suffix;
            return \is_file($scriptPath);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * AJAX: 获取可用域名商类型列表
     */
    public function postGetRegistrars(): string
    {
        try {
            $registrars = $this->aggregator->queryRegistrars();
            return $this->fetchJson(['success' => true, 'data' => $registrars]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: 获取域名商适配器的配置字段
     */
    public function postGetConfigFields(): string
    {
        $registrarCode = trim($this->request->getParam('registrar_code', '') ?? '');
        if ($registrarCode === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('缺少域名商代码')]);
        }

        try {
            $fields = $this->aggregator->queryRegistrarConfigFields($registrarCode);
            return $this->fetchJson(['success' => true, 'data' => $fields]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: 获取域名商完整信息（配置字段 + 帮助说明 + 默认值）
     */
    public function postGetRegistrarInfo(): string
    {
        $registrarCode = trim($this->request->getParam('registrar_code', '') ?? '');
        if ($registrarCode === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('缺少域名商代码')]);
        }

        try {
            $info = $this->aggregator->queryRegistrarInfo($registrarCode);
            return $this->fetchJson(['success' => true, 'data' => $info]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: 保存域名商账号
     */
    public function postSaveAccount(): string
    {
        $accountId = (int) $this->request->getParam('account_id', 0);
        $registrarCode = trim($this->request->getParam('registrar_code', '') ?? '');
        $accountName = trim($this->request->getParam('account_name', '') ?? '');
        $apiKey = trim($this->request->getParam('api_key', '') ?? '');
        $apiSecret = trim($this->request->getParam('api_secret', '') ?? '');
        $region = trim($this->request->getParam('region', '') ?? '');
        $status = trim($this->request->getParam('status', 'active') ?? '');

        $extraFields = $this->request->getParam('extra_config', []);
        if (\is_string($extraFields)) {
            $extraFields = json_decode($extraFields, true) ?: [];
        }

        if ($registrarCode === '' || $accountName === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('域名商类型和账号名称不能为空')]);
        }

        // 根据适配器配置校验必填字段
        $resolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
        $adapter = $resolver->getAdapter($registrarCode);
        if ($adapter !== null) {
            $configFields = $adapter->getConfigFields();
            $missingFields = [];
            $isEditMode = $accountId > 0;
            
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
                
                $value = '';
                
                // 根据 mapping 获取对应值
                if ($mapping === 'api_key') {
                    $value = $apiKey;
                } elseif ($mapping === 'api_secret') {
                    $value = $apiSecret;
                } elseif ($mapping === 'region') {
                    $value = $region;
                } elseif (\str_starts_with($mapping, 'extra_config.')) {
                    $extraKey = \str_replace('extra_config.', '', $mapping);
                    $value = trim((string) ($extraFields[$extraKey] ?? ''));
                } else {
                    $value = trim((string) ($extraFields[$fieldName] ?? ''));
                }
                
                if ($value === '') {
                    $missingFields[] = $field['label'] ?? $fieldName;
                }
            }
            
            if (!empty($missingFields)) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('请填写必填字段：%{1}', [\implode(', ', $missingFields)])
                ]);
            }
        }

        $data = [
            'account_id' => $accountId,
            'registrar_code' => $registrarCode,
            'account_name' => $accountName,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'region' => $region,
            'extra_config' => $extraFields,
            'status' => $status,
        ];

        try {
            $result = $this->aggregator->saveRegistrarAccount($data);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('保存失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 删除域名商账号
     */
    public function postDeleteAccount(): string
    {
        $accountId = (int) $this->request->getParam('account_id', 0);
        if ($accountId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('账号 ID 无效')]);
        }

        try {
            $result = $this->aggregator->deleteRegistrarAccount($accountId);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('删除失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 测试域名商连接
     */
    public function postTestConnection(): string
    {
        $accountId = (int) $this->request->getParam('account_id', 0);
        if ($accountId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('账号 ID 无效')]);
        }

        try {
            $result = $this->aggregator->testRegistrarConnection($accountId);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: 查询域名列表（远程 + 本地合并，标记已拉取状态）
     */
    public function postGetDomains(): string
    {
        $accountId = (int) $this->request->getParam('account_id', 0);
        $status = \trim($this->request->getParam('status', '') ?? '');
        $search = \trim($this->request->getParam('search', '') ?? '');
        $page = \max(1, (int) $this->request->getParam('page', 1));
        $limit = \max(1, \min(500, (int) $this->request->getParam('limit', 100)));

        try {
            // 获取要查询的账户列表
            $activeAccounts = $this->aggregator->getActiveRegistrarAccounts();
            $accountMap = [];
            foreach ($activeAccounts as $acct) {
                $accountMap[(int) ($acct['account_id'] ?? 0)] = $acct['account_name'] ?? '';
            }

            $accountIds = [];
            if ($accountId > 0) {
                $accountIds = [$accountId];
            } else {
                $accountIds = \array_keys($accountMap);
            }

            if ($accountIds === []) {
                return $this->fetchJson([
                    'success' => true,
                    'data' => [
                        'groups' => [],
                        'items' => [],
                        'total' => 0,
                        'pages' => 0,
                        'page' => 1,
                        'accounts' => $accountMap,
                    ],
                ]);
            }

            // 获取本地已拉取的域名（所有账户或指定账户）- 分页获取全部
            $localDomains = [];
            $filters = $accountId > 0 ? ['account_id' => $accountId] : [];
            $localPage = 1;
            do {
                $localResult = $this->aggregator->getLocalDomains($filters, $localPage, 500);
                foreach ($localResult['items'] ?? [] as $item) {
                    $localDomains[$item['domain'] ?? ''] = $item;
                }
                $localPage++;
            } while ($localPage <= ($localResult['pages'] ?? 1));

            // 按账户分组获取远程域名
            $groups = [];
            $allItems = [];

            // DNS 服务商检测器
            $dnsDetector = ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class);
            $resolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);

            // 获取所有账户注册商信息（直接SQL，避免 ORM JOIN 字段冲突）
            $accountInfoCache = [];
            foreach ($accountIds as $aId) {
                $aName = $accountMap[$aId] ?? ('Account #' . $aId);
                // 通过 adapter 获取注册商信息
                try {
                    $accObj = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                    $accObj->load($aId);
                    $registrarCode = $accObj->getRegistrarCode() ?: '';
                    $registrarName = '';
                    $isDomainRegistrar = false;
                    if ($registrarCode) {
                        $adapter = $resolver->getAdapter($registrarCode);
                        if ($adapter) {
                            $registrarName = $adapter->getRegistrarName();
                            $isDomainRegistrar = $adapter->isDomainRegistrar();
                        }
                    }
                    $accountInfoCache[$aId] = [
                        'registrar_code' => $registrarCode,
                        'registrar_name' => $registrarName ?: $aName,
                        'is_domain_registrar' => $isDomainRegistrar,
                    ];
                } catch (\Throwable $e) {
                    $accountInfoCache[$aId] = [
                        'registrar_code' => '',
                        'registrar_name' => $aName,
                        'is_domain_registrar' => true,
                    ];
                }
            }

            // 全局域名去重：根域只能属于一个注册商账户
            $globalDomainOwner = [];
            foreach ($localDomains as $domainName => $localData) {
                $ownerAccountId = (int) ($localData['account_id'] ?? 0);
                if ($ownerAccountId > 0) {
                    $globalDomainOwner[$domainName] = $ownerAccountId;
                }
            }

            // 已建站 / 可建站：根域下池子域名状态
            $localDomainIds = array_filter(array_unique(array_column($localDomains, 'domain_id')));
            $parentIdsWithSiteCreated = [];
            $parentIdsWithSiteReady = [];
            if ($localDomainIds !== []) {
                $poolModel = ObjectManager::getInstance(DomainPool::class);
                $poolModel->clearQuery()
                    ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $localDomainIds, 'IN')
                    ->where(DomainPool::schema_fields_SITE_CREATED, 1);
                $poolRows = $poolModel->fields(DomainPool::schema_fields_PARENT_DOMAIN_ID)->select()->fetchArray();
                $parentIdsWithSiteCreated = array_unique(array_column($poolRows, DomainPool::schema_fields_PARENT_DOMAIN_ID));

                $poolModel->clearQuery()
                    ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $localDomainIds, 'IN')
                    ->where(DomainPool::schema_fields_SITE_READY, 1);
                $poolRowsReady = $poolModel->fields(DomainPool::schema_fields_PARENT_DOMAIN_ID)->select()->fetchArray();
                $parentIdsWithSiteReady = array_unique(array_column($poolRowsReady, DomainPool::schema_fields_PARENT_DOMAIN_ID));
            }

            foreach ($accountIds as $acctId) {
                $accountName = $accountMap[$acctId] ?? ('Account #' . $acctId);
                $acctInfo = $accountInfoCache[$acctId] ?? ['registrar_code' => '', 'registrar_name' => $accountName, 'is_domain_registrar' => true];

                // DNS 服务商（如 Cloudflare）不是注册商，不显示域名列表
                if (!$acctInfo['is_domain_registrar']) {
                    $groups[] = [
                        'account_id' => $acctId,
                        'account_name' => $accountName,
                        'registrar_name' => $acctInfo['registrar_name'],
                        'registrar_code' => $acctInfo['registrar_code'],
                        'is_dns_only' => true,
                        'total' => 0,
                        'pulled' => 0,
                        'not_pulled' => 0,
                        'items' => [],
                    ];
                    continue;
                }

                $groupItems = [];
                $fetchError = '';

                try {
                    $remoteResult = $this->aggregator->getRemoteDomains($acctId);
                    $remoteDomains = $remoteResult['domains'] ?? [];

                    if (($remoteResult['success'] ?? true) === false) {
                        $fetchError = (string) ($remoteResult['message'] ?? __('获取域名列表失败'));
                    }

                    foreach ($remoteDomains as $rd) {
                        $domainName = $rd['domain'] ?? '';
                        if ($domainName === '') {
                            continue;
                        }

                        // 域名去重：如果域名已属于其他注册商账户，跳过
                        if (isset($globalDomainOwner[$domainName]) && $globalDomainOwner[$domainName] !== $acctId) {
                            continue;
                        }
                        if (!isset($globalDomainOwner[$domainName])) {
                            $globalDomainOwner[$domainName] = $acctId;
                        }

                        $isLocal = isset($localDomains[$domainName]);
                        $localData = $localDomains[$domainName] ?? [];

                        // 搜索过滤
                        if ($search !== '' && \stripos($domainName, $search) === false) {
                            continue;
                        }

                        // 状态过滤
                        if ($status !== '') {
                            if ($status === 'pulled' && !$isLocal) {
                                continue;
                            }
                            if ($status === 'not_pulled' && $isLocal) {
                                continue;
                            }
                            if ($status !== 'pulled' && $status !== 'not_pulled') {
                                $itemStatus = $isLocal ? ($localData['status'] ?? '') : ($rd['status'] ?? '');
                                if ($itemStatus !== $status) {
                                    continue;
                                }
                            }
                        }

                        // DNS 服务商：优先本地数据，其次从远程 nameservers 检测；本地为空时用注册商表示「跟随注册商」
                        $dnsProvider = '';
                        $cdnProvider = '';

                        if ($isLocal) {
                            $dnsProvider = $localData['dns_provider'] ?? '';
                            $cdnProvider = $localData['cdn_provider'] ?? '';
                            // 本地已拉取但未单独配置 DNS/CDN 的，显示注册商表示跟随注册商
                            if ($dnsProvider === '' || $dnsProvider === null) {
                                $dnsProvider = $acctInfo['registrar_code'] ?? '';
                            }
                            if ($cdnProvider === '' || $cdnProvider === null) {
                                $cdnProvider = $acctInfo['registrar_code'] ?? '';
                            }
                        } elseif (!empty($rd['nameservers'])) {
                            // 远程域名带有 nameservers，实时检测 DNS 服务商
                            $dnsProvider = $dnsDetector->detectProvider($rd['nameservers']);
                            if ($dnsProvider && $dnsProvider !== 'unknown') {
                                // 检测是否为 CDN 服务商
                                if ($dnsDetector->isCdnProvider($dnsProvider)) {
                                    $cdnProvider = $dnsProvider;
                                }
                            } else {
                                $dnsProvider = '';
                            }
                        }

                        $dnsProviderName = $dnsProvider ? $dnsDetector->getProviderInfo($dnsProvider)['name'] : '-';
                        $cdnProviderName = $cdnProvider ? $dnsDetector->getProviderInfo($cdnProvider)['name'] : '-';

                        $domainId = $isLocal ? (int) ($localData['domain_id'] ?? 0) : 0;
                        // 根域可建站：自身 site_ready 或 至少一个池子域名可建站
                        $siteReady = 0;
                        if ($isLocal && $domainId > 0) {
                            $siteReady = (int) ($localData['site_ready'] ?? 0) ?: (\in_array($domainId, $parentIdsWithSiteReady, true) ? 1 : 0);
                        }
                        $item = [
                            'domain' => $domainName,
                            'domain_id' => $domainId,
                            'status' => $isLocal ? ($localData['status'] ?? 'active') : ($rd['status'] ?? 'active'),
                            'expires_at' => $rd['expires_at'] ?? ($localData['expires_at'] ?? ''),
                            'synced_at' => $localData['synced_at'] ?? '',
                            'is_pulled' => $isLocal,
                            'account_id' => $acctId,
                            'registrar_name' => $acctInfo['registrar_name'],
                            'registrar_code' => $acctInfo['registrar_code'],
                            'dns_provider' => $dnsProvider,
                            'dns_provider_name' => $dnsProviderName,
                            'cdn_provider' => $cdnProvider,
                            'cdn_provider_name' => $cdnProviderName,
                            'dns_account_id' => $isLocal ? (int) ($localData['dns_account_id'] ?? 0) : 0,
                            'cdn_account_id' => $isLocal ? (int) ($localData['cdn_account_id'] ?? 0) : 0,
                            'site_ready' => $siteReady,
                            'site_created' => ($isLocal && $domainId > 0 && \in_array($domainId, $parentIdsWithSiteCreated, true)) ? 1 : 0,
                        ];
                        $groupItems[] = $item;
                        $allItems[] = $item;
                    }
                } catch (\Throwable $e) {
                    $fetchError = $e->getMessage();
                }

                // 排序：未拉取在前
                \usort($groupItems, function ($a, $b) {
                    if ($a['is_pulled'] !== $b['is_pulled']) {
                        return $a['is_pulled'] ? 1 : -1;
                    }
                    return \strcmp($a['domain'], $b['domain']);
                });

                // 正在注册中的根域（未完成的生命周期订单）：用于前端禁用「切换 DNS」等操作并提示
                $registeringRoots = [];
                foreach ($groupItems as $i) {
                    $root = \trim((string) ($i['domain'] ?? ''));
                    if ($root === '' || isset($registeringRoots[$root])) {
                        continue;
                    }
                    try {
                        $lifecycle = w_query('websites', 'getDomainLifecycleStatus', ['domain' => $root]);
                        if (!empty($lifecycle['success']) && !empty($lifecycle['data']['order'])) {
                            $status = (string) ($lifecycle['data']['order']['status'] ?? '');
                            if ($status !== 'completed' && $status !== 'failed') {
                                $registeringRoots[$root] = true;
                            }
                        }
                    } catch (\Throwable) {
                        // Saas 未安装或查询失败，视为非注册中
                    }
                }
                foreach ($groupItems as &$it) {
                    $it['is_registering'] = !empty($registeringRoots[\trim((string) ($it['domain'] ?? ''))]);
                }
                unset($it);

                $pulledCount = \count(\array_filter($groupItems, fn($i) => $i['is_pulled']));
                $notPulledCount = \count($groupItems) - $pulledCount;

                $groups[] = [
                    'account_id' => $acctId,
                    'account_name' => $accountName,
                    'registrar_name' => $accountInfoCache[$acctId]['registrar_name'] ?? $accountName,
                    'registrar_code' => $accountInfoCache[$acctId]['registrar_code'] ?? '',
                    'total' => \count($groupItems),
                    'pulled' => $pulledCount,
                    'not_pulled' => $notPulledCount,
                    'items' => $groupItems,
                    'fetch_error' => $fetchError !== '' ? $fetchError : null,
                ];
            }

            // 分页（针对 allItems）
            $total = \count($allItems);
            $pages = (int) \ceil($total / $limit);

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'groups' => $groups,
                    'items' => $allItems,
                    'total' => $total,
                    'pages' => $pages,
                    'page' => $page,
                    'accounts' => $accountMap,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('查询失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 手动拉取选中的域名到本地
     * 参数支持 POST 与 JSON Body，避免 getParam 对 domains 数组做类型过滤导致丢失。
     */
    public function postPullDomains(): string
    {
        $accountId = (int) $this->request->getPost('account_id', 0);
        $domains = $this->request->getPost('domains');
        $autoResolve = $this->request->getPost('auto_resolve', '0') === '1' || $this->request->getPost('auto_resolve', '') === 1;
        $bindDns = (int) $this->request->getPost('bind_dns_account_id', 0);
        $bindCdn = (int) $this->request->getPost('bind_cdn_account_id', 0);

        if (\is_string($domains)) {
            $domains = \json_decode($domains, true) ?: [];
        }
        if (!\is_array($domains)) {
            $domains = [];
        }

        if ($domains === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择要拉取的域名')]);
        }

        try {
            // 如果指定了账户，直接使用
            if ($accountId > 0) {
                $result = $this->aggregator->importDomains($accountId, $domains, $autoResolve, $bindDns, $bindCdn);
                return $this->fetchJson($result);
            }

            // 未指定账户时，需要为每个域名找到对应的账户
            $activeAccounts = $this->aggregator->getActiveRegistrarAccounts();
            if ($activeAccounts === []) {
                return $this->fetchJson(['success' => false, 'msg' => __('没有可用的域名商账户')]);
            }

            // 构建域名到账户的映射
            $domainAccountMap = [];
            foreach ($activeAccounts as $acct) {
                $acctId = (int) ($acct['account_id'] ?? 0);
                if ($acctId <= 0) {
                    continue;
                }
                try {
                    $remoteResult = $this->aggregator->getRemoteDomains($acctId);
                    foreach ($remoteResult['domains'] ?? [] as $rd) {
                        $domainName = \strtolower($rd['domain'] ?? '');
                        if ($domainName !== '' && !isset($domainAccountMap[$domainName])) {
                            $domainAccountMap[$domainName] = $acctId;
                        }
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            // 按账户分组要导入的域名
            $accountDomains = [];
            $notFound = [];
            foreach ($domains as $domain) {
                $key = \strtolower(\trim($domain));
                if (isset($domainAccountMap[$key])) {
                    $acctId = $domainAccountMap[$key];
                    if (!isset($accountDomains[$acctId])) {
                        $accountDomains[$acctId] = [];
                    }
                    $accountDomains[$acctId][] = $domain;
                } else {
                    $notFound[] = $domain;
                }
            }

            // 批量导入
            $totalImported = 0;
            $autoResolveQueued = false;
            $errors = [];
            foreach ($accountDomains as $acctId => $acctDomains) {
                try {
                    $result = $this->aggregator->importDomains($acctId, $acctDomains, $autoResolve, $bindDns, $bindCdn);
                    if ($result['success'] ?? false) {
                        $totalImported += $result['imported'] ?? \count($acctDomains);
                        if ($result['auto_resolve_queued'] ?? false) {
                            $autoResolveQueued = true;
                        }
                    } else {
                        $errors[] = $result['message'] ?? '';
                    }
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }

            $msg = __('拉取完成：成功 %{1} 个', [$totalImported]);
            if (\count($notFound) > 0) {
                $msg .= __('，未找到 %{1} 个', [\count($notFound)]);
            }

            return $this->fetchJson([
                'success' => true,
                'message' => $msg,
                'imported' => $totalImported,
                'not_found' => $notFound,
                'auto_resolve_queued' => $autoResolveQueued,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => __('拉取失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 手动同步域名
     */
    public function postSyncDomains(): string
    {
        $accountId = (int) $this->request->getParam('account_id', 0);

        try {
            if ($accountId > 0) {
                $result = $this->aggregator->syncDomains($accountId);
            } else {
                $result = $this->aggregator->syncDomains();
            }
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => __('同步失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 批量操作域名
     */
    public function postBatchOperate(): string
    {
        $domainIds = $this->request->getParam('domain_ids', []);
        $operation = \trim($this->request->getParam('operation', '') ?? '');
        $params = $this->request->getParam('params', []);

        if (\is_string($domainIds)) {
            $domainIds = \json_decode($domainIds, true) ?: [];
        }
        if (\is_string($params)) {
            $params = \json_decode($params, true) ?: [];
        }

        $domainIds = \array_map('intval', \array_filter((array) $domainIds));

        if ($domainIds === [] || $operation === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数不完整')]);
        }

        try {
            $result = $this->aggregator->batchOperateDomains($domainIds, $operation, $params);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => __('操作失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 获取同步状态信息
     */
    public function postGetSyncStatus(): string
    {
        $accountId = (int) $this->request->getParam('account_id', 0);

        try {
            $lastSyncTime = $this->aggregator->getDomainLastSyncTime($accountId);

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'last_sync_time' => $lastSyncTime,
                    'cron_interval' => '15 分钟',
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 将 domains 输入统一为一行一个的字符串（兼容数组和字符串两种格式）
     */
    private function normalizeDomainsInput(mixed $input): string
    {
        if (\is_array($input)) {
            return trim(implode("\n", array_map(static fn($d): string => trim((string) $d), $input)));
        }
        return trim((string) $input);
    }

    /**
     * AJAX: 检查域名可用性
     * ParameterBag::getRequest 已自动回退 Body，FPM/WLS 均可正常读取。
     */
    public function postCheckAvailability(): string
    {
        $accountId = (int) ($this->request->getPost('account_id', 0) ?: $this->request->getPost('accountId', 0));
        $domainsRaw = $this->normalizeDomainsInput($this->request->getPost('domains', ''));

        if ($accountId <= 0 || $domainsRaw === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择域名商账号并输入域名')]);
        }

        $domains = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $domainsRaw))));
        if ($domains === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('域名列表为空')]);
        }

        try {
            $results = $this->aggregator->checkAvailability($accountId, $domains);
            return $this->fetchJson(['success' => true, 'data' => $results]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('检查失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 批量购买域名
     */
    public function postBatchPurchase(): string
    {
        $accountId = (int) $this->request->getParam('account_id', 0);
        $domainsRaw = $this->request->getParam('domains', '');
        $autoResolve = $this->request->getParam('auto_resolve', '0') === '1';
        $options = [
            'resolve_to_local' => (string) $this->request->getParam('resolve_to_local', $autoResolve ? 'yes' : 'no'),
            'subdomains' => $this->request->getParam('subdomains', '@,www'),
            'dns_choice' => (string) $this->request->getParam('dns_choice', 'follow_registrar'),
            'dns_provider' => (string) $this->request->getParam('dns_provider', ''),
            'dns_account_id' => (int) $this->request->getParam('dns_account_id', 0),
            'dns_nameservers' => (string) $this->request->getParam('dns_nameservers', ''),
            'cdn_choice' => (string) $this->request->getParam('cdn_choice', 'follow_registrar'),
            'cdn_provider' => (string) $this->request->getParam('cdn_provider', ''),
            'cdn_account_id' => (int) $this->request->getParam('cdn_account_id', 0),
            'start_lifecycle' => (string) $this->request->getParam('start_lifecycle', '1'),
        ];

        if ($accountId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择域名商账号')]);
        }

        $domainItems = \is_string($domainsRaw) ? json_decode($domainsRaw, true) : $domainsRaw;
        if (!\is_array($domainItems) || $domainItems === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('购买域名列表为空')]);
        }

        $items = [];
        foreach ($domainItems as $item) {
            $domain = trim((string) ($item['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }
            $items[] = [
                'domain' => $domain,
                'years' => max(1, (int) ($item['years'] ?? 1)),
                'website_id' => (int) ($item['website_id'] ?? 0) ?: null,
            ];
        }

        if ($items === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('无有效域名')]);
        }

        $cip = \trim((string) $this->request->getClientIp());
        if ($cip !== '' && \filter_var($cip, FILTER_VALIDATE_IP)) {
            $options['client_ip'] = $cip;
        }
        $pcRaw = $this->request->getParam('purchase_contact', '');
        if (\is_string($pcRaw) && $pcRaw !== '') {
            $decoded = \json_decode($pcRaw, true);
            if (\is_array($decoded)) {
                $options['purchase_contact'] = $decoded;
            }
        }

        try {
            $result = $this->aggregator->purchaseDomain($accountId, $items, $autoResolve, $options);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('购买失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 单域名购买
     */
    public function postPurchase(): string
    {
        $accountId = (int) $this->request->getParam('account_id', 0);
        $domain = trim($this->request->getParam('domain', '') ?? '');
        $years = (int) $this->request->getParam('years', 1);
        $autoResolve = $this->request->getParam('auto_resolve', '0') === '1';
        $options = [
            'resolve_to_local' => (string) $this->request->getParam('resolve_to_local', $autoResolve ? 'yes' : 'no'),
            'subdomains' => $this->request->getParam('subdomains', '@,www'),
            'dns_choice' => (string) $this->request->getParam('dns_choice', 'follow_registrar'),
            'dns_provider' => (string) $this->request->getParam('dns_provider', ''),
            'dns_account_id' => (int) $this->request->getParam('dns_account_id', 0),
            'dns_nameservers' => (string) $this->request->getParam('dns_nameservers', ''),
            'cdn_choice' => (string) $this->request->getParam('cdn_choice', 'follow_registrar'),
            'cdn_provider' => (string) $this->request->getParam('cdn_provider', ''),
            'cdn_account_id' => (int) $this->request->getParam('cdn_account_id', 0),
            'start_lifecycle' => (string) $this->request->getParam('start_lifecycle', '1'),
        ];

        if ($accountId <= 0 || $domain === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('参数不完整')]);
        }

        $cip = \trim((string) $this->request->getClientIp());
        if ($cip !== '' && \filter_var($cip, FILTER_VALIDATE_IP)) {
            $options['client_ip'] = $cip;
        }
        $pcRaw = $this->request->getParam('purchase_contact', '');
        if (\is_string($pcRaw) && $pcRaw !== '') {
            $decoded = \json_decode($pcRaw, true);
            if (\is_array($decoded)) {
                $options['purchase_contact'] = $decoded;
            }
        }

        try {
            $items = [['domain' => $domain, 'years' => max(1, $years)]];
            $result = $this->aggregator->purchaseDomain($accountId, $items, $autoResolve, $options);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('购买失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 查询根域生命周期状态
     */
    public function postGetLifecycleStatus(): string
    {
        $domain = \strtolower(\trim((string) $this->request->getParam('domain', '')));
        if ($domain === '') {
            return $this->fetchJson(['success' => false, 'message' => __('请输入根域名')]);
        }

        try {
            $result = $this->aggregator->getDomainLifecycleStatus($domain);
            if (($result['success'] ?? false) && !empty($result['data']['order']['order_id'])) {
                $orderId = (int) ($result['data']['order']['order_id'] ?? 0);
                if ($orderId > 0) {
                    $this->aggregator->processLifecycleOrder($orderId);
                    $result = $this->aggregator->getDomainLifecycleStatus($domain);
                }
            }

            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('查询失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 补建已购买域名的生命周期订单
     */
    public function postRepairLifecycleOrder(): string
    {
        $domain = \strtolower(\trim((string) $this->request->getParam('domain', '')));
        $accountId = (int) $this->request->getParam('account_id', 0);
        if ($domain === '') {
            return $this->fetchJson(['success' => false, 'message' => __('请输入根域名')]);
        }
        if ($accountId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('请选择购买时使用的域名商账号')]);
        }

        try {
            $result = $this->aggregator->repairLifecycleOrder($domain, $accountId);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('补建失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    // ============================================================
    // v1.6.0: 域名池相关 API
    // ============================================================
    
    /**
     * AJAX: 获取域名池列表（可建站的域名）
     */
    public function postGetDomainPool(): string
    {
        $siteReadyOnly = $this->request->getParam('site_ready_only', 'false') === 'true';
        $parentDomainId = (int) $this->request->getParam('parent_domain_id', 0);
        $search = \trim($this->request->getParam('search', '') ?? '');
        $resolveFilter = \trim($this->request->getParam('resolve_status', '') ?? '');
        $page = \max(1, (int) $this->request->getParam('page', 1));
        $limit = \max(1, \min(100, (int) $this->request->getParam('limit', 50)));
        
        try {
            $model = ObjectManager::getInstance(DomainPool::class);
            $model->clearQuery()->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE);
            
            if ($siteReadyOnly) {
                $model->where(DomainPool::schema_fields_SITE_READY, 1);
                $model->where(DomainPool::schema_fields_SITE_CREATED, 0);
            }
            
            if ($parentDomainId > 0) {
                $model->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $parentDomainId);
            }
            
            if ($resolveFilter === 'resolved') {
                $model->where(DomainPool::schema_fields_RESOLVE_STATUS, DomainPool::RESOLVE_STATUS_RESOLVED);
            } elseif ($resolveFilter === 'unresolved') {
                $model->where(DomainPool::schema_fields_RESOLVE_STATUS, [DomainPool::RESOLVE_STATUS_PENDING, DomainPool::RESOLVE_STATUS_ERROR], 'IN');
            }
            
            if ($search !== '') {
                $model->where(DomainPool::schema_fields_DOMAIN, '%' . $search . '%', 'LIKE');
            }
            
            $model->order(DomainPool::schema_fields_ROOT_DOMAIN, 'ASC')
                ->order(DomainPool::schema_fields_DOMAIN, 'ASC');

            $model->pagination($page, $limit);
            $domains = $model->select()->fetchArray();
            $pagination = $model->pagination ?? [];

            // 正在注册中的根域（有未完成的生命周期订单）：这些域名的操作按钮需置灰并提示
            // 若根域已在域名表（已拉取/已经注册），则不视为「正在注册中」，允许子域进行 DNS 检查
            $rootDomains = array_unique(array_filter(array_column($domains, DomainPool::schema_fields_ROOT_DOMAIN)));
            $rootsInDomainTable = [];
            if ($rootDomains !== []) {
                $domainModel = ObjectManager::getInstance(Domain::class);
                $domainRows = $domainModel->clearQuery()
                    ->fields(Domain::schema_fields_DOMAIN)
                    ->select()
                    ->fetchArray();
                foreach ($domainRows as $row) {
                    $d = \strtolower(\trim((string) ($row[Domain::schema_fields_DOMAIN] ?? '')));
                    if ($d !== '') {
                        $rootsInDomainTable[$d] = true;
                    }
                }
            }
            $registeringRoots = [];
            $lifecycleStages = [];
            foreach ($rootDomains as $root) {
                $root = (string) $root;
                if ($root === '') {
                    continue;
                }
                $rootLower = \strtolower(\trim($root));
                if (!empty($rootsInDomainTable[$rootLower])) {
                    continue;
                }
                try {
                    $lifecycle = w_query('websites', 'getDomainLifecycleStatus', ['domain' => $root]);
                    if (!empty($lifecycle['success']) && !empty($lifecycle['data']['order'])) {
                        $orderData = $lifecycle['data']['order'];
                        $status = (string) ($orderData['status'] ?? '');
                        if ($status !== 'completed' && $status !== 'failed') {
                            $registeringRoots[$root] = true;
                        }
                        $lifecycleStages[$rootLower] = [
                            'lifecycle_stage' => (string) ($orderData['lifecycle_stage'] ?? ''),
                            'lifecycle_stage_label' => (string) ($orderData['lifecycle_stage_label'] ?? ''),
                        ];
                    }
                } catch (\Throwable) {
                    // Saas 未安装或查询失败，视为非注册中
                }
            }

            // 补充服务商名称（注册商、DNS、CDN）
            $parentIds = array_unique(array_filter(array_column($domains, DomainPool::schema_fields_PARENT_DOMAIN_ID)));
            $parentMap = [];
            $accountCache = [];
            if ($parentIds !== []) {
                $domainModel = ObjectManager::getInstance(Domain::class);
                $parentRows = $domainModel->clearQuery()
                    ->where(Domain::schema_fields_ID, $parentIds, 'IN')
                    ->select()->fetchArray();
                foreach ($parentRows as $r) {
                    $parentMap[(int) ($r[Domain::schema_fields_ID] ?? 0)] = $r;
                }
                $resolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
                $dnsDetector = ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class);
                foreach ($parentRows as $r) {
                    $accId = (int) ($r[Domain::schema_fields_ACCOUNT_ID] ?? 0);
                    if ($accId > 0 && !isset($accountCache[$accId])) {
                        $acc = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                        $acc->load($accId);
                        $code = $acc->getRegistrarCode() ?: '';
                        $name = $code ? ($resolver->getAdapter($code)?->getRegistrarName() ?? $code) : '-';
                        $accountCache[$accId] = ['registrar_name' => $name ?: '-'];
                    }
                }
            }
            
            // 格式化数据
            $data = [];
            foreach ($domains as $domain) {
                $d = $domain[DomainPool::schema_fields_DOMAIN] ?? '';
                $root = $domain[DomainPool::schema_fields_ROOT_DOMAIN] ?? '';
                $pid = (int) ($domain[DomainPool::schema_fields_PARENT_DOMAIN_ID] ?? 0);
                $parent = $parentMap[$pid] ?? null;
                $registrarName = '-';
                $dnsProviderName = '-';
                $cdnProviderName = '-';
                if ($parent) {
                    $accId = (int) ($parent[Domain::schema_fields_ACCOUNT_ID] ?? 0);
                    $registrarName = $accountCache[$accId]['registrar_name'] ?? '-';
                    $dnsCode = $parent[Domain::schema_fields_DNS_PROVIDER] ?? $domain[DomainPool::schema_fields_DNS_PROVIDER] ?? '';
                    $cdnCode = $parent[Domain::schema_fields_CDN_PROVIDER] ?? '';
                    if ($dnsCode && isset($dnsDetector)) {
                        $info = $dnsDetector->getProviderInfo($dnsCode);
                        $dnsProviderName = $info['name'] ?? $dnsCode;
                    }
                    if ($cdnCode && isset($dnsDetector)) {
                        $info = $dnsDetector->getProviderInfo($cdnCode);
                        $cdnProviderName = $info['name'] ?? $cdnCode;
                    }
                }
                $data[] = [
                    'pool_id' => $domain[DomainPool::schema_fields_ID] ?? 0,
                    'domain' => $d,
                    'full_domain' => $d,
                    'root_domain' => $root,
                    'subdomain' => $d !== '' && $root !== '' && $d !== $root,
                    'status' => $domain[DomainPool::schema_fields_STATUS] ?? 'active',
                    'resolve_status' => $domain[DomainPool::schema_fields_RESOLVE_STATUS] ?? 'pending',
                    'resolved_ip' => $domain[DomainPool::schema_fields_RESOLVED_IP] ?? '',
                    'resolved_ipv6' => $domain[DomainPool::schema_fields_RESOLVED_IPV6] ?? '',
                    'resolve_checked_at' => $domain[DomainPool::schema_fields_RESOLVE_CHECKED_AT] ?? '',
                    'is_local_server' => (int) ($domain[DomainPool::schema_fields_IS_LOCAL_SERVER] ?? 0),
                    'https_status' => $domain[DomainPool::schema_fields_HTTPS_STATUS] ?? 'none',
                    'https_expires_at' => $domain[DomainPool::schema_fields_HTTPS_EXPIRES_AT] ?? '',
                    'site_ready' => (int) ($domain[DomainPool::schema_fields_SITE_READY] ?? 0),
                    'site_created' => (int) ($domain[DomainPool::schema_fields_SITE_CREATED] ?? 0),
                    'description' => $domain[DomainPool::schema_fields_DESCRIPTION] ?? '',
                    'allocated_at' => $domain[DomainPool::schema_fields_CREATED_AT] ?? $domain[DomainPool::schema_fields_UPDATED_AT] ?? '',
                    'registrar_name' => $registrarName,
                    'dns_provider_name' => $dnsProviderName,
                    'cdn_provider_name' => $cdnProviderName,
                    'is_registering' => !empty($registeringRoots[$root]),
                    'lifecycle_stage' => $lifecycleStages[\strtolower(\trim((string) $root))]['lifecycle_stage'] ?? '',
                    'lifecycle_stage_label' => $lifecycleStages[\strtolower(\trim((string) $root))]['lifecycle_stage_label'] ?? '',
                ];
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $data,
                'page' => (int) ($pagination['page'] ?? $page),
                'limit' => (int) ($pagination['pageSize'] ?? $limit),
                'total' => (int) ($pagination['totalSize'] ?? count($data)),
                'pages' => (int) ($pagination['lastPage'] ?? 1),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('查询失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }
    
    /**
     * AJAX: 添加子域名到域名池
     */
    public function postAddSubdomain(): string
    {
        $parentDomainId = (int) $this->request->getParam('parent_domain_id', 0);
        $subdomain = \trim($this->request->getParam('subdomain', '') ?? '');
        $description = \trim($this->request->getParam('description', '') ?? '');
        
        if ($subdomain === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('子域名不能为空')]);
        }
        
        try {
            // 检查是否已存在
            $existing = ObjectManager::getInstance(DomainPool::class, [], false);
            $existing->clearQuery()
                ->where(DomainPool::schema_fields_DOMAIN, strtolower($subdomain))
                ->find()
                ->fetch();
            
            if ($existing->getPoolId()) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('域名 %{1} 已存在于域名池中', [$subdomain]),
                ]);
            }
            
            // 自动推断 root_domain
            $parts = explode('.', strtolower($subdomain));
            $rootDomain = count($parts) >= 2 
                ? implode('.', array_slice($parts, -2)) 
                : $subdomain;
            
            // 创建新记录
            $newPool = ObjectManager::getInstance(DomainPool::class, [], false);
            $newPool->setDomain(strtolower($subdomain));
            $newPool->setRootDomain($rootDomain);
            $newPool->setParentDomainId($parentDomainId);
            $newPool->setDescription($description);
            $newPool->setStatus(DomainPool::STATUS_ACTIVE);
            $newPool->setResolveStatus(DomainPool::RESOLVE_STATUS_PENDING);
            $newPool->setHttpsStatus(DomainPool::HTTPS_STATUS_NONE);
            $newPool->setSiteReady(false);
            $newPool->save();
            
            return $this->fetchJson([
                'success' => true,
                'msg' => __('子域名添加成功'),
                'data' => [
                    'pool_id' => $newPool->getPoolId(),
                    'domain' => $newPool->getDomain(),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('添加失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }
    
    /**
     * AJAX: 检测域名解析状态（支持 pool_id 或 domain_id）
     */
    public function postCheckResolve(): string
    {
        // getParam 会从 Body(POST/JSON) > $_POST > $_GET 依次读取，FormData 与 JSON 均可
        $poolId = (int) $this->request->getParam('pool_id', 0);
        $domainId = (int) $this->request->getParam('domain_id', 0);

        try {
            $resolveService = ObjectManager::getInstance(DomainPoolResolveService::class);

            if ($poolId > 0) {
                $pool = ObjectManager::getInstance(DomainPool::class, [], false);
                $pool->loadByPoolId($poolId);

                if (!$pool->getPoolId()) {
                    return $this->fetchJson([
                        'success' => false,
                        'msg' => __('域名池记录不存在'),
                        'data' => ['pool_id' => $poolId],
                    ]);
                }

                $result = $resolveService->checkResolve($pool);
                $resolved = $result['resolved'] ?? false;
                $result['pool_id'] = $poolId;
                return $this->fetchJson([
                    'success' => $resolved,
                    'msg' => $resolved ? __('解析正常') : __('解析异常'),
                    'data' => $result,
                ]);
            }

            if ($domainId > 0) {
                $domain = ObjectManager::getInstance(Domain::class, [], false);
                $domain->load($domainId);

                if (!$domain->getDomainId()) {
                    return $this->fetchJson(['success' => false, 'msg' => __('根域名不存在')]);
                }

                $domainName = $domain->getDomain();
                $results = [];
                $allResolved = true;

                $poolModel = ObjectManager::getInstance(DomainPool::class, [], false);
                $poolItems = $poolModel->clearQuery()
                    ->where(DomainPool::schema_fields_ROOT_DOMAIN, $domainName)
                    ->select()
                    ->fetchArray();

                foreach ($poolItems as $item) {
                    $pool = ObjectManager::getInstance(DomainPool::class, [], false);
                    $pool->loadByPoolId((int) $item[DomainPool::schema_fields_ID]);
                    if ($pool->getPoolId()) {
                        $checkResult = $resolveService->checkResolve($pool);
                        $results[$pool->getDomain()] = $checkResult;
                        if (!($checkResult['resolved'] ?? false)) {
                            $allResolved = false;
                        }
                    }
                }

                return $this->fetchJson([
                    'success' => $allResolved,
                    'msg' => $allResolved ? __('所有子域名解析正常') : __('部分子域名解析异常'),
                    'data' => [
                        'domain' => $domainName,
                        'all_resolved' => $allResolved,
                        'details' => $results,
                    ],
                ]);
            }

            return $this->fetchJson(['success' => false, 'msg' => __('请提供 pool_id 或 domain_id')]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('检测失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 删除域名池中的子域名
     */
    public function postDeletePoolDomain(): string
    {
        $poolId = (int) $this->request->getParam('pool_id', 0);
        if ($poolId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('请提供 pool_id')]);
        }
        try {
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            $pool->load($poolId);
            if (!$pool->getPoolId()) {
                return $this->fetchJson(['success' => false, 'msg' => __('域名池记录不存在')]);
            }
            $domainName = $pool->getDomain();
            $pool->delete()->fetch();
            return $this->fetchJson([
                'success' => true,
                'msg' => __('已删除：%{1}', [$domainName]),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('删除失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    private const HTTPS_PROVIDERS = ['letsencrypt', 'litessl'];

    /**
     * 获取 WLS 端口与是否可用 HTTP-01（用于证书申请提示）
     * 以 wls 为准：主端口 80 或 443 且启用了 80 重定向时，可用 HTTP-01。
     */
    public function getGetServerPort(): string
    {
        $config = Env::getInstance()->getConfig('wls');
        $port = 80;
        if (\is_array($config) && isset($config['port'])) {
            $portVal = $config['port'];
            $portVal = \is_array($portVal) ? ($portVal[0] ?? 80) : $portVal;
            $portInt = (int) $portVal;
            if ($portInt > 0) {
                $port = $portInt;
            }
        }
        $canUseHttp01 = $this->computeCanUseHttp01($config, $port);
        return $this->fetchJson(['success' => true, 'port' => $port, 'can_use_http01' => $canUseHttp01]);
    }

    /**
     * 是否可使用 HTTP-01：主端口 80 或（主端口 443 且启用了 80 重定向）
     */
    private function computeCanUseHttp01(?array $config, int $port): bool
    {
        if ($port === 80) {
            return true;
        }
        if (!\is_array($config)) {
            return false;
        }
        $redirectPort = (int) ($config['http_redirect_port'] ?? 80);
        $redirectEnabled = ($port === 443) && ($redirectPort > 0);
        return $redirectEnabled && $redirectPort === 80;
    }

    /**
     * SSE 流式输出证书申请过程
     * GET: pool_id, provider, domain, challenge_strategy
     */
    public function getRequestHttpsStream(): void
    {
        $poolId = (int) $this->request->getParam('pool_id', 0);
        $provider = (string) ($this->request->getParam('provider', 'letsencrypt') ?: 'letsencrypt');
        $domain = \trim((string) $this->request->getParam('domain', ''));

        $pool = ObjectManager::getInstance(DomainPool::class, [], false);
        if ($poolId > 0) {
            $pool->load($poolId);
            if ($pool->getPoolId()) {
                $domain = \trim((string) $pool->getDomain());
            }
        }

        $sse = new SseWriter();
        $sse->setRetryInterval(86400000);
        $sse->start();

        if ($poolId <= 0 || $domain === '') {
            $sse->sendEvent('failed', ['message' => __('参数无效')]);
            $sse->close();
            return;
        }

        $sse->sendEvent('start', ['message' => __('开始申请证书：%{1}', [$domain])]);
        $sse->sendEvent('info', ['message' => __('正在连接 ACME 服务器...')]);

        try {
            $webroot = \defined('PUB') ? PUB : (BP . 'pub');
            $email = (string) (Env::getInstance()->getConfig('ssl.contact_email') ?? '');
            if ($email === '') {
                $email = 'admin@' . $domain;
            }

            if (!$pool->getPoolId()) {
                $pool = ObjectManager::getInstance(DomainPool::class, [], false);
                $pool->load($poolId);
            }
            if (!$pool->getPoolId()) {
                $sse->sendEvent('failed', ['message' => __('域名池记录不存在')]);
                $sse->close();
                return;
            }
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
            $pool->setHttpsError('');
            $pool->save();

            $sse->sendEvent('progress', ['message' => __('正在验证域名...'), 'progress' => 30]);

            $onProgress = function (string $message, array $extra = []) use ($sse): void {
                $sse->sendEvent('progress', \array_merge(['message' => $message], $extra));
            };
            $challengeRaw = $this->request->getParam('challenge_strategy', 'auto');
            $challengeStrategy = \is_array($challengeRaw)
                ? \trim((string) ($challengeRaw[0] ?? 'auto'))
                : \trim((string) ($challengeRaw ?: 'auto'));
            if ($challengeStrategy === '' || $challengeStrategy === 'Array') {
                $challengeStrategy = 'auto';
            }
            if (!\in_array($challengeStrategy, ['http01', 'dns01', 'auto'], true)) {
                $challengeStrategy = 'auto';
            }
            $sse->sendEvent('progress', ['message' => __('使用验证方式：%{1}', [$challengeStrategy]), 'progress' => 31]);

            $domainId = (int) $pool->getParentDomainId();
            $result = w_query('server', 'requestCertificate', [
                'domain' => $domain,
                'webroot' => $webroot,
                'email' => $email,
                'website_id' => 0,
                'provider' => $provider,
                'cert_type' => 'exact',
                'pool_id' => $poolId,
                'domain_id' => $domainId > 0 ? $domainId : 0,
                'challenge_strategy' => $challengeStrategy,
                '_on_progress' => $onProgress,
            ]);

            if ($result['success'] ?? false) {
                $certId = (int) ($result['cert_id'] ?? 0);
                $certPath = (string) ($result['cert_path'] ?? '');
                if ($certPath !== '') {
                    $sse->sendEvent('progress', ['message' => __('证书存储位置：%{1}', [\dirname($certPath)]), 'cert_dir' => \dirname($certPath)]);
                }
                if ($certId > 0) {
                    $sse->sendEvent('progress', ['message' => __('证书管理记录已保存，cert_id=%{1}', [$certId]), 'cert_id' => $certId]);
                }

                $sse->sendEvent('progress', ['message' => __('正在更新域名池「%{1}」的 HTTPS 状态…', [$domain]), 'step' => 'update_pool']);
                $poolToUpdate = ObjectManager::getInstance(DomainPool::class, [], false)->loadByDomain($domain);
                if (!$poolToUpdate->getPoolId()) {
                    $poolToUpdate = $pool;
                }
                $poolToUpdate->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
                $poolToUpdate->setHttpsError('');
                if ($certId > 0) {
                    $poolToUpdate->setCertId($certId);
                }
                $cert = $result['cert'] ?? null;
                if ($cert !== null && \method_exists($cert, 'getExpiresAt')) {
                    $expiresAt = $cert->getExpiresAt();
                    if ($expiresAt !== '') {
                        $poolToUpdate->setHttpsExpiresAt($expiresAt);
                    }
                }
                $poolToUpdate->calculateSiteReady();
                $poolToUpdate->save();
                $sse->sendEvent('progress', ['message' => __('域名池 HTTPS 状态已更新为有效（pool_id=%{1}）', [$poolToUpdate->getPoolId()]), 'step' => 'pool_updated']);

                $sse->sendEvent('success', ['message' => __('证书申请成功')]);
                $sse->sendEvent('done', ['message' => __('申请完成：%{1}', [$domain]), 'success' => true]);
            } else {
                $msg = (string) ($result['message'] ?? __('未知错误'));
                $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
                $pool->setHttpsError($msg);
                $pool->save();
                $sse->sendEvent('failed', ['message' => $msg]);
            }
        } catch (\Throwable $e) {
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            if ($pool->load($poolId)->getPoolId()) {
                $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
                $pool->setHttpsError($e->getMessage());
                $pool->save();
            }
            $sse->sendEvent('failed', ['message' => $e->getMessage()]);
        }
        $sse->close();
    }

    /**
     * SSE 流式输出根域名 DNS/CDN 切换过程
     * GET: domain_id, dns_account_id, cdn_account_id (可选)
     * 委托 DnsSwitchService 统一执行，通过进度回调推送 SSE 事件。
     */
    public function getDnsSwitchStream(): void
    {
        $domainId = (int) $this->request->getParam('domain_id', 0);
        $dnsAccountId = (int) $this->request->getParam('dns_account_id', 0);
        $cdnAccountId = (int) $this->request->getParam('cdn_account_id', 0);

        $sse = new SseWriter();
        $sse->setRetryInterval(86400000);
        $sse->start();

        if ($domainId <= 0 || $dnsAccountId <= 0) {
            $sse->sendEvent('failed', ['message' => __('参数无效：domain_id 与 dns_account_id 必填')]);
            $sse->close();
            return;
        }

        $sse->sendEvent('start', ['message' => __('开始 DNS/CDN 切换流程')]);

        try {
            $domain = ObjectManager::getInstance(Domain::class, [], false);
            $domain->load($domainId);
            if (!$domain->getDomainId()) {
                $sse->sendEvent('failed', ['message' => __('域名不存在')]);
                $sse->close();
                return;
            }
            $domainName = $domain->getDomain();

            // 注册中的域名不执行切换，由定时任务在注册完成后自动处理
            try {
                $lifecycle = w_query('websites', 'getDomainLifecycleStatus', ['domain' => $domainName]);
                if (!empty($lifecycle['success']) && !empty($lifecycle['data']['order'])) {
                    $status = (string) ($lifecycle['data']['order']['status'] ?? '');
                    if ($status !== 'completed' && $status !== 'failed') {
                        $sse->sendEvent('failed', ['message' => __('域名注册完成后将自动处理 DNS/CDN，无需手动切换。')]);
                        $sse->close();
                        return;
                    }
                }
            } catch (\Throwable) {
                // Saas 未安装或查询失败，允许继续切换
            }

            $targetAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $targetAccount->load($dnsAccountId);
            if (!$targetAccount->getAccountId()) {
                $sse->sendEvent('failed', ['message' => __('目标 DNS 账户不存在')]);
                $sse->close();
                return;
            }

            $sse->sendEvent('info', ['message' => __('[DEBUG] 请求参数 dns_account_id=%{1}，load 后 getAccountId()=%{2}，registrar_id=%{3}', [
                (string) $dnsAccountId,
                (string) $targetAccount->getAccountId(),
                (string) $targetAccount->getRegistrarId(),
            ])]);
            $sse->sendEvent('info', ['message' => __('[DEBUG] getRegistrarCode()=%{1}', [(string) $targetAccount->getRegistrarCode()])]);
            $sse->sendEvent('info', ['message' => __('目标账户：%{1}（%{2}），账户 ID：%{3}', [
                (string) ($targetAccount->getData('account_name') ?: $targetAccount->getName() ?: ''),
                (string) ($targetAccount->getData('registrar_name') ?: $targetAccount->getRegistrarCode() ?: ''),
                (string) $dnsAccountId,
            ])]);

            $cdnAccount = null;
            if ($cdnAccountId > 0) {
                $cdnAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                $cdnAccount->load($cdnAccountId);
                if (!$cdnAccount->getAccountId()) {
                    $cdnAccount = null;
                }
            }

            $resolveService = ObjectManager::getInstance(\Weline\Websites\Service\DomainResolveService::class);
            $dnsSwitchService = ObjectManager::getInstance(\Weline\Websites\Service\DnsSwitchService::class);
            $dnsSwitchService->markPoolSwitching($domainName, (string) $targetAccount->getRegistrarCode());

            $onStep = function (string $event, array $data) use ($sse, $targetAccount): void {
                $msg = (string) ($data['message'] ?? '');
                if ($event === 'sync_records' || $event === 'sync_records_done') {
                    $sse->sendEvent('info', ['message' => $msg]);
                    return;
                }
                if ($event === 'add_zone') {
                    $sse->sendEvent('progress', ['message' => __('步骤 1/5：正在获取目标 Nameserver…'), 'step' => 1]);
                    return;
                }
                if ($event === 'add_zone_done') {
                    $sse->sendEvent('info', ['message' => $msg]);
                    return;
                }
                if ($event === 'switch_ns') {
                    $sse->sendEvent('progress', ['message' => __('步骤 1/5：正在向注册商提交新 Nameserver…'), 'step' => 1]);
                    return;
                }
                if ($event === 'switch_ns_done') {
                    $sse->sendEvent('progress', ['message' => __('Nameserver 已提交，等待全球解析生效（每 15 秒检测一次）…'), 'step' => 1]);
                    return;
                }
                if ($event === 'registrar_ns_updated' || $event === 'registrar_ns_current') {
                    $sse->sendEvent('info', ['message' => $msg]);
                    return;
                }
                if ($event === 'sync_records_result' || $event === 'registrar_ns_check' || $event === 'wait_ns_start' || $event === 'wait_ns_registrar_detail') {
                    $sse->sendEvent('info', ['message' => $msg]);
                    return;
                }
                if ($event === 'switch_ns_error') {
                    $sse->sendEvent('info', ['message' => __('[错误] %{1}', [$msg]), 'type' => 'error']);
                    return;
                }
                if ($event === 'wait_ns_progress') {
                    $elapsed = (int) ($data['elapsed'] ?? 0);
                    $live = $data['live'] ?? [];
                    $targetLabel = __('目标 NS：%{1}', [\implode(', ', $data['target'] ?? [])]);
                    $sse->sendEvent('progress', ['message' => __('第 %{1} 秒检测：当前 NS %{2}，%{3}', [
                        $elapsed,
                        $live === [] ? __('(暂无)') : \implode(', ', $live),
                        $targetLabel,
                    ]), 'step' => 2, 'elapsed' => $elapsed]);
                    return;
                }
                if ($event === 'wait_ns_verified') {
                    $by = (string) ($data['by'] ?? '');
                    $sse->sendEvent('progress', ['message' => $by === 'registrar'
                        ? __('注册商已更新为目标 NS，继续搬迁（全球解析可能尚未完全生效）')
                        : __('NS 已生效（公网解析已更新）'), 'step' => 2]);
                    return;
                }
                if ($event === 'push_records') {
                    $sse->sendEvent('progress', ['message' => __('步骤 3/5：正在搬迁 DNS 记录到新供应商…'), 'step' => 3]);
                    return;
                }
                if ($event === 'push_records_done') {
                    $added = (int) ($data['added'] ?? 0);
                    $failed = (int) ($data['failed'] ?? 0);
                    $sse->sendEvent('progress', ['message' => __('搬迁完成：成功 %{1} 条，失败 %{2} 条', [$added, $failed]), 'step' => 3]);
                    foreach (\array_slice($data['errors'] ?? [], 0, 5) as $err) {
                        $sse->sendEvent('info', ['message' => '  - ' . $err]);
                    }
                    return;
                }
                if ($event === 'sync_verify') {
                    $sse->sendEvent('progress', ['message' => __('步骤 4/5：正在同步并校验 DNS 记录…'), 'step' => 4]);
                    return;
                }
                if ($event === 'sync_verify_done') {
                    $count = (int) ($data['record_count'] ?? 0);
                    $sse->sendEvent('progress', ['message' => __('校验完成，共 %{1} 条记录', [$count]), 'step' => 4]);
                    return;
                }
                if ($event === 'verify_cdn') {
                    $sse->sendEvent('progress', ['message' => __('步骤 5/5：校验 CDN 响应…'), 'step' => 5]);
                    return;
                }
                if ($event === 'verify_cdn_done') {
                    if (($data['ok'] ?? false)) {
                        $sse->sendEvent('progress', ['message' => __('CDN 校验通过（响应头符合预期）'), 'step' => 5]);
                    } else {
                        $sse->sendEvent('info', ['message' => __('CDN 头校验未命中，可能尚未生效或非该 CDN 节点，请稍后自行确认')]);
                    }
                    return;
                }
                if ($event === 'complete') {
                    $sse->sendEvent('success', ['message' => __('DNS/CDN 切换完成')]);
                }
            };

            $options = [
                'wait_for_ns' => true,
                'wait_max_seconds' => 30 * 60,
                'wait_interval_seconds' => 15,
                'is_alive' => static function () use ($sse): bool {
                    return $sse->isAlive() && !\connection_aborted();
                },
                'cdn_account' => $cdnAccount,
                'verify_cdn' => $cdnAccountId > 0 || ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class)->isCdnProvider($targetAccount->getRegistrarCode()),
                'verify_cdn_wait_max_seconds' => 5 * 60,
                'verify_cdn_wait_interval_seconds' => 15,
                'records_to_push' => $resolveService->getRecordsForPush($domain),
                'after_sync_records' => function (Domain $d, array $dnsRecords): void {
                    $this->syncDnsProviderToPool($d->getDomain(), (string) $d->getDnsProvider(), (string) ($d->getCdnProvider() ?? ''));
                    if ($dnsRecords !== []) {
                        $this->syncDnsRecordsToDomainPool($d, $dnsRecords, false);
                    }
                },
            ];

            $result = $dnsSwitchService->executeDnsSwitch($domain, $targetAccount, $onStep, $options);

            if (!empty($result['client_aborted'])) {
                $sse->sendEvent('done', ['message' => __('已由用户断开'), 'success' => false]);
                $sse->close();
                return;
            }
            if (!($result['success'] ?? false)) {
                $sse->sendEvent('failed', ['message' => $result['message'] ?? __('切换失败')]);
                $sse->close();
                return;
            }
            $sse->sendEvent('done', ['message' => __('流程结束：%{1}', [$domainName]), 'success' => true]);
        } catch (\Throwable $e) {
            $sse->sendEvent('failed', ['message' => $e->getMessage()]);
        }
        $sse->close();
    }

    /**
     * AJAX: 为域名池记录手动申请 HTTPS 证书
     * POST: pool_id, provider (可选，默认 letsencrypt)
     */
    public function postRequestHttps(): string
    {
        $poolId = (int) $this->request->getParam('pool_id', 0);
        if ($poolId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('请提供 pool_id')]);
        }
        $provider = (string) ($this->request->getParam('provider', '') ?: 'letsencrypt');
        if (!\in_array($provider, self::HTTPS_PROVIDERS, true)) {
            $provider = 'letsencrypt';
        }
        try {
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            $pool->load($poolId);
            if (!$pool->getPoolId()) {
                return $this->fetchJson(['success' => false, 'msg' => __('域名池记录不存在')]);
            }
            $domain = $pool->getDomain();
            if ($domain === '') {
                return $this->fetchJson(['success' => false, 'msg' => __('域名为空')]);
            }
            $webroot = \defined('PUB') ? PUB : (BP . 'pub');
            $email = (string) (Env::getInstance()->getConfig('ssl.contact_email') ?? '');
            if ($email === '') {
                $email = 'admin@' . $domain;
            }
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
            $pool->setHttpsError('');
            $pool->save();

            $result = w_query('server', 'requestCertificate', [
                'domain' => $domain,
                'webroot' => $webroot,
                'email' => $email,
                'website_id' => 0,
                'provider' => $provider,
                'cert_type' => 'exact',
                'pool_id' => $poolId,
            ]);

            if ($result['success'] ?? false) {
                $certId = (int) ($result['cert_id'] ?? 0);
                $poolToUpdate = ObjectManager::getInstance(DomainPool::class, [], false)->loadByDomain($domain);
                if (!$poolToUpdate->getPoolId()) {
                    $poolToUpdate = $pool;
                }
                $poolToUpdate->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
                $poolToUpdate->setHttpsError('');
                if ($certId > 0) {
                    $poolToUpdate->setCertId($certId);
                }
                $cert = $result['cert'] ?? null;
                if ($cert !== null && \method_exists($cert, 'getExpiresAt')) {
                    $expiresAt = $cert->getExpiresAt();
                    if ($expiresAt !== '') {
                        $poolToUpdate->setHttpsExpiresAt($expiresAt);
                    }
                }
                $poolToUpdate->calculateSiteReady();
                $poolToUpdate->save();

                return $this->fetchJson([
                    'success' => true,
                    'msg' => __('证书申请成功：%{1}', [$domain]),
                    'data' => $result,
                ]);
            }
            $poolToUpdate = ObjectManager::getInstance(DomainPool::class, [], false)->loadByDomain($domain);
            if (!$poolToUpdate->getPoolId()) {
                $poolToUpdate = $pool;
            }
            $poolToUpdate->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
            $poolToUpdate->setHttpsError((string) ($result['message'] ?? __('未知错误')));
            $poolToUpdate->save();
            return $this->fetchJson([
                'success' => false,
                'msg' => __('证书申请失败：%{1}', [$result['message'] ?? __('未知错误')]),
            ]);
        } catch (\Throwable $e) {
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            if ($pool->load($poolId)->getPoolId()) {
                $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
                $pool->setHttpsError($e->getMessage());
                $pool->save();
            }
            return $this->fetchJson([
                'success' => false,
                'msg' => __('证书申请异常：%{1}', [$e->getMessage()]),
            ]);
        }
    }
    
    /**
     * AJAX: 为根域名生成默认子域名
     */
    public function postGenerateSubdomains(): string
    {
        $domainId = (int) $this->request->getParam('domain_id', 0);
        
        if ($domainId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('domain_id 不能为空')]);
        }
        
        try {
            $domain = ObjectManager::getInstance(Domain::class, [], false);
            $domain->load($domainId);
            
            if (!$domain->getDomainId()) {
                return $this->fetchJson(['success' => false, 'msg' => __('根域名不存在')]);
            }
            
            $subdomainGenerator = ObjectManager::getInstance(SubdomainGeneratorService::class);
            $result = $subdomainGenerator->generateDefaultSubdomains($domain);
            
            return $this->fetchJson([
                'success' => true,
                'msg' => __('生成完成：新增 %{1} 个，跳过 %{2} 个', [$result['added'], $result['skipped']]),
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('生成失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 批量转存子域名（仅解析到本服务器的域名）
     */
    public function postBatchTransferToPool(): string
    {
        $domainIds = $this->request->getParam('domain_ids', []);

        if (\is_string($domainIds)) {
            $domainIds = \json_decode($domainIds, true) ?: [];
        }

        $domainIds = \array_map('intval', \array_filter((array) $domainIds));

        if ($domainIds === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择要转存的域名')]);
        }

        try {
            $serverIpService = ObjectManager::getInstance(ServerIpService::class);
            $subdomainGenerator = ObjectManager::getInstance(SubdomainGeneratorService::class);

            $serverIp = $serverIpService->getPublicIpv4();
            if ($serverIp === '') {
                return $this->fetchJson(['success' => false, 'msg' => __('无法获取服务器公网IP')]);
            }

            $transferred = 0;
            $skipped = 0;
            $skippedDomains = [];

            foreach ($domainIds as $domainId) {
                $domain = ObjectManager::getInstance(Domain::class, [], false);
                $domain->load($domainId);

                if (!$domain->getDomainId()) {
                    $skipped++;
                    continue;
                }

                $domainName = $domain->getDomain();
                $resolvedIp = \gethostbyname($domainName);

                if ($resolvedIp === $domainName || !$serverIpService->isLocalServer($resolvedIp)) {
                    $skipped++;
                    $skippedDomains[] = $domainName;
                    continue;
                }

                $result = $subdomainGenerator->generateDefaultSubdomains($domain);
                $transferred += $result['added'] ?? 0;
            }

            $msg = __('转存完成：成功 %{1} 个子域名', [$transferred]);
            if ($skipped > 0) {
                $msg .= '，' . __('跳过 %{1} 个域名（IP不匹配）', [$skipped]);
            }

            return $this->fetchJson([
                'success' => true,
                'msg' => $msg,
                'data' => [
                    'transferred' => $transferred,
                    'skipped' => $skipped,
                    'skipped_domains' => $skippedDomains,
                    'server_ip' => $serverIp,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('转存失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 批量解析域名到本地服务器
     */
    public function postBatchResolveToLocal(): string
    {
        $domainIds = $this->request->getParam('domain_ids', []);
        $autoTransferToPool = $this->request->getParam('auto_transfer_to_pool', '0') === '1';
        $prefixes = $this->request->getParam('prefixes', ['@', 'www']);

        if (\is_string($domainIds)) {
            $domainIds = \json_decode($domainIds, true) ?: [];
        }
        if (\is_string($prefixes)) {
            $prefixes = \json_decode($prefixes, true) ?: [];
        }
        if (!\is_array($prefixes)) {
            $prefixes = ['@', 'www'];
        }
        $prefixes = \array_values(\array_filter(\array_map(static fn($v) => \trim((string) $v), $prefixes)));
        if ($prefixes === []) {
            $prefixes = ['@', 'www'];
        }

        $domainIds = \array_map('intval', \array_filter((array) $domainIds));

        if ($domainIds === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择要解析的域名')]);
        }

        try {
            $resolveService = ObjectManager::getInstance(\Weline\Websites\Service\DomainResolveService::class);
            $serverIpService = ObjectManager::getInstance(ServerIpService::class);
            $subdomainGenerator = ObjectManager::getInstance(SubdomainGeneratorService::class);

            $serverIp = $serverIpService->getPublicIpv4();
            if ($serverIp === '') {
                return $this->fetchJson(['success' => false, 'msg' => __('无法获取服务器公网IP')]);
            }

            $success = 0;
            $failed = 0;
            $errors = [];
            $poolAdded = 0;
            $poolSkipped = 0;
            /** @var array<int, array{domain_id: int, domain: string, result: array}> 解析 API 完整返回，便于排查失败原因 */
            $resolve_results = [];

            foreach ($domainIds as $domainId) {
                $domain = ObjectManager::getInstance(Domain::class, [], false);
                $domain->load($domainId);

                if (!$domain->getDomainId()) {
                    $failed++;
                    $errors[] = __('域名 ID %{1} 不存在或加载失败', [$domainId]);
                    $resolve_results[] = [
                        'domain_id' => $domainId,
                        'domain' => '',
                        'result' => ['success' => false, 'errors' => [__('域名不存在或加载失败')]],
                    ];
                    continue;
                }

                $result = $resolveService->autoResolveToLocal($domain, ['@', 'www']);

                if ($result['success']) {
                    $success++;
                    // 解析成功后直接按目标记录写入域名池（不依赖第三方查询接口返回）
                    $poolSyncAfterResolve = $this->syncDnsRecordsToDomainPool($domain, [
                        ['type' => 'A', 'host' => '@', 'value' => $serverIp],
                        ['type' => 'A', 'host' => 'www', 'value' => $serverIp],
                    ], false);
                    $poolAdded += (int) ($poolSyncAfterResolve['added'] ?? 0);
                    $poolSkipped += (int) ($poolSyncAfterResolve['skipped'] ?? 0);
                    if ($autoTransferToPool) {
                        $poolResult = $subdomainGenerator->generateDefaultSubdomains($domain, $prefixes);
                        $poolAdded += (int) ($poolResult['added'] ?? 0);
                        $poolSkipped += (int) ($poolResult['skipped'] ?? 0);
                    }
                } else {
                    $failed++;
                    $errList = $result['errors'] ?? [];
                    $errParts = \array_map(static function ($e) {
                        if (\is_array($e)) {
                            $v = $e['error'] ?? $e['message'] ?? $e;
                            return \is_array($v) ? \json_encode($v, \JSON_UNESCAPED_UNICODE) : (string) $v;
                        }
                        return (string) $e;
                    }, $errList);
                    $errors[] = $domain->getDomain() . ': ' . (\implode('; ', $errParts) ?: __('API 未返回具体错误，请查看 resolve_results'));
                    $resolve_results[] = [
                        'domain_id' => $domainId,
                        'domain' => $domain->getDomain(),
                        'result' => $result,
                    ];
                    // 第三方 API 失败时，兜底使用实时 DNS 查询同步域名池（双保险）
                    $fallbackRecords = $this->collectLiveDnsRecordsForPoolSync($domain);
                    if ($fallbackRecords !== []) {
                        $fallbackSync = $this->syncDnsRecordsToDomainPool($domain, $fallbackRecords, false);
                        $poolAdded += (int) ($fallbackSync['added'] ?? 0);
                        $poolSkipped += (int) ($fallbackSync['skipped'] ?? 0);
                    }
                }
            }

            $summary = __('解析完成：成功 %{1} 个，失败 %{2} 个', [$success, $failed]);
            if ($autoTransferToPool) {
                $summary .= '，' . __('域名池新增 %{1} 个，跳过 %{2} 个', [$poolAdded, $poolSkipped]);
            }
            // 所有错误必须完整写入 msg，便于 BackendToast 等组件识别并展示
            if ($errors !== []) {
                $errorText = \implode('；', $errors);
                $msg = __('失败 %{1} 个，具体错误：%{2}', [$failed, $errorText]) . '。' . $summary;
            } else {
                $msg = $summary;
            }

            return $this->fetchJson([
                'success' => true,
                'msg' => $msg,
                'data' => [
                    'success' => $success,
                    'failed' => $failed,
                    'errors' => $errors,
                    'server_ip' => $serverIp,
                    'auto_transfer_to_pool' => $autoTransferToPool,
                    'pool_added' => $poolAdded,
                    'pool_skipped' => $poolSkipped,
                    'resolve_results' => $resolve_results,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('解析失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 单条添加 DNS 解析记录
     */
    public function postAddDnsRecord(): string
    {
        $domainId = (int) $this->request->getParam('domain_id', 0);
        $type = (string) $this->request->getParam('type', 'A');
        $host = (string) $this->request->getParam('host', '@');
        $value = (string) $this->request->getParam('value', '');
        $ttl = (int) $this->request->getParam('ttl', 600);
        $priority = (int) $this->request->getParam('priority', 0);

        if ($domainId <= 0 || $value === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('域名 ID 和记录值不能为空')]);
        }

        try {
            $domain = ObjectManager::getInstance(Domain::class, [], false);
            $domain->load($domainId);

            if (!$domain->getDomainId()) {
                return $this->fetchJson(['success' => false, 'msg' => __('域名不存在')]);
            }

            $accountId = (int) $domain->getAccountId();
            $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $account->load($accountId);

            if (!$account->getAccountId()) {
                return $this->fetchJson(['success' => false, 'msg' => __('找不到域名商账户')]);
            }

            $registrarResolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $adapter = $registrarResolver->getAdapter($account->getRegistrarCode());

            if ($adapter === null || !$adapter->supportsDnsManagement()) {
                return $this->fetchJson(['success' => false, 'msg' => __('域名商不支持 DNS 管理')]);
            }

            $record = [
                'type' => $type,
                'host' => $host,
                'value' => $value,
                'ttl' => $ttl,
                'priority' => $priority,
            ];

            $result = $adapter->addDnsRecord($domain->getDomain(), $record, $account->getCredentials());

            if (!$result['success']) {
                return $this->fetchJson(['success' => false, 'msg' => $result['message'] ?? __('添加 DNS 记录失败')]);
            }

            // 新增 DNS 后立即同步到域名池：
            // - 本机 IP：自动入池
            // - 非本机 IP：入池并标记 is_local_server=0
            $poolSync = $this->syncDnsRecordsToDomainPool($domain, [$record], true);

            return $this->fetchJson([
                'success' => true,
                'msg' => __('DNS 记录添加成功'),
                'data' => [
                    'dns_result' => $result,
                    'pool_sync' => $poolSync,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('添加 DNS 记录失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 批量添加 DNS 解析记录
     */
    public function postBatchAddDnsRecords(): string
    {
        $domainIds = $this->request->getParam('domain_ids', []);
        $type = (string) $this->request->getParam('type', 'A');
        $host = (string) $this->request->getParam('host', '@');
        $value = (string) $this->request->getParam('value', '');
        $ttl = (int) $this->request->getParam('ttl', 600);
        $priority = (int) $this->request->getParam('priority', 0);
        $useServerIp = (bool) $this->request->getParam('use_server_ip', false);

        if (\is_string($domainIds)) {
            $domainIds = \json_decode($domainIds, true) ?: [];
        }
        $domainIds = \array_filter(\array_map('intval', (array) $domainIds));

        if ($domainIds === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择要添加 DNS 记录的域名')]);
        }

        try {
            if ($useServerIp) {
                $serverIpService = ObjectManager::getInstance(ServerIpService::class);
                $value = $serverIpService->getPublicIpv4();
                if ($value === '') {
                    return $this->fetchJson(['success' => false, 'msg' => __('无法获取服务器公网 IP')]);
                }
                $type = 'A';
            } elseif ($value === '') {
                return $this->fetchJson(['success' => false, 'msg' => __('记录值不能为空')]);
            }

            $record = [
                'type' => $type,
                'host' => $host,
                'value' => $value,
                'ttl' => $ttl,
                'priority' => $priority,
            ];

            $registrarResolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);

            $successCount = 0;
            $failedCount = 0;
            $errors = [];

            foreach ($domainIds as $domainId) {
                $domain = ObjectManager::getInstance(Domain::class, [], false);
                $domain->load($domainId);

                if (!$domain->getDomainId()) {
                    $failedCount++;
                    $errors[] = __('域名 ID %{1} 不存在', [$domainId]);
                    continue;
                }

                $accountId = (int) $domain->getAccountId();
                $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                $account->load($accountId);

                if (!$account->getAccountId()) {
                    $failedCount++;
                    $errors[] = $domain->getDomain() . ': ' . __('找不到域名商账户');
                    continue;
                }

                $adapter = $registrarResolver->getAdapter($account->getRegistrarCode());
                if ($adapter === null || !$adapter->supportsDnsManagement()) {
                    $failedCount++;
                    $errors[] = $domain->getDomain() . ': ' . __('域名商不支持 DNS 管理');
                    continue;
                }

                try {
                    $result = $adapter->addDnsRecord($domain->getDomain(), $record, $account->getCredentials());
                    if ($result['success']) {
                        $successCount++;
                    } else {
                        $failedCount++;
                        $errors[] = $domain->getDomain() . ': ' . ($result['message'] ?? __('添加失败'));
                    }
                } catch (\Throwable $e) {
                    $failedCount++;
                    $errors[] = $domain->getDomain() . ': ' . $e->getMessage();
                }
            }

            $msg = __('批量添加 DNS 记录完成：成功 %{1} 个，失败 %{2} 个', [$successCount, $failedCount]);

            return $this->fetchJson([
                'success' => $failedCount === 0 || $successCount > 0,
                'msg' => $msg,
                'data' => [
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'errors' => $errors,
                    'record' => $record,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('批量添加 DNS 记录失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 获取域名的 DNS 记录列表
     */
    public function getGetDnsRecords(): string
    {
        $domainId = (int) $this->request->getParam('domain_id', 0);

        if ($domainId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('域名 ID 不能为空')]);
        }

        try {
            $queryResult = w_query('websites', 'getDnsRecords', [
                'domain_id' => $domainId,
            ]);

            if (!\is_array($queryResult)) {
                $fallback = $this->queryDnsRecordsFallback($domainId);
                return $this->fetchJson($fallback);
            }

            return $this->fetchJson([
                'success' => (bool)($queryResult['success'] ?? false),
                'msg' => (string)($queryResult['message'] ?? __('获取失败')),
                'data' => (array)($queryResult['data'] ?? []),
            ]);
        } catch (\Throwable $e) {
            $fallback = $this->queryDnsRecordsFallback($domainId);
            $fallbackMsg = (string)($fallback['msg'] ?? '');
            $prefix = (string)__('查询层异常，已切换本地兜底：%{1}', [$e->getMessage()]);
            $fallback['msg'] = $fallbackMsg !== '' ? $prefix . '；' . $fallbackMsg : $prefix;
            return $this->fetchJson($fallback);
        }
    }

    /**
     * DNS 查询兜底：不依赖 QueryProvider 注册状态
     */
    private function queryDnsRecordsFallback(int $domainId): array
    {
        try {
            $domain = ObjectManager::getInstance(Domain::class, [], false);
            $domain->load($domainId);
            if (!$domain->getDomainId()) {
                return ['success' => false, 'msg' => __('域名不存在')];
            }

            /** @var \Weline\Websites\Service\DomainResolveService $resolveService */
            $resolveService = ObjectManager::getInstance(\Weline\Websites\Service\DomainResolveService::class);
            $sync = $resolveService->syncDnsRecords($domain);
            $details = $resolveService->getDnsDetails($domain);

            $records = \is_array($details['records'] ?? null) ? $details['records'] : [];
            // 查询 DNS 时：查到的记录都入池；仅用本机 IP 判定 is_local_server。
            $poolSync = $this->syncDnsRecordsToDomainPool($domain, $records, true);
            $liveRecords = $this->collectLiveDnsRecordsForPoolSync($domain);
            if ($liveRecords !== []) {
                $liveSync = $this->syncDnsRecordsToDomainPool($domain, $liveRecords, true);
                $poolSync['added'] += (int)($liveSync['added'] ?? 0);
                $poolSync['marked_non_local'] += (int)($liveSync['marked_non_local'] ?? 0);
                $poolSync['skipped'] += (int)($liveSync['skipped'] ?? 0);
            }

            $dnsProvider = \is_array($details['dns_provider'] ?? null) ? $details['dns_provider'] : [];
            $syncError = (string)($sync['error'] ?? '');
            $serverIpService = ObjectManager::getInstance(ServerIpService::class);

            return [
                'success' => true,
                'msg' => $syncError === ''
                    ? __('获取成功（兜底）')
                    : __('DNS远程同步失败，已使用本地/实时数据回填并同步域名池：%{1}', [$syncError]),
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
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'msg' => __('获取 DNS 记录失败（兜底）：%{1}', [$e->getMessage()]),
            ];
        }
    }

    /**
     * 将 DNS 记录同步到域名池（双保险）
     *
     * @param Domain $rootDomain 根域名模型
     * @param array $records DNS 记录数组
     * @param bool $createNonLocal 是否在非本机 IP 时也创建池记录（新增记录场景）
     * @return array{added:int, marked_non_local:int, skipped:int}
     */
    private function syncDnsRecordsToDomainPool(Domain $rootDomain, array $records, bool $createNonLocal = false): array
    {
        $serverIpService = ObjectManager::getInstance(ServerIpService::class);
        $rootDomainName = \strtolower((string) $rootDomain->getDomain());
        $parentDomainId = (int) $rootDomain->getDomainId();
        $now = \date('Y-m-d H:i:s');

        $added = 0;
        $markedNonLocal = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (!\is_array($record)) {
                $skipped++;
                continue;
            }

            $type = \strtoupper((string) ($record['type'] ?? $record['record_type'] ?? ''));
            $host = \trim((string) ($record['host'] ?? $record['name'] ?? '@'));
            $value = \trim((string) ($record['value'] ?? $record['data'] ?? ''));

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
                $poolDomain->setDescription(__('由 DNS 记录自动同步'));
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

    /**
     * 根据 DNS host 生成完整域名
     */
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

    /**
     * 实时 DNS 查询（@ + www）用于域名池同步兜底
     */
    private function collectLiveDnsRecordsForPoolSync(Domain $rootDomain): array
    {
        $root = \strtolower(\trim((string) $rootDomain->getDomain()));
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
                    $ip = \trim((string) ($aRecord['ip'] ?? ''));
                    if ($ip !== '') {
                        $records[] = [
                            'type' => 'A',
                            'host' => $host,
                            'value' => $ip,
                        ];
                    }
                }
            }

            $aaaaRecords = @\dns_get_record($fqdn, \DNS_AAAA);
            if (\is_array($aaaaRecords)) {
                foreach ($aaaaRecords as $aaaaRecord) {
                    $ip6 = \trim((string) ($aaaaRecord['ipv6'] ?? ''));
                    if ($ip6 !== '') {
                        $records[] = [
                            'type' => 'AAAA',
                            'host' => $host,
                            'value' => $ip6,
                        ];
                    }
                }
            }
        }

        return $records;
    }

    /**
     * AJAX: 删除 DNS 解析记录
     */
    public function postDeleteDnsRecord(): string
    {
        $domainId = (int) $this->request->getParam('domain_id', 0);
        $recordId = (string) $this->request->getParam('record_id', '');

        if ($domainId <= 0 || $recordId === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('域名 ID 和记录 ID 不能为空')]);
        }

        try {
            $domain = ObjectManager::getInstance(Domain::class, [], false);
            $domain->load($domainId);

            if (!$domain->getDomainId()) {
                return $this->fetchJson(['success' => false, 'msg' => __('域名不存在')]);
            }

            $accountId = (int) $domain->getAccountId();
            $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $account->load($accountId);

            if (!$account->getAccountId()) {
                return $this->fetchJson(['success' => false, 'msg' => __('找不到域名商账户')]);
            }

            $registrarResolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $adapter = $registrarResolver->getAdapter($account->getRegistrarCode());

            if ($adapter === null || !$adapter->supportsDnsManagement()) {
                return $this->fetchJson(['success' => false, 'msg' => __('域名商不支持 DNS 管理')]);
            }

            $result = $adapter->deleteDnsRecord($domain->getDomain(), $recordId, $account->getCredentials());

            if (!$result['success']) {
                return $this->fetchJson(['success' => false, 'msg' => $result['message'] ?? __('删除 DNS 记录失败')]);
            }

            return $this->fetchJson([
                'success' => true,
                'msg' => __('DNS 记录删除成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('删除 DNS 记录失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 获取/刷新服务器公网 IP
     */
    public function getRefreshServerIp(): string
    {
        try {
            $serverIpService = ObjectManager::getInstance(ServerIpService::class);
            $ip = $serverIpService->getPublicIpv4();

            if ($ip === '') {
                return $this->fetchJson(['code' => 400, 'msg' => __('无法获取服务器公网 IP')]);
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('获取成功'),
                'data' => ['ip' => $ip],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取 IP 失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 批量切换域名 DNS 服务器
     */
    public function postBatchChangeNameservers(): string
    {
        $domainIds = $this->request->getParam('domain_ids', []);
        $nameservers = $this->request->getParam('nameservers', '');
        $targetProvider = $this->request->getParam('target_provider', '');

        if (\is_string($domainIds)) {
            $domainIds = \json_decode($domainIds, true) ?: [];
        }

        $domainIds = \array_map('intval', \array_filter((array) $domainIds));

        if ($domainIds === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择要切换的域名')]);
        }

        $nameserverList = [];
        if ($nameservers !== '') {
            $nameserverList = \array_filter(\array_map('trim', \explode(',', $nameservers)));
        }

        if ($nameserverList === [] && $targetProvider === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('请输入目标 DNS 服务器或选择目标服务商')]);
        }

        try {
            $registrarResolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);

            if ($targetProvider !== '' && $nameserverList === []) {
                $nameserverList = $this->getProviderNameservers($targetProvider);
                if ($nameserverList === []) {
                    return $this->fetchJson(['success' => false, 'msg' => __('无法获取目标服务商的 Nameserver')]);
                }
            }

            $success = 0;
            $failed = 0;
            $errors = [];
            $autoSwitchedProvider = 0;
            $autoSyncErrors = [];

            foreach ($domainIds as $domainId) {
                $domain = ObjectManager::getInstance(Domain::class, [], false);
                $domain->load($domainId);

                if (!$domain->getDomainId()) {
                    $failed++;
                    continue;
                }

                $accountId = (int) $domain->getAccountId();
                $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                $account->load($accountId);

                if (!$account->getAccountId()) {
                    $failed++;
                    $errors[] = $domain->getDomain() . ': ' . __('找不到域名商账户');
                    continue;
                }

                $adapter = $registrarResolver->getAdapter($account->getRegistrarCode());
                if ($adapter === null) {
                    $failed++;
                    $errors[] = $domain->getDomain() . ': ' . __('域名商适配器不存在');
                    continue;
                }

                $credentials = $account->getCredentials();
                $result = $adapter->updateNameservers($domain->getDomain(), $nameserverList, $credentials);

                if ($result['success'] ?? false) {
                    $success++;
                    $domain->setNameservers(\implode(',', $nameserverList));
                    $domain->save();
                    $autoResult = $this->refreshDomainDnsProviderAndSyncRecords($domain, true);
                    if (($autoResult['provider_updated'] ?? false) === true) {
                        $autoSwitchedProvider++;
                    }
                    if (($autoResult['sync_error'] ?? '') !== '') {
                        $autoSyncErrors[] = $domain->getDomain() . ': ' . $autoResult['sync_error'];
                    }
                } else {
                    $failed++;
                    $errors[] = $domain->getDomain() . ': ' . ($result['message'] ?? __('切换失败'));
                }
            }

            $msg = __('DNS切换完成：成功 %{1} 个，失败 %{2} 个', [$success, $failed]);

            return $this->fetchJson([
                'success' => true,
                'msg' => $msg,
                'data' => [
                    'success' => $success,
                    'failed' => $failed,
                    'errors' => $errors,
                    'target_nameservers' => $nameserverList,
                    'auto_switched_provider' => $autoSwitchedProvider,
                    'auto_sync_errors' => $autoSyncErrors,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('切换失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 获取可用的 DNS 服务商列表
     */
    public function getGetDnsProviders(): string
    {
        $providers = [
            [
                'code' => 'cloudflare',
                'name' => 'Cloudflare',
                'nameservers' => [],
                'description' => __('全球领先的 CDN 和 DNS 服务商，提供免费 DDoS 防护'),
            ],
            [
                'code' => 'dnspod',
                'name' => 'DNSPod',
                'nameservers' => ['ns1.dnspod.net', 'ns2.dnspod.net'],
                'description' => __('腾讯云 DNS 服务，国内解析速度快'),
            ],
            [
                'code' => 'alidns',
                'name' => __('阿里云 DNS'),
                'nameservers' => ['ns1.alidns.com', 'ns2.alidns.com'],
                'description' => __('阿里云 DNS 服务，稳定可靠'),
            ],
            [
                'code' => 'custom',
                'name' => __('自定义'),
                'nameservers' => [],
                'description' => __('手动输入 Nameserver'),
            ],
        ];

        return $this->fetchJson([
            'success' => true,
            'msg' => 'success',
            'data' => ['providers' => $providers],
        ]);
    }

    /**
     * 获取服务商的默认 Nameserver
     */
    private function getProviderNameservers(string $provider): array
    {
        $providerNameservers = [
            'dnspod' => ['ns1.dnspod.net', 'ns2.dnspod.net'],
            'alidns' => ['ns1.alidns.com', 'ns2.alidns.com'],
            'cloudflare' => [],
            'godaddy' => ['ns1.domaincontrol.com', 'ns2.domaincontrol.com'],
            'namecheap' => ['dns1.registrar-servers.com', 'dns2.registrar-servers.com'],
        ];

        return $providerNameservers[\strtolower($provider)] ?? [];
    }

    /**
     * AJAX: 获取可用的域名商账户列表（用于DNS切换、购买弹窗等）
     * 支持 GET 参数 active_only=1 仅返回活跃账号
     */
    public function getGetRegistrarAccounts(): string
    {
        try {
            $accountModel = ObjectManager::getInstance(DomainRegistrarAccount::class);
            $accountModel->clearData(true);
            $accountModel->clearQuery();
            if ($this->request->getParam('active_only', '0') === '1') {
                $accountModel->where(DomainRegistrarAccount::schema_fields_STATUS, DomainRegistrarAccount::STATUS_ACTIVE);
            }
            $allAccounts = $accountModel->select()->fetchArray();

            $result = [];
            foreach ($allAccounts as $account) {
                $acctId = (int) ($account['account_id'] ?? $account['id'] ?? 0);
                if ($acctId <= 0) {
                    continue;
                }
                $registrarCode = $account['registrar_code'] ?? '';
                $registrarName = $account['registrar_name'] ?? '';

                if ($registrarCode === '' || $registrarName === '') {
                    $registrarId = (int) ($account['registrar_id'] ?? 0);
                    if ($registrarId > 0) {
                        $registrar = ObjectManager::getInstance(\Weline\Websites\Model\DomainRegistrar::class);
                        $registrar->clearData(true);
                        $registrar->clearQuery();
                        $registrar->where(\Weline\Websites\Model\DomainRegistrar::schema_fields_ID, $registrarId)
                            ->find()->fetch();
                        $registrarCode = (string) ($registrar->getData(\Weline\Websites\Model\DomainRegistrar::schema_fields_CODE) ?? '');
                        $registrarName = (string) ($registrar->getData(\Weline\Websites\Model\DomainRegistrar::schema_fields_NAME) ?? '');
                    }
                }

                $result[] = [
                    'id' => $acctId,
                    'name' => $account['account_name'] ?? '',
                    'registrar_code' => $registrarCode,
                    'registrar_name' => $registrarName,
                ];
            }

            return $this->fetchJson([
                'success' => true,
                'msg' => 'success',
                'data' => ['accounts' => $result],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('获取账户列表失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 获取账户的 Nameserver（用于一键切换）
     */
    public function postGetAccountNameservers(): string
    {
        $accountId = (int) $this->request->getParam('account_id', 0);
        $domains = $this->request->getParam('domains', []);

        if ($accountId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择目标账户')]);
        }

        if (\is_string($domains)) {
            $domains = \json_decode($domains, true) ?: [];
        }

        try {
            $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $account->load($accountId);

            if (!$account->getAccountId()) {
                return $this->fetchJson(['success' => false, 'msg' => __('账户不存在')]);
            }

            $registrarResolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $adapter = $registrarResolver->getAdapter($account->getRegistrarCode());

            if ($adapter === null) {
                return $this->fetchJson(['success' => false, 'msg' => __('适配器不存在')]);
            }

            $credentials = $account->getCredentials();

            if (empty($domains)) {
                $result = $adapter->getProviderNameservers($credentials);
                return $this->fetchJson([
                    'success' => $result['success'] ?? false,
                    'msg' => $result['message'] ?? '',
                    'data' => [
                        'nameservers' => $result['nameservers'] ?? [],
                        'per_domain' => false,
                    ],
                ]);
            }

            $firstDomain = \is_array($domains) ? ($domains[0] ?? '') : '';
            $result = $adapter->getProviderNameservers($credentials, $firstDomain);

            $needsPerDomain = ($account->getRegistrarCode() === 'cloudflare');

            if ($needsPerDomain && !empty($domains)) {
                $domainNs = [];
                foreach ($domains as $domain) {
                    $nsResult = $adapter->getProviderNameservers($credentials, $domain);
                    $domainNs[$domain] = [
                        'success' => $nsResult['success'] ?? false,
                        'nameservers' => $nsResult['nameservers'] ?? [],
                        'message' => $nsResult['message'] ?? '',
                    ];
                }
                return $this->fetchJson([
                    'success' => true,
                    'msg' => __('已获取各域名的 Nameserver'),
                    'data' => [
                        'nameservers' => [],
                        'per_domain' => true,
                        'domain_nameservers' => $domainNs,
                    ],
                ]);
            }

            return $this->fetchJson([
                'success' => $result['success'] ?? false,
                'msg' => $result['message'] ?? '',
                'data' => [
                    'nameservers' => $result['nameservers'] ?? [],
                    'per_domain' => false,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('获取 Nameserver 失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 批量切换域名到目标账户（一键切换）
     */
    public function postBatchSwitchToAccount(): string
    {
        $domainIds = $this->request->getParam('domain_ids', []);
        $targetAccountId = (int) $this->request->getParam('target_account_id', 0);

        if (\is_string($domainIds)) {
            $domainIds = \json_decode($domainIds, true) ?: [];
        }

        $domainIds = \array_map('intval', \array_filter((array) $domainIds));

        if ($domainIds === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择要切换的域名')]);
        }

        if ($targetAccountId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择目标账户')]);
        }

        try {
            $targetAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $targetAccount->load($targetAccountId);

            if (!$targetAccount->getAccountId()) {
                return $this->fetchJson(['success' => false, 'msg' => __('目标账户不存在')]);
            }

            $registrarResolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $targetAdapter = $registrarResolver->getAdapter($targetAccount->getRegistrarCode());

            if ($targetAdapter === null) {
                return $this->fetchJson(['success' => false, 'msg' => __('目标适配器不存在')]);
            }

            $targetCredentials = $targetAccount->getCredentials();

            $success = 0;
            $failed = 0;
            $errors = [];
            $autoSwitchedProvider = 0;
            $autoSyncErrors = [];

            foreach ($domainIds as $domainId) {
                $domain = ObjectManager::getInstance(Domain::class, [], false);
                $domain->load($domainId);

                if (!$domain->getDomainId()) {
                    $failed++;
                    continue;
                }

                $domainName = $domain->getDomain();

                $nsResult = $targetAdapter->getProviderNameservers($targetCredentials, $domainName);
                if (!($nsResult['success'] ?? false) || empty($nsResult['nameservers'])) {
                    $failed++;
                    $errors[] = $domainName . ': ' . ($nsResult['message'] ?? __('无法获取目标 Nameserver'));
                    continue;
                }

                $targetNs = $nsResult['nameservers'];

                $sourceAccountId = (int) $domain->getAccountId();
                $sourceAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                $sourceAccount->load($sourceAccountId);

                if (!$sourceAccount->getAccountId()) {
                    $failed++;
                    $errors[] = $domainName . ': ' . __('找不到源域名商账户');
                    continue;
                }

                $sourceAdapter = $registrarResolver->getAdapter($sourceAccount->getRegistrarCode());
                if ($sourceAdapter === null) {
                    $failed++;
                    $errors[] = $domainName . ': ' . __('源域名商适配器不存在');
                    continue;
                }

                $sourceCredentials = $sourceAccount->getCredentials();

                $resolveService = ObjectManager::getInstance(\Weline\Websites\Service\DomainResolveService::class);
                $recordsToPush = $resolveService->getRecordsForPush($domain);

                $updateResult = $sourceAdapter->updateNameservers($domainName, $targetNs, $sourceCredentials);

                if ($updateResult['success'] ?? false) {
                    $success++;
                    $domain->setNameservers($targetNs);
                    $targetCode = (string) ($targetAccount->getRegistrarCode() ?? '');
                    $domain->setDnsProvider($targetCode);
                    $domain->setDnsAccountId($targetAccount->getAccountId());
                    if ($targetCode !== '' && ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class)->isCdnProvider($targetCode)) {
                        $domain->setCdnProvider($targetAccount->getRegistrarCode());
                        $domain->setCdnAccountId($targetAccount->getAccountId());
                    }
                    $domain->forceCheck(false)->save();

                    $pushResult = $resolveService->pushRecordsToProvider($domain, $targetAccount, $recordsToPush);
                    if (($pushResult['failed'] ?? 0) > 0 && !empty($pushResult['errors'])) {
                        $autoSyncErrors[] = $domainName . ': ' . __('记录同步') . ' - ' . \implode('; ', \array_slice($pushResult['errors'], 0, 3));
                    }
                    $autoSwitchedProvider++;

                    $sync = $resolveService->syncDnsRecords($domain);
                    $syncError = (string) ($sync['error'] ?? '');
                    if ($syncError !== '') {
                        $autoSyncErrors[] = $domain->getDomain() . ': ' . $syncError;
                    } else {
                        $this->syncDnsProviderToPool($domainName, $targetCode, $targetCode);
                        $dnsDetails = $resolveService->getDnsDetails($domain);
                        $dnsRecords = \is_array($dnsDetails['records'] ?? null) ? $dnsDetails['records'] : [];
                        if ($dnsRecords !== []) {
                            $this->syncDnsRecordsToDomainPool($domain, $dnsRecords, false);
                        }
                    }
                } else {
                    $failed++;
                    $errors[] = $domainName . ': ' . ($updateResult['message'] ?? __('切换失败'));
                }
            }

            $msg = __('DNS切换完成：成功 %{1} 个，失败 %{2} 个', [$success, $failed]);

            return $this->fetchJson([
                'success' => true,
                'msg' => $msg,
                'data' => [
                    'success' => $success,
                    'failed' => $failed,
                    'errors' => $errors,
                    'auto_switched_provider' => $autoSwitchedProvider,
                    'auto_sync_errors' => $autoSyncErrors,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('切换失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 实时检测域名的 DNS 服务商和 NS 记录
     */
    public function postDetectDnsProvider(): string
    {
        try {
            $domainIds = $this->request->getParam('domain_ids', []);
            $forceRefresh = (bool) $this->request->getParam('force_refresh', false);

            if (\is_string($domainIds)) {
                $domainIds = \json_decode($domainIds, true) ?: [];
            }

            $domainIds = \array_map('intval', \array_filter((array) $domainIds));

            if ($domainIds === []) {
                return $this->fetchJson(['success' => false, 'msg' => __('请选择要检测的域名')]);
            }

            $dnsDetector = ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class);
            $domainModel = ObjectManager::getInstance(\Weline\Websites\Model\Domain::class);
            $accountModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainRegistrarAccount::class);

            $results = [];
            $updated = 0;

            foreach ($domainIds as $domainId) {
                $domain = clone $domainModel;
                $domain->clearQuery()->load($domainId);

                if (!$domain->getId()) {
                    continue;
                }

                $domainName = $domain->getDomain();
                $registrarCode = '';

                $accountId = $domain->getAccountId();
                if ($accountId > 0) {
                    $account = clone $accountModel;
                    $account->load($accountId);
                    $registrarCode = $account->getRegistrarCode() ?: '';
                }

                // 实时查询 NS
                $liveNs = $this->queryLiveNsRecords($domainName);
                $storedNs = $domain->getNameservers();

                if (!empty($liveNs)) {
                    if ($forceRefresh || $liveNs !== $storedNs) {
                        $domain->setNameservers($liveNs);
                        $storedNs = $liveNs;
                    }
                }

                $detectResult = $dnsDetector->detect($storedNs, $registrarCode);
                $provider = $detectResult['provider'];

                $currentProvider = $domain->getDnsProvider();
                if ($currentProvider !== $provider) {
                    $domain->setDnsProvider($provider);
                    $updated++;
                }

                // 如果 DNS 服务商是 CDN 服务商，同步更新 cdn_provider 和 cdn_account_id
                $cdnProvider = '';
                $cdnAccountId = 0;
                if ($dnsDetector->isCdnProvider($provider)) {
                    $cdnProvider = $provider;
                    $domain->setCdnProvider($cdnProvider);

                    // 自动查找并关联 CDN 账户
                    $resolveService = ObjectManager::getInstance(\Weline\Websites\Service\DomainResolveService::class);
                    if ($domain->getCdnAccountId() === 0) {
                        $cdnAccount = $resolveService->findAccountByProviderCode($cdnProvider);
                        if ($cdnAccount !== null) {
                            $cdnAccountId = $cdnAccount->getAccountId();
                            $domain->setCdnAccountId($cdnAccountId);
                        }
                    } else {
                        $cdnAccountId = $domain->getCdnAccountId();
                    }

                    // 同时设置 DNS 账户
                    if ($domain->getDnsAccountId() === 0 && $cdnAccountId > 0) {
                        $domain->setDnsAccountId($cdnAccountId);
                    }
                }

                // 强制保存
                $domain->forceCheck(false)->save();

                // 同步到域名池
                $poolUpdated = $this->syncDnsProviderToPool($domainName, $provider, $cdnProvider);

                // 获取 CDN provider 显示名称
                $cdnProviderName = '';
                if ($cdnProvider !== '') {
                    $cdnInfo = $dnsDetector->getProviderInfo($cdnProvider);
                    $cdnProviderName = $cdnInfo['name'] ?? $cdnProvider;
                }

                $results[$domainName] = [
                    'domain_id' => $domainId,
                    'domain' => $domainName,
                    'nameservers' => $storedNs,
                    'live_nameservers' => $liveNs,
                    'dns_provider' => $provider,
                    'dns_provider_name' => $detectResult['name'],
                    'dns_provider_color' => $detectResult['color'],
                    'cdn_provider' => $cdnProvider,
                    'cdn_provider_name' => $cdnProviderName,
                    'dns_account_id' => $domain->getDnsAccountId(),
                    'cdn_account_id' => $cdnAccountId,
                    'is_original' => $detectResult['is_original'],
                    'registrar_code' => $registrarCode,
                    'pool_updated' => $poolUpdated,
                ];
            }

            return $this->fetchJson([
                'success' => true,
                'msg' => __('DNS 检测完成，共 %{1} 个根域，%{2} 个已更新', [\count($results), $updated]),
                'data' => [
                    'results' => $results,
                    'total' => \count($results),
                    'updated' => $updated,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('DNS 检测失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 实时查询域名的 NS 记录
     */
    private function queryLiveNsRecords(string $domain): array
    {
        $records = @\dns_get_record($domain, \DNS_NS);
        if ($records === false || empty($records)) {
            return [];
        }

        $nameservers = [];
        foreach ($records as $record) {
            if (isset($record['target'])) {
                $nameservers[] = \strtolower($record['target']);
            }
        }

        \sort($nameservers);
        return $nameservers;
    }

    /**
     * DNS 切换后自动识别供应商并同步 DNS 记录
     */
    private function refreshDomainDnsProviderAndSyncRecords(Domain $domain, bool $forceRefresh = true): array
    {
        $domainName = (string) $domain->getDomain();
        if ($domainName === '') {
            return ['provider_updated' => false, 'sync_error' => ''];
        }

        $dnsDetector = ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class);
        $resolveService = ObjectManager::getInstance(\Weline\Websites\Service\DomainResolveService::class);
        $accountModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainRegistrarAccount::class);

        $registrarCode = '';
        $accountId = (int) $domain->getAccountId();
        if ($accountId > 0) {
            $account = clone $accountModel;
            $account->load($accountId);
            $registrarCode = (string) ($account->getRegistrarCode() ?: '');
        }

        $liveNs = $this->queryLiveNsRecords($domainName);
        $storedNs = $domain->getNameservers();
        if ($liveNs !== [] && ($forceRefresh || $liveNs !== $storedNs)) {
            $domain->setNameservers($liveNs);
            $storedNs = $liveNs;
        }

        $detectResult = $dnsDetector->detect($storedNs, $registrarCode);
        $provider = (string) ($detectResult['provider'] ?? '');
        $providerUpdated = false;

        if ($provider !== '' && $domain->getDnsProvider() !== $provider) {
            $domain->setDnsProvider($provider);
            $providerUpdated = true;
        }

        if ($provider !== '') {
            $providerAccount = $resolveService->findAccountByProviderCode($provider);
            if ($providerAccount !== null) {
                $providerAccountId = (int) $providerAccount->getAccountId();
                if ($providerAccountId > 0 && (int) $domain->getDnsAccountId() !== $providerAccountId) {
                    $domain->setDnsAccountId($providerAccountId);
                    $providerUpdated = true;
                }

                if ($dnsDetector->isCdnProvider($provider)) {
                    if ((int) $domain->getCdnAccountId() !== $providerAccountId) {
                        $domain->setCdnAccountId($providerAccountId);
                        $providerUpdated = true;
                    }
                    if ((string) $domain->getCdnProvider() !== $provider) {
                        $domain->setCdnProvider($provider);
                        $providerUpdated = true;
                    }
                }
            }
        }

        $domain->forceCheck(false)->save();
        $this->syncDnsProviderToPool($domainName, $provider, (string) ($domain->getCdnProvider() ?? ''));

        $sync = $resolveService->syncDnsRecords($domain);
        $syncError = (string) ($sync['error'] ?? '');
        if ($syncError === '') {
            $dnsDetails = $resolveService->getDnsDetails($domain);
            $dnsRecords = \is_array($dnsDetails['records'] ?? null) ? $dnsDetails['records'] : [];
            if ($dnsRecords !== []) {
                $this->syncDnsRecordsToDomainPool($domain, $dnsRecords, false);
            }
        }

        return [
            'provider_updated' => $providerUpdated,
            'sync_error' => $syncError,
        ];
    }

    /**
     * 同步 DNS/CDN 服务商到域名池
     *
     * @param string $rootDomain 根域名
     * @param string $provider DNS 服务商代码
     * @param string $cdnProvider CDN 服务商代码（可选）
     * @return int 更新的记录数
     */
    private function syncDnsProviderToPool(string $rootDomain, string $provider, string $cdnProvider = ''): int
    {
        $poolModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainPool::class);

        // fetch 返回 Model，需 getItems() 取记录列表
        $poolDomains = $poolModel->clearQuery()
            ->where(\Weline\Websites\Model\DomainPool::schema_fields_ROOT_DOMAIN, \strtolower($rootDomain))
            ->select()
            ->fetch()
            ->getItems();

        $updated = 0;
        foreach ($poolDomains as $poolDomain) {
            $changed = false;

            $currentProvider = $poolDomain->getDnsProvider();
            if ($currentProvider !== $provider) {
                $poolDomain->setDnsProvider($provider);
                $changed = true;
            }

            // 同步 CDN 服务商
            if ($cdnProvider !== '' && $poolDomain->getCdnProvider() !== $cdnProvider) {
                $poolDomain->setCdnProvider($cdnProvider);
                $changed = true;
            }

            if ($changed) {
                $poolDomain->forceCheck(false)->save();
                $updated++;
            }
        }

        return $updated;
    }

    // ============================================================
    // 批量设置 DNS/CDN 账户
    // ============================================================

    /**
     * AJAX: 批量设置域名的 DNS/CDN 账户
     *
     * 允许用户批量修改已拉取根域的 dns_account_id、cdn_account_id
     */
    #[Acl('GuoLaiRen_PageBuilder::batch_set_accounts', '批量设置账户', 'mdi mdi-account-multiple-check', '批量设置域名的 DNS/CDN 管理账户')]
    public function postBatchSetAccounts(): string
    {
        try {
            $domainIds = $this->request->getParam('domain_ids', []);
            $dnsAccountId = $this->request->getParam('dns_account_id');
            $cdnAccountId = $this->request->getParam('cdn_account_id');

            if (\is_string($domainIds)) {
                $domainIds = \json_decode($domainIds, true) ?: [];
            }

            $domainIds = \array_map('intval', \array_filter((array) $domainIds));

            if ($domainIds === []) {
                return $this->fetchJson(['success' => false, 'msg' => __('请选择要设置的域名')]);
            }

            $hasDnsAccount = $dnsAccountId !== null && $dnsAccountId !== '';
            $hasCdnAccount = $cdnAccountId !== null && $cdnAccountId !== '';

            if (!$hasDnsAccount && !$hasCdnAccount) {
                return $this->fetchJson(['success' => false, 'msg' => __('请至少选择一个账户进行设置')]);
            }

            // 验证账户存在性
            if ($hasDnsAccount && (int) $dnsAccountId > 0) {
                $dnsAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                $dnsAccount->load((int) $dnsAccountId);
                if (!$dnsAccount->getAccountId()) {
                    return $this->fetchJson(['success' => false, 'msg' => __('DNS 账户不存在')]);
                }
            }

            if ($hasCdnAccount && (int) $cdnAccountId > 0) {
                $cdnAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                $cdnAccount->load((int) $cdnAccountId);
                if (!$cdnAccount->getAccountId()) {
                    return $this->fetchJson(['success' => false, 'msg' => __('CDN 账户不存在（请确认所选账户仍存在且已启用，或在「域名商账户」中添加 CDN 服务商账户）')]);
                }
            }

            $domainModel = ObjectManager::getInstance(Domain::class);
            $updated = 0;
            $errors = [];

            foreach ($domainIds as $domainId) {
                $domain = clone $domainModel;
                $domain->clearQuery()->load($domainId);

                if (!$domain->getDomainId()) {
                    $errors[] = __('域名 ID %{1} 不存在', [$domainId]);
                    continue;
                }

                $changed = false;

                if ($hasDnsAccount) {
                    $newDnsAccountId = (int) $dnsAccountId;
                    if ($domain->getDnsAccountId() !== $newDnsAccountId) {
                        $domain->setDnsAccountId($newDnsAccountId);
                        $changed = true;
                    }
                }

                if ($hasCdnAccount) {
                    $newCdnAccountId = (int) $cdnAccountId;
                    if ($domain->getCdnAccountId() !== $newCdnAccountId) {
                        $domain->setCdnAccountId($newCdnAccountId);
                        $changed = true;
                    }
                }

                if ($changed) {
                    $domain->forceCheck(false)->save();
                    $updated++;
                }
            }

            $msg = __('批量设置完成：共 %{1} 个域名，更新 %{2} 个', [\count($domainIds), $updated]);
            if (!empty($errors)) {
                $msg .= '，' . __('失败 %{1} 个', [\count($errors)]);
            }

            return $this->fetchJson([
                'success' => true,
                'msg' => $msg,
                'data' => [
                    'total' => \count($domainIds),
                    'updated' => $updated,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('批量设置失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 获取可用于 DNS/CDN 管理的账户列表
     *
     * 返回支持 DNS 管理的账户（用于下拉选择）
     */
    #[Acl('GuoLaiRen_PageBuilder::get_dns_accounts', '获取DNS账户列表', 'mdi mdi-dns', '获取支持 DNS 管理的账户列表')]
    public function getDnsAccounts(): string
    {
        try {
            $registrarResolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $dnsDetector = ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class);
            $accountModel = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $allRows = $accountModel->clearQuery()
                ->where(DomainRegistrarAccount::schema_fields_STATUS, DomainRegistrarAccount::STATUS_ACTIVE)
                ->select()
                ->fetchArray();

            w_log_info(__('[getDnsAccounts] 活跃账户数量：%{1}', [(string) \count($allRows)]), [], 'dns_cdn_switch');

            $dnsAccounts = [];
            $cdnAccounts = [];

            foreach ($allRows as $row) {
                $accountId = (int) ($row[DomainRegistrarAccount::schema_fields_ID] ?? 0);
                if ($accountId <= 0) {
                    continue;
                }
                $registrarCode = (string) ($row['registrar_code'] ?? '');
                $registrarName = (string) ($row['registrar_name'] ?? '');
                $registrarId = (int) ($row[DomainRegistrarAccount::schema_fields_REGISTRAR_ID] ?? 0);

                if ($registrarCode === '' && $registrarId > 0) {
                    $registrar = ObjectManager::getInstance(\Weline\Websites\Model\DomainRegistrar::class, [], false);
                    $registrar->load($registrarId);
                    $registrarCode = (string) ($registrar->getData(\Weline\Websites\Model\DomainRegistrar::schema_fields_CODE) ?? '');
                    $registrarName = (string) ($registrar->getData(\Weline\Websites\Model\DomainRegistrar::schema_fields_NAME) ?? $registrarCode);
                }
                if ($registrarName === '') {
                    $registrarName = $registrarCode;
                }

                $adapter = $registrarResolver->getAdapter($registrarCode);
                $supportsDns = $adapter !== null && $adapter->supportsDnsManagement();
                $isCdn = $dnsDetector->isCdnProvider($registrarCode);

                w_log_info(__('[getDnsAccounts] 账户 ID=%{1}, name=%{2}, registrar_id=%{3}, code=%{4}, supportsDns=%{5}, isCdn=%{6}', [
                    (string) $accountId,
                    (string) ($row[DomainRegistrarAccount::schema_fields_ACCOUNT_NAME] ?? ''),
                    (string) $registrarId,
                    $registrarCode,
                    $supportsDns ? 'true' : 'false',
                    $isCdn ? 'true' : 'false',
                ]), [], 'dns_cdn_switch');

                $accountInfo = [
                    'account_id' => $accountId,
                    'name' => (string) ($row[DomainRegistrarAccount::schema_fields_ACCOUNT_NAME] ?? ''),
                    'registrar_code' => $registrarCode,
                    'registrar_name' => $registrarName,
                ];

                if ($supportsDns) {
                    $dnsAccounts[] = $accountInfo;
                }
                if ($isCdn) {
                    $cdnAccounts[] = $accountInfo;
                }
            }

            w_log_info(__('[getDnsAccounts] DNS 账户数：%{1}，CDN 账户数：%{2}', [
                (string) \count($dnsAccounts),
                (string) \count($cdnAccounts),
            ]), [], 'dns_cdn_switch');

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'dns_accounts' => $dnsAccounts,
                    'cdn_accounts' => $cdnAccounts,
                ],
            ]);
        } catch (\Throwable $e) {
            w_log_error(__('[getDnsAccounts] 异常：%{1}', [$e->getMessage()]), [], 'dns_cdn_switch');
            return $this->fetchJson([
                'success' => false,
                'msg' => __('获取账户列表失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    // ============================================================
    // 批量取消拉取（从本地删除已同步的域名）
    // ============================================================

    /**
     * 批量取消拉取域名
     *
     * 从本地删除已同步的域名记录，不影响远程域名商的域名数据。
     * 同时会删除关联的域名池记录和 DNS 解析记录。
     */
    #[Acl('GuoLaiRen_PageBuilder::batch_remove_sync', '批量取消拉取', 'mdi mdi-database-remove', '从本地移除已同步的域名')]
    public function postBatchRemoveSync(): string
    {
        try {
            // 使用 null 默认值避免 getParam 对 "[142]" 做 (array) 强转得到 ["[142]"]，导致 intval 后变空
            $domainIds = $this->request->getParam('domain_ids', null);
            if ($domainIds === null || $domainIds === '') {
                $domainIds = [];
            } elseif (\is_string($domainIds)) {
                $domainIds = \json_decode($domainIds, true);
                $domainIds = \is_array($domainIds) ? $domainIds : [];
            } elseif (!\is_array($domainIds)) {
                $domainIds = [];
            }
            $domainIds = \array_filter(\array_map('intval', $domainIds));

            if (empty($domainIds)) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('请选择要取消拉取的域名'),
                ]);
            }

            $deleted = 0;
            $poolDeleted = 0;
            $dnsDeleted = 0;
            $errors = [];

            foreach ($domainIds as $domainId) {
                try {
                    $domain = ObjectManager::getInstance(Domain::class, [], false);
                    $domain->clearQuery()->load($domainId);

                    if (!$domain->getDomainId()) {
                        continue;
                    }

                    $domainName = $domain->getDomain();

                    // 1. 删除关联的域名池记录（子域：按 parent_domain_id 与 root_domain 双条件，避免遗漏）
                    $poolModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainPool::class);
                    $rootDomainLower = \strtolower(\trim($domainName));
                    $byParent = $poolModel->clearQuery()
                        ->where(\Weline\Websites\Model\DomainPool::schema_fields_PARENT_DOMAIN_ID, $domainId)
                        ->select()
                        ->fetch()
                        ->getItems();
                    $byRoot = $poolModel->clearQuery()
                        ->where(\Weline\Websites\Model\DomainPool::schema_fields_ROOT_DOMAIN, $rootDomainLower)
                        ->select()
                        ->fetch()
                        ->getItems();
                    $seen = [];
                    foreach (\array_merge($byParent, $byRoot) as $poolRecord) {
                        $pid = $poolRecord->getData(\Weline\Websites\Model\DomainPool::schema_fields_ID);
                        if (isset($seen[$pid])) {
                            continue;
                        }
                        $seen[$pid] = true;
                        $poolRecord->delete()->fetch();
                        $poolDeleted++;
                    }

                    // 2. 删除关联的 DNS 解析记录（本地记录）
                    $dnsModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainDnsRecord::class, [], false);
                    $dnsRecords = $dnsModel->clearQuery()
                        ->where(\Weline\Websites\Model\DomainDnsRecord::schema_fields_DOMAIN_ID, $domainId)
                        ->select()
                        ->fetch()
                        ->getItems();

                    foreach ($dnsRecords as $dnsRecord) {
                        $dnsRecord->delete()->fetch();
                        $dnsDeleted++;
                    }

                    // 3. 删除域名记录（必须 fetch() 才真正执行 DELETE）
                    $domain->delete()->fetch();
                    $deleted++;
                } catch (\Throwable $e) {
                    $errors[] = "ID {$domainId}: " . $e->getMessage();
                }
            }

            $msg = __('批量取消拉取完成：删除 %{1} 个根域，%{2} 个域名池记录，%{3} 条 DNS 记录', [
                $deleted,
                $poolDeleted,
                $dnsDeleted,
            ]);

            if (!empty($errors)) {
                $msg .= ' ' . __('（%{1} 个失败）', [\count($errors)]);
            }

            return $this->fetchJson([
                'success' => empty($errors),
                'msg' => $msg,
                'data' => [
                    'deleted' => $deleted,
                    'pool_deleted' => $poolDeleted,
                    'dns_deleted' => $dnsDeleted,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('批量取消拉取失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 按账户批量取消拉取
     *
     * 删除指定账户下的所有已同步域名
     */
    #[Acl('GuoLaiRen_PageBuilder::remove_sync_by_account', '按账户取消拉取', 'mdi mdi-account-remove', '删除指定账户下的所有域名')]
    public function postRemoveSyncByAccount(): string
    {
        try {
            $accountId = (int) $this->request->getParam('account_id', 0);

            if ($accountId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('请选择域名商账户'),
                ]);
            }

            // 获取该账户下的所有域名（fetch() 返回模型，需 getItems() 取记录列表）
            $domainModel = ObjectManager::getInstance(Domain::class);
            $domains = $domainModel->clearQuery()
                ->where(Domain::schema_fields_ACCOUNT_ID, $accountId)
                ->select()
                ->fetch()
                ->getItems();

            if (empty($domains) || (\is_countable($domains) && \count($domains) === 0)) {
                return $this->fetchJson([
                    'success' => true,
                    'msg' => __('该账户下没有已同步的域名'),
                    'data' => [
                        'deleted' => 0,
                        'pool_deleted' => 0,
                        'dns_deleted' => 0,
                    ],
                ]);
            }

            $deleted = 0;
            $poolDeleted = 0;
            $dnsDeleted = 0;

            foreach ($domains as $domain) {
                $domainId = $domain->getDomainId();
                $domainName = $domain->getDomain();
                $rootDomainLower = \strtolower(\trim($domainName));

                // 1. 删除关联的域名池记录（子域：按 parent_domain_id 与 root_domain 双条件）
                $poolModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainPool::class);
                $byParent = $poolModel->clearQuery()
                    ->where(\Weline\Websites\Model\DomainPool::schema_fields_PARENT_DOMAIN_ID, $domainId)
                    ->select()
                    ->fetch()
                    ->getItems();
                $byRoot = $poolModel->clearQuery()
                    ->where(\Weline\Websites\Model\DomainPool::schema_fields_ROOT_DOMAIN, $rootDomainLower)
                    ->select()
                    ->fetch()
                    ->getItems();
                $seen = [];
                foreach (\array_merge($byParent, $byRoot) as $poolRecord) {
                    $pid = $poolRecord->getData(\Weline\Websites\Model\DomainPool::schema_fields_ID);
                    if (isset($seen[$pid])) {
                        continue;
                    }
                    $seen[$pid] = true;
                    $poolRecord->delete()->fetch();
                    $poolDeleted++;
                }

                // 2. 删除关联的 DNS 解析记录
                $dnsModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainDnsRecord::class, [], false);
                $dnsRecords = $dnsModel->clearQuery()
                    ->where(\Weline\Websites\Model\DomainDnsRecord::schema_fields_DOMAIN_ID, $domainId)
                    ->select()
                    ->fetch()
                    ->getItems();

                foreach ($dnsRecords as $dnsRecord) {
                    $dnsRecord->delete()->fetch();
                    $dnsDeleted++;
                }

                // 3. 删除域名记录（必须 fetch() 才真正执行 DELETE）
                $domain->delete()->fetch();
                $deleted++;
            }

            return $this->fetchJson([
                'success' => true,
                'msg' => __('取消拉取完成：删除 %{1} 个根域，%{2} 个域名池记录，%{3} 条 DNS 记录', [
                    $deleted,
                    $poolDeleted,
                    $dnsDeleted,
                ]),
                'data' => [
                    'deleted' => $deleted,
                    'pool_deleted' => $poolDeleted,
                    'dns_deleted' => $dnsDeleted,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('取消拉取失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 清理所有 DNS 服务商账户下的域名
     *
     * 删除所有非域名注册商（如 Cloudflare、Azure DNS）账户下的域名，
     * 这些域名不应该出现在根域列表中（它们只是托管在 DNS 服务商，实际归属于其他注册商）。
     */
    #[Acl('GuoLaiRen_PageBuilder::cleanup_dns_provider_domains', '清理DNS服务商域名', 'mdi mdi-broom', '清理误同步的DNS服务商域名')]
    public function postCleanupDnsProviderDomains(): string
    {
        try {
            $resolverService = ObjectManager::getInstance(\Weline\Websites\Service\DomainRegistrarResolverService::class);
            $registrarAccount = ObjectManager::getInstance(\Weline\Websites\Model\DomainRegistrarAccount::class);

            // 获取所有账户
            $accounts = $registrarAccount->clearQuery()
                ->select()
                ->fetch();

            $totalDeleted = 0;
            $totalPoolDeleted = 0;
            $totalDnsDeleted = 0;
            $cleanedAccounts = [];

            foreach ($accounts as $account) {
                $registrarCode = $account->getRegistrarCode();
                if (!$registrarCode) {
                    continue;
                }

                $adapter = $resolverService->getAdapter($registrarCode);
                if (!$adapter) {
                    continue;
                }

                // 跳过真正的域名注册商
                if ($adapter->isDomainRegistrar()) {
                    continue;
                }

                $accountId = $account->getAccountId();
                $accountName = $account->getAccountName();

                // 获取该账户下的所有域名（fetch() 返回模型，需 getItems() 取记录列表）
                $domainModel = ObjectManager::getInstance(Domain::class);
                $domains = $domainModel->clearQuery()
                    ->where(Domain::schema_fields_ACCOUNT_ID, $accountId)
                    ->select()
                    ->fetch()
                    ->getItems();

                if (empty($domains)) {
                    continue;
                }

                $deleted = 0;
                $poolDeleted = 0;
                $dnsDeleted = 0;

                foreach ($domains as $domain) {
                    $domainId = $domain->getDomainId();
                    $domainName = $domain->getDomain();
                    $rootDomainLower = \strtolower(\trim($domainName));

                    // 删除关联的域名池记录（子域：按 parent_domain_id 与 root_domain 双条件）
                    $poolModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainPool::class);
                    $byParent = $poolModel->clearQuery()
                        ->where(\Weline\Websites\Model\DomainPool::schema_fields_PARENT_DOMAIN_ID, $domainId)
                        ->select()
                        ->fetch()
                        ->getItems();
                    $byRoot = $poolModel->clearQuery()
                        ->where(\Weline\Websites\Model\DomainPool::schema_fields_ROOT_DOMAIN, $rootDomainLower)
                        ->select()
                        ->fetch()
                        ->getItems();
                    $seen = [];
                    foreach (\array_merge($byParent, $byRoot) as $poolRecord) {
                        $pid = $poolRecord->getData(\Weline\Websites\Model\DomainPool::schema_fields_ID);
                        if (isset($seen[$pid])) {
                            continue;
                        }
                        $seen[$pid] = true;
                        $poolRecord->delete()->fetch();
                        $poolDeleted++;
                    }

                    // 删除关联的 DNS 解析记录
                    $dnsModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainDnsRecord::class, [], false);
                    $dnsRecords = $dnsModel->clearQuery()
                        ->where(\Weline\Websites\Model\DomainDnsRecord::schema_fields_DOMAIN_ID, $domainId)
                        ->select()
                        ->fetch()
                        ->getItems();

                    foreach ($dnsRecords as $dnsRecord) {
                        $dnsRecord->delete()->fetch();
                        $dnsDeleted++;
                    }

                    // 删除域名记录（必须 fetch() 才真正执行 DELETE）
                    $domain->delete()->fetch();
                    $deleted++;
                }

                if ($deleted > 0) {
                    $cleanedAccounts[] = [
                        'account_name' => $accountName,
                        'registrar_name' => $adapter->getRegistrarName(),
                        'deleted' => $deleted,
                        'pool_deleted' => $poolDeleted,
                        'dns_deleted' => $dnsDeleted,
                    ];
                    $totalDeleted += $deleted;
                    $totalPoolDeleted += $poolDeleted;
                    $totalDnsDeleted += $dnsDeleted;
                }
            }

            if (empty($cleanedAccounts)) {
                return $this->fetchJson([
                    'success' => true,
                    'msg' => __('没有需要清理的 DNS 服务商域名'),
                    'data' => [
                        'cleaned_accounts' => [],
                        'total_deleted' => 0,
                    ],
                ]);
            }

            return $this->fetchJson([
                'success' => true,
                'msg' => __('清理完成：共删除 %{1} 个误同步的域名（来自 %{2} 个 DNS 服务商账户）', [
                    $totalDeleted,
                    \count($cleanedAccounts),
                ]),
                'data' => [
                    'cleaned_accounts' => $cleanedAccounts,
                    'total_deleted' => $totalDeleted,
                    'total_pool_deleted' => $totalPoolDeleted,
                    'total_dns_deleted' => $totalDnsDeleted,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('清理失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }
}
