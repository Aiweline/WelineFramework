<?php

declare(strict_types=1);

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Service\DnsProviderDetector;
use Weline\Websites\Service\DomainRegistrarResolverService;
use Weline\Websites\Service\DomainResolveService;

/**
 * 定时任务：域名购买后自动切换 DNS/CDN 服务商
 *
 * 当购买域名时选择了非注册商自带的 DNS/CDN 服务商（如 Cloudflare），
 * 本任务在域名生命周期完成（就绪）后自动完成 NS 切换和 DNS 记录迁移。
 */
class DnsCdnAutoSwitch implements CronTaskInterface
{
    public function name(): string
    {
        return __('DNS/CDN 自动切换');
    }

    public function execute_name(): string
    {
        return 'dns_cdn_auto_switch';
    }

    public function tip(): string
    {
        return __('域名就绪后自动切换 DNS/CDN 服务商并迁移记录');
    }

    public function cron_time(): string
    {
        return '*/3 * * * *';
    }

    public function unlock_timeout(int $minute = 10): int
    {
        return $minute;
    }

    public function execute(): string
    {
        try {
            $domainModel = ObjectManager::getInstance(Domain::class);
            $rows = $domainModel->clearQuery()
                ->where(Domain::schema_fields_DNS_SWITCH_PENDING, 1)
                ->select()
                ->fetchArray();

            if ($rows === []) {
                return '';
            }

            $total = \count($rows);
            w_log_info(__('[DnsCdnAutoSwitch] 开始执行，待处理域名数=%{1}', [(string) $total]), [], 'dns_cdn_auto_switch');

            $success = 0;
            $skipped = 0;
            $failed = 0;
            $errors = [];

            foreach ($rows as $row) {
                $domain = clone $domainModel;
                $domain->setData($row);
                $domainName = $domain->getDomain();

                try {
                    w_log_info(__('[DnsCdnAutoSwitch] 开始处理域名：%{1}', [$domainName]), [], 'dns_cdn_auto_switch');
                    $result = $this->processDomain($domain);
                    if ($result === true) {
                        $success++;
                        w_log_info(__('[DnsCdnAutoSwitch] 域名 %{1} 切换成功', [$domainName]), [], 'dns_cdn_auto_switch');
                    } elseif ($result === null) {
                        $skipped++;
                        w_log_info(__('[DnsCdnAutoSwitch] 域名 %{1} 跳过（生命周期未就绪）', [$domainName]), [], 'dns_cdn_auto_switch');
                    } else {
                        $failed++;
                        $errors[] = $domainName . ': ' . $result;
                        w_log_error(__('[DnsCdnAutoSwitch] 域名 %{1} 切换失败：%{2}', [$domainName, $result]), [], 'dns_cdn_auto_switch');
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = $domainName . ': ' . $e->getMessage();
                    w_log_error(__('[DnsCdnAutoSwitch] 域名 %{1} 异常：%{2}', [$domainName, $e->getMessage()]), [], 'dns_cdn_auto_switch');
                }
            }

            $message = \sprintf(
                'DNS/CDN 自动切换: 待处理%d, 成功%d, 跳过(未就绪)%d, 失败%d',
                $total,
                $success,
                $skipped,
                $failed
            );

            if ($errors !== []) {
                $message .= ' | ' . \implode('; ', \array_slice($errors, 0, 5));
            }

            w_log_info(__('[DnsCdnAutoSwitch] 执行完毕：%{1}', [$message]), [], 'dns_cdn_auto_switch');
            return $message;
        } catch (\Throwable $e) {
            $msg = 'DNS/CDN 自动切换任务异常: ' . $e->getMessage();
            w_log_error($msg, [], 'dns_cdn_auto_switch');
            return $msg;
        }
    }

    /**
     * @return true|null|string true=成功, null=跳过（未就绪）, string=错误信息
     */
    private function processDomain(Domain $domain): true|null|string
    {
        $domainName = $domain->getDomain();
        $targetDnsAccountId = (int) $domain->getDnsAccountId();
        $logCh = 'dns_cdn_auto_switch';

        if ($targetDnsAccountId <= 0) {
            $domain->setDnsSwitchPending(0);
            $domain->forceCheck(false)->save();
            w_log_warning(__('[DnsCdnAutoSwitch] %{1} 目标 DNS 账户未设置，取消切换', [$domainName]), [], $logCh);
            return __('目标 DNS 账户未设置，取消切换');
        }

        w_log_info(__('[DnsCdnAutoSwitch] %{1} 目标 DNS 账户 ID=%{2}，检查生命周期', [$domainName, (string) $targetDnsAccountId]), [], $logCh);

        try {
            $lifecycle = w_query('saas', 'getDomainLifecycleStatus', ['domain' => $domainName]);
            if (!empty($lifecycle['success']) && !empty($lifecycle['data']['order'])) {
                $status = (string) ($lifecycle['data']['order']['status'] ?? '');
                w_log_info(__('[DnsCdnAutoSwitch] %{1} 生命周期状态=%{2}', [$domainName, $status]), [], $logCh);
                if ($status !== 'completed' && $status !== 'failed') {
                    return null;
                }
                if ($status === 'failed') {
                    return null;
                }
            } else {
                w_log_info(__('[DnsCdnAutoSwitch] %{1} 无生命周期数据，视为可切换', [$domainName]), [], $logCh);
            }
        } catch (\Throwable $e) {
            w_log_info(__('[DnsCdnAutoSwitch] %{1} Saas 查询失败（%{2}），视为可切换', [$domainName, $e->getMessage()]), [], $logCh);
        }

        $registrarAccountId = (int) $domain->getAccountId();
        if ($registrarAccountId <= 0) {
            $domain->setDnsSwitchPending(0);
            $domain->forceCheck(false)->save();
            w_log_warning(__('[DnsCdnAutoSwitch] %{1} 未关联注册商账户', [$domainName]), [], $logCh);
            return __('域名未关联注册商账户，无法修改 NS');
        }

        $targetAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
        $targetAccount->load($targetDnsAccountId);
        if (!$targetAccount->getAccountId()) {
            $domain->setDnsSwitchPending(0);
            $domain->forceCheck(false)->save();
            w_log_error(__('[DnsCdnAutoSwitch] %{1} 目标 DNS 账户 ID %{2} 不存在', [$domainName, (string) $targetDnsAccountId]), [], $logCh);
            return __('目标 DNS 账户 ID %{1} 不存在', [$targetDnsAccountId]);
        }

        $resolverService = ObjectManager::getInstance(DomainRegistrarResolverService::class);
        $resolveService = ObjectManager::getInstance(DomainResolveService::class);

        $targetAdapter = $resolverService->getAdapter($targetAccount->getRegistrarCode());
        if ($targetAdapter === null || !$targetAdapter->supportsDnsManagement()) {
            $domain->setDnsSwitchPending(0);
            $domain->forceCheck(false)->save();
            w_log_error(__('[DnsCdnAutoSwitch] %{1} 目标适配器 %{2} 不支持 DNS 管理', [$domainName, $targetAccount->getRegistrarCode()]), [], $logCh);
            return __('目标账户 %{1} 不支持 DNS 管理', [$targetAccount->getRegistrarCode()]);
        }

        $targetCode = (string) $targetAccount->getRegistrarCode();
        $targetCredentials = $targetAccount->getCredentials();

        w_log_info(__('[DnsCdnAutoSwitch] %{1} 源账户 ID=%{2}，目标=%{3}(%{4})', [
            $domainName, (string) $registrarAccountId, $targetCode, (string) $targetDnsAccountId,
        ]), [], $logCh);

        // 1. 标记 DomainPool switching
        w_log_info(__('[DnsCdnAutoSwitch] %{1} Step1: 标记 DomainPool switching', [$domainName]), [], $logCh);
        $this->markPoolSwitching($domainName, $targetCode);

        // 2. 同步当前 DNS 记录到本地
        w_log_info(__('[DnsCdnAutoSwitch] %{1} Step2: 同步当前 DNS 记录到本地', [$domainName]), [], $logCh);
        $resolveService->syncDnsRecords($domain);

        // 3. 获取目标 NS 并在注册商处修改
        $sourceAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
        $sourceAccount->load($registrarAccountId);
        if (!$sourceAccount->getAccountId()) {
            w_log_error(__('[DnsCdnAutoSwitch] %{1} 注册商账户 ID %{2} 不存在', [$domainName, (string) $registrarAccountId]), [], $logCh);
            return __('注册商账户 ID %{1} 不存在', [$registrarAccountId]);
        }

        $sourceAdapter = $resolverService->getAdapter($sourceAccount->getRegistrarCode());
        if ($sourceAdapter === null) {
            w_log_error(__('[DnsCdnAutoSwitch] %{1} 注册商适配器 %{2} 不存在', [$domainName, $sourceAccount->getRegistrarCode()]), [], $logCh);
            return __('注册商适配器 %{1} 不存在', [$sourceAccount->getRegistrarCode()]);
        }

        w_log_info(__('[DnsCdnAutoSwitch] %{1} Step3: 获取目标 NS（%{2}）', [$domainName, $targetCode]), [], $logCh);
        $nsResult = $targetAdapter->getProviderNameservers($targetCredentials, $domainName);
        if (!($nsResult['success'] ?? false) || empty($nsResult['nameservers'])) {
            $nsMsg = $nsResult['message'] ?? __('无法获取目标 Nameserver');
            w_log_error(__('[DnsCdnAutoSwitch] %{1} 获取目标 NS 失败：%{2}', [$domainName, $nsMsg]), [], $logCh);
            return $nsMsg;
        }

        $targetNs = $nsResult['nameservers'];
        w_log_info(__('[DnsCdnAutoSwitch] %{1} 获取到目标 NS=%{2}', [$domainName, \implode(', ', $targetNs)]), [], $logCh);

        w_log_info(__('[DnsCdnAutoSwitch] %{1} Step4: 在注册商 %{2} 切换 NS', [$domainName, $sourceAccount->getRegistrarCode()]), [], $logCh);
        $updateResult = $sourceAdapter->updateNameservers(
            $domainName,
            $targetNs,
            $sourceAccount->getCredentials()
        );

        if (!($updateResult['success'] ?? false)) {
            $updateMsg = $updateResult['message'] ?? __('NS 切换失败');
            w_log_error(__('[DnsCdnAutoSwitch] %{1} NS 切换失败：%{2}', [$domainName, $updateMsg]), [], $logCh);
            return $updateMsg;
        }
        w_log_info(__('[DnsCdnAutoSwitch] %{1} NS 切换成功', [$domainName]), [], $logCh);

        // 4. 推送 DNS 记录到新服务商
        w_log_info(__('[DnsCdnAutoSwitch] %{1} Step5: 推送 DNS 记录到 %{2}', [$domainName, $targetCode]), [], $logCh);
        $pushResult = $resolveService->pushRecordsToProvider($domain, $targetAccount, null);
        $pushSuccess = $pushResult['success'] ?? false;
        $pushAdded = $pushResult['added'] ?? 0;
        $pushFailed = $pushResult['failed'] ?? 0;
        w_log_info(__('[DnsCdnAutoSwitch] %{1} 推送结果：success=%{2}, added=%{3}, failed=%{4}', [
            $domainName, $pushSuccess ? 'true' : 'false', (string) $pushAdded, (string) $pushFailed,
        ]), [], $logCh);

        // 5. 更新域名状态
        w_log_info(__('[DnsCdnAutoSwitch] %{1} Step6: 更新域名状态', [$domainName]), [], $logCh);
        $domain->setNameservers($targetNs);
        $domain->setDnsProvider($targetCode);
        $domain->setDnsAccountId($targetDnsAccountId);
        $domain->setDnsSwitchPending(0);
        $domain->setDnsMigrationPending($pushSuccess ? 0 : 1);
        $domain->forceCheck(false)->save();

        // 6. 更新 DomainPool 状态
        w_log_info(__('[DnsCdnAutoSwitch] %{1} Step7: 更新 DomainPool 状态', [$domainName]), [], $logCh);
        $detector = ObjectManager::getInstance(DnsProviderDetector::class);
        $cdnProvider = $detector->isCdnProvider($targetCode) ? $targetCode : '';
        $this->updatePoolStatus($domainName, $targetCode, $cdnProvider);

        w_log_info(__('[DnsCdnAutoSwitch] %{1} 全部完成，dns_provider=%{2}, cdn=%{3}', [$domainName, $targetCode, $cdnProvider ?: 'none']), [], $logCh);
        return true;
    }

    /**
     * 标记根域名下所有 DomainPool 记录的 dns_status/cdn_status 为 switching
     */
    private function markPoolSwitching(string $rootDomain, string $targetCode): void
    {
        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $detector = ObjectManager::getInstance(DnsProviderDetector::class);
        $isCdn = $detector->isCdnProvider($targetCode);

        $pools = $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_ROOT_DOMAIN, \strtolower($rootDomain))
            ->select()
            ->fetch();

        foreach ($pools as $pool) {
            $pool->setDnsStatus(DomainPool::INFRA_STATUS_SWITCHING);
            if ($isCdn) {
                $pool->setCdnStatus(DomainPool::INFRA_STATUS_SWITCHING);
            }
            $pool->save();
        }
    }

    /**
     * 切换完成后更新 DomainPool 的 dns_provider、dns_status、cdn_status
     */
    private function updatePoolStatus(string $rootDomain, string $dnsProvider, string $cdnProvider): void
    {
        $poolModel = ObjectManager::getInstance(DomainPool::class);

        $pools = $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_ROOT_DOMAIN, \strtolower($rootDomain))
            ->select()
            ->fetch();

        foreach ($pools as $pool) {
            $pool->setDnsProvider($dnsProvider);
            $pool->setDnsStatus(DomainPool::INFRA_STATUS_READY);
            if ($cdnProvider !== '') {
                $pool->setCdnStatus(DomainPool::INFRA_STATUS_READY);
            }
            $pool->save();
        }
    }
}
