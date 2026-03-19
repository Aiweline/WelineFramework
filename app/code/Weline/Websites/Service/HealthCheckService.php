<?php
declare(strict_types=1);

/**
 * Weline Websites - 健康检查服务
 * 
 * 定时检查所有网站域名的可访问性
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * 健康检查服务
 *
 * 功能：
 * - 检查域名是否可访问（HTTP/HTTPS 请求仅表示连通性，不用于判断证书是否在「证书管理」中有效）
 * - 更新健康状态
 * - 按 {@see WebsiteSslCertificateStatusService} 同步站点 HTTPS 开关与 cert_id（与请求探测解耦）
 * - 将结果同步到根域 {@see Domain}、域名池 {@see DomainPool}（连通性来自探测；https_status 来自证书表）
 */
class HealthCheckService
{
    /**
     * 请求超时时间（秒）
     */
    protected int $timeout = 10;
    
    /**
     * 连接超时时间（秒）
     */
    protected int $connectTimeout = 5;
    
    /**
     * @var WebsiteDomain
     */
    protected WebsiteDomain $domainModel;
    
    public function __construct()
    {
        $this->domainModel = ObjectManager::getInstance(WebsiteDomain::class);
    }

    /**
     * @return array{local_endpoint_probe?: bool, local_bind_address?: string}
     */
    public static function getHealthCheckProbeConfig(): array
    {
        $cfg = Env::getInstance()->getConfig();

        return (array) (($cfg['websites'] ?? [])['health_check'] ?? []);
    }

    /**
     * CURLOPT_RESOLVE 将主机解析到环回，URL 不变，SNI/证书校验仍针对原主机名。
     *
     * @param resource|\CurlHandle $ch
     */
    public static function applyLocalEndpointProbeToCurl($ch, string $url): void
    {
        $hc = self::getHealthCheckProbeConfig();
        if (!($hc['local_endpoint_probe'] ?? true)) {
            return;
        }
        $bind = \trim((string) ($hc['local_bind_address'] ?? '127.0.0.1'));
        if ($bind === '') {
            $bind = '127.0.0.1';
        }
        $parts = \parse_url($url);
        if ($parts === false || ($parts['host'] ?? '') === '') {
            return;
        }
        $scheme = \strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' && $scheme !== 'http') {
            return;
        }
        $host = (string) $parts['host'];
        if ($host === '127.0.0.1' || $host === 'localhost' || $host === '[::1]' || $host === '::1') {
            return;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return;
        }
        $addr = \str_contains($bind, ':') && !\str_starts_with($bind, '[')
            ? '[' . $bind . ']'
            : $bind;
        \curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$addr}"]);
    }
    
    /**
     * 设置超时时间
     */
    public function setTimeout(int $timeout, int $connectTimeout = 5): self
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        return $this;
    }
    
    /**
     * 检查所有活跃域名的健康状态
     * 
     * @return array 检查结果摘要
     */
    public function checkAllDomains(): array
    {
        $domains = $this->domainModel->getAllActiveDomainsForHealthCheck();
        if (WebsitesCronTestContext::getDomainFilter() !== null) {
            $domains = \array_values(\array_filter(
                $domains,
                static fn (array $d): bool => WebsitesCronTestContext::matchesSubject(
                    (string) ($d[WebsiteDomain::schema_fields_DOMAIN] ?? ''),
                    null
                )
            ));
            WebsitesCronTestContext::detail('HealthCheck.filtered_domains', ['count' => \count($domains)]);
        }

        $results = [
            'total' => \count($domains),
            'healthy' => 0,
            'unhealthy' => 0,
            'https_updated' => 0,
            'infra_synced' => 0,
            'skipped_cron_lock' => 0,
            'details' => [],
        ];

        $infraSync = ObjectManager::getInstance(HealthCheckInfrastructureSyncService::class);
        $cronLock = ObjectManager::getInstance(DomainCronLockService::class);

        foreach ($domains as $domainData) {
            $domain = $domainData[WebsiteDomain::schema_fields_DOMAIN];
            $rootFqdn = \strtolower(\trim((string) ($domainData[WebsiteDomain::schema_fields_ROOT_DOMAIN] ?? '')));
            if ($rootFqdn === '') {
                $rootFqdn = \strtolower(\trim((string) $domain));
            }
            if ($cronLock->shouldSkipNonCertificateWorkForRootFqdn($rootFqdn)) {
                $results['skipped_cron_lock']++;
                continue;
            }
            $domainId = (int) $domainData[WebsiteDomain::schema_fields_ID];
            $hasHttps = (bool) $domainData[WebsiteDomain::schema_fields_HTTPS_ENABLED];
            $certRaw = $domainData[WebsiteDomain::schema_fields_CERT_ID] ?? null;
            $certId = ($certRaw !== null && $certRaw !== '') ? (int) $certRaw : null;
            WebsitesCronTestContext::detail('HealthCheck.checkDomain', ['domain' => $domain, 'https' => $hasHttps]);

            // 执行健康检查
            $checkResult = $this->checkDomain($domain, $hasHttps, $certId);
            WebsitesCronTestContext::detail('HealthCheck.result', ['domain' => $domain, 'checkResult' => $checkResult]);
            
            // 更新数据库
            $this->domainModel->updateHealthCheck(
                $domainId,
                $checkResult['status'],
                $checkResult['code'],
                $checkResult['message']
            );
            
            // 统计
            if ($checkResult['status'] === WebsiteDomain::HEALTH_HEALTHY) {
                $results['healthy']++;
            } else {
                $results['unhealthy']++;
            }
            
            // 检查是否需要更新 HTTPS 状态
            if ($checkResult['https_changed']) {
                $results['https_updated']++;
                $sslSvc = ObjectManager::getInstance(WebsiteSslCertificateStatusService::class);
                $effectiveCertId = $sslSvc->effectiveCertIdForWebsiteBinding($domain, $certId);
                $this->syncHttpsStatus($domain, $effectiveCertId, $checkResult['https_available']);
            }

            // 同步根域 Domain / 域名池 DomainPool 的连通性与 HTTPS 状态（与 WebsiteDomain 探测结果对齐）
            if ($infraSync->syncFromHealthProbe($domainId, $checkResult)) {
                $results['infra_synced']++;
            }

            $results['details'][$domain] = $checkResult;
        }
        
        return $results;
    }
    
    /**
     * 检查单个域名的健康状态
     * 
     * @param string $domain 域名
     * @param bool $expectHttps 当前站点是否启用 HTTPS（数据库）
     * @param int|null $certId 站点绑定的 cert_id（可为空，仍可从证书表按域名解析）
     * @return array 检查结果
     */
    public function checkDomain(string $domain, bool $expectHttps = false, ?int $certId = null): array
    {
        $result = [
            'domain' => $domain,
            'status' => WebsiteDomain::HEALTH_UNKNOWN,
            'code' => null,
            'message' => '',
            'https_available' => false,
            'https_changed' => false,
            'response_time_ms' => 0,
        ];

        // 确定要检查的 URL（仅用于连通性，不用于推断证书是否在证书管理中有效）
        $protocol = $expectHttps ? 'https' : 'http';
        $url = $protocol . '://' . $domain . '/';

        $startTime = \microtime(true);
        $response = $this->httpRequest($url);
        $result['response_time_ms'] = \round((\microtime(true) - $startTime) * 1000, 2);

        $result['code'] = $response['code'];

        if ($response['success']) {
            $result['status'] = WebsiteDomain::HEALTH_HEALTHY;
            $result['message'] = __('访问正常');
        } else {
            $result['status'] = WebsiteDomain::HEALTH_UNHEALTHY;
            $result['message'] = $response['error'];

            if ($expectHttps && $this->isSslError($response['error'])) {
                $httpResponse = $this->httpRequest('http://' . $domain . '/');
                if ($httpResponse['success']) {
                    $result['status'] = WebsiteDomain::HEALTH_HEALTHY;
                    $result['code'] = $httpResponse['code'];
                    $result['message'] = __(
                        'HTTPS/TLS 探测失败，HTTP 可访问（站点 HTTPS 是否启用以 SSL 证书管理为准）'
                    );
                }
            }
        }

        $sslSvc = ObjectManager::getInstance(WebsiteSslCertificateStatusService::class);
        $sslValid = $sslSvc->hasValidManagedCertificate($domain, $certId);
        $result['https_available'] = $sslValid;
        $result['https_changed'] = ($sslValid !== $expectHttps);

        return $result;
    }
    
    /**
     * 发送 HTTP 请求
     */
    protected function httpRequest(string $url): array
    {
        $result = [
            'success' => false,
            'code' => null,
            'error' => '',
            'body' => '',
        ];
        
        $ch = \curl_init();
        
        \curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'Weline-HealthCheck/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // 只获取头部，不下载完整内容
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
        ]);
        self::applyLocalEndpointProbeToCurl($ch, $url);
        
        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        $errno = \curl_errno($ch);
        
        \curl_close($ch);
        
        $result['code'] = $httpCode;
        
        if ($errno === 0 && $httpCode > 0 && $httpCode < 500) {
            $result['success'] = true;
        } else {
            $result['error'] = $error ?: __('HTTP %{1}', [$httpCode]);
        }
        
        return $result;
    }
    
    /**
     * 判断是否为 SSL 相关错误
     */
    protected function isSslError(string $error): bool
    {
        $sslKeywords = [
            'SSL',
            'certificate',
            'TLS',
            'handshake',
            'verify',
            'expired',
        ];
        
        $errorLower = \strtolower($error);
        foreach ($sslKeywords as $keyword) {
            if (\stripos($errorLower, \strtolower($keyword)) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 同步 HTTPS 状态（httpsAvailable 须与证书管理一致；启用时必须带有效 cert_id）
     *
     * @param string $domain 域名
     * @param int|null $certId 证书管理中的有效 cert_id
     * @param bool $httpsAvailable 是否应启用 HTTPS
     */
    protected function syncHttpsStatus(string $domain, ?int $certId, bool $httpsAvailable): void
    {
        if ($httpsAvailable) {
            if ($certId !== null && $certId > 0) {
                $this->domainModel->syncDomainCertificate($domain, $certId, true);
            }
        } else {
            $this->domainModel->rollbackHttps($domain);
        }
    }
    
    /**
     * 检查指定网站的所有域名
     */
    public function checkWebsiteDomains(int $websiteId): array
    {
        $domains = $this->domainModel->getDomainsWithStatus($websiteId);
        $results = [];
        
        foreach ($domains as $domainData) {
            $domain = $domainData[WebsiteDomain::schema_fields_DOMAIN];
            $hasHttps = (bool) $domainData[WebsiteDomain::schema_fields_HTTPS_ENABLED];
            $certRaw = $domainData[WebsiteDomain::schema_fields_CERT_ID] ?? null;
            $certId = ($certRaw !== null && $certRaw !== '') ? (int) $certRaw : null;

            $results[$domain] = $this->checkDomain($domain, $hasHttps, $certId);
        }
        
        return $results;
    }
    
    /**
     * 同步所有域名的 HTTPS 状态
     * 
     * 根据证书有效性自动更新各域名的 HTTPS 状态
     * 
     * @return array 同步结果
     */
    public function syncAllHttpsStatus(): array
    {
        $domains = $this->domainModel->getAllActiveDomainsForHealthCheck();
        if (WebsitesCronTestContext::getDomainFilter() !== null) {
            $domains = \array_values(\array_filter(
                $domains,
                static fn (array $d): bool => WebsitesCronTestContext::matchesSubject(
                    (string) ($d[WebsiteDomain::schema_fields_DOMAIN] ?? ''),
                    null
                )
            ));
        }

        $results = [
            'total' => \count($domains),
            'https_enabled' => 0,
            'https_disabled' => 0,
            'unchanged' => 0,
            'skipped_cron_lock' => 0,
        ];

        $sslSvc = ObjectManager::getInstance(WebsiteSslCertificateStatusService::class);
        $cronLock = ObjectManager::getInstance(DomainCronLockService::class);

        foreach ($domains as $domainData) {
            $domain = $domainData[WebsiteDomain::schema_fields_DOMAIN];
            $rootFqdn = \strtolower(\trim((string) ($domainData[WebsiteDomain::schema_fields_ROOT_DOMAIN] ?? '')));
            if ($rootFqdn === '') {
                $rootFqdn = \strtolower(\trim((string) $domain));
            }
            if ($cronLock->shouldSkipNonCertificateWorkForRootFqdn($rootFqdn)) {
                $results['skipped_cron_lock']++;
                continue;
            }
            $currentHttps = (bool) $domainData[WebsiteDomain::schema_fields_HTTPS_ENABLED];
            $certRaw = $domainData[WebsiteDomain::schema_fields_CERT_ID] ?? null;
            $prefCertId = ($certRaw !== null && $certRaw !== '') ? (int) $certRaw : null;
            WebsitesCronTestContext::detail('HttpsSync.row', ['domain' => $domain, 'https_enabled' => $currentHttps]);

            $effectiveCertId = $sslSvc->effectiveCertIdForWebsiteBinding($domain, $prefCertId);
            $hasValidCert = $effectiveCertId !== null && $effectiveCertId > 0;

            if ($hasValidCert !== $currentHttps) {
                if ($hasValidCert && $effectiveCertId !== null) {
                    $this->domainModel->syncDomainCertificate($domain, $effectiveCertId, true);
                    $results['https_enabled']++;
                } else {
                    $this->domainModel->rollbackHttps($domain);
                    $results['https_disabled']++;
                }
            } else {
                $results['unchanged']++;
            }
        }
        
        return $results;
    }
}
