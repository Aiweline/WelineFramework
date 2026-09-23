<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Block\Partials;
use Weline\Theme\Helper\HeaderCommerceData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityChrome;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller;

/**
 * wave8-8c / 8c3 / P5-O1: Theme-owned compliant HotCache bag priming for deferred warmup.
 *
 * Seeds chrome.rendered (eager sync, disk-only + scope fallback), chrome slot projection,
 * header commerce bags, and frontend header Partials HTML.
 * Fail-open; never invents FPC HIT; never clears shared FPC; no cross-module Model.
 *
 * wls-perf-regression Option C + O1/P8-O2: when bag-prime runs with a live request Context but
 * ScopeIdentity was never frozen (FPC HIT / capture edge), install the default
 * storefront channel so HotCache keys match `default.__store__.__channel__` —
 * no fake HIT. Chrome miss → honest [] short-path (禁空烧重投影).
 * P8-O2: ensure Theme on Template before chrome seed so stage-gated durable bake
 * (loadOrRenderPublished on heavy/peer) can write chrome.rendered.{locale}.html.
 */
final class StorefrontHotCacheBagSeeder
{
    /**
     * @return array{
     *   seeded:int,
     *   peeked:int,
     *   bags:list<string>,
     *   errors:list<string>
     * }
     */
    public function prime(): array
    {
        $seeded = 0;
        $peeked = 0;
        $bags = [];
        $errors = [];
        $chromeMiss = false;

        // peer_hydrate / critical: ScopeIdentity may be absent even with in_request=true.
        // Install default channel only when a request Context is already alive (no leak after reset).
        $this->ensureStorefrontScopeIdentityForBagPrime();
        // Theme must be on Template before chrome seed (durable bake / partials share keys).
        $this->ensureFrontendThemeAssignedToTemplate();

        $themeId = $this->resolveFrontendThemeId();
        $storageScope = $this->resolveStorageScope();
        if ($themeId > 0) {
            try {
                /** @var ThemeLayoutEntityChrome $chrome */
                $chrome = ObjectManager::getInstance(ThemeLayoutEntityChrome::class);
                $chromeResult = $chrome->seedPublishedHotCacheEager($themeId, $storageScope);
                if (($chromeResult['seeded'] ?? false) === true) {
                    $seeded++;
                    $bags[] = 'theme.layout_entity.chrome_rendered';
                } elseif (($chromeResult['peeked'] ?? false) === true) {
                    $peeked++;
                    $bags[] = 'theme.layout_entity.chrome_rendered';
                } else {
                    // Architect msg-5 / O1: honest miss — do NOT remember empty chrome.rendered HTML;
                    // do NOT buildChromeSlotProjection (空烧). Light [] Shared only.
                    $chromeMiss = true;
                    $errors[] = 'chrome_rendered:miss scope=' . $storageScope;
                    try {
                        /** @var ThemeLayoutEntitySlotFiller $fillerEarly */
                        $fillerEarly = ObjectManager::getInstance(ThemeLayoutEntitySlotFiller::class);
                        foreach ($this->storefrontBagPrimeScopes($storageScope) as $candidateScope) {
                            $fillerEarly->rememberHonestEmptyChromeSlotProjection($themeId, $candidateScope);
                        }
                    } catch (\Throwable) {
                        // MRU block below still records honest [] when possible.
                    }
                }
            } catch (\Throwable $e) {
                $chromeMiss = true;
                $errors[] = 'chrome_rendered:' . $e->getMessage();
            }
        } else {
            $errors[] = 'theme_id_unresolved';
        }

        foreach ([
            'theme.header.category_nav' => static fn(): mixed => HeaderCommerceData::resolveCategoryNavItems(),
            'theme.header.search_types' => static fn(): mixed => HeaderCommerceData::resolveSearchTypes(),
            'theme.header.hot_words' => static fn(): mixed => HeaderCommerceData::resolveHotWords(),
        ] as $bag => $primer) {
            try {
                $primer();
                $seeded++;
                $bags[] = $bag;
            } catch (\Throwable $e) {
                $errors[] = $bag . ':' . $e->getMessage();
            }
        }

        // wave8-8c3/8c4: fill theme.partials.fetch.header Shared/Process bags.
        // Ensure theme_id is present in Template so chrome cache keys match live SSR.
        try {
            if (!RequestContext::scopeIdentity() instanceof ScopeIdentity) {
                throw new \RuntimeException('scope_identity_missing');
            }
            /** @var Partials $partials */
            $partials = ObjectManager::getInstance(Partials::class);
            $html = $partials->renderPartials('frontend', 'header', [], 'default');
            if (\is_string($html) && \trim($html) !== '') {
                $seeded++;
                $bags[] = 'theme.partials.fetch.header';
            }
        } catch (\Throwable $e) {
            $errors[] = 'partials.header:' . $e->getMessage();
        }

        // wave8-8c6/8c7 + architect msg-5 / O1: chrome_slot_projection LAST (MRU).
        // On chrome miss: honest [] only (禁空烧). On hit: normal prime may build once.
        if ($themeId > 0) {
            try {
                /** @var ThemeLayoutEntitySlotFiller $filler */
                $filler = ObjectManager::getInstance(ThemeLayoutEntitySlotFiller::class);
                $scopes = $this->storefrontBagPrimeScopes($storageScope);
                $slotted = false;
                foreach ($scopes as $candidateScope) {
                    if ($chromeMiss) {
                        if ($filler->rememberHonestEmptyChromeSlotProjection($themeId, $candidateScope)) {
                            $slotted = true;
                        }
                    } elseif ($filler->primePublishedChromeSlotProjectionHotCache($themeId, $candidateScope)) {
                        $slotted = true;
                    }
                }
                if ($slotted) {
                    $seeded++;
                    $bags[] = 'theme.layout_entity.chrome_slot_projection';
                } else {
                    $errors[] = 'chrome_slot_projection:peek_miss scope=' . $storageScope;
                }
            } catch (\Throwable $e) {
                $errors[] = 'chrome_slot_projection:' . $e->getMessage();
            }
        }

        return [
            'seeded' => $seeded,
            'peeked' => $peeked,
            'bags' => \array_values(\array_unique($bags)),
            'errors' => \array_slice($errors, 0, 8),
        ];
    }

    /**
     * Canonical storefront bag-prime scopes. Leaf channel first (ensureStorefrontScopeIdentity),
     * then ancestors where published chrome bake usually lives.
     *
     * @return list<string>
     */
    private function storefrontBagPrimeScopes(string $storageScope): array
    {
        return \array_values(\array_unique(\array_filter([
            \trim($storageScope),
            'default.__store__.__channel__',
            'default.__store__.default',
            'default.__website__.default',
            'default.default.default',
        ], static fn(string $s): bool => $s !== '')));
    }

    /**
     * Bag-prime Context may serve FPC HIT without a frozen ScopeIdentity.
     * Align keys with resolveStorageScope() → default.__store__.__channel__.
     * Never creates a new Context after reset (capture_miss) — avoids identity leak.
     */
    private function ensureStorefrontScopeIdentityForBagPrime(): bool
    {
        try {
            if (RequestContext::scopeIdentity() instanceof ScopeIdentity) {
                return true;
            }
            if (!RequestContext::isInitialized()) {
                return false;
            }
            // Website::ID_DEFAULT channel → storageScope default.__store__.__channel__.
            RequestContext::installScopeIdentity(
                ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL)
            );

            return RequestContext::scopeIdentity() instanceof ScopeIdentity;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensureFrontendThemeAssignedToTemplate(): void
    {
        try {
            $template = \Weline\Framework\View\Template::getInstance();
            $existing = $template->getData('theme');
            if (\is_array($existing) && isset($existing['theme']) && \is_object($existing['theme'])) {
                return;
            }
            $themeId = $this->resolveFrontendThemeId();
            if ($themeId <= 0) {
                return;
            }
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->clearData()->clearQuery()->load($themeId);
            if ((int)$theme->getId() <= 0) {
                return;
            }
            $base = \is_array($existing) ? $existing : [];
            $base['theme'] = $theme;
            $base['area'] = $base['area'] ?? 'frontend';
            $template->assign('theme', $base);
        } catch (\Throwable) {
        }
    }

    private function resolveFrontendThemeId(): int
    {
        try {
            /** @var ThemeContextService $context */
            $context = ObjectManager::getInstance(ThemeContextService::class);
            $theme = $context->resolveTheme('frontend', null, false);
            if ($theme instanceof WelineTheme) {
                return (int)$theme->getId();
            }
        } catch (\Throwable) {
        }

        // Theme-owned fallback (no cross-module Model): active frontend theme row.
        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->clearData()->clearQuery()->getActiveTheme('frontend');
            $id = (int)$theme->getId();
            if ($id > 0) {
                return $id;
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    private function resolveStorageScope(): string
    {
        try {
            if (RequestContext::isInitialized()) {
                $identity = RequestContext::scopeIdentity();
                if ($identity instanceof ScopeIdentity) {
                    /** @var \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface $scopes */
                    $scopes = ObjectManager::getInstance(
                        \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class
                    );

                    return $scopes->contextFromIdentity($identity)->storageScope;
                }
            }
        } catch (\Throwable) {
        }

        // Align with ensureStorefrontScopeIdentityForBagPrime / SystemConfig channel leaf.
        return 'default.__store__.__channel__';
    }
}
