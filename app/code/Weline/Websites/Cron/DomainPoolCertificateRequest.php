<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池证书自动申请定时任务
 *
 * 定期检测域名池中解析已生效且指向本服务器的域名，自动申请 HTTPS 证书
 * 条件：resolve_status=resolved + is_local_server=1 + https_status in (none, expired, error)
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
        return __('定期为域名池内解析已生效且指向本服务器的域名自动申请 Let\'s Encrypt 证书');
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

        try {
            $domainPoolModel = ObjectManager::getInstance(DomainPool::class);
            $domains = $domainPoolModel->getDomainsNeedCertificate(50);
            $results['checked'] = \count($domains);
            if ($domains === []) {
                return __('没有需要申请证书的域名池域名');
            }

            $strategy = (string) (Env::get('server.ssl.cert_strategy', self::DEFAULT_CERT_STRATEGY) ?? self::DEFAULT_CERT_STRATEGY);
            $strategy = \in_array($strategy, ['single', 'wildcard_prefer', 'both'], true) ? $strategy : self::DEFAULT_CERT_STRATEGY;
            $webroot = \defined('PUB') ? PUB : (BP . 'pub');
            $email = Env::getInstance()->getConfig('ssl.contact_email') ?? '';

            $groupedByRoot = [];
            foreach ($domains as $row) {
                $rootDomain = (string) ($row[DomainPool::schema_fields_ROOT_DOMAIN] ?? '');
                $groupedByRoot[$rootDomain !== '' ? $rootDomain : (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '')][] = $row;
            }

            foreach ($groupedByRoot as $rootDomain => $rows) {
                if (($strategy === 'wildcard_prefer' || $strategy === 'both') && $rootDomain !== '') {
                    $wildResult = $this->requestByRow($rows[0], $webroot, $email, $strategy, true);
                    $results['requested'] += $wildResult['requested'];
                    $results['success'] += $wildResult['success'];
                    $results['failed'] += $wildResult['failed'];
                    $results['skipped'] += $wildResult['skipped'];
                    if ($wildResult['success'] > 0 && $strategy === 'wildcard_prefer') {
                        continue;
                    }
                }

                foreach ($rows as $row) {
                    $singleResult = $this->requestByRow($row, $webroot, $email, 'single', false);
                    $results['requested'] += $singleResult['requested'];
                    $results['success'] += $singleResult['success'];
                    $results['failed'] += $singleResult['failed'];
                    $results['skipped'] += $singleResult['skipped'];
                }
            }

            return __('域名池证书申请完成：检查 %{1} 个，申请 %{2} 个，成功 %{3} 个，失败 %{4} 个', [
                $results['checked'],
                $results['requested'],
                $results['success'],
                $results['failed'],
            ]);
        } catch (\Throwable $e) {
            $err = __('域名池证书申请任务异常：%{1}', [$e->getMessage()]);
            w_log_error('[DomainPoolCertificateRequest] ' . $err, [], 'domain_pool_cert');
            return $err;
        }
    }

    private function requestByRow(array $row, string $webroot, string $email, string $strategy, bool $isWildcard): array
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
            return $counter;
        }

        $requestDomain = $isWildcard && $rootDomain !== '' ? '*.' . $rootDomain : $domain;
        $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
        $poolDomain->setData($row);
        $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
        $poolDomain->setHttpsError('');
        $poolDomain->save();

        $counter['requested']++;
        $reqEmail = $email !== '' ? $email : 'admin@' . $domain;
        try {
            $result = w_query('server', 'requestCertificate', [
                'domain' => $requestDomain,
                'webroot' => $webroot,
                'email' => $reqEmail,
                'website_id' => 0,
                'provider' => 'letsencrypt',
                'cert_type' => $isWildcard ? 'wildcard' : 'exact',
                'cert_strategy' => $strategy,
                'pool_id' => $poolId,
            ]);

            if ($result['success'] ?? false) {
                if (!$isWildcard && !$this->validateHttpsAccess($domain)) {
                    $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
                    $poolDomain->setHttpsError((string)__('证书已签发，HTTPS 连通性校验未通过，等待下次检测'));
                    $poolDomain->setSiteReady(false);
                    $poolDomain->save();
                }
                $counter['success']++;
            } else {
                $counter['failed']++;
                $msg = $result['message'] ?? __('未知错误');
                $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
                $poolDomain->setHttpsError($msg);
                $poolDomain->save();
                w_log_error(__('[DomainPoolCertificateRequest] %{1} 证书申请失败: %{2}', [$requestDomain, $msg]), [], 'domain_pool_cert');
            }
        } catch (\Throwable $e) {
            $counter['failed']++;
            $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
            $poolDomain->setHttpsError($e->getMessage());
            $poolDomain->save();
            w_log_error(__('[DomainPoolCertificateRequest] %{1} 证书申请异常: %{2}', [$requestDomain, $e->getMessage()]), [], 'domain_pool_cert');
        }

        return $counter;
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
