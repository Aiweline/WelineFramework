<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Framework\Runtime\ProcessSharedInterface;

/**
 * Resolve the published ThemeScopeVersion (theme version V) for storefront runtime tags.
 *
 * Authority is ThemeScopeVersion selection / is_published — not ThemeLayoutVersion page axis.
 * Storefront generation / Worker identity is this Scope-published version id — not system deploy_version.
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
        ];

        try {
            if ($themeId === null || $themeId <= 0) {
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

                    return [
                        'themePublishedVersionId' => (string)$published->getVersionId(),
                        'themePublishedVersion' => $name,
                        'themeId' => $candidateThemeId,
                        'canonicalScope' => (string)$scope,
                    ];
                }
            }
        } catch (\Throwable) {
            return $empty;
        }

        return $empty;
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
     * Accept any sealed published id reachable from Env theme + frontend-bound themes
     * across storefront scope probes (Channel fallback chain included).
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
                    $ids[(string)$published->getVersionId()] = true;
                }
            }
        }

        return $ids;
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
