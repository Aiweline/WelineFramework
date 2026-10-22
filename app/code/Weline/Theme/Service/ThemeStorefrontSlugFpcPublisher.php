<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Event\ResourceRevision;
use Weline\Framework\Runtime\ScopeHierarchyInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Helper\ThemeData;

/**
 * Emit theme_layout Changed with impact.slugs for storefront FPC purge (no publish/version).
 */
final class ThemeStorefrontSlugFpcPublisher
{
    /**
     * Resolve storefront slug for a layout identity (no leading slash). Empty = homepage / non-storefront.
     */
    public function resolveSlug(string $layoutType, string $layoutOption = 'default'): string
    {
        try {
            /** @var ThemePageTypeResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);
            $path = \trim((string)$resolver->getPreviewPathByPageType($layoutType, $layoutOption ?: 'default'));
        } catch (\Throwable) {
            return '';
        }
        if ($path === '' || $path === '/') {
            return '';
        }

        return \ltrim($path, '/');
    }

    public function isActiveStorefrontSlug(string $layoutType, string $layoutOption = 'default'): bool
    {
        return $this->resolveSlug($layoutType, $layoutOption) !== '';
    }

    /**
     * Upsert-only FPC purge for the current layout slug (更新当前 / 活动页保存).
     * Does not create a theme/layout publish version.
     *
     * @return array{ok:bool,slug:string,skipped:?string}
     */
    public function purgeCurrentLayout(
        ThemeEditorContext $context,
        string $entry = 'theme_editor_update_current',
    ): array {
        $slug = $this->resolveSlug((string)$context->layoutType, (string)($context->layoutOption ?: 'default'));
        if ($slug === '') {
            return ['ok' => false, 'slug' => '', 'skipped' => 'no_storefront_slug'];
        }
        $this->emitUpsert($context, [$slug], $entry);

        return ['ok' => true, 'slug' => $slug, 'skipped' => null];
    }

    /**
     * Storefront thin entry: path/slug → impact.slugs purge only.
     * Unrecognized path → fail closed; never full-site FPC purge.
     *
     * @return array{ok:bool,slug:string,skipped:?string}
     */
    public function purgeByStorefrontPath(
        string $requestPath,
        string $entry = 'i18n_inline_update_current_page',
    ): array {
        $raw = \trim($requestPath);
        if ($raw === '') {
            return ['ok' => false, 'slug' => '', 'skipped' => 'no_storefront_slug'];
        }
        $path = \parse_url($raw, \PHP_URL_PATH);
        if (!\is_string($path) || $path === '') {
            // Relative path without scheme.
            $path = \str_contains($raw, '?') ? (string)\strtok($raw, '?') : $raw;
        }
        $slug = \ltrim($path, '/');
        // Homepage `/` has empty slug; still purge via impact.urls `/`.
        $isHomepage = ($slug === '' && ($path === '/' || $path === ''));
        if (!$isHomepage && ($slug === '' || \str_contains($slug, '..') || \str_starts_with($slug, '~'))) {
            return ['ok' => false, 'slug' => '', 'skipped' => 'path_not_recognized'];
        }
        if ($isHomepage) {
            $slug = '/';
        }

        $app = ThemeApplicationContext::current('frontend', 'runtime')
            ?? ThemeApplicationContext::current('frontend');
        if ($app === null) {
            return ['ok' => false, 'slug' => '', 'skipped' => 'no_theme_context'];
        }
        $themeId = 0;
        try {
            $themeId = (int)(ThemeData::getCurrentTheme()?->getId() ?? 0);
        } catch (\Throwable) {
            $themeId = 0;
        }
        if ($themeId <= 0) {
            $themeId = (int)($app->themeId ?? 0);
        }
        if ($themeId <= 0) {
            return ['ok' => false, 'slug' => '', 'skipped' => 'no_theme'];
        }

        try {
            $scope = ThemeContentScope::fromApplication($app);
            $context = new ThemeEditorContext(
                scope: $scope,
                area: 'frontend',
                resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
                themeId: $themeId,
                layoutType: 'default',
                layoutOption: 'default',
                application: $app,
            );
        } catch (\Throwable) {
            return ['ok' => false, 'slug' => '', 'skipped' => 'theme_context_invalid'];
        }

        $this->emitUpsert($context, [$slug], $entry);

        return ['ok' => true, 'slug' => $slug, 'skipped' => null];
    }

    /**
     * Normalize storefront paths/urls into bounded, safe slugs.
     *
     * @return array{slugs:list<string>,homepage:bool,rejected:list<string>,truncated:int}
     */
    public static function normalizeStorefrontPurgePaths(array $pathsOrUrls): array
    {
        $slugs = [];
        $seen = [];
        $rejected = [];
        $homepage = false;
        $truncated = 0;
        foreach ($pathsOrUrls as $raw) {
            $raw = \trim((string)$raw);
            if ($raw === '') {
                $rejected[] = (string)$raw;
                continue;
            }
            if ($raw === '/') {
                $homepage = true;
                continue;
            }
            $path = \parse_url($raw, \PHP_URL_PATH);
            if (!\is_string($path) || $path === '') {
                $path = (string)\strtok($raw, '?');
                $path = (string)\strtok($path, '#');
            }
            $slug = \ltrim((string)$path, '/');
            if ($slug === '' || \str_contains($slug, '..') || \str_starts_with($slug, '~')) {
                $rejected[] = $raw;
                continue;
            }
            if (isset($seen[$slug])) {
                continue;
            }
            if (\count($slugs) >= 200) {
                $truncated += 1;
                continue;
            }
            $seen[$slug] = true;
            $slugs[] = $slug;
        }
        return ['slugs' => $slugs, 'homepage' => $homepage, 'rejected' => $rejected, 'truncated' => $truncated];
    }

    /**
     * Path-level FPC/CDN purge entry for declared storefront paths.
     *
     * @param list<array{version_owner_scope?:string,store_mode?:string,area?:string}> $targets
     * @return array{ok:bool,path_count:int,slug_count:int,target_count:int,truncated:int,rejected:list<string>,skipped:?string}
     */
    public function purgeStorefrontPaths(
        int $themeId,
        array $pathsOrUrls,
        array $targets,
        string $entry = 'theme_static_path_purge',
    ): array {
        $base = ['path_count' => 0, 'slug_count' => 0, 'target_count' => 0, 'truncated' => 0, 'rejected' => []];
        if ($themeId < 1) {
            return ['ok' => false] + $base + ['skipped' => 'no_theme'];
        }
        $normalized = self::normalizeStorefrontPurgePaths($pathsOrUrls);
        $rejected = $normalized['rejected'];
        $truncated = $normalized['truncated'];
        $slugs = $normalized['slugs'];
        if ($normalized['homepage']) {
            $slugs = \array_merge(['/'], $slugs);
        }
        if ($slugs === []) {
            return ['ok' => false, 'path_count' => 0, 'slug_count' => 0, 'target_count' => 0, 'truncated' => $truncated, 'rejected' => $rejected, 'skipped' => 'no_paths'];
        }
        $slugList = array_values(array_unique($slugs));
        $emitted = 0;
        $seenScopes = [];
        foreach ($targets as $target) {
            if (!\is_array($target)) {
                continue;
            }
            $area = \trim((string)($target['area'] ?? ''));
            if ($area !== 'frontend') {
                continue;
            }
            $ownerScope = \trim((string)($target['version_owner_scope'] ?? ''));
            if ($ownerScope === '') {
                continue;
            }
            $storeMode = \trim((string)($target['store_mode'] ?? 'normal'));
            if ($storeMode === '') {
                $storeMode = 'normal';
            }
            $scopeKey = $ownerScope . '|' . $storeMode;
            if (isset($seenScopes[$scopeKey])) {
                continue;
            }
            $seenScopes[$scopeKey] = true;
            try {
                $scope = ThemeContentScope::fromStoredOwner($ownerScope, $storeMode, 'default');
                $context = new ThemeEditorContext(
                    scope: $scope,
                    area: 'frontend',
                    resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
                    themeId: $themeId,
                    layoutType: 'default',
                    layoutOption: 'default',
                );
                $this->emitUpsert($context, $slugList, $entry);
                ++$emitted;
            } catch (\Throwable) {
                continue;
            }
        }
        return [
            'ok' => $emitted > 0,
            'path_count' => count($slugList),
            'slug_count' => count($slugList),
            'target_count' => $emitted,
            'truncated' => $truncated,
            'rejected' => $rejected,
            'skipped' => $emitted > 0 ? null : 'no_frontend_target',
        ];
    }

    /**
     * Origin-watch / layout bake: purge only the storefront FPC leaves for these layouts.
     * Never rotates html-disk generations / never clearAllThemeRelatedCaches.
     *
     * @param list<array{type:string,option?:string}> $layouts
     * @param list<array{
     *   version_owner_scope?:string,
     *   store_mode?:string,
     *   area?:string
     * }> $targets Application rows that own this theme (frontend preferred).
     * @return array{ok:bool,slug_count:int,target_count:int,skipped:?string}
     */
    public function purgeLayoutsForThemeTargets(
        int $themeId,
        array $layouts,
        array $targets,
        string $entry = 'theme_layout_origin_watch',
    ): array {
        if ($themeId < 1) {
            return ['ok' => false, 'slug_count' => 0, 'target_count' => 0, 'skipped' => 'no_theme'];
        }
        $slugs = [];
        $includeHomepage = false;
        foreach ($layouts as $layout) {
            if (!\is_array($layout)) {
                continue;
            }
            $type = \trim((string)($layout['type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $option = \trim((string)($layout['option'] ?? 'default'));
            if ($option === '' || $option === '*') {
                $option = 'default';
            }
            $slug = $this->resolveSlug($type, $option);
            if ($slug === '') {
                $includeHomepage = true;
                continue;
            }
            $slugs[] = $slug;
        }
        $slugs = \array_values(\array_unique($slugs));
        if ($slugs === [] && !$includeHomepage) {
            return ['ok' => false, 'slug_count' => 0, 'target_count' => 0, 'skipped' => 'no_storefront_slug'];
        }
        if ($includeHomepage) {
            $slugs[] = '/';
            $slugs = \array_values(\array_unique($slugs));
        }

        $emitted = 0;
        $seenScopes = [];
        foreach ($targets as $target) {
            if (!\is_array($target)) {
                continue;
            }
            $area = \trim((string)($target['area'] ?? 'frontend'));
            if ($area !== 'frontend') {
                // Storefront FPC is frontend-only; backend layouts do not map to html-disk.
                continue;
            }
            $ownerScope = \trim((string)($target['version_owner_scope'] ?? ''));
            $storeMode = \trim((string)($target['store_mode'] ?? 'normal'));
            if ($ownerScope === '') {
                continue;
            }
            if ($storeMode === '') {
                $storeMode = 'normal';
            }
            $scopeKey = $ownerScope . '|' . $storeMode;
            if (isset($seenScopes[$scopeKey])) {
                continue;
            }
            $seenScopes[$scopeKey] = true;
            try {
                $scope = ThemeContentScope::fromStoredOwner($ownerScope, $storeMode, 'default');
                $context = new ThemeEditorContext(
                    scope: $scope,
                    area: 'frontend',
                    resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
                    themeId: $themeId,
                    layoutType: 'default',
                    layoutOption: 'default',
                );
                $this->emitUpsert($context, $slugs, $entry);
                ++$emitted;
            } catch (\Throwable) {
                continue;
            }
        }
        if ($emitted < 1) {
            return ['ok' => false, 'slug_count' => \count($slugs), 'target_count' => 0, 'skipped' => 'no_frontend_target'];
        }

        return [
            'ok' => true,
            'slug_count' => \count($slugs),
            'target_count' => $emitted,
            'skipped' => null,
        ];
    }

    /**
     * @param list<string> $slugs
     */
    public function emitUpsert(ThemeEditorContext $context, array $slugs, string $entry): void
    {
        $includeHomepage = false;
        $normalized = [];
        foreach ($slugs as $raw) {
            $raw = \trim((string)$raw);
            if ($raw === '' || $raw === '/') {
                $includeHomepage = true;
                continue;
            }
            $s = \ltrim($raw, '/');
            if ($s === '' || \str_contains($s, '..') || \str_starts_with($s, '~')) {
                continue;
            }
            $normalized[] = $s;
        }
        $slugs = \array_values(\array_unique($normalized));
        if ($slugs === [] && !$includeHomepage) {
            return;
        }

        $themeId = \max(0, $context->themeId);
        $resourceId = $themeId > 0 ? (string)$themeId : ('scope:' . $context->scope->storageScope);
        if (\strlen($resourceId) > 191) {
            $resourceId = 'sha256:' . \hash('sha256', $resourceId);
        }
        [$websiteId, $websiteCode, $storefrontOrigin] = $this->resolveWebsiteFace($context);
        $namespaces = [];
        if (\is_string($websiteCode) && $websiteCode !== '') {
            $namespaces = ['website/' . $websiteCode . '/theme', 'website/' . $websiteCode];
        }
        $relativeUrls = \array_map(static fn(string $s): string => '/' . $s, $slugs);
        if ($includeHomepage) {
            \array_unshift($relativeUrls, '/');
            $relativeUrls = \array_values(\array_unique($relativeUrls));
        }
        $absoluteUrls = $this->absolutizeStorefrontUrls($relativeUrls, $storefrontOrigin);
        $urls = $absoluteUrls !== [] ? $absoluteUrls : $relativeUrls;

        $emit = static function () use (
            $context,
            $resourceId,
            $themeId,
            $websiteId,
            $websiteCode,
            $namespaces,
            $urls,
            $slugs,
            $entry,
        ): void {
            $revision = ObjectManager::getInstance(ResourceRevisionService::class)
                ->next('theme_layout', $resourceId);
            $change = ObjectManager::getInstance(ResourceChangeFactory::class)->create(
                resourceType: 'theme_layout',
                resourceId: $resourceId,
                action: 'upsert',
                revision: $revision,
                websiteId: $websiteId,
                websiteCode: $websiteCode,
                before: [],
                after: [
                    'theme_id' => $themeId,
                    'layout_type' => $context->layoutType,
                    'layout_option' => $context->layoutOption,
                    'area' => $context->area,
                    'storage_scope' => $context->scope->storageScope,
                    'purge_current' => true,
                    // Not draft preview — must purge production FPC for declared slugs.
                    'status' => 'published',
                ],
                changedFields: ['storefront_slug_fpc'],
                impact: [
                    'namespaces' => $namespaces,
                    'urls' => $urls,
                    'slugs' => $slugs,
                ],
                origin: ['entry' => $entry],
                siteId: null,
                resourceScope: $context->scope->storageScope,
            );
            \w_changed($change);
        };

        // ResourceRevisionService::next requires sole active Framework txn
        // (same contract as I18nResourceChangePublisher / ThemeScopedWorkspace).
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        $connection = ObjectManager::getInstance(ResourceRevision::class)->getConnection();
        if ($transactions->isActive($connection)) {
            $emit();
        } else {
            $transactions->run($connection, $emit);
        }
    }

    /**
     * @return array{0:?int,1:?string,2:string}
     */
    private function resolveWebsiteFace(ThemeEditorContext $context): array
    {
        $websiteId = null;
        $websiteCode = null;
        $origin = '';
        try {
            $scopeContext = ObjectManager::getInstance(ScopeHierarchyInterface::class)
                ->fromStorageScope($context->scope->storageScope, true);
            $identity = $scopeContext->identity ?? null;
            if ($identity instanceof ScopeIdentity && $identity->websiteId !== null) {
                $websiteId = (int)$identity->websiteId;
                $websiteCode = $identity->websiteCode !== null ? (string)$identity->websiteCode : null;
            }
        } catch (\Throwable) {
        }
        if ($websiteId !== null) {
            try {
                if (\class_exists(InstallLocalStorefrontBaseResolver::class)) {
                    $resolver = ObjectManager::getInstance(InstallLocalStorefrontBaseResolver::class);
                    $origin = \trim((string)($resolver->resolveForWebsite(
                        $websiteId,
                        (string)($websiteCode ?? '')
                    ) ?? ''));
                }
            } catch (\Throwable) {
                $origin = '';
            }
        }

        return [$websiteId, $websiteCode, \rtrim($origin, '/')];
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function absolutizeStorefrontUrls(array $urls, string $origin): array
    {
        $origin = \rtrim(\trim($origin), '/');
        if ($origin === '' || \preg_match('#^https?://#i', $origin) !== 1) {
            return [];
        }
        $out = [];
        foreach ($urls as $url) {
            $url = \trim((string)$url);
            if ($url === '') {
                continue;
            }
            if (\preg_match('#^https?://#i', $url) === 1) {
                $out[] = $url;
                continue;
            }
            $out[] = $origin . '/' . \ltrim($url, '/');
        }

        return \array_values(\array_unique($out));
    }
}
