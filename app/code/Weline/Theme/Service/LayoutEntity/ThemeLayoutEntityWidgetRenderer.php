<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutValueHydrationRegistry;
use Weline\Theme\Service\ThemeComponentRenderer;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
use Weline\Theme\Service\ThemePlaceableRegistry;

/**
 * Runtime widget render for baked entity phtml call sites.
 *
 * Loads node config from sidecar; optionally applies a light locale overlay when
 * ThemeScopedPreviewResolver is available. Falls back to an HTML comment on miss.
 */
final class ThemeLayoutEntityWidgetRenderer
{
    public function __construct(
        private readonly ThemeLayoutEntityConfigStore $configStore,
        private readonly ThemePlaceableRegistry $placeableRegistry,
        private readonly ThemeComponentRenderer $componentRenderer,
    ) {
    }

    /**
     * @param string $configSource page|chrome
     * @param string $versionKey chrome: theme_version_id; page: identity_key/structure_or_release
     */
    public function render(
        string $nodeUid,
        string $configSource,
        int $themeId,
        string $scopeKey,
        string $versionKey,
    ): string {
        $nodeUid = \strtolower(\trim($nodeUid));
        $configSource = $configSource === 'chrome' ? 'chrome' : 'page';
        if ($nodeUid === '' || $themeId < 1 || $scopeKey === '' || $versionKey === '') {
            return '<!-- theme-layout-entity:invalid-widget-call -->';
        }

        $primed = RequestContext::get('theme.layout_entity.node.' . $nodeUid);
        $entry = \is_array($primed) && $primed !== []
            ? $primed
            : $this->configStore->readNodeConfig(
                $configSource,
                $nodeUid,
                $themeId,
                $scopeKey,
                $versionKey,
            );
        if ($entry === []) {
            return '<!-- theme-layout-entity:missing-config:' . \htmlspecialchars($nodeUid, \ENT_QUOTES) . ' -->';
        }

        // Primed page-config may be param-only (legacy bake). Hydrate identity from structure.
        if ($configSource === 'page' && $this->configStore->needsStructureHydration($entry)) {
            $entry = $this->configStore->hydratePageNodeFromStructure(
                $entry,
                $nodeUid,
                $themeId,
                $scopeKey,
                $versionKey,
            );
            RequestContext::set('theme.layout_entity.node.' . $nodeUid, $entry);
        }

        if (\array_key_exists('is_active', $entry) && empty($entry['is_active'])) {
            return '';
        }

        $entry = $this->maybeApplyLocaleOverlay($entry);

        $module = (string)($entry['widget_module'] ?? '');
        $code = (string)($entry['widget_code'] ?? '');
        $type = (string)($entry['widget_type'] ?? '');
        $config = \is_array($entry['config'] ?? null) ? $entry['config'] : $entry;
        if (isset($config['config']) && \is_array($config['config'])) {
            $config = $config['config'];
        }

        if ($module === '' || $code === '') {
            return '<!-- theme-layout-entity:incomplete-widget:' . \htmlspecialchars($nodeUid, \ENT_QUOTES) . ' -->';
        }

        try {
            $theme = null;
            if ($themeId > 0) {
                $theme = $this->themeForRequest($themeId);
            }

            $definition = $this->placeableRegistry->find($module, $type, $code, $theme, 'frontend');
            if ($definition === null) {
                return '<!-- theme-layout-entity:unknown-widget:' . \htmlspecialchars($module . '::' . $code, \ENT_QUOTES) . ' -->';
            }

            // Entity hard-cut must hydrate file-image (and companions like
            // image_file_html) the same way SlotRendererService does — otherwise
            // hero/promo keep typed JSON but render gradient-only slides.
            $config = $this->hydrateTypedLayoutValues($config, $scopeKey);
            $config['_widget_instance_key'] = $nodeUid;
            $html = (string)$this->componentRenderer->render($definition, $config, $theme, [
                'area' => 'frontend',
            ]);

            return $html;
        } catch (\Throwable $e) {
            return '<!-- theme-layout-entity:render-error:'
                . \htmlspecialchars($nodeUid . ':' . $e->getMessage(), \ENT_QUOTES)
                . ' -->';
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function hydrateTypedLayoutValues(array $config, string $scopeKey): array
    {
        try {
            /** @var LayoutValueHydrationRegistry $registry */
            $registry = ObjectManager::getInstance(LayoutValueHydrationRegistry::class);
            $scope = RequestContext::scopeIdentity();
            if ($scope === null) {
                $encoded = \trim($scopeKey) !== '' ? \trim($scopeKey) : ThemeContextService::DEFAULT_SCOPE;
                $scope = ObjectManager::getInstance(ThemeLayoutScopeNormalizer::class)
                    ->identityFromEncodedScope($encoded);
            }

            return $registry->hydrate($config, [
                'scope_identity' => $scope,
                // Empty locale: FileImage hydrator follows each usage.locale_code.
                'locale_code' => '',
                'actor_id' => null,
                'roles' => [],
                'purpose' => 'render',
                'policy_revision' => 1,
            ]);
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning(
                    '[ThemeLayoutEntityWidgetRenderer] typed hydrate soft-skip: ' . $e->getMessage(),
                    ['scope' => $scopeKey],
                    'theme_layout_entity',
                );
            }

            return $config;
        }
    }

    private function themeForRequest(int $themeId): ?WelineTheme
    {
        $cacheKey = 'theme.layout_entity.theme.' . $themeId;
        $cached = RequestContext::get($cacheKey);
        if ($cached instanceof WelineTheme) {
            return clone $cached;
        }

        /** @var WelineTheme $themeModel */
        $themeModel = ObjectManager::getInstance(WelineTheme::class);
        $loaded = clone $themeModel;
        $loaded->load($themeId);
        if ((int)$loaded->getId() !== $themeId) {
            return null;
        }

        RequestContext::set($cacheKey, $loaded);

        return clone $loaded;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function maybeApplyLocaleOverlay(array $entry): array
    {
        // Entity phtml call sites are rare; storefront slot fill applies full
        // ThemeRuntimeLayoutResolver::overlayLocaleOnLayout before processSlotsWithLayout.
        // Keep entry as baked when no per-locale sidecar map is present.
        if (isset($entry['config']) && \is_array($entry['config'])) {
            return $entry;
        }

        return $entry;
    }
}
