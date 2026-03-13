<?php

declare(strict_types=1);

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Service\DnsSwitchService;

/**
 * 定时任务：域名购买后自动切换 DNS/CDN 服务商
 *
 * 当购买域名时选择了非注册商自带的 DNS/CDN 服务商（如 Cloudflare），
 * 本任务在域名生命周期完成（就绪）后，通过 DnsSwitchService 完成切换。
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

        // ── 前置校验：不可恢复的配置缺失，清掉标记 ──
        if ($targetDnsAccountId <= 0) {
            $domain->setDnsSwitchPending(0);
            $domain->forceCheck(false)->save();
            w_log_warning(__('[DnsCdnAutoSwitch] %{1} 目标 DNS 账户未设置，取消切换', [$domainName]), [], $logCh);
            return __('目标 DNS 账户未设置，取消切换');
        }

        // ── 生命周期检查 ──
        w_log_info(__('[DnsCdnAutoSwitch] %{1} 目标 DNS 账户 ID=%{2}，检查生命周期', [$domainName, (string) $targetDnsAccountId]), [], $logCh);
        try {
            $lifecycle = w_query('saas', 'getDomainLifecycleStatus', ['domain' => $domainName]);
            if (!empty($lifecycle['success']) && !empty($lifecycle['data']['order'])) {
                $status = (string) ($lifecycle['data']['order']['status'] ?? '');
                w_log_info(__('[DnsCdnAutoSwitch] %{1} 生命周期状态=%{2}', [$domainName, $status]), [], $logCh);
                if ($status !== 'completed') {
                    if ($status === 'failed') {
                        $domain->setDnsSwitchPending(0);
                        $domain->forceCheck(false)->save();
                        w_log_warning(__('[DnsCdnAutoSwitch] %{1} 生命周期已失败，取消切换', [$domainName]), [], $logCh);
                        return __('生命周期已失败，取消切换');
                    }
                    return null;
                }
            } else {
                w_log_info(__('[DnsCdnAutoSwitch] %{1} 无生命周期数据，视为可切换', [$domainName]), [], $logCh);
            }
        } catch (\Throwable $e) {
            w_log_info(__('[DnsCdnAutoSwitch] %{1} Saas 查询失败（%{2}），视为可切换', [$domainName, $e->getMessage()]), [], $logCh);
        }

        // ── 加载目标账户 ──
        $targetAccount = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
        $targetAccount->load($targetDnsAccountId);
        if (!$targetAccount->getAccountId()) {
            $domain->setDnsSwitchPending(0);
            $domain->forceCheck(false)->save();
            w_log_error(__('[DnsCdnAutoSwitch] %{1} 目标 DNS 账户 ID %{2} 不存在', [$domainName, (string) $targetDnsAccountId]), [], $logCh);
            return __('目标 DNS 账户 ID %{1} 不存在', [$targetDnsAccountId]);
        }

        // ── 标记 DomainPool switching ──
        $switchService = ObjectManager::getInstance(DnsSwitchService::class);
        $switchService->markPoolSwitching($domainName, (string) $targetAccount->getRegistrarCode());

        // ── 委托 DnsSwitchService 执行全流程 ──
        // dns_switch_pending 由 DnsSwitchService 在切换成功时置 0
        // 失败时不触碰标记，下次 cron 继续重试
        $result = $switchService->executeDnsSwitch($domain, $targetAccount);

        if ($result['success']) {
            return true;
        }

        return $result['message'];
    }
}
