<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池证书自动申请定时任务
 *
 * 统一负责域名池 HTTPS 证书申请：同步阻塞直至每个域名申请完成，并立即更新池子状态。
 * 带域名池 id 时仅按每条记录的域名申请单域证书，不做泛域（*.xxx）解析与申请。
 * 条件：resolve_status=resolved + is_local_server=1（含公网 IP 匹配或 DNS/CDN 提供商权威 A/AAAA 指向源站）+ https_status in (none, expired, error, pending)
 * 过程会写入 domain_pool_cert 日志（开始/进度/结束），便于排查“申请中但未实际申请”等问题。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;

class DomainPoolCertificateRequest implements CronTaskInterface
{
    private const DEFAULT_CERT_STRATEGY = 'wildcard_prefer';

    /** CLI 下同时输出到屏幕，便于手动执行时查看 */
    private function echoLine(string $line): void
    {
        if (\PHP_SAPI === 'cli') {
            echo $line . "\n";
        }
    }

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
        return __('每 5 分钟执行，仅处理状态为待申请证书的域名池记录，向 CA 申请 Let\'s Encrypt 证书');
    }

    public function cron_time(): string
    {
        return '*/5 * * * *';
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
                $msg = __('没有需要申请证书的域名池域名');
                $this->echoLine($msg);
                w_log_info('[DomainPoolCertificateRequest] ' . $msg, [], 'domain_pool_cert');
                return $msg;
            }
            $line = __('本次待申请证书域名数：%{1}，将逐个调用 CA 申请并输出过程', [$results['checked']]);
            $processLogs[] = $line;
            $this->echoLine($line);
            w_log_info('[DomainPoolCertificateRequest] ' . $line, [], 'domain_pool_cert');

            $webroot = \defined('PUB') ? PUB : (BP . 'pub');
            $email = Env::getInstance()->getConfig('ssl.contact_email') ?? '';
            $processLogs[] = __('域名池任务：仅按每条记录的域名申请单域证书，不做泛域（*.*）申请');
            $this->echoLine($processLogs[\count($processLogs) - 1]);

            foreach ($domains as $row) {
                $singleResult = $this->requestByRow($row, $webroot, $email, 'single', false, $processLogs);
                $results['requested'] += $singleResult['requested'];
                $results['success'] += $singleResult['success'];
                $results['failed'] += $singleResult['failed'];
                $results['skipped'] += $singleResult['skipped'];
            }

            $summary = __('域名池证书申请完成：检查 %{1} 个，申请 %{2} 个，成功 %{3} 个，失败 %{4} 个', [
                $results['checked'],
                $results['requested'],
                $results['success'],
                $results['failed'],
            ]);
            $this->echoLine('---');
            $this->echoLine($summary);
            return \implode("\n", $processLogs) . "\n---\n" . $summary;
        } catch (\Throwable $e) {
            $err = __('域名池证书申请任务异常：%{1}', [$e->getMessage()]);
            $this->echoLine($err);
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
            $line = "[{$domain}] " . __('跳过: domain 或 pool_id 为空');
            $processLogs[] = $line;
            $this->echoLine($line);
            return $counter;
        }

        $requestDomain = $isWildcard && $rootDomain !== '' ? '*.' . $rootDomain : $domain;
        $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
        $poolDomain->setData($row);
        $domainId = (int) ($row[DomainPool::schema_fields_PARENT_DOMAIN_ID] ?? 0);
        $parentDomain = ObjectManager::getInstance(Domain::class, [], false);
        if ($domainId > 0) {
            $parentDomain->load($domainId);
        }
        $hasDnsOrCdnAccount = $parentDomain->getDnsAccountId() > 0 || $parentDomain->getCdnAccountId() > 0;
        if (!$hasDnsOrCdnAccount) {
            $counter['skipped']++;
            $line = "[{$requestDomain}] " . __('DNS/CDN 账户为空，定时任务跳过证书申请');
            $processLogs[] = $line;
            $this->echoLine($line);
            return $counter;
        }

        $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
        $poolDomain->setHttpsError('');
        $poolDomain->save();

        $counter['requested']++;
        $line = "[{$requestDomain}] " . __('开始向 CA 申请证书（%{1}），阻塞直至申请完成', [$isWildcard ? '泛域' : '单域']);
        $processLogs[] = $line;
        $this->echoLine($line);
        w_log_info('[DomainPoolCertificateRequest] ' . __('开始同步申请证书：%{1}，阻塞直至申请完成', [$requestDomain]), [], 'domain_pool_cert');

        $reqEmail = $email !== '' ? $email : 'admin@' . $domain;
        $onProgress = function (string $message, array $extra = []) use ($requestDomain, &$processLogs): void {
            $line = "[{$requestDomain}] " . $message;
            $processLogs[] = $line;
            $this->echoLine($line);
            w_log_info('[DomainPoolCertificateRequest] ' . $requestDomain . ' - ' . $message, $extra, 'domain_pool_cert');
        };
        try {
            $line = "[{$requestDomain}] " . __('调用 requestCertificate，等待 CA 响应…');
            $processLogs[] = $line;
            $this->echoLine($line);
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
                    $line = "[{$requestDomain}] " . __('CA 返回成功，但 HTTPS 连通性校验未通过，已标记待下次检测');
                    $processLogs[] = $line;
                    $this->echoLine($line);
                    w_log_info('[DomainPoolCertificateRequest] ' . __('证书申请结束：%{1}，成功但 HTTPS 校验未通过，保持待检测', [$requestDomain]), [], 'domain_pool_cert');
                } else {
                    $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
                    $poolDomain->setHttpsError('');
                    $poolDomain->calculateSiteReady();
                    $poolDomain->save();
                    $line = "[{$requestDomain}] " . __('证书申请成功，已更新 HTTPS 状态与可建站');
                    $processLogs[] = $line;
                    $this->echoLine($line);
                    w_log_info('[DomainPoolCertificateRequest] ' . __('证书申请结束：%{1}，成功，已更新为可建站', [$requestDomain]), [], 'domain_pool_cert');
                }
                $counter['success']++;
            } else {
                $counter['failed']++;
                $msg = $resultMessage ?: (string) __('未知错误');
                $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
                $poolDomain->setHttpsError($msg);
                $poolDomain->save();
                $line = "[{$requestDomain}] " . __('证书申请失败: %{1}', [$msg]);
                $processLogs[] = $line;
                $this->echoLine($line);
                w_log_error(__('[DomainPoolCertificateRequest] 证书申请结束：%{1}，失败: %{2}', [$requestDomain, $msg]), [], 'domain_pool_cert');
                if (\function_exists('w_msg')) {
                    w_msg(
                        'domain_pool_certificate_request',
                        'error',
                        __('域名池证书申请失败：%{1}', [$requestDomain]),
                        $msg,
                        [
                            'icon' => 'ri-error-warning-line',
                            'metadata' => [
                                'domain' => $domain,
                                'request_domain' => $requestDomain,
                                'pool_id' => $poolId,
                            ],
                        ]
                    );
                }
            }
        } catch (\Throwable $e) {
            $counter['failed']++;
            $errMsg = $e->getMessage();
            $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
            $poolDomain->setHttpsError($errMsg);
            $poolDomain->save();
            $line = "[{$requestDomain}] " . __('证书申请异常: %{1}', [$errMsg]);
            $processLogs[] = $line;
            $this->echoLine($line);
            w_log_error(__('[DomainPoolCertificateRequest] 证书申请结束：%{1}，异常: %{2}', [$requestDomain, $errMsg]), [], 'domain_pool_cert');
            if (\function_exists('w_msg')) {
                w_msg(
                    'domain_pool_certificate_request',
                    'error',
                    __('域名池证书申请异常：%{1}', [$requestDomain]),
                    $errMsg,
                    [
                        'icon' => 'ri-error-warning-line',
                        'metadata' => [
                            'domain' => $domain,
                            'request_domain' => $requestDomain,
                            'pool_id' => $poolId,
                        ],
                    ]
                );
            }
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
        $line = __('泛域证书已覆盖同根下 %{1} 条池子记录，已全部标为有效', [\count($rows)]);
        $this->echoLine($line);
        w_log_info('[DomainPoolCertificateRequest] ' . $line, [], 'domain_pool_cert');
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
