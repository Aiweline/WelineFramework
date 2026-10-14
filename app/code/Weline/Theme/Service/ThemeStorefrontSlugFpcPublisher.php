<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeHierarchyInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Api\Scoped\ThemeEditorContext;

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
     * @param list<string> $slugs
     */
    public function emitUpsert(ThemeEditorContext $context, array $slugs, string $entry): void
    {
        $slugs = \array_values(\array_unique(\array_filter(\array_map(
            static fn(string $s): string => \ltrim(\trim($s), '/'),
            $slugs,
        ), static fn(string $s): bool => $s !== '')));
        if ($slugs === []) {
            return;
        }

        $themeId = \max(0, $context->themeId);
        $resourceId = $themeId > 0 ? (string)$themeId : ('scope:' . $context->scope->storageScope);
        if (\strlen($resourceId) > 191) {
            $resourceId = 'sha256:' . \hash('sha256', $resourceId);
        }
        $revision = ObjectManager::getInstance(ResourceRevisionService::class)->next('theme_layout', $resourceId);
        [$websiteId, $websiteCode, $storefrontOrigin] = $this->resolveWebsiteFace($context);
        $namespaces = [];
        if (\is_string($websiteCode) && $websiteCode !== '') {
            $namespaces = ['website/' . $websiteCode . '/theme', 'website/' . $websiteCode];
        }
        $relativeUrls = \array_map(static fn(string $s): string => '/' . $s, $slugs);
        $absoluteUrls = $this->absolutizeStorefrontUrls($relativeUrls, $storefrontOrigin);
        $urls = $absoluteUrls !== [] ? $absoluteUrls : $relativeUrls;

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
