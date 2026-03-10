<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池全流程检测定时任务
 *
 * 流程：1. 查询所有未建站就绪的域名 → 2. 检测 DNS → 3. 申请 HTTPS → 4. 更新可建站状态
 * - DNS 未设置：尝试自动添加 A 记录后跳过，下次再检
 * - DNS 已就绪：申请 HTTPS；若 HTTPS 已有则更新可建站状态
 * - 错误时发送消息并继续处理后续域名
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
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
        return __('定期检测未建站就绪域名的 DNS 解析，自动添加 A 记录，申请 HTTPS 并更新可建站状态');
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

                try {
                    $logPrefix = "[{$domainName}] ";
                    $logs[] = $logPrefix . __('开始处理');
                    w_log_info($logPrefix . __('开始处理'), [], self::LOG_KEY);

                    // Step 2: DNS 检测
                    $result = $resolveService->checkResolve($poolDomain);

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
                        $logs[] = $logPrefix . __('解析正常但未指向本服务器，跳过 HTTPS');
                        w_log_info($logPrefix . __('解析正常但未指向本服务器'), [], self::LOG_KEY);
                        continue;
                    }

                    // Step 3: DNS 已就绪，处理 HTTPS
                    $httpsStatus = $poolDomain->getHttpsStatus();
                    if ($httpsStatus === DomainPool::HTTPS_STATUS_VALID) {
                        $poolDomain->calculateSiteReady();
                        $poolDomain->save();
                        $siteReadyUpdated++;
                        $logs[] = $logPrefix . __('HTTPS 有效，已更新可建站状态');
                        w_log_info($logPrefix . __('已更新可建站状态'), [], self::LOG_KEY);
                        continue;
                    }

                    // 需要申请 HTTPS
                    $logs[] = $logPrefix . __('开始申请 HTTPS 证书');
                    w_log_info($logPrefix . __('开始申请 HTTPS 证书'), [], self::LOG_KEY);

                    $certResult = $this->requestCertificate($poolDomain);
                    if ($certResult['success']) {
                        $httpsApplied++;
                        $poolDomain->calculateSiteReady();
                        $poolDomain->save();
                        $logs[] = $logPrefix . __('HTTPS 申请成功，已更新可建站状态');
                        w_log_info($logPrefix . __('HTTPS 申请成功'), [], self::LOG_KEY);
                    } else {
                        $errors++;
                        $errorDetails[] = $domainName . ': ' . $certResult['message'];
                        $logs[] = $logPrefix . __('HTTPS 申请失败: %{1}', [$certResult['message']]);
                        w_log_warning($logPrefix . $certResult['message'], [], self::LOG_KEY);
                        w_msg(
                            'domain_pool_resolve_check',
                            'warning',
                            __('域名 %{1} HTTPS 申请失败', [$domainName]),
                            $certResult['message'],
                            ['icon' => 'ri-error-warning-line']
                        );
                    }
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
                __('检测完成: 共 %d 个域名，添加 A 记录 %d 个，跳过 %d 个，HTTPS 申请 %d 个，可建站更新 %d 个，错误 %d 个'),
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

    private function requestCertificate(DomainPool $poolDomain): array
    {
        $domain = $poolDomain->getDomain();
        $poolId = $poolDomain->getPoolId();
        $domainId = (int) $poolDomain->getParentDomainId();
        $webroot = \defined('PUB') ? PUB : (BP . 'pub');
        $email = (string) (Env::getInstance()->getConfig('ssl.contact_email') ?? 'admin@' . $domain);

        $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
        $poolDomain->setHttpsError('');
        $poolDomain->save();

        try {
            $result = w_query('server', 'requestCertificate', [
                'domain' => $domain,
                'webroot' => $webroot,
                'email' => $email,
                'website_id' => 0,
                'provider' => 'letsencrypt',
                'cert_type' => 'exact',
                'cert_strategy' => 'single',
                'pool_id' => $poolId,
                'domain_id' => $domainId > 0 ? $domainId : 0,
                'challenge_strategy' => 'dns01',
                '_on_progress' => function (string $msg) use ($domain): void {
                    w_log_info("[{$domain}] {$msg}", [], self::LOG_KEY);
                },
            ]);

            if ($result['success'] ?? false) {
                if (!$this->validateHttpsAccess($domain)) {
                    return [
                        'success' => false,
                        'message' => __('证书已签发，HTTPS 连通性校验未通过'),
                    ];
                }
                return ['success' => true, 'message' => ''];
            }
            $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
            $poolDomain->setHttpsError((string) ($result['message'] ?? __('未知错误')));
            $poolDomain->save();
            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? __('未知错误')),
            ];
        } catch (\Throwable $e) {
            $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
            $poolDomain->setHttpsError($e->getMessage());
            $poolDomain->save();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function validateHttpsAccess(string $domain): bool
    {
        $ch = \curl_init('https://' . $domain);
        if ($ch === false) {
            return false;
        }
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_NOBODY, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        @\curl_exec($ch);
        $httpCode = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);
        return $httpCode >= 200 && $httpCode < 500;
    }

    public function unlock_timeout(int $minute = 20): int
    {
        return $minute;
    }
}
