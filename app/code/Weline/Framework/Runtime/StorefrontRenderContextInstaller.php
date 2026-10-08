<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\App\Localization\LocalizationProviderRegistry;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;

/**
 * Installs {@see StorefrontRenderContext} once per request after ScopeIdentity freeze.
 *
 * Idempotent: bag present → no-op. Never leaves a half-installed bag (single atomic set).
 * Skeleton only from RequestContext / ScopeIdentity + LocalizationProviderRegistry.
 * Owning modules fill maintenance / website_table_snapshot / theme_meta via
 * {@see StorefrontRenderContextReader::mergeFields()}.
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

        return new StorefrontRenderContext(
            (int)$websiteId,
            $websiteCode,
            $websiteUrl,
            $websiteLocal,
            $locale,
            $currency,
            $timezone,
            $localeCatalog,
            null,
            null,
            null,
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
            /** @var LocalizationProviderRegistry $registry */
            $registry = ObjectManager::getInstance(LocalizationProviderRegistry::class);
            $codes = $registry->preferredLanguageCodes();
            if (\is_array($codes)) {
                $active = \array_values(\array_map('strval', $codes));
            }
            $installedCodes = $registry->preferredInstalledLanguageCodes();
            if (\is_array($installedCodes)) {
                $installed = \array_values(\array_map('strval', $installedCodes));
            }
        } catch (\Throwable) {
            $active = [];
            $installed = [];
        }
        if ($installed === []) {
            $installed = $active;
        }

        return ['active' => $active, 'installed' => $installed];
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
