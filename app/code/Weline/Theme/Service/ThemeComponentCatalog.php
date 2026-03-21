<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Model\WelineTheme;

class ThemeComponentCatalog
{
    private array $cache = [];

    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeDirectoryResolver $directoryResolver,
        private readonly ThemeFileComponentSource $fileSource,
        private readonly VirtualThemeComponentSource $virtualSource,
        private readonly WidgetRegistryComponentSource $widgetSource,
    ) {
    }

    /**
     * @return ThemeComponentDefinition[]
     */
    public function getDefinitions(string $area = 'frontend', ?WelineTheme $theme = null, bool $forceReload = false): array
    {
        $resolvedTheme = $this->resolveTheme($theme);
        $themeId = $resolvedTheme?->getId() ?: 0;
        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';
        $cacheKey = "{$themeId}:{$area}";
        if (!$forceReload && isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $definitions = [];
        $seen = [];
        $nativeThemeComponentCodes = [];

        foreach ($this->directoryResolver->getThemeChain($resolvedTheme) as $layerTheme) {
            foreach ($this->virtualSource->collect($area, $resolvedTheme, ['theme_id' => (int)$layerTheme->getId()]) as $definition) {
                $this->appendDefinition($definitions, $seen, $nativeThemeComponentCodes, $definition);
            }
            foreach ($this->fileSource->collect($area, $resolvedTheme, ['theme_id' => (int)$layerTheme->getId(), 'include_default' => false]) as $definition) {
                $this->appendDefinition($definitions, $seen, $nativeThemeComponentCodes, $definition);
            }
        }

        foreach ($this->fileSource->collect($area, $resolvedTheme, ['default_only' => true]) as $definition) {
            $this->appendDefinition($definitions, $seen, $nativeThemeComponentCodes, $definition);
        }

        foreach ($this->widgetSource->collect($area, $resolvedTheme, [
            'exclude_theme_component_codes' => array_keys($nativeThemeComponentCodes),
        ]) as $definition) {
            $this->appendDefinition($definitions, $seen, $nativeThemeComponentCodes, $definition);
        }

        $this->cache[$cacheKey] = array_values($definitions);
        return $this->cache[$cacheKey];
    }

    public function find(string $module, string $type, string $code, string $area = 'frontend', ?WelineTheme $theme = null): ?ThemeComponentDefinition
    {
        $identity = $this->buildIdentity($module, $type, $code);
        foreach ($this->getDefinitions($area, $theme) as $definition) {
            if ($definition->getIdentity() === $identity) {
                return $definition;
            }
        }

        return null;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    private function appendDefinition(array &$definitions, array &$seen, array &$nativeThemeComponentCodes, ThemeComponentDefinition $definition): void
    {
        $identity = $definition->getIdentity();
        if (isset($seen[$identity])) {
            return;
        }

        $definitions[$identity] = $definition;
        $seen[$identity] = true;

        if ($definition->module === 'Weline_Theme' && $definition->type === 'theme_component') {
            $nativeThemeComponentCodes[$definition->code] = true;
        }
    }

    private function resolveTheme(?WelineTheme $theme = null): ?WelineTheme
    {
        if ($theme && $theme->getId()) {
            return $theme;
        }

        $activeTheme = clone $this->welineTheme;
        $activeTheme->clearData()->clearQuery();
        $activeTheme->getActiveTheme();

        return $activeTheme->getId() ? $activeTheme : null;
    }

    private function buildIdentity(string $module, string $type, string $code): string
    {
        return "{$module}::{$type}::{$code}";
    }
}
