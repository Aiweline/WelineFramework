<?php
declare(strict_types=1);

/**
 * 域名管理控制器
 *
 * 域名服务主控制器，提供 Tab 页面管理：
 * - Tab1：域名商管理（域名商账号 CRUD、测试连接）
 * - Tab2：域名购买（批量检查可用性、批量购买、绑定站点）
 * - Tab3：证书管理（通过 Hook 由 Server 模块注入）
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Controller\Admin;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain as DomainModel;
use Weline\Websites\Model\DomainConfig;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainDnsRecord;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Service\DnsSwitchService;
use Weline\Websites\Service\DomainPoolFlowLogService;
use Weline\Websites\Service\DomainPoolMaintenanceService;
use Weline\Websites\Service\DomainRegistrarResolverService;
use Weline\Websites\Service\DomainParserService;
use Weline\Websites\Service\DomainOriginMatchService;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\DomainSyncService;
use Weline\Websites\Service\ServerIpService;

#[Acl('Weline_Websites::domain_service', '域名服务', 'mdi mdi-domain', '域名服务管理', 'Weline_Websites::website_service')]
class Domain extends BackendController
{
    private DomainRegistrar $registrar;
    private DomainRegistrarAccount $registrarAccount;
    private DomainRegistrarResolverService $resolverService;
    private DomainModel $domainModel;
    private DomainConfig $domainConfig;
    private DomainResolveService $resolveService;
    private DomainOriginMatchService $originMatch;
    private DomainParserService $domainParserService;
    private DomainSyncService $syncService;
    private ServerIpService $serverIpService;
    private DnsSwitchService $dnsSwitchService;

    public function __construct(
        DomainRegistrar $registrar,
        DomainRegistrarAccount $registrarAccount,
        DomainRegistrarResolverService $resolverService,
        DomainModel $domainModel,
        DomainConfig $domainConfig,
        DomainResolveService $resolveService,
        DomainOriginMatchService $originMatch,
        DomainParserService $domainParserService,
        DomainSyncService $syncService,
        ServerIpService $serverIpService,
        DnsSwitchService $dnsSwitchService
    ) {
        $this->registrar = $registrar;
        $this->registrarAccount = $registrarAccount;
        $this->resolverService = $resolverService;
        $this->domainModel = $domainModel;
        $this->domainConfig = $domainConfig;
        $this->resolveService = $resolveService;
        $this->originMatch = $originMatch;
        $this->domainParserService = $domainParserService;
        $this->syncService = $syncService;
        $this->serverIpService = $serverIpService;
        $this->dnsSwitchService = $dnsSwitchService;
    }

    /**
     * 检测系统定时任务是否已安装
     * 调度器和平台脚本检测由 Cron 模块自己负责。
     */
    private function isCronInstalled(): bool
    {
        try {
            $status = w_query('cron', 'getInstallationStatus', ['scope' => 'Weline_Cron']);
            return is_array($status) && !empty($status['installed']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 格式化异常为完整错误信息（便于排查具体原因）
     */
    private function formatThrowableFull(\Throwable $e): string
    {
        $parts = [
            \get_class($e) . ': ' . $e->getMessage(),
            $e->getFile() . ':' . $e->getLine(),
        ];
        $out = \implode(' @ ', $parts);
        if ($e->getPrevious() instanceof \Throwable) {
            $out .= ' | Caused by: ' . $this->formatThrowableFull($e->getPrevious());
        }
        return $out;
    }

    /**
     * 为域名池列表行补充注册商 / DNS / CDN 展示名
     *
     * @param list<array<string, mixed>> $poolRows
     * @return list<array<string, mixed>>
     */
    private function enrichDomainPoolRows(array $poolRows): array
    {
        if ($poolRows === []) {
            return [];
        }
        $parentIds = \array_unique(\array_filter(\array_column($poolRows, DomainPool::schema_fields_PARENT_DOMAIN_ID)));
        $parentDomainMap = [];
        if ($parentIds !== []) {
            $parentDomains = $this->domainModel->clearQuery()
                ->where(DomainModel::schema_fields_ID, $parentIds, 'IN')
                ->select()
                ->fetchArray();
            foreach ($parentDomains as $d) {
                $parentDomainMap[(int) ($d[DomainModel::schema_fields_ID] ?? 0)] = $d;
            }
        }
        $dnsDetector = ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class);
        $accountCache = [];
        foreach ($poolRows as &$poolRow) {
            $pid = (int) ($poolRow[DomainPool::schema_fields_PARENT_DOMAIN_ID] ?? 0);
            $parent = $parentDomainMap[$pid] ?? null;
            $poolRow['_registrar_name'] = '-';
            $poolRow['_dns_provider_name'] = '-';
            $poolRow['_cdn_provider_name'] = '-';
            if ($parent) {
                $accId = (int) ($parent[DomainModel::schema_fields_ACCOUNT_ID] ?? 0);
                if ($accId > 0) {
                    if (!isset($accountCache[$accId])) {
                        $acc = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                        $acc->load($accId);
                        $accountCache[$accId] = [
                            'registrar_code' => $acc->getRegistrarCode() ?: '',
                            'registrar_name' => '',
                        ];
                        if ($accountCache[$accId]['registrar_code']) {
                            $adapter = $this->resolverService->getAdapter($accountCache[$accId]['registrar_code']);
                            $accountCache[$accId]['registrar_name'] = $adapter ? $adapter->getRegistrarName() : $accountCache[$accId]['registrar_code'];
                        }
                    }
                    $poolRow['_registrar_name'] = $accountCache[$accId]['registrar_name'] ?: '-';
                }
                $dnsCode = $parent[DomainModel::schema_fields_DNS_PROVIDER] ?? $poolRow[DomainPool::schema_fields_DNS_PROVIDER] ?? '';
                $cdnCode = $parent[DomainModel::schema_fields_CDN_PROVIDER] ?? '';
                if ($dnsCode) {
                    $info = $dnsDetector->getProviderInfo($dnsCode);
                    $poolRow['_dns_provider_name'] = $info['name'] ?? $dnsCode;
                }
                if ($cdnCode) {
                    $info = $dnsDetector->getProviderInfo($cdnCode);
                    $poolRow['_cdn_provider_name'] = $info['name'] ?? $cdnCode;
                }
            }
        }
        unset($poolRow);

        $flowSvc = ObjectManager::getInstance(DomainPoolFlowLogService::class);
        $pids = \array_values(\array_filter(\array_map('intval', \array_column($poolRows, DomainPool::schema_fields_ID))));
        $recentMap = $flowSvc->getRecentByPoolIds($pids, 12);
        foreach ($poolRows as &$poolRow) {
            $pid = (int) ($poolRow[DomainPool::schema_fields_ID] ?? 0);
            $poolRow['_flow_html'] = $flowSvc->buildFlowDisplayHtml($poolRow, $recentMap[$pid] ?? []);
        }
        unset($poolRow);

        return $poolRows;
    }

    // ============================================================
    // 域名管理主页
    // ============================================================

    /**
     * 域名管理主页（Tab 布局）
     */
    #[Acl('Weline_Websites::domain_index', '域名管理', 'mdi mdi-domain', '域名管理首页')]
    public function index()
    {
        // 获取已注册的适配器列表（供前端下拉选择使用）
        $adapterOptions = $this->resolverService->getAdapterOptions();
        $this->assign('adapter_options', $adapterOptions);

        // 获取所有账号（含域名商信息）
        $accounts = $this->registrarAccount->getAccountsWithRegistrar();
        $this->assign('accounts', $accounts);

        // 获取所有域名商
        $registrars = $this->registrar->getAllRegistrars();
        $this->assign('registrars', $registrars);

        // 获取网站列表（供购买时绑定选择）
        $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
        $websiteModel->clearData(true);
        $websites = $websiteModel->order('name', 'ASC')->select()->fetchArray();
        $this->assign('websites', $websites);

        // 当前 Tab
        $this->assign('active_tab', $this->request->getGet('tab', 'domain_list'));

        $this->assign('cronInstalled', $this->isCronInstalled());

        // 域名列表（初始数据，前端会通过 AJAX 加载分页）
        $domains = $this->domainModel->clearQuery()
            ->order(DomainModel::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();
        $this->assign('domains', \array_slice($domains, 0, 20));

        // 域名池：分页与总数（Tab 内可翻页或 AJAX）
        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $poolLimit = 20;
        $poolPage = max(1, (int) $this->request->getGet('pool_page', 1));
        $poolTotal = (int) $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE)
            ->count();
        $poolRows = $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE)
            ->order(DomainPool::schema_fields_ROOT_DOMAIN, 'ASC')
            ->order(DomainPool::schema_fields_DOMAIN, 'ASC')
            ->pagination($poolPage, $poolLimit)
            ->select()
            ->fetchArray();
        $this->assign('domain_pool_rows', $this->enrichDomainPoolRows($poolRows));
        $this->assign('domain_pool_total', $poolTotal);
        $this->assign('domain_pool_page', $poolPage);
        $this->assign('domain_pool_limit', $poolLimit);
        $this->assign('domain_pool_pages', max(1, (int) \ceil($poolTotal / $poolLimit)));

        return $this->fetch();
    }

    /**
     * 域名池列表（分页，供 Tab AJAX）
     */
    #[Acl('Weline_Websites::domain_index', '域名管理', 'mdi mdi-domain', '域名管理首页')]
    public function getDomainPoolList()
    {
        try {
            $page = max(1, (int) $this->request->getGet('page', 1));
            $pageSize = min(100, max(5, (int) ($this->request->getGet('page_size') ?: $this->request->getGet('limit', 20))));
            $poolModel = ObjectManager::getInstance(DomainPool::class);
            $total = (int) $poolModel->clearQuery()
                ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE)
                ->count();
            $poolRows = $poolModel->clearQuery()
                ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE)
                ->order(DomainPool::schema_fields_ROOT_DOMAIN, 'ASC')
                ->order(DomainPool::schema_fields_DOMAIN, 'ASC')
                ->pagination($page, $pageSize)
                ->select()
                ->fetchArray();
            $items = $this->enrichDomainPoolRows($poolRows);
            $pages = max(1, (int) \ceil($total / $pageSize));

            return $this->fetchJson([
                'code' => 200,
                'data' => [
                    'items' => $items,
                    'total' => $total,
                    'page' => $page,
                    'pages' => $pages,
                    'limit' => $pageSize,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取域名池列表失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 域名池流转记录（时间线）
     */
    #[Acl('Weline_Websites::domain_index', '域名管理', 'mdi mdi-domain', '域名管理首页')]
    public function getPoolFlowLog()
    {
        try {
            $poolId = (int) $this->request->getGet('pool_id', 0);
            if ($poolId <= 0) {
                return $this->fetchJson(['code' => 400, 'msg' => __('参数错误')]);
            }
            $model = ObjectManager::getInstance(\Weline\Websites\Model\DomainPoolFlowLog::class);
            $rows = $model->clearQuery()
                ->where(\Weline\Websites\Model\DomainPoolFlowLog::schema_fields_POOL_ID, $poolId)
                ->order(\Weline\Websites\Model\DomainPoolFlowLog::schema_fields_ID, 'DESC')
                ->limit(80)
                ->select()
                ->fetchArray();
            $items = [];
            foreach ($rows as $r) {
                $items[] = [
                    'at' => (string) ($r[\Weline\Websites\Model\DomainPoolFlowLog::schema_fields_CREATED_AT] ?? ''),
                    'kind' => (string) ($r[\Weline\Websites\Model\DomainPoolFlowLog::schema_fields_EVENT_KIND] ?? ''),
                    'message' => (string) ($r[\Weline\Websites\Model\DomainPoolFlowLog::schema_fields_MESSAGE] ?? ''),
                ];
            }

            return $this->fetchJson(['code' => 200, 'data' => ['items' => $items]]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 清理域名池中误同步的非法记录（如含 [] 的域名串）
     */
    #[Acl('Weline_Websites::domain_index', '域名管理', 'mdi mdi-domain', '域名管理首页')]
    public function postCleanPoolInvalidDomains()
    {
        try {
            $dryRun = \filter_var($this->request->getPost('dry_run', ''), FILTER_VALIDATE_BOOLEAN)
                || $this->request->getPost('dry_run') === '1';
            $svc = ObjectManager::getInstance(DomainPoolMaintenanceService::class);
            $r = $svc->cleanInvalidPoolDomains($dryRun);

            return $this->fetchJson([
                'code' => 200,
                'msg' => $dryRun
                    ? __('试运行：发现 %{1} 条可清理记录', [(string) $r['deleted']])
                    : __('已清理 %{1} 条非法域名池记录', [(string) $r['deleted']]),
                'data' => $r,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('清理失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 根域 @ 上未指向本机公网的 A/AAAA（非 CF 橙云）从 DNS 商删除，并移除该根下域名池条目
     */
    #[Acl('Weline_Websites::domain_index', '域名管理', 'mdi mdi-domain', '域名管理首页')]
    public function postCleanPoolMispointedApexDns()
    {
        try {
            $dryRun = \filter_var($this->request->getPost('dry_run', ''), FILTER_VALIDATE_BOOLEAN)
                || $this->request->getPost('dry_run') === '1';
            $svc = ObjectManager::getInstance(DomainPoolMaintenanceService::class);
            $r = $svc->cleanMispointedApexDnsAndPool($dryRun);
            $nDns = \count($r['dns_deleted']);
            $msg = $dryRun
                ? __('将删除 %{n} 条 DNS 记录，并移除 %{p} 条域名池记录（试运行）', ['n' => (string) $nDns, 'p' => (string) $r['pool_removed']])
                : __('已删除 %{n} 条 DNS 记录，已移除 %{p} 条域名池记录', ['n' => (string) $nDns, 'p' => (string) $r['pool_removed']]);

            return $this->fetchJson([
                'code' => 200,
                'msg' => $msg,
                'data' => $r,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('操作失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    // ============================================================
    // 域名商管理 API
    // ============================================================

    /**
     * 获取域名商列表（AJAX）
     */
    public function getRegistrars()
    {
        try {
            $registrars = $this->registrar->getAllRegistrars();
            return $this->fetchJson([
                'code' => 200,
                'data' => $registrars,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取域名商列表失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取域名商适配器配置信息（AJAX）
     */
    public function postRegistrarInfo()
    {
        $registrarCode = trim($this->request->getPost('registrar_code', '') ?? '');
        if ($registrarCode === '') {
            return $this->fetchJson([
                'code' => 400,
                'msg' => __('域名商代码不能为空'),
            ]);
        }

        $adapter = $this->resolverService->getAdapter($registrarCode);
        if (!$adapter) {
            return $this->fetchJson([
                'code' => 404,
                'msg' => __('未找到域名商适配器'),
            ]);
        }

        return $this->fetchJson([
            'code' => 200,
            'data' => [
                'name' => $adapter->getRegistrarName(),
                'description' => $adapter->getDescription(),
                'config_fields' => $adapter->getConfigFields(),
                'config_help' => $adapter->getConfigHelp(),
            ],
        ]);
    }

    /**
     * 获取某域名商的账号列表（AJAX）
     */
    public function getAccounts()
    {
        try {
            $registrarId = (int) $this->request->getGet('registrar_id', 0);
            if ($registrarId > 0) {
                $accounts = $this->registrarAccount->getAccountsByRegistrarId($registrarId);
            } else {
                $accounts = $this->registrarAccount->getAccountsWithRegistrar();
            }
            return $this->fetchJson([
                'code' => 200,
                'data' => $accounts,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取账号列表失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 保存域名商账号（新增或编辑）
     */
    public function postSaveAccount()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            $registrarCode = (string) $this->request->getPost('registrar_code', '');
            $accountName = (string) $this->request->getPost('account_name', '');
            $apiKey = (string) $this->request->getPost('api_key', '');
            $apiSecret = (string) $this->request->getPost('api_secret', '');
            $region = (string) $this->request->getPost('region', '');
            $extraConfig = (string) $this->request->getPost('extra_config', '');
            $status = (string) $this->request->getPost('status', DomainRegistrarAccount::STATUS_ACTIVE);

            if (empty($registrarCode) || empty($accountName)) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('域名商渠道和账号名称不能为空'),
                ]);
            }

            // 查找或创建域名商记录
            $registrarModel = clone $this->registrar;
            $registrarModel->loadByCode($registrarCode);
            $adapter = $this->resolverService->getAdapter($registrarCode);
            if (!$adapter) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('未找到域名商适配器：%{code}', ['code' => $registrarCode]),
                ]);
            }
            
            if (!$registrarModel->getRegistrarId()) {
                // 从适配器获取信息并自动创建
                $registrarModel->clearData();
                $registrarModel->setCode($registrarCode);
                $registrarModel->setName($adapter->getRegistrarName());
                $registrarModel->setDescription($adapter->getDescription());
                $registrarModel->setStatus(DomainRegistrar::STATUS_ACTIVE);
                $registrarModel->save();
            }

            // 根据适配器配置校验必填字段
            $extraConfigArr = !empty($extraConfig) ? (\json_decode($extraConfig, true) ?: []) : [];
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
                
                if ($mapping === 'api_key') {
                    $value = $apiKey;
                } elseif ($mapping === 'api_secret') {
                    $value = $apiSecret;
                } elseif ($mapping === 'region') {
                    $value = $region;
                } elseif (\str_starts_with($mapping, 'extra_config.')) {
                    $extraKey = \str_replace('extra_config.', '', $mapping);
                    $value = trim((string) ($extraConfigArr[$extraKey] ?? ''));
                } else {
                    $value = trim((string) ($extraConfigArr[$fieldName] ?? ''));
                }
                
                if ($value === '') {
                    $missingFields[] = $field['label'] ?? $fieldName;
                }
            }
            
            if (!empty($missingFields)) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请填写必填字段：%{1}', [\implode(', ', $missingFields)]),
                ]);
            }

            // 保存账号
            $accountModel = clone $this->registrarAccount;
            if ($accountId > 0) {
                $accountModel->load($accountId);
                if (!$accountModel->getAccountId()) {
                    return $this->fetchJson([
                        'code' => 404,
                        'msg' => __('账号不存在'),
                    ]);
                }
            }

            $accountModel->setRegistrarId($registrarModel->getRegistrarId());
            $accountModel->setAccountName($accountName);

            // 仅在提供了新值时更新凭据（编辑时可能不改密码）
            if (!empty($apiKey)) {
                $accountModel->setApiKey($apiKey);
            }
            if (!empty($apiSecret)) {
                $accountModel->setApiSecret($apiSecret);
            }

            $accountModel->setRegion($region);
            $accountModel->setStatus($status);

            if (!empty($extraConfig)) {
                $decoded = \json_decode($extraConfig, true);
                if (\is_array($decoded)) {
                    $accountModel->setExtraConfig($decoded);
                }
            }

            $accountModel->save();

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('保存成功'),
                'data' => [
                    'account_id' => $accountModel->getAccountId(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('保存失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 删除域名商账号
     */
    public function deleteAccount()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            if ($accountId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('账号 ID 不能为空'),
                ]);
            }

            $accountModel = clone $this->registrarAccount;
            $accountModel->load($accountId);
            if (!$accountModel->getAccountId()) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('账号不存在'),
                ]);
            }

            $accountModel->delete()->fetch();

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('删除成功'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('删除失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 测试 API 连通性
     */
    public function postTestConnection()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            $registrarCode = (string) $this->request->getPost('registrar_code', '');

            // 获取适配器
            $adapter = $this->resolverService->getAdapter($registrarCode);
            if (!$adapter) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('未找到域名商适配器：%{code}', ['code' => $registrarCode]),
                ]);
            }

            // 获取凭据
            $credentials = [];
            if ($accountId > 0) {
                $accountModel = clone $this->registrarAccount;
                $accountModel->load($accountId);
                if ($accountModel->getAccountId()) {
                    $credentials = $accountModel->getCredentials();
                }
            } else {
                // 从 POST 参数获取临时凭据
                $credentials = [
                    'api_key' => (string) $this->request->getPost('api_key', ''),
                    'api_secret' => (string) $this->request->getPost('api_secret', ''),
                    'region' => (string) $this->request->getPost('region', ''),
                    'extra' => [],
                ];
            }

            $result = $adapter->testConnection($credentials);

            return $this->fetchJson([
                'code' => $result ? 200 : 400,
                'msg' => $result ? __('连接测试成功') : __('连接测试失败'),
            ]);
        } catch (\RuntimeException $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg' => __('连接测试失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('连接测试异常：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取适配器列表（供前端下拉使用，AJAX）
     */
    public function getAdapterOptions()
    {
        try {
            $options = $this->resolverService->getAdapterOptions();
            return $this->fetchJson([
                'code' => 200,
                'data' => $options,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取适配器列表失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    // ============================================================
    // 域名购买 API
    // ============================================================

    /**
     * 批量检查域名可用性（AJAX）
     */
    public function postCheckAvailability()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            $domainsText = (string) $this->request->getPost('domains', '');

            if (empty($domainsText) || $accountId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择域名商账号并输入域名'),
                ]);
            }

            // 解析域名列表
            $domains = \array_filter(\array_map('trim', \explode("\n", $domainsText)));
            if (empty($domains)) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('域名列表不能为空'),
                ]);
            }

            // 获取账号和适配器
            $accountModel = clone $this->registrarAccount;
            $accountModel->load($accountId);
            if (!$accountModel->getAccountId()) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('域名商账号不存在'),
                ]);
            }

            $registrarModel = clone $this->registrar;
            $registrarModel->load($accountModel->getRegistrarId());
            $adapter = $this->resolverService->getAdapter($registrarModel->getCode());
            if (!$adapter) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('未找到对应的域名商适配器'),
                ]);
            }

            $credentials = $accountModel->getCredentials();
            $results = $adapter->batchCheckAvailability($domains, $credentials);

            return $this->fetchJson([
                'code' => 200,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('检查可用性失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 提交批量购买（AJAX）
     */
    public function postPurchase()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            $itemsRaw = $this->request->getPost('items', []);
            $items = \is_string($itemsRaw) ? (json_decode($itemsRaw, true) ?: []) : (array) $itemsRaw;
            $autoResolve = $this->request->getPost('auto_resolve', '0') === '1';
            $resolveToLocal = $this->request->getPost('resolve_to_local', $autoResolve ? 'yes' : 'no');
            $subdomainsRaw = $this->request->getPost('subdomains', '');
            $dnsChoice = (string) $this->request->getPost('dns_choice', 'follow_registrar');
            $dnsProvider = (string) $this->request->getPost('dns_provider', '');
            $dnsAccountId = (int) $this->request->getPost('dns_account_id', 0);
            $dnsNameservers = (string) $this->request->getPost('dns_nameservers', '');
            $cdnChoice = (string) $this->request->getPost('cdn_choice', 'follow_registrar');
            $cdnProvider = (string) $this->request->getPost('cdn_provider', '');
            $cdnAccountId = (int) $this->request->getPost('cdn_account_id', 0);
            $startLifecycle = (string) $this->request->getPost('start_lifecycle', '1');
            $subdomains = \is_string($subdomainsRaw) ? (json_decode($subdomainsRaw, true) ?: \array_map('trim', \explode(',', $subdomainsRaw))) : (array) $subdomainsRaw;
            if ($subdomains === []) {
                $subdomains = ['@', 'www'];
            }
            $purchaseContactPost = $this->request->getPost('purchase_contact', '');
            $purchaseContactGlobal = [];
            if (\is_string($purchaseContactPost) && $purchaseContactPost !== '') {
                $purchaseContactGlobal = \json_decode($purchaseContactPost, true) ?: [];
            } elseif (\is_array($purchaseContactPost)) {
                $purchaseContactGlobal = $purchaseContactPost;
            }

            if ($items === [] || $accountId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择域名商账号并添加购买域名'),
                ]);
            }

            foreach ($items as &$it) {
                if (!isset($it['resolve_to_local'])) {
                    $it['resolve_to_local'] = ($resolveToLocal ?? 'yes') === 'yes' ? 'yes' : 'no';
                }
                if (!isset($it['subdomains'])) {
                    $it['subdomains'] = $subdomains;
                }
                if (!isset($it['dns_choice'])) {
                    $it['dns_choice'] = $dnsChoice;
                }
                if (!isset($it['dns_nameservers'])) {
                    $it['dns_nameservers'] = $dnsNameservers;
                }
                if (!isset($it['dns_provider'])) {
                    $it['dns_provider'] = $dnsProvider;
                }
                if (!isset($it['dns_account_id'])) {
                    $it['dns_account_id'] = $dnsAccountId;
                }
                if (!isset($it['cdn_choice'])) {
                    $it['cdn_choice'] = $cdnChoice;
                }
                if (!isset($it['cdn_provider'])) {
                    $it['cdn_provider'] = $cdnProvider;
                }
                if (!isset($it['cdn_account_id'])) {
                    $it['cdn_account_id'] = $cdnAccountId;
                }
                if (!isset($it['start_lifecycle'])) {
                    $it['start_lifecycle'] = $startLifecycle;
                }
                if ($purchaseContactGlobal !== []) {
                    $existingPc = [];
                    if (isset($it['purchase_contact'])) {
                        $rawPc = $it['purchase_contact'];
                        $existingPc = \is_string($rawPc) ? (\json_decode($rawPc, true) ?: []) : (array) $rawPc;
                    }
                    $it['purchase_contact'] = \array_merge($purchaseContactGlobal, $existingPc);
                }
                $cip = \trim((string) $this->request->getClientIp());
                if ($cip !== '' && \filter_var($cip, FILTER_VALIDATE_IP)) {
                    $it['user_client_ip'] = $cip;
                }
            }
            unset($it);

            $purchaseService = ObjectManager::getInstance(
                \Weline\Websites\Service\DomainPurchaseService::class
            );

            $result = $purchaseService->createAndProcessOrder($accountId, $items, $autoResolve);

            return $this->fetchJson([
                'code' => $result['success'] ? 200 : 400,
                'msg' => $result['message'],
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('购买失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取购买订单列表（AJAX）
     */
    public function getOrders()
    {
        try {
            $orderModel = ObjectManager::getInstance(
                \Weline\Websites\Model\DomainPurchaseOrder::class
            );
            $orders = $orderModel->clearQuery()
                ->order('created_at', 'DESC')
                ->pagination()
                ->select()
                ->fetch();

            return $this->fetchJson([
                'code' => 200,
                'data' => $orders->getItems(),
                'pagination' => $orders->getPagination(),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取订单列表失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取订单详情（AJAX）
     */
    public function getOrderDetail()
    {
        try {
            $orderId = (int) $this->request->getGet('order_id', 0);
            if ($orderId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('订单 ID 不能为空'),
                ]);
            }

            $orderModel = ObjectManager::getInstance(
                \Weline\Websites\Model\DomainPurchaseOrder::class
            );
            $orderModel->load($orderId);
            if (!$orderModel->getData('order_id')) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('订单不存在'),
                ]);
            }

            $itemModel = ObjectManager::getInstance(
                \Weline\Websites\Model\DomainPurchaseItem::class
            );
            $items = $itemModel->clearQuery()
                ->where('order_id', $orderId)
                ->select()
                ->fetchArray();

            return $this->fetchJson([
                'code' => 200,
                'data' => [
                    'order' => $orderModel->getData(),
                    'items' => $items,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取订单详情失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    // ============================================================
    // 域名列表管理 API（v1.5.0 新增）
    // ============================================================

    /**
     * 获取域名列表（分页、筛选）
     */
    #[Acl('Weline_Websites::domain_list', '域名列表', 'mdi mdi-format-list-bulleted', '查看域名列表')]
    public function getDomainList()
    {
        try {
            $page = (int) $this->request->getGet('page', 1);
            $pageSize = (int) ($this->request->getGet('page_size') ?: $this->request->getGet('limit', 20));
            $pageSize = min($pageSize, 100);
            $accountId = (int) $this->request->getGet('account_id', 0);
            $status = (string) $this->request->getGet('status', '');
            $search = (string) $this->request->getGet('search', '');
            $resolveStatus = (string) $this->request->getGet('resolve_status', '');
            $httpsStatus = (string) $this->request->getGet('https_status', '');

            $filters = [
                'account_id' => $accountId,
                'status' => $status,
                'search' => $search,
            ];

            $result = $this->domainModel->getPagedList($filters, $page, $pageSize);

            // 补充注册商名称和服务商名称
            $dnsDetector = ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class);
            $accountCache = [];

            foreach ($result['items'] as &$item) {
                // 获取注册商名称
                $accId = (int) ($item['account_id'] ?? 0);
                if ($accId > 0) {
                    if (!isset($accountCache[$accId])) {
                        $acc = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                        $acc->load($accId);
                        $accountCache[$accId] = [
                            'account_name' => $acc->getAccountName() ?: '',
                            'registrar_code' => $acc->getRegistrarCode() ?: '',
                            'registrar_name' => '',
                        ];
                        if ($accountCache[$accId]['registrar_code']) {
                            $adapter = $this->resolverService->getAdapter($accountCache[$accId]['registrar_code']);
                            if ($adapter) {
                                $accountCache[$accId]['registrar_name'] = $adapter->getRegistrarName();
                            }
                        }
                    }
                    $item['account_name'] = $accountCache[$accId]['account_name'];
                    $item['registrar_name'] = $accountCache[$accId]['registrar_name'] ?: $accountCache[$accId]['account_name'];
                    $item['registrar_code'] = $accountCache[$accId]['registrar_code'];
                } else {
                    $item['account_name'] = '';
                    $item['registrar_name'] = '-';
                    $item['registrar_code'] = '';
                }

                // DNS 服务商名称
                $dnsProvider = $item['dns_provider'] ?? '';
                if ($dnsProvider) {
                    $dnsInfo = $dnsDetector->getProviderInfo($dnsProvider);
                    $item['dns_provider_name'] = $dnsInfo['name'] ?? $dnsProvider;
                } else {
                    $item['dns_provider_name'] = '-';
                }

                // CDN 服务商名称
                $cdnProvider = $item['cdn_provider'] ?? '';
                if ($cdnProvider) {
                    $cdnInfo = $dnsDetector->getProviderInfo($cdnProvider);
                    $item['cdn_provider_name'] = $cdnInfo['name'] ?? $cdnProvider;
                } else {
                    $item['cdn_provider_name'] = '-';
                }
            }
            unset($item);

            // 补充「已建站」与「可建站」：根域下池子域名状态
            $domainIds = array_filter(array_column($result['items'], 'domain_id'));
            $parentIdsWithSiteCreated = [];
            $parentIdsWithSiteReady = [];
            if ($domainIds !== []) {
                $poolModel = ObjectManager::getInstance(DomainPool::class);
                $poolModel->clearQuery()
                    ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $domainIds, 'IN')
                    ->where(DomainPool::schema_fields_SITE_CREATED, 1);
                $rows = $poolModel->fields(DomainPool::schema_fields_PARENT_DOMAIN_ID)->select()->fetchArray();
                $parentIdsWithSiteCreated = array_unique(array_column($rows, DomainPool::schema_fields_PARENT_DOMAIN_ID));

                $poolModel->clearQuery()
                    ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $domainIds, 'IN')
                    ->where(DomainPool::schema_fields_SITE_READY, 1);
                $rowsReady = $poolModel->fields(DomainPool::schema_fields_PARENT_DOMAIN_ID)->select()->fetchArray();
                $parentIdsWithSiteReady = array_unique(array_column($rowsReady, DomainPool::schema_fields_PARENT_DOMAIN_ID));
            }
            foreach ($result['items'] as &$it) {
                $rootId = (int) ($it['domain_id'] ?? 0);
                $it['site_created'] = in_array($rootId, $parentIdsWithSiteCreated, true) ? 1 : 0;
                // 根域可建站：自身 site_ready 或 至少一个池子域名可建站
                $it['site_ready'] = (int) ($it['site_ready'] ?? 0) || (in_array($rootId, $parentIdsWithSiteReady, true) ? 1 : 0);
            }
            unset($it);

            return $this->fetchJson([
                'code' => 200,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取域名列表失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取远程域名列表（仅查询，不自动导入）
     */
    #[Acl('Weline_Websites::domain_sync', '同步域名', 'mdi mdi-sync', '从域名商获取域名列表')]
    public function getRemoteDomains()
    {
        try {
            $accountId = (int) $this->request->getGet('account_id', 0);
            if ($accountId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择域名商账户'),
                ]);
            }

            $result = $this->syncService->fetchRemoteDomains($accountId);

            return $this->fetchJson([
                'code' => $result['success'] ? 200 : 400,
                'msg' => $result['message'],
                'data' => [
                    'domains' => $result['domains'] ?? [],
                    'account_name' => $result['account_name'] ?? '',
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 手动导入选中的域名到本地
     */
    #[Acl('Weline_Websites::domain_import', '导入域名', 'mdi mdi-import', '导入域名到本地')]
    public function postImportDomains()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            $domains = $this->request->getPost('domains', []);
            $resolveMode = $this->request->getPost('resolve_mode', '');
            $autoResolve = $this->request->getPost('auto_resolve', '0') === '1';

            if ($accountId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择域名商账户'),
                ]);
            }

            if (!\is_array($domains) || $domains === []) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择要导入的域名'),
                ]);
            }

            $bindDns = (int) $this->request->getPost('bind_dns_account_id', 0);
            $bindCdn = (int) $this->request->getPost('bind_cdn_account_id', 0);

            $sync = $this->syncService;
            $mode = ($resolveMode === $sync::RESOLVE_MODE_KEEP_EACH_DNS || $resolveMode === $sync::RESOLVE_MODE_BATCH_TO_LOCAL)
                ? $resolveMode
                : ($autoResolve ? $sync::RESOLVE_MODE_BATCH_TO_LOCAL : $sync::RESOLVE_MODE_KEEP_EACH_DNS);
            $result = $sync->importDomains($accountId, $domains, $mode, $bindDns, $bindCdn);

            return $this->fetchJson([
                'code' => $result['success'] ? 200 : 400,
                'msg' => $result['message'],
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('导入失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 同步域名列表（从域名商拉取，自动同步所有）- 保留向后兼容
     */
    public function postSyncDomains()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            if ($accountId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择域名商账户'),
                ]);
            }

            $result = $this->syncService->syncAccount($accountId);

            return $this->fetchJson([
                'code' => $result['success'] ? 200 : 400,
                'msg' => $result['message'],
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('同步失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 删除域名（根域及其子域名/池记录、DNS 记录一并删除）
     *
     * POST domain_ids: 数组或 JSON 数组，根域 domain_id 列表
     */
    #[Acl('Weline_Websites::domain_list', '域名列表', 'mdi mdi-format-list-bulleted', '查看域名列表')]
    public function postDeleteDomains()
    {
        try {
            $domainIds = $this->request->getPost('domain_ids', []);
            if (\is_string($domainIds)) {
                $domainIds = \json_decode($domainIds, true) ?: [];
            }
            $domainIds = \array_filter(\array_map('intval', $domainIds));
            if (empty($domainIds)) {
                return $this->fetchJson(['code' => 400, 'msg' => __('请选择要删除的域名')]);
            }

            $deleted = 0;
            $poolDeleted = 0;
            $dnsDeleted = 0;
            $errors = [];

            foreach ($domainIds as $domainId) {
                try {
                    $domain = ObjectManager::getInstance(DomainModel::class, [], false);
                    $domain->clearQuery()->load($domainId);
                    if (!$domain->getDomainId()) {
                        continue;
                    }
                    $domainName = $domain->getDomain();
                    $rootDomainLower = \strtolower(\trim($domainName));

                    // 0. 若有网站正在使用该根域名，则禁止删除，提示先删除网站或解除绑定
                    $websiteDomainModel = ObjectManager::getInstance(WebsiteDomain::class, [], false);
                    $usedBySites = $websiteDomainModel->clearQuery()
                        ->where(WebsiteDomain::schema_fields_ROOT_DOMAIN, $rootDomainLower)
                        ->select()
                        ->fetch()
                        ->getItems();
                    if (\count($usedBySites) > 0) {
                        $errors[] = __('ID %{1}（%{2}）：该根域名正在被网站使用，不能删除。请先删除相关网站或解除域名绑定后再删除该域名。', [$domainId, $domainName]);
                        continue;
                    }

                    // 1. 删除该根域下所有域名池记录（子域名：按 parent_domain_id 与 root_domain 双条件，避免遗漏）
                    $poolModel = ObjectManager::getInstance(DomainPool::class);
                    $byParent = $poolModel->clearQuery()
                        ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $domainId)
                        ->select()
                        ->fetch()
                        ->getItems();
                    $byRoot = $poolModel->clearQuery()
                        ->where(DomainPool::schema_fields_ROOT_DOMAIN, $rootDomainLower)
                        ->select()
                        ->fetch()
                        ->getItems();
                    $seen = [];
                    foreach (\array_merge($byParent, $byRoot) as $poolRecord) {
                        $pid = $poolRecord->getData(DomainPool::schema_fields_ID);
                        if (isset($seen[$pid])) {
                            continue;
                        }
                        $seen[$pid] = true;
                        $poolRecord->delete()->fetch();
                        $poolDeleted++;
                    }

                    // 2. 删除该域名的 DNS 解析记录
                    $dnsModel = ObjectManager::getInstance(DomainDnsRecord::class, [], false);
                    $dnsRecords = $dnsModel->clearQuery()
                        ->where(DomainDnsRecord::schema_fields_DOMAIN_ID, $domainId)
                        ->select()
                        ->fetch()
                        ->getItems();
                    foreach ($dnsRecords as $dnsRecord) {
                        $dnsRecord->delete()->fetch();
                        $dnsDeleted++;
                    }

                    // 3. 删除根域记录（必须 fetch() 才真正执行 DELETE）
                    $domain->delete()->fetch();
                    $deleted++;
                } catch (\Throwable $e) {
                    $errors[] = "ID {$domainId}: " . $this->formatThrowableFull($e);
                }
            }

            $pool = ObjectManager::getInstance(DomainPool::class);
            $pool->syncSiteCreatedFromWebsiteDomainTable();

            $msg = __('删除完成：%{1} 个根域，%{2} 条池记录，%{3} 条 DNS 记录', [
                $deleted,
                $poolDeleted,
                $dnsDeleted,
            ]);
            if (!empty($errors)) {
                $msg .= ' ' . __('（%{1} 个失败）', [\count($errors)]);
            }

            return $this->fetchJson([
                'code' => empty($errors) ? 200 : 207,
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
                'code' => 500,
                'msg' => __('删除失败：%{1}', [$e->getMessage()]),
                'data' => [
                    'error_detail' => $this->formatThrowableFull($e),
                ],
            ]);
        }
    }

    /**
     * 获取域名管理配置
     */
    public function getDomainConfig()
    {
        try {
            $config = $this->domainConfig->getAllConfig();
            $serverIp = $this->serverIpService->getPublicIpv4();
            $serverIpv6 = $this->serverIpService->getPublicIpv6();

            return $this->fetchJson([
                'code' => 200,
                'data' => [
                    'config' => $config,
                    'server_ip' => $serverIp,
                    'server_ipv6' => $serverIpv6,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取配置失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 保存域名管理配置
     */
    public function postSaveDomainConfig()
    {
        try {
            $autoResolve = (string) $this->request->getPost('auto_resolve_enabled', '0');
            $recordType = (string) $this->request->getPost('auto_resolve_record_type', 'A');
            $subdomains = (string) $this->request->getPost('auto_resolve_subdomains', '@,www');
            $certAutoRequest = (string) $this->request->getPost('cert_auto_request', '0');

            $this->domainConfig->setValues([
                DomainConfig::CONFIG_AUTO_RESOLVE_ENABLED => $autoResolve === '1' ? '1' : '0',
                DomainConfig::CONFIG_AUTO_RESOLVE_RECORD_TYPE => $recordType,
                DomainConfig::CONFIG_AUTO_RESOLVE_SUBDOMAINS => $subdomains,
                DomainConfig::CONFIG_CERT_AUTO_REQUEST => $certAutoRequest === '1' ? '1' : '0',
            ]);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('配置保存成功'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('保存配置失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 刷新服务器公网 IP
     */
    public function postRefreshServerIp()
    {
        try {
            $result = $this->serverIpService->refreshAll();

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('刷新成功'),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('刷新失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    // ============================================================
    // DNS 解析管理 API（v1.5.0 新增）
    // ============================================================

    /**
     * 获取域名的 DNS 记录详情
     */
    #[Acl('Weline_Websites::dns_view', 'DNS 记录', 'mdi mdi-dns', '查看 DNS 解析记录')]
    public function getDnsRecords()
    {
        try {
            $domainId = (int) $this->request->getGet('domain_id', 0);
            if ($domainId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('域名 ID 不能为空'),
                ]);
            }

            $domain = clone $this->domainModel;
            $domain->clearQuery()->load($domainId);
            if (!$domain->getDomainId()) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('域名不存在'),
                ]);
            }

            try {
                $queryResult = w_query('websites', 'getDnsRecords', ['domain_id' => $domainId]);
                if (\is_array($queryResult) && ($queryResult['success'] ?? false)) {
                    $queryData = \is_array($queryResult['data'] ?? null) ? $queryResult['data'] : [];

                    return $this->fetchJson([
                        'code' => 200,
                        'msg' => (string)($queryResult['message'] ?? __('获取成功')),
                        'data' => [
                            'domain' => $domain->getData(),
                            'records' => \is_array($queryData['records'] ?? null) ? $queryData['records'] : [],
                            'dns_provider' => (string)($queryData['dns_provider'] ?? $domain->getDnsProvider() ?? ''),
                            'dns_provider_name' => (string)($queryData['dns_provider_name'] ?? $domain->getDnsProvider() ?? ''),
                            'server_ip' => (string)($queryData['server_ip'] ?? $this->serverIpService->getPublicIpv4()),
                            'pool_sync' => \is_array($queryData['pool_sync'] ?? null) ? $queryData['pool_sync'] : [],
                            'sync_error' => (string)($queryData['sync_error'] ?? ''),
                        ],
                    ]);
                }
            } catch (\Throwable $queryException) {
                // 查询层不可用时回退到原有逻辑，避免接口整体不可用
            }

            // 兜底：保留历史逻辑，避免查询器注册/重载前导致接口失效
            $details = $this->resolveService->getDnsDetails($domain);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('获取成功（回退模式）'),
                'data' => [
                    'domain' => $domain->getData(),
                    'records' => \is_array($details['records'] ?? null) ? $details['records'] : [],
                    'dns_provider' => (string)($details['dns_provider']['provider'] ?? $domain->getDnsProvider() ?? ''),
                    'dns_provider_name' => (string)($details['dns_provider']['name'] ?? $domain->getDnsProvider() ?? ''),
                    'server_ip' => $this->serverIpService->getPublicIpv4(),
                    'dns' => $details,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取 DNS 记录失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 同步域名的 DNS 记录
     */
    public function postSyncDnsRecords()
    {
        try {
            $domainId = (int) $this->request->getPost('domain_id', 0);
            if ($domainId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('域名 ID 不能为空'),
                ]);
            }

            $domain = clone $this->domainModel;
            $domain->clearQuery()->load($domainId);
            if (!$domain->getDomainId()) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('域名不存在'),
                ]);
            }

            $result = $this->resolveService->syncDnsRecords($domain);

            if ($result['error'] !== '') {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => $result['error'],
                ]);
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('同步完成：新增 %{added}，更新 %{updated}，删除 %{deleted}', [
                    'added' => $result['added'],
                    'updated' => $result['updated'],
                    'deleted' => $result['deleted'],
                ]),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('同步 DNS 记录失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 添加 DNS 记录
     */
    #[Acl('Weline_Websites::dns_edit', '编辑 DNS', 'mdi mdi-dns', '添加/编辑 DNS 解析记录')]
    public function postAddDnsRecord()
    {
        try {
            $domainId = (int) $this->request->getPost('domain_id', 0);
            $type = (string) $this->request->getPost('type', 'A');
            $host = (string) $this->request->getPost('host', '@');
            $value = (string) $this->request->getPost('value', '');
            $ttl = (int) $this->request->getPost('ttl', 600);
            $priority = (int) $this->request->getPost('priority', 0);

            if ($domainId <= 0 || $value === '') {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('域名 ID 和记录值不能为空'),
                ]);
            }

            $domain = clone $this->domainModel;
            $domain->clearQuery()->load($domainId);
            if (!$domain->getDomainId()) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('域名不存在'),
                ]);
            }

            // 获取 DNS 管理账户（根据 DNS 托管位置自动选择）
            $dnsResult = $this->resolveService->getDnsManagementAccount($domain);
            if ($dnsResult['error'] !== '') {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => $dnsResult['error'],
                ]);
            }

            $account = $dnsResult['account'];
            $adapter = $dnsResult['adapter'];

            $record = [
                'type' => $type,
                'host' => $host,
                'value' => $value,
                'ttl' => $ttl,
                'priority' => $priority,
            ];

            $creds = $this->resolveService->mergeDnsAdapterCredentials($domain, $account, $account->getCredentials());
            $result = $adapter->addDnsRecord($domain->getDomain(), $record, $creds);

            if (!$result['success']) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => $result['message'] ?? __('添加 DNS 记录失败'),
                ]);
            }

            $z = \trim((string) ($result['zone_id'] ?? ''));
            if ($z !== '' && \strtolower((string) $adapter->getRegistrarCode()) === 'cloudflare') {
                $this->resolveService->persistCloudflareDnsZoneExternalId($domain, $z);
            }

            // 同步到本地
            $this->resolveService->syncDnsRecords($domain);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('DNS 记录添加成功'),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('添加 DNS 记录失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 删除 DNS 记录
     */
    public function postDeleteDnsRecord()
    {
        try {
            $domainId = (int) $this->request->getPost('domain_id', 0);
            $recordId = (string) $this->request->getPost('record_id', '');

            if ($domainId <= 0 || $recordId === '') {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('域名 ID 和记录 ID 不能为空'),
                ]);
            }

            $domain = clone $this->domainModel;
            $domain->clearQuery()->load($domainId);
            if (!$domain->getDomainId()) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('域名不存在'),
                ]);
            }

            // 获取本地记录的 remote_record_id
            $dnsRecord = ObjectManager::getInstance(DomainDnsRecord::class);
            $dnsRecord->clearQuery()->load((int) $recordId);
            $remoteRecordId = $dnsRecord->getRemoteRecordId();

            if ($remoteRecordId === '') {
                // 仅删除本地记录
                $dnsRecord->delete()->fetch();
                return $this->fetchJson([
                    'code' => 200,
                    'msg' => __('本地记录已删除'),
                ]);
            }

            // 获取 DNS 管理账户（根据 DNS 托管位置自动选择）
            $dnsResult = $this->resolveService->getDnsManagementAccount($domain);
            if ($dnsResult['error'] !== '') {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => $dnsResult['error'],
                ]);
            }

            $account = $dnsResult['account'];
            $adapter = $dnsResult['adapter'];
            $creds = $this->resolveService->mergeDnsAdapterCredentials($domain, $account, $account->getCredentials());

            $result = $adapter->deleteDnsRecord($domain->getDomain(), $remoteRecordId, $creds);

            // 无论远程是否成功，都删除本地记录
            $dnsRecord->delete()->fetch();

            if (!$result['success']) {
                return $this->fetchJson([
                    'code' => 200,
                    'msg' => __('本地记录已删除，远程删除失败：%{error}', ['error' => $result['message'] ?? '']),
                ]);
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('DNS 记录删除成功'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('删除 DNS 记录失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 批量添加 DNS 解析记录
     * 支持为多个域名添加相同的DNS记录配置
     */
    #[Acl('Weline_Websites::dns_batch_add', '批量添加DNS记录', 'mdi mdi-dns', '批量为域名添加DNS解析记录')]
    public function postBatchAddDnsRecords(): string
    {
        try {
            $domainIds = $this->request->getPost('domain_ids', []);
            $type = (string) $this->request->getPost('type', 'A');
            $hostInput = (string) $this->request->getPost('host', '@');
            $value = (string) $this->request->getPost('value', '');
            $ttl = (int) $this->request->getPost('ttl', 600);
            $priority = (int) $this->request->getPost('priority', 0);
            $useServerIp = (bool) $this->request->getPost('use_server_ip', false);

            if (\is_string($domainIds)) {
                $domainIds = \json_decode($domainIds, true) ?: [];
            }
            $domainIds = \array_filter(\array_map('intval', $domainIds));

            if (empty($domainIds)) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择要添加DNS记录的域名'),
                ]);
            }

            if ($useServerIp) {
                $serverIpService = ObjectManager::getInstance(ServerIpService::class);
                $value = $serverIpService->getServerIp();
                if ($value === '') {
                    return $this->fetchJson([
                        'code' => 400,
                        'msg' => __('无法获取服务器公网IP'),
                    ]);
                }
                $type = 'A';
            } elseif ($value === '') {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('记录值不能为空'),
                ]);
            }

            // 支持逗号分隔的多个主机记录
            $hosts = \array_filter(\array_map('trim', \explode(',', $hostInput)));
            if (empty($hosts)) {
                $hosts = ['@'];
            }

            $successCount = 0;
            $failedCount = 0;
            $errors = [];
            $addedRecords = [];
            $dnsProviderHints = []; // 域名的 DNS 服务商提示

            // 获取 DNS 服务商检测器
            $dnsDetector = ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class);

            foreach ($domainIds as $domainId) {
                $domain = clone $this->domainModel;
                $domain->clearQuery()->load($domainId);
                if (!$domain->getDomainId()) {
                    $failedCount += \count($hosts);
                    $errors[] = __('域名ID %{1} 不存在', [$domainId]);
                    continue;
                }

                // 获取 DNS 管理账户（根据 DNS 托管位置自动选择）
                $dnsResult = $this->resolveService->getDnsManagementAccount($domain);
                if ($dnsResult['error'] !== '') {
                    $failedCount += \count($hosts);
                    $errors[] = $domain->getDomain() . ': ' . $dnsResult['error'];
                    continue;
                }

                $account = $dnsResult['account'];
                $adapter = $dnsResult['adapter'];
                $creds = $this->resolveService->mergeDnsAdapterCredentials($domain, $account, $account->getCredentials());

                $domainAddFailed = false;

                // 为每个主机记录添加 DNS
                foreach ($hosts as $host) {
                    $record = [
                        'type' => $type,
                        'host' => $host,
                        'value' => $value,
                        'ttl' => $ttl,
                        'priority' => $priority,
                    ];

                    try {
                        $result = $adapter->addDnsRecord($domain->getDomain(), $record, $creds);
                        if ($result['success']) {
                            $successCount++;
                            $addedRecords[] = $domain->getDomain() . ' [' . $host . ']';
                            $z = \trim((string) ($result['zone_id'] ?? ''));
                            if ($z !== '' && \strtolower((string) $adapter->getRegistrarCode()) === 'cloudflare') {
                                $this->resolveService->persistCloudflareDnsZoneExternalId($domain, $z);
                            }
                        } else {
                            $failedCount++;
                            $domainAddFailed = true;
                            $errors[] = $domain->getDomain() . ' [' . $host . ']: ' . ($result['message'] ?? __('添加失败'));
                        }
                    } catch (\Throwable $e) {
                        $failedCount++;
                        $domainAddFailed = true;
                        $errors[] = $domain->getDomain() . ' [' . $host . ']: ' . $e->getMessage();
                    }
                }

                // 如果添加失败，检测域名当前的 DNS 服务商
                if ($domainAddFailed) {
                    $dnsHint = $this->detectDnsProviderHint($domain, $account->getRegistrarCode(), $dnsDetector);
                    if ($dnsHint !== null) {
                        $dnsProviderHints[$domain->getDomain()] = $dnsHint;
                    }
                }

                // 同步 DNS 记录
                $this->resolveService->syncDnsRecords($domain);
            }

            $msg = __('批量添加DNS记录完成：成功 %{1} 条，失败 %{2} 条', [$successCount, $failedCount]);

            return $this->fetchJson([
                'code' => $failedCount === 0 ? 200 : ($successCount > 0 ? 206 : 400),
                'msg' => $msg,
                'data' => [
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'errors' => $errors,
                    'hosts' => $hosts,
                    'added_records' => $addedRecords,
                    'dns_provider_hints' => $dnsProviderHints,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('批量添加DNS记录失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 检测域名当前的 DNS 服务商并生成提示
     *
     * @param DomainModel $domain 域名模型
     * @param string $registrarCode 注册商代码
     * @param \Weline\Websites\Service\DnsProviderDetector $dnsDetector DNS 检测器
     * @return array|null 提示信息或 null
     */
    private function detectDnsProviderHint(
        DomainModel $domain,
        string $registrarCode,
        \Weline\Websites\Service\DnsProviderDetector $dnsDetector
    ): ?array {
        try {
            // 获取域名的 Nameservers
            $nameservers = $this->getNameserversForDomain($domain->getDomain());
            if (empty($nameservers)) {
                return null;
            }

            // 检测 DNS 服务商
            $detectResult = $dnsDetector->detect($nameservers, $registrarCode);
            $provider = $detectResult['provider'];
            $providerName = $detectResult['name'];

            if ($provider === 'unknown') {
                return [
                    'provider' => 'unknown',
                    'provider_name' => __('未知'),
                    'nameservers' => $nameservers,
                    'message' => __('域名 DNS 已转移到其他服务商，无法通过 %{1} 管理解析', [$this->resolverService->getAdapter($registrarCode)?->getRegistrarName() ?? $registrarCode]),
                    'has_account' => false,
                    'action' => 'manual',
                ];
            }

            // 如果是原注册商的 DNS，不应该失败，返回 null
            if ($detectResult['is_original']) {
                return null;
            }

            // 检查是否有对应 DNS 服务商的账户
            $hasAccount = $this->checkHasProviderAccount($provider);

            // 构建提示信息
            $message = __('域名 DNS 已转移到 %{1}', [$providerName]);
            $action = 'add_account';

            if ($hasAccount) {
                $message .= __('，您已有 %{1} 账户，请到该账户下管理此域名的 DNS 解析', [$providerName]);
                $action = 'switch_account';
            } else {
                $message .= __('，请先添加 %{1} 账户后再管理此域名的 DNS 解析', [$providerName]);
            }

            return [
                'provider' => $provider,
                'provider_name' => $providerName,
                'nameservers' => $nameservers,
                'message' => $message,
                'has_account' => $hasAccount,
                'action' => $action,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 获取域名的 Nameservers
     */
    private function getNameserversForDomain(string $domain): array
    {
        try {
            $records = @\dns_get_record($domain, \DNS_NS);
            if ($records === false || empty($records)) {
                return [];
            }

            $nameservers = [];
            foreach ($records as $record) {
                if (!empty($record['target'])) {
                    $nameservers[] = $record['target'];
                }
            }

            return $nameservers;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 检查是否有某个 DNS 服务商的账户
     */
    private function checkHasProviderAccount(string $providerCode): bool
    {
        try {
            $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $account->where('registrar_code', $providerCode)
                    ->where('status', 'active');

            return $account->find()->fetch() !== null && $account->getId() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ============================================================
    // 解析检测 API（v1.5.0 新增）
    // ============================================================

    /**
     * 检测单个域名的解析状态
     */
    #[Acl('Weline_Websites::resolve_check', '解析检测', 'mdi mdi-check-network', '检测域名解析状态')]
    public function postCheckResolve()
    {
        try {
            $domainId = (int) $this->request->getPost('domain_id', 0);
            if ($domainId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('域名 ID 不能为空'),
                ]);
            }

            $domain = clone $this->domainModel;
            $domain->clearQuery()->load($domainId);
            if (!$domain->getDomainId()) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('域名不存在'),
                ]);
            }

            $result = $this->resolveService->checkResolve($domain);

            return $this->fetchJson([
                'code' => 200,
                'msg' => $result['resolved'] ? __('解析正常') : __('解析异常'),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('检测失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 批量检测解析状态
     */
    public function postBatchCheckResolve()
    {
        try {
            $domainIds = $this->request->getPost('domain_ids', []);
            if (!\is_array($domainIds) || $domainIds === []) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择要检测的域名'),
                ]);
            }

            $domainIds = \array_map('intval', $domainIds);
            $result = $this->resolveService->batchCheckResolve($domainIds);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('检测完成：%{checked} 个域名，%{resolved} 个已解析，%{local} 个指向本服务器', [
                    'checked' => $result['checked'],
                    'resolved' => $result['resolved'],
                    'local' => $result['local'],
                ]),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('批量检测失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 自动解析域名到本服务器
     */
    #[Acl('Weline_Websites::auto_resolve', '自动解析', 'mdi mdi-auto-fix', '自动添加解析到本服务器')]
    public function postAutoResolve()
    {
        try {
            $domainId = (int) $this->request->getPost('domain_id', 0);
            $subdomains = $this->request->getPost('subdomains', []);

            if ($domainId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('域名 ID 不能为空'),
                ]);
            }

            $domain = clone $this->domainModel;
            $domain->clearQuery()->load($domainId);
            if (!$domain->getDomainId()) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('域名不存在'),
                ]);
            }

            if (!\is_array($subdomains)) {
                $subdomains = [];
            }

            $result = $this->resolveService->autoResolveToLocal($domain, $subdomains);

            if (!$result['success']) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('自动解析失败：%{error}', ['error' => \implode('; ', $result['errors'])]),
                ]);
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('自动解析成功：添加了 %{count} 条记录', ['count' => $result['added']]),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('自动解析失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 批量自动解析
     */
    public function postBatchAutoResolve()
    {
        try {
            $domainIds = $this->request->getPost('domain_ids', []);
            if (!\is_array($domainIds) || $domainIds === []) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择要解析的域名'),
                ]);
            }

            $domainIds = \array_map('intval', $domainIds);
            $domains = $this->domainModel->getByIds($domainIds);

            $success = 0;
            $failed = 0;
            $errors = [];

            foreach ($domains as $row) {
                $domain = clone $this->domainModel;
                $domain->setData($row);

                $result = $this->resolveService->autoResolveToLocal($domain);
                if ($result['success']) {
                    $success++;
                } else {
                    $failed++;
                    $errors[] = $domain->getDomain() . ': ' . \implode('; ', $result['errors']);
                }
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('批量解析完成：成功 %{success} 个，失败 %{failed} 个', [
                    'success' => $success,
                    'failed' => $failed,
                ]),
                'data' => [
                    'success' => $success,
                    'failed' => $failed,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('批量解析失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    // ============================================================
    // 域名转存到域名池 API
    // ============================================================

    /**
     * 单个域名转存到域名池
     */
    #[Acl('Weline_Websites::domain_to_pool', '转存域名池', 'mdi mdi-database-export', '将根域名转存到域名池')]
    public function postTransferToPool()
    {
        try {
            $domainId = (int) $this->request->getPost('domain_id', 0);
            $prefixes = $this->request->getPost('prefixes', []);

            if ($domainId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('域名 ID 不能为空'),
                ]);
            }

            $domain = clone $this->domainModel;
            $domain->clearQuery()->load($domainId);
            if (!$domain->getDomainId()) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('域名不存在'),
                ]);
            }

            $subdomainGenerator = ObjectManager::getInstance(
                \Weline\Websites\Service\SubdomainGeneratorService::class
            );

            if (!\is_array($prefixes)) {
                $prefixes = [];
            }

            $result = $subdomainGenerator->generateDefaultSubdomains($domain, $prefixes);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('转存完成：新增 %{added} 个，已存在 %{skipped} 个', [
                    'added' => $result['added'],
                    'skipped' => $result['skipped'],
                ]),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('转存失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 批量域名转存到域名池
     */
    public function postBatchTransferToPool()
    {
        try {
            $domainIds = $this->request->getPost('domain_ids', []);
            $prefixes = $this->request->getPost('prefixes', []);

            if (!\is_array($domainIds) || $domainIds === []) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择要转存的域名'),
                ]);
            }

            $domainIds = \array_map('intval', $domainIds);

            $subdomainGenerator = ObjectManager::getInstance(
                \Weline\Websites\Service\SubdomainGeneratorService::class
            );

            if (!\is_array($prefixes)) {
                $prefixes = [];
            }

            $result = $subdomainGenerator->batchGenerateSubdomains($domainIds, $prefixes);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('批量转存完成：新增 %{added} 个，已存在 %{skipped} 个', [
                    'added' => $result['total_added'],
                    'skipped' => $result['total_skipped'],
                ]),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('批量转存失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 批量域名转存到域名池（仅解析到本服务器的域名）
     */
    public function postBatchTransferToPoolWithIpCheck()
    {
        try {
            $domainIds = $this->request->getPost('domain_ids', []);
            $prefixes = $this->request->getPost('prefixes', []);

            if (\is_string($domainIds)) {
                $domainIds = \json_decode($domainIds, true) ?: [];
            }

            if (!\is_array($domainIds) || $domainIds === []) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择要转存的域名'),
                ]);
            }

            $domainIds = \array_map('intval', $domainIds);

            $serverIpService = ObjectManager::getInstance(\Weline\Websites\Service\ServerIpService::class);
            $subdomainGenerator = ObjectManager::getInstance(\Weline\Websites\Service\SubdomainGeneratorService::class);

            $serverIp = $serverIpService->getPublicIpv4();
            $serverIpv6 = $serverIpService->getPublicIpv6();
            if ($serverIp === '' && $serverIpv6 === '') {
                return $this->fetchJson([
                    'code' => 500,
                    'msg' => __('无法获取服务器公网IP'),
                ]);
            }

            if (!\is_array($prefixes)) {
                $prefixes = [];
            }

            $totalAdded = 0;
            $totalSkipped = 0;
            $skippedDomains = [];
            $processedDomains = [];

            foreach ($domainIds as $domainId) {
                $domain = ObjectManager::getInstance(\Weline\Websites\Model\Domain::class, [], false);
                $domain->load($domainId);

                if (!$domain->getDomainId()) {
                    $totalSkipped++;
                    continue;
                }

                $domainName = $domain->getDomain();
                $pointsToLocal = $this->originMatch->fqdnPointsToServer(
                    $domainName,
                    \strtolower(\trim($domainName)),
                    (int) $domain->getDnsAccountId(),
                    (int) $domain->getCdnAccountId(),
                );

                if (!$pointsToLocal) {
                    $totalSkipped++;
                    $skippedDomains[] = $domainName;
                    continue;
                }

                $result = $subdomainGenerator->generateDefaultSubdomains($domain, $prefixes);
                $totalAdded += $result['added'] ?? 0;
                $processedDomains[] = $domainName;
            }

            $msg = __('批量转存完成：成功 %{1} 个子域名', [$totalAdded]);
            if (\count($skippedDomains) > 0) {
                $msg .= '，' . __('跳过 %{1} 个域名（IP不匹配）', [\count($skippedDomains)]);
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => $msg,
                'data' => [
                    'total_added' => $totalAdded,
                    'total_skipped' => $totalSkipped,
                    'skipped_domains' => $skippedDomains,
                    'processed_domains' => $processedDomains,
                    'server_ip' => $serverIp,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('批量转存失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 批量解析域名到本地服务器
     */
    public function postBatchResolveToLocal(): string
    {
        $domainIds = $this->request->getPost('domain_ids', []);

        if (\is_string($domainIds)) {
            $domainIds = \json_decode($domainIds, true) ?: [];
        }

        $domainIds = \array_map('intval', \array_filter((array) $domainIds));

        if ($domainIds === []) {
            return $this->fetchJson(['code' => 400, 'msg' => __('请选择要解析的域名')]);
        }

        try {
            $resolveService = ObjectManager::getInstance(DomainResolveService::class);
            $serverIpService = ObjectManager::getInstance(ServerIpService::class);

            $serverIp = $serverIpService->getPublicIpv4();
            if ($serverIp === '') {
                return $this->fetchJson(['code' => 500, 'msg' => __('无法获取服务器公网IP')]);
            }

            $success = 0;
            $failed = 0;
            $errors = [];

            foreach ($domainIds as $domainId) {
                $domain = ObjectManager::getInstance(DomainModel::class, [], false);
                $domain->load($domainId);

                if (!$domain->getDomainId()) {
                    $failed++;
                    continue;
                }

                $result = $resolveService->autoResolveToLocal($domain, ['@', 'www']);

                if ($result['success']) {
                    $success++;
                } else {
                    $failed++;
                    $errors[] = $domain->getDomain() . ': ' . \implode('; ', $result['errors'] ?? []);
                }
            }

            $msg = __('解析完成：成功 %{1} 个，失败 %{2} 个', [$success, $failed]);

            return $this->fetchJson([
                'code' => 200,
                'msg' => $msg,
                'data' => [
                    'success' => $success,
                    'failed' => $failed,
                    'errors' => $errors,
                    'server_ip' => $serverIp,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('解析失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 批量切换域名 DNS 服务器
     *
     * 仅调用注册商 {@see \Weline\Websites\Api\DomainRegistrarInterface::updateNameservers} 并写入 Domain.nameservers，
     * **非** {@see DnsSwitchService::executeDnsSwitch} 全量流水线（无推送记录、无 dns_cutover_complete）。
     * 购买/迁移场景请用 {@see postSwitchDnsAccount} 或 SSE {@see getDnsSwitchSse}。
     */
    public function postBatchChangeNameservers(): string
    {
        $domainIds = $this->request->getPost('domain_ids', []);
        $nameservers = $this->request->getPost('nameservers', '');
        $targetProvider = $this->request->getPost('target_provider', '');

        if (\is_string($domainIds)) {
            $domainIds = \json_decode($domainIds, true) ?: [];
        }

        $domainIds = \array_map('intval', \array_filter((array) $domainIds));

        if ($domainIds === []) {
            return $this->fetchJson(['code' => 400, 'msg' => __('请选择要切换的域名')]);
        }

        $nameserverList = [];
        if ($nameservers !== '') {
            $nameserverList = \array_filter(\array_map('trim', \explode(',', $nameservers)));
        }

        if ($nameserverList === [] && $targetProvider === '') {
            return $this->fetchJson(['code' => 400, 'msg' => __('请输入目标 DNS 服务器或选择目标服务商')]);
        }

        try {
            $registrarResolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);

            if ($targetProvider !== '' && $nameserverList === []) {
                $nameserverList = $this->getProviderNameservers($targetProvider);
                if ($nameserverList === []) {
                    return $this->fetchJson(['code' => 400, 'msg' => __('无法获取目标服务商的 Nameserver')]);
                }
            }

            $success = 0;
            $failed = 0;
            $errors = [];

            foreach ($domainIds as $domainId) {
                $domain = ObjectManager::getInstance(DomainModel::class, [], false);
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
                    $domain->setNameservers($nameserverList);
                    $domain->save();
                } else {
                    $failed++;
                    $errors[] = $domain->getDomain() . ': ' . ($result['message'] ?? __('切换失败'));
                }
            }

            $msg = __('DNS切换完成：成功 %{1} 个，失败 %{2} 个', [$success, $failed]);

            return $this->fetchJson([
                'code' => 200,
                'msg' => $msg,
                'data' => [
                    'success' => $success,
                    'failed' => $failed,
                    'errors' => $errors,
                    'target_nameservers' => $nameserverList,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
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
            'code' => 200,
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
     *
     * 支持 GET 参数 active_only=1 仅返回活跃账号
     */
    public function getGetRegistrarAccounts(): string
    {
        try {
            $accountModel = ObjectManager::getInstance(DomainRegistrarAccount::class);
            $accountModel->clearData(true);
            $accountModel->clearQuery();
            if ($this->request->getGet('active_only', '0') === '1') {
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
                'code' => 200,
                'msg' => 'success',
                'data' => ['accounts' => $result],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取账户列表失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 获取账户的 Nameserver（用于一键切换）
     *
     * 对于 Cloudflare 等供应商，需要传入域名列表来获取每个域名的 Nameserver
     */
    public function postGetAccountNameservers(): string
    {
        $accountId = (int) $this->request->getPost('account_id', 0);
        $domains = $this->request->getPost('domains', []);

        if ($accountId <= 0) {
            return $this->fetchJson(['code' => 400, 'msg' => __('请选择目标账户')]);
        }

        if (\is_string($domains)) {
            $domains = \json_decode($domains, true) ?: [];
        }

        try {
            $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $account->load($accountId);

            if (!$account->getAccountId()) {
                return $this->fetchJson(['code' => 404, 'msg' => __('账户不存在')]);
            }

            $registrarResolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $adapter = $registrarResolver->getAdapter($account->getRegistrarCode());

            if ($adapter === null) {
                return $this->fetchJson(['code' => 404, 'msg' => __('适配器不存在')]);
            }

            $credentials = $account->getCredentials();

            if (empty($domains)) {
                $result = $adapter->getProviderNameservers($credentials);
                return $this->fetchJson([
                    'code' => $result['success'] ? 200 : 400,
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
                    'code' => 200,
                    'msg' => __('已获取各域名的 Nameserver'),
                    'data' => [
                        'nameservers' => [],
                        'per_domain' => true,
                        'domain_nameservers' => $domainNs,
                    ],
                ]);
            }

            return $this->fetchJson([
                'code' => $result['success'] ? 200 : 400,
                'msg' => $result['message'] ?? '',
                'data' => [
                    'nameservers' => $result['nameservers'] ?? [],
                    'per_domain' => false,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取 Nameserver 失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 批量切换域名到目标账户（一键切换）
     *
     * 仅获取目标 NS 后在注册商侧 {@see \Weline\Websites\Api\DomainRegistrarInterface::updateNameservers}，**非**
     * {@see DnsSwitchService::executeDnsSwitch}。全量切换请用 {@see postSwitchDnsAccount} / {@see getDnsSwitchSse}。
     */
    public function postBatchSwitchToAccount(): string
    {
        $domainIds = $this->request->getPost('domain_ids', []);
        $targetAccountId = (int) $this->request->getPost('target_account_id', 0);

        if (\is_string($domainIds)) {
            $domainIds = \json_decode($domainIds, true) ?: [];
        }

        $domainIds = \array_map('intval', \array_filter((array) $domainIds));

        if ($domainIds === []) {
            return $this->fetchJson(['code' => 400, 'msg' => __('请选择要切换的域名')]);
        }

        if ($targetAccountId <= 0) {
            return $this->fetchJson(['code' => 400, 'msg' => __('请选择目标账户')]);
        }

        try {
            $targetAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $targetAccount->load($targetAccountId);

            if (!$targetAccount->getAccountId()) {
                return $this->fetchJson(['code' => 404, 'msg' => __('目标账户不存在')]);
            }

            $registrarResolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $targetAdapter = $registrarResolver->getAdapter($targetAccount->getRegistrarCode());

            if ($targetAdapter === null) {
                return $this->fetchJson(['code' => 404, 'msg' => __('目标适配器不存在')]);
            }

            $targetCredentials = $targetAccount->getCredentials();

            $success = 0;
            $failed = 0;
            $errors = [];

            foreach ($domainIds as $domainId) {
                $domain = ObjectManager::getInstance(DomainModel::class, [], false);
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
                $updateResult = $sourceAdapter->updateNameservers($domainName, $targetNs, $sourceCredentials);

                if ($updateResult['success'] ?? false) {
                    $success++;
                    $domain->setNameservers($targetNs);
                    $domain->save();
                } else {
                    $failed++;
                    $errors[] = $domainName . ': ' . ($updateResult['message'] ?? __('切换失败'));
                }
            }

            $msg = __('DNS切换完成：成功 %{1} 个，失败 %{2} 个', [$success, $failed]);

            return $this->fetchJson([
                'code' => 200,
                'msg' => $msg,
                'data' => [
                    'success' => $success,
                    'failed' => $failed,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('切换失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 实时检测域名的 DNS 服务商和 NS 记录
     *
     * 从公网 DNS 查询当前域名的 NS 记录，判断 DNS 服务商归属
     */
    public function postDetectDnsProvider(): string
    {
        try {
            $domainIds = $this->request->getPost('domain_ids', []);
            $forceRefresh = (bool) $this->request->getPost('force_refresh', false);

            if (\is_string($domainIds)) {
                $domainIds = \json_decode($domainIds, true) ?: [];
            }

            $domainIds = \array_map('intval', \array_filter((array) $domainIds));

            if ($domainIds === []) {
                return $this->fetchJson(['code' => 400, 'msg' => __('请选择要检测的域名')]);
            }

            $dnsDetector = ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class);
            $results = [];
            $updated = 0;

            foreach ($domainIds as $domainId) {
                $domain = clone $this->domainModel;
                $domain->clearQuery()->load($domainId);

                if (!$domain->getId()) {
                    continue;
                }

                $domainName = $domain->getDomain();
                $registrarCode = '';

                // 获取注册商代码
                $accountId = $domain->getAccountId();
                if ($accountId > 0) {
                    $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                    $account->load($accountId);
                    $registrarCode = $account->getRegistrarCode() ?: '';
                }

                // 实时查询 DNS NS 记录
                $liveNs = $this->queryLiveNsRecords($domainName);
                $storedNs = $domain->getNameservers();

                // 如果实时查询成功，更新存储的 NS
                if (!empty($liveNs)) {
                    if ($forceRefresh || $liveNs !== $storedNs) {
                        $domain->setNameservers($liveNs);
                        $storedNs = $liveNs;
                    }
                }

                // 检测 DNS 服务商
                $detectResult = $dnsDetector->detect($storedNs, $registrarCode);
                $provider = $detectResult['provider'];

                // 更新 dns_provider 字段
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
                    if ($domain->getCdnAccountId() === 0) {
                        $cdnAccount = $this->resolveService->findAccountByProviderCode($cdnProvider);
                        if ($cdnAccount !== null) {
                            $cdnAccountId = $cdnAccount->getAccountId();
                            $domain->setCdnAccountId($cdnAccountId);
                        }
                    } else {
                        $cdnAccountId = $domain->getCdnAccountId();
                    }
                    
                    // 同时设置 DNS 账户（CDN 服务商通常也管理 DNS）
                    if ($domain->getDnsAccountId() === 0 && $cdnAccountId > 0) {
                        $domain->setDnsAccountId($cdnAccountId);
                    }
                }

                // 保存更改（强制保存，因为 hasDataChanges 可能误判）
                $domain->forceCheck(false)->save();

                // 同步更新域名池中关联此根域的所有子域名
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
                    'is_original' => $detectResult['is_original'],
                    'registrar_code' => $registrarCode,
                    'pool_updated' => $poolUpdated,
                ];
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('DNS 检测完成，共 %{1} 个根域，%{2} 个已更新', [\count($results), $updated]),
                'data' => [
                    'results' => $results,
                    'total' => \count($results),
                    'updated' => $updated,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('DNS 检测失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 批量检测所有域名的 DNS 服务商
     */
    public function postBatchDetectDnsProvider(): string
    {
        try {
            $dnsDetector = ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class);

            // 获取所有域名（fetch() 返回模型，需 getItems() 取记录列表）
            $domains = $this->domainModel->clearQuery()->select()->fetch()->getItems();

            $total = 0;
            $updated = 0;
            $errors = 0;
            $results = [];

            foreach ($domains as $domain) {
                $total++;
                try {
                    $domainName = $domain->getDomain();
                    $registrarCode = '';

                    // 获取注册商代码
                    $accountId = $domain->getAccountId();
                    if ($accountId > 0) {
                        $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                        $account->load($accountId);
                        $registrarCode = $account->getRegistrarCode() ?: '';
                    }

                    // 实时查询 NS
                    $liveNs = $this->queryLiveNsRecords($domainName);
                    $storedNs = $domain->getNameservers();

                    if (!empty($liveNs) && $liveNs !== $storedNs) {
                        $domain->setNameservers($liveNs);
                        $storedNs = $liveNs;
                    }

                    // 检测服务商
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
                        if ($domain->getCdnAccountId() === 0) {
                            $cdnAccount = $this->resolveService->findAccountByProviderCode($cdnProvider);
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

                    // 强制保存（hasDataChanges 可能误判）
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
                        'dns_provider' => $provider,
                        'dns_provider_name' => $detectResult['name'],
                        'cdn_provider' => $cdnProvider,
                        'cdn_provider_name' => $cdnProviderName,
                        'dns_account_id' => $domain->getDnsAccountId(),
                        'cdn_account_id' => $cdnAccountId,
                        'nameservers' => $storedNs,
                        'pool_updated' => $poolUpdated,
                    ];
                } catch (\Throwable $e) {
                    $errors++;
                }
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('批量检测完成：共 %{1} 个根域，更新 %{2} 个，失败 %{3} 个', [$total, $updated, $errors]),
                'data' => [
                    'total' => $total,
                    'updated' => $updated,
                    'errors' => $errors,
                    'results' => $results,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('批量检测失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 批量设置域名的 DNS/CDN 账户
     *
     * 允许用户批量修改已拉取根域的 dns_account_id、cdn_account_id
     */
    #[Acl('Weline_Websites::batch_set_accounts', '批量设置账户', 'mdi mdi-account-multiple-check', '批量设置域名的 DNS/CDN 管理账户')]
    public function postBatchSetAccounts(): string
    {
        try {
            $domainIds = $this->request->getPost('domain_ids', []);
            $dnsAccountId = $this->request->getPost('dns_account_id');
            $cdnAccountId = $this->request->getPost('cdn_account_id');

            if (\is_string($domainIds)) {
                $domainIds = \json_decode($domainIds, true) ?: [];
            }

            $domainIds = \array_map('intval', \array_filter((array) $domainIds));

            if ($domainIds === []) {
                return $this->fetchJson(['code' => 400, 'msg' => __('请选择要设置的域名')]);
            }

            // 至少要设置一个账户
            $hasDnsAccount = $dnsAccountId !== null && $dnsAccountId !== '';
            $hasCdnAccount = $cdnAccountId !== null && $cdnAccountId !== '';

            if (!$hasDnsAccount && !$hasCdnAccount) {
                return $this->fetchJson(['code' => 400, 'msg' => __('请至少选择一个账户进行设置')]);
            }

            // 验证账户存在性
            if ($hasDnsAccount && (int) $dnsAccountId > 0) {
                $dnsAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                $dnsAccount->load((int) $dnsAccountId);
                if (!$dnsAccount->getAccountId()) {
                    return $this->fetchJson(['code' => 404, 'msg' => __('DNS 账户不存在')]);
                }
            }
            $dnsProviderCode = ($hasDnsAccount && (int) $dnsAccountId > 0)
                ? (string) ($dnsAccount->getRegistrarCode() ?? '')
                : '';

            if ($hasCdnAccount && (int) $cdnAccountId > 0) {
                $cdnAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
                $cdnAccount->load((int) $cdnAccountId);
                if (!$cdnAccount->getAccountId()) {
                    return $this->fetchJson(['code' => 404, 'msg' => __('CDN 账户不存在（请确认所选账户仍存在且已启用，或在「域名商账户」中添加 CDN 服务商账户）')]);
                }
            }
            $cdnProviderCode = ($hasCdnAccount && (int) $cdnAccountId > 0)
                ? (string) ($cdnAccount->getRegistrarCode() ?? '')
                : '';

            $updated = 0;
            $errors = [];

            foreach ($domainIds as $domainId) {
                $domain = clone $this->domainModel;
                $domain->clearQuery()->load($domainId);

                if (!$domain->getDomainId()) {
                    $errors[] = __('域名 ID %{1} 不存在', [$domainId]);
                    continue;
                }

                $changed = false;

                // 设置 DNS 账户（0 表示清除，> 0 表示设置）
                if ($hasDnsAccount) {
                    $newDnsAccountId = (int) $dnsAccountId;
                    if ($domain->getDnsAccountId() !== $newDnsAccountId) {
                        $domain->setDnsAccountId($newDnsAccountId);
                        $changed = true;
                    }
                }

                // 设置 CDN 账户
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

                if ($hasDnsAccount || $hasCdnAccount) {
                    $poolUpdate = ObjectManager::getInstance(DomainPool::class, [], false);
                    $poolUpdate->clearQuery()->where(DomainPool::schema_fields_ROOT_DOMAIN, \strtolower($domain->getDomain()));

                    if ($hasDnsAccount) {
                        $newDnsAccountId = (int) $dnsAccountId;
                        if ($newDnsAccountId > 0) {
                            $poolUpdate->setData(DomainPool::schema_fields_DNS_STATUS, DomainPool::INFRA_STATUS_PENDING);
                            $poolUpdate->setData(DomainPool::schema_fields_DNS_PROVIDER, $dnsProviderCode);
                        } else {
                            // 手动域名可无 DNS 账户，置为 ready 避免被强制阻塞
                            $poolUpdate->setData(DomainPool::schema_fields_DNS_STATUS, DomainPool::INFRA_STATUS_READY);
                            $poolUpdate->setData(DomainPool::schema_fields_DNS_PROVIDER, '');
                        }
                    }

                    if ($hasCdnAccount) {
                        $newCdnAccountId = (int) $cdnAccountId;
                        if ($newCdnAccountId > 0) {
                            $poolUpdate->setData(DomainPool::schema_fields_CDN_STATUS, DomainPool::INFRA_STATUS_PENDING);
                        } else {
                            $poolUpdate->setData(DomainPool::schema_fields_CDN_STATUS, DomainPool::INFRA_STATUS_READY);
                        }
                    }
                    $poolUpdate->update()->fetch();
                }
            }

            $msg = __('批量设置完成：共 %{1} 个域名，更新 %{2} 个', [\count($domainIds), $updated]);
            if (!empty($errors)) {
                $msg .= '，' . __('失败 %{1} 个', [\count($errors)]);
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => $msg,
                'data' => [
                    'total' => \count($domainIds),
                    'updated' => $updated,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('批量设置失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * AJAX: 切换根域名 DNS 服务账户（同步记录到本地 → 修改 NS → 更新账户并标记待迁移，定时任务将把记录推送到新账户）
     *
     * POST: domain_ids[], target_account_id
     * 相同账户则跳过；不同账户时：先同步当前记录到本地，再在注册商处修改 NS 到目标账户，更新 domain 的 dns_account_id/dns_provider，并设置 dns_migration_pending，由定时任务推送记录到新账户。
     */
    #[Acl('Weline_Websites::switch_dns_account', '切换DNS服务账户', 'mdi mdi-swap-horizontal', '为根域名切换 DNS 服务账户并迁移记录')]
    public function postSwitchDnsAccount(): string
    {
        $domainIds = $this->request->getPost('domain_ids', []);
        $targetAccountId = (int) $this->request->getPost('target_account_id', 0);

        if (\is_string($domainIds)) {
            $domainIds = \json_decode($domainIds, true) ?: [];
        }
        $domainIds = \array_map('intval', \array_filter((array) $domainIds));

        if ($domainIds === []) {
            return $this->fetchJson(['code' => 400, 'msg' => __('请选择要切换的根域名')]);
        }
        if ($targetAccountId <= 0) {
            return $this->fetchJson(['code' => 400, 'msg' => __('请选择目标 DNS 服务账户')]);
        }

        try {
            $targetAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $targetAccount->load($targetAccountId);
            if (!$targetAccount->getAccountId()) {
                return $this->fetchJson(['code' => 404, 'msg' => __('目标账户不存在')]);
            }

            $targetAdapter = $this->resolverService->getAdapter($targetAccount->getRegistrarCode());
            if ($targetAdapter === null || !$targetAdapter->supportsDnsManagement()) {
                return $this->fetchJson(['code' => 400, 'msg' => __('目标账户不支持 DNS 管理')]);
            }

            $success = 0;
            $skipped = 0;
            $failed = 0;
            $errors = [];

            foreach ($domainIds as $domainId) {
                $domain = ObjectManager::getInstance(DomainModel::class, [], false);
                $domain->load($domainId);
                if (!$domain->getDomainId()) {
                    $failed++;
                    $errors[] = __('域名 ID %{1} 不存在', [$domainId]);
                    continue;
                }

                if ((int) $domain->getDnsAccountId() === $targetAccountId) {
                    $skipped++;
                    continue;
                }

                $this->dnsSwitchService->markPoolSwitching($domain->getDomain(), (string) $targetAccount->getRegistrarCode());
                $result = $this->dnsSwitchService->executeDnsSwitchWithStandardOptions($domain, $targetAccount);

                if ($result['success']) {
                    $success++;
                } else {
                    $failed++;
                    $errors[] = $domain->getDomain() . ': ' . $result['message'];
                }
            }

            $msg = __('切换完成：成功 %{1}，跳过（已是该账户）%{2}，失败 %{3}', [$success, $skipped, $failed]);
            if ($errors !== []) {
                $msg .= "\n" . \implode("\n", \array_slice($errors, 0, 5));
                if (\count($errors) > 5) {
                    $msg .= "\n…";
                }
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => $msg,
                'data' => [
                    'success' => $success,
                    'skipped' => $skipped,
                    'failed' => $failed,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('切换失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * SSE: 实时推送 DNS/CDN 切换每步进度
     *
     * GET: domain_ids (JSON), target_account_id
     */
    #[Acl('Weline_Websites::switch_dns_account', '切换DNS服务账户', 'mdi mdi-swap-horizontal', '为根域名切换 DNS 服务账户并迁移记录')]
    public function getDnsSwitchSse(): void
    {
        $sse = new \Weline\Framework\Http\Sse\SseWriter();
        $sse->start();

        $logCh = 'dns_cdn_switch';
        $ts = static fn(): string => \date('H:i:s');

        $domainIdsRaw = $this->request->getGet('domain_ids', '');
        $domainIds = \is_string($domainIdsRaw) ? (\json_decode($domainIdsRaw, true) ?: []) : (array) $domainIdsRaw;
        $domainIds = \array_map('intval', \array_filter($domainIds));
        $targetAccountId = (int) $this->request->getGet('target_account_id', 0);

        if ($domainIds === []) {
            $sse->sendError((string) __('请选择要切换的根域名'));
            $sse->complete(['success' => false]);
            return;
        }
        if ($targetAccountId <= 0) {
            $sse->sendError((string) __('请选择目标 DNS 服务账户'));
            $sse->complete(['success' => false]);
            return;
        }

        $total = \count($domainIds);
        $sse->sendEvent('init', [
            'message' => __('开始 DNS/CDN 切换，共 %{1} 个域名', [$total]),
            'total' => $total,
            'time' => $ts(),
        ]);
        w_log_info(__('[DnsSwitchSSE] 开始切换，domain_ids=%{1}, target=%{2}', [
            \json_encode($domainIds), (string) $targetAccountId,
        ]), [], $logCh);

        try {
            $targetAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $targetAccount->load($targetAccountId);
            if (!$targetAccount->getAccountId()) {
                $sse->sendError((string) __('目标账户不存在'));
                $sse->complete(['success' => false]);
                return;
            }

            $targetAdapter = $this->resolverService->getAdapter($targetAccount->getRegistrarCode());
            if ($targetAdapter === null || !$targetAdapter->supportsDnsManagement()) {
                $sse->sendError((string) __('目标账户不支持 DNS 管理'));
                $sse->complete(['success' => false]);
                return;
            }

            $targetCode = (string) $targetAccount->getRegistrarCode();

            $sse->sendEvent('init', [
                'message' => __('目标账户验证通过：%{1}(%{2})', [$targetAccount->getName(), $targetCode]),
                'time' => $ts(),
            ]);

            $success = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($domainIds as $idx => $domainId) {
                $step = $idx + 1;
                $domain = ObjectManager::getInstance(DomainModel::class, [], false);
                $domain->load($domainId);

                if (!$domain->getDomainId()) {
                    $failed++;
                    $sse->sendEvent('step_error', [
                        'step' => $step, 'total' => $total,
                        'domain' => "ID:{$domainId}",
                        'message' => __('域名不存在'),
                        'time' => $ts(),
                    ]);
                    continue;
                }

                $domainName = $domain->getDomain();

                if ((int) $domain->getDnsAccountId() === $targetAccountId
                    && (int) $domain->getDnsSwitchPending() === 0
                    && (int) $domain->getDnsCutoverComplete() === 1) {
                    $skipped++;
                    $sse->sendEvent('step_skip', [
                        'step' => $step, 'total' => $total,
                        'domain' => $domainName,
                        'message' => __('已是该账户且切换已完成，跳过'),
                        'time' => $ts(),
                    ]);
                    continue;
                }

                w_log_info(__('[DnsSwitchSSE] 开始处理 %{1}（%{2}/%{3}）', [$domainName, (string) $step, (string) $total]), [], $logCh);

                $this->dnsSwitchService->markPoolSwitching($domainName, $targetCode);

                $result = $this->dnsSwitchService->executeDnsSwitchWithStandardOptions(
                    $domain,
                    $targetAccount,
                    static function (string $event, array $data) use ($sse, $step, $total, $ts): void {
                        $sse->sendEvent($event, \array_merge($data, [
                            'step' => $step,
                            'total' => $total,
                            'time' => $ts(),
                        ]));
                    },
                    [
                        'is_alive' => static function () use ($sse): bool {
                            return $sse->isAlive() && !\connection_aborted();
                        },
                    ]
                );

                if ($result['success']) {
                    $success++;
                    $sse->sendEvent('domain_done', [
                        'step' => $step, 'total' => $total,
                        'domain' => $domainName,
                        'message' => __('域名 %{1} 切换完成', [$domainName]),
                        'time' => $ts(),
                    ]);
                    w_log_info(__('[DnsSwitchSSE] %{1} 切换完成', [$domainName]), [], $logCh);
                } else {
                    $failed++;
                    $sse->sendEvent('step_error', [
                        'step' => $step, 'total' => $total,
                        'domain' => $domainName,
                        'message' => $result['message'],
                        'time' => $ts(),
                    ]);
                    w_log_error(__('[DnsSwitchSSE] %{1} 切换失败：%{2}', [$domainName, $result['message']]), [], $logCh);
                }
            }

            $sse->complete([
                'message' => __('全部完成：成功 %{1}，跳过 %{2}，失败 %{3}', [(string) $success, (string) $skipped, (string) $failed]),
                'success' => $success,
                'skipped' => $skipped,
                'failed' => $failed,
                'total' => $total,
                'time' => $ts(),
            ]);
            w_log_info(__('[DnsSwitchSSE] 全部完成：success=%{1}, skipped=%{2}, failed=%{3}', [
                (string) $success, (string) $skipped, (string) $failed,
            ]), [], $logCh);
        } catch (\Throwable $e) {
            $sse->sendError((string) __('切换失败：%{1}', [$e->getMessage()]));
            $sse->complete(['success' => false]);
            w_log_error(__('[DnsSwitchSSE] 致命异常：%{1}', [$e->getMessage()]), [], $logCh);
        }
    }

    /**
     * AJAX: 获取可用于 DNS/CDN 管理的账户列表
     *
     * 返回支持 DNS 管理的账户（用于下拉选择）
     */
    #[Acl('Weline_Websites::get_dns_accounts', '获取DNS账户列表', 'mdi mdi-dns', '获取支持 DNS 管理的账户列表')]
    public function getDnsAccounts(): string
    {
        try {
            $registrarResolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $accounts = ObjectManager::getInstance(DomainRegistrarAccount::class);
            $allAccounts = $accounts->clearQuery()
                ->where(DomainRegistrarAccount::schema_fields_STATUS, DomainRegistrarAccount::STATUS_ACTIVE)
                ->select()
                ->fetch();

            $dnsAccounts = [];
            $cdnAccounts = [];

            foreach ($allAccounts as $account) {
                $registrarCode = $account->getRegistrarCode();
                $adapter = $registrarResolver->getAdapter($registrarCode);

                $accountInfo = [
                    'account_id' => $account->getAccountId(),
                    'name' => $account->getName(),
                    'registrar_code' => $registrarCode,
                    'registrar_name' => $account->getData('registrar_name') ?: $registrarCode,
                ];

                // 支持 DNS 管理的账户
                if ($adapter !== null && $adapter->supportsDnsManagement()) {
                    $dnsAccounts[] = $accountInfo;
                }

                // CDN 服务商账户（Cloudflare 等）
                $dnsDetector = ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class);
                if ($dnsDetector->isCdnProvider($registrarCode)) {
                    $cdnAccounts[] = $accountInfo;
                }
            }

            return $this->fetchJson([
                'code' => 200,
                'data' => [
                    'dns_accounts' => $dnsAccounts,
                    'cdn_accounts' => $cdnAccounts,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取账户列表失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 实时查询域名的 NS 记录（从公网 DNS）
     *
     * @param string $domain 域名
     * @return array NS 记录列表
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
     * 同步 DNS/CDN 服务商到域名池
     *
     * 将根域名的 DNS 服务商同步到域名池中所有关联的子域名
     *
     * @param string $rootDomain 根域名
     * @param string $dnsProvider DNS 服务商代码
     * @param string $cdnProvider CDN 服务商代码（可选，如果 DNS 是 CDN 服务商则传入）
     * @return int 更新的域名池记录数
     */
    private function syncDnsProviderToPool(string $rootDomain, string $dnsProvider, string $cdnProvider = ''): int
    {
        $poolModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainPool::class);

        // 查找域名池中所有 root_domain 匹配的记录（fetch 返回 Model，需 getItems() 取记录列表）
        $poolDomains = $poolModel->clearQuery()
            ->where(\Weline\Websites\Model\DomainPool::schema_fields_ROOT_DOMAIN, \strtolower($rootDomain))
            ->select()
            ->fetch()
            ->getItems();

        $updated = 0;
        foreach ($poolDomains as $poolDomain) {
            $changed = false;
            
            // 同步 DNS provider
            $currentDnsProvider = $poolDomain->getDnsProvider();
            if ($currentDnsProvider !== $dnsProvider) {
                $poolDomain->setDnsProvider($dnsProvider);
                $changed = true;
            }
            
            // 同步 CDN provider（如果有）
            if ($cdnProvider !== '') {
                $currentCdnProvider = $poolDomain->getData('cdn_provider') ?? '';
                if ($currentCdnProvider !== $cdnProvider) {
                    $poolDomain->setData('cdn_provider', $cdnProvider);
                    $changed = true;
                }
            }
            
            if ($changed) {
                $poolDomain->save();
                $updated++;
            }
        }

        return $updated;
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
    #[Acl('Weline_Websites::batch_remove_sync', '批量取消拉取', 'mdi mdi-database-remove', '从本地移除已同步的域名')]
    public function postBatchRemoveSync(): string
    {
        try {
            $domainIds = $this->request->getPost('domain_ids', []);

            if (\is_string($domainIds)) {
                $domainIds = \json_decode($domainIds, true) ?: [];
            }
            $domainIds = \array_filter(\array_map('intval', $domainIds));

            if (empty($domainIds)) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择要取消拉取的域名'),
                ]);
            }

            $deleted = 0;
            $poolDeleted = 0;
            $dnsDeleted = 0;
            $errors = [];

            foreach ($domainIds as $domainId) {
                try {
                    $domain = ObjectManager::getInstance(DomainModel::class, [], false);
                    $domain->clearQuery()->load($domainId);

                    if (!$domain->getDomainId()) {
                        continue;
                    }

                    $domainName = $domain->getDomain();
                    $rootDomainLower = \strtolower(\trim($domainName));

                    // 若有网站正在使用该根域名，则禁止删除
                    $websiteDomainModel = ObjectManager::getInstance(WebsiteDomain::class, [], false);
                    $usedBySites = $websiteDomainModel->clearQuery()
                        ->where(WebsiteDomain::schema_fields_ROOT_DOMAIN, $rootDomainLower)
                        ->select()
                        ->fetch()
                        ->getItems();
                    if (\count($usedBySites) > 0) {
                        $errors[] = __('ID %{1}（%{2}）：该根域名正在被网站使用，不能删除。请先删除相关网站或解除域名绑定后再删除该域名。', [$domainId, $domainName]);
                        continue;
                    }

                    // 1. 删除关联的域名池记录（子域：按 parent_domain_id 与 root_domain 双条件，避免遗漏）
                    $poolModel = ObjectManager::getInstance(DomainPool::class);
                    $byParent = $poolModel->clearQuery()
                        ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $domainId)
                        ->select()
                        ->fetch()
                        ->getItems();
                    $byRoot = $poolModel->clearQuery()
                        ->where(DomainPool::schema_fields_ROOT_DOMAIN, $rootDomainLower)
                        ->select()
                        ->fetch()
                        ->getItems();
                    $seen = [];
                    foreach (\array_merge($byParent, $byRoot) as $poolRecord) {
                        $pid = $poolRecord->getData(DomainPool::schema_fields_ID);
                        if (isset($seen[$pid])) {
                            continue;
                        }
                        $seen[$pid] = true;
                        $poolRecord->delete()->fetch();
                        $poolDeleted++;
                    }

                    // 2. 删除关联的 DNS 解析记录（本地记录）
                    $dnsModel = ObjectManager::getInstance(DomainDnsRecord::class, [], false);
                    $dnsRecords = $dnsModel->clearQuery()
                        ->where(DomainDnsRecord::schema_fields_DOMAIN_ID, $domainId)
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
                    $errors[] = "ID {$domainId}: " . $this->formatThrowableFull($e);
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
                'code' => empty($errors) ? 200 : 206,
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
                'code' => 500,
                'msg' => __('批量取消拉取失败：%{1}', [$e->getMessage()]),
                'data' => [
                    'error_detail' => $this->formatThrowableFull($e),
                ],
            ]);
        }
    }

    /**
     * 按账户批量取消拉取
     *
     * 删除指定账户下的所有已同步域名
     */
    #[Acl('Weline_Websites::remove_sync_by_account', '按账户取消拉取', 'mdi mdi-account-remove', '删除指定账户下的所有域名')]
    public function postRemoveSyncByAccount(): string
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);

            if ($accountId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择域名商账户'),
                ]);
            }

            // 获取该账户下的所有域名（fetch() 返回模型，需 getItems() 取记录列表）
            $domains = $this->domainModel->clearQuery()
                ->where(DomainModel::schema_fields_ACCOUNT_ID, $accountId)
                ->select()
                ->fetch()
                ->getItems();

            if (empty($domains)) {
                return $this->fetchJson([
                    'code' => 200,
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
            $skipped = 0;

            foreach ($domains as $domain) {
                $domainId = $domain->getDomainId();
                $domainName = $domain->getDomain();
                $rootDomainLower = \strtolower(\trim($domainName));

                // 若有网站正在使用该根域名，则跳过不删
                $websiteDomainModel = ObjectManager::getInstance(WebsiteDomain::class, [], false);
                $usedBySites = $websiteDomainModel->clearQuery()
                    ->where(WebsiteDomain::schema_fields_ROOT_DOMAIN, $rootDomainLower)
                    ->select()
                    ->fetch()
                    ->getItems();
                if (\count($usedBySites) > 0) {
                    $skipped++;
                    continue;
                }

                // 1. 删除关联的域名池记录（子域：按 parent_domain_id 与 root_domain 双条件）
                $poolModel = ObjectManager::getInstance(DomainPool::class);
                $byParent = $poolModel->clearQuery()
                    ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $domainId)
                    ->select()
                    ->fetch()
                    ->getItems();
                $byRoot = $poolModel->clearQuery()
                    ->where(DomainPool::schema_fields_ROOT_DOMAIN, $rootDomainLower)
                    ->select()
                    ->fetch()
                    ->getItems();
                $seen = [];
                foreach (\array_merge($byParent, $byRoot) as $poolRecord) {
                    $pid = $poolRecord->getData(DomainPool::schema_fields_ID);
                    if (isset($seen[$pid])) {
                        continue;
                    }
                    $seen[$pid] = true;
                    $poolRecord->delete()->fetch();
                    $poolDeleted++;
                }

                // 2. 删除关联的 DNS 解析记录
                $dnsModel = ObjectManager::getInstance(DomainDnsRecord::class, [], false);
                $dnsRecords = $dnsModel->clearQuery()
                    ->where(DomainDnsRecord::schema_fields_DOMAIN_ID, $domainId)
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

            $msg = __('取消拉取完成：删除 %{1} 个根域，%{2} 个域名池记录，%{3} 条 DNS 记录', [
                    $deleted,
                    $poolDeleted,
                    $dnsDeleted,
                ]);
            if ($skipped > 0) {
                $msg .= ' ' . __('（跳过 %{1} 个：正在被网站使用，请先删除相关网站或解除绑定）', [$skipped]);
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => $msg,
                'data' => [
                    'deleted' => $deleted,
                    'pool_deleted' => $poolDeleted,
                    'dns_deleted' => $dnsDeleted,
                    'skipped' => $skipped,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('取消拉取失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 手动新建域名（支持可选 DNS/CDN 账户与 HTTPS 初始化）
     */
    #[Acl('Weline_Websites::manual_create_domain', '手动新建域名', 'mdi mdi-plus-circle', '手动新建域名并可直接处理 HTTPS')]
    public function postCreateManualDomain(): string
    {
        try {
            $rawDomain = (string) $this->request->getPost('domain', '');
            $description = (string) $this->request->getPost('description', '');
            $dnsAccountId = (int) $this->request->getPost('dns_account_id', 0);
            $cdnAccountId = (int) $this->request->getPost('cdn_account_id', 0);
            $httpsMode = (string) $this->request->getPost('https_mode', 'none');
            $httpsEmail = (string) $this->request->getPost('https_email', '');
            $manualProvider = (string) $this->request->getPost('manual_cert_provider', 'manual');

            $domain = $this->domainParserService->normalizeDomain($rawDomain);
            if ($domain === '') {
                return $this->fetchJson(['code' => 400, 'msg' => __('请输入域名')]);
            }

            $rootDomain = $this->domainParserService->parseRootDomain($domain);
            if ($rootDomain === '') {
                return $this->fetchJson(['code' => 400, 'msg' => __('无法解析根域名，请检查输入')]);
            }

            $dnsProvider = $this->getRegistrarCodeByAccountId($dnsAccountId);
            $cdnProvider = $this->getRegistrarCodeByAccountId($cdnAccountId);

            /** @var DomainModel $rootDomainModel */
            $rootDomainModel = ObjectManager::getInstance(DomainModel::class, [], false);
            $rootDomainModel->clearQuery()
                ->where(DomainModel::schema_fields_DOMAIN, $rootDomain)
                ->find()
                ->fetch();

            if (!$rootDomainModel->getDomainId()) {
                $rootDomainModel->setAccountId(0)
                    ->setDomain($rootDomain)
                    ->setStatus(DomainModel::STATUS_ACTIVE)
                    ->setResolveStatus(DomainModel::RESOLVE_STATUS_PENDING)
                    ->setHttpsStatus(DomainModel::HTTPS_STATUS_NONE)
                    ->setDnsAccountId($dnsAccountId)
                    ->setCdnAccountId($cdnAccountId)
                    ->setDnsProvider($dnsProvider)
                    ->setCdnProvider($cdnProvider)
                    ->forceCheck(false)
                    ->save();
            } else {
                $rootDomainModel->setDnsAccountId($dnsAccountId)
                    ->setCdnAccountId($cdnAccountId)
                    ->setDnsProvider($dnsProvider)
                    ->setCdnProvider($cdnProvider)
                    ->forceCheck(false)
                    ->save();
            }

            /** @var DomainPool $poolModel */
            $poolModel = ObjectManager::getInstance(DomainPool::class, [], false);
            $poolModel->clearQuery()
                ->where(DomainPool::schema_fields_DOMAIN, $domain)
                ->find()
                ->fetch();

            $isNewPool = !$poolModel->getPoolId();
            if ($isNewPool) {
                $poolModel->setDomain($domain)
                    ->setParentDomainId($rootDomainModel->getDomainId())
                    ->setDescription($description)
                    ->setStatus(DomainPool::STATUS_ACTIVE)
                    ->setResolveStatus(DomainPool::RESOLVE_STATUS_PENDING)
                    ->setHttpsStatus(DomainPool::HTTPS_STATUS_NONE)
                    ->setSiteReady(false);
            } else {
                $poolModel->setParentDomainId($rootDomainModel->getDomainId());
                if (\trim($description) !== '') {
                    $poolModel->setDescription($description);
                }
            }

            if ($dnsAccountId > 0) {
                $poolModel->setDnsStatus(DomainPool::INFRA_STATUS_PENDING);
                $poolModel->setDnsProvider($dnsProvider);
            } else {
                // 手动域名默认允许无 DNS 账户，避免阻塞后续流程
                $poolModel->setDnsStatus(DomainPool::INFRA_STATUS_READY);
                $poolModel->setDnsProvider('');
            }
            if ($cdnAccountId > 0) {
                $poolModel->setCdnStatus(DomainPool::INFRA_STATUS_PENDING);
            } else {
                $poolModel->setCdnStatus(DomainPool::INFRA_STATUS_READY);
            }
            $poolModel->save();
            if ($isNewPool && $poolModel->getPoolId() > 0) {
                ObjectManager::getInstance(DomainPoolFlowLogService::class)->append(
                    (int) $poolModel->getPoolId(),
                    \Weline\Websites\Model\DomainPoolFlowLog::KIND_POOL_CREATED,
                    __('后台手动添加：%{1}', [\trim($domain)])
                );
            }

            $httpsMode = \strtolower(\trim($httpsMode));
            if (!\in_array($httpsMode, ['none', 'auto', 'manual'], true)) {
                $httpsMode = 'none';
            }

            $httpsResult = null;
            if ($httpsMode !== 'none') {
                $reachability = $this->precheckRootDomainReachability($rootDomain);
                if (!(bool) ($reachability['success'] ?? false)) {
                    return $this->fetchJson([
                        'code' => 400,
                        'msg' => __('HTTPS 前置校验失败，请先确认根域下所有域名都已指向本机并可访问'),
                        'data' => [
                            'domain' => $domain,
                            'reachability' => $reachability,
                            'domain_id' => $rootDomainModel->getDomainId(),
                            'pool_id' => $poolModel->getPoolId(),
                        ],
                    ]);
                }

                if ($httpsMode === 'auto') {
                    $email = \trim($httpsEmail);
                    if ($email === '') {
                        $email = 'admin@' . $rootDomain;
                    }
                    $poolModel->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING)->setHttpsError('')->save();
                    $httpsResult = w_query('server', 'requestCertificate', [
                        'domain' => $domain,
                        'webroot' => \defined('PUB') ? PUB : (BP . 'pub'),
                        'email' => $email,
                        'provider' => 'letsencrypt',
                        'pool_id' => (int) $poolModel->getPoolId(),
                        'domain_id' => (int) $rootDomainModel->getDomainId(),
                        'challenge_strategy' => 'dns01',
                    ]);

                    if ((bool) ($httpsResult['success'] ?? false)) {
                        $certId = (int) ($httpsResult['cert_id'] ?? 0);
                        if ($certId > 0) {
                            $poolModel->setCertId($certId);
                        }
                        $poolModel->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID)->setHttpsError('');
                        $poolModel->calculateSiteReady();
                        $poolModel->save();
                    } else {
                        $poolModel->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR)
                            ->setHttpsError((string) ($httpsResult['message'] ?? __('证书申请失败')))
                            ->save();
                    }
                }

                if ($httpsMode === 'manual') {
                    $certPayload = $this->buildManualCertificatePayloadFromRequest();
                    if (!(bool) ($certPayload['success'] ?? false)) {
                        return $this->fetchJson([
                            'code' => 400,
                            'msg' => (string) ($certPayload['message'] ?? __('请提供有效的证书内容')),
                            'data' => [
                                'domain' => $domain,
                                'domain_id' => $rootDomainModel->getDomainId(),
                                'pool_id' => $poolModel->getPoolId(),
                            ],
                        ]);
                    }

                    $poolModel->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING)->setHttpsError('')->save();
                    $httpsResult = w_query('server', 'importCertificate', [
                        'domain' => $domain,
                        'provider' => $manualProvider !== '' ? $manualProvider : 'manual',
                        'website_id' => 0,
                        'fullchain_pem' => (string) ($certPayload['fullchain_pem'] ?? ''),
                        'private_key_pem' => (string) ($certPayload['private_key_pem'] ?? ''),
                        'chain_pem' => (string) ($certPayload['chain_pem'] ?? ''),
                        'pfx_base64' => (string) ($certPayload['pfx_base64'] ?? ''),
                        'pfx_password' => (string) ($certPayload['pfx_password'] ?? ''),
                    ]);

                    if ((bool) ($httpsResult['success'] ?? false)) {
                        $certId = (int) ($httpsResult['cert_id'] ?? 0);
                        if ($certId > 0) {
                            $poolModel->setCertId($certId);
                        }
                        $poolModel->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID)->setHttpsError('');
                        $poolModel->calculateSiteReady();
                        $poolModel->save();
                    } else {
                        $poolModel->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR)
                            ->setHttpsError((string) ($httpsResult['message'] ?? __('证书导入失败')))
                            ->save();
                    }
                }
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('域名创建成功'),
                'data' => [
                    'domain' => $domain,
                    'root_domain' => $rootDomain,
                    'domain_id' => $rootDomainModel->getDomainId(),
                    'pool_id' => $poolModel->getPoolId(),
                    'https_mode' => $httpsMode,
                    'https_result' => $httpsResult,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('新建域名失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 使用 WLS server 查询器校验域名 URL 可达性，并检测是否命中当前服务器公网 IP。
     */
    private function precheckDomainReachability(string $domain): array
    {
        $expectedIpv4 = $this->serverIpService->getPublicIpv4();
        $expectedIpv6 = $this->serverIpService->getPublicIpv6();

        return (array) w_query('server', 'checkDomainReachability', [
            'domain' => $domain,
            'url' => 'https://' . $domain . '/',
            'expected_ipv4' => $expectedIpv4,
            'expected_ipv6' => $expectedIpv6,
        ]);
    }

    /**
     * 手动触发 HTTPS 时，对根域下所有域名执行一次本机可达性校验。
     */
    private function precheckRootDomainReachability(string $rootDomain): array
    {
        $domainPool = ObjectManager::getInstance(DomainPool::class, [], false);
        $poolRows = $domainPool->getDomainsByRoot($rootDomain);
        if ($poolRows === []) {
            return ['success' => false, 'message' => __('根域下无可检测域名')];
        }

        $details = [];
        $failed = [];
        foreach ($poolRows as $row) {
            $domain = \trim((string) ($row[DomainPool::schema_fields_DOMAIN] ?? ''));
            if ($domain === '') {
                continue;
            }
            $check = $this->precheckDomainReachability($domain);
            $details[$domain] = $check;
            if (!(bool) ($check['success'] ?? false)) {
                $failed[$domain] = (string) ($check['message'] ?? __('可达性校验失败'));
            }
        }

        if ($failed !== []) {
            return [
                'success' => false,
                'message' => __('以下域名未通过可达性校验：%{1}', [\implode(', ', \array_keys($failed))]),
                'failed' => $failed,
                'details' => $details,
            ];
        }

        return [
            'success' => true,
            'message' => __('根域下全部域名可达性校验通过'),
            'details' => $details,
        ];
    }

    private function getRegistrarCodeByAccountId(int $accountId): string
    {
        if ($accountId <= 0) {
            return '';
        }
        $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
        $account->load($accountId);
        if (!$account->getAccountId()) {
            return '';
        }
        return (string) ($account->getRegistrarCode() ?? '');
    }

    /**
     * 组装手动证书导入参数：
     * - 支持 PEM/KEY/CHAIN 文本
     * - 支持上传 cert/key/chain/pfx(p12)
     */
    private function buildManualCertificatePayloadFromRequest(): array
    {
        $fullchainPem = \trim((string) $this->request->getPost('cert_fullchain_text', ''));
        $privateKeyPem = \trim((string) $this->request->getPost('cert_private_key_text', ''));
        $chainPem = \trim((string) $this->request->getPost('cert_chain_text', ''));
        $pfxPassword = (string) $this->request->getPost('cert_pfx_password', '');

        $certFile = $this->request->getFiles('cert_file');
        $keyFile = $this->request->getFiles('key_file');
        $chainFile = $this->request->getFiles('chain_file');
        $pfxFile = $this->request->getFiles('pfx_file');

        if ($fullchainPem === '') {
            $fullchainPem = $this->readUploadedTextFile($certFile);
        }
        if ($privateKeyPem === '') {
            $privateKeyPem = $this->readUploadedTextFile($keyFile);
        }
        if ($chainPem === '') {
            $chainPem = $this->readUploadedTextFile($chainFile);
        }

        $pfxBase64 = '';
        if (\is_array($pfxFile) && isset($pfxFile['tmp_name']) && \is_string($pfxFile['tmp_name']) && \is_file($pfxFile['tmp_name'])) {
            $pfxContent = @\file_get_contents($pfxFile['tmp_name']);
            if (\is_string($pfxContent) && $pfxContent !== '') {
                $pfxBase64 = \base64_encode($pfxContent);
            }
        }

        if ($pfxBase64 === '' && ($fullchainPem === '' || $privateKeyPem === '')) {
            return ['success' => false, 'message' => __('请上传证书文件或粘贴证书内容')];
        }

        return [
            'success' => true,
            'fullchain_pem' => $fullchainPem,
            'private_key_pem' => $privateKeyPem,
            'chain_pem' => $chainPem,
            'pfx_base64' => $pfxBase64,
            'pfx_password' => $pfxPassword,
        ];
    }

    private function readUploadedTextFile(mixed $file): string
    {
        if (!\is_array($file)) {
            return '';
        }
        $tmpName = $file['tmp_name'] ?? '';
        if (!\is_string($tmpName) || $tmpName === '' || !\is_file($tmpName)) {
            return '';
        }
        $content = @\file_get_contents($tmpName);
        return \is_string($content) ? \trim($content) : '';
    }

    /**
     * 清理所有 DNS 服务商账户下的域名
     *
     * 删除所有非域名注册商（如 Cloudflare、Azure DNS）账户下的域名，
     * 这些域名不应该出现在根域列表中（它们只是托管在 DNS 服务商，实际归属于其他注册商）。
     */
    #[Acl('Weline_Websites::cleanup_dns_provider_domains', '清理DNS服务商域名', 'mdi mdi-broom', '清理误同步的DNS服务商域名')]
    public function postCleanupDnsProviderDomains(): string
    {
        try {
            $resolverService = ObjectManager::getInstance(DomainRegistrarResolverService::class);

            // 获取所有账户（须 getItems()，不可对 fetch() 返回的模型直接 foreach）
            $accounts = $this->registrarAccount->clearQuery()
                ->select()
                ->fetch()
                ->getItems();

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
                $domains = $this->domainModel->clearQuery()
                    ->where(DomainModel::schema_fields_ACCOUNT_ID, $accountId)
                    ->select()
                    ->fetch()
                    ->getItems();

                if (empty($domains)) {
                    continue;
                }

                $deleted = 0;
                $poolDeleted = 0;
                $dnsDeleted = 0;
                $skipped = 0;

                foreach ($domains as $domain) {
                    $domainId = $domain->getDomainId();
                    $domainName = $domain->getDomain();
                    $rootDomainLower = \strtolower(\trim($domainName));

                    // 若有网站正在使用该根域名，则跳过不删
                    $websiteDomainModel = ObjectManager::getInstance(WebsiteDomain::class, [], false);
                    $usedBySites = $websiteDomainModel->clearQuery()
                        ->where(WebsiteDomain::schema_fields_ROOT_DOMAIN, $rootDomainLower)
                        ->select()
                        ->fetch()
                        ->getItems();
                    if (\count($usedBySites) > 0) {
                        $skipped++;
                        continue;
                    }

                    // 删除关联的域名池记录（子域：按 parent_domain_id 与 root_domain 双条件）
                    $poolModel = ObjectManager::getInstance(DomainPool::class);
                    $byParent = $poolModel->clearQuery()
                        ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $domainId)
                        ->select()
                        ->fetch()
                        ->getItems();
                    $byRoot = $poolModel->clearQuery()
                        ->where(DomainPool::schema_fields_ROOT_DOMAIN, $rootDomainLower)
                        ->select()
                        ->fetch()
                        ->getItems();
                    $seen = [];
                    foreach (\array_merge($byParent, $byRoot) as $poolRecord) {
                        $pid = $poolRecord->getData(DomainPool::schema_fields_ID);
                        if (isset($seen[$pid])) {
                            continue;
                        }
                        $seen[$pid] = true;
                        $poolRecord->delete()->fetch();
                        $poolDeleted++;
                    }

                    // 删除关联的 DNS 解析记录
                    $dnsModel = ObjectManager::getInstance(DomainDnsRecord::class, [], false);
                    $dnsRecords = $dnsModel->clearQuery()
                        ->where(DomainDnsRecord::schema_fields_DOMAIN_ID, $domainId)
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
                    'code' => 200,
                    'msg' => __('没有需要清理的 DNS 服务商域名'),
                    'data' => [
                        'cleaned_accounts' => [],
                        'total_deleted' => 0,
                    ],
                ]);
            }

            return $this->fetchJson([
                'code' => 200,
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
                'code' => 500,
                'msg' => __('清理失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

}
