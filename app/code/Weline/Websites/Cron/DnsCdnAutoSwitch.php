<?php

declare(strict_types=1);

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\DnsSwitchService;
use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * 由 {@see WebsitesOperationsMaintenance} 调用。
 */
#[CronTestHelp(
    description: '购买后 DNS/CDN 自动切换：将「延迟切换」中已注册完成的根域转为待执行，再对「待执行」根域调用 DNS 接口把 Nameserver 切到目标服务商。',
    examples: ['php bin/w cron:test --task=dns_cdn_auto_switch --domain=example.com -v'],
    manual_help: [
        '逻辑：① dns_switch_deferred=1 且生命周期已完成的根域 → 置为 dns_switch_pending ② dns_switch_pending=1 的根域 → DnsSwitchService：同服务商跳过改 NS；异注册商时若注册商侧 NS 已与目标一致也跳过 updateNameservers；成功后 dns_cutover_complete=1，并默认传入 cdn_account + verify_cdn（若根域已绑 CDN 账户）。',
        '--domain= 仅处理该根域；不指定则处理全部待切换。',
    ],
)]
class DnsCdnAutoSwitch
{
    use WebsitesCronTestRunnerTrait;

    public function execute(): string
    {
        try {
            $domainModel = ObjectManager::getInstance(Domain::class);

            // 将「延迟切换」中已注册完成的域名转为待执行（注册中不尝试切换，等生命周期 completed 后再执行）
            $deferredRows = $domainModel->clearQuery()
                ->where(Domain::schema_fields_DNS_SWITCH_DEFERRED, 1)
                ->select()
                ->fetchArray();
            foreach ($deferredRows as $row) {
                $d = clone $domainModel;
                $d->setData($row);
                $domainName = $d->getDomain();
                if (!WebsitesCronTestContext::matchesSubject($domainName, $domainName)) {
                    WebsitesCronTestContext::skipNote($domainName, 'deferred dns switch');
                    continue;
                }
                try {
                    $lifecycle = w_query('websites', 'getDomainLifecycleStatus', ['domain' => $domainName]);
                    WebsitesCronTestContext::detail('deferred_lifecycle', ['domain' => $domainName, 'lifecycle' => $lifecycle]);
                    if (!empty($lifecycle['success']) && !empty($lifecycle['data']['order'])) {
                        $status = (string) ($lifecycle['data']['order']['status'] ?? '');
                        if ($status === 'completed') {
                            $d->setDnsSwitchPending(1);
                            $d->setDnsSwitchDeferred(0);
                            $d->forceCheck(false)->save();
                            w_log_info(__('[DnsCdnAutoSwitch] 域名 %{1} 注册已完成，已加入待切换队列', [$domainName]), [], 'dns_cdn_auto_switch');
                        }
                    }
                } catch (\Throwable $e) {
                    w_log_warning(__('[DnsCdnAutoSwitch] 检查延迟切换 %{1} 生命周期失败：%{2}', [$domainName, $e->getMessage()]), [], 'dns_cdn_auto_switch');
                }
            }

            $rows = $domainModel->clearQuery()
                ->where(Domain::schema_fields_DNS_SWITCH_PENDING, 1)
                ->select()
                ->fetchArray();
            if (WebsitesCronTestContext::getDomainFilter() !== null) {
                $rows = \array_values(\array_filter(
                    $rows,
                    static function (array $r) use ($domainModel): bool {
                        $m = clone $domainModel;
                        $m->setData($r);

                        return WebsitesCronTestContext::matchesSubject($m->getDomain(), $m->getDomain());
                    }
                ));
            }

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
                WebsitesCronTestContext::detail('pending_switch_row', [
                    'domain' => $domainName,
                    'dns_account_id' => $domain->getDnsAccountId(),
                    'cdn_account_id' => $domain->getCdnAccountId(),
                    'dns_switch_pending' => $domain->getDnsSwitchPending(),
                ]);

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
            $lifecycle = w_query('websites', 'getDomainLifecycleStatus', ['domain' => $domainName]);
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
            w_log_info(__('[DnsCdnAutoSwitch] %{1} 生命周期查询失败（%{2}），视为可切换', [$domainName, $e->getMessage()]), [], $logCh);
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
        $switchOptions = $this->buildDnsSwitchWaitOptions($targetAccount);
        $cdnId = (int) $domain->getCdnAccountId();
        if ($cdnId > 0) {
            $cdnAcc = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $cdnAcc->load($cdnId);
            if ((int) $cdnAcc->getAccountId() > 0) {
                $switchOptions['cdn_account'] = $cdnAcc;
                $switchOptions['verify_cdn'] = true;
            }
        }
        $result = $switchService->executeDnsSwitch($domain, $targetAccount, null, $switchOptions);
        WebsitesCronTestContext::detail('executeDnsSwitch', ['domain' => $domainName, 'result' => $result]);

        if ($result['success']) {
            return true;
        }

        return $result['message'];
    }

    /**
     * 按 websites.env dns_switch 为 cron 开启「改 NS 后短等公网/DoH」，避免仅依赖本机解析器误判。
     *
     * @return array<string, mixed>
     */
    private function buildDnsSwitchWaitOptions(DomainRegistrarAccount $targetAccount): array
    {
        $ds = Env::module_env('Weline_Websites', 'dns_switch') ?? [];
        if (empty($ds['wait_public_ns_enabled'])) {
            return [];
        }
        $codes = $ds['wait_public_ns_provider_codes'] ?? ['cloudflare'];
        $codes = \is_array($codes) ? $codes : [];
        $codes = \array_map(static fn ($c) => \strtolower(\trim((string) $c)), $codes);
        $targetCode = \strtolower(\trim((string) $targetAccount->getRegistrarCode()));
        if ($targetCode === '' || !\in_array($targetCode, $codes, true)) {
            return [];
        }

        return [
            'wait_for_ns' => true,
            'wait_max_seconds' => (int) ($ds['wait_public_ns_max_seconds'] ?? 180),
            'wait_interval_seconds' => (int) ($ds['wait_public_ns_interval_seconds'] ?? 15),
            'ns_probe_cloudflare_doh' => (bool) ($ds['ns_probe_use_cloudflare_doh'] ?? true),
        ];
    }
}
