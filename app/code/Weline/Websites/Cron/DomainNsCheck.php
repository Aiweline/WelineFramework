<?php
declare(strict_types=1);

/**
 * Weline Websites - 根域 NS 归属检测定时任务
 *
 * 定期检测所有根域名的 NS（Nameserver）归属情况
 * 判断域名是否托管到外部 DNS 服务商（如 Cloudflare）
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Service\DnsProviderDetector;

class DomainNsCheck implements CronTaskInterface
{
    public function name(): string
    {
        return __('根域 NS 归属检测');
    }

    public function execute_name(): string
    {
        return 'domain_ns_check';
    }

    public function tip(): string
    {
        return __('定期检测根域名的 NS 归属情况，识别是否托管到 Cloudflare 等外部 DNS 服务商');
    }

    public function cron_time(): string
    {
        return '0 * * * *';
    }

    public function execute(): string
    {
        try {
            $domainModel = ObjectManager::getInstance(Domain::class);
            $dnsDetector = ObjectManager::getInstance(DnsProviderDetector::class);

            $domains = $domainModel->clearQuery()
                ->where(Domain::fields_STATUS, Domain::STATUS_ACTIVE)
                ->select()
                ->fetchArray();

            $total = \count($domains);
            $updated = 0;
            $cloudflare = 0;
            $original = 0;
            $errors = 0;

            foreach ($domains as $row) {
                $domain = ObjectManager::getInstance(Domain::class, [], false);
                $domain->setData($row);

                try {
                    $nameservers = $domain->getNameservers();
                    $domainName = $domain->getDomain();
                    
                    // 实时查询 NS 记录
                    $liveNameservers = $this->queryNsRecords($domainName);
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
                    
                    if (\stripos($dnsProvider, 'cloudflare') !== false) {
                        $cloudflare++;
                    } elseif ($dnsProvider === '' || \stripos($dnsProvider, 'original') !== false) {
                        $original++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    w_log_warning("域名 {$row[Domain::fields_DOMAIN]} NS 检测失败: {$e->getMessage()}", [], 'domain_ns_check');
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

            w_log_info($message, [], 'domain_ns_check');

            return $message;
        } catch (\Throwable $e) {
            $errorMsg = '根域 NS 检测任务异常: ' . $e->getMessage();
            w_log_error($errorMsg, [], 'domain_ns_check');
            return $errorMsg;
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
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
