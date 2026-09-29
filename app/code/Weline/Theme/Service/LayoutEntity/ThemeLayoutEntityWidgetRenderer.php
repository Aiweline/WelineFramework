<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutValueHydrationRegistry;
use Weline\Theme\Service\ThemeComponentRenderer;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Taglib\Slot;

/**
 * Renders generated PHTML widget calls with their frozen configuration.
 *
 * renderResolved receives complete base and locale configurations plus instance
 * slots. The older render/renderBound entry points remain for compatibility.
 */
final class ThemeLayoutEntityWidgetRenderer
{
    public function __construct(
        private readonly ThemeLayoutEntityConfigStore $configStore,
        private readonly ThemePlaceableRegistry $placeableRegistry,
        private readonly ThemeComponentRenderer $componentRenderer,
    ) {
    }

    /** A generated call owns its complete configuration and its instance child slots. */
    public function renderResolved(array $entry, array $localeOverrides = [], array $slots = [], ?ThemeVersionIdentity $identity = null): string
    {
        if (\array_key_exists('is_active', $entry) && !$entry['is_active']) {
            return '';
        }
        $uid = (string)($entry['node_uid'] ?? '');
        $module = (string)($entry['widget_module'] ?? '');
        $type = (string)($entry['widget_type'] ?? '');
        $code = (string)($entry['widget_code'] ?? '');
        if ($code === '__no_widget_placements__') { return ''; }
        $area = $identity?->area ?? 'frontend';
        $theme = $identity !== null ? $this->themeForRequest($identity->themeId) : null;
        $definition = $this->placeableRegistry->find($module, $type, $code, $theme, $area);
        if ($definition === null) {
            return '<!-- theme-layout-entity:unknown-widget:' . \htmlspecialchars($module . '::' . $code, \ENT_QUOTES) . ' -->';
        }
        $config = \is_array($entry['config'] ?? null) ? $entry['config'] : [];
        $locale = \strtolower(\str_replace('-', '_', \Weline\Theme\Helper\WidgetI18n::storefrontLocale()));
        foreach ($localeOverrides as $candidate => $completeConfig) {
            if (\strtolower(\str_replace('-', '_', (string)$candidate)) === $locale && \is_array($completeConfig)) {
                $config = $completeConfig;
                break;
            }
        }
        foreach (['layout_source', 'source', 'source_position'] as $assetKey) {
            if (!empty($entry[$assetKey])) { $config['_' . $assetKey] = $entry[$assetKey]; }
        }
        $config = $this->hydrateTypedLayoutValues($config, $identity?->canonicalScope ?? ThemeContextService::DEFAULT_SCOPE);
        $config['_widget_instance_key'] = $uid;
        $config['node_uid'] = $config['_node_uid'] = $uid;
        $config['slot_id'] = $config['_slot_id'] = (string)($entry['slot_id'] ?? '');
        $config['_widget_module'] = $module;
        $config['_widget_type'] = $type;
        $config['_widget_code'] = $code;
        $config['_widget_area'] = $area;
        $config['children'] = ResolvedLayoutSlots::legacyChildren($slots);
        $context = ['area' => $area, 'block_class' => (string)($entry['block_class'] ?? ''), 'template_path' => (string)($entry['template_path'] ?? '')];
        $html = ResolvedLayoutSlots::with($slots, fn(): string => $this->componentRenderer->renderResolved($definition, $config, $theme, $context));
        $attributes = [
            'data-node-uid' => $uid,
            'data-widget-code' => $code,
            'data-widget-module' => $module,
            'data-widget-type' => $type,
            'data-widget-name' => $definition->name,
            'data-slot-id' => (string)($entry['slot_id'] ?? ''),
            'data-layout-option' => (string)($entry['layout_option'] ?? 'default'),
            'data-layout-scope' => (string)($entry['scope'] ?? $identity?->canonicalScope ?? ''),
            'data-target-type' => (string)($entry['target_type'] ?? 'global'),
            'data-target-id' => (string)($entry['target_id'] ?? ''),
        ];
        $attrs = '';
        foreach ($attributes as $name => $value) {
            if ($value !== '') { $attrs .= ' ' . $name . '="' . \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'; }
        }
        return '<div class="widget-wrapper"' . $attrs . '>' . $html . '</div>';
    }

    public static function requestNodeKey(string $uid, string $source, int $themeId, string $scope, string $version, string $locale): string
    {
        return 'theme.layout_entity.node.' . hash('sha256', json_encode([$uid, $source, $themeId, $scope, $version, $locale], JSON_THROW_ON_ERROR));
    }

    public function renderBound(string $nodeUid, string $source, EntityRenderBinding $binding): string
    {
        $source = $source === 'chrome' ? 'chrome' : 'page';
        // Page shell includeExecutable injects page binding into chrome.phtml; remap to chrome sidecar.
        $effective = $this->configStore->bindingForConfigSource($source, $binding) ?? $binding;

        return $this->render(
            $nodeUid,
            $source,
            $effective->identity->themeId,
            $effective->identity->scopeKey(),
            $effective->cacheKey(),
            $effective,
        );
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
        ?EntityRenderBinding $binding = null,
    ): string {
        $nodeUid = \strtolower(\trim($nodeUid));
        $configSource = $configSource === 'chrome' ? 'chrome' : 'page';
        if ($nodeUid === '' || $themeId < 1 || $scopeKey === '' || $versionKey === '') {
            return '<!-- theme-layout-entity:invalid-widget-call -->';
        }

        // Belt-and-suspenders with ThemeComponentRenderer: entity bake + required overlay
        // may render the same container twice in one request (product-info nested slots).
        Slot::clearRegisteredSlots();

        $effectiveBinding = $this->configStore->bindingForConfigSource($configSource, $binding);
        if ($effectiveBinding !== null) {
            $themeId = $effectiveBinding->identity->themeId;
            $scopeKey = $effectiveBinding->identity->scopeKey();
            $versionKey = $effectiveBinding->cacheKey();
        }

        $requestKey = self::requestNodeKey($nodeUid, $configSource, $themeId, $scopeKey, $versionKey, \Weline\Theme\Helper\WidgetI18n::storefrontLocale());
        $primed = RequestContext::get($requestKey);
        $entry = \is_array($primed) && $primed !== []
            ? $primed
            : ($effectiveBinding !== null
                ? ($this->configStore->readBoundConfig($effectiveBinding)[$nodeUid] ?? [])
                : $this->configStore->readNodeConfig(
                $configSource,
                $nodeUid,
                $themeId,
                $scopeKey,
                $versionKey,
            ));
        if ($entry === []) {
            return '<!-- theme-layout-entity:missing-config:' . \htmlspecialchars($nodeUid, \ENT_QUOTES) . ' -->';
        }

        // Primed page-config may be param-only (legacy bake). Hydrate identity from structure.
        if ($configSource === 'page' && $this->configStore->needsStructureHydration($entry)) {
            $entry = $this->configStore->hydratePageNodeFromStructure(
                $entry,
                $nodeUid,
                $effectiveBinding?->structurePath ?? '',
            );
            RequestContext::set($requestKey, $entry);
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

        foreach (['layout_source', 'source', 'source_position'] as $assetKey) {
            if (isset($entry[$assetKey]) && $entry[$assetKey] !== '') { $config['_' . $assetKey] = $entry[$assetKey]; }
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
        // Legacy render() compatibility leaves its supplied configuration intact.
        // Generated PHTML uses renderResolved() with explicit locale configurations.
        if (isset($entry['config']) && \is_array($entry['config'])) {
            return $entry;
        }

        return $entry;
    }
}
