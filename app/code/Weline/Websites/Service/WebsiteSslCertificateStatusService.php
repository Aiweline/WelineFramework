<?php
declare(strict_types=1);

/**
 * 站点域名与 SSL 证书管理表对齐：所有「证书是否有效 / 应否启用 HTTPS」的判定统一走此服务，
 * 不通过带证书校验的 HTTPS 请求推断。
 */

namespace Weline\Websites\Service;

use Weline\Server\Model\SslCertificate;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;

final class WebsiteSslCertificateStatusService
{
    public function resolveManagedCertificate(?int $preferredCertId, string $hostname): ?SslCertificate
    {
        if (!\class_exists(SslCertificate::class)) {
            return null;
        }
        $hostname = \strtolower(\trim($hostname));
        if ($hostname === '') {
            return null;
        }

        return SslCertificate::resolveForWebsiteInfrastructure($preferredCertId, $hostname);
    }

    public function hasValidManagedCertificate(string $hostname, ?int $preferredCertId): bool
    {
        $c = $this->resolveManagedCertificate($preferredCertId, $hostname);
        if ($c === null || $c->getCertId() <= 0) {
            return false;
        }

        return $c->getStatus() === SslCertificate::STATUS_ACTIVE && !$c->isExpired();
    }

    /**
     * 用于写回 website_domain.cert_id：仅当证书管理中存在 active 且未过期记录时返回其 ID
     */
    public function effectiveCertIdForWebsiteBinding(string $hostname, ?int $preferredCertId): ?int
    {
        $c = $this->resolveManagedCertificate($preferredCertId, $hostname);
        if ($c === null || $c->getCertId() <= 0) {
            return null;
        }
        if ($c->getStatus() !== SslCertificate::STATUS_ACTIVE || $c->isExpired()) {
            return null;
        }

        return $c->getCertId();
    }

    /**
     * 供连通性 hover 等展示的简短文案（与池/根域 https_status 语义一致，数据源为证书表）
     */
    public function getManagementSummaryLabel(string $hostname, ?int $preferredCertId = null): string
    {
        if (!\class_exists(SslCertificate::class)) {
            return '';
        }
        try {
            $c = $this->resolveManagedCertificate($preferredCertId, $hostname);
            if ($c === null || $c->getCertId() <= 0) {
                return (string) \__('证书管理：无记录（待申请）');
            }
            $st = $c->getStatus();
            if ($st === SslCertificate::STATUS_REVOKED || $st === SslCertificate::STATUS_ERROR) {
                return (string) \__('证书管理：异常');
            }
            if ($st === SslCertificate::STATUS_EXPIRED || $c->isExpired()) {
                return (string) \__('证书管理：已过期');
            }
            if ($st === SslCertificate::STATUS_PENDING) {
                return (string) \__('证书管理：申请中');
            }
            if ($st === SslCertificate::STATUS_ACTIVE && !$c->isExpired()) {
                return (string) \__('证书管理：有效');
            }

            return (string) \__('证书管理：待处理');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function mapToPoolHttpsStatus(?SslCertificate $cert): string
    {
        if ($cert === null || $cert->getCertId() <= 0) {
            return DomainPool::HTTPS_STATUS_PENDING;
        }
        $st = $cert->getStatus();
        if ($st === SslCertificate::STATUS_REVOKED || $st === SslCertificate::STATUS_ERROR) {
            return DomainPool::HTTPS_STATUS_ERROR;
        }
        if ($st === SslCertificate::STATUS_EXPIRED || $cert->isExpired()) {
            return DomainPool::HTTPS_STATUS_EXPIRED;
        }
        if ($st === SslCertificate::STATUS_PENDING) {
            return DomainPool::HTTPS_STATUS_PENDING;
        }
        if ($st === SslCertificate::STATUS_ACTIVE && !$cert->isExpired()) {
            return DomainPool::HTTPS_STATUS_VALID;
        }

        return DomainPool::HTTPS_STATUS_PENDING;
    }

    public function mapToDomainHttpsStatus(?SslCertificate $cert): string
    {
        if ($cert === null || $cert->getCertId() <= 0) {
            return Domain::HTTPS_STATUS_PENDING;
        }
        $st = $cert->getStatus();
        if ($st === SslCertificate::STATUS_REVOKED || $st === SslCertificate::STATUS_ERROR) {
            return Domain::HTTPS_STATUS_ERROR;
        }
        if ($st === SslCertificate::STATUS_EXPIRED || $cert->isExpired()) {
            return Domain::HTTPS_STATUS_EXPIRED;
        }
        if ($st === SslCertificate::STATUS_PENDING) {
            return Domain::HTTPS_STATUS_PENDING;
        }
        if ($st === SslCertificate::STATUS_ACTIVE && !$cert->isExpired()) {
            return Domain::HTTPS_STATUS_VALID;
        }

        return Domain::HTTPS_STATUS_PENDING;
    }
}
