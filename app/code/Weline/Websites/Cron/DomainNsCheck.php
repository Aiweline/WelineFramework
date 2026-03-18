<?php
declare(strict_types=1);

/**
 * Weline Websites - 根域 NS 归属检测定时任务
 *
 * 定期检测所有根域名的 NS（Nameserver）归属情况
 * 判断域名是否托管到外部 DNS 服务商（如 Cloudflare）
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DnsProviderDetector;
use Weline\Websites\Service\SubdomainGeneratorService;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * 由 {@see WebsitesOperationsMaintenance} 在每小时整点附带执行。
 */
#[CronTestHelp(
    description: '根域 NS 检测与 DNS 服务商识别。',
    examples: ['php bin/w cron:test --task=domain_ns_check --domain=example.com -v'],
    manual_help: [
        '控制台 --domain= 只检测该根域 NS。',
        '后台「后缀」未解析时检测全部活跃根域。',
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

            $subdomainGenerator = ObjectManager::getInstance(SubdomainGeneratorService::class);

            foreach ($domains as $row) {
                $domain = ObjectManager::getInstance(Domain::class, [], false);
                $domain->setData($row);
                $dn = $domain->getDomain();
                if (!WebsitesCronTestContext::matchesSubject($dn, $dn)) {
                    WebsitesCronTestContext::skipNote($dn, 'ns check');
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
            if ($poolAdded > 0) {
                $message .= \sprintf(', 子域名入池 %d 个', $poolAdded);
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
