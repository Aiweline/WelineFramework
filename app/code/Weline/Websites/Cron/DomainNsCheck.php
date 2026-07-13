<?php
declare(strict_types=1);

/**
 * Weline Websites - 根域 NS 归属检测定时任务
 *
 * 定期检测所有根域名的 NS（Nameserver）归属情况
 * 判断域名是否托管到外部 DNS 服务商（如 Cloudflare）
 */

namespace Weline\Websites\Cron;

use Weline\Framework\Cron\Attribute\CronTestHelp;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainCronLockService;
use Weline\Websites\Service\DnsProviderDetector;
use Weline\Websites\Service\DomainNsMismatchNotifier;
use Weline\Websites\Service\DomainRootRegistrationSelfCorrectService;
use Weline\Websites\Service\SubdomainGeneratorService;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * 由 {@see WebsitesOperationsMaintenance} 整点调用。
 */
#[CronTestHelp(
    description: '根域 Nameserver 检测：查询根域 NS 记录，识别当前 DNS 服务商（如 Cloudflare、原注册商），用于后台展示与策略判断；可选比对「配置的 DNS 账户」并告警/白名单自愈。',
    examples: ['php bin/w cron:test --task=domain_ns_check --domain=example.com -v'],
    manual_help: [
        '逻辑：先纠正「子域已可建站但根域仍非 active」的脏数据；再对活跃根域做 NS 查询，根据 NS 主机名识别 dns_provider 并更新。',
        '若域名已绑定 dns_account_id 且非切换中：公网识别服务商与账户 registrar 不一致时，按 env websites.ns_check 冷却写告警日志（默认开）；自愈默认开，白名单空=全部根域，非空则仅列表内；自愈受 self_heal_cooldown_seconds 限制。',
        '--domain= 仅检测该根域；不指定则处理全部活跃根域。',
    ],
)]
class DomainNsCheck
{
    use WebsitesCronTestRunnerTrait;

    public function execute(): string
    {
        try {
            $domainModel = ObjectManager::getInstance(Domain::class);
            $dnsDetector = ObjectManager::getInstance(DnsProviderDetector::class);
            $promoted = ObjectManager::getInstance(DomainRootRegistrationSelfCorrectService::class)->correctBatch(300);

            $domains = $domainModel->clearQuery()
                ->where(Domain::schema_fields_STATUS, Domain::STATUS_ACTIVE)
                ->select()
                ->fetchArray();

            $total = \count($domains);
            $updated = 0;
            $cloudflare = 0;
            $original = 0;
            $errors = 0;
            $poolAdded = 0;
            $mismatchAlerts = 0;
            $selfHealQueued = 0;

            $subdomainGenerator = ObjectManager::getInstance(SubdomainGeneratorService::class);
            $nsMismatchNotifier = ObjectManager::getInstance(DomainNsMismatchNotifier::class);
            $cronLock = ObjectManager::getInstance(DomainCronLockService::class);

            foreach ($domains as $row) {
                $domain = ObjectManager::getInstance(Domain::class, [], false);
                $domain->setData($row);
                $dn = $domain->getDomain();
                if (!WebsitesCronTestContext::matchesSubject($dn, $dn)) {
                    WebsitesCronTestContext::skipNote($dn, 'ns check');
                    continue;
                }
                if ($cronLock->shouldSkipNonCertificateWorkForRootFqdn($dn)) {
                    WebsitesCronTestContext::skipNote($dn, 'cron_resolved lock');
                    continue;
                }
                WebsitesCronTestContext::detail('DomainNsCheck.row', ['domain' => $dn]);

                // 无论 NS 查询成功或失败，都立即确保子域名接入域名池
                try {
                    $poolResult = $subdomainGenerator->generateDefaultSubdomains($domain);
                    $poolAdded += $poolResult['added'] ?? 0;
                } catch (\Throwable $e) {
                    w_log_warning(__('子域名入池失败: %{1}, %{2}', [$domain->getDomain(), $e->getMessage()]), [], 'domain_ns_check');
                }

                try {
                    $nameservers = $domain->getNameservers();
                    $domainName = $domain->getDomain();
                    
                    // 实时查询 NS 记录
                    $liveNameservers = $this->queryNsRecords($domainName);
                    WebsitesCronTestContext::detail('queryNsRecords', ['domain' => $domainName, 'ns' => $liveNameservers]);
                    $needsSave = false;
                    
                    // 更新 NS 记录
                    if (!empty($liveNameservers) && $liveNameservers !== $nameservers) {
                        $domain->setNameservers($liveNameservers);
                        $nameservers = $liveNameservers;
                        $needsSave = true;
                    }
                    
                    // 检测 DNS 服务商
                    $dnsInfo = $dnsDetector->detect($nameservers, '');
                    $dnsProvider = $dnsInfo['provider'] ?? '';
                    
                    // 更新域名的 DNS 服务商字段
                    $currentProvider = $domain->getDnsProvider();
                    if ($currentProvider !== $dnsProvider) {
                        $domain->setDnsProvider($dnsProvider);
                        $needsSave = true;
                        $updated++;
                    }
                    
                    // 如果 DNS 服务商是 CDN 服务商，同步更新 cdn_provider
                    if ($dnsDetector->isCdnProvider($dnsProvider)) {
                        $currentCdnProvider = $domain->getCdnProvider();
                        if ($currentCdnProvider !== $dnsProvider) {
                            $domain->setCdnProvider($dnsProvider);
                            $needsSave = true;
                        }
                    }
                    
                    // 保存变更
                    if ($needsSave) {
                        $domain->forceCheck(false)->save();
                    }

                    // 同步根域下 DomainPool 的 DNS/CDN 状态
                    $poolModel = ObjectManager::getInstance(DomainPool::class);
                    $poolRows = $poolModel->clearQuery()
                        ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, (int) $domain->getDomainId())
                        ->select()
                        ->fetchArray();
                    $dnsStatus = $dnsProvider === '' || $dnsProvider === 'unknown'
                        ? DomainPool::INFRA_STATUS_PENDING
                        : DomainPool::INFRA_STATUS_READY;
                    $cdnStatus = $dnsDetector->isCdnProvider($dnsProvider)
                        ? DomainPool::INFRA_STATUS_READY
                        : DomainPool::INFRA_STATUS_PENDING;
                    foreach ($poolRows as $poolRow) {
                        $pool = ObjectManager::getInstance(DomainPool::class, [], false);
                        $pool->setData($poolRow);
                        $pool->setDnsProvider($dnsProvider);
                        $pool->setDnsStatus($dnsStatus);
                        $pool->setCdnStatus($cdnStatus);
                        $pool->calculateSiteReady();
                        $pool->save();
                    }

                    $probeResult = $nsMismatchNotifier->handleAfterLiveProbe($domain, $liveNameservers, $dnsProvider);
                    if ($probeResult['alerted'] ?? false) {
                        $mismatchAlerts++;
                    }
                    if ($probeResult['self_heal_queued'] ?? false) {
                        $selfHealQueued++;
                    }

                    if (\stripos($dnsProvider, 'cloudflare') !== false) {
                        $cloudflare++;
                    } elseif ($dnsProvider === '' || \stripos($dnsProvider, 'original') !== false) {
                        $original++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    w_log_warning("域名 {$row[Domain::schema_fields_DOMAIN]} NS 检测失败: {$e->getMessage()}", [], 'domain_ns_check');
                }
            }

            $message = \sprintf(
                'NS 检测完成: 共 %d 个根域, %d 个已更新, %d 个使用 Cloudflare, %d 个使用原注册商 NS, %d 个错误',
                $total,
                $updated,
                $cloudflare,
                $original,
                $errors
            );
            if ($promoted > 0) {
                $message .= \sprintf(', %s %d', (string) __('根域注册状态纠正'), $promoted);
            }
            if ($poolAdded > 0) {
                $message .= \sprintf(', 子域名入池 %d 个', $poolAdded);
            }
            if ($mismatchAlerts > 0) {
                $message .= \sprintf(', NS与DNS账户不一致告警(冷却内首次) %d 次', $mismatchAlerts);
            }
            if ($selfHealQueued > 0) {
                $message .= \sprintf(', NS自愈写入pending %d 个', $selfHealQueued);
            }

            w_log_info($message, [], 'domain_ns_check');

            return $message;
        } catch (\Throwable $e) {
            $errorMsg = '根域 NS 检测任务异常: ' . $e->getMessage();
            w_log_error($errorMsg, [], 'domain_ns_check');
            return $errorMsg;
        }
    }

    /**
     * 查询域名的 NS 记录
     */
    private function queryNsRecords(string $domain): array
    {
        $records = @\dns_get_record($domain, DNS_NS);
        
        if ($records === false || $records === []) {
            return [];
        }
        
        $nameservers = [];
        foreach ($records as $record) {
            if (isset($record['target'])) {
                $nameservers[] = \strtolower($record['target']);
            }
        }
        
        return $nameservers;
    }
}
