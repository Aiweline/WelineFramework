<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Exception\AiSiteProvisioningException;
use Weline\Websites\Model\AiSiteProvisioningRequest;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

/** Executes one Websites-owned local-domain preparation or paid domain purchase. */
final class AiSiteDomainPreparationService
{
    public function __construct(
        private readonly AiSiteDomainPurchaseAccountService $accountService,
        private readonly DomainPurchaseService $purchaseService,
        private readonly DefaultWebsiteService $defaultWebsiteService,
        private readonly DomainPool $domainPool,
        private readonly WebsiteDomain $websiteDomain,
        private readonly LocalWelineHostsSyncService $hostsSyncService,
        private readonly LocalWelineWildcardCertificateService $certificateService,
        private readonly AiSiteWebsiteTargetResolver $websiteTargetResolver,
        private readonly Website $website,
    ) {
    }

    /**
     * @param null|callable():void $beforeExternalPurchase
     * @return array{
     *     website_id:int,
     *     purchase_order_id:int,
     *     local_ready:bool,
     *     availability:array<string,mixed>,
     *     authorization_pending?:bool,
     *     authorization_already_started?:bool,
     *     message?:string
     * }
     */
    public function prepare(AiSiteProvisioningRequest $request, ?callable $beforeExternalPurchase = null): array
    {
        $mode = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE);
        $domain = \strtolower(\trim((string)$request->getData(AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN)));
        $subPath = $this->normalizeSubPath(
            (string)$request->getData(AiSiteProvisioningRequest::schema_fields_SUB_PATH)
        );
        if ($mode === AiSiteProvisioningRequest::DOMAIN_MODE_BIND) {
            return $this->prepareBindExisting($request, $domain, $subPath);
        }
        if ($mode === AiSiteProvisioningRequest::DOMAIN_MODE_TEST) {
            if (\preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.weline\.test$/D', $domain) !== 1) {
                throw new AiSiteProvisioningException('TEST_DOMAIN_REQUIRED', (string)__('测试模式必须使用单标签 *.weline.test 域名。'));
            }
            $this->defaultWebsiteService->ensureDefaultWebsite(false);
            $hosts = $this->hostsSyncService->ensureHostsInjected($domain);
            if (($hosts['success'] ?? false) !== true) {
                if (($hosts['authorization_pending'] ?? false) === true) {
                    return [
                        'website_id' => 0,
                        'purchase_order_id' => 0,
                        'local_ready' => false,
                        'availability' => [
                            'domain' => $domain,
                            'available' => true,
                            'simulated' => true,
                        ],
                        'authorization_pending' => true,
                        'authorization_already_started' =>
                            ($hosts['authorization_already_started'] ?? false) === true,
                        'message' => \trim((string)($hosts['message'] ?? ''))
                            ?: (string)__('正在等待 macOS 管理员批准本地域名 hosts 配置。'),
                    ];
                }
                throw new AiSiteProvisioningException(
                    'TEST_DOMAIN_HOSTS_FAILED',
                    \trim((string)($hosts['message'] ?? '')) ?: (string)__('本地测试域名 hosts 准备失败。')
                );
            }
            $certificate = $this->certificateService->ensureWildcardCertificateForDomain($domain, Website::ID_DEFAULT);
            if (($certificate['success'] ?? false) !== true) {
                throw new AiSiteProvisioningException(
                    'TEST_DOMAIN_CERTIFICATE_FAILED',
                    \trim((string)($certificate['message'] ?? '')) ?: (string)__('本地测试域名证书准备失败。')
                );
            }
            $poolId = $this->persistLocalPool($domain);
            $websiteId = $this->websiteTargetResolver->resolve($request, $domain, $subPath);
            $this->bindDomain($domain, $subPath, $websiteId, $poolId, true);

            return [
                'website_id' => $websiteId,
                'purchase_order_id' => 0,
                'local_ready' => true,
                'availability' => ['domain' => $domain, 'available' => true, 'simulated' => true],
            ];
        }
        if ($mode !== AiSiteProvisioningRequest::DOMAIN_MODE_PURCHASE) {
            throw new AiSiteProvisioningException('DOMAIN_MODE_UNSUPPORTED', (string)__('不支持的域名准备模式。'));
        }
        if ((int)$request->getData(AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED) !== 1) {
            throw new AiSiteProvisioningException('PURCHASE_CONFIRMATION_REQUIRED', (string)__('正式域名购买需要明确确认。'));
        }
        if ((int)$request->getData(AiSiteProvisioningRequest::schema_fields_PURCHASE_ATTEMPTED) === 1
            && (int)$request->getData(AiSiteProvisioningRequest::schema_fields_PURCHASE_ORDER_ID) <= 0
        ) {
            throw new AiSiteProvisioningException(
                'DOMAIN_PURCHASE_RESULT_UNCERTAIN',
                (string)__('上次购买结果尚未确认，为避免重复扣费，必须先在域名管理中核对。')
            );
        }
        $accountId = (int)$request->getData(AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID);
        $availability = $this->accountService->checkAvailability($accountId, $domain);
        if (($availability['domain'] ?? '') !== $domain || ($availability['available'] ?? false) !== true) {
            throw new AiSiteProvisioningException('DOMAIN_UNAVAILABLE', (string)__('目标域名当前不可购买。'));
        }
        if ($beforeExternalPurchase !== null) {
            $beforeExternalPurchase();
        }
        $result = $this->purchaseService->createAndProcessOrder($accountId, [[
            'domain' => $domain,
            'years' => \max(1, \min(10, (int)$request->getData(AiSiteProvisioningRequest::schema_fields_YEARS))),
            'website_id' => Website::ID_DEFAULT,
            'auto_create_site' => 'no',
            'resolve_to_local' => 'yes',
            'subdomains' => ['@', 'www'],
            'start_lifecycle' => '1',
        ]], true);
        $orderId = (int)($result['order_id'] ?? 0);
        $exactSuccess = false;
        foreach (\is_array($result['results'] ?? null) ? $result['results'] : [] as $item) {
            if (\is_array($item)
                && \strtolower(\trim((string)($item['domain'] ?? ''))) === $domain
                && ($item['success'] ?? false) === true
            ) {
                $exactSuccess = true;
                break;
            }
        }
        if (($result['success'] ?? false) !== true || $orderId <= 0 || !$exactSuccess) {
            throw new AiSiteProvisioningException(
                'DOMAIN_PURCHASE_FAILED',
                \trim((string)($result['message'] ?? '')) ?: (string)__('域名购买失败。')
            );
        }
        $this->defaultWebsiteService->ensureDefaultWebsite(false);
        $websiteId = $this->websiteTargetResolver->resolve($request, $domain, $subPath);
        $this->bindDomain($domain, $subPath, $websiteId, 0, false);

        return [
            'website_id' => $websiteId,
            'purchase_order_id' => $orderId,
            'local_ready' => false,
            'availability' => $availability,
        ];
    }

    /**
     * Publish-bypass path for local *.weline.test domains: create/reuse the
     * Website and bind the domain without rewriting hosts or certificates.
     *
     * @return array{
     *     website_id:int,
     *     purchase_order_id:int,
     *     local_ready:bool,
     *     availability:array<string,mixed>
     * }
     */
    public function prepareIgnoringLocalHosts(AiSiteProvisioningRequest $request): array
    {
        $mode = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE);
        $domain = \strtolower(\trim((string)$request->getData(AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN)));
        $subPath = $this->normalizeSubPath(
            (string)$request->getData(AiSiteProvisioningRequest::schema_fields_SUB_PATH)
        );
        if ($mode !== AiSiteProvisioningRequest::DOMAIN_MODE_TEST) {
            throw new AiSiteProvisioningException(
                'DOMAIN_MODE_UNSUPPORTED',
                (string)__('跳过本机 hosts 的强制绑定仅支持测试域名模式。')
            );
        }
        if (\preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.weline\.test$/D', $domain) !== 1) {
            throw new AiSiteProvisioningException('TEST_DOMAIN_REQUIRED', (string)__('测试模式必须使用单标签 *.weline.test 域名。'));
        }
        $this->defaultWebsiteService->ensureDefaultWebsite(false);
        $poolId = $this->persistLocalPool($domain);
        $websiteId = $this->websiteTargetResolver->resolve($request, $domain, $subPath);
        $this->bindDomain($domain, $subPath, $websiteId, $poolId, true);

        // Publish still needs loopback resolution. Hosts is best-effort here so a
        // missing admin approval cannot undo an already-bound Website.
        $hosts = $this->hostsSyncService->ensureHostsInjected($domain);
        $localReady = ($hosts['success'] ?? false) === true
            && empty($hosts['skipped']);

        return [
            'website_id' => $websiteId,
            'purchase_order_id' => 0,
            'local_ready' => $localReady,
            'hosts' => $hosts,
            'availability' => ['domain' => $domain, 'available' => true, 'simulated' => true],
        ];
    }

    public function isBound(string $domain, int $websiteId, string $subPath = ''): bool
    {
        $domain = \strtolower(\trim($domain));
        $subPath = $this->normalizeSubPath($subPath);
        if ($domain === '' || $websiteId < Website::ID_DEFAULT) {
            return false;
        }

        $binding = clone $this->websiteDomain;
        $binding->clearData()->loadByDomainAndSubPath($domain, $subPath);

        return $binding->getDomainId() > 0
            && $binding->getWebsiteId() === $websiteId
            && $binding->getStatus() === WebsiteDomain::STATUS_ACTIVE;
    }

    /**
     * Bind an already-owned pool domain (local or public) without purchase.
     *
     * @return array{
     *     website_id:int,
     *     purchase_order_id:int,
     *     local_ready:bool,
     *     availability:array<string,mixed>
     * }
     */
    private function prepareBindExisting(
        AiSiteProvisioningRequest $request,
        string $domain,
        string $subPath
    ): array {
        if ($domain === '') {
            throw new AiSiteProvisioningException('TARGET_DOMAIN_REQUIRED', (string)__('目标域名格式无效。'));
        }
        $conflict = $this->websiteDomain->findConflict(
            $domain,
            $subPath,
            $request->getRequestedWebsiteId()
        );
        if ($conflict !== null) {
            throw new AiSiteProvisioningException(
                'DOMAIN_PATH_CONFLICT',
                (string)__(
                    '目标地址 %{1} 已绑定到其他站点「%{2}」。',
                    [$domain . $subPath, (string)($conflict['website_name'] ?? $conflict['website_id'])]
                )
            );
        }

        $this->defaultWebsiteService->ensureDefaultWebsite(false);
        $pool = clone $this->domainPool;
        $pool->clearData()->loadByDomain($domain);
        $poolId = $pool->getPoolId() > 0 ? $pool->getPoolId() : 0;
        $isLocal = ($poolId > 0 && $pool->isLocalServer())
            || \preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.weline\.test$/D', $domain) === 1;

        if ($isLocal && $poolId <= 0) {
            $poolId = $this->persistLocalPool($domain);
        }

        $localReady = false;
        if ($isLocal) {
            $hosts = $this->hostsSyncService->ensureHostsInjected($domain);
            if (($hosts['success'] ?? false) !== true) {
                if (($hosts['authorization_pending'] ?? false) === true) {
                    return [
                        'website_id' => 0,
                        'purchase_order_id' => 0,
                        'local_ready' => false,
                        'availability' => [
                            'domain' => $domain,
                            'available' => true,
                            'simulated' => true,
                        ],
                        'authorization_pending' => true,
                        'authorization_already_started' =>
                            ($hosts['authorization_already_started'] ?? false) === true,
                        'message' => \trim((string)($hosts['message'] ?? ''))
                            ?: (string)__('正在等待 macOS 管理员批准本地域名 hosts 配置。'),
                    ];
                }
                throw new AiSiteProvisioningException(
                    'TEST_DOMAIN_HOSTS_FAILED',
                    \trim((string)($hosts['message'] ?? '')) ?: (string)__('本地测试域名 hosts 准备失败。')
                );
            }
            $certificate = $this->certificateService->ensureWildcardCertificateForDomain($domain, Website::ID_DEFAULT);
            if (($certificate['success'] ?? false) !== true) {
                throw new AiSiteProvisioningException(
                    'TEST_DOMAIN_CERTIFICATE_FAILED',
                    \trim((string)($certificate['message'] ?? '')) ?: (string)__('本地测试域名证书准备失败。')
                );
            }
            $localReady = true;
        }

        $websiteId = $this->websiteTargetResolver->resolve($request, $domain, $subPath);
        $this->bindDomain($domain, $subPath, $websiteId, $poolId, $isLocal);

        return [
            'website_id' => $websiteId,
            'purchase_order_id' => 0,
            'local_ready' => $localReady || !$isLocal,
            'availability' => [
                'domain' => $domain,
                'available' => true,
                'simulated' => $isLocal,
                'sub_path' => $subPath,
            ],
        ];
    }

    private function persistLocalPool(string $domain): int
    {
        $pool = clone $this->domainPool;
        $pool->clearData()->loadByDomain($domain);
        if ($pool->getPoolId() <= 0) {
            $pool->clearData();
        }
        $pool->setDomain($domain)
            ->setStatus(DomainPool::STATUS_ACTIVE)
            ->setResolveStatus(DomainPool::RESOLVE_STATUS_RESOLVED)
            ->setDnsStatus(DomainPool::INFRA_STATUS_READY)
            ->setCdnStatus(DomainPool::INFRA_STATUS_READY)
            ->setResolvedIp('127.0.0.1')
            ->setIsLocalServer(true)
            ->setResolveCheckedAt(\date('Y-m-d H:i:s'))
            ->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID)
            ->setSiteReady(true)
            ->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID)
            ->setConnectivityStatus(DomainPool::CONNECTIVITY_OK)
            ->setConnectivityCheckedAt(\date('Y-m-d H:i:s'))
            ->save();

        $poolId = $pool->getPoolId();
        if ($poolId <= 0) {
            throw new AiSiteProvisioningException(
                'WEBSITE_DOMAIN_BINDING_FAILED',
                (string)__('域名池保存后无法取得有效记录。')
            );
        }

        return $poolId;
    }

    private function bindDomain(
        string $domain,
        string $subPath,
        int $websiteId,
        int $poolId,
        bool $httpsEnabled
    ): void {
        $subPath = $this->normalizeSubPath($subPath);
        $conflict = $this->websiteDomain->findConflict($domain, $subPath, $websiteId);
        if ($conflict !== null) {
            throw new AiSiteProvisioningException(
                'DOMAIN_ALREADY_BOUND',
                (string)__(
                    '目标地址 %{1} 已绑定到其他站点「%{2}」，不能自动改绑。',
                    [$domain . $subPath, (string)($conflict['website_name'] ?? $conflict['website_id'])]
                )
            );
        }

        $binding = clone $this->websiteDomain;
        $binding->clearData()->loadByDomainAndSubPath($domain, $subPath);
        if ($binding->getDomainId() > 0
            && $binding->getWebsiteId() !== $websiteId
            && $binding->getWebsiteId() !== Website::ID_DEFAULT
        ) {
            throw new AiSiteProvisioningException(
                'DOMAIN_ALREADY_BOUND',
                (string)__('目标域名已绑定到其他站点，不能自动改绑。')
            );
        }
        if ($binding->getDomainId() <= 0) {
            $binding->clearData();
        }

        $binding->setWebsiteId($websiteId)
            ->setDomain($domain)
            ->setSubPath($subPath)
            ->setIsPrimary(false)
            ->setHttpsEnabled($httpsEnabled)
            ->setStatus(WebsiteDomain::STATUS_ACTIVE);
        if ($poolId > 0) {
            $binding->setPoolId($poolId);
        }
        $binding->save();

        if ($poolId > 0) {
            $this->markPoolCreated($poolId);
        }
        $this->syncWebsiteUrl($websiteId, $domain, $subPath);
        if (!$this->isBound($domain, $websiteId, $subPath)) {
            throw new AiSiteProvisioningException(
                'WEBSITE_DOMAIN_BINDING_FAILED',
                (string)__('域名记录保存后未能验证站点绑定。')
            );
        }
    }

    private function syncWebsiteUrl(int $websiteId, string $domain, string $subPath): void
    {
        if ($websiteId <= Website::ID_DEFAULT) {
            return;
        }
        $url = 'https://' . $domain . $subPath;
        if (\strlen($url) > 255) {
            throw new AiSiteProvisioningException(
                'TARGET_DOMAIN_INVALID',
                (string)__('目标域名或子路径过长。')
            );
        }
        $website = clone $this->website;
        $website->clearData()->clearQuery()->load($websiteId);
        if ($website->getWebsiteId() !== $websiteId) {
            return;
        }
        if (\strtolower(\trim((string)$website->getUrl())) === \strtolower($url)) {
            return;
        }
        $website->setUrl($url)->save();
    }

    private function normalizeSubPath(string $subPath): string
    {
        $subPath = \trim($subPath);
        if ($subPath === '' || $subPath === '/') {
            return '';
        }
        $subPath = '/' . \trim($subPath, '/');

        return $subPath === '/' ? '' : $subPath;
    }

    private function markPoolCreated(int $poolId): void
    {
        $pool = clone $this->domainPool;
        $pool->clearData()->loadByPoolId($poolId);
        if ($pool->getPoolId() !== $poolId) {
            throw new AiSiteProvisioningException(
                'WEBSITE_DOMAIN_BINDING_FAILED',
                (string)__('域名池状态与站点绑定不一致。')
            );
        }
        if (!$pool->isSiteCreated()) {
            $pool->setSiteCreated(true)->save();
        }
    }
}
