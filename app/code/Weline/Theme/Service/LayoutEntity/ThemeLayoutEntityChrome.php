<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\State;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Theme\Helper\WidgetI18n;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/**
 * Storefront/preview chrome render via baked entity phtml (hard fail if missing).
 *
 * Heavy chrome scopes (store/website with footer-container) can take tens of seconds
 * to render on a cold worker. Channel overlays only bake footer-extras; injectChromeSlots
 * must still pull footer from ancestors. Without a durable snapshot, ancestor renders
 * time out / soft-skip and every page keeps an empty weline-footer--shell.
 *
 * Snapshot file is always colocated with the resolved chrome.phtml (not the request
 * scope). Published pointers often inherit an ancestor version — keying cache by the
 * leaf request scope would poison sibling directories with the wrong HTML.
 *
 * Snapshots are ALSO keyed by storefront locale: chrome.rendered.{locale}.html.
 * A single locale-agnostic chrome.rendered.html froze zh labels into every EN/HI/… page.
 *
 * wave7-7s: published path peeks Policy then fills from durable disk on miss.
 * Sync rememberPolicy of large chrome HTML on first cold paid bag-write tax
 * (msg-38 LayoutSlot 1673ms). Seed Policy after the response so warm workers
 * still HIT; same-request secondary chrome slots HIT via SlotFiller projection Policy.
 * No parallel Theme process static.
 */
final class ThemeLayoutEntityChrome
{
    private const SNAPSHOT_FORMAT = 'v3';
    public function __construct(
        private readonly ThemeLayoutEntityPointerResolver $pointers,
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ?StorefrontScopeHotCache $hotCache = null,
        private readonly ?\Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface $scopes = null,
    ) {
    }

    /**
     * Render shared chrome for theme+scope. Prefer published unless $preview.
     * Optional $themeVersionId forces a specific version path when set.
     *
     * @throws \RuntimeException when chrome entity is missing
     */
    public function renderCurrent(
        int $themeId,
        string $scope,
        ?int $themeVersionId = null,
        bool $preview = false,
    ): string {
        return (string)\Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
            \Weline\Theme\Service\ThemeLayoutBudgetPhases::L1_CHROME,
            function () use ($themeId, $scope, $themeVersionId, $preview): string {
                return $this->renderCurrentInner($themeId, $scope, $themeVersionId, $preview);
            },
        );
    }

    /**
     * @throws \RuntimeException when chrome entity is missing
     */
    private function renderCurrentInner(
        int $themeId,
        string $scope,
        ?int $themeVersionId = null,
        bool $preview = false,
    ): string {
        $source = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
            'theme.chrome.resolve',
            fn(): array => $this->resolveRenderSource($themeId, $scope, $themeVersionId, $preview),
        );
        $path = $source['path'];
        $binding = $source['binding'];
        $preview = $source['preview'];
        // Preview/draft always renders live so editors see unpublished chrome nodes.
        if ($preview) {
            $html = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
                'theme.chrome.render',
                fn(): string => $this->includeChromePhtml($path, $binding),
            );
            return $binding !== null
                ? $this->finalizeChromeRendered($html, $themeId, true)
                : $html;
        }

        $hotCache = $this->resolveHotCache();
        $locale = $this->normalizeLocaleSegment(WidgetI18n::storefrontLocale());
        $logicalKey = 'chrome.rendered.' . self::SNAPSHOT_FORMAT . '|'
                    . ($binding?->identity->cacheKey() ?? '') . '|'
                    . ($binding?->cacheKey() ?? $path) . '|' . $locale;
        if ($hotCache instanceof StorefrontScopeHotCache) {
            // Warm HIT only — never invent a HIT; miss falls through to disk.
            $cached = $hotCache->peekPolicy(
                StorefrontThemeCacheCoordinator::publishedChromeRenderedPolicy(),
                $logicalKey,
            );
            if (\is_string($cached) && $cached !== '' && !$this->isLocalePoisonedChrome($cached, $locale)) {
                $this->noteVisitorPixelBootstrapIfPresent($cached);

                return $cached;
            }
        }

        // First-cold critical path: durable disk snapshot (or include) — no sync
        // shared_write of multi-scope chrome HTML into HotCache (6s→msg-38 regression).
        $html = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
            'theme.chrome.render',
            fn(): string => $this->loadOrRenderPublished($path, $binding),
        );
        if ($html !== '' && $hotCache instanceof StorefrontScopeHotCache) {
            $this->queuePublishedChromePolicySeed($hotCache, $logicalKey, $html);
        }
        $this->noteVisitorPixelBootstrapIfPresent($html);

        return $html;
    }

    /**
     * Resolve once for the root and all nested chrome slots. Inherited bindings
     * carry their actual owner scope; preview never falls back to published data.
     * @return array{path:string,binding:?EntityRenderBinding,scope:string,version_id:?int,preview:bool}
     */
    public function resolveRenderSource(int $themeId, string $scope, ?int $themeVersionId = null, bool $preview = false): array
    {
        if ($themeId < 1 || \trim($scope) === '') {
            throw new \RuntimeException('theme_layout_entity_chrome_invalid_identity');
        }

        $selection = \Weline\Framework\Runtime\RequestContext::get('theme.layout_entity.preview_entity');
        if (is_array($selection) && (int)($selection['theme_id'] ?? 0) === $themeId) {
            $themeVersionId = (int)($selection['chrome_version_id'] ?? 0);
            if ($themeVersionId < 1) {
                throw new \RuntimeException('theme_layout_entity_preview_chrome_version_missing');
            }
            $scope = (string)($selection['chrome_scope'] ?? $selection['scope']);
            $preview = true;
        }
        $key = 'theme.layout_entity.chrome_source.' . hash('sha256', json_encode([$themeId, $scope, $themeVersionId, $preview], JSON_THROW_ON_ERROR));
        $resolved = \Weline\Framework\Runtime\RequestContext::get($key);
        if (is_array($resolved)) {
            if ($resolved['binding'] instanceof EntityRenderBinding) {
                \Weline\Framework\Runtime\RequestContext::set('theme.layout_entity.rendered_chrome_binding', $resolved['binding']);
            }
            return $resolved;
        }
        $path = '';
        $binding = null;
        $versionIdentity = null;
        if ($themeVersionId !== null && $themeVersionId > 0) {
            $version = clone ObjectManager::getInstance(\Weline\Theme\Model\ThemeScopeVersion::class);
            $version->load($themeVersionId);
            if ($version->getVersionId() > 0) {
                $versionIdentity = $version->toVersionIdentity()->withVersion(
                    $version->getVersionId(),
                    $preview
                        ? \Weline\Theme\Api\Version\ThemeVersionIdentity::MODE_DRAFT
                        : \Weline\Theme\Api\Version\ThemeVersionIdentity::MODE_FORMAL,
                    \max(1, $version->getContentRevision()),
                );
                $scope = $version->getScope();
            }
        } else {
            $pointer = $preview
                ? $this->pointers->resolveCurrentChrome($themeId, $scope)
                : $this->pointers->resolvePublishedChrome($themeId, $scope);
            $path = \is_array($pointer) ? (string)($pointer['path'] ?? '') : '';
            $themeVersionId = (int)($pointer['version_id'] ?? 0);
            $scope = (string)($pointer['scope'] ?? $scope);
            $versionIdentity = ($pointer['identity'] ?? null) instanceof \Weline\Theme\Api\Version\ThemeVersionIdentity
                ? $pointer['identity']
                : null;
            $binding = null;
            if ($versionIdentity !== null) {
                $binding = $this->readChromeBinding($versionIdentity);
                $path = $binding?->templatePath ?? $path;
            }
        }

        if ($versionIdentity !== null && $binding === null) {
            $binding = $this->readChromeBinding($versionIdentity);
            $path = $binding?->templatePath ?? $path;
        }

        if ($path === '' || !\is_file($path)) {
            throw new \RuntimeException(
                'theme_layout_entity_chrome_missing: theme=' . $themeId
                . ' scope=' . $scope
                . ($themeVersionId ? (' tv=' . $themeVersionId) : '')
            );
        }

        if ($binding !== null) {
            \Weline\Framework\Runtime\RequestContext::set('theme.layout_entity.rendered_chrome_binding', $binding);
        }
        $resolved = ['path' => $path, 'binding' => $binding, 'scope' => $scope,
            'version_id' => $themeVersionId, 'preview' => $preview];
        \Weline\Framework\Runtime\RequestContext::set($key, $resolved);
        return $resolved;
    }

    /** @return list<array{path:string,binding:?EntityRenderBinding,scope:string,version_id:?int,preview:bool}> */
    public function resolveRenderSources(int $themeId, string $scope, bool $preview = false): array
    {
        $selection = \Weline\Framework\Runtime\RequestContext::get('theme.layout_entity.preview_entity');
        $key = 'theme.layout_entity.chrome_sources.' . hash('sha256', json_encode([$themeId, $scope, $preview, $selection], JSON_THROW_ON_ERROR));
        $sources = \Weline\Framework\Runtime\RequestContext::get($key);
        if (!is_array($sources)) {
            $sources = [];
            $lastError = null;
            $seen = [];
            $chain = is_array($selection) && (int)($selection['theme_id'] ?? 0) === $themeId
                ? [$scope] : $this->scopeFallbackChain($scope);
            foreach ($chain as $candidate) {
                try {
                    $source = $this->resolveRenderSource($themeId, $candidate, null, $preview);
                } catch (\Throwable $error) {
                    $lastError = $error;
                    continue;
                }
                $identity = $source['binding']?->cacheKey() ?? $source['path'];
                if (!isset($seen[$identity])) {
                    $seen[$identity] = true;
                    $sources[] = $source;
                }
            }
            if ($sources === [] && $lastError !== null) {
                throw $lastError;
            }
            \Weline\Framework\Runtime\RequestContext::set($key, $sources);
        }
        // Reinstall on cache hits too: nested reads and root HTML must have the
        // same scope ownership, regardless of whether projection HTML was cached.
        $bindings = array_values(array_filter(array_column($sources, 'binding'), static fn($binding): bool => $binding instanceof EntityRenderBinding));
        \Weline\Framework\Runtime\RequestContext::set('theme.layout_entity.rendered_chrome_bindings', $bindings);
        return $sources;
    }

    /** @return list<string> */
    public function scopeFallbackChain(string $scope): array
    {
        $scope = \trim($scope);
        $chain = [];
        $scopes = $this->scopes ?? ObjectManager::getInstance(\Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class);
        try {
            $identity = $scopes->fromStorageScope($scope, true);
            if ($identity !== null) {
                foreach ($scopes->chainFromIdentity($identity) as $candidate) {
                    $candidate = \trim((string)$candidate);
                    if ($candidate !== '' && !\in_array($candidate, $chain, true)) {
                        $chain[] = $candidate;
                    }
                }
            }
        } catch (\Throwable) {
            // fall through
        }
        if ($chain === [] && $scope !== '') {
            $chain[] = $scope;
        }
        // SystemConfig folds default Store/Channel into the global bucket, so a
        // storefront identity resolves storage scope "default.default.default" while Theme
        // publishes own the sentinel scopes. Ascend to them before the literal global.
        if (\in_array('default.default.default', $chain, true)
            && !\in_array('default.__store__.__channel__', $chain, true)) {
            $chain[] = 'default.__website__.default';
            $chain[] = 'default.__store__.__channel__';
        }
        if (!\in_array('default.default.default', $chain, true)) {
            $chain[] = 'default.default.default';
        }

        return $chain;
    }

    /**
     * Seed chrome.rendered Policy after the response so the next request / worker
     * can peek HIT. Keeps first-cold LayoutSlot off the shared_write tax.
     */
    private function queuePublishedChromePolicySeed(
        StorefrontScopeHotCache $hotCache,
        string $logicalKey,
        string $html,
    ): void {
        $queueKey = 'theme-chrome-rendered-policy:' . \sha1($logicalKey);
        PostResponseTaskQueue::enqueue($queueKey, static function () use ($hotCache, $logicalKey, $html): void {
            try {
                $hotCache->rememberPolicy(
                    StorefrontThemeCacheCoordinator::publishedChromeRenderedPolicy(),
                    $logicalKey,
                    static fn(): string => $html,
                );
            } catch (\Throwable) {
                // Best-effort; disk snapshot remains the durable source of truth.
            }
        });
    }

    /**
     * wave8-8c / P5-O1 / P8-O2 deferred warmup: sync-seed chrome.rendered Policy.
     *
     * Order per scope: Shared peek → durable disk → (stage-gated) one-shot
     * {@see loadOrRenderPublished} when chrome.phtml exists but locale snapshot was
     * wiped by materializeChrome. Never remembers empty-string HTML (禁假 HIT).
     *
     * Critical stages (`pre_critical` / `critical`) stay disk-only so virgin include
     * tax does not inflate the sealed wall clock; durable bake runs on
     * `post_critical_heavy` / `peer_hydrate` (and leftover `post_locale` when O1
     * still retouches after real locale SSR).
     *
     * @return array{seeded:bool,peeked:bool,scope?:string}
     */
    public function seedPublishedHotCacheEager(int $themeId, string $scope): array
    {
        if ($themeId < 1 || \trim($scope) === '') {
            return ['seeded' => false, 'peeked' => false];
        }

        $hotCache = $this->resolveHotCache();
        if (!$hotCache instanceof StorefrontScopeHotCache) {
            return ['seeded' => false, 'peeked' => false];
        }

        $locale = $this->normalizeLocaleSegment(WidgetI18n::storefrontLocale());
        $policy = StorefrontThemeCacheCoordinator::publishedChromeRenderedPolicy();
        $allowDurableBake = $this->bagPrimeAllowsDurableChromeBake();

        foreach ($this->publishedChromeSeedScopes($scope) as $candidateScope) {
            try {
                $pointer = $this->pointers->resolvePublishedChrome($themeId, $candidateScope);
                $path = \is_array($pointer) ? (string)($pointer['path'] ?? '') : '';
                $versionIdentity = ($pointer['identity'] ?? null) instanceof \Weline\Theme\Api\Version\ThemeVersionIdentity
                    ? $pointer['identity']
                    : null;
                $binding = $versionIdentity !== null ? $this->readChromeBinding($versionIdentity) : null;
                $path = $binding?->templatePath ?? $path;
                if ($path === '' || !\is_file($path)) {
                    continue;
                }

                $logicalKey = 'chrome.rendered.' . self::SNAPSHOT_FORMAT . '|'
                    . ($binding?->identity->cacheKey() ?? '') . '|'
                    . ($binding?->cacheKey() ?? $path) . '|' . $locale;
                $peeked = $hotCache->peekPolicy($policy, $logicalKey);
                if (\is_string($peeked) && $peeked !== ''
                    && !$this->isLocalePoisonedChrome($peeked, $locale)
                ) {
                    return ['seeded' => false, 'peeked' => true, 'scope' => $candidateScope];
                }

                // Prefer durable snapshot; stage-gated one-shot bake when wipe left phtml only.
                $html = $this->readRenderedCache($path, $binding);
                if (($html === null || $html === '') && $allowDurableBake) {
                    $baked = $this->loadOrRenderPublished($path, $binding);
                    $html = $baked !== '' ? $baked : null;
                }
                if ($html === null || $html === '') {
                    continue;
                }
                if ($this->isLocalePoisonedChrome($html, $locale)) {
                    continue;
                }

                $hotCache->rememberPolicy($policy, $logicalKey, static fn(): string => $html);

                return ['seeded' => true, 'peeked' => false, 'scope' => $candidateScope];
            } catch (\Throwable) {
                continue;
            }
        }

        return ['seeded' => false, 'peeked' => false];
    }

    /**
     * P8-O2: one-shot chrome.rendered durable bake is allowed only off the critical
     * sealed wall-clock path. pre_critical / critical remain disk-only + honest miss.
     */
    private function bagPrimeAllowsDurableChromeBake(): bool
    {
        $stage = '';
        try {
            if (\class_exists(\Weline\Framework\Runtime\RequestContext::class)
                && \Weline\Framework\Runtime\RequestContext::isInitialized()
            ) {
                $stage = \trim((string)\Weline\Framework\Runtime\RequestContext::get(
                    'wls.storefront_hot_cache_bag_prime.stage',
                    '',
                ));
            }
        } catch (\Throwable) {
            $stage = '';
        }
        if ($stage === '') {
            try {
                $stage = \trim((string)($_SERVER['WLS_PRIME_HOT_CACHE_BAGS_STAGE'] ?? ''));
            } catch (\Throwable) {
                $stage = '';
            }
        }
        // Unknown / empty stage (CLI probes): allow bake — not on critical seal path.
        if ($stage === '') {
            return true;
        }

        return \in_array($stage, [
            'post_critical_heavy',
            'peer_hydrate',
            'post_locale',
        ], true);
    }

    /**
     * Leaf request scopes often have no chrome bake; published chrome lives on
     * website/store ancestors. Order matches SystemConfig channel fallback.
     *
     * @return list<string>
     */
    private function publishedChromeSeedScopes(string $scope): array
    {
        return \array_values(\array_unique(\array_filter([
            \trim($scope),
            'default.__store__.__channel__',
            'default.__store__.default',
            'default.__website__.default',
            'default.default.default',
        ], static fn(string $s): bool => $s !== '')));
    }

    /**
     * Force re-solidify chrome.rendered.{locale}.html for a chrome.phtml path.
     * Used by injection-collect rebake (all themes) and runtime miss self-heal.
     * Writes required JSON default_injections via finalize (minus user_deleted only).
     */
    public function forceResolidifyRenderedSnapshot(string $chromePhtmlPath, string $locale = '', ?EntityRenderBinding $binding = null): string
    {
        $chromePhtmlPath = \trim($chromePhtmlPath);
        if ($chromePhtmlPath === '' || !\is_file($chromePhtmlPath)) {
            return '';
        }
        $locale = $locale !== ''
            ? $this->normalizeLocaleSegment($locale)
            : $this->normalizeLocaleSegment(WidgetI18n::storefrontLocale());
        $previousOverride = State::getRequestLanguageOverride();
        try {
            State::setRequestLanguageOverride($locale);

            return $this->loadOrRenderPublished($chromePhtmlPath, $binding, true);
        } finally {
            State::setRequestLanguageOverride($previousOverride);
        }
    }

    private function loadOrRenderPublished(string $path, ?EntityRenderBinding $binding = null, bool $refresh = false): string
    {
        $cached = $refresh ? null : $this->readRenderedCache($path, $binding);
        if ($cached !== null) {
            return $cached;
        }

        // Path locale (WidgetI18n) can diverge from Phrase State::getLangLocal();
        // force override so chrome.rendered.{locale}.html is not English-poisoned.
        $locale = $this->normalizeLocaleSegment(WidgetI18n::storefrontLocale());
        $previousOverride = State::getRequestLanguageOverride();
        try {
            State::setRequestLanguageOverride($locale);
            $html = $this->includeChromePhtml($path, $binding);
        } finally {
            State::setRequestLanguageOverride($previousOverride);
        }
        if ($html !== '') {
            // Bake-time完备：promote @weline-slot projections into blank
            // theme-published-slot before durable write (禁店面/控制器运行时补槽).
            $themeId = 0;
            if (\preg_match('#theme-layout-entities[/\\\\](\d+)[/\\\\]#', $path, $themeMatch) === 1) {
                $themeId = (int)$themeMatch[1];
            }
            try {
                $html = $this->finalizeChromeRendered($html, $themeId, $binding !== null);
            } catch (\Throwable $error) {
                throw new \RuntimeException('theme_layout_entity_chrome_finalize_failed', 0, $error);
            }
            $this->writeRenderedCache($path, $html, $binding);
        }

        return $html;
    }

    private function finalizeChromeRendered(string $html, int $themeId, bool $structureComplete): string
    {
        $previous = \Weline\Framework\Runtime\RequestContext::get(ThemeLayoutEntityPublishedSlotHost::CTX_SOLIDIFYING);
        \Weline\Framework\Runtime\RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_SOLIDIFYING, true);
        try {
            return ObjectManager::getInstance(ThemeLayoutEntitySlotFiller::class)
                ->finalizePublishedChromeRenderedHtml($html, $themeId, $structureComplete);
        } finally {
            \Weline\Framework\Runtime\RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_SOLIDIFYING, $previous);
        }
    }

    private function includeChromePhtml(string $path, ?EntityRenderBinding $entityBinding = null): string
    {
        $previous = \Weline\Framework\Runtime\RequestContext::get(ThemeLayoutEntityPublishedSlotHost::CTX_SOLIDIFYING);
        \Weline\Framework\Runtime\RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_SOLIDIFYING, true);
        \Weline\Framework\Runtime\FiberOutputBuffer::beginCapture();
        try {
            include $path;

            return (string)\Weline\Framework\Runtime\FiberOutputBuffer::endCapture();
        } catch (\Throwable $e) {
            \Weline\Framework\Runtime\FiberOutputBuffer::discardCapture();
            throw new \RuntimeException(
                'theme_layout_entity_chrome_render_failed: ' . $e->getMessage(),
                0,
                $e,
            );
        } finally {
            \Weline\Framework\Runtime\RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_SOLIDIFYING, $previous);
        }
    }

    private function renderedCachePath(string $chromePhtmlPath, ?EntityRenderBinding $binding = null): string
    {
        $locale = $this->normalizeLocaleSegment(WidgetI18n::storefrontLocale());

        return \dirname($chromePhtmlPath)
            . \DIRECTORY_SEPARATOR
            . 'chrome.rendered.'
            . ($binding !== null ? self::SNAPSHOT_FORMAT . '.' . hash('sha256', $binding->cacheKey()) . '.' : '')
            . $locale
            . '.html';
    }

    private function normalizeLocaleSegment(string $locale): string
    {
        $locale = \trim($locale);
        if ($locale === ''
            || \preg_match('/^[a-z]{2,3}_[A-Za-z0-9]+(?:_[A-Za-z0-9]+)?$/', $locale) !== 1
        ) {
            return 'zh_Hans_CN';
        }

        return $locale;
    }

    private function readRenderedCache(string $chromePhtmlPath, ?EntityRenderBinding $binding = null): ?string
    {
        $cachePath = $this->renderedCachePath($chromePhtmlPath, $binding);
        if (!\is_file($cachePath)) {
            return null;
        }

        if ($binding === null) {
            // 旧产物没有配置指纹，保留其时间戳兼容检查。
            $cacheMtime = @filemtime($cachePath);
            $phtmlMtime = @filemtime($chromePhtmlPath);
            if ($cacheMtime === false || $phtmlMtime === false || $cacheMtime < $phtmlMtime) {
                return null;
            }
            $configPath = dirname($chromePhtmlPath) . DIRECTORY_SEPARATOR . 'chrome-config.json';
            if (is_file($configPath)) {
                $configMtime = @filemtime($configPath);
                if ($configMtime !== false && $cacheMtime < $configMtime) {
                    return null;
                }
            }
        }

        $html = @\file_get_contents($cachePath);
        if (!\is_string($html) || $html === '') {
            return null;
        }
        if ($binding !== null) {
            return $html;
        }

        // Heal: snapshot locale must match chrome labels (EN「Ship to」/ ZH footer seeds).
        $locale = $this->normalizeLocaleSegment(WidgetI18n::storefrontLocale());
        if ($this->isLocalePoisonedChrome($html, $locale)) {
            @\unlink($cachePath);

            return null;
        }

        // Incomplete solidify (blank required chrome extension slots) → drop snapshot
        // so this request dynamically re-solidifies the active theme (user rule:
        // 无固化则运行时动态固化；必装 JSON 无 user_deleted 必须写入模板).
        if ($this->isIncompleteRequiredChromeRendered($html)) {
            @\unlink($cachePath);

            return null;
        }

        return $html;
    }

    /**
     * True when footer/header extension published slots exist but still hold
     * missing-config / empty inners — solidify scheme failed; force rebuild.
     */
    private function isIncompleteRequiredChromeRendered(string $html): bool
    {
        if ($html === '' || !\str_contains($html, 'theme-published-slot')) {
            return false;
        }
        // Known required chrome carriers that must not ship blank after solidify.
        foreach ([
            'footer-payment-account-links',
            'footer-help-links',
        ] as $slotId) {
            if (!\str_contains($html, 'data-slot-id="' . $slotId . '"')) {
                continue;
            }
            if (\preg_match(
                '/<div\b[^>]*\bdata-slot-id="' . \preg_quote($slotId, '/') . '"[^>]*>(.*?)<\/div>/is',
                $html,
                $m,
            ) !== 1) {
                continue;
            }
            $inner = (string)($m[1] ?? '');
            if (ThemeLayoutEntityPublishedSlotHost::isEffectivelyBlankSlotInner($inner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a chrome snapshot was baked under the wrong Phrase/path locale:
     * - non-English pages still contain English「Ship to」
     * - non-Chinese pages still contain Chinese footer/chrome seed labels
     * (dict already has fr_FR etc.; stale chrome.rendered.{locale}.html must rebuild).
     */
    private function isLocalePoisonedChrome(string $html, string $locale): bool
    {
        if ($html === '') {
            return false;
        }

        $isEn = $locale === 'en_US' || $locale === 'en_GB' || \str_starts_with($locale, 'en_');
        if (!$isEn && \str_contains($html, 'delivery-line-1">Ship to')) {
            return true;
        }
        // Address Taglib English fallback baked while Phrase/State lagged (bn/fr/… filename + EN labels).
        if (!$isEn && (
            \str_contains($html, '"province":"Province"')
            || \str_contains($html, 'Quickly add address')
            || \str_contains($html, '">Province</label>')
        )) {
            return true;
        }

        $isZh = $locale === 'zh_Hans_CN'
            || $locale === 'zh_Hant_TW'
            || $locale === 'zh_CN'
            || \str_starts_with($locale, 'zh_');
        if ($isZh) {
            return false;
        }

        // Footer / chrome seeds that must not leak Chinese on FR/ES/… pages.
        foreach ([
            '定制与合作',
            '支付与账户',
            '帮助中心',
            '关于我们',
            '官方社交媒体',
            '无障碍声明',
            '社媒登录',
            '回到顶部',
            '同时用作结账地址',
            '周一至周五 9:00 - 18:00',
            // Newsletter footer / popup CTA seeds (dict has bn_BD etc.; stale bake must rebuild).
            '订阅我们的邮件',
            '获取最新的优惠信息和新品资讯',
            '请输入您的邮箱',
            '请输入您的邮箱地址',
            '>订阅</',
            '>立即订阅</',
        ] as $zhMarker) {
            if (\str_contains($html, $zhMarker)) {
                return true;
            }
        }

        return false;
    }

    private function writeRenderedCache(string $chromePhtmlPath, string $html, ?EntityRenderBinding $binding = null): void
    {
        $cachePath = $this->renderedCachePath($chromePhtmlPath, $binding);
        $dir = \dirname($cachePath);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return;
        }

        $tmp = $cachePath . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $html) === false) {
            return;
        }
        if (!@\rename($tmp, $cachePath)) {
            @\unlink($tmp);
        }
    }

    private function readChromeBinding(\Weline\Theme\Api\Version\ThemeVersionIdentity $identity): ?EntityRenderBinding
    {
        $key = 'theme.layout_entity.chrome_binding.v3.' . hash('sha256', json_encode($identity->toArray(), JSON_THROW_ON_ERROR));
        $cached = \Weline\Framework\Runtime\RequestContext::get($key);
        if ($cached instanceof EntityRenderBinding) {
            return $cached;
        }
        $binding = ObjectManager::getInstance(ThemeLayoutEntityBindingStore::class)->readChromeBinding($identity);
        if ($binding !== null) {
            \Weline\Framework\Runtime\RequestContext::set($key, $binding);
        }

        return $binding;
    }

    private function resolveHotCache(): ?StorefrontScopeHotCache
    {
        if ($this->hotCache instanceof StorefrontScopeHotCache) {
            return $this->hotCache;
        }
        try {
            $resolved = ObjectManager::getInstance(StorefrontScopeHotCache::class);

            return $resolved instanceof StorefrontScopeHotCache ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Cached chrome may already embed Visitor pixel bootstrap HTML without calling
     * PixelBootstrapHtmlService::render this request — mark so body-end skips the ~40KB dup.
     */
    private function noteVisitorPixelBootstrapIfPresent(string $html): void
    {
        if ($html === '' || !\str_contains($html, 'data-weline-pixel-bootstrap=')) {
            return;
        }
        if (!\class_exists(\Weline\Visitor\Service\PixelBootstrapHtmlService::class)) {
            return;
        }
        try {
            \Weline\Visitor\Service\PixelBootstrapHtmlService::markEmittedThisRequest();
        } catch (\Throwable) {
        }
    }
}
