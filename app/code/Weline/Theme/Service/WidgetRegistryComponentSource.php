<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Interface\ThemeComponentSourceInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Widget\Service\WidgetRegistry;

class WidgetRegistryComponentSource implements ThemeComponentSourceInterface
{
    private const SLOT_TO_POSITION_MAP = [
        'logo' => 'header',
        'search' => 'header',
        'header-search' => 'header',
        'navigation' => 'header',
        'user-area' => 'header',
        'account' => 'header',
        'cart' => 'header',
        'cart-icon' => 'header',
        'mini-cart-icon' => 'header',
        'language' => 'header',
        'language-switcher' => 'header',
        'currency' => 'header',
        'currency-switcher' => 'header',
        'footer-links' => 'footer',
        'links' => 'footer',
        'footer-newsletter' => 'footer',
        'newsletter' => 'footer',
        'footer-social' => 'footer',
        'social' => 'footer',
        'footer-payment' => 'footer',
        'payment' => 'footer',
        'footer-copyright' => 'footer',
        'copyright' => 'footer',
    ];

    public function __construct(
        private readonly WidgetRegistry $widgetRegistry,
    ) {
    }

    public function getName(): string
    {
        return 'widget_registry';
    }

    public function collect(string $area, ?WelineTheme $theme = null, array $options = []): array
    {
        $definitions = [];
        $excludeThemeComponentCodes = array_fill_keys($options['exclude_theme_component_codes'] ?? [], true);
        $registry = $this->widgetRegistry->getRegistry();

        foreach ($registry as $type => $widgets) {
            if (!is_array($widgets)) {
                continue;
            }

            foreach ($widgets as $widgetName => $widget) {
                if (!is_array($widget)) {
                    continue;
                }

                $module = (string)($widget['module'] ?? '');
                $widgetType = (string)($widget['type'] ?? $type);
                $code = (string)($widget['code'] ?? $widgetName);
                $widgetArea = (string)($widget['area'] ?? $area);
                if ($widgetArea !== '' && $widgetArea !== $area) {
                    continue;
                }

                if ($module === 'Weline_Theme') {
                    $themeComponentCode = "{$widgetType}/{$code}";
                    if (isset($excludeThemeComponentCodes[$themeComponentCode])) {
                        continue;
                    }
                }

                $templateContent = !empty($widget['template_content']) ? (string)$widget['template_content'] : null;
                $templatePath = !empty($widget['template']) ? (string)$widget['template'] : null;

                $defaultInjections = $this->normalizeDefaultInjections($widget['default_injections'] ?? ($widget['config']['default_injections'] ?? []));

                $definitions[] = new ThemeComponentDefinition(
                    module: $module,
                    type: $widgetType,
                    code: $code,
                    name: (string)($widget['name'] ?? $code),
                    description: (string)($widget['description'] ?? ''),
                    area: $area,
                    sourceType: 'widget',
                    category: $widgetType,
                    renderMode: $templateContent !== null ? ThemeRenderable::MODE_TEMPLATE_CONTENT : ThemeRenderable::MODE_TEMPLATE_PATH,
                    configSchema: $this->buildConfigSchema($widget['params'] ?? []),
                    defaultConfig: $this->buildDefaultConfig($widget['params'] ?? []),
                    meta: array_merge($widget['meta'] ?? [], [
                        'default_injections' => $defaultInjections,
                        'registry_group' => $type,
                        'template' => $widget['template'] ?? null,
                    ]),
                    params: is_array($widget['params'] ?? null) ? $widget['params'] : [],
                    position: $this->resolvePositions($widget, $widgetType, $code),
                    pageLayouts: $this->normalizeArray($widget['page_layouts'] ?? ['*'], ['*']),
                    slots: is_array($widget['slots'] ?? null) ? $widget['slots'] : [],
                    slot: !empty($widget['slot']) ? (string)$widget['slot'] : null,
                    defaultInjections: $defaultInjections,
                    supports: $this->normalizeArray($widget['supports'] ?? [], []),
                    exclusive: (bool)($widget['exclusive'] ?? false),
                    compatible: (bool)($widget['compatible'] ?? false),
                    isContainer: (bool)($widget['is_container'] ?? false),
                    isAiGenerated: (bool)($widget['is_ai_generated'] ?? ($widget['meta']['is_ai_generated'] ?? false)),
                    icon: !empty($widget['icon']) ? (string)$widget['icon'] : null,
                    templatePath: $templatePath,
                    templateContent: $templateContent,
                    logicalKey: "{$module}/{$widgetType}/{$code}",
                    layerKey: 'widget_registry',
                );
            }
        }

        return $definitions;
    }

    private function buildConfigSchema(array $params): array
    {
        $schema = [];
        foreach ($params as $key => $param) {
            if (!is_array($param)) {
                continue;
            }
            $schema[$key] = [
                'type' => $param['type'] ?? 'text',
                'label' => $param['label'] ?? $param['name'] ?? $key,
                'description' => $param['description'] ?? '',
                'default' => $param['default'] ?? null,
                'required' => (bool)($param['required'] ?? false),
                'options' => $param['options'] ?? $param['option'] ?? [],
            ];
        }

        return $schema;
    }

    private function buildDefaultConfig(array $params): array
    {
        $config = [];
        foreach ($params as $key => $param) {
            if (is_array($param) && array_key_exists('default', $param)) {
                $config[$key] = $param['default'];
            }
        }
        return $config;
    }

    private function normalizeArray(array|string $value, array $fallback): array
    {
        if (is_array($value)) {
            $items = array_values(array_filter(array_map('strval', $value), static fn(string $item): bool => $item !== ''));
            return $items ?: $fallback;
        }

        $items = array_values(array_filter(array_map(
            static fn(string $item): string => trim($item),
            explode(',', $value)
        ), static fn(string $item): bool => $item !== ''));

        return $items ?: $fallback;
    }

    private function normalizeDefaultInjections(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value) || $value === []) {
            return [];
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        $items = $isList ? $value : [$value];
        $result = [];

        foreach ($items as $item) {
            if (is_array($item) && $item !== []) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function resolvePositions(array $widget, string $widgetType, string $code): array
    {
        $explicit = $this->normalizeArray($widget['position'] ?? [], []);
        if ($explicit) {
            return $explicit;
        }

        $slot = (string)($widget['slot'] ?? '');
        if ($slot !== '' && isset(self::SLOT_TO_POSITION_MAP[$slot])) {
            return [self::SLOT_TO_POSITION_MAP[$slot]];
        }

        if ($widgetType === 'container') {
            $codeLower = strtolower($code);
            if (str_contains($codeLower, 'header')) {
                return ['header'];
            }
            if (str_contains($codeLower, 'footer')) {
                return ['footer'];
            }
            if (str_contains($codeLower, 'sidebar')) {
                return ['sidebar'];
            }
            if (str_contains($codeLower, 'banner')) {
                return ['banner'];
            }
            return ['content'];
        }

        return match ($widgetType) {
            'header', 'navigation', 'search' => ['header'],
            'footer', 'newsletter', 'social' => ['footer'],
            'sidebar' => ['sidebar'],
            default => ['content'],
        };
    }
}
