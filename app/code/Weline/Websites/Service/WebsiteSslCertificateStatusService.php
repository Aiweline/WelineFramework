<?php
declare(strict_types=1);

/**
 * 站点域名与 SSL 证书管理表对齐：所有「证书是否有效 / 是否启用 HTTPS」的判断统一走此服务。
 * 不通过带证书校验的 HTTPS 请求推断。
 */

namespace Weline\Websites\Service;

use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;

final class WebsiteSslCertificateStatusService
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_EXPIRED = 'expired';
    private const STATUS_REVOKED = 'revoked';
    private const STATUS_ERROR = 'error';

    public function resolveManagedCertificate(?int $preferredCertId, string $hostname): ?array
    {
        $hostname = \strtolower(\trim($hostname));
        if ($hostname === '') {
            return null;
        }

        try {
            $result = w_query('server', 'resolveManagedCertificate', [
                'hostname' => $hostname,
                'preferred_cert_id' => $preferredCertId,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        return \is_array($result) ? $result : null;
    }

    public function hasValidManagedCertificate(string $hostname, ?int $preferredCertId): bool
    {
        try {
            return (bool) w_query('server', 'hasValidManagedCertificate', [
                'hostname' => $hostname,
                'preferred_cert_id' => $preferredCertId,
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 用于写回 website_domain.cert_id：仅当证书管理中存在 active 且未过期记录时返回其 ID
     */
    public function effectiveCertIdForWebsiteBinding(string $hostname, ?int $preferredCertId): ?int
    {
        $cert = $this->resolveManagedCertificate($preferredCertId, $hostname);
        if (!$this->isValidCertificate($cert)) {
            return null;
        }

        return (int) ($cert['cert_id'] ?? 0);
    }

    /**
     * 供连通性 hover 等展示的简短文案（与池/根域 https_status 语义一致，数据源为证书表）
     */
    public function getManagementSummaryLabel(string $hostname, ?int $preferredCertId = null): string
    {
        try {
            $cert = $this->resolveManagedCertificate($preferredCertId, $hostname);
            if (!$this->hasCertificateId($cert)) {
                return (string) \__('证书管理：无记录（待申请）');
            }
            $status = $this->getStatus($cert);
            if ($status === self::STATUS_REVOKED || $status === self::STATUS_ERROR) {
                return (string) \__('证书管理：异常');
            }
            if ($status === self::STATUS_EXPIRED || $this->isExpired($cert)) {
                return (string) \__('证书管理：已过期');
            }
            if ($status === self::STATUS_PENDING) {
                return (string) \__('证书管理：申请中');
            }
            if ($status === self::STATUS_ACTIVE && !$this->isExpired($cert)) {
                return (string) \__('证书管理：有效');
            }

            return (string) \__('证书管理：待处理');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function mapToPoolHttpsStatus(?array $cert): string
    {
        if (!$this->hasCertificateId($cert)) {
            return DomainPool::HTTPS_STATUS_PENDING;
        }
        $status = $this->getStatus($cert);
        if ($status === self::STATUS_REVOKED || $status === self::STATUS_ERROR) {
            return DomainPool::HTTPS_STATUS_ERROR;
        }
        if ($status === self::STATUS_EXPIRED || $this->isExpired($cert)) {
            return DomainPool::HTTPS_STATUS_EXPIRED;
        }
        if ($status === self::STATUS_PENDING) {
            return DomainPool::HTTPS_STATUS_PENDING;
        }
        if ($status === self::STATUS_ACTIVE && !$this->isExpired($cert)) {
            return DomainPool::HTTPS_STATUS_VALID;
        }

        return DomainPool::HTTPS_STATUS_PENDING;
    }

    public function mapToDomainHttpsStatus(?array $cert): string
    {
        if (!$this->hasCertificateId($cert)) {
            return Domain::HTTPS_STATUS_PENDING;
        }
        $status = $this->getStatus($cert);
        if ($status === self::STATUS_REVOKED || $status === self::STATUS_ERROR) {
            return Domain::HTTPS_STATUS_ERROR;
        }
        if ($status === self::STATUS_EXPIRED || $this->isExpired($cert)) {
            return Domain::HTTPS_STATUS_EXPIRED;
        }
        if ($status === self::STATUS_PENDING) {
            return Domain::HTTPS_STATUS_PENDING;
        }
        if ($status === self::STATUS_ACTIVE && !$this->isExpired($cert)) {
            return Domain::HTTPS_STATUS_VALID;
        }

        return Domain::HTTPS_STATUS_PENDING;
    }

    private function hasCertificateId(?array $cert): bool
    {
        return $cert !== null && (int) ($cert['cert_id'] ?? 0) > 0;
    }

    private function isValidCertificate(?array $cert): bool
    {
        return $this->hasCertificateId($cert)
            && $this->getStatus($cert) === self::STATUS_ACTIVE
            && !$this->isExpired($cert);
    }

    private function getStatus(?array $cert): string
    {
        return (string) ($cert['status'] ?? '');
    }

    private function isExpired(?array $cert): bool
    {
        return (bool) ($cert['is_expired'] ?? true);
    }
}
