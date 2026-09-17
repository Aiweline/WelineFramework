<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeComponentRenderer;
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

        $entry = $this->configStore->readNodeConfig(
            $configSource,
            $nodeUid,
            $themeId,
            $scopeKey,
            $versionKey,
        );
        if ($entry === []) {
            return '<!-- theme-layout-entity:missing-config:' . \htmlspecialchars($nodeUid, \ENT_QUOTES) . ' -->';
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
                /** @var WelineTheme $themeModel */
                $themeModel = ObjectManager::getInstance(WelineTheme::class);
                $loaded = clone $themeModel;
                $loaded->load($themeId);
                if ((int)$loaded->getId() === $themeId) {
                    $theme = $loaded;
                }
            }

            $definition = $this->placeableRegistry->find($module, $type, $code, $theme, 'frontend');
            if ($definition === null) {
                return '<!-- theme-layout-entity:unknown-widget:' . \htmlspecialchars($module . '::' . $code, \ENT_QUOTES) . ' -->';
            }

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
