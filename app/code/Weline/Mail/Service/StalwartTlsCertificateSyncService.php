<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;

/**
 * 域名池 LE PEM → 本机 Stalwart Certificate（与 Smtp/Mail 启用链路共用）。
 */
final class StalwartTlsCertificateSyncService
{
    public const STATUS_NEEDS_DNS = 'needs_dns';
    public const STATUS_NEEDS_CERT = 'needs_cert';
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';

    public function __construct(
        private readonly ?StalwartManagementAdapter $adapter = null,
    ) {
    }

    private function adapter(): StalwartManagementAdapter
    {
        return $this->adapter ?? ObjectManager::getInstance(StalwartManagementAdapter::class);
    }

    /**
     * @return array{
     *   status: string,
     *   hostname: string,
     *   pool_https: string,
     *   is_local: bool,
     *   stalwart_cert_id: string,
     *   message: string,
     *   success: bool
     * }
     */
    public function getStatus(string $hostname): array
    {
        $hostname = $this->normalizeHostname($hostname);
        $base = [
            'status' => self::STATUS_NEEDS_DNS,
            'hostname' => $hostname,
            'pool_https' => '',
            'is_local' => false,
            'stalwart_cert_id' => '',
            'message' => '',
            'success' => false,
        ];
        if ($hostname === '') {
            $base['message'] = (string)__('主机名为空');

            return $base;
        }
        if (!$this->adapter()->hasManagementCredential()) {
            $base['status'] = self::STATUS_ERROR;
            $base['message'] = (string)__('Stalwart 管理凭据未配置');

            return $base;
        }

        $pool = $this->loadPoolRow($hostname);
        $https = strtolower(trim((string)($pool[DomainPool::schema_fields_HTTPS_STATUS] ?? '')));
        $isLocal = ((int)($pool[DomainPool::schema_fields_IS_LOCAL_SERVER] ?? 0)) === 1;
        $base['pool_https'] = $https;
        $base['is_local'] = $isLocal;

        if ($pool === [] || !$isLocal) {
            $base['status'] = self::STATUS_NEEDS_DNS;
            $base['message'] = (string)__('DNS 未指向本机，无法自动接线 Stalwart');

            return $base;
        }

        if ($https !== DomainPool::HTTPS_STATUS_VALID || !$this->isManagedCertificateHealthy($hostname)) {
            $base['status'] = self::STATUS_NEEDS_CERT;
            $base['message'] = (string)__('证书需要配置');

            return $base;
        }

        try {
            $ids = $this->adapter()->certificateIdsCoveringHostname($hostname);
        } catch (\Throwable $e) {
            $base['status'] = self::STATUS_ERROR;
            $base['message'] = $e->getMessage();

            return $base;
        }
        if ($ids === []) {
            $base['status'] = self::STATUS_NEEDS_CERT;
            $base['message'] = (string)__('证书需要配置');

            return $base;
        }

        $base['status'] = self::STATUS_READY;
        $base['stalwart_cert_id'] = (string)$ids[0];
        $base['message'] = (string)__('证书已就绪');
        $base['success'] = true;

        return $base;
    }

    /**
     * 确保主机：本机池 → 域名池出证 → 导入 Stalwart。
     *
     * @return array{
     *   status: string,
     *   hostname: string,
     *   pool_https: string,
     *   is_local: bool,
     *   stalwart_cert_id: string,
     *   message: string,
     *   success: bool
     * }
     */
    public function ensureForHostname(string $hostname, string $rootDomain = '', string $originIp = ''): array
    {
        $hostname = $this->normalizeHostname($hostname);
        $status = $this->getStatus($hostname);
        if ($status['status'] === self::STATUS_READY) {
            return $status;
        }
        if ($status['status'] === self::STATUS_NEEDS_DNS && ($status['is_local'] ?? false) !== true) {
            // 池行缺失时尝试入池（仍可能因非本机失败）
            if ($rootDomain === '') {
                $rootDomain = $this->inferRootDomain($hostname);
            }
            if ($rootDomain !== '') {
                $this->ensureInPool($rootDomain, [$hostname], $originIp);
                $status = $this->getStatus($hostname);
            }
            if ($status['status'] === self::STATUS_NEEDS_DNS) {
                return $status;
            }
        }

        if (!$this->adapter()->hasManagementCredential()) {
            return [
                'status' => self::STATUS_ERROR,
                'hostname' => $hostname,
                'pool_https' => (string)($status['pool_https'] ?? ''),
                'is_local' => (bool)($status['is_local'] ?? false),
                'stalwart_cert_id' => '',
                'message' => (string)__('Stalwart 管理凭据未配置'),
                'success' => false,
            ];
        }

        if ($rootDomain === '') {
            $rootDomain = $this->inferRootDomain($hostname);
        }
        if (!$this->isManagedCertificateHealthy($hostname)) {
            $poolResult = $this->ensureInPool($rootDomain, [$hostname], $originIp);
            if (($poolResult['success'] ?? false) !== true
                && !$this->isManagedCertificateHealthy($hostname)
            ) {
                return [
                    'status' => self::STATUS_NEEDS_CERT,
                    'hostname' => $hostname,
                    'pool_https' => (string)($this->loadPoolRow($hostname)[DomainPool::schema_fields_HTTPS_STATUS] ?? ''),
                    'is_local' => ((int)($this->loadPoolRow($hostname)[DomainPool::schema_fields_IS_LOCAL_SERVER] ?? 0)) === 1,
                    'stalwart_cert_id' => '',
                    'message' => (string)($poolResult['message'] ?? __('证书需要配置')),
                    'success' => false,
                ];
            }
        }

        $pool = $this->loadPoolRow($hostname);
        if (((int)($pool[DomainPool::schema_fields_IS_LOCAL_SERVER] ?? 0)) !== 1) {
            return [
                'status' => self::STATUS_NEEDS_DNS,
                'hostname' => $hostname,
                'pool_https' => (string)($pool[DomainPool::schema_fields_HTTPS_STATUS] ?? ''),
                'is_local' => false,
                'stalwart_cert_id' => '',
                'message' => (string)__('DNS 未指向本机，无法自动接线 Stalwart'),
                'success' => false,
            ];
        }

        try {
            $resolved = w_query('server', 'resolveManagedCertificate', [
                'hostname' => $hostname,
            ]);
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_ERROR,
                'hostname' => $hostname,
                'pool_https' => (string)($pool[DomainPool::schema_fields_HTTPS_STATUS] ?? ''),
                'is_local' => true,
                'stalwart_cert_id' => '',
                'message' => $e->getMessage(),
                'success' => false,
            ];
        }
        if (!is_array($resolved)) {
            return [
                'status' => self::STATUS_NEEDS_CERT,
                'hostname' => $hostname,
                'pool_https' => (string)($pool[DomainPool::schema_fields_HTTPS_STATUS] ?? ''),
                'is_local' => true,
                'stalwart_cert_id' => '',
                'message' => (string)__('证书需要配置'),
                'success' => false,
            ];
        }
        $certPath = (string)($resolved['cert_path'] ?? '');
        $keyPath = (string)($resolved['key_path'] ?? '');
        $fullchain = $certPath !== '' && is_readable($certPath) ? (string)file_get_contents($certPath) : '';
        $privkey = $keyPath !== '' && is_readable($keyPath) ? (string)file_get_contents($keyPath) : '';
        if ($fullchain === '' || $privkey === '') {
            return [
                'status' => self::STATUS_NEEDS_CERT,
                'hostname' => $hostname,
                'pool_https' => (string)($pool[DomainPool::schema_fields_HTTPS_STATUS] ?? ''),
                'is_local' => true,
                'stalwart_cert_id' => '',
                'message' => (string)__('证书需要配置'),
                'success' => false,
            ];
        }

        try {
            $imported = $this->adapter()->importManagedHostnameCertificate($hostname, $fullchain, $privkey);
        } catch (\Throwable $e) {
            w_log_error('[Mail] Stalwart TLS import failed: ' . $e->getMessage(), ['hostname' => $hostname], 'mail');

            return [
                'status' => self::STATUS_ERROR,
                'hostname' => $hostname,
                'pool_https' => (string)($pool[DomainPool::schema_fields_HTTPS_STATUS] ?? ''),
                'is_local' => true,
                'stalwart_cert_id' => '',
                'message' => $e->getMessage(),
                'success' => false,
            ];
        }

        return [
            'status' => self::STATUS_READY,
            'hostname' => $hostname,
            'pool_https' => DomainPool::HTTPS_STATUS_VALID,
            'is_local' => true,
            'stalwart_cert_id' => (string)($imported['cert_id'] ?? ''),
            'message' => (string)($imported['message'] ?? __('证书已就绪')),
            'success' => true,
        ];
    }

    /**
     * @param list<string> $hostnames
     * @return list<array<string, mixed>>
     */
    public function ensureForHostnames(array $hostnames, string $rootDomain = '', string $originIp = ''): array
    {
        $out = [];
        foreach ($hostnames as $h) {
            $n = $this->normalizeHostname((string)$h);
            if ($n === '') {
                continue;
            }
            $out[] = $this->ensureForHostname($n, $rootDomain, $originIp);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureInPool(string $rootDomain, array $hostnames, string $originIp): array
    {
        $rootDomain = $this->normalizeHostname($rootDomain);
        $hostnames = array_values(array_filter(array_map([$this, 'normalizeHostname'], $hostnames)));
        if ($rootDomain === '' || $hostnames === []) {
            return ['success' => false, 'message' => (string)__('根域或主机名为空')];
        }
        try {
            $result = w_query('websites', 'ensurePublicDnsHostnamesInPool', [
                'root_domain' => $rootDomain,
                'hostnames' => $hostnames,
                'origin_ip' => trim($originIp),
                'request_certificate' => true,
                'source' => 'mail_stalwart_tls_sync',
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return is_array($result) ? $result : ['success' => false, 'message' => (string)__('域名池同步返回异常')];
    }

    private function isManagedCertificateHealthy(string $hostname): bool
    {
        try {
            return (bool)w_query('server', 'isManagedCertificateHealthy', [
                'hostname' => $hostname,
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPoolRow(string $hostname): array
    {
        try {
            /** @var DomainPool $pool */
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            $pool->clearQuery()->loadByDomain($hostname);
            if ((int)$pool->getPoolId() <= 0) {
                return [];
            }

            return $pool->getData() ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function inferRootDomain(string $hostname): string
    {
        if (str_starts_with($hostname, 'smtp.') || str_starts_with($hostname, 'mail.')) {
            return substr($hostname, 5);
        }
        $parts = explode('.', $hostname);
        if (count($parts) >= 2) {
            return implode('.', array_slice($parts, -2));
        }

        return $hostname;
    }

    private function normalizeHostname(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('#^https?://#', '', $value) ?? $value;

        return rtrim($value, '.');
    }
}
