<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Framework\Runtime\ProcessSharedInterface;

/**
 * Resolve the published ThemeScopeVersion (theme version V) for storefront runtime tags.
 *
 * Authority is ThemeScopeVersion selection / is_published — not ThemeLayoutVersion page axis.
 * Storefront generation / Worker identity is {@see ThemeScopeGenerationCode} for the
 * Scope-published row — not system deploy_version, not a cache-clear side effect.
 */
class ThemePublishedVersionRuntimeResolver implements ProcessSharedInterface
{
    /**
     * @return array{themePublishedVersionId: string, themePublishedVersion: string}
     */
    public function resolve(?int $themeId = null, string $pageType = 'homepage'): array
    {
        $full = $this->resolveGenerationIdentity($themeId, $pageType);

        return [
            'themePublishedVersionId' => $full['themePublishedVersionId'],
            'themePublishedVersion' => $full['themePublishedVersion'],
        ];
    }

    /**
     * Applied storefront theme before the process default.
     *
     * Header/API/ScopeBootstrap may run before Env theme is switched to the
     * website theme; Env::getTheme() is the module package default (theme id 1)
     * and would stamp every page/API with another theme's generation.
     */
    private function resolveAppliedThemeId(): int
    {
        foreach (['frontend', 'backend'] as $area) {
            foreach (['runtime', 'editor', 'preview'] as $purpose) {
                $ctx = ThemeApplicationContext::current($area, $purpose);
                if ($ctx instanceof ThemeApplicationContext && (int)$ctx->themeId > 0) {
                    return (int)$ctx->themeId;
                }
            }
        }

        // Website application is the authoritative storefront theme even when the
        // request has not installed ThemeApplicationContext (ScopeBootstrap fast
        // path / early header). Query the exact Website scope before Env default.
        if (\class_exists(\Weline\Websites\Service\ThemeApplicationService::class)) {
            try {
                $meta = RequestContext::scopeMetadata();
                $websiteId = (int)($meta['website_id'] ?? 0);
                $websiteCode = \trim((string)($meta['website_code'] ?? 'default')) ?: 'default';
                /** @var \Weline\Websites\Service\ThemeApplicationService $applications */
                $applications = ObjectManager::getInstance(
                    \Weline\Websites\Service\ThemeApplicationService::class
                );
                $record = $applications->getOwn(
                    'website|' . $websiteId . '|' . $websiteCode . '||||v1',
                    ScopeIdentity::MODE_NORMAL,
                    'frontend',
                );
                $themeId = (int)($record['reference']?->themeId ?? 0);
                if ($themeId > 0) {
                    return $themeId;
                }
            } catch (\Throwable) {
            }
        }

        if (\class_exists(\Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::class)) {
            try {
                $active = \Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::active();
                $themeId = \is_array($active) ? (int)($active['theme_id'] ?? 0) : 0;
                if ($themeId > 0) {
                    return $themeId;
                }
            } catch (\Throwable) {
            }
        }

        // QueryBin / ScopeBootstrap may run outside App's ThemeApplicationContext,
        // but the trusted ScopeIdentity is still present. Resolve the Website
        // application chain instead of stamping the module default theme.
        $identity = RequestContext::scopeIdentity();
        if ($identity instanceof ScopeIdentity) {
            try {
                $hierarchy = ObjectManager::getInstance(ScopeHierarchyInterface::class);
                /** @var \Weline\Websites\Api\Theme\ThemeApplicationInterface $applications */
                $applications = ObjectManager::getInstance(
                    \Weline\Websites\Api\Theme\ThemeApplicationInterface::class
                );
                $resolution = $applications->resolve(
                    $hierarchy->chainFromIdentity($identity),
                    $identity->storeMode ?? ScopeIdentity::MODE_NORMAL,
                    'frontend',
                );
                $themeId = (int)($resolution->reference?->themeId ?? 0);
                if ($themeId > 0) {
                    return $themeId;
                }
            } catch (\Throwable) {
            }
        }

        return 0;
    }

    /**
     * Numeric ThemeScopeVersion PK for internal Theme services (injections / bake).
     * Not the storefront wire generation code — use {@see resolve()} / generation identity for FE.
     */
    public function resolvePublishedNumericVersionId(?int $themeId = null, string $pageType = 'homepage'): int
    {
        unset($pageType);
        try {
            if ($themeId === null || $themeId <= 0) {
                $themeId = $this->resolveAppliedThemeId();
            }
            if ($themeId <= 0) {
                $theme = Env::getInstance()->getTheme();
                $themeId = (int)($theme['id'] ?? $theme['theme_id'] ?? 0);
            }
            if ($themeId <= 0) {
                return 0;
            }
            /** @var ThemeScopeVersionService $versions */
            $versions = ObjectManager::getInstance(ThemeScopeVersionService::class);
            foreach ($this->themeIdChain($themeId) as $candidateThemeId) {
                foreach ($this->scopeCandidates($candidateThemeId) as $scope) {
                    $published = $versions->getPublished($candidateThemeId, $scope);
                    if ($published instanceof ThemeScopeVersion && $published->getVersionId() > 0) {
                        return $published->getVersionId();
                    }
                }
            }
        } catch (\Throwable) {
            return 0;
        }

        return 0;
    }

    /**
     * Scope-level storefront generation identity for FE↔BE handshake pairing.
     * Empty id means skip generation enforcement (same spirit as empty deploy stamp).
     *
     * @return array{
     *   themePublishedVersionId: string,
     *   themePublishedVersion: string,
     *   themeId: int,
     *   canonicalScope: string
     * }
     */
    public function resolveGenerationIdentity(?int $themeId = null, string $pageType = 'homepage'): array
    {
        unset($pageType);
        $empty = [
            'themePublishedVersionId' => '',
            'themePublishedVersion' => '',
            'themeId' => 0,
            'canonicalScope' => '',
            'themeCode' => '',
            'versionNumber' => 0,
        ];

        try {
            if ($themeId === null || $themeId <= 0) {
                $themeId = $this->resolveAppliedThemeId();
            }
            if ($themeId <= 0) {
                $theme = Env::getInstance()->getTheme();
                $themeId = (int)($theme['id'] ?? $theme['theme_id'] ?? 0);
            }
            if ($themeId <= 0) {
                return $empty;
            }

            /** @var ThemeScopeVersionService $versions */
            $versions = ObjectManager::getInstance(ThemeScopeVersionService::class);
            foreach ($this->themeIdChain($themeId) as $candidateThemeId) {
                foreach ($this->scopeCandidates($candidateThemeId) as $scope) {
                    $published = $versions->getPublished($candidateThemeId, $scope);
                    if (!$published instanceof ThemeScopeVersion || $published->getVersionId() <= 0) {
                        continue;
                    }

                    $name = \trim((string)($published->getVersionName() ?? ''));
                    if ($name === '') {
                        $name = 'v' . $published->getVersionNumber();
                    }
                    $code = ThemeScopeGenerationCode::fromVersion($published);
                    if ($code === '') {
                        continue;
                    }
                    // TU epoch participates in the wire generation so a batch
                    // promote/rollback changes the FE scope identity even when the
                    // Scope-published version number and deploy build stay the same.
                    $tuEpoch = $this->storefrontTuEpoch($candidateThemeId);
                    if ($tuEpoch > 0) {
                        $code .= '.e' . $tuEpoch;
                    }

                    return [
                        'themePublishedVersionId' => $code,
                        'themePublishedVersion' => $name,
                        'themeId' => $candidateThemeId,
                        'canonicalScope' => (string)$scope,
                        'themeCode' => self::themeCodeFor($candidateThemeId),
                        'versionNumber' => \max(1, $published->getVersionNumber()),
                    ];
                }
            }
        } catch (\Throwable) {
            return $empty;
        }

        return $empty;
    }

    /**
     * 主题 code：WelineTheme.path 的最后一段（如 app/design/{Vendor}/{theme} → {theme}）。
     * 不是 name，也不是 id。
     */
    public function themeCodeFor(int $themeId): string
    {
        if ($themeId < 1) {
            return '';
        }
        try {
            $theme = (clone ObjectManager::getInstance(WelineTheme::class))->clearData()->clearQuery()->load($themeId);
            $path = \str_replace('\\', '/', \trim((string)$theme->getData('path')));

            return $path === '' ? '' : \basename($path);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Current Theme Update batch epoch for the active storefront pointer.
     * Empty/zero means no TU pointer; the wire generation stays version-only.
     */
    private function storefrontTuEpoch(int $themeId): int
    {
        if (!\class_exists(\Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::class)) {
            return 0;
        }
        try {
            return \Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::epochForTheme($themeId);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<int>
     */
    private function themeIdChain(int $themeId): array
    {
        $chain = [];
        $seen = [];
        $candidateId = $themeId;
        while ($candidateId > 0 && !isset($seen[$candidateId])) {
            $seen[$candidateId] = true;
            $chain[] = $candidateId;
            $theme = (clone ObjectManager::getInstance(WelineTheme::class))->clearData()->clearQuery()->load($candidateId);
            $parentId = (int)$theme->getParentId();
            if ($parentId < 1 || (int)$theme->getId() !== $candidateId) {
                break;
            }
            $candidateId = $parentId;
        }

        return $chain;
    }

    /**
     * Acceptable storefront generation ids after FPC promote (multi-host / multi-Scope).
     *
     * CLI without Channel RequestContext used to resolve only Global defaults while
     * html-disk Host pages bake Channel-published stamps — that false-failed promote
     * and caused FE「有新版本」reload loops (cookie/API Global ≠ page Channel).
     * Accept any sealed published generation code reachable from Env theme + frontend-bound
     * themes across storefront scope probes (Channel fallback chain included).
     * Also accepts legacy numeric version_id strings baked by pre-code FPC/html-disk.
     *
     * @return array<string, true> map of themePublishedVersionId => true
     */
    public function collectAcceptableStorefrontGenerationIds(): array
    {
        $ids = [];
        $current = \trim((string)($this->resolveGenerationIdentity()['themePublishedVersionId'] ?? ''));
        if ($current !== '') {
            $ids[$current] = true;
        }

        $themeIds = [];
        try {
            $envTheme = Env::getInstance()->getTheme();
            $envId = (int)($envTheme['id'] ?? $envTheme['theme_id'] ?? 0);
            if ($envId > 0) {
                $themeIds[$envId] = true;
            }
        } catch (\Throwable) {
        }
        try {
            if (\class_exists(ThemeApplicationUsageService::class)) {
                /** @var ThemeApplicationUsageService $usage */
                $usage = ObjectManager::getInstance(ThemeApplicationUsageService::class);
                foreach ($usage->frontendBoundThemeIds() as $boundId) {
                    if ($boundId > 0) {
                        $themeIds[$boundId] = true;
                    }
                }
            }
        } catch (\Throwable) {
        }

        /** @var ThemeScopeVersionService $versions */
        $versions = ObjectManager::getInstance(ThemeScopeVersionService::class);
        foreach (\array_keys($themeIds) as $themeId) {
            foreach ($this->themeIdChain((int)$themeId) as $candidateThemeId) {
                foreach ($this->storefrontScopeProbeList((int)$candidateThemeId) as $scope) {
                    try {
                        $published = $versions->getPublished($candidateThemeId, $scope);
                    } catch (\Throwable) {
                        continue;
                    }
                    if (!$published instanceof ThemeScopeVersion || $published->getVersionId() <= 0) {
                        continue;
                    }
                    if ($published->getLifecycle() !== ThemeScopeVersion::LIFECYCLE_SEALED) {
                        continue;
                    }
                    $code = ThemeScopeGenerationCode::fromVersion($published);
                    if ($code !== '') {
                        $epoch = $this->storefrontTuEpoch($candidateThemeId);
                        if ($epoch > 0) {
                            $code .= '.e' . $epoch;
                        }
                        $ids[$code] = true;
                    }
                    $legacy = ThemeScopeGenerationCode::legacyNumericId($published);
                    if ($legacy !== '') {
                        $ids[$legacy] = true;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * Strict current-generation check for a client-supplied wire id.
     *
     * The request may resolve a different fallback scope than the page that baked the
     * stamp (Channel page vs bare/Global API). Accept only a generation that is the
     * CURRENT published row of some storefront scope for that theme — never an older
     * sealed version, and never a different theme's generation.
     */
    public function isCurrentStorefrontGenerationId(string $clientVersionId): bool
    {
        $clientVersionId = \trim($clientVersionId);
        if ($clientVersionId === ''
            || \preg_match(ThemeScopeGenerationCode::PATTERN, $clientVersionId) !== 1
            || \preg_match('/^t(\d+)\./', $clientVersionId, $matched) !== 1) {
            return false;
        }
        $current = \trim((string)($this->resolveGenerationIdentity()['themePublishedVersionId'] ?? ''));
        if ($current !== '' && \strcasecmp($current, $clientVersionId) === 0) {
            return true;
        }

        $themeId = (int)$matched[1];
        if ($themeId < 1) {
            return false;
        }
        /** @var ThemeScopeVersionService $versions */
        $versions = ObjectManager::getInstance(ThemeScopeVersionService::class);
        foreach ($this->themeIdChain($themeId) as $candidateThemeId) {
            foreach ($this->storefrontScopeProbeList($candidateThemeId) as $scope) {
                try {
                    $published = $versions->getPublished($candidateThemeId, $scope);
                } catch (\Throwable) {
                    continue;
                }
                if (!$published instanceof ThemeScopeVersion || $published->getVersionId() <= 0) {
                    continue;
                }
                $code = ThemeScopeGenerationCode::fromVersion($published);
                if ($code === '') {
                    continue;
                }
                $epoch = $this->storefrontTuEpoch($candidateThemeId);
                if ($epoch > 0) {
                    $code .= '.e' . $epoch;
                }
                if (\strcasecmp($code, $clientVersionId) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Storage scopes nearest-first for storefront generation resolve.
     *
     * When RequestContext already has Website/Store/Channel, use that fallback chain.
     * Otherwise (CLI, /api hole-punch without Channel pin, Global-only API) still probe
     * Channel→Store→Website chains from frontend theme bindings so Host html-disk stamps
     * and outbound Cookie/JSON agree — never prefer orphan Global publish over Channel.
     *
     * @return list<string>
     */
    private function scopeCandidates(int $themeId): array
    {
        $list = [];
        $seen = [];
        $push = static function (string $scope) use (&$list, &$seen): void {
            $scope = \trim($scope);
            if ($scope === '' || isset($seen[$scope])) {
                return;
            }
            $seen[$scope] = true;
            $list[] = $scope;
        };

        try {
            $scopeIdentity = RequestContext::scopeIdentity();
            if ($scopeIdentity instanceof ScopeIdentity && !$scopeIdentity->isGlobal()) {
                /** @var ScopeHierarchyInterface $scopes */
                $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
                $context = $scopes->contextFromIdentity($scopeIdentity);
                foreach ($context->fallbackStorageScopes as $storageScope) {
                    $push((string)$storageScope);
                }
            }
        } catch (\Throwable) {
            // CLI / early bootstrap.
        }

        // RequestContext Global / missing: still prefer bound Channel publish over Global.
        $this->pushFrontendBoundChannelFallbackScopes($themeId, $push);

        foreach ($this->defaultStorefrontScopes() as $scope) {
            $push($scope);
        }

        return $list;
    }

    /**
     * RequestContext-independent scopes to probe for promote stamp acceptance.
     * Channel fallback chain must be included — html-disk Host pages bake Channel V.
     *
     * @return list<string>
     */
    private function storefrontScopeProbeList(int $themeId): array
    {
        $list = [];
        $seen = [];
        $push = static function (string $scope) use (&$list, &$seen): void {
            $scope = \trim($scope);
            if ($scope === '' || isset($seen[$scope])) {
                return;
            }
            $seen[$scope] = true;
            $list[] = $scope;
        };

        $this->pushFrontendBoundChannelFallbackScopes($themeId, $push);

        foreach ($this->defaultStorefrontScopes() as $scope) {
            $push($scope);
        }

        return $list;
    }

    /**
     * Push ScopeHierarchy fallback chains for frontend-bound websites as Channel.
     * Uses ScopeIdentity::channel + hierarchy — never hardcode storage scope literals.
     *
     * @param callable(string):void $push
     */
    private function pushFrontendBoundChannelFallbackScopes(int $themeId, callable $push): void
    {
        try {
            if (!\class_exists(ThemeApplicationUsageService::class)) {
                return;
            }
            /** @var ThemeApplicationUsageService $usage */
            $usage = ObjectManager::getInstance(ThemeApplicationUsageService::class);
            $bindings = $usage->frontendWebsiteBindingsByThemeId()[$themeId] ?? [];
            if ($bindings === []) {
                // Unbound theme: still probe default-website Channel (storefront Host bake).
                $bindings = [[
                    'website_id' => 0,
                    'website_code' => 'default',
                ]];
            }
            /** @var ScopeHierarchyInterface $scopes */
            $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
            foreach ($bindings as $binding) {
                $websiteId = (int)($binding['website_id'] ?? -1);
                $websiteCode = \strtolower(\trim((string)($binding['website_code'] ?? '')));
                if ($websiteId < 0 || $websiteCode === '') {
                    continue;
                }
                try {
                    $channel = ScopeIdentity::channel(
                        $websiteId,
                        $websiteCode,
                        'default',
                        'default',
                        ScopeIdentity::MODE_NORMAL,
                    );
                    foreach ($scopes->contextFromIdentity($channel)->fallbackStorageScopes as $storageScope) {
                        $push((string)$storageScope);
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @return list<string>
     */
    private function defaultStorefrontScopes(): array
    {
        return [
            'default.__store__.default',
            'default.__website__.default',
            'default.default.default',
        ];
    }
}
