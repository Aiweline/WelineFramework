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
 * - 检查域名是否可访问
 * - 验证 HTTPS 证书有效性
 * - 更新健康状态
 * - 同步 HTTPS 状态（证书失效自动回退 HTTP）
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
            'details' => [],
        ];
        
        foreach ($domains as $domainData) {
            $domain = $domainData[WebsiteDomain::schema_fields_DOMAIN];
            $domainId = (int) $domainData[WebsiteDomain::schema_fields_ID];
            $hasHttps = (bool) $domainData[WebsiteDomain::schema_fields_HTTPS_ENABLED];
            $certId = $domainData[WebsiteDomain::schema_fields_CERT_ID] ?? null;
            WebsitesCronTestContext::detail('HealthCheck.checkDomain', ['domain' => $domain, 'https' => $hasHttps]);

            // 执行健康检查
            $checkResult = $this->checkDomain($domain, $hasHttps);
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
                $this->syncHttpsStatus($domain, $certId, $checkResult['https_available']);
            }
            
            $results['details'][$domain] = $checkResult;
        }
        
        return $results;
    }
    
    /**
     * 检查单个域名的健康状态
     * 
     * @param string $domain 域名
     * @param bool $expectHttps 期望是否 HTTPS
     * @return array 检查结果
     */
    public function checkDomain(string $domain, bool $expectHttps = false): array
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
        
        // 确定要检查的 URL
        $protocol = $expectHttps ? 'https' : 'http';
        $url = $protocol . '://' . $domain . '/';
        
        // 执行请求
        $startTime = \microtime(true);
        $response = $this->httpRequest($url);
        $result['response_time_ms'] = \round((\microtime(true) - $startTime) * 1000, 2);
        
        $result['code'] = $response['code'];
        
        if ($response['success']) {
            $result['status'] = WebsiteDomain::HEALTH_HEALTHY;
            $result['message'] = __('访问正常');
            
            // 如果期望 HTTPS 且成功，确认 HTTPS 可用
            if ($expectHttps) {
                $result['https_available'] = true;
            }
        } else {
            $result['status'] = WebsiteDomain::HEALTH_UNHEALTHY;
            $result['message'] = $response['error'];
            
            // 如果 HTTPS 失败，检查是否是证书问题
            if ($expectHttps && $this->isSslError($response['error'])) {
                // 尝试 HTTP
                $httpResponse = $this->httpRequest('http://' . $domain . '/');
                if ($httpResponse['success']) {
                    $result['message'] = __('HTTPS 证书无效，已自动回退 HTTP');
                    $result['https_available'] = false;
                    $result['https_changed'] = true;
                    $result['status'] = WebsiteDomain::HEALTH_HEALTHY;
                    $result['code'] = $httpResponse['code'];
                }
            }
        }
        
        // 检查 HTTPS 是否可用（如果当前是 HTTP）
        if (!$expectHttps && $result['status'] === WebsiteDomain::HEALTH_HEALTHY) {
            $httpsCheck = $this->checkHttpsAvailable($domain);
            if ($httpsCheck['available']) {
                $result['https_available'] = true;
            }
        }
        
        return $result;
    }
    
    /**
     * 检查 HTTPS 是否可用
     */
    protected function checkHttpsAvailable(string $domain): array
    {
        $response = $this->httpRequest('https://' . $domain . '/');
        return [
            'available' => $response['success'],
            'code' => $response['code'],
        ];
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
     * 同步 HTTPS 状态
     * 
     * @param string $domain 域名
     * @param int|null $certId 证书 ID
     * @param bool $httpsAvailable HTTPS 是否可用
     */
    protected function syncHttpsStatus(string $domain, ?int $certId, bool $httpsAvailable): void
    {
        if ($httpsAvailable && $certId) {
            // HTTPS 可用且有证书
            $this->domainModel->syncDomainCertificate($domain, $certId, true);
        } else {
            // HTTPS 不可用，回退到 HTTP
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
            
            $results[$domain] = $this->checkDomain($domain, $hasHttps);
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
        ];
        
        foreach ($domains as $domainData) {
            $domain = $domainData[WebsiteDomain::schema_fields_DOMAIN];
            $currentHttps = (bool) $domainData[WebsiteDomain::schema_fields_HTTPS_ENABLED];
            $certId = $domainData[WebsiteDomain::schema_fields_CERT_ID] ?? null;
            WebsitesCronTestContext::detail('HttpsSync.row', ['domain' => $domain, 'https_enabled' => $currentHttps]);

            // 创建一个临时模型实例来检查证书有效性
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            $domainModel->setData($domainData);
            
            $hasValidCert = $domainModel->hasValidCertificate();
            
            if ($hasValidCert !== $currentHttps) {
                if ($hasValidCert) {
                    $this->domainModel->syncDomainCertificate($domain, $certId, true);
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
