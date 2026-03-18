<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池全流程检测定时任务
 *
 * 流程：1. 查询所有未建站就绪的域名 → 2. 检测 DNS → 3. 标记待申请 HTTPS（不在此任务内同步申请）→ 4. 若 HTTPS 已有效则更新可建站状态
 * - DNS 未设置：尝试自动添加 A 记录后跳过，下次再检
 * - DNS 已就绪且 HTTPS 无效：仅标记为待申请，由定时任务「域名池证书自动申请」统一同步申请
 * - 错误时发送消息并继续处理后续域名
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainPoolResolveService;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\ServerIpService;

class DomainPoolResolveCheck implements CronTaskInterface
{
    private const LOG_KEY = 'domain_pool_resolve_check';

    public function name(): string
    {
        return __('域名池解析状态检测');
    }

    public function execute_name(): string
    {
        return 'domain_pool_resolve_check';
    }

    public function tip(): string
    {
        return __('定期检测未建站就绪域名的 DNS 解析，自动添加 A 记录，标记待申请证书并由证书任务统一申请，更新可建站状态');
    }

    public function cron_time(): string
    {
        return '*/10 * * * *';
    }

    public function execute(): string
    {
        $logs = [];
        $errors = 0;
        $errorDetails = [];
        $dnsAdded = 0;
        $dnsSkipped = 0;
        $httpsApplied = 0;
        $siteReadyUpdated = 0;

        try {
            $domainPoolModel = ObjectManager::getInstance(DomainPool::class);
            $resolveService = ObjectManager::getInstance(DomainPoolResolveService::class);
            $domainResolveService = ObjectManager::getInstance(DomainResolveService::class);
            $serverIpService = ObjectManager::getInstance(ServerIpService::class);
            $eventsManager = ObjectManager::getInstance(EventsManager::class);

            $domains = $domainPoolModel->getDomainsNotSiteReady(100);
            $total = \count($domains);

            $logs[] = __('共获取 %{1} 个待处理域名', [$total]);
            w_log_info($logs[\count($logs) - 1], [], self::LOG_KEY);

            if ($total === 0) {
                return __('没有需要处理的域名池域名');
            }

            $serverIp = $serverIpService->getPublicIpv4() ?: $serverIpService->getPublicIpv6() ?? '';

            foreach ($domains as $row) {
                $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
                $poolDomain->setData($row);
                $domainName = $row[DomainPool::schema_fields_DOMAIN] ?? '';
                $parentDomainId = (int) ($row[DomainPool::schema_fields_PARENT_DOMAIN_ID] ?? 0);
                $parentDomain = ObjectManager::getInstance(Domain::class, [], false);
                if ($parentDomainId > 0) {
                    $parentDomain->load($parentDomainId);
                }
                $hasDnsOrCdnAccount = $parentDomain->getDnsAccountId() > 0 || $parentDomain->getCdnAccountId() > 0;
                if (!$hasDnsOrCdnAccount) {
                    $dnsSkipped++;
                    $logPrefix = "[{$domainName}] ";
                    $logs[] = $logPrefix . __('DNS/CDN 账户为空，定时任务跳过检测与证书标记');
                    w_log_info($logPrefix . __('DNS/CDN 账户为空，定时任务跳过检测与证书标记'), [], self::LOG_KEY);
                    continue;
                }

                try {
                    $logPrefix = "[{$domainName}] ";
                    $logs[] = $logPrefix . __('开始处理');
                    w_log_info($logPrefix . __('开始处理'), [], self::LOG_KEY);

                    // Step 2: DNS 检测
                    $result = $resolveService->checkResolve($poolDomain);
                    $resolvedIp = $result['ipv4'] ?: $result['ipv6'] ?? '';
                    $logs[] = $logPrefix . __('DNS 检测结果: resolved=%{1}, ip=%{2}', [$result['resolved'] ? 'true' : 'false', $resolvedIp ?: '-']);
                    w_log_info($logPrefix . __('DNS 检测结果') . ' resolved=' . ($result['resolved'] ? '1' : '0') . ' ip=' . $resolvedIp, [], self::LOG_KEY);

                    if (!$result['resolved']) {
                        $errMsg = $result['error'] ?? __('DNS 未解析');
                        $logs[] = $logPrefix . __('DNS 未解析: %{1}', [$errMsg]);
                        w_log_info($logPrefix . __('DNS 未解析，尝试添加 A 记录'), [], self::LOG_KEY);

                        if ($serverIp !== '') {
                            $addResult = $domainResolveService->tryAddARecordForPoolDomain($poolDomain, $serverIp);
                            if ($addResult['success']) {
                                $dnsAdded++;
                                $logs[] = $logPrefix . __('已尝试添加 A 记录，等待 DNS 生效');
                                w_log_info($logPrefix . $addResult['message'], [], self::LOG_KEY);
                            } else {
                                $dnsSkipped++;
                                $logs[] = $logPrefix . __('添加 A 记录失败: %{1}', [$addResult['message']]);
                                w_log_warning($logPrefix . $addResult['message'], [], self::LOG_KEY);
                                $errors++;
                                $errorDetails[] = $domainName . ': ' . $addResult['message'];
                                w_msg(
                                    'domain_pool_resolve_check',
                                    'warning',
                                    __('域名 %{1} DNS 未解析且添加记录失败', [$domainName]),
                                    $addResult['message'],
                                    ['icon' => 'ri-error-warning-line']
                                );
                            }
                        } else {
                            $dnsSkipped++;
                            $errors++;
                            $errorDetails[] = $domainName . ': ' . $errMsg;
                        }
                        continue;
                    }

                    if (!empty($result['resolve_off_local'])) {
                        $eventData = [
                            'data' => [
                                'domain' => $poolDomain->getDomain(),
                                'pool_id' => (int) $poolDomain->getPoolId(),
                                'resolved_ip' => $result['ipv4'] ?: $result['ipv6'] ?? '',
                                'expected_ip' => $serverIpService->getPublicIpv4() ?: $serverIpService->getPublicIpv6() ?? '',
                            ],
                        ];
                        $eventsManager->dispatch('Weline_Websites::domain_pool::resolve_off_local', $eventData);
                    }
                    if (!$result['is_local']) {
                        $logs[] = $logPrefix . __('解析正常但公网 IP 与源站不一致且提供商权威记录未指向本站，跳过证书标记');
                        w_log_info($logPrefix . __('未判定为指向本站（公网/权威均未匹配源站）'), [], self::LOG_KEY);
                        continue;
                    }
                    if (!empty($result['is_local_via_authoritative'])) {
                        $logs[] = $logPrefix . __('经 DNS/CDN 提供商权威记录确认源站指向本站（公网可能为 CDN 边缘 IP）');
                        w_log_info($logPrefix . __('权威记录指向本站'), [], self::LOG_KEY);
                    }

                    // Step 3: DNS 已就绪，处理 HTTPS 状态（本任务不实际申请证书，仅标记或更新可建站）
                    $httpsStatus = $poolDomain->getHttpsStatus();
                    $logs[] = $logPrefix . __('当前 HTTPS 状态: %{1}', [$httpsStatus ?: 'none']);

                    if ($httpsStatus === DomainPool::HTTPS_STATUS_VALID) {
                        $poolDomain->calculateSiteReady();
                        $poolDomain->save();
                        $siteReadyUpdated++;
                        $logs[] = $logPrefix . __('证书已有效，仅更新可建站状态（本任务不申请证书）');
                        w_log_info($logPrefix . __('已更新可建站状态'), [], self::LOG_KEY);
                        continue;
                    }

                    // 需要申请 HTTPS：仅标记为「待申请」，由定时任务「域名池证书自动申请」实际调用 CA 申请
                    $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
                    $poolDomain->setHttpsError('');
                    $poolDomain->save();
                    $httpsApplied++;
                    $logs[] = $logPrefix . __('仅标记为「待申请证书」，将由证书定时任务实际向 CA 申请（本任务不发起申请）');
                    w_log_info($logPrefix . __('已标记待申请证书，由定时任务「域名池证书自动申请」统一处理'), [], self::LOG_KEY);
                } catch (\Throwable $e) {
                    $errors++;
                    $errMsg = $e->getMessage();
                    $errorDetails[] = $domainName . ': ' . $errMsg;
                    $logs[] = "[{$domainName}] " . __('处理异常: %{1}', [$errMsg]);
                    w_log_warning("[{$domainName}] {$errMsg}", [], self::LOG_KEY);
                    w_msg(
                        'domain_pool_resolve_check',
                        'warning',
                        __('域名 %{1} 处理异常', [$domainName]),
                        $errMsg,
                        ['icon' => 'ri-error-warning-line']
                    );
                }
            }

            $message = \sprintf(
                __('检测完成: 共 %d 个域名，添加 A 记录 %d 个，跳过 %d 个，已标记待证书申请 %d 个（由证书任务实际申请），可建站更新 %d 个，错误 %d 个'),
                $total,
                $dnsAdded,
                $dnsSkipped,
                $httpsApplied,
                $siteReadyUpdated,
                $errors
            );
            if ($errorDetails !== []) {
                $message .= "\n" . __('错误详情:') . "\n  - " . \implode("\n  - ", $errorDetails);
            }

            $logs[] = $message;
            w_log_info($message, [], self::LOG_KEY);

            if ($errors > 0) {
                w_msg(
                    'domain_pool_resolve_check',
                    'warning',
                    __('域名池检测有 %{1} 个域名失败', [$errors]),
                    $message,
                    ['icon' => 'ri-error-warning-line']
                );
            }

            return \implode("\n", $logs);
        } catch (\Throwable $e) {
            $errorMsg = __('域名池解析检测任务异常: %{1}', [$e->getMessage()]);
            w_log_error($errorMsg, [], self::LOG_KEY);
            return $errorMsg;
        }
    }

    public function unlock_timeout(int $minute = 20): int
    {
        return $minute;
    }
}
