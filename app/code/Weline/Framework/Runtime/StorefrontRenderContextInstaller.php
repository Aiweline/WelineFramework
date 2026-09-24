<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;

/**
 * Installs {@see StorefrontRenderContext} once per request after ScopeIdentity freeze.
 *
 * Idempotent: bag present → no-op. Never leaves a half-installed bag (single atomic set).
 * Soft-resolves Websites / Theme / maintenance when those modules are present.
 */
final class StorefrontRenderContextInstaller
{
    private const PENDING_KEY = 'storefront.render_context.install_pending.v1';

    public function installOnce(): StorefrontRenderContext
    {
        $existing = StorefrontRenderContext::current();
        if ($existing instanceof StorefrontRenderContext) {
            return $existing;
        }

        if (!Context::hasCurrent()) {
            return $this->incomplete('', '', 'storefront_render_no_request_context');
        }

        // Re-entrancy / concurrent Fiber install → refuse silent half-bag.
        if (RequestContext::has(self::PENDING_KEY)) {
            return $this->incomplete(
                (string)RequestContext::getWelineWebsiteCode(),
                '',
                'storefront_render_install_reentrant',
            );
        }

        RequestContext::set(self::PENDING_KEY, true);
        try {
            $built = $this->buildSnapshot();
            StorefrontRenderContext::install($built);
            return $built;
        } catch (\Throwable $e) {
            $failed = $this->incomplete(
                trim(RequestContext::getWelineWebsiteCode()),
                trim(RequestContext::getWelineWebsiteUrl()),
                'storefront_render_install_failed',
            );
            StorefrontRenderContext::install($failed);
            return $failed;
        } finally {
            RequestContext::remove(self::PENDING_KEY);
        }
    }

    /**
     * Contract alias — same as {@see installOnce()} (freeze after ScopeIdentity).
     */
    public function freezeCurrent(): StorefrontRenderContext
    {
        return $this->installOnce();
    }

    private function buildSnapshot(): StorefrontRenderContext
    {
        $identity = RequestContext::scopeIdentity();
        $websiteId = $identity?->websiteId;
        if ($websiteId === null) {
            $websiteId = RequestContext::getWelineWebsiteId();
        }
        $websiteCode = trim((string)($identity?->websiteCode ?? ''));
        if ($websiteCode === '') {
            $websiteCode = trim(RequestContext::getWelineWebsiteCode());
        }
        $websiteUrl = trim(RequestContext::getWelineWebsiteUrl());
        $locale = trim(RequestContext::getWelineUserLang()) ?: 'zh_Hans_CN';
        $currency = trim(RequestContext::getWelineUserCurrency()) ?: 'CNY';
        $timezone = trim(RequestContext::getWelineTimezone()) ?: \date_default_timezone_get();

        $hasWebsite = $websiteCode !== ''
            && ($websiteId > 0 || $websiteCode === 'default' || $websiteId === 0);

        if (!$hasWebsite) {
            return new StorefrontRenderContext(
                (int)$websiteId,
                $websiteCode,
                $websiteUrl,
                null,
                $locale,
                $currency,
                $timezone,
                ['active' => [], 'installed' => []],
                null,
                null,
                null,
                false,
                'storefront_render_scope_incomplete',
            );
        }

        $websiteLocal = $this->projectWebsiteLocal((int)$websiteId);
        $localeCatalog = $this->resolveLocaleCatalog();
        $maintenance = $this->resolveMaintenance($identity);
        $themeMeta = $this->resolveThemeMeta();
        $websiteTableSnapshot = $this->resolveWebsiteTableSnapshot((int)$websiteId, $websiteCode);

        return new StorefrontRenderContext(
            (int)$websiteId,
            $websiteCode,
            $websiteUrl,
            $websiteLocal,
            $locale,
            $currency,
            $timezone,
            $localeCatalog,
            $maintenance,
            $themeMeta,
            $websiteTableSnapshot,
            true,
            '',
        );
    }

    /**
     * @return list<array{local_code?:string,name?:string,description?:string}>|null
     */
    private function projectWebsiteLocal(int $websiteId): ?array
    {
        if ($websiteId < 0 || !Context::hasCurrent()) {
            return null;
        }
        $bag = RequestContext::get(StorefrontRenderContext::WEBSITE_LOCAL_ROWS_BAG_KEY);
        if (!\is_array($bag)) {
            return null;
        }
        $idKey = (string)$websiteId;
        if (!\array_key_exists($idKey, $bag) || !\is_array($bag[$idKey])) {
            return null;
        }

        /** @var list<array{local_code?:string,name?:string,description?:string}> $rows */
        $rows = \array_values($bag[$idKey]);

        return $rows;
    }

    /** @return array{active:list<string>,installed:list<string>} */
    private function resolveLocaleCatalog(): array
    {
        $active = [];
        $installed = [];
        try {
            if (\class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                $codes = \Weline\Websites\Data\WebsiteData::getLanguageCodes();
                if (\is_array($codes)) {
                    $active = \array_values(\array_map('strval', $codes));
                }
            }
        } catch (\Throwable) {
            $active = [];
        }

        // Installed catalog: prefer I18n switcher catalog when available; else mirror active.
        try {
            if (\class_exists(\Weline\I18n\Taglib\LanguageSwitcher::class)
                && \method_exists(\Weline\I18n\Taglib\LanguageSwitcher::class, 'installedLocaleCodes')
            ) {
                $installed = \array_values(\array_map(
                    'strval',
                    (array)\Weline\I18n\Taglib\LanguageSwitcher::installedLocaleCodes()
                ));
            }
        } catch (\Throwable) {
            $installed = [];
        }
        if ($installed === []) {
            $installed = $active;
        }

        return ['active' => $active, 'installed' => $installed];
    }

    /**
     * Request-memo maintenance snapshot (cross-request cache deferred by contract).
     *
     * @return array{scope_key?:string,enabled?:bool,reason?:string,generation?:int,since?:int}|null
     */
    private function resolveMaintenance(?ScopeIdentity $identity): ?array
    {
        if (!$identity instanceof ScopeIdentity) {
            return null;
        }
        if (!\class_exists(\Weline\Websites\Service\ScopeMaintenanceGate::class)) {
            return null;
        }
        try {
            /** @var StorefrontScopeHotCache $hot */
            $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);
            /** @var \Weline\Websites\Service\ScopeMaintenanceGate $gate */
            $gate = ObjectManager::getInstance(\Weline\Websites\Service\ScopeMaintenanceGate::class);
            $status = $hot->rememberForRequest(
                'scope_maintenance',
                $identity->canonicalKey(),
                static fn (): array => $gate->status($identity),
            );
            return \is_array($status) ? $status : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * theme_meta stays null at install; Theme consumers merge via
     * {@see StorefrontRenderContextReader::mergeFields()} after type-scoped getMetaList.
     *
     * @return array<string,mixed>|null
     */
    private function resolveThemeMeta(): ?array
    {
        return null;
    }

    /** @return array<string,mixed>|null */
    private function resolveWebsiteTableSnapshot(int $websiteId, string $websiteCode): ?array
    {
        try {
            if (!\class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                return null;
            }
            $website = \Weline\Websites\Data\WebsiteData::getWebsite();
            if ($website === null) {
                return null;
            }
            $data = \method_exists($website, 'getData') ? (array)$website->getData() : null;
            if (!\is_array($data) || $data === []) {
                return null;
            }

            return [
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
                'row' => $data,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function incomplete(string $websiteCode, string $websiteUrl, string $failureCode): StorefrontRenderContext
    {
        $locale = 'zh_Hans_CN';
        $currency = 'CNY';
        $timezone = \date_default_timezone_get();
        $websiteId = 0;
        if (Context::hasCurrent()) {
            try {
                $locale = trim(RequestContext::getWelineUserLang()) ?: $locale;
                $currency = trim(RequestContext::getWelineUserCurrency()) ?: $currency;
                $timezone = trim(RequestContext::getWelineTimezone()) ?: $timezone;
                $websiteId = RequestContext::getWelineWebsiteId();
                if ($websiteCode === '') {
                    $websiteCode = trim(RequestContext::getWelineWebsiteCode());
                }
                if ($websiteUrl === '') {
                    $websiteUrl = trim(RequestContext::getWelineWebsiteUrl());
                }
            } catch (\Throwable) {
                // keep defaults
            }
        }

        return new StorefrontRenderContext(
            $websiteId,
            $websiteCode,
            $websiteUrl,
            null,
            $locale,
            $currency,
            $timezone,
            ['active' => [], 'installed' => []],
            null,
            null,
            null,
            false,
            $failureCode,
        );
    }
}
