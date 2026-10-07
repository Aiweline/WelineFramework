<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\Websites\Model\DomainPool;

/**
 * Ensures hosts + local wildcard certificate for managed local WLS domains
 * after admin/manual DomainPool creation.
 */
final class ManagedLocalDomainProvisionService
{
    /**
     * @param null|\Closure():array<string,mixed> $edgeSync
     */
    public function __construct(
        private readonly ?LocalWelineHostsSyncService $hostsSyncService = null,
        private readonly ?LocalWelineWildcardCertificateService $certificateService = null,
        private readonly ?DomainPool $domainPool = null,
        private readonly ?\Closure $edgeSync = null,
    ) {
    }

    public function isManagedLocalDomain(string $domain): bool
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '' || $domain === 'localhost') {
            return $domain === 'localhost';
        }

        return \class_exists(LocalDomainPolicy::class)
            && LocalDomainPolicy::isManagedLocalDomain($domain);
    }

    /**
     * @return array{
     *     success:bool,
     *     domain:string,
     *     local_ready:bool,
     *     authorization_pending?:bool,
     *     authorization_already_started?:bool,
     *     skipped?:bool,
     *     hosts?:array<string,mixed>,
     *     certificate?:array<string,mixed>,
     *     message?:string
     * }
     */
    public function ensureForManualCreate(string $domain, int $websiteId = 0): array
    {
        $domain = \strtolower(\trim($domain));
        if (!$this->isManagedLocalDomain($domain)) {
            return [
                'success' => false,
                'domain' => $domain,
                'local_ready' => false,
                'skipped' => true,
                'message' => (string)__('Not a managed local WLS domain'),
            ];
        }

        $this->markPoolLocalResolved($domain);

        $hosts = $this->hosts()->ensureHostsInjected($domain);
        $hostsNeedsAdmin = ($hosts['authorization_pending'] ?? false) === true
            || ($hosts['needs_admin'] ?? false) === true;
        if ($hostsNeedsAdmin) {
            return [
                'success' => false,
                'domain' => $domain,
                'local_ready' => false,
                'authorization_pending' => true,
                'authorization_already_started' => ($hosts['authorization_already_started'] ?? false) === true,
                'hosts' => $hosts,
                'message' => \trim((string)($hosts['message'] ?? ''))
                    ?: (string)__('正在等待 macOS 管理员批准本地域名 hosts 配置。'),
            ];
        }

        $hostsOk = ($hosts['success'] ?? false) === true
            || (($hosts['skipped'] ?? false) === true && ($hosts['success'] ?? false) !== false);

        $certificate = $this->certificates()->ensureWildcardCertificateForDomain($domain, \max(0, $websiteId));
        $this->syncCertificateStatus($domain, $certificate);

        $certOk = ($certificate['success'] ?? false) === true;
        $edge = $certOk ? $this->syncManagedNginxEdge() : [
            'ok' => false,
            'skipped' => true,
            'message' => (string)__('通配证书未就绪，跳过托管 Nginx 本机域同步'),
        ];
        $edgeOk = ($edge['ok'] ?? false) === true
            || ($edge['skipped'] ?? false) === true;
        $localReady = $hostsOk && $certOk && $edgeOk;

        return [
            'success' => $localReady,
            'domain' => $domain,
            'local_ready' => $localReady,
            'hosts' => $hosts,
            'certificate' => $certificate,
            'edge' => $edge,
            'message' => $localReady
                ? (string)__('本地域名 hosts、通配证书与托管 Nginx 边缘已就绪')
                : (\trim((string)($edge['message'] ?? $certificate['message'] ?? $hosts['message'] ?? ''))
                    ?: (string)__('本地域名就绪未完成')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function syncManagedNginxEdge(): array
    {
        if ($this->edgeSync instanceof \Closure) {
            $result = ($this->edgeSync)();
            return \is_array($result) ? $result : [
                'ok' => false,
                'message' => (string)__('托管 Nginx 本机域同步返回无效结果'),
            ];
        }
        if (!\class_exists(\Weline\Server\Service\Edge\Nginx\ManagedNginxService::class)) {
            return [
                'ok' => true,
                'skipped' => true,
                'message' => (string)__('Weline_Server 未安装，跳过托管 Nginx 同步'),
            ];
        }
        try {
            $service = \Weline\Server\Service\Edge\Nginx\ManagedNginxService::fromEnv();
            if (!$service->isEdgeNginxManaged()) {
                return [
                    'ok' => true,
                    'skipped' => true,
                    'message' => (string)__('当前未启用托管 Nginx 边缘，跳过 server_names 同步'),
                ];
            }
            $result = $service->syncLocalManagedHosts();
            if (!\is_array($result)) {
                return [
                    'ok' => false,
                    'message' => (string)__('托管 Nginx 本机域同步返回无效结果'),
                ];
            }

            return $result + ['skipped' => false];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => (string)__('托管 Nginx 本机域同步失败：%{1}', [$e->getMessage()]),
            ];
        }
    }

    private function markPoolLocalResolved(string $domain): void
    {
        $pool = $this->loadPool($domain);
        if ($pool === null) {
            return;
        }

        $pool->setStatus(DomainPool::STATUS_ACTIVE);
        $pool->setResolveStatus(DomainPool::RESOLVE_STATUS_RESOLVED);
        $pool->setDnsStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setCdnStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setIsLocalServer(true);
        $pool->setResolveCheckedAt(\date('Y-m-d H:i:s'));
        $pool->setResolveError('');
        $pool->setResolvedIp('127.0.0.1');

        $httpsStatus = (string)$pool->getHttpsStatus();
        if ($httpsStatus === DomainPool::HTTPS_STATUS_VALID) {
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID);
            $pool->calculateSiteReady();
        } else {
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_PENDING);
            $pool->setSiteReady(false);
        }
        $pool->save();
    }

    /**
     * @param array<string, mixed> $certificateResult
     */
    private function syncCertificateStatus(string $domain, array $certificateResult): void
    {
        $pool = $this->loadPool($domain);
        if ($pool === null) {
            return;
        }

        $ok = !empty($certificateResult['success']);
        $message = \trim((string)($certificateResult['message'] ?? ''));
        $certId = (int)($certificateResult['cert_id']
            ?? $certificateResult['certificate']['cert_id']
            ?? 0);

        $pool->setStatus(DomainPool::STATUS_ACTIVE);
        $pool->setResolveStatus(DomainPool::RESOLVE_STATUS_RESOLVED);
        $pool->setDnsStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setCdnStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setIsLocalServer(true);
        $pool->setResolveCheckedAt(\date('Y-m-d H:i:s'));
        $pool->setResolveError('');
        $pool->setResolvedIp('127.0.0.1');

        if ($ok) {
            if ($certId > 0) {
                $pool->setCertId($certId);
            }
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
            $pool->setHttpsError('');
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID);
            $pool->calculateSiteReady();
        } else {
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
            $pool->setHttpsError($message);
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_PENDING);
            $pool->setSiteReady(false);
        }
        $pool->save();
    }

    private function loadPool(string $domain): ?DomainPool
    {
        try {
            if ($this->domainPool instanceof DomainPool) {
                // Injected test double / explicit instance — do not clone.
                $pool = $this->domainPool;
            } else {
                $pool = clone \Weline\Framework\Manager\ObjectManager::getInstance(DomainPool::class);
            }
        } catch (\Throwable) {
            return null;
        }
        if (!$pool instanceof DomainPool) {
            return null;
        }
        $pool->loadByDomain($domain);

        return $pool->getPoolId() > 0 ? $pool : null;
    }

    private function hosts(): LocalWelineHostsSyncService
    {
        return $this->hostsSyncService
            ?? \Weline\Framework\Manager\ObjectManager::getInstance(LocalWelineHostsSyncService::class);
    }

    private function certificates(): LocalWelineWildcardCertificateService
    {
        return $this->certificateService
            ?? \Weline\Framework\Manager\ObjectManager::getInstance(LocalWelineWildcardCertificateService::class);
    }
}
