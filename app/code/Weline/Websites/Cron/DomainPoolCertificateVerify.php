<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池证书有效性校验定时任务
 *
 * 检测 https_status=valid 的域名池记录，验证证书管理表中是否存在有效的 PEM 及文件。
 * - 证书丢失/无 PEM → https_status 回退为 none，site_ready=0
 * - 该域名已建站(site_created=1) → 立即重新申请证书
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\SslCertificate;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPoolFlowLog;
use Weline\Websites\Service\CertificateRequestService;
use Weline\Websites\Service\DomainPoolFlowLogService;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * 由 {@see WebsitesPoolCertificateMaintenance} 第一步调用（仅整点/半点执行）。
 */
#[CronTestHelp(
    description: '子域证书校验：检查池内已标 https_status=valid 的证书在服务器上是否仍有有效 PEM/文件；丢失或无效则回退为 none、site_ready=0，已建站则触发重新申请。',
    examples: ['php bin/w cron:test --task=domain_pool_certificate_verify --domain=www.example.com -v'],
    manual_help: [
        '逻辑：遍历池内 https_status=valid 的记录，查证书表与磁盘 PEM；无有效证书则回退该条为 none 并设 site_ready=0；若该子域已建站(site_created=1)则加入重新申请队列。',
        '--domain= 仅校验该子域；不指定则处理全部。',
    ],
)]
class DomainPoolCertificateVerify
{
    use WebsitesCronTestRunnerTrait;

    private const LOG_KEY = 'domain_pool_cert';

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
            'invalidated' => 0,
            're_requested' => 0,
            'request_success' => 0,
            'request_failed' => 0,
            'ok' => 0,
        ];

        try {
            $poolModel = ObjectManager::getInstance(DomainPool::class);
            $domains = $this->getPoolDomainsWithValidHttps($poolModel);
            $results['checked'] = \count($domains);

            if ($domains === []) {
                $msg = __('没有需要校验的域名池证书');
                $this->echoLine($msg);
                return $msg;
            }

            $this->echoLine(__('本次校验域名数：%{1}', [$results['checked']]));

            foreach ($domains as $row) {
                $domain = (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '');
                $poolRoot = (string) ($row[DomainPool::schema_fields_ROOT_DOMAIN] ?? '');
                $poolId = (int) ($row[DomainPool::schema_fields_ID] ?? 0);
                $certId = $row[DomainPool::schema_fields_CERT_ID] ?? null;
                $siteCreated = (int) ($row[DomainPool::schema_fields_SITE_CREATED] ?? 0) === 1;

                if ($domain === '' || $poolId <= 0) {
                    continue;
                }
                WebsitesCronTestContext::detail('DomainPoolCertificateVerify.row', ['domain' => $domain, 'pool_id' => $poolId, 'cert_id' => $certId]);

                $certValid = $this->isCertificateAvailable($domain, $certId);
                WebsitesCronTestContext::detail('isCertificateAvailable', ['domain' => $domain, 'valid' => $certValid]);

                if ($certValid) {
                    $results['ok']++;
                    continue;
                }

                $results['invalidated']++;
                $this->echoLine(__('[%{1}] 证书丢失或无 PEM，回退 HTTPS 状态', [$domain]));
                w_log_warning(
                    __('[DomainPoolCertificateVerify] 域名 %{1} 证书丢失，回退 HTTPS 状态', [$domain]),
                    [],
                    self::LOG_KEY
                );

                $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
                $poolDomain->load($poolId);
                if (!$poolDomain->getData(DomainPool::schema_fields_ID)) {
                    continue;
                }

                $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_NONE);
                $poolDomain->setHttpsError('');
                $poolDomain->setHttpsExpiresAt(null);
                $poolDomain->setCertId(null);
                $poolDomain->setSiteReady(false);
                $poolDomain->setPoolLifecycleStage(DomainPool::LIFECYCLE_ORIGIN_READY);
                $poolDomain->save();
                ObjectManager::getInstance(DomainPoolFlowLogService::class)->append(
                    $poolId,
                    DomainPoolFlowLog::KIND_CERT_INVALID,
                    (string) __('证书在库中无效或 PEM 丢失，已回退 HTTPS'),
                );

                if ($siteCreated) {
                    $results['re_requested']++;
                    $this->echoLine(__('[%{1}] 域名已建站，立即重新申请证书', [$domain]));
                    $success = $this->reRequestCertificate($row, $poolDomain);
                    if ($success) {
                        $results['request_success']++;
                    } else {
                        $results['request_failed']++;
                    }
                }
            }

            $summary = __('证书校验完成：检查 %{1}，正常 %{2}，失效 %{3}，重新申请 %{4}（成功 %{5}，失败 %{6}）', [
                $results['checked'],
                $results['ok'],
                $results['invalidated'],
                $results['re_requested'],
                $results['request_success'],
                $results['request_failed'],
            ]);
            $this->echoLine($summary);
            w_log_info('[DomainPoolCertificateVerify] ' . $summary, [], self::LOG_KEY);
            return $summary;
        } catch (\Throwable $e) {
            $err = __('域名池证书校验任务异常：%{1}', [$e->getMessage()]);
            $this->echoLine($err);
            w_log_error('[DomainPoolCertificateVerify] ' . $err, [], self::LOG_KEY);
            return $err;
        }
    }

    /**
     * 查询 https_status=valid 的活跃域名池记录
     */
    private function getPoolDomainsWithValidHttps(DomainPool $model): array
    {
        return $model->clearQuery()
            ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE)
            ->where(DomainPool::schema_fields_HTTPS_STATUS, DomainPool::HTTPS_STATUS_VALID)
            ->select()
            ->fetchArray();
    }

    /**
     * 检查证书是否可用：证书管理表存在且 PEM 不为空，或本地文件存在
     */
    private function isCertificateAvailable(string $domain, mixed $certId): bool
    {
        $certModel = ObjectManager::getInstance(SslCertificate::class, [], false);

        if ($certId !== null && (int) $certId > 0) {
            $certModel->load((int) $certId);
            if ($certModel->getCertId()) {
                if ($certModel->getCertPem() !== '' && $certModel->getKeyPem() !== '') {
                    return true;
                }
                $certPath = $certModel->getCertPath();
                $keyPath = $certModel->getKeyPath();
                if ($certPath !== '' && $keyPath !== '' && \is_file($certPath) && \is_file($keyPath)) {
                    return true;
                }
                return false;
            }
        }

        $found = $certModel->findCertificateForDomain($domain);
        if ($found !== null) {
            if ($found->getCertPem() !== '' && $found->getKeyPem() !== '') {
                return true;
            }
            $certPath = $found->getCertPath();
            $keyPath = $found->getKeyPath();
            if ($certPath !== '' && $keyPath !== '' && \is_file($certPath) && \is_file($keyPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 为已建站域名重新申请证书
     */
    private function reRequestCertificate(array $row, DomainPool $poolDomain): bool
    {
        $domain = (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '');
        $poolId = (int) ($row[DomainPool::schema_fields_ID] ?? 0);
        $domainId = (int) ($row[DomainPool::schema_fields_PARENT_DOMAIN_ID] ?? 0);

        $parentDomain = ObjectManager::getInstance(Domain::class, [], false);
        if ($domainId > 0) {
            $parentDomain->load($domainId);
        }
        $hasDnsOrCdnAccount = $parentDomain->getDnsAccountId() > 0 || $parentDomain->getCdnAccountId() > 0;
        if (!$hasDnsOrCdnAccount) {
            $this->echoLine(__('[%{1}] DNS/CDN 账户为空，无法重新申请证书', [$domain]));
            w_log_warning(
                __('[DomainPoolCertificateVerify] 域名 %{1} 需重新申请证书但 DNS/CDN 账户为空', [$domain]),
                [],
                self::LOG_KEY
            );
            return false;
        }

        $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
        $poolDomain->setHttpsError('');
        $poolDomain->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_PENDING);
        $poolDomain->save();

        $webroot = \defined('PUB') ? PUB : (BP . 'pub');
        $email = Env::getInstance()->getConfig('ssl.contact_email') ?? '';
        $reqEmail = $email !== '' ? $email : 'admin@' . $domain;

        $onProgress = function (string $message) use ($domain): void {
            $this->echoLine("[{$domain}] {$message}");
            w_log_info("[DomainPoolCertificateVerify] {$domain} - {$message}", [], self::LOG_KEY);
        };

        try {
            $certRequestService = ObjectManager::getInstance(CertificateRequestService::class);
            $result = $certRequestService->requestCertificate([
                'domain' => $domain,
                'webroot' => $webroot,
                'email' => $reqEmail,
                'website_id' => 0,
                'provider' => 'letsencrypt',
                'cert_type' => 'exact',
                'cert_strategy' => 'single',
                'pool_id' => $poolId,
                'domain_id' => $domainId > 0 ? $domainId : 0,
                'challenge_strategy' => 'auto',
                '_on_progress' => $onProgress,
            ]);

            $success = (bool) ($result['success'] ?? false);
            if ($success) {
                $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
                $poolDomain->setHttpsError('');
                $poolDomain->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID);
                $poolDomain->calculateSiteReady();
                $poolDomain->save();
                $this->echoLine(__('[%{1}] 证书重新申请成功', [$domain]));
                w_log_info(__('[DomainPoolCertificateVerify] 域名 %{1} 证书重新申请成功', [$domain]), [], self::LOG_KEY);
                return true;
            }

            $msg = (string) ($result['message'] ?? __('未知错误'));
            $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
            $poolDomain->setHttpsError($msg);
            $poolDomain->save();
            $this->echoLine(__('[%{1}] 证书重新申请失败：%{2}', [$domain, $msg]));
            w_log_error(__('[DomainPoolCertificateVerify] 域名 %{1} 证书重新申请失败：%{2}', [$domain, $msg]), [], self::LOG_KEY);
            return false;
        } catch (\Throwable $e) {
            $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
            $poolDomain->setHttpsError($e->getMessage());
            $poolDomain->save();
            $this->echoLine(__('[%{1}] 证书重新申请异常：%{2}', [$domain, $e->getMessage()]));
            w_log_error(__('[DomainPoolCertificateVerify] 域名 %{1} 证书重新申请异常：%{2}', [$domain, $e->getMessage()]), [], self::LOG_KEY);
            return false;
        }
    }
}
