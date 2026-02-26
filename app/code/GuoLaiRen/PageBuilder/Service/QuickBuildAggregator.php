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
        $eventData = [
            'data' => [
                'domain' => $domain,
                'registrar_account_id' => $registrarAccountId,
                'options' => $options,
                'result' => null,
            ],
        ];
        $this->eventsManager->dispatch('GuoLaiRen_PageBuilder::quickbuild::start_provisioning', $eventData);

        return $eventData['data']['result'] ?? ['success' => false, 'message' => __('无可用配置服务')];
    }

    /**
     * 查询配置订单列表（事件聚合 - 多模块响应）
     */
    public function queryProvisioningOrders(array $filter = []): array
    {
        $eventData = [
            'data' => [
                'filter' => $filter,
                'orders' => [],
            ],
        ];
        $this->eventsManager->dispatch('GuoLaiRen_PageBuilder::quickbuild::query_provisioning_orders', $eventData);

        return $eventData['data']['orders'] ?? [];
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
    public function purchaseDomain(int $accountId, array $items): array
    {
        return $this->queryService->execute('websites', 'purchaseDomain', [
            'account_id' => $accountId,
            'items' => $items,
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
}
