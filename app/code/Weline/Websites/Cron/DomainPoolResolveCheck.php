<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池 ——【解析阶段】定时任务
 *
 * 仅处理生命周期 registered / awaiting_origin：检测解析与源站，推进至 origin_ready。
 * 不在本任务内标记 https pending / 不申请证书（由【证书阶段】任务承接）。
 * 另：cert_valid 且未 site_ready 时本任务第二段仅刷新可建站标记。
 */

namespace Weline\Websites\Cron;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainPoolLifecycleService;
use Weline\Websites\Service\DomainPoolResolveService;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\ServerIpService;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * 由 {@see WebsitesDomainResolvePipeline} 统一调度。
 */
#[CronTestHelp(
    description: '域名池解析阶段（registered/awaiting_origin）。',
    examples: ['php bin/w cron:test --task=domain_pool_resolve_check --domain=www.example.com -v'],
)]
class DomainPoolResolveCheck
{
    use WebsitesCronTestRunnerTrait;

    private const LOG_KEY = 'domain_pool_resolve_check';

    public function execute(): string
    {
        $logs = [];
        $errors = 0;
        $errorDetails = [];
        $dnsAdded = 0;
        $dnsSkipped = 0;
        $originReadyAdvanced = 0;
        $siteReadyUpdated = 0;

        try {
            $domainPoolModel = ObjectManager::getInstance(DomainPool::class);
            $resolveService = ObjectManager::getInstance(DomainPoolResolveService::class);
            $domainResolveService = ObjectManager::getInstance(DomainResolveService::class);
            $serverIpService = ObjectManager::getInstance(ServerIpService::class);
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $lifecycle = ObjectManager::getInstance(DomainPoolLifecycleService::class);

            $domains = $domainPoolModel->getDomainsNeedResolveCheck(100);
            $total = \count($domains);
            WebsitesCronTestContext::detail('DomainPoolResolveCheck.batch', ['raw_count' => $total]);

            $logs[] = __('【解析阶段】本批 %{1} 条（registered/awaiting_origin，距上次检测≥约10分钟）', [$total]);
            w_log_info($logs[\count($logs) - 1], [], self::LOG_KEY);

            $serverIp = $serverIpService->getPublicIpv4() ?: $serverIpService->getPublicIpv6() ?? '';

            foreach ($domains as $row) {
                $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
                $poolDomain->setData($row);
                $domainName = $row[DomainPool::schema_fields_DOMAIN] ?? '';
                $logPrefix = "[{$domainName}] ";
                $parentDomainId = (int) ($row[DomainPool::schema_fields_PARENT_DOMAIN_ID] ?? 0);
                $parentDomain = ObjectManager::getInstance(Domain::class, [], false);
                if ($parentDomainId > 0) {
                    $parentDomain->load($parentDomainId);
                }
                $hasDnsOrCdnAccount = $parentDomain->getDnsAccountId() > 0 || $parentDomain->getCdnAccountId() > 0;

                try {
                    $logs[] = $logPrefix . __('开始处理');
                    w_log_info($logPrefix . __('开始处理'), [], self::LOG_KEY);

                    $result = $resolveService->checkResolve($poolDomain);
                    WebsitesCronTestContext::detail('checkResolve', ['domain' => $domainName, 'result' => $result]);
                    $resolvedIp = $result['ipv4'] ?: $result['ipv6'] ?? '';
                    $logs[] = $logPrefix . __('DNS 检测结果: resolved=%{1}, ip=%{2}', [$result['resolved'] ? 'true' : 'false', $resolvedIp ?: '-']);
                    w_log_info($logPrefix . __('DNS 检测结果') . ' resolved=' . ($result['resolved'] ? '1' : '0') . ' ip=' . $resolvedIp, [], self::LOG_KEY);

                    if (!$result['resolved']) {
                        $errMsg = $result['error'] ?? __('DNS 未解析');
                        $logs[] = $logPrefix . __('DNS 未解析: %{1}', [$errMsg]);

                        if ($serverIp !== '' && $hasDnsOrCdnAccount) {
                            w_log_info($logPrefix . __('尝试添加 A/AAAA 记录'), [], self::LOG_KEY);
                            $addResult = $domainResolveService->tryAddARecordForPoolDomain($poolDomain, $serverIp);
                            if ($addResult['success']) {
                                $dnsAdded++;
                                $logs[] = $logPrefix . $addResult['message'];
                                w_log_info($logPrefix . $addResult['message'], [], self::LOG_KEY);
                            } else {
                                $dnsSkipped++;
                                $logs[] = $logPrefix . __('添加记录失败: %{1}', [$addResult['message']]);
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
                        } elseif (!$hasDnsOrCdnAccount) {
                            $dnsSkipped++;
                            w_log_info($logPrefix . __('无 DNS 管理账户，无法代写记录；请在外部 DNS 配置后等待生效'), [], self::LOG_KEY);
                        } else {
                            $dnsSkipped++;
                            $errors++;
                            $errorDetails[] = $domainName . ': ' . $errMsg;
                        }
                        $lifecycle->applyAfterResolvePass($poolDomain, ['resolved' => false, 'is_local' => false]);
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
                        $logs[] = $logPrefix . __('已解析但未指向本站，阶段保持 awaiting_origin');
                        w_log_info($logPrefix . __('未判定为指向本站'), [], self::LOG_KEY);
                        $lifecycle->applyAfterResolvePass($poolDomain, ['resolved' => true, 'is_local' => false]);
                        continue;
                    }
                    if (!empty($result['is_local_via_authoritative'])) {
                        $logs[] = $logPrefix . __('权威记录确认源站指向本站');
                        w_log_info($logPrefix . __('权威记录指向本站'), [], self::LOG_KEY);
                    }

                    $lifecycle->applyAfterResolvePass($poolDomain, ['resolved' => true, 'is_local' => true]);
                    $originReadyAdvanced++;
                    $logs[] = $logPrefix . __('【解析阶段完成】已进入 origin_ready，下一节拍由「证书申请」任务处理');
                    w_log_info($logPrefix . __('阶段→origin_ready'), [], self::LOG_KEY);
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

            $refresh = $domainPoolModel->getPoolsCertValidNeedSiteReadyRefresh(40);
            foreach ($refresh as $rrow) {
                $rfq = (string) ($rrow[DomainPool::schema_fields_DOMAIN] ?? '');
                $rrt = (string) ($rrow[DomainPool::schema_fields_ROOT_DOMAIN] ?? '');
                if (!WebsitesCronTestContext::matchesSubject($rfq, $rrt !== '' ? $rrt : null)) {
                    WebsitesCronTestContext::skipNote($rfq, 'cert_valid site_ready refresh');
                    continue;
                }
                $p = ObjectManager::getInstance(DomainPool::class, [], false);
                $p->setData($rrow);
                try {
                    $p->calculateSiteReady();
                    $p->save();
                    $siteReadyUpdated++;
                    w_log_info('[cert_valid→site_ready] ' . $p->getDomain(), [], self::LOG_KEY);
                } catch (\Throwable) {
                }
            }
            if ($refresh !== []) {
                $logs[] = __('【cert_valid 阶段】刷新可建站 %{1} 条', [\count($refresh)]);
            }

            $message = \sprintf(
                __('解析阶段完成: 处理 %d 条，推进 origin_ready %d 条，代写记录成功 %d，跳过 %d，cert_valid 刷新建站 %d，错误 %d'),
                $total,
                $originReadyAdvanced,
                $dnsAdded,
                $dnsSkipped,
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
}
