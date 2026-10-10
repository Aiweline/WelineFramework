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
        if ($definition === null && $module !== '' && $code !== '') {
            // Published entities may keep a stale widget_type after @widget.type moves
            // (order-notice: form → content). Fall back by module+code so note/coupon/credit
            // slots do not collapse to empty HTML comments.
            $definition = $this->placeableRegistry->findByModuleCode($module, $code, $theme, $area);
            if ($definition !== null) {
                $type = (string)$definition->type;
            }
        }
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
        $layoutOption = \trim((string)($entry['layout_option'] ?? 'default'));
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }
        $layoutType = \trim((string)($entry['layout_type'] ?? $entry['page_type'] ?? ''));
        $layoutName = \Weline\Widget\Cache\WidgetOutputCache::resolveLayoutName([
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'layout_name' => (string)($entry['layout_name'] ?? ''),
        ], $config);
        $config['_layout_option'] = $config['layout_option'] = $layoutOption;
        if ($layoutType !== '') {
            $config['_layout_type'] = $config['layout_type'] = $layoutType;
        }
        if ($layoutName !== '') {
            $config['_layout_name'] = $config['layout_name'] = $layoutName;
        }
        $config['children'] = ResolvedLayoutSlots::legacyChildren($slots);
        $instanceTtl = \Weline\Widget\Cache\WidgetOutputCache::normalizeTtl(
            $entry['cache'] ?? $config['_cache'] ?? $config['cache'] ?? null,
        );
        if ($instanceTtl > 0) {
            $config['_cache'] = $instanceTtl;
        }
        $share = null;
        if (\array_key_exists('share', $entry)) {
            $share = \Weline\Widget\Cache\WidgetOutputCache::normalizeShare($entry['share']);
        } elseif (\array_key_exists('share', $config) || \array_key_exists('_share', $config) || \array_key_exists('_cache_share', $config)) {
            $share = \Weline\Widget\Cache\WidgetOutputCache::normalizeShare(
                $config['_cache_share'] ?? $config['_share'] ?? $config['share'] ?? false,
            );
        } elseif (\Weline\Widget\Cache\WidgetOutputCache::normalizeShare($definition->meta['share'] ?? null)) {
            $share = true;
        }
        if ($share === true) {
            $config['_share'] = $config['share'] = true;
        }
        $context = [
            'area' => $area,
            'block_class' => (string)($entry['block_class'] ?? ''),
            'template_path' => (string)($entry['template_path'] ?? ''),
            'cache' => $instanceTtl,
            'share' => $share,
        ];
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

    /** @deprecated Sidecar calls are retired; regenerated PHTML uses renderResolved. */
    public function renderBound(string $nodeUid, string $source, EntityRenderBinding $binding): string
    {
        return '';
    }

    /** @deprecated Sidecar calls are retired; regenerated PHTML uses renderResolved. */
    public function render(
        string $nodeUid,
        string $configSource,
        int $themeId,
        string $scopeKey,
        string $versionKey,
        ?EntityRenderBinding $binding = null,
    ): string {
        return '';
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

}
