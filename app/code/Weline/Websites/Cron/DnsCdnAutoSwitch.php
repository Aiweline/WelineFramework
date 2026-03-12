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

            $success = 0;
            $skipped = 0;
            $failed = 0;
            $errors = [];

            foreach ($rows as $row) {
                $domain = clone $domainModel;
                $domain->setData($row);
                $domainName = $domain->getDomain();

                try {
                    $result = $this->processDomain($domain);
                    if ($result === true) {
                        $success++;
                    } elseif ($result === null) {
                        $skipped++;
                    } else {
                        $failed++;
                        $errors[] = $domainName . ': ' . $result;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = $domainName . ': ' . $e->getMessage();
                    w_log_error(__('DNS/CDN 自动切换异常：%{domain} - %{error}', [
                        'domain' => $domainName,
                        'error' => $e->getMessage(),
                    ]), [], 'dns_cdn_auto_switch');
                }
            }

            $message = \sprintf(
                'DNS/CDN 自动切换: 待处理%d, 成功%d, 跳过(未就绪)%d, 失败%d',
                \count($rows),
                $success,
                $skipped,
                $failed
            );

            if ($errors !== []) {
                $message .= ' | ' . \implode('; ', \array_slice($errors, 0, 5));
            }

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

        if ($targetDnsAccountId <= 0) {
            $domain->setDnsSwitchPending(0);
            $domain->forceCheck(false)->save();
            return __('目标 DNS 账户未设置，取消切换');
        }

        // 检查生命周期是否完成
        try {
            $lifecycle = w_query('saas', 'getDomainLifecycleStatus', ['domain' => $domainName]);
            if (!empty($lifecycle['success']) && !empty($lifecycle['data']['order'])) {
                $status = (string) ($lifecycle['data']['order']['status'] ?? '');
                if ($status !== 'completed' && $status !== 'failed') {
                    return null;
                }
                if ($status === 'failed') {
                    return null;
                }
            }
        } catch (\Throwable) {
            // Saas 未安装或查询失败，视为可切换（无生命周期跟踪）
        }

        $registrarAccountId = (int) $domain->getAccountId();
        if ($registrarAccountId <= 0) {
            $domain->setDnsSwitchPending(0);
            $domain->forceCheck(false)->save();
            return __('域名未关联注册商账户，无法修改 NS');
        }

        $targetAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
        $targetAccount->load($targetDnsAccountId);
        if (!$targetAccount->getAccountId()) {
            $domain->setDnsSwitchPending(0);
            $domain->forceCheck(false)->save();
            return __('目标 DNS 账户 ID %{1} 不存在', [$targetDnsAccountId]);
        }

        $resolverService = ObjectManager::getInstance(DomainRegistrarResolverService::class);
        $resolveService = ObjectManager::getInstance(DomainResolveService::class);

        $targetAdapter = $resolverService->getAdapter($targetAccount->getRegistrarCode());
        if ($targetAdapter === null || !$targetAdapter->supportsDnsManagement()) {
            $domain->setDnsSwitchPending(0);
            $domain->forceCheck(false)->save();
            return __('目标账户 %{1} 不支持 DNS 管理', [$targetAccount->getRegistrarCode()]);
        }

        $targetCode = (string) $targetAccount->getRegistrarCode();
        $targetCredentials = $targetAccount->getCredentials();

        // 1. 标记 DomainPool 中的 dns_status / cdn_status 为 switching
        $this->markPoolSwitching($domainName, $targetCode);

        // 2. 同步当前 DNS 记录到本地
        $resolveService->syncDnsRecords($domain);

        // 3. 获取目标 NS 并在注册商处修改
        $sourceAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
        $sourceAccount->load($registrarAccountId);
        if (!$sourceAccount->getAccountId()) {
            return __('注册商账户 ID %{1} 不存在', [$registrarAccountId]);
        }

        $sourceAdapter = $resolverService->getAdapter($sourceAccount->getRegistrarCode());
        if ($sourceAdapter === null) {
            return __('注册商适配器 %{1} 不存在', [$sourceAccount->getRegistrarCode()]);
        }

        $nsResult = $targetAdapter->getProviderNameservers($targetCredentials, $domainName);
        if (!($nsResult['success'] ?? false) || empty($nsResult['nameservers'])) {
            return $nsResult['message'] ?? __('无法获取目标 Nameserver');
        }

        $targetNs = $nsResult['nameservers'];
        $updateResult = $sourceAdapter->updateNameservers(
            $domainName,
            $targetNs,
            $sourceAccount->getCredentials()
        );

        if (!($updateResult['success'] ?? false)) {
            return $updateResult['message'] ?? __('NS 切换失败');
        }

        // 4. 推送 DNS 记录到新服务商
        $pushResult = $resolveService->pushRecordsToProvider($domain, $targetAccount, null);
        $pushSuccess = $pushResult['success'] ?? false;

        // 5. 更新域名状态
        $domain->setNameservers($targetNs);
        $domain->setDnsProvider($targetCode);
        $domain->setDnsAccountId($targetDnsAccountId);
        $domain->setDnsSwitchPending(0);
        $domain->setDnsMigrationPending($pushSuccess ? 0 : 1);
        $domain->forceCheck(false)->save();

        // 6. 更新 DomainPool 状态
        $detector = ObjectManager::getInstance(DnsProviderDetector::class);
        $cdnProvider = $detector->isCdnProvider($targetCode) ? $targetCode : '';
        $this->updatePoolStatus($domainName, $targetCode, $cdnProvider);

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
