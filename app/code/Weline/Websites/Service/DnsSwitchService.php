<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\NameserverSwitchInterface;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainRegistrarAccount;

/**
 * DNS 切换统一服务
 *
 * 封装 DNS/CDN 切换的完整编排逻辑，所有入口（购买后自动、手动、定时任务）
 * 统一调用本服务，保证标记一致。
 *
 * 职责：
 *  1. 从源（注册商）同步 DNS 记录到本地
 *  2. 在目标 DNS 服务商添加域名（Zone）并获取 NS
 *  3. 在注册商处修改 NS 指向目标
 *  4. 将本地记录推送到目标 DNS 服务商
 *  5. 正确设置 Domain 标记（dns_switch_pending / dns_migration_pending / dns_provider / dns_account_id / nameservers）
 *  6. 更新 DomainPool 状态
 */
class DnsSwitchService
{
    private DomainRegistrarResolverService $registrarResolver;
    private DomainResolveService $resolveService;
    private DnsProviderDetector $dnsDetector;

    public function __construct(
        DomainRegistrarResolverService $registrarResolver,
        DomainResolveService $resolveService,
        DnsProviderDetector $dnsDetector
    ) {
        $this->registrarResolver = $registrarResolver;
        $this->resolveService = $resolveService;
        $this->dnsDetector = $dnsDetector;
    }

    /**
     * 执行 DNS 切换（同步操作，适用于手动 / Cron）
     *
     * @param Domain $domain 域名模型（必须已持久化）
     * @param DomainRegistrarAccount $targetAccount 目标 DNS 服务商账户
     * @param callable|null $onStep 进度回调 fn(string $event, array $data): void
     * @return array{success: bool, message: string, nameservers: string[], push_success: bool, push_added: int, push_failed: int}
     */
    public function executeDnsSwitch(
        Domain $domain,
        DomainRegistrarAccount $targetAccount,
        ?callable $onStep = null
    ): array {
        $domainName = $domain->getDomain();
        $targetCode = (string) $targetAccount->getRegistrarCode();
        $targetCredentials = $targetAccount->getCredentials();
        $targetAccountId = (int) $targetAccount->getAccountId();
        $logCh = 'dns_cdn_switch';

        $notify = static function (string $event, array $data) use ($onStep): void {
            if ($onStep !== null) {
                $onStep($event, $data);
            }
        };

        $targetAdapter = $this->registrarResolver->getAdapter($targetCode);
        if ($targetAdapter === null || !$targetAdapter->supportsDnsManagement()) {
            return $this->fail(__('目标账户 %{1} 不支持 DNS 管理', [$targetCode]));
        }

        // ── Step 1: 从源（注册商）同步 DNS 记录到本地 ──
        $notify('sync_records', ['domain' => $domainName, 'message' => __('从注册商同步 DNS 记录到本地')]);
        $registrarAccountId = (int) $domain->getAccountId();
        if ($registrarAccountId <= 0) {
            return $this->fail(__('域名 %{1} 未关联注册商账户，无法切换', [$domainName]));
        }

        $sourceAccount = $this->loadAccount($registrarAccountId);
        if ($sourceAccount === null) {
            return $this->fail(__('注册商账户 ID %{1} 不存在', [$registrarAccountId]));
        }

        $sourceAdapter = $this->registrarResolver->getAdapter($sourceAccount->getRegistrarCode());
        if ($sourceAdapter === null) {
            return $this->fail(__('注册商适配器 %{1} 不存在', [$sourceAccount->getRegistrarCode()]));
        }

        $this->syncRecordsFromSource($domain, $sourceAccount);
        $notify('sync_records_done', ['domain' => $domainName, 'message' => __('DNS 记录同步完成')]);
        w_log_info(__('[DnsSwitchService] %{1} Step1: 从 %{2} 同步记录完成', [$domainName, $sourceAccount->getRegistrarCode()]), [], $logCh);

        // ── Step 2: 在目标获取 NS（会自动 addZone） ──
        $notify('add_zone', ['domain' => $domainName, 'message' => __('获取目标 Nameserver（%{1}）', [$targetCode])]);
        $nsResult = $targetAdapter->getProviderNameservers($targetCredentials, $domainName);
        if (!($nsResult['success'] ?? false) || empty($nsResult['nameservers'])) {
            $nsMsg = $nsResult['message'] ?? __('无法获取目标 Nameserver');
            w_log_error(__('[DnsSwitchService] %{1} 获取目标 NS 失败：%{2}', [$domainName, $nsMsg]), [], $logCh);
            return $this->fail($nsMsg);
        }
        $targetNs = $nsResult['nameservers'];
        $notify('add_zone_done', ['domain' => $domainName, 'nameservers' => $targetNs, 'message' => __('目标 NS：%{1}', [\implode(', ', $targetNs)])]);
        w_log_info(__('[DnsSwitchService] %{1} Step2: 目标 NS=%{2}', [$domainName, \implode(', ', $targetNs)]), [], $logCh);

        // ── Step 3: 在注册商处修改 NS ──
        if (!($sourceAdapter instanceof NameserverSwitchInterface)) {
            return $this->fail(__('注册商 %{1} 不支持修改 NS', [$sourceAccount->getRegistrarCode()]));
        }

        $notify('switch_ns', ['domain' => $domainName, 'message' => __('在注册商 %{1} 切换 NS', [$sourceAccount->getRegistrarCode()])]);
        $updateResult = $sourceAdapter->updateNameservers($domainName, $targetNs, $sourceAccount->getCredentials());
        if (!($updateResult['success'] ?? false)) {
            $updateMsg = $updateResult['message'] ?? __('NS 切换失败');
            w_log_error(__('[DnsSwitchService] %{1} NS 切换失败：%{2}', [$domainName, $updateMsg]), [], $logCh);
            return $this->fail($updateMsg);
        }
        $notify('switch_ns_done', ['domain' => $domainName, 'message' => __('NS 切换成功')]);
        w_log_info(__('[DnsSwitchService] %{1} Step3: NS 切换成功', [$domainName]), [], $logCh);

        // ── Step 4: 推送 DNS 记录到目标 ──
        $notify('push_records', ['domain' => $domainName, 'message' => __('推送 DNS 记录到 %{1}', [$targetCode])]);
        $pushResult = $this->resolveService->pushRecordsToProvider($domain, $targetAccount, null);
        $pushSuccess = $pushResult['success'] ?? false;
        $pushAdded = $pushResult['added'] ?? 0;
        $pushFailed = $pushResult['failed'] ?? 0;
        $notify('push_records_done', [
            'domain' => $domainName,
            'push_success' => $pushSuccess,
            'added' => $pushAdded,
            'failed' => $pushFailed,
            'message' => __('推送完成：成功 %{1}，失败 %{2}', [(string) $pushAdded, (string) $pushFailed]),
        ]);
        w_log_info(__('[DnsSwitchService] %{1} Step4: push success=%{2}, added=%{3}, failed=%{4}', [
            $domainName, $pushSuccess ? 'true' : 'false', (string) $pushAdded, (string) $pushFailed,
        ]), [], $logCh);

        // ── Step 5: 更新 Domain 标记 ──
        $domain->setNameservers($targetNs);
        $domain->setDnsProvider($targetCode);
        $domain->setDnsAccountId($targetAccountId);
        $domain->setDnsSwitchPending(0);
        $domain->setDnsMigrationPending($pushSuccess ? 0 : 1);
        $domain->forceCheck(false)->save();
        w_log_info(__('[DnsSwitchService] %{1} Step5: 域名标记已更新', [$domainName]), [], $logCh);

        // ── Step 6: 更新 DomainPool 状态 ──
        $cdnProvider = $this->dnsDetector->isCdnProvider($targetCode) ? $targetCode : '';
        $this->updateDomainPoolStatus($domainName, $targetCode, $cdnProvider);
        w_log_info(__('[DnsSwitchService] %{1} Step6: DomainPool 状态已更新', [$domainName]), [], $logCh);

        $notify('complete', ['domain' => $domainName, 'message' => __('DNS 切换完成')]);

        return [
            'success' => true,
            'message' => __('DNS 切换成功：%{1} → %{2}', [$domainName, $targetCode]),
            'nameservers' => $targetNs,
            'push_success' => $pushSuccess,
            'push_added' => $pushAdded,
            'push_failed' => $pushFailed,
        ];
    }

    /**
     * 标记域名需要 DNS 切换（购买后 / 手动设定目标但不立即执行）
     *
     * 仅写标记，不执行实际切换。由 DnsCdnAutoSwitch 定时任务消费。
     */
    public function markPendingSwitch(Domain $domain, int $targetDnsAccountId, string $targetDnsProvider): void
    {
        $domain->setDnsAccountId($targetDnsAccountId);
        $domain->setDnsProvider($targetDnsProvider);
        $domain->setDnsSwitchPending(1);
        $domain->forceCheck(false)->save();

        w_log_info(__('[DnsSwitchService] 域名 %{1} 已标记 dns_switch_pending=1, target=%{2}, account_id=%{3}', [
            $domain->getDomain(), $targetDnsProvider, (string) $targetDnsAccountId,
        ]), [], 'dns_cdn_switch');
    }

    /**
     * 标记 DomainPool 为 switching（切换前调用）
     */
    public function markPoolSwitching(string $rootDomain, string $targetCode): void
    {
        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $detector = ObjectManager::getInstance(DnsProviderDetector::class);
        $isCdn = $detector->isCdnProvider($targetCode);

        $pools = $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_ROOT_DOMAIN, \strtolower($rootDomain))
            ->select()
            ->fetch()
            ->getItems();

        foreach ($pools as $pool) {
            $pool->setDnsStatus(DomainPool::INFRA_STATUS_SWITCHING);
            if ($isCdn) {
                $pool->setCdnStatus(DomainPool::INFRA_STATUS_SWITCHING);
            }
            $pool->save();
        }
    }

    /**
     * 从源（注册商）同步 DNS 记录到本地 DB
     *
     * 与 DomainResolveService::syncDnsRecords 不同：本方法显式指定账户，
     * 避免在 dns_account_id 已指向目标（尚未 addZone）时使用错误账户。
     */
    public function syncRecordsFromSource(Domain $domain, DomainRegistrarAccount $sourceAccount): array
    {
        $adapter = $this->registrarResolver->getAdapter($sourceAccount->getRegistrarCode());
        if ($adapter === null || !$adapter->supportsDnsManagement()) {
            return ['synced' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0, 'error' => __('源账户 %{1} 不支持 DNS 管理', [$sourceAccount->getRegistrarCode()])];
        }

        try {
            $remoteRecords = $adapter->getDnsRecords($domain->getDomain(), $sourceAccount->getCredentials());
        } catch (\Throwable $e) {
            w_log_warning(__('[DnsSwitchService] syncRecordsFromSource %{1} 失败（非致命）：%{2}', [$domain->getDomain(), $e->getMessage()]), [], 'dns_cdn_switch');
            return ['synced' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0, 'error' => $e->getMessage()];
        }

        $records = [];
        foreach ($remoteRecords as $r) {
            $records[] = [
                'type' => $r['type'] ?? 'A',
                'host' => $r['host'] ?? '@',
                'value' => $r['value'] ?? '',
                'ttl' => $r['ttl'] ?? 600,
                'priority' => $r['priority'] ?? 0,
                'remote_record_id' => $r['record_id'] ?? '',
            ];
        }

        $dnsRecordModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainDnsRecord::class);
        $result = $dnsRecordModel->syncRecords($domain->getDomainId(), $records);
        $result['error'] = '';
        return $result;
    }

    private function updateDomainPoolStatus(string $rootDomain, string $dnsProvider, string $cdnProvider): void
    {
        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $pools = $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_ROOT_DOMAIN, \strtolower($rootDomain))
            ->select()
            ->fetch()
            ->getItems();

        foreach ($pools as $pool) {
            $pool->setDnsProvider($dnsProvider);
            $pool->setDnsStatus(DomainPool::INFRA_STATUS_READY);
            if ($cdnProvider !== '') {
                $pool->setCdnStatus(DomainPool::INFRA_STATUS_READY);
            }
            $pool->save();
        }
    }

    private function loadAccount(int $accountId): ?DomainRegistrarAccount
    {
        if ($accountId <= 0) {
            return null;
        }
        $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
        $account->load($accountId);
        return $account->getAccountId() ? $account : null;
    }

    /**
     * @return array{success: false, message: string, nameservers: string[], push_success: false, push_added: 0, push_failed: 0}
     */
    private function fail(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'nameservers' => [],
            'push_success' => false,
            'push_added' => 0,
            'push_failed' => 0,
        ];
    }
}
