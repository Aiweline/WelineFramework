<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Service\Query\FrameworkQueryService;

/**
 * @DESC | 快速建站服务聚合器
 *
 * 数据查询操作通过 FrameworkQueryService（统一查询器）完成，
 * 仅通知型/协作型操作保留事件 dispatch。
 */
class QuickBuildAggregator
{
    public function __construct(
        private readonly EventsManager $eventsManager,
        private readonly FrameworkQueryService $queryService
    ) {
    }

    /**
     * 查询所有可用的快速建站服务（事件聚合 - 多模块响应）
     */
    public function queryServices(string $category = 'all', ?int $websiteId = null): array
    {
        $eventData = [
            'data' => [
                'category' => $category,
                'website_id' => $websiteId,
                'services' => [],
            ],
        ];
        $this->eventsManager->dispatch('GuoLaiRen_PageBuilder::quickbuild::query_services', $eventData);

        $services = $eventData['data']['services'] ?? [];
        usort($services, static fn(array $a, array $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));
        return $services;
    }

    /**
     * 启动一站式配置流程 DNS → CDN → SSL（事件通知 - 多模块协作）
     */
    public function startProvisioning(string $domain, int $registrarAccountId, array $options = []): array
    {
        // 参数预检
        if (trim($domain) === '') {
            return ['success' => false, 'message' => __('域名不能为空')];
        }
        if ($registrarAccountId <= 0) {
            return ['success' => false, 'message' => __('请选择域名商账号')];
        }

        $eventData = [
            'data' => [
                'domain' => $domain,
                'registrar_account_id' => $registrarAccountId,
                'options' => $options,
                'result' => null,
            ],
        ];
        $this->eventsManager->dispatch('GuoLaiRen_PageBuilder::quickbuild::start_provisioning', $eventData);

        $result = $eventData['data']['result'] ?? null;
        
        if ($result === null) {
            // 没有模块处理该事件，返回详细诊断信息
            return [
                'success' => false,
                'message' => __('无可用配置服务。请检查 Weline_Saas 模块是否已安装并正确注册事件观察者 (GuoLaiRen_PageBuilder::quickbuild::start_provisioning)。'),
            ];
        }
        
        return $result;
    }

    /**
     * 查询配置订单列表（通过统一查询器调用 Saas getOrders）
     */
    public function queryProvisioningOrders(array $filter = []): array
    {
        $params = [
            'page'      => 1,
            'page_size' => (int)($filter['page_size'] ?? 500),
        ];
        if (!empty($filter['status'])) {
            $params['status'] = $filter['status'];
        }
        if (!empty($filter['domain'])) {
            $params['domain'] = $filter['domain'];
        }
        $result = $this->queryService->execute('saas', 'getOrders', $params);
        return is_array($result['items'] ?? null) ? $result['items'] : [];
    }

    public function getDomainLifecycleStatus(string $domain): array
    {
        return $this->queryService->execute('saas', 'getDomainLifecycleStatus', [
            'domain' => $domain,
        ]);
    }

    public function processLifecycleOrder(int $orderId): array
    {
        return $this->queryService->execute('saas', 'processOrder', [
            'order_id' => $orderId,
        ]);
    }

    /**
     * 补建已购买域名的生命周期订单（用于购买时未勾选「启动全流程状态跟踪」的补救）
     */
    public function repairLifecycleOrder(string $domain, int $accountId, array $options = []): array
    {
        return $this->queryService->execute('saas', 'startPurchasedLifecycle', \array_merge($options, [
            'domain' => $domain,
            'registrar_account_id' => $accountId,
        ]));
    }

    // ── 以下全部通过统一查询器 (WebsitesQueryProvider) ──

    /**
     * 查询可用的域名商类型列表
     */
    public function queryRegistrars(): array
    {
        return $this->queryService->execute('websites', 'getRegistrars');
    }

    /**
     * 查询已配置的域名商账号列表
     */
    public function queryRegistrarAccounts(array $filter = []): array
    {
        return $this->queryService->execute('websites', 'getRegistrarAccounts', $filter);
    }

    /**
     * 保存域名商账号（创建或更新）
     */
    public function saveRegistrarAccount(array $data): array
    {
        return $this->queryService->execute('websites', 'saveRegistrarAccount', $data);
    }

    /**
     * 删除域名商账号
     */
    public function deleteRegistrarAccount(int $accountId): array
    {
        return $this->queryService->execute('websites', 'deleteRegistrarAccount', [
            'account_id' => $accountId,
        ]);
    }

    /**
     * 查询域名商账号下的域名列表
     */
    public function queryDomainList(int $accountId): array
    {
        return $this->queryService->execute('websites', 'getDomainList', [
            'account_id' => $accountId,
        ]);
    }

    /**
     * 批量检查域名可用性
     */
    public function checkAvailability(int $accountId, array $domains): array
    {
        return $this->queryService->execute('websites', 'checkAvailability', [
            'account_id' => $accountId,
            'domains' => $domains,
        ]);
    }

    /**
     * 发起域名购买
     */
    public function purchaseDomain(int $accountId, array $items, bool $autoResolve = false, array $options = []): array
    {
        return $this->queryService->execute('websites', 'purchaseDomain', [
            'account_id' => $accountId,
            'items' => $items,
            'auto_resolve' => $autoResolve,
            'resolve_to_local' => $options['resolve_to_local'] ?? ($autoResolve ? 'yes' : 'no'),
            'subdomains' => $options['subdomains'] ?? ['@', 'www'],
            'dns_choice' => $options['dns_choice'] ?? 'follow_registrar',
            'dns_provider' => $options['dns_provider'] ?? '',
            'dns_account_id' => $options['dns_account_id'] ?? 0,
            'dns_nameservers' => $options['dns_nameservers'] ?? '',
            'cdn_choice' => $options['cdn_choice'] ?? 'follow_registrar',
            'cdn_provider' => $options['cdn_provider'] ?? '',
            'cdn_account_id' => $options['cdn_account_id'] ?? 0,
            'start_lifecycle' => $options['start_lifecycle'] ?? '1',
        ]);
    }

    /**
     * 测试域名商账号连接
     */
    public function testRegistrarConnection(int $accountId): array
    {
        return $this->queryService->execute('websites', 'testConnection', [
            'account_id' => $accountId,
        ]);
    }

    /**
     * 获取域名商的配置字段定义
     */
    public function queryRegistrarConfigFields(string $registrarCode): array
    {
        return $this->queryService->execute('websites', 'getConfigFields', [
            'registrar_code' => $registrarCode,
        ]);
    }

    /**
     * 获取域名商完整信息（含配置字段、帮助说明、默认值）
     */
    public function queryRegistrarInfo(string $registrarCode): array
    {
        return $this->queryService->execute('websites', 'getRegistrarInfo', [
            'registrar_code' => $registrarCode,
        ]);
    }

    // ── CDN/DNS 相关（与域名购买/管理逻辑一致，数据来自 WebsitesQueryProvider） ──

    /**
     * 查询可用的 CDN 适配器列表（Cdn 模块）
     */
    public function queryCdnAdapters(): array
    {
        return $this->queryService->execute('cdn', 'getAdapters');
    }

    /**
     * 查询 CDN 账户列表（Cdn 模块）
     */
    public function queryCdnAccounts(array $filter = []): array
    {
        return $this->queryService->execute('cdn', 'getAccounts', $filter);
    }

    /**
     * 获取 CDN/DNS 服务商与账户（与域名购买逻辑一致，供 QuickBuild 向导使用）
     * 返回 cdnAdapters + cdnAccounts，数据来源与 DomainManagement getDnsAccounts 一致。
     */
    public function queryDnsCdnForWizard(): array
    {
        $data = $this->queryService->execute('websites', 'getDnsCdnAccounts', ['status' => 'active']);
        $cdnAccounts = $data['cdn_accounts'] ?? [];
        $seen = [];
        // 默认“跟随域名商”由模板固定渲染，这里只返回真实供应商，避免重复选项
        $adapters = [];
        foreach ($cdnAccounts as $acc) {
            $code = (string) ($acc['registrar_code'] ?? '');
            $name = (string) ($acc['registrar_name'] ?? $code);
            if ($code !== '' && !isset($seen[$code])) {
                $seen[$code] = true;
                $adapters[] = ['code' => $code, 'name' => $name];
            }
        }
        $accounts = [];
        foreach ($cdnAccounts as $acc) {
            $accounts[] = [
                'account_id' => (int) ($acc['account_id'] ?? 0),
                'name'       => (string) ($acc['name'] ?? ''),
                'adapter'    => (string) ($acc['registrar_code'] ?? ''),
                'is_default' => !empty($acc['is_default']),
            ];
        }
        return ['cdnAdapters' => $adapters, 'cdnAccounts' => $accounts];
    }

    /**
     * 获取指定适配器的默认 CDN 账户
     */
    public function getCdnDefaultAccount(string $adapter): ?array
    {
        return $this->queryService->execute('cdn', 'getDefaultAccount', [
            'adapter' => $adapter,
        ]);
    }

    /**
     * 获取已启用域名商账号（同步页使用）
     */
    public function getActiveRegistrarAccounts(): array
    {
        return $this->queryService->execute('websites', 'getActiveAccounts');
    }

    /**
     * 获取域名同步状态选项
     */
    public function getDomainStatusOptions(): array
    {
        return $this->queryService->execute('websites', 'getDomainStatusOptions');
    }

    /**
     * 获取本地域名同步时间
     */
    public function getDomainLastSyncTime(int $accountId = 0): ?string
    {
        return $this->queryService->execute('websites', 'getLastSyncTime', [
            'account_id' => $accountId,
        ]);
    }

    /**
     * 获取本地域名列表（已同步）
     */
    public function getLocalDomains(array $filters, int $page = 1, int $limit = 20): array
    {
        return $this->queryService->execute('websites', 'getLocalDomains', [
            'filters' => $filters,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 获取远程域名列表（不落库）
     */
    public function getRemoteDomains(int $accountId): array
    {
        return $this->queryService->execute('websites', 'getRemoteDomains', [
            'account_id' => $accountId,
        ]);
    }

    /**
     * 导入远程域名到本地
     */
    public function importDomains(
        int $accountId,
        array $domains,
        bool|string $resolveMode,
        int $bindDnsAccountId = 0,
        int $bindCdnAccountId = 0,
    ): array {
        return $this->queryService->execute('websites', 'importDomains', [
            'account_id' => $accountId,
            'domains' => $domains,
            'resolve_mode' => $resolveMode,
            'bind_dns_account_id' => $bindDnsAccountId,
            'bind_cdn_account_id' => $bindCdnAccountId,
        ]);
    }

    /**
     * 同步单账号或全账号域名
     */
    public function syncDomains(int $accountId = 0): array
    {
        return $this->queryService->execute('websites', 'syncDomains', [
            'account_id' => $accountId,
        ]);
    }

    /**
     * 域名批量操作
     */
    public function batchOperateDomains(array $domainIds, string $operation, array $params = []): array
    {
        return $this->queryService->execute('websites', 'batchOperateDomains', [
            'domain_ids' => $domainIds,
            'operation' => $operation,
            'params' => $params,
        ]);
    }
}
