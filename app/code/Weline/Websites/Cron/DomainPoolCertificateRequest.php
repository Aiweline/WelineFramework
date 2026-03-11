<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池证书自动申请定时任务
 *
 * 统一负责域名池 HTTPS 证书申请：同步阻塞直至每个域名申请完成，并立即更新池子状态。
 * 条件：resolve_status=resolved + is_local_server=1 + https_status in (none, expired, error, pending)
 * 过程会写入 domain_pool_cert 日志（开始/进度/结束），便于排查“申请中但未实际申请”等问题。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;

class DomainPoolCertificateRequest implements CronTaskInterface
{
    private const DEFAULT_CERT_STRATEGY = 'wildcard_prefer';

    public function name(): string
    {
        return __('域名池证书自动申请');
    }

    public function execute_name(): string
    {
        return 'domain_pool_certificate_request';
    }

    public function tip(): string
    {
        return __('定期为域名池中尚未建站就绪、且解析已指向本服务器的域名自动申请 Let\'s Encrypt 证书；就绪后不再申请');
    }

    public function cron_time(): string
    {
        return '*/30 * * * *';
    }

    public function execute(): string
    {
        $results = [
            'checked' => 0,
            'requested' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
        $processLogs = [];

        try {
            $domainPoolModel = ObjectManager::getInstance(DomainPool::class);
            $domains = $domainPoolModel->getDomainsNeedCertificate(50);
            $results['checked'] = \count($domains);
            if ($domains === []) {
                w_log_info('[DomainPoolCertificateRequest] ' . __('没有需要申请证书的域名池域名'), [], 'domain_pool_cert');
                return __('没有需要申请证书的域名池域名');
            }
            $processLogs[] = __('本次待申请证书域名数：%{1}，将逐个调用 CA 申请并输出过程', [$results['checked']]);
            w_log_info('[DomainPoolCertificateRequest] ' . __('本次待申请证书域名数：%{1}，同步阻塞直至每个申请完成并更新状态', [$results['checked']]), [], 'domain_pool_cert');

            $strategy = (string) (Env::get('server.ssl.cert_strategy', self::DEFAULT_CERT_STRATEGY) ?? self::DEFAULT_CERT_STRATEGY);
            $strategy = \in_array($strategy, ['single', 'wildcard_prefer', 'both'], true) ? $strategy : self::DEFAULT_CERT_STRATEGY;
            $webroot = \defined('PUB') ? PUB : (BP . 'pub');
            $email = Env::getInstance()->getConfig('ssl.contact_email') ?? '';
            $processLogs[] = __('策略: %{1}, webroot: %{2}', [$strategy, $webroot]);

            $groupedByRoot = [];
            foreach ($domains as $row) {
                $rootDomain = (string) ($row[DomainPool::schema_fields_ROOT_DOMAIN] ?? '');
                $groupedByRoot[$rootDomain !== '' ? $rootDomain : (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '')][] = $row;
            }

            foreach ($groupedByRoot as $rootDomain => $rows) {
                if (($strategy === 'wildcard_prefer' || $strategy === 'both') && $rootDomain !== '') {
                    $wildResult = $this->requestByRow($rows[0], $webroot, $email, $strategy, true, $processLogs);
                    $results['requested'] += $wildResult['requested'];
                    $results['success'] += $wildResult['success'];
                    $results['failed'] += $wildResult['failed'];
                    $results['skipped'] += $wildResult['skipped'];
                    if ($wildResult['success'] > 0 && $strategy === 'wildcard_prefer') {
                        $this->markRootPoolRowsValid($rows);
                        continue;
                    }
                }

                foreach ($rows as $row) {
                    $singleResult = $this->requestByRow($row, $webroot, $email, 'single', false, $processLogs);
                    $results['requested'] += $singleResult['requested'];
                    $results['success'] += $singleResult['success'];
                    $results['failed'] += $singleResult['failed'];
                    $results['skipped'] += $singleResult['skipped'];
                }
            }

            $summary = __('域名池证书申请完成：检查 %{1} 个，申请 %{2} 个，成功 %{3} 个，失败 %{4} 个', [
                $results['checked'],
                $results['requested'],
                $results['success'],
                $results['failed'],
            ]);
            return \implode("\n", $processLogs) . "\n---\n" . $summary;
        } catch (\Throwable $e) {
            $err = __('域名池证书申请任务异常：%{1}', [$e->getMessage()]);
            w_log_error('[DomainPoolCertificateRequest] ' . $err, [], 'domain_pool_cert');
            if ($processLogs !== []) {
                return \implode("\n", $processLogs) . "\n---\n" . $err;
            }
            return $err;
        }
    }

    /**
     * @param array<string> $processLogs 过程日志，会追加本域名的每条进度与结果
     */
    private function requestByRow(array $row, string $webroot, string $email, string $strategy, bool $isWildcard, array &$processLogs = []): array
    {
        $counter = [
            'requested' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $domain = (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '');
        $rootDomain = (string) ($row[DomainPool::schema_fields_ROOT_DOMAIN] ?? '');
        $poolId = (int) ($row[DomainPool::schema_fields_ID] ?? 0);
        if ($domain === '' || $poolId <= 0) {
            $counter['skipped']++;
            $processLogs[] = "[{$domain}] " . __('跳过: domain 或 pool_id 为空');
            return $counter;
        }

        $requestDomain = $isWildcard && $rootDomain !== '' ? '*.' . $rootDomain : $domain;
        $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
        $poolDomain->setData($row);
        $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
        $poolDomain->setHttpsError('');
        $poolDomain->save();

        $counter['requested']++;
        $processLogs[] = "[{$requestDomain}] " . __('开始向 CA 申请证书（%{1}），阻塞直至申请完成', [$isWildcard ? '泛域' : '单域']);
        w_log_info('[DomainPoolCertificateRequest] ' . __('开始同步申请证书：%{1}，阻塞直至申请完成', [$requestDomain]), [], 'domain_pool_cert');

        $reqEmail = $email !== '' ? $email : 'admin@' . $domain;
        $domainId = (int) ($row[DomainPool::schema_fields_PARENT_DOMAIN_ID] ?? 0);
        $onProgress = function (string $message, array $extra = []) use ($requestDomain, &$processLogs): void {
            $processLogs[] = "[{$requestDomain}] " . $message;
            w_log_info('[DomainPoolCertificateRequest] ' . $requestDomain . ' - ' . $message, $extra, 'domain_pool_cert');
        };
        try {
            $processLogs[] = "[{$requestDomain}] " . __('调用 requestCertificate，等待 CA 响应…');
            $result = w_query('server', 'requestCertificate', [
                'domain' => $requestDomain,
                'webroot' => $webroot,
                'email' => $reqEmail,
                'website_id' => 0,
                'provider' => 'letsencrypt',
                'cert_type' => $isWildcard ? 'wildcard' : 'exact',
                'cert_strategy' => $strategy,
                'pool_id' => $poolId,
                'domain_id' => $domainId > 0 ? $domainId : 0,
                'challenge_strategy' => 'dns01',
                '_on_progress' => $onProgress,
            ]);

            $success = (bool) ($result['success'] ?? false);
            $resultMessage = (string) ($result['message'] ?? '');

            if ($success) {
                if (!$isWildcard && !$this->validateHttpsAccess($domain)) {
                    $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
                    $poolDomain->setHttpsError((string)__('证书已签发，HTTPS 连通性校验未通过，等待下次检测'));
                    $poolDomain->setSiteReady(false);
                    $poolDomain->save();
                    $processLogs[] = "[{$requestDomain}] " . __('CA 返回成功，但 HTTPS 连通性校验未通过，已标记待下次检测');
                    w_log_info('[DomainPoolCertificateRequest] ' . __('证书申请结束：%{1}，成功但 HTTPS 校验未通过，保持待检测', [$requestDomain]), [], 'domain_pool_cert');
                } else {
                    $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
                    $poolDomain->setHttpsError('');
                    $poolDomain->calculateSiteReady();
                    $poolDomain->save();
                    $processLogs[] = "[{$requestDomain}] " . __('证书申请成功，已更新 HTTPS 状态与可建站');
                    w_log_info('[DomainPoolCertificateRequest] ' . __('证书申请结束：%{1}，成功，已更新为可建站', [$requestDomain]), [], 'domain_pool_cert');
                }
                $counter['success']++;
            } else {
                $counter['failed']++;
                $msg = $resultMessage ?: (string) __('未知错误');
                $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
                $poolDomain->setHttpsError($msg);
                $poolDomain->save();
                $processLogs[] = "[{$requestDomain}] " . __('证书申请失败: %{1}', [$msg]);
                w_log_error(__('[DomainPoolCertificateRequest] 证书申请结束：%{1}，失败: %{2}', [$requestDomain, $msg]), [], 'domain_pool_cert');
            }
        } catch (\Throwable $e) {
            $counter['failed']++;
            $errMsg = $e->getMessage();
            $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
            $poolDomain->setHttpsError($errMsg);
            $poolDomain->save();
            $processLogs[] = "[{$requestDomain}] " . __('证书申请异常: %{1}', [$errMsg]);
            w_log_error(__('[DomainPoolCertificateRequest] 证书申请结束：%{1}，异常: %{2}', [$requestDomain, $errMsg]), [], 'domain_pool_cert');
        }

        return $counter;
    }

    /**
     * 泛域证书申请成功后，将同根下所有池子记录标为有效并更新可建站状态（泛域覆盖所有子域）
     */
    private function markRootPoolRowsValid(array $rows): void
    {
        foreach ($rows as $row) {
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            $pool->setData($row);
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
            $pool->setHttpsError('');
            $pool->calculateSiteReady();
            $pool->save();
        }
        $firstDomain = (string) ($rows[0][DomainPool::schema_fields_DOMAIN] ?? '');
        w_log_info('[DomainPoolCertificateRequest] ' . __('泛域证书已覆盖同根下 %{1} 条池子记录，已全部标为有效', [\count($rows)]), [], 'domain_pool_cert');
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

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
