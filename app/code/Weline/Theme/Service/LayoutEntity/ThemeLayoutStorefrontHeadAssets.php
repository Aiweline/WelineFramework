<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\ThemeScopeVersionService;

/**
 * Storefront head consumer for baked widget static asset manifests.
 */
final class ThemeLayoutStorefrontHeadAssets
{
    public const CTX_PTR = 'theme.layout_entity.page_assets_ptr.v1';

    public function __construct(
        private readonly ThemeLayoutEntityConfigStore $configStore,
        private readonly ThemeLayoutEntityAssetCollector $collector,
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ?ThemeScopeVersionService $scopeVersions = null,
    ) {
    }

    /**
     * @param array{theme_id:int,scope:string,identity_key:string,structure_or_release?:string,binding?:?EntityRenderBinding} $ptr
     */
    public static function rememberPointer(array $ptr): void
    {
        RequestContext::set(self::CTX_PTR, $ptr);
    }

    public function renderHtmlForCurrentRequest(): string
    {
        $previewContext = $this->previewContext();
        if ($previewContext !== null && (int)($previewContext['version_id'] ?? 0) > 0
            && !is_array(RequestContext::get('theme.layout_entity.preview_entity'))
        ) {
            $pageTypes = ObjectManager::getInstance(\Weline\Theme\Service\ThemePageTypeResolver::class);
            $target = (string)($previewContext['target_value'] ?? '/');
            $pageType = ($previewContext['target_type'] ?? '') === 'layout'
                ? $pageTypes->extractBaseLayoutType($target)
                : $pageTypes->resolvePageTypeFromUri($target);
            ObjectManager::getInstance(ThemeLayoutEntitySlotFiller::class)->resolveRequestedPreviewEntity(
                (int)$previewContext['frontend_theme_id'], $pageType, 'frontend',
            );
        }
        $ptr = RequestContext::get(self::CTX_PTR);
        $themeId = 0;
        $scope = '';
        $page = [];

        if (\is_array($ptr)) {
            $themeId = (int)($ptr['theme_id'] ?? 0);
            $scope = (string)($ptr['scope'] ?? '');
            if (($ptr['binding'] ?? null) instanceof EntityRenderBinding) {
                $page = $this->configStore->readBoundAssets($ptr['binding']);
            }
        }

        // Cart/login/CMS etc. may render head before SlotFiller remembers a page pointer.
        // Chrome assets are still required on every storefront shell.
        if ($themeId < 1 || $scope === '') {
            [$themeId, $scope] = $this->resolveActiveThemeScope();
        }
        if ($themeId < 1 || $scope === '') {
            return $page === [] ? '' : $this->mergeAndEmit([], $page);
        }

        $renderedAssets = $this->renderedChromeAssets($themeId);
        if ($renderedAssets !== null) {
            return $this->mergeAndEmit($renderedAssets, $page);
        }
        $chromeBinding = RequestContext::get('theme.layout_entity.rendered_chrome_binding');
        if ($chromeBinding instanceof EntityRenderBinding && $chromeBinding->identity->themeId === $themeId) {
            return $this->mergeAndEmit($this->configStore->readBoundAssets($chromeBinding), $page);
        }
        $selection = RequestContext::get('theme.layout_entity.preview_entity');
        if (is_array($selection) && (int)($selection['theme_id'] ?? 0) === $themeId && (int)($selection['chrome_version_id'] ?? 0) > 0) {
            $versions = $this->scopeVersions ?? ObjectManager::getInstance(ThemeScopeVersionService::class);
            $chromeScope = (string)($selection['chrome_scope'] ?? $selection['scope']);
            $version = $versions->getCurrent($themeId, $chromeScope)
                ?? $versions->getPublished($themeId, $chromeScope);
            if ($version !== null && $version->getVersionId() === (int)$selection['chrome_version_id']) {
                $identity = $version->toVersionIdentity()->withVersion(
                    $version->getVersionId(),
                    ThemeVersionIdentity::MODE_DRAFT,
                    \max(1, $version->getContentRevision()),
                );
                return $this->mergeAndEmit($this->configStore->readChromeAssets($identity), $page);
            }
        }
        $preview = $this->previewContext();
        $chrome = [];
        $versions = $this->scopeVersions ?? ObjectManager::getInstance(ThemeScopeVersionService::class);
        foreach ($this->chromeScopeCandidates($scope) as $candidateScope) {
            $published = $preview !== null && ($preview['status'] ?? '') !== 'published'
                ? $versions->getCurrent($themeId, $candidateScope)
                : $versions->getPublished($themeId, $candidateScope);
            if ($published === null) {
                continue;
            }
            $mode = $preview !== null && ($preview['status'] ?? '') !== 'published'
                ? ThemeVersionIdentity::MODE_DRAFT
                : ThemeVersionIdentity::MODE_FORMAL;
            $identity = $published->toVersionIdentity()->withVersion(
                $published->getVersionId(),
                $mode,
                \max(1, $published->getContentRevision()),
            );
            $candidate = $this->configStore->readChromeAssets($identity);
            $n = \count($candidate['layout_css'] ?? [])
                + \count($candidate['layout_js'] ?? [])
                + \count($candidate['source_css'] ?? [])
                + \count($candidate['source_js'] ?? []);
            if ($n < 1) {
                continue;
            }
            $chrome = $candidate;
            break;
        }

        return $this->mergeAndEmit($chrome, $page);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function renderedChromeAssets(int $themeId): ?array
    {
        $bindings = RequestContext::get('theme.layout_entity.rendered_chrome_bindings');
        if (!is_array($bindings) || $bindings === []) {
            return null;
        }
        $assets = null;
        foreach ($bindings as $binding) {
            if ($binding instanceof EntityRenderBinding && $binding->identity->themeId === $themeId) {
                $assets = $this->mergeManifests($assets ?? [], $this->configStore->readBoundAssets($binding));
            }
        }

        return $assets;
    }

    /**
     * @return list<string>
     */
    private function chromeScopeCandidates(string $scope): array
    {
        $scope = \trim($scope);
        $out = [];
        if ($scope !== '') {
            $out[] = $scope;
        }
        foreach ([
            'default.__website__.default',
            'default.default.default',
        ] as $fallback) {
            if (!\in_array($fallback, $out, true)) {
                $out[] = $fallback;
            }
        }

        return $out;
    }

    private function previewContext(): ?array
    {
        try {
            $service = ObjectManager::getInstance(\Weline\Theme\Service\PreviewContextService::class);
            if ($service->isEditorThemeRequest() || $service->hasAuthoritativePreviewContext()) {
                $context = $service->getCurrentContext();
                return (int)($context['frontend_theme_id'] ?? 0) > 0 ? $context : null;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function resolveActiveThemeScope(): array
    {
        $preview = $this->previewContext();
        if ($preview !== null && (int)($preview['frontend_theme_id'] ?? 0) > 0) {
            return [(int)$preview['frontend_theme_id'], (string)($preview['scope'] ?? 'default.__website__.default')];
        }
        $themeId = 0;
        $scope = '';
        try {
            if (RequestContext::isInitialized()) {
                $identity = RequestContext::scopeIdentity();
                if ($identity !== null) {
                    $scopes = ObjectManager::getInstance(\Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class);
                    $scope = (string)$scopes->contextFromIdentity($identity)->storageScope;
                }
            }
        } catch (\Throwable) {
        }
        if ($scope === '') {
            $scope = 'default.__website__.default';
        }
        try {
            $theme = ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
            $theme->clearData()->clearQuery()->getActiveTheme('frontend');
            $themeId = (int)$theme->getId();
        } catch (\Throwable) {
            $themeId = 0;
        }

        return [$themeId, $scope];
    }

    /**
     * @param array<string, mixed> $chrome
     * @param array<string, mixed> $page
     */
    public function mergeAndEmit(array $chrome, array $page): string
    {
        return $this->collector->emitHtml($this->mergeManifests($chrome, $page));
    }

    public function mergeManifests(array $chrome, array $page): array
    {
        $merged = [
            'layout_css' => $this->uniqueMerge($chrome['layout_css'] ?? [], $page['layout_css'] ?? []),
            'layout_js' => $this->uniqueMerge($chrome['layout_js'] ?? [], $page['layout_js'] ?? []),
            'source_css' => $this->uniqueMerge($chrome['source_css'] ?? [], $page['source_css'] ?? []),
            'source_js' => $this->uniqueMerge($chrome['source_js'] ?? [], $page['source_js'] ?? []),
            'source_positions' => $this->mergePositions($this->positionsForManifest($chrome), $this->positionsForManifest($page)),
        ];
        $layoutSeen = [];
        foreach (\array_merge($merged['layout_css'], $merged['layout_js']) as $p) {
            $layoutSeen[\strtolower((string)$p)] = true;
        }
        $merged['source_css'] = \array_values(\array_filter(
            $merged['source_css'],
            static fn(string $p): bool => !isset($layoutSeen[\strtolower($p)]),
        ));
        $merged['source_js'] = \array_values(\array_filter(
            $merged['source_js'],
            static fn(string $p): bool => !isset($layoutSeen[\strtolower($p)]),
        ));
        $merged['fp'] = \hash('sha256', \json_encode([
            $merged['layout_css'],
            $merged['layout_js'],
            $merged['source_css'],
            $merged['source_js'],
        ], JSON_UNESCAPED_SLASHES) ?: '');

        return $merged;
    }

    private function positionsForManifest(array $manifest): array
    {
        $positions = [];
        foreach (array_merge($manifest['source_css'] ?? [], $manifest['source_js'] ?? []) as $path) {
            $positions[$path] = $this->collector->normalizePosition((string)($manifest['source_positions'][$path] ?? 'head'));
        }

        return $positions;
    }

    private function mergePositions(array $a, array $b): array
    {
        $rank = ['head' => 0, 'footer' => 1, 'body' => 2];
        foreach ($b as $path => $position) {
            $position = $this->collector->normalizePosition((string)$position);
            if (!isset($a[$path]) || $rank[$position] < $rank[$this->collector->normalizePosition((string)$a[$path])]) {
                $a[$path] = $position;
            }
        }

        return $a;
    }

    private function uniqueMerge(array $a, array $b): array
    {
        $out = [];
        $seen = [];
        foreach (\array_merge($a, $b) as $item) {
            $s = \trim((string)$item);
            if ($s === '') {
                continue;
            }
            $k = \strtolower($s);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $s;
        }

        return $out;
    }
}
