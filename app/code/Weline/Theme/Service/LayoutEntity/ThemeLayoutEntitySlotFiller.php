<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Helper\WidgetI18n;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\SlotBoundaryScanner;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;
use Weline\Theme\Service\ThemeRuntimeLayoutResolver;
use Weline\Theme\Service\ThemeScopeVersionService;

/** Compatibility boundary: layout relationships are emitted only into PHTML at save time. */
final class ThemeLayoutEntitySlotFiller
{

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeLayoutEntityConfigStore $configStore,
        private readonly ThemeLayoutEntityChrome $chrome,
        private readonly SlotRendererService $slotRenderer,
        private readonly SlotBoundaryScanner $boundaryScanner,
        private readonly SharedChromeService $sharedChrome,
        private readonly ThemeRuntimeLayoutResolver $layoutResolver,
        private readonly ScopeHierarchyInterface $scopes,
        private readonly ?StorefrontScopeHotCache $hotCache = null,
    ) {  }

    public function renderPublishedSolidifiedFragments(
        int $themeId,
        string $pageType,
        string $area = 'frontend',
    ): ?array { return null; }

    public function fill(
        string $html,
        int $themeId,
        string $pageType,
        string $status = ThemeLayout::STATUS_PUBLISHED,
        string $area = 'frontend',
    ): string { return $html; }

    public function fillRequiredDefaultsOnShell(
        string $html,
        int $themeId,
        string $pageType,
        string $status,
        string $scope = 'default',
        ?array $slotAllowlist = null,
    ): string { return $html; }

    public function solidifyMissingPublishedPageIfNeeded(
        int $themeId,
        string $pageType,
        string $area = 'frontend',
    ): bool { return false; }

    public function finalizePublishedChromeRenderedHtml(string $html, int $themeId = 0, bool $structureComplete = false): string
    { return $html; }

    public function prefillPublishedChromeFromRenderedSnapshot(string $html, int $themeId): string
    { return $html; }

    public function healNarrowFilterPlaceholders(
        string $html,
        int $themeId,
        string $pageType,
        string $area = 'frontend',
    ): string { return $html; }

    public static function shouldPreferNarrowFilterHeal(string $html): bool
    { return false; }

    public function fillBlankNestedChromeExtensions(string $html, int $themeId): string
    { return $html; }

    public function healPublishedPlaceholderShell(
        string $html,
        int $themeId,
        string $pageType,
        string $area = 'frontend',
    ): string { return $html; }

    public function fillChromeOnly(
        string $html,
        int $themeId,
        string $area = 'frontend',
        bool $preview = false,
    ): string { return $html; }

    public function splicePublishedChromeFromDisk(string $html, int $themeId): string
    { return $html; }

    public function primePublishedChromeSlotProjectionHotCache(int $themeId, ?string $scope = null): bool
    { return false; }

    public function rememberHonestEmptyChromeSlotProjection(int $themeId, ?string $scope = null): bool
    { return false; }

    public function resolveRequestedPreviewEntity(int $themeId, string $pageType, string $area): ?array
    {
        $service = ObjectManager::getInstance(\Weline\Theme\Service\PreviewContextService::class);
        if (!$service->isEditorThemeRequest() && !$service->hasAuthoritativePreviewContext()) {
            return null;
        }
        $cursor = $service->getCurrentContext();
        $storageScope = (string)($cursor['canonical_scope'] ?? $cursor['scope'] ?? 'default');
        $storeMode = (string)($cursor['store_mode'] ?? 'normal');
        $versionId = (int)($cursor['theme_version_id'] ?? $cursor['version_id'] ?? 0);
        // Editor iframe URLs often omit version_id; PreviewContextService then clears
        // the sticky session version. Fall back to the owner's current draft selection
        // so the canvas keeps rendering the workspace draft after reload.
        if ($versionId < 1) {
            $current = ObjectManager::getInstance(ThemeScopeVersionService::class)
                ->getCurrent($themeId, $storageScope, $storeMode, $area);
            if ($current === null || (int)$current->getVersionId() < 1) {
                return null;
            }
            $versionId = (int)$current->getVersionId();
            $cursor['theme_version_id'] = $versionId;
            $cursor['version_id'] = $versionId;
            $cursor['mode'] = trim((string)($cursor['mode'] ?? '')) !== ''
                ? (string)$cursor['mode']
                : \Weline\Theme\Api\Version\ThemeVersionIdentity::MODE_DRAFT;
            $cursor['content_revision'] = (int)($cursor['content_revision'] ?? 0) > 0
                ? (int)$cursor['content_revision']
                : max(1, (int)$current->getContentRevision());
            $cursor['canonical_scope'] = $storageScope;
            $cursor['store_mode'] = $storeMode;
        }
        $locale = trim((string)($cursor['locale'] ?? $cursor['locale_code'] ?? ''));
        if ($locale === '' || strcasecmp($locale, 'default') === 0) {
            $locale = trim((string)(
                \Weline\Framework\Runtime\ThemeApplicationContext::current($area, 'preview')?->defaultLocale
                ?? \Weline\Framework\Runtime\ThemeApplicationContext::current($area, 'editor')?->defaultLocale
                ?? \Weline\Framework\Runtime\ThemeApplicationContext::current($area, 'runtime')?->defaultLocale
                ?? 'zh_Hans_CN'
            ));
        }
        // Pin the editor cursor owner explicitly. Otherwise buildContext() may walk
        // ThemeContentScope up to application.versionOwnerScope (often global) and
        // the canvas silently renders a different owner's draft/published payload.
        $identity = [
            'scope' => $storageScope,
            'store_mode' => $storeMode,
            'layout_option' => $cursor['layout_option'] ?? 'default',
            'target_type' => $cursor['target_type'] ?? 'global',
            'target_id' => $cursor['target_id'] ?? 0,
            'content_scope' => \Weline\Theme\Api\Scoped\ThemeContentScope::fromStoredOwner(
                $storageScope,
                $storeMode,
                $locale !== '' ? $locale : 'zh_Hans_CN',
            ),
            // purpose selects ThemeApplicationContext::current($area, $purpose).
            'purpose' => 'runtime',
        ];
        $resolved = ObjectManager::getInstance(\Weline\Theme\Service\ThemeVersionPreviewResolver::class)
            ->resolve($themeId, $pageType, $area, $identity, $versionId, $cursor);
        if (empty($resolved['resolved'])) {
            throw new \RuntimeException((string)$resolved['reason']);
        }
        RequestContext::set('theme.layout_entity.preview_entity', $resolved);

        return $resolved;
    }

    public function purgePublishedPageEntityLocationCaches(int $themeId, string $scope): void
    {  }
}
