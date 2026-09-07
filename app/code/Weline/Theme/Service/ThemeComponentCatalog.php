<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeSlotDefinition;
use Weline\Theme\Model\WelineTheme;

class ThemeComponentCatalog
{
    private array $cache = [];
    /** @var array<string, array<string, ThemeComponentDefinition>> */
    private array $identityIndex = [];

    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeDirectoryResolver $directoryResolver,
        private readonly ThemeFileComponentSource $fileSource,
        private readonly VirtualThemeComponentSource $virtualSource,
        private readonly WidgetRegistryComponentSource $widgetSource,
        private readonly ThemeContextService $themeContextService,
    ) {
    }

    /**
     * @return ThemeComponentDefinition[]
     */
    public function getDefinitions(string $area = 'frontend', ?WelineTheme $theme = null, bool $forceReload = false): array
    {
        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';
        $resolvedTheme = $this->resolveTheme($area, $theme);
        return $this->getDefinitionsForResolvedTheme($area, $resolvedTheme, $forceReload);
    }

    /**
     * @return ThemeComponentDefinition[]
     */
    private function getDefinitionsForResolvedTheme(string $area, ?WelineTheme $resolvedTheme, bool $forceReload = false): array
    {
        $themeId = $resolvedTheme?->getId() ?: 0;
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
        $this->identityIndex[$cacheKey] = [];
        foreach ($this->cache[$cacheKey] as $definition) {
            $this->identityIndex[$cacheKey][$definition->getIdentity()] = $definition;
        }

        return $this->cache[$cacheKey];
    }

    public function find(string $module, string $type, string $code, string $area = 'frontend', ?WelineTheme $theme = null): ?ThemeComponentDefinition
    {
        $identity = $this->buildIdentity($module, $type, $code);
        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';
        $resolvedTheme = $this->resolveTheme($area, $theme);
        $themeId = $resolvedTheme?->getId() ?: 0;
        $cacheKey = "{$themeId}:{$area}";
        $this->getDefinitionsForResolvedTheme($area, $resolvedTheme);

        return $this->identityIndex[$cacheKey][$identity] ?? null;
    }

    /**
     * Resolve slots owned by placeable container components, including external module widgets.
     *
     * @return array<string,mixed>|null
     */
    public function findSlot(
        string $slotId,
        string $area = 'frontend',
        ?WelineTheme $theme = null,
    ): ?array {
        $slotId = trim($slotId);
        if ($slotId === '') {
            return null;
        }

        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';
        foreach ($this->getDefinitions($area, $theme) as $definition) {
            foreach ($definition->slots as $key => $rawSlot) {
                $slot = is_array($rawSlot) ? $rawSlot : [];
                $candidateId = is_string($key) ? trim($key) : trim((string)($slot['id'] ?? ''));
                if ($candidateId !== $slotId) {
                    continue;
                }

                $accept = $slot['accept'] ?? ($slot['accepts'] ?? []);
                $accept = is_array($accept)
                    ? array_values(array_filter(array_map(
                        static fn(mixed $value): string => trim((string)$value),
                        $accept
                    )))
                    : [];
                $max = isset($slot['max']) && is_numeric($slot['max']) ? (int)$slot['max'] : null;
                $exclusive = (bool)($slot['exclusive'] ?? false) || $max === 1;

                return (new ThemeSlotDefinition(
                    id: $slotId,
                    name: trim((string)($slot['name'] ?? '')) ?: $slotId,
                    area: $area,
                    accept: $accept,
                    exclusive: $exclusive,
                    multiple: array_key_exists('multiple', $slot) ? (bool)$slot['multiple'] : !$exclusive,
                    append: (bool)($slot['append'] ?? false),
                    prepend: (bool)($slot['prepend'] ?? false),
                    meta: [
                        'position' => $slot['position'] ?? null,
                        'reject' => is_array($slot['reject'] ?? null) ? $slot['reject'] : [],
                        'max' => $max,
                        'min' => isset($slot['min']) && is_numeric($slot['min']) ? (int)$slot['min'] : null,
                        'required' => (bool)($slot['required'] ?? false),
                        'has_template_widgets' => false,
                        'template_widgets' => [],
                        'component_identity' => $definition->getIdentity(),
                    ],
                    sourcePath: $definition->templatePath,
                ))->toArray();
            }
        }

        return null;
    }

    public function clearCache(): void
    {
        $this->cache = [];
        $this->identityIndex = [];
    }

    private function appendDefinition(array &$definitions, array &$seen, array &$nativeThemeComponentCodes, ThemeComponentDefinition $definition): void
    {
        $identity = $definition->getIdentity();
        if (isset($seen[$identity])) {
            $existing = $definitions[$identity] ?? null;
            if ($existing instanceof ThemeComponentDefinition
                && ($definition->layerKey ?? '') === 'widget_registry'
                && ($existing->layerKey ?? '') !== 'widget_registry'
            ) {
                $definitions[$identity] = $definition;
            }
            return;
        }

        $definitions[$identity] = $definition;
        $seen[$identity] = true;

        if ($definition->module === 'Weline_Theme' && $definition->type === 'theme_component') {
            $nativeThemeComponentCodes[$definition->code] = true;
        }
    }

    private function resolveTheme(string $area, ?WelineTheme $theme = null): ?WelineTheme
    {
        if ($theme && $theme->getId()) {
            return $theme;
        }

        // Use ThemeContextService which properly handles preview themes
        return $this->themeContextService->resolveTheme($area, null, true);
    }

    private function buildIdentity(string $module, string $type, string $code): string
    {
        return "{$module}::{$type}::{$code}";
    }
}
