<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池证书自动申请定时任务
 *
 * 统一负责域名池 HTTPS 证书申请：同步阻塞直至每个域名申请完成，并立即更新池子状态。
 * 带域名池 id 时仅按每条记录的域名申请单域证书，不做泛域（*.xxx）解析与申请。
 * 【证书阶段】仅处理 pool_lifecycle_stage = origin_ready | cert_pending；不在解析阶段写 https pending。
 * 定时任务默认 DNS-01：避免经 CDN/代理时 HTTP-01 公网访问返回 502 等导致验签失败；物理 webroot 仍传入，不传 use_wls_virtual_http01。
 * 后台手工申请仍为 auto，见 {@see \Weline\Server\Controller\Backend\SslCertificate::postRequest}。
 * 过程会写入 domain_pool_cert 日志（开始/进度/结束），便于排查“申请中但未实际申请”等问题。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainPoolFlowLog;
use Weline\Websites\Service\CertificateRequestService;
use Weline\Websites\Service\DomainCronLockService;
use Weline\Websites\Service\DomainPoolFlowLogService;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * 由 {@see WebsitesPoolCertificateMaintenance} 第二步调用。
 */
#[CronTestHelp(
    description: '子域 HTTPS 证书申请：从池内取生命周期 origin_ready 或 cert_pending 的子域，按队列逐个发起 ACME 申请（默认 DNS-01，需父域 DNS/CDN 账户），成功后更新为可建站。',
    examples: ['php bin/w cron:test --task=domain_pool_certificate_request --domain=www.example.com -v'],
    manual_help: [
        '逻辑：取待申请队列候选后，用 SSL 证书管理器（表+PEM/文件+未临期）判断是否已健康；不健康才向 CA 申请。池子 https_status 不作为入队依据。日志写 domain_pool_cert。',
        '父根域 dns_cutover_complete=0 时不会进入队列；根域 cron_resolved=1 时跳过申请（建站已锁定）。',
        '--domain= 仅处理该子域（FQDN）；不指定则按队列处理。',
    ],
)]
class DomainPoolCertificateRequest
{
    use WebsitesCronTestRunnerTrait;

    private const DEFAULT_CERT_STRATEGY = 'wildcard_prefer';
    private const DEFAULT_CHALLENGE_STRATEGY = 'dns01';

    /** CLI 下同时输出到屏幕，便于手动执行时查看 */
    private function echoLine(string $line): void
    {
        if (\PHP_SAPI === 'cli') {
            echo $line . "\n";
        }
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
            $candidates = $domainPoolModel->getDomainsNeedCertificate(200);
            if (WebsitesCronTestContext::getDomainFilter() !== null) {
                $candidates = \array_values(\array_filter(
                    $candidates,
                    static function (array $r): bool {
                        $fq = (string) ($r[DomainPool::schema_fields_DOMAIN] ?? '');
                        $rt = (string) ($r[DomainPool::schema_fields_ROOT_DOMAIN] ?? '');

                        return WebsitesCronTestContext::matchesSubject($fq, $rt !== '' ? $rt : null);
                    }
                ));
            }
            $domains = [];
            foreach ($candidates as $row) {
                $fq = \strtolower(\trim((string) ($row[DomainPool::schema_fields_DOMAIN] ?? '')));
                if ($fq === '') {
                    continue;
                }
                if ($this->hasHealthyManagedCertificate($fq)) {
                    $this->reconcilePoolWithManagedCertificateIfOutOfSync($row, $fq);
                    continue;
                }
                $domains[] = $row;
                if (\count($domains) >= 50) {
                    break;
                }
            }
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
            $hint = __('挑战策略 DNS-01：自动写入 _acme-challenge TXT（需父域已配置 DNS/CDN 账户且权威 NS 与服务商一致）。');
            $processLogs[] = $hint;
            $this->echoLine($hint);
            w_log_info('[DomainPoolCertificateRequest] ' . $hint, [], 'domain_pool_cert');

            $cronLock = ObjectManager::getInstance(DomainCronLockService::class);
            foreach ($domains as $row) {
                if ($cronLock->shouldSkipNonCertificateWorkForPoolRow($row)) {
                    $results['skipped']++;
                    continue;
                }
                $fq = (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '');
                $rt = (string) ($row[DomainPool::schema_fields_ROOT_DOMAIN] ?? '');
                WebsitesCronTestContext::detail('DomainPoolCertificateRequest.row', [
                    'domain' => $fq,
                    'pool_id' => (int) ($row[DomainPool::schema_fields_ID] ?? 0),
                    'stage' => $row[DomainPool::schema_fields_POOL_LIFECYCLE_STAGE] ?? '',
                ]);
                $singleResult = $this->requestByRow($row, $webroot, $email, false, $processLogs);
                $results['requested'] += $singleResult['requested'];
                $results['success'] += $singleResult['success'];
                $results['failed'] += $singleResult['failed'];
                $results['skipped'] += $singleResult['skipped'];
            }

            $summary = __('域名池证书申请完成：检查 %{1} 个，跳过 %{2} 个，申请 %{3} 个，成功 %{4} 个，失败 %{5} 个', [
                $results['checked'],
                $results['skipped'],
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
    private function requestByRow(array $row, string $webroot, string $email, bool $isWildcard, array &$processLogs = []): array
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
        $poolDomain->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_PENDING);
        $poolDomain->save();
        ObjectManager::getInstance(DomainPoolFlowLogService::class)->append(
            $poolId,
            DomainPoolFlowLog::KIND_CERT_START,
            (string) __('开始向 CA 申请：%{1}', [$requestDomain]),
        );

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
            $line = "[{$requestDomain}] " . __('调用统一证书申请入口，等待 CA 响应…');
            $processLogs[] = $line;
            $this->echoLine($line);
            $certRequestService = ObjectManager::getInstance(CertificateRequestService::class);
            // 定时任务固定 DNS-01（不经公网 HTTP 路径，避免 CDN 502）；物理 webroot 仍传，不启用 WLS 虚拟 HTTP-01
            $result = $certRequestService->requestCertificate([
                'domain' => $requestDomain,
                'webroot' => $webroot,
                'email' => $reqEmail,
                'website_id' => 0,
                'provider' => 'letsencrypt',
                'cert_type' => $isWildcard ? 'wildcard' : 'exact',
                'pool_id' => $poolId,
                'domain_id' => $domainId > 0 ? $domainId : 0,
                'challenge_strategy' => self::DEFAULT_CHALLENGE_STRATEGY,
                '_on_progress' => $onProgress,
            ]);

            $success = (bool) ($result['success'] ?? false);
            $resultMessage = (string) ($result['message'] ?? '');

            if ($success) {
                $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
                $poolDomain->setHttpsError('');
                $poolDomain->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID);
                $poolDomain->calculateSiteReady();
                $poolDomain->save();
                ObjectManager::getInstance(DomainPoolFlowLogService::class)->append(
                    $poolId,
                    DomainPoolFlowLog::KIND_CERT_OK,
                    (string) __('证书有效：%{1}', [$requestDomain]),
                );
                if ($poolDomain->isSiteReady()) {
                    ObjectManager::getInstance(DomainPoolFlowLogService::class)->append(
                        $poolId,
                        DomainPoolFlowLog::KIND_SITE_READY,
                        (string) __('已满足可建站条件'),
                    );
                }
                $line = "[{$requestDomain}] " . __('证书申请成功，已更新 HTTPS 状态与可建站');
                $processLogs[] = $line;
                $this->echoLine($line);
                w_log_info('[DomainPoolCertificateRequest] ' . __('证书申请结束：%{1}，成功，已更新为可建站', [$requestDomain]), [], 'domain_pool_cert');
                $counter['success']++;
            } else {
                $counter['failed']++;
                $msg = $resultMessage ?: (string) __('未知错误');
                $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
                $poolDomain->setHttpsError($msg);
                $poolDomain->setPoolLifecycleStage(DomainPool::LIFECYCLE_ORIGIN_READY);
                $poolDomain->save();
                ObjectManager::getInstance(DomainPoolFlowLogService::class)->append(
                    $poolId,
                    DomainPoolFlowLog::KIND_CERT_FAIL,
                    $msg,
                );
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
            $poolDomain->setPoolLifecycleStage(DomainPool::LIFECYCLE_ORIGIN_READY);
            $poolDomain->save();
            ObjectManager::getInstance(DomainPoolFlowLogService::class)->append(
                $poolId,
                DomainPoolFlowLog::KIND_CERT_FAIL,
                $errMsg,
            );
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
     * 管理器已有健康证书时，将池子 https/阶段/cert_id 与之一致（消除与域名池字段的漂移）。
     *
     * @param array<string, mixed> $row
     */
    private function reconcilePoolWithManagedCertificateIfOutOfSync(array $row, string $fqdn): void
    {
        $poolId = (int) ($row[DomainPool::schema_fields_ID] ?? 0);
        if ($poolId <= 0) {
            return;
        }
        $preferredCertIdRaw = $row[DomainPool::schema_fields_CERT_ID] ?? null;
        $preferredCertId = $preferredCertIdRaw !== null && $preferredCertIdRaw !== ''
            ? (int) $preferredCertIdRaw
            : null;
        $certRow = $this->resolveManagedCertificate($fqdn, $preferredCertId);
        if (!\is_array($certRow) || (int) ($certRow['cert_id'] ?? 0) <= 0) {
            return;
        }
        $pool = ObjectManager::getInstance(DomainPool::class, [], false);
        $pool->load($poolId);
        if ((int) $pool->getPoolId() <= 0) {
            return;
        }
        $exp = \trim((string) ($certRow['expires_at'] ?? ''));
        $poolExp = $pool->getHttpsExpiresAt();
        $poolExpStr = $poolExp !== null && $poolExp !== '' ? \trim((string) $poolExp) : '';
        if ($pool->getHttpsStatus() === DomainPool::HTTPS_STATUS_VALID
            && $pool->getPoolLifecycleStage() === DomainPool::LIFECYCLE_CERT_VALID
            && (int) $pool->getCertId() === (int) ($certRow['cert_id'] ?? 0)
            && ($exp === $poolExpStr || ($exp === '' && $poolExpStr === ''))) {
            return;
        }
        $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
        $pool->setHttpsError('');
        $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID);
        $pool->setCertId((int) ($certRow['cert_id'] ?? 0));
        if ($exp !== '') {
            $pool->setHttpsExpiresAt($exp);
        }
        $pool->calculateSiteReady();
        $pool->save();
        ObjectManager::getInstance(DomainPoolFlowLogService::class)->append(
            $poolId,
            DomainPoolFlowLog::KIND_CERT_OK,
            (string) __('已与 SSL 证书管理器同步（池状态此前与有效证书记录不一致）'),
        );
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
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID);
            $pool->calculateSiteReady();
            $pool->save();
            $fid = $pool->getPoolId();
            if ($fid > 0) {
                ObjectManager::getInstance(DomainPoolFlowLogService::class)->append(
                    $fid,
                    DomainPoolFlowLog::KIND_CERT_OK,
                    (string) __('泛域证书覆盖'),
                );
            }
        }
        $line = __('泛域证书已覆盖同根下 %{1} 条池子记录，已全部标为有效', [\count($rows)]);
        $this->echoLine($line);
        w_log_info('[DomainPoolCertificateRequest] ' . $line, [], 'domain_pool_cert');
    }
    private function hasHealthyManagedCertificate(string $hostname): bool
    {
        try {
            return (bool) w_query('server', 'isManagedCertificateHealthy', [
                'hostname' => $hostname,
            ]);
        } catch (\Throwable $e) {
            w_log_warning('[DomainPoolCertificateRequest] ' . __('查询管理证书健康状态失败：%{1}', [$e->getMessage()]), [], 'domain_pool_cert');

            return false;
        }
    }

    private function resolveManagedCertificate(string $hostname, ?int $preferredCertId = null): ?array
    {
        try {
            $result = w_query('server', 'resolveManagedCertificate', [
                'hostname' => $hostname,
                'preferred_cert_id' => $preferredCertId,
            ]);
        } catch (\Throwable $e) {
            w_log_warning('[DomainPoolCertificateRequest] ' . __('解析管理证书失败：%{1}', [$e->getMessage()]), [], 'domain_pool_cert');

            return null;
        }

        return \is_array($result) ? $result : null;
    }
}
