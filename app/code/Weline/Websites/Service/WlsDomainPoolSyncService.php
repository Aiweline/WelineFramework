<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\WebsiteDomain;

/**
 * Sync Websites DomainPool from WLS domain lifecycle events.
 */
class WlsDomainPoolSyncService
{
    public function __construct(
        private readonly DomainPool $domainPool,
    ) {
    }

    /**
     * @return array{pool_id:int,created:bool,updated:bool,skipped:bool}
     */
    public function syncFromWlsRegistration(string $domain, string $ip, string $status = ''): array
    {
        return $this->upsertManagedLocalPoolEntry($domain, $ip, true);
    }

    /**
     * WLS 启用域名时：仅当池内不存在对应记录时才写入。
     *
     * @return array{pool_id:int,created:bool,updated:bool,skipped:bool}
     */
    public function ensurePoolEntryIfMissing(string $domain, string $ip = '127.0.0.1', string $source = 'wls'): array
    {
        $domain = LocalDomainPolicy::normalizeDomain($domain);
        if ($domain === '' || $this->shouldSkipPoolDomain($domain)) {
            return ['pool_id' => 0, 'created' => false, 'updated' => false, 'skipped' => true];
        }

        $pool = clone $this->domainPool;
        $pool->clearData()->loadByDomain($domain);
        if ($pool->getPoolId() > 0) {
            return [
                'pool_id' => $pool->getPoolId(),
                'created' => false,
                'updated' => false,
                'skipped' => false,
            ];
        }

        if (LocalDomainPolicy::isManagedLocalDomain($domain)) {
            return $this->upsertManagedLocalPoolEntry($domain, $ip, false);
        }

        return $this->createBasicPoolEntry($domain, $source);
    }

    public function applyCertificate(
        string $domain,
        int $certId,
        ?string $expiresAt,
        string $certType = 'exact',
    ): void {
        if ($certId <= 0) {
            return;
        }

        $domain = LocalDomainPolicy::normalizeDomain($domain);
        if ($domain === '' || $this->shouldSkipPoolDomain($domain)) {
            return;
        }

        if ($certType === 'wildcard') {
            $this->applyWildcardCertificate($domain, $certId, $expiresAt);
            return;
        }

        $this->applyExactCertificate($domain, $certId, $expiresAt);
    }

    /**
     * @return array{pool_id:int,created:bool,updated:bool,skipped:bool}
     */
    private function upsertManagedLocalPoolEntry(string $domain, string $ip, bool $forceUpdate): array
    {
        $domain = LocalDomainPolicy::normalizeDomain($domain);
        $ip = \trim($ip);
        if ($domain === '' || $ip === '' || !LocalDomainPolicy::isManagedLocalDomain($domain)) {
            return ['pool_id' => 0, 'created' => false, 'updated' => false, 'skipped' => true];
        }

        $pool = clone $this->domainPool;
        $pool->clearData()->loadByDomain($domain);
        $created = $pool->getPoolId() <= 0;
        if ($created) {
            $pool->clearData();
            $pool->setDomain($domain);
            $pool->setDescription((string)__('WLS 本地域名注册同步'));
            $pool->setStatus(DomainPool::STATUS_ACTIVE);
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_NONE);
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_ORIGIN_READY);
            $resolvedRoot = LocalDomainPolicy::resolveRootDomain($domain);
            if (\is_string($resolvedRoot) && $resolvedRoot !== '') {
                $pool->setData(DomainPool::schema_fields_ROOT_DOMAIN, $resolvedRoot);
            }
        } elseif (!$forceUpdate) {
            return [
                'pool_id' => $pool->getPoolId(),
                'created' => false,
                'updated' => false,
                'skipped' => false,
            ];
        }

        $pool->setResolveStatus(DomainPool::RESOLVE_STATUS_RESOLVED);
        $pool->setDnsStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setCdnStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setResolvedIp($ip);
        $pool->setIsLocalServer(true);
        $pool->setResolveCheckedAt(\date('Y-m-d H:i:s'));
        $pool->setResolveError('');
        $pool->setConnectivityStatus(DomainPool::CONNECTIVITY_OK);
        $pool->setConnectivityCheckedAt(\date('Y-m-d H:i:s'));
        $pool->calculateSiteReady();
        $pool->save();

        $poolId = $pool->getPoolId();
        if ($poolId <= 0) {
            throw new \RuntimeException((string)__('域名池保存后无法取得有效记录。'));
        }

        return [
            'pool_id' => $poolId,
            'created' => $created,
            'updated' => !$created,
            'skipped' => false,
        ];
    }

    /**
     * @return array{pool_id:int,created:bool,updated:bool,skipped:bool}
     */
    private function createBasicPoolEntry(string $domain, string $source): array
    {
        $pool = clone $this->domainPool;
        $pool->clearData();
        $pool->setDomain($domain);
        $pool->setDescription((string)__(
            'WLS 域名使用同步（%{1}）',
            [$source !== '' ? $source : 'wls']
        ));
        $pool->setStatus(DomainPool::STATUS_ACTIVE);
        $pool->setResolveStatus(DomainPool::RESOLVE_STATUS_PENDING);
        $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_NONE);
        $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_REGISTERED);
        $pool->calculateSiteReady();
        $pool->save();

        $poolId = $pool->getPoolId();
        if ($poolId <= 0) {
            throw new \RuntimeException((string)__('域名池保存后无法取得有效记录。'));
        }

        return [
            'pool_id' => $poolId,
            'created' => true,
            'updated' => false,
            'skipped' => false,
        ];
    }

    private function applyExactCertificate(string $domain, int $certId, ?string $expiresAt): void
    {
        $this->ensurePoolEntryIfMissing($domain);
        $pool = clone $this->domainPool;
        $pool->clearData()->loadByDomain($domain);
        if ($pool->getPoolId() <= 0) {
            return;
        }

        $pool->setCertId($certId);
        $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
        $pool->setHttpsExpiresAt($expiresAt);
        $pool->setHttpsError('');
        if (\trim((string)$pool->getPoolLifecycleStage()) !== DomainPool::LIFECYCLE_SITE_LIVE) {
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID);
        }
        $pool->calculateSiteReady();
        $pool->save();
    }

    private function applyWildcardCertificate(string $wildcardDomain, int $certId, ?string $expiresAt): void
    {
        $rootDomain = \ltrim($wildcardDomain, '*.');
        if ($rootDomain === '') {
            return;
        }

        $poolModel = clone $this->domainPool;
        $pools = $poolModel->clearQuery()->select()->fetchArray();

        foreach ($pools as $poolRow) {
            $poolDomain = (string)($poolRow[DomainPool::schema_fields_DOMAIN] ?? '');
            if (!$this->domainCoveredByWildcard($poolDomain, $rootDomain)) {
                continue;
            }

            $pool = clone $this->domainPool;
            $pool->setData($poolRow);
            $pool->setCertId($certId);
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
            $pool->setHttpsExpiresAt($expiresAt);
            $pool->setHttpsError('');
            if (\trim((string)$pool->getPoolLifecycleStage()) !== DomainPool::LIFECYCLE_SITE_LIVE) {
                $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID);
            }
            $pool->calculateSiteReady();
            $pool->save();
        }

        /** @var WebsiteDomain $domainModel */
        $domainModel = \Weline\Framework\Manager\ObjectManager::getInstance(WebsiteDomain::class);
        foreach ($domainModel->getDomainsByRoot($rootDomain) as $subdomain) {
            if (empty($subdomain[WebsiteDomain::schema_fields_CERT_ID])) {
                $domainModel->syncDomainCertificate(
                    (string)($subdomain[WebsiteDomain::schema_fields_DOMAIN] ?? ''),
                    $certId,
                    true,
                );
            }
        }
    }

    private function domainCoveredByWildcard(string $domain, string $rootDomain): bool
    {
        $domain = LocalDomainPolicy::normalizeDomain($domain);
        $rootDomain = LocalDomainPolicy::normalizeDomain($rootDomain);
        if ($domain === '' || $rootDomain === '') {
            return false;
        }
        if ($domain === $rootDomain || \str_ends_with($domain, '.' . $rootDomain)) {
            return true;
        }

        $resolvedRoot = LocalDomainPolicy::resolveRootDomain($domain);
        return $resolvedRoot === $rootDomain;
    }

    private function shouldSkipPoolDomain(string $domain): bool
    {
        return \in_array($domain, ['127.0.0.1', '0.0.0.0', 'localhost'], true);
    }
}
