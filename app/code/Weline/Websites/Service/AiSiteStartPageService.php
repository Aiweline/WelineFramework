<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\Websites\Api\DomainStartPageConfig;
use Weline\Websites\Exception\AiSiteProvisioningException;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

/**
 * Websites-owned root-entry writer for an already verified AI-site binding.
 */
class AiSiteStartPageService
{
    private const CONFIG_MODULE = 'Weline_Websites';

    public function __construct(
        private readonly Website $websiteModel,
        private readonly WebsiteDomain $websiteDomainModel,
        private readonly ConfigStore $configStore,
        private readonly CacheManager $cacheManager,
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    /**
     * @return array{website_id:int,target_domain:string,page_id:int,start_page_path:string,cache_broadcast:array<string,mixed>}
     */
    public function configure(int $websiteId, string $targetDomain, int $pageId): array
    {
        $targetDomain = \strtolower(\trim($targetDomain));
        if ($websiteId < Website::ID_DEFAULT || $targetDomain === '' || $pageId <= 0) {
            throw new AiSiteProvisioningException(
                'START_PAGE_REQUIRED',
                (string)__('站点、域名或首页页面无效。')
            );
        }

        $binding = clone $this->websiteDomainModel;
        $binding->clearData()->clearQuery()->loadByDomainAndSubPath($targetDomain, '');
        if ((int)$binding->getId() <= 0
            || (int)$binding->getData(WebsiteDomain::schema_fields_WEBSITE_ID) !== $websiteId
        ) {
            throw new AiSiteProvisioningException(
                'DOMAIN_BINDING_NOT_READY',
                (string)__('目标域名尚未绑定到当前站点。')
            );
        }

        $website = clone $this->websiteModel;
        $website->clearData()
            ->clearQuery()
            ->where(Website::schema_fields_ID, $websiteId)
            ->find()
            ->fetch();
        $websiteCode = \trim((string)$website->getData(Website::schema_fields_CODE));
        if ($websiteCode === '') {
            throw new AiSiteProvisioningException(
                'WEBSITE_NOT_FOUND',
                (string)__('找不到域名所属站点。')
            );
        }

        $startPagePath = 'pagebuilder/frontend/page/view?page_id=' . $pageId;
        $domainConfigKey = DomainStartPageConfig::key($targetDomain);
        if ($domainConfigKey === '') {
            throw new AiSiteProvisioningException(
                'START_PAGE_REQUIRED',
                (string)__('目标域名无法建立独立首页配置。')
            );
        }
        $saved = $this->configStore->setScopedConfig(
            key: $domainConfigKey,
            value: $startPagePath,
            module: self::CONFIG_MODULE,
            area: ConfigStore::area_FRONTEND,
            // Root-route resolution runs before the normal website-detection
            // observer has populated RequestContext. The key already contains
            // a collision-safe hash of the exact domain, so it must be readable
            // from the global scope during that early routing phase.
            scope: ConfigStore::SCOPE_GLOBAL,
            locale: ConfigStore::LOCALE_DEFAULT,
            options: ['operation' => 'pb_ai_start_page_publish']
        );
        if (!$saved) {
            throw new AiSiteProvisioningException(
                'START_PAGE_CONFIG_FAILED',
                (string)__('保存站点首页入口失败。')
            );
        }

        // Also mirror the website-scoped key used by admin forms / StarPage so
        // both the domain-hash reader and the website-code reader stay aligned.
        // SystemConfig rejects short raw scopes; pass ScopeIdentity so storage
        // becomes "{websiteCode}.default.default".
        $websiteScopedSaved = $this->configStore->setScopedConfig(
            key: 'frontend_start_page_path',
            value: $startPagePath,
            module: self::CONFIG_MODULE,
            area: ConfigStore::area_FRONTEND,
            scope: ConfigStore::SCOPE_GLOBAL,
            locale: ConfigStore::LOCALE_DEFAULT,
            options: [
                'operation' => 'pb_ai_start_page_site',
                'scope_identity' => ScopeIdentity::website($websiteId, $websiteCode),
            ]
        );
        if (!$websiteScopedSaved) {
            throw new AiSiteProvisioningException(
                'START_PAGE_CONFIG_FAILED',
                (string)__('保存站点 scope 首页入口失败。')
            );
        }

        $cacheBroadcast = $this->invalidateFrontendCaches();

        return [
            'website_id' => $websiteId,
            'target_domain' => $targetDomain,
            'page_id' => $pageId,
            'start_page_path' => $startPagePath,
            'cache_broadcast' => $cacheBroadcast,
        ];
    }

    /** @return array<string,mixed> */
    private function invalidateFrontendCaches(): array
    {
        foreach (['fpc', 'router'] as $pool) {
            if ($this->cacheManager->hasPool($pool)) {
                $this->cacheManager->pool($pool)->clear();
            }
        }
        FullPageCacheCoordinator::clearProcessCache();

        try {
            $sharedState = $this->runtimeProviders->resolve(SharedCacheStateInterface::class);
        } catch (\Throwable) {
            $sharedState = null;
        }
        if ($sharedState instanceof SharedCacheStateInterface) {
            $sharedState->clearCache('router');
            $sharedState->clearCache('fpc');
        }

        try {
            $broadcaster = $this->runtimeProviders->resolve(RuntimeControlBroadcasterInterface::class);
        } catch (\Throwable) {
            $broadcaster = null;
        }
        if (!$broadcaster instanceof RuntimeControlBroadcasterInterface) {
            return ['success' => true, 'attempted' => []];
        }

        // Best-effort worker L1 reset. Local/shared router+FPC clears above are
        // the durable path. Waiting on every READY worker used to fail an already
        // saved start_page (and the whole publish queue) when control-plane IPC
        // was congested — looking like "only the second publish works".
        if (\method_exists($broadcaster, 'cacheClearAndWait')) {
            $result = $broadcaster->cacheClearAndWait(null, 12.0);
            $result['soft_failure'] = (($result['success'] ?? false) !== true)
                || (($result['completed'] ?? false) !== true);

            return $result;
        }

        $result = $broadcaster->cacheClear();
        $result['soft_failure'] = \array_key_exists('success', $result)
            && $result['success'] !== true;

        return $result;
    }
}
